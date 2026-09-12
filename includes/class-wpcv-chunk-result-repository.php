<?php
/**
 * WPCV_Chunk_Result_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `WPCV_Chunk_Verifier` の結果を `wpcv_target_runs`(cursor等)と `wpcv_findings`
 * の2テーブルへ、1トランザクションで確定させる(v0.4.0 §Step3)。
 *
 * Repositoryをrun/target_run/findingの3責務に分割した方針(v0.4.0 §Step1。
 * `WPCV_Run_Repository` のクラス docblock 参照)を維持しつつ、chunk確定は
 * 複数テーブルにまたがる操作のため、専用の調整役クラスとして本クラスを新設した
 * (`WPCV_Target_Run_Repository`/`WPCV_Finding_Repository` 自体にはtransaction
 * 制御を持たせない。ユーザー確認済みの設計方針).
 *
 * このプラグインではこれまでtransactionを使う箇所は無かった(`WPCV_Run_Repository::reserve_run()`
 * はMySQLの名前付きadvisory lockのみを使う。トランザクションとは別物)。
 * chunk確定では「findingsのinsertは成功したがcursor更新が失敗した」といった
 * 半端な状態を残さないため、`$wpdb` の `START TRANSACTION`/`COMMIT`/`ROLLBACK`を
 * 直接発行する.
 *
 * v0.4.0 §Step8で `exclude_path`/`allowlist_hash` 抑制ルール(`WPCV_Suppression_Matcher`)の
 * 適用を追加した。永続化直前(`save_findings()` を呼ぶ前)の1箇所に集約する設計
 * (ユーザー確認済み)。`exclude_target` は `WPCV_Run_Planner::plan()` が列挙時点で
 * 適用済みのため、ここでは扱わない.
 *
 * v0.4.0コードレビューCR-02是正: `commit_chunk()` に `$lease_owner` を追加し、
 * `WPCV_Target_Run_Repository::update_chunk_progress()`/`reset_for_retry()` の
 * fencing(`WPCV_Target_Run_Repository` のクラスdocblock参照)結果を見て、
 * fencingに失敗した(lease失効sweepにより既に別workerへ再claimされていた)
 * 場合はCOMMITではなくROLLBACKする。`findings`のinsertだけが確定しcursor/
 * 集計は古いまま、という半端な状態を防ぐため(`commit_chunk()`は`void`から
 * `bool`に変更し、確定できたかどうかを呼び出し元〔`WPCV_Chunk_Dispatcher`〕に
 * 伝える).
 *
 * v0.4.0コードレビューCR-04是正: `needs_retry: true`(fingerprint/version不一致を
 * 検知し `reset_for_retry()` でcursor・集計値を0へ戻す経路)で、`reset_for_retry()`が
 * fencingに成功した場合のみ `WPCV_Finding_Repository::delete_by_target_run_id()` で
 * この target_run の旧世代findingsを削除するようにした。cursor・集計値だけ
 * リセットしfindings行を残したままだと、再走査後に重複・陳腐化したfindingが
 * 表示され `findings_total` と実件数が食い違う不整合が起きていた(レビュー指摘)。
 * fencingに失敗した場合は削除しない ―― 既に別workerが再claimして進めている
 * findingsを、fencing負けした古いworkerが誤って消してしまわないため.
 */
class WPCV_Chunk_Result_Repository {

	/**
	 * `$wpdb` 相当のオブジェクト(`query()` を持つもの).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * `wpcv_target_runs` の永続化層.
	 *
	 * @var WPCV_Target_Run_Repository
	 */
	private $target_run_repository;

	/**
	 * `wpcv_findings` の永続化層.
	 *
	 * @var WPCV_Finding_Repository
	 */
	private $finding_repository;

	/**
	 * `wpcv_suppressions` の永続化層(v0.4.0 §Step8).
	 *
	 * @var WPCV_Suppression_Repository
	 */
	private $suppression_repository;

