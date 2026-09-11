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
	 * コンストラクタ.
	 *
	 * @param object                     $wpdb                  `$wpdb` 相当のオブジェクト.
	 * @param WPCV_Target_Run_Repository $target_run_repository `wpcv_target_runs` の永続化層.
	 * @param WPCV_Finding_Repository    $finding_repository    `wpcv_findings` の永続化層.
	 */
	public function __construct( $wpdb, WPCV_Target_Run_Repository $target_run_repository, WPCV_Finding_Repository $finding_repository ) {
		$this->wpdb                  = $wpdb;
		$this->target_run_repository = $target_run_repository;
		$this->finding_repository    = $finding_repository;
	}

	/**
	 * 1回分のchunk結果を確定する.
	 *
	 * `$chunk_result['needs_retry']` が真の場合、findings は保存せず
	 * `WPCV_Target_Run_Repository::reset_for_retry()` のみを行う(§Step3
	 * 「resume時にversion/fingerprintが変わっていたらchunk結果を確定せず
	 * retryへ戻す」)。偽の場合は findings を保存してから cursor・集計値を更新する.
	 *
	 * @param int          $run_id        findings.run_id に使う run の id.
	 * @param int          $target_run_id 対象の target_run の id.
	 * @param string       $target_id     対象の target_id(`save_findings()` の
	 *                                    `target_id => target_run_id` 対応表の組み立てに使う).
	 * @param array        $chunk_result  `WPCV_Chunk_Verifier::verify_manifest_chunk()`/
	 *                                    `verify_unknown_files_chunk()` の戻り値.
	 * @param string|false $new_version   `needs_retry: true` のとき
	 *                                    `WPCV_Target_Run_Repository::reset_for_retry()` へ
	 *                                    そのまま渡す新しい version(v0.4.0 §Step4:
	 *                                    dispatcherが今回のchunk処理で観測した「現在の」
	 *                                    version。`reset_for_retry()` のdocblock参照。
	 *                                    `false`(既定)は「version列を変更しない」).
	 * @return void
	 *
	 * @throws Throwable DB操作中に発生した例外(ROLLBACK後に再送出).
	 */
	public function commit_chunk( $run_id, $target_run_id, $target_id, array $chunk_result, $new_version = false ) {
		$wpdb = $this->wpdb;

		// transaction制御自体は動的な値を含まない固定リテラルのため prepare 不要.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control statement.
		$wpdb->query( 'START TRANSACTION' );

		try {
			if ( $chunk_result['needs_retry'] ) {
				$this->target_run_repository->reset_for_retry( $target_run_id, $chunk_result['manifest_fingerprint'], $new_version );
			} else {
				if ( ! empty( $chunk_result['findings'] ) ) {
					$this->finding_repository->save_findings(
						$run_id,
						array( $target_id => $target_run_id ),
						$chunk_result['findings']
					);
				}

				$this->target_run_repository->update_chunk_progress( $target_run_id, $chunk_result );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control statement.
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control statement.
			$wpdb->query( 'ROLLBACK' );

			throw $e;
		}
	}
}