	/**
	 * コンストラクタ.
	 *
	 * @param object                      $wpdb                   `$wpdb` 相当のオブジェクト.
	 * @param WPCV_Target_Run_Repository  $target_run_repository  `wpcv_target_runs` の永続化層.
	 * @param WPCV_Finding_Repository     $finding_repository     `wpcv_findings` の永続化層.
	 * @param WPCV_Suppression_Repository $suppression_repository `wpcv_suppressions` の永続化層.
	 */
	public function __construct( $wpdb, WPCV_Target_Run_Repository $target_run_repository, WPCV_Finding_Repository $finding_repository, WPCV_Suppression_Repository $suppression_repository ) {
		$this->wpdb                   = $wpdb;
		$this->target_run_repository  = $target_run_repository;
		$this->finding_repository     = $finding_repository;
		$this->suppression_repository = $suppression_repository;
	}

	/**
	 * 1回分のchunk結果を確定する.
	 *
	 * `$chunk_result['needs_retry']` が真の場合、findings は保存せず
	 * `WPCV_Target_Run_Repository::reset_for_retry()` でcursor・集計値をリセット
	 * したうえで、この target_run に紐づく旧世代のfindingsを
	 * `WPCV_Finding_Repository::delete_by_target_run_id()` で削除する(§Step3
	 * 「resume時にversion/fingerprintが変わっていたらchunk結果を確定せず
	 * retryへ戻す」+ v0.4.0コードレビューCR-04是正)。偽の場合は findings を
	 * 保存してから cursor・集計値を更新する.
	 *
	 * `$lease_owner`(v0.4.0コードレビューCR-02是正で追加)は、`claim_next()`が
	 * この処理エピソードに割り当てた値をそのまま渡すこと。`WPCV_Target_Run_Repository::
	 * update_chunk_progress()`/`reset_for_retry()`のfencing(`status = running AND
	 * lease_owner = $lease_owner`)が失敗した場合(lease失効sweepが別workerへ
	 * 既に再claimさせていた場合)、`findings`のinsertが既に行われていても
	 * ROLLBACKし、古いworkerの結果を確定させない(この判定が無いと、findingsだけ
	 * 保存されcursor/集計は更新されない、という半端な状態がCOMMITされてしまう).
	 *
	 * @param int          $run_id        findings.run_id に使う run の id.
	 * @param int          $target_run_id 対象の target_run の id.
	 * @param string       $target_id     対象の target_id(`save_findings()` の
	 *                                    `target_id => target_run_id` 対応表の組み立てに使う).
	 * @param array        $chunk_result  `WPCV_Chunk_Verifier::verify_manifest_chunk()`/
	 *                                    `verify_unknown_files_chunk()` の戻り値.
	 * @param string       $lease_owner   `claim_next()` がこの処理エピソードに割り当てた
	 *                                    lease owner(fencingに使う).
	 * @param string|false $new_version   `needs_retry: true` のとき
	 *                                    `WPCV_Target_Run_Repository::reset_for_retry()` へ
	 *                                    そのまま渡す新しい version(v0.4.0 §Step4:
	 *                                    dispatcherが今回のchunk処理で観測した「現在の」
	 *                                    version。`reset_for_retry()` のdocblock参照。
	 *                                    `false`(既定)は「version列を変更しない」).
	 * @return bool 確定できたら true。false はfencingに失敗した(既に別workerに
	 *              再claimされていた)ことを意味し、呼び出し元は例外を投げず
	 *              静かに諦めてよい.
	 *
	 * @throws Throwable        DB操作中に発生した例外(ROLLBACK後に再送出。`save_findings()`/
	 *                          `update_chunk_progress()`/`reset_for_retry()` が
	 *                          `$wpdb` の insert/update 失敗時に投げる `RuntimeException`
	 *                          〔v0.4.0コードレビューCR-03是正〕もここで捕捉される).
	 * @throws RuntimeException `$wpdb->query( 'COMMIT' )` 自体が失敗した場合
	 *                          (v0.4.0コードレビューCR-03是正。上記の`Throwable`と
	 *                          同じcatch節でROLLBACKを試みたうえで再送出する).
	 */
	public function commit_chunk( $run_id, $target_run_id, $target_id, array $chunk_result, $lease_owner, $new_version = false ) {
		$wpdb = $this->wpdb;

		// transaction制御自体は動的な値を含まない固定リテラルのため prepare 不要.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control statement.
		$wpdb->query( 'START TRANSACTION' );

		try {
			if ( $chunk_result['needs_retry'] ) {
				$committed = $this->target_run_repository->reset_for_retry( $target_run_id, $chunk_result['manifest_fingerprint'], $new_version, $lease_owner );

				if ( $committed ) {
					// cursor・集計値のリセットに成功した(=fencingに勝った)場合のみ、
					// この target_run の旧世代findingsを削除する(v0.4.0コード
					// レビューCR-04是正。クラスdocblock参照)。fencingに負けていた
					// 場合ここには来ないため、既に別workerが再claimして進めている
					// findingsを誤って消すことはない.
					$this->finding_repository->delete_by_target_run_id( $target_run_id );
				}
			} else {
				if ( ! empty( $chunk_result['findings'] ) ) {
					$this->finding_repository->save_findings(
						$run_id,
						array( $target_id => $target_run_id ),
						$this->apply_suppressions( $chunk_result['findings'] )
					);
				}

				$committed = $this->target_run_repository->update_chunk_progress( $target_run_id, $chunk_result, $lease_owner );
			}

			if ( $committed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control statement.
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					// COMMIT自体がSQLエラーで失敗した場合(v0.4.0コードレビュー
					// CR-03是正)。ここまでのfindings insert・target_run updateは
					// 実DBではロールバックされないまま残る可能性があるが、
					// 「成功した」と呼び出し元に伝えて処理を進めさせるよりは、
					// 例外で異常を可視化したほうが安全(catch節が明示的に
					// ROLLBACKを試みたうえで再送出する).
					throw new RuntimeException(
						esc_html(
							sprintf(
								'WPCV_Chunk_Result_Repository::commit_chunk() の COMMIT に失敗しました: %s',
								(string) $wpdb->last_error
							)
						)
					);
				}
			} else {
				// fencingに失敗した(既に別workerに再claimされていた)。findingsの
				// insertが行われていた場合でも、古いworkerの結果を確定させない.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control statement.
				$wpdb->query( 'ROLLBACK' );
			}

			return $committed;
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control statement.
			$wpdb->query( 'ROLLBACK' );

			throw $e;
		}
	}

	/**
	 * Findings配列に `exclude_path`/`allowlist_hash` 抑制ルールを適用し、
	 * `suppressed_by`/`suppression_id` を埋め込んだ配列を返す(v0.4.0 §Step8)。
	 *
	 * `$findings` は同一chunk(=同一target_run。1 target_run = 1直列cursorの前提。
	 * `WPCV_Chunk_Verifier` のクラス docblock参照)内のfindingsのため、すべて同じ
	 * dimension/slugを持つ。ルール取得は1回で済む.
	 *
	 * @param array $findings `WPCV_Chunk_Verifier` が返す findings の配列(空でないことを呼び出し元が保証済み).
	 * @return array `suppressed_by`/`suppression_id` を追加済みの findings.
	 */
	private function apply_suppressions( array $findings ) {
		$first       = $findings[0];
		$rules       = $this->suppression_repository->find_active_rules_for_target( $first['dimension'], $first['slug'] );
		$strict_mode = WPCV_Settings::get_strict_mode();

		return array_map(
			static function ( $finding ) use ( $rules, $strict_mode ) {
				return array_merge(
					$finding,
					WPCV_Suppression_Matcher::apply( $finding, $rules['exclude_path'], $rules['allowlist_hash'], $strict_mode )
				);
			},
			$findings
		);
	}
}
