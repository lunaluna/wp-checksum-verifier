<?php
/**
 * WPCV_Target_Run_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_target_runs` テーブルの永続化を担当する(v0.4.0 §Step1でWPCV_Repositoryから分割).
 *
 * 分割の経緯は `WPCV_Run_Repository` のクラス docblock 参照。v0.4.0 §Step3で
 * `WPCV_Chunk_Verifier` の結果(cursor/manifest_fingerprint等)を書き込む
 * `update_chunk_progress()`/`reset_for_retry()` を追加した。v0.4.0 §Step4で
 * `claim_next()`(atomic claim)・`sweep_expired_leases()`(stale worker検知+
 * backoff)・`abort_non_terminal_for_run()`(run deadline超過sweep)・
 * `finalize_immediate()`(chunk処理を伴わない即時終端化。muplugin loader等)・
 * `find_all_by_run()`(run完了判定・summary再計算用)を追加した.
 *
 * v0.4.0コードレビューCR-02是正: `update_chunk_progress()`/`reset_for_retry()`/
 * `finalize_immediate()`(=claimした後に確定を行う3メソッド)は、確定用の
 * `$wpdb->update()`のWHEREに`id`だけでなく`status = running`・`lease_owner`
 * (`claim_next()`が割り当てた値)も含めるfencingを行う。これが無いと、
 * worker Aのleaseが切れて`sweep_expired_leases()`が同じtargetをworker Bへ
 * 再claimさせた後に、Aが(処理が単に遅かっただけで)遅れて確定処理を実行すると、
 * `id`のみのWHEREではAの古い結果でBの結果を無条件に上書きしてしまう
 * (cursorの後退・集計の二重加算・finding重複・Bの状態の消失)。fencingに
 * より、Aの確定は「もう自分がこのtargetのlease所有者ではない」ため0行しか
 * 更新できず、静かに諦められる(claim_next()のCAS敗北と同じ扱い).
 */
class WPCV_Target_Run_Repository {

	/**
	 * `claim_next()` が設定するlease有効期間の既定値(秒).
	 *
	 * 未実測: 暫定値。実測の上で見直すこと(§数値を決める前に実測するルール)。
	 * chunk1回分の処理時間(§Step3の `WPCV_Chunk_Verifier` の時間予算。既定20秒
	 * 〔`WPCV_Chunk_Dispatcher::DEFAULT_BUDGET_MAX_SECONDS`〕)に、manifest取得の
	 * ネットワーク往復・DB操作のオーバーヘッドを見込んだ安全率を掛けた値として
	 * 120秒を仮置きする.
	 *
	 * @var int
	 */
	const DEFAULT_LEASE_SECONDS = 120;

	/**
	 * `sweep_expired_leases()` がlease切れとみなして再試行させる最大回数の既定値.
	 *
	 * 未実測: 暫定値。この回数を超えたら `WPCV_Target_Status::FAILED` へ倒す
	 * (§Step4「最大retry回数」)。chunkが正常にyieldして継続する分(§Step3の
	 * `completed:false`)はこのカウントを消費しない(`update_chunk_progress()` 参照)。
	 * ここでカウントするのは「lease期限が切れるまで応答が無かった」= workerが
	 * 停止・クラッシュした疑いのある試行のみ.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_ATTEMPTS = 5;

	/**
	 * `sweep_expired_leases()` のbackoff基準秒数の既定値.
	 *
	 * 未実測: 暫定値。`$base * 2^(attempt_count-1)`(上限 `DEFAULT_BACKOFF_MAX_SECONDS`)
	 * で指数backoffを計算する既定の `backoff` callable が使う.
	 *
	 * @var int
	 */
	const DEFAULT_BACKOFF_BASE_SECONDS = 30;

	/**
	 * `sweep_expired_leases()` のbackoff秒数の上限値の既定値.
	 *
	 * 未実測: 暫定値.
	 *
	 * @var int
	 */
	const DEFAULT_BACKOFF_MAX_SECONDS = 3600;

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `update()` / `get_results()` / `query()` /
	 * `base_prefix` / `insert_id` / `last_error` を持つもの).
	 *
	 * `query()`(`START TRANSACTION`/`COMMIT`/`ROLLBACK` 用)と `last_error` は
	 * v0.4.0コードレビューCR-03是正で追加した要件(`save_target_runs()` が
	 * 全件を1トランザクションにまとめ、insert失敗時にROLLBACKして例外を投げる
	 * ために使う).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * 現在時刻(UTC の MySQL DATETIME 文字列)を返す callable.
	 *
	 * テストで固定時刻を注入できるようにするため引数で差し替え可能にする
	 * (`WPCV_Run_Repository` と同じパターン. v0.4.0 §Step3で `update_chunk_progress()`
	 * の `finished_at` に使うために追加).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * コンストラクタ.
	 *
	 * @param object        $wpdb `$wpdb` 相当のオブジェクト.
	 * @param callable|null $now  現在時刻を返す callable. 省略時は `gmdate( 'Y-m-d H:i:s' )`.
	 */
	public function __construct( $wpdb, ?callable $now = null ) {
		$this->wpdb = $wpdb;
		$this->now  = $now ?? static function () {
			return gmdate( 'Y-m-d H:i:s' );
		};
	}

	/**
	 * 検証対象(target_run)群を保存する.
	 *
	 * @param int   $run_id      `WPCV_Run_Repository::reserve_run()` が返した run の id.
	 * @param array $target_runs `WPCV_Verifier` の各 `verify_*()` が返す target_run の配列
	 *                           (id/run_id 無し。§5.3 のスキーマに準拠).
	 * @return array `target_id => target_run_id` の対応表(`WPCV_Finding_Repository::save_findings()` に渡す).
	 *
	 * @throws RuntimeException `$wpdb->insert()`/`COMMIT` が失敗した場合(v0.4.0
	 *                          コードレビューCR-03是正)。全件を1トランザクション
	 *                          にまとめ、1件でも失敗したらROLLBACKして再送出する
	 *                          ―― これが無いと、target数十件のうち途中の1件だけが
	 *                          DBエラーで欠けても「plan成功」のまま処理が続き
	 *                          (呼び出し元は戻り値の対応表を見て初めて欠落に
	 *                          気付ける保証が無い)、以降のchunk処理は存在しない
	 *                          target_run_idを参照し続ける。呼び出し元
	 *                          `WPCV_Run_Starter::plan_and_save()` は既にこの
	 *                          メソッドからのThrowableを捕捉してrunをfailed化する
	 *                          設計のため、ここでは投げ返すだけでよい.
	 * @throws Throwable        上記以外の理由でtry節内で発生した例外(ROLLBACK後に
	 *                          再送出する。現状は上記の `RuntimeException` のみが
	 *                          該当するが、`catch ( Throwable $e )` の型に合わせて
	 *                          記載する).
	 */
	public function save_target_runs( $run_id, array $target_runs ) {
		$table          = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$target_run_ids = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control statement.
		$this->wpdb->query( 'START TRANSACTION' );

		try {
			foreach ( $target_runs as $target_run ) {
				$inserted = $this->wpdb->insert(
					$table,
					array(
						'run_id'          => $run_id,
						'target_id'       => $target_run['target_id'],
						'dimension'       => $target_run['dimension'],
						'slug'            => $target_run['slug'],
						'version'         => $target_run['version'],
						'source'          => $target_run['source'],
						'source_ref'      => $target_run['source_ref'],
						'manifest_status' => $target_run['manifest_status'],
						'status'          => $target_run['status'],
						'error_code'      => $target_run['error_code'],
						'error_message'   => $target_run['error_message'],
						'files_total'     => $target_run['files_total'],
						'files_verified'  => $target_run['files_verified'],
						'findings_total'  => $target_run['findings_total'],
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
				);

				if ( false === $inserted ) {
					throw new RuntimeException(
						esc_html(
							sprintf(
								'WPCV_Target_Run_Repository::save_target_runs() の insert に失敗しました: %s',
								(string) $this->wpdb->last_error
							)
						)
					);
				}

				$target_run_ids[ $target_run['target_id'] ] = (int) $this->wpdb->insert_id;
			}

			if ( false === $this->wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException(
					esc_html(
						sprintf(
							'WPCV_Target_Run_Repository::save_target_runs() の COMMIT に失敗しました: %s',
							(string) $this->wpdb->last_error
						)
					)
				);
			}
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control statement.
			$this->wpdb->query( 'ROLLBACK' );

			throw $e;
		}

		return $target_run_ids;
	}

	/**
	 * `WPCV_Chunk_Verifier::verify_manifest_chunk()`/`verify_unknown_files_chunk()` の
	 * 結果(`needs_retry: false` の場合)を target_run 行へ反映する(v0.4.0 §Step3).
	 *
	 * `files_verified`/`findings_total` は前回までの累積値に今回の増分
	 * (`files_verified_delta`・`count( findings )`)を加算する(chunk単位の
	 * 呼び出しを重ねるたびに積み上がっていく値のため)。加算前の現在値を
	 * 読み取ってから `update()` に渡す方式にしたのは、`$wpdb->update()` が
	 * `SET files_verified = files_verified + %d` のような式を組み立てられない
	 * ため(生SQLでの直接更新も可能だが、テストダブル〔`WPCV_Test_Fake_WPDB`〕の
	 * 複雑化を避けるため見送った).
	 *
	 * `$chunk_result['completed']` が真の場合、この chunk 呼び出しで対象集合を
	 * 最後まで処理できたとみなし、`status` を `WPCV_Target_Status::SUCCESS` へ
	 * 進め `finished_at` を記録する(manifest取得自体の成功可否は呼び出し元が
	 * chunk verifier を呼ぶ前提条件のため、ここでは判定しない).
	 *
	 * `completed: false`(時間・件数・メモリ予算に達して yield した。異常ではない
	 * 正常系)の場合、v0.4.0 §Step4で `status` を `WPCV_Target_Status::RETRY` へ
	 * 進めるようにした(Step3時点では `status` を変更しないままだったため、claim
	 * 済みの `running` のまま残り、`WPCV_Target_Status::SCHEDULABLE`
	 * (`queued`/`retry`)に含まれず二度と claim されなくなっていた)。この経路は
	 * `attempt_count` を加算しない(§Step4「chunkが正常にyieldする分は最大retry
	 * 回数を消費しない」。`sweep_expired_leases()` の docblock 参照)。lease関連
	 * 列もあわせてクリアし、次回の `claim_next()` がすぐにこの target_run を
	 * schedulable と判定できるようにする.
	 *
	 * `$lease_owner` を(`id`に加えて)`status = running`とともにWHEREへ含める
	 * ことで、確定用のfencingを行う(v0.4.0コードレビューCR-02是正)。lease失効
	 * sweep(`sweep_expired_leases()`)が別workerへ再claimさせた後に、失効した
	 * 側のworkerが遅れて戻ってきてこのメソッドを呼んでも、`lease_owner`が
	 * 既に変わっている(または`status`がrunningでなくなっている)ため0行しか
	 * 更新されず、cursorの後退・集計の二重加算・新workerの結果の上書きを防げる.
	 *
	 * v0.4.0コードレビューCR-09是正: `$chunk_result['manifest_status']`が
	 * 設定されている場合(=`process_manifest_chunk()`。manifestを実際に取得
	 * できたことが前提の呼び出し元)、`manifest_status`列もあわせて更新する。
	 * これが無いと、manifest取得に成功して`success`まで完了したtarget_runでも
	 * planner挿入時の既定値`missing`のまま残り続け、成功しているのに監査情報が
	 * 「manifest無し」を示す矛盾が起きていた(レビュー指摘)。未知ファイル走査
	 * (`process_scan_chunk()`。manifestの概念が無い)からの呼び出しでは
	 * `manifest_status`キー自体が無いため、この列には触れない.
	 *
	 * @param int    $target_run_id 対象の target_run の id.
	 * @param array  $chunk_result  `WPCV_Chunk_Verifier::verify_*_chunk()` の戻り値
	 *                              (`needs_retry: false` のもの)に、manifestベースの
	 *                              呼び出し元が `manifest_status` を追加したもの
	 *                              (省略可。未知ファイル走査からの呼び出しには無い).
	 * @param string $lease_owner   `claim_next()` がこの処理エピソードに割り当てた
	 *                               lease owner(呼び出し元が保持しているclaim結果の値).
	 * @return bool 更新できたら true。false は対象行が見つからなかった、または
	 *              既に別workerに再claimされていた(fencing失敗)ことを意味する
	 *              (呼び出し元は例外を投げず、静かに諦めてよい ―― 再claimした
	 *              側が処理を引き継ぐため).
	 *
	 * @throws RuntimeException `$wpdb->update()` がSQLエラーで `false` を返した
	 *                          場合(v0.4.0コードレビューCR-03是正)。WHEREに
	 *                          一致する行が単に無かった(fencing失敗。上記の
	 *                          正常系)場合は整数 `0` が返るため、これとは区別する
	 *                          ―― 区別せずどちらも`false`相当として静かに諦めると、
	 *                          呼び出し元 `WPCV_Chunk_Result_Repository::commit_chunk()`は
	 *                          「findingsのinsertだけ成功しcursorは古いまま」の
	 *                          半端な状態を、fencing失敗時と同じ「正常なROLLBACK」
	 *                          として扱ってしまい、実際にはDB異常が起きている
	 *                          ことに誰も気付けなくなる.
	 */
	public function update_chunk_progress( $target_run_id, array $chunk_result, $lease_owner ) {
		$table   = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$current = $this->find_by_id( (int) $target_run_id );

		if ( null === $current ) {
			return false;
		}

		$files_verified = (int) $current['files_verified'] + (int) $chunk_result['files_verified_delta'];
		$findings_total = (int) $current['findings_total'] + count( $chunk_result['findings'] );

		// v0.4.0コードレビューCR-08是正の実地検証で発見: `WPCV_Target_Run_Repository::
		// mark_scan_incomplete()`(walk予算切れ)が記録した`error_code`
		// (`WPCV_Error_Code::TIMEOUT`)が、その後このtarget_runが実際に
		// (`completed: true`で)成功しても消えずに残り、`status=success`なのに
		// `error_code=timeout`が表示され続ける不整合が実機で確認された。
		// `update_chunk_progress()`が呼ばれる=chunk_verifierが実際に走って結果を
		// 返した(=以前の`error_code`は陳腐化した)ことを意味するため、
		// `completed`の真偽に関わらず常にクリアする.
		$data   = array(
			'cursor_path'          => $chunk_result['cursor_path'],
			'manifest_fingerprint' => $chunk_result['manifest_fingerprint'],
			'files_total'          => (int) $chunk_result['files_total'],
			'files_verified'       => $files_verified,
			'findings_total'       => $findings_total,
			'error_code'           => null,
		);
		$format = array( '%s', '%s', '%d', '%d', '%d', '%s' );

		if ( isset( $chunk_result['manifest_status'] ) ) {
			$data['manifest_status'] = (string) $chunk_result['manifest_status'];
			$format[]                = '%s';
		}

		if ( $chunk_result['completed'] ) {
			$data['status']      = WPCV_Target_Status::SUCCESS;
			$data['finished_at'] = call_user_func( $this->now );
			$format[]            = '%s';
			$format[]            = '%s';
		} else {
			$data['status']           = WPCV_Target_Status::RETRY;
			$data['lease_owner']      = null;
			$data['lease_expires_at'] = null;
			$data['retry_after']      = null;
			$format[]                 = '%s';
			$format[]                 = '%s';
			$format[]                 = '%s';
			$format[]                 = '%s';
		}

		$updated = $this->wpdb->update(
			$table,
			$data,
			array(
				'id'          => (int) $target_run_id,
				'status'      => WPCV_Target_Status::RUNNING,
				'lease_owner' => (string) $lease_owner,
			),
			$format,
			array( '%d', '%s', '%s' )
		);

		if ( false === $updated ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Target_Run_Repository::update_chunk_progress() の update に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}

		return $updated > 0;
	}

	/**
	 * Fingerprint/versionの不一致を検知した target_run を `WPCV_Target_Status::RETRY` へ
	 * 戻し、cursor・集計値をリセットする(v0.4.0 §Step3。
	 * `WPCV_Chunk_Verifier::verify_*_chunk()` が `needs_retry: true` を返した場合に呼ぶ).
	 *
	 * 集計値をリセットするのは、対象集合が変わった(プラグイン更新等)ことで
	 * 「前回どこまで確認できていたか」の情報自体が意味を失うため。次回の
	 * chunk呼び出しは、この target_run を最初から(`cursor_path = null` として)
	 * 再検証する.
	 *
	 * v0.4.0 §Step4: 第3引数 `$version` を追加した。プラグイン等の対象は
	 * dispatcherが毎回「現在の」バージョンを解決し直す設計(§Step4 dispatcher
	 * docblock参照。enqueue時点の状態を持ち回らず実行時点の最新状態を使う既存方針
	 * の延長)のため、version変動を検知して retry へ戻す際に `version` 列を
	 * 更新しないままだと、次回 chunk 実行時も「保存済み version(古いまま)」対
	 * 「今回解決した version(新しい)」の不一致を検知し続け、いつまで経っても
	 * `needs_retry` から抜けられなくなる(検知の基準そのものが古いままのため)。
	 * `$version` に `false`(既定値)以外を渡すことで、この呼び出し時点で
	 * dispatcherが観測した最新の version を新しい基準として保存できるようにした。
	 * `false` は「呼び出し元がversionを解決していない(または対象にversionの
	 * 概念が無い。未知ファイル走査target等)ため列を変更しない」を表す
	 * (`null` は「versionが不明であることを明示的に記録する」という別の意味に
	 * 使うため、区別する必要がある).
	 *
	 * `$lease_owner`を(`id`に加えて)`status = running`とともにWHEREへ含めて
	 * fencingする理由は `update_chunk_progress()` と同じ(v0.4.0コードレビュー
	 * CR-02是正。クラスdocblock参照).
	 *
	 * v0.4.0コードレビューCR-09是正: 第5引数 `$manifest_status` を追加した。
	 * `needs_retry: true` はmanifestの取得自体には成功した(=呼び出し元の
	 * `process_manifest_chunk()`が`error_code`チェックを通過した)場合にのみ
	 * 起こりうるため、retryへ戻す際にも`manifest_status`を最新の値へ更新して
	 * よい(`$version`と同じ「実行時点で観測した最新の値で基準を更新する」
	 * 考え方。§Step4)。`$version`と同じく`false`(既定)は「呼び出し元がこの
	 * 値を持たない(未知ファイル走査target等)ため列を変更しない」を表す.
	 *
	 * @param int          $target_run_id        対象の target_run の id.
	 * @param string|null  $manifest_fingerprint 今回計算し直した fingerprint
	 *                                           (次回の照合基準として保存しておく).
	 * @param string|false $version              新しい基準として保存する version。
	 *                                           `false`(既定)なら version 列は
	 *                                           変更しない.
	 * @param string       $lease_owner          `claim_next()` がこの処理エピソードに
	 *                                           割り当てた lease owner.
	 * @param string|false $manifest_status      新しい基準として保存する
	 *                                           manifest_status。`false`(既定)なら
	 *                                           manifest_status列は変更しない.
	 * @return bool 更新できたら true。false は対象行が見つからなかった、または
	 *              既に別workerに再claimされていた(fencing失敗)ことを意味する.
	 *
	 * @throws RuntimeException `$wpdb->update()` がSQLエラーで `false` を返した
	 *                          場合(v0.4.0コードレビューCR-03是正。理由は
	 *                          `update_chunk_progress()` の同じ `@throws` 参照).
	 */
	public function reset_for_retry( $target_run_id, $manifest_fingerprint, $version, $lease_owner, $manifest_status = false ) {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';

		// v0.4.0コードレビューCR-08是正の実地検証で発見した問題
		// (`update_chunk_progress()` の同じコメント参照)と同じ理由で、ここに
		// 到達する時点でchunk_verifierは実際に走っている(fingerprint/version
		// drift検知はchunk_verifierの実行結果)ため、以前の`mark_scan_incomplete()`
		// 等が残した陳腐化した`error_code`をクリアする.
		$data   = array(
			'status'               => WPCV_Target_Status::RETRY,
			'cursor_path'          => null,
			'manifest_fingerprint' => $manifest_fingerprint,
			'files_total'          => 0,
			'files_verified'       => 0,
			'findings_total'       => 0,
			'lease_owner'          => null,
			'lease_expires_at'     => null,
			'retry_after'          => null,
			'error_code'           => null,
		);
		$format = array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' );

		if ( false !== $version ) {
			$data['version'] = $version;
			$format[]        = '%s';
		}

		if ( false !== $manifest_status ) {
			$data['manifest_status'] = $manifest_status;
			$format[]                = '%s';
		}

		$updated = $this->wpdb->update(
			$table,
			$data,
			array(
				'id'          => (int) $target_run_id,
				'status'      => WPCV_Target_Status::RUNNING,
				'lease_owner' => (string) $lease_owner,
			),
			$format,
			array( '%d', '%s', '%s' )
		);

		if ( false === $updated ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Target_Run_Repository::reset_for_retry() の update に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}

		return $updated > 0;
	}

	/**
	 * `WPCV_Unknown_File_Scanner::scan()` 自体が時間・メモリ予算内に完了できなかった
	 * (walkが打ち切られた)場合に呼ぶ(v0.4.0コードレビューCR-08是正)。
	 *
	 * `update_chunk_progress()`(`completed: false`)とは異なり、この経路では
	 * chunk_verifierによる比較・finding化が1件も行われていない(walkそのものが
	 * 予算切れで中断し、fingerprint計算に使える完全な集合が無い)。そのため
	 * `cursor_path`/`manifest_fingerprint`/`files_total`等は一切更新せず、次回
	 * dispatchで最初から(今回と同じ内容で)再走査させる。`WPCV_Error_Code::TIMEOUT`
	 * を記録することで、「lease切れ(worker異常。`sweep_expired_leases()`参照)」とは
	 * 異なる理由であることを運用者が区別できるようにする。`attempt_count`は
	 * 加算しない(`update_chunk_progress()`の`completed:false`分岐と同じ理由 ――
	 * walk予算切れは正常な yield であり、workerクラッシュのような異常系ではないため).
	 *
	 * `$lease_owner`を(`id`に加えて)`status = running`とともにWHEREへ含めてfencing
	 * する理由は `update_chunk_progress()` と同じ(v0.4.0コードレビューCR-02是正参照).
	 *
	 * @param int    $target_run_id 対象の target_run の id.
	 * @param string $lease_owner   `claim_next()` がこの処理エピソードに割り当てた
	 *                              lease owner.
	 * @return bool 更新できたら true。false は対象行が見つからなかった、または
	 *              既に別workerに再claimされていた(fencing失敗)ことを意味する.
	 *
	 * @throws RuntimeException `$wpdb->update()` がSQLエラーで `false` を返した
	 *                          場合(v0.4.0コードレビューCR-03是正と同じ理由).
	 */
	public function mark_scan_incomplete( $target_run_id, $lease_owner ) {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';

		$updated = $this->wpdb->update(
			$table,
			array(
				'status'           => WPCV_Target_Status::RETRY,
				'error_code'       => WPCV_Error_Code::TIMEOUT,
				'lease_owner'      => null,
				'lease_expires_at' => null,
				'retry_after'      => null,
			),
			array(
				'id'          => (int) $target_run_id,
				'status'      => WPCV_Target_Status::RUNNING,
				'lease_owner' => (string) $lease_owner,
			),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( false === $updated ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Target_Run_Repository::mark_scan_incomplete() の update に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}

		return $updated > 0;
	}

	/**
	 * 指定 run に属する、claim可能(`WPCV_Target_Status::SCHEDULABLE`。かつ
	 * `retry_after` が未来でない)な target_run を1件、原子的に claim する
	 * (v0.4.0 §Step4: `WPCV_Chunk_Dispatcher` から呼ぶ).
	 *
	 * 実際の排他は `$wpdb->update()` の `WHERE id = ? AND status = ?`(読み取り時点の
	 * 状態を条件に含む Compare-And-Swap)が担う。これは `WPCV_Run_Repository::mark_queued_planning()`
	 * と同じパターンで、MySQL の `UPDATE ... WHERE` は単一の原子的な文であるため、
	 * 2つの worker が同じ行を同時に claim しようとしても、先に成功した側だけが
	 * 影響行数1を得て、後発は影響行数0(=claim失敗。呼び出し元は次の候補を
	 * 探すのではなく `null` を返し、次の dispatch 呼び出しに委ねる)を得る。
	 * 候補の選定(SELECT)自体は原子的ではない(`ORDER BY ... LIMIT 1` 相当を
	 * 使わず、`WPCV_Run_Repository::find_active_run()` と同じ「全行取得してPHPで
	 * 絞り込む」方式。テストダブル `WPCV_Test_Fake_WPDB::get_results()` がWHERE句を
	 * 解釈しないため)が、claim の安全性は上記のCASのみに依存しており、候補選定の
	 * 非原子性は「同じ行を2 workerが同時に選ぶ」ことはあっても「2 workerが両方とも
	 * claimに成功する」ことは無い、という性質を壊さない.
	 *
	 * @param int    $run_id        対象の run の id.
	 * @param string $lease_owner   claim した worker を識別する一意な文字列
	 *                              (`WPCV_Chunk_Dispatcher` が呼び出しごとに生成する).
	 * @param int    $lease_seconds lease有効期間(秒). 省略時は `DEFAULT_LEASE_SECONDS`.
	 * @return array|null claim できた target_run 行(更新後の値を反映済み)。
	 *                     claim対象が無い、またはCASに敗れた場合は `null`.
	 */
	public function claim_next( $run_id, $lease_owner, $lease_seconds = self::DEFAULT_LEASE_SECONDS ) {
		$table      = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$now_string = call_user_func( $this->now );

		$candidates = array();
		foreach ( $this->all_rows() as $row ) {
			if ( (int) $row['run_id'] !== (int) $run_id ) {
				continue;
			}

			if ( ! WPCV_Target_Status::is_schedulable( $row['status'] ) ) {
				continue;
			}

			if ( ! empty( $row['retry_after'] ) && (string) $row['retry_after'] > $now_string ) {
				continue;
			}

			$candidates[] = $row;
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);

		$target           = $candidates[0];
		$lease_expires_at = gmdate( 'Y-m-d H:i:s', strtotime( $now_string ) + (int) $lease_seconds );
		$new_fields       = array(
			'status'           => WPCV_Target_Status::RUNNING,
			'lease_owner'      => (string) $lease_owner,
			'lease_expires_at' => $lease_expires_at,
			'heartbeat_at'     => $now_string,
			'started_at'       => empty( $target['started_at'] ) ? $now_string : $target['started_at'],
		);

		$updated = $this->wpdb->update(
			$table,
			$new_fields,
			array(
				'id'     => (int) $target['id'],
				'status' => $target['status'],
			),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( $updated <= 0 ) {
			// 他 worker が先に claim した(CAS敗北)。呼び出し元は次の
			// dispatch 呼び出しに委ねる(このメソッド自身は再試行しない).
			return null;
		}

		return array_merge( $target, $new_fields );
	}

	/**
	 * Lease期限(`lease_expires_at`)が切れているのに `running` のまま残っている
	 * target_run を検知し、`WPCV_Target_Status::RETRY`(backoff付きで再試行可能)
	 * または `WPCV_Target_Status::FAILED`(最大試行回数超過)へ倒す
	 * (v0.4.0 §Step4: worker のクラッシュ・強制終了・タイムアウトからの回復).
	 *
	 * `attempt_count` を加算するのはこのメソッドのみ(§Step4「最大retry回数」は
	 * 「lease切れで検知した失敗」の回数であって「chunkを何回処理したか」ではない。
	 * `update_chunk_progress()` の docblock 参照。正常な yield による継続は
	 * このメソッドの対象にならない ―― `update_chunk_progress()` が既に
	 * `retry`/`success` へ進めているため、`running` のままlease切れを迎えることが
	 * ない).
	 *
	 * @param int   $run_id 対象の run の id.
	 * @param array $options {
	 *     省略可能なオプション.
	 *
	 *     @type int      $max_attempts 最大試行回数. 省略時は `DEFAULT_MAX_ATTEMPTS`.
	 *     @type callable $backoff      `function( int $attempt_count ): int`
	 *                                  (backoff秒数を返す). 省略時は
	 *                                  `DEFAULT_BACKOFF_BASE_SECONDS * 2^(attempt-1)`を
	 *                                  `DEFAULT_BACKOFF_MAX_SECONDS` で頭打ちにする.
	 * }
	 * @return int 検知して更新した target_run の件数.
	 */
	public function sweep_expired_leases( $run_id, array $options = array() ) {
		$table        = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$max_attempts = isset( $options['max_attempts'] ) ? (int) $options['max_attempts'] : self::DEFAULT_MAX_ATTEMPTS;
		$backoff      = isset( $options['backoff'] ) ? $options['backoff'] : array( __CLASS__, 'default_backoff_seconds' );
		$now_string   = call_user_func( $this->now );
		$swept        = 0;

		foreach ( $this->all_rows() as $row ) {
			if ( (int) $row['run_id'] !== (int) $run_id ) {
				continue;
			}

			if ( WPCV_Target_Status::RUNNING !== $row['status'] ) {
				continue;
			}

			if ( empty( $row['lease_expires_at'] ) || (string) $row['lease_expires_at'] > $now_string ) {
				continue;
			}

			$new_attempt_count = (int) $row['attempt_count'] + 1;

			if ( $new_attempt_count > $max_attempts ) {
				$updated = $this->wpdb->update(
					$table,
					array(
						'status'           => WPCV_Target_Status::FAILED,
						'error_code'       => WPCV_Error_Code::LEASE_EXPIRED,
						'error_message'    => 'lease有効期限切れが最大試行回数を超えたため failed にしました.',
						'attempt_count'    => $new_attempt_count,
						'lease_owner'      => null,
						'lease_expires_at' => null,
						'finished_at'      => $now_string,
					),
					array(
						'id'     => (int) $row['id'],
						'status' => WPCV_Target_Status::RUNNING,
					),
					array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' ),
					array( '%d', '%s' )
				);
			} else {
				$retry_after = gmdate( 'Y-m-d H:i:s', strtotime( $now_string ) + (int) call_user_func( $backoff, $new_attempt_count ) );

				$updated = $this->wpdb->update(
					$table,
					array(
						'status'           => WPCV_Target_Status::RETRY,
						'attempt_count'    => $new_attempt_count,
						'lease_owner'      => null,
						'lease_expires_at' => null,
						'retry_after'      => $retry_after,
					),
					array(
						'id'     => (int) $row['id'],
						'status' => WPCV_Target_Status::RUNNING,
					),
					array( '%s', '%d', '%s', '%s', '%s' ),
					array( '%d', '%s' )
				);
			}

			if ( $updated > 0 ) {
				++$swept;
			}
		}

		return $swept;
	}

	/**
	 * `sweep_expired_leases()` の既定backoff計算(指数backoff、上限あり).
	 *
	 * @param int $attempt_count 今回の(加算後の)試行回数.
	 * @return int backoff秒数.
	 */
	public static function default_backoff_seconds( $attempt_count ) {
		$seconds = self::DEFAULT_BACKOFF_BASE_SECONDS * ( 2 ** max( 0, (int) $attempt_count - 1 ) );

		return (int) min( self::DEFAULT_BACKOFF_MAX_SECONDS, $seconds );
	}

	/**
	 * 指定 run に属する target_run をすべて読み取る(v0.4.0 §Step4: run完了判定・
	 * summary再計算〔`WPCV_Verifier::summarize()` にそのまま渡せる形〕に使う).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return array<int, array>
	 */
	public function find_all_by_run( $run_id ) {
		$rows = array();

		foreach ( $this->all_rows() as $row ) {
			if ( (int) $row['run_id'] === (int) $run_id ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * 指定 run に属する、まだ終端状態(`WPCV_Target_Status::TERMINAL`)に達していない
	 * target_run をすべて `WPCV_Target_Status::ABORTED` にする(v0.4.0 §Step4:
	 * run deadline超過sweep。`WPCV_Chunk_Dispatcher` から呼ぶ).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return int 更新した件数.
	 */
	public function abort_non_terminal_for_run( $run_id ) {
		$table      = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$now_string = call_user_func( $this->now );
		$aborted    = 0;

		foreach ( $this->find_all_by_run( $run_id ) as $row ) {
			if ( WPCV_Target_Status::is_terminal( $row['status'] ) ) {
				continue;
			}

			$updated = $this->wpdb->update(
				$table,
				array(
					'status'           => WPCV_Target_Status::ABORTED,
					'finished_at'      => $now_string,
					'lease_owner'      => null,
					'lease_expires_at' => null,
				),
				array(
					'id'     => (int) $row['id'],
					'status' => $row['status'],
				),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);

			if ( $updated > 0 ) {
				++$aborted;
			}
		}

		return $aborted;
	}

	/**
	 * Chunk処理(manifest取得・ファイル比較)を伴わずに、target_run を直接
	 * 終端状態へ更新する(v0.4.0 §Step4: muplugin loader(§3.6。常に
	 * `unverifiable`/`unknown_source`)や、claim時点で対象が消えていた場合
	 * (`WPCV_Error_Code::TARGET_MISSING`)のように、そもそもファイル単位の比較を
	 * 行わない target 向け。findings を伴わないため `WPCV_Chunk_Result_Repository`
	 * のtransactionは経由しない).
	 *
	 * `$lease_owner`を(`id`に加えて)`status = running`とともにWHEREへ含めて
	 * fencingする理由は `update_chunk_progress()` と同じ(v0.4.0コードレビュー
	 * CR-02是正。クラスdocblock参照)。`WPCV_Chunk_Dispatcher`の各呼び出し箇所は
	 * すべて`claim_next()`が返した行の`lease_owner`をそのまま渡す.
	 *
	 * @param int    $target_run_id 対象の target_run の id.
	 * @param array  $fields        更新するカラム => 値(すべて文字列として扱う。
	 *                              `status`/`error_code`/`error_message`/
	 *                              `manifest_status` 等を想定).
	 * @param string $lease_owner   `claim_next()` がこの処理エピソードに割り当てた
	 *                              lease owner.
	 * @return bool 更新できたら true。false は対象行が見つからなかった、または
	 *              既に別workerに再claimされていた(fencing失敗)ことを意味する.
	 */
	public function finalize_immediate( $target_run_id, array $fields, $lease_owner ) {
		$table  = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$data   = array_merge( array( 'finished_at' => call_user_func( $this->now ) ), $fields );
		$format = array_fill( 0, count( $data ), '%s' );

		$updated = $this->wpdb->update(
			$table,
			$data,
			array(
				'id'          => (int) $target_run_id,
				'status'      => WPCV_Target_Status::RUNNING,
				'lease_owner' => (string) $lease_owner,
			),
			$format,
			array( '%d', '%s', '%s' )
		);

		return $updated > 0;
	}

	/**
	 * `find_by_id()`/`claim_next()`/`sweep_expired_leases()`/`find_all_by_run()`で
	 * 共有する「テーブルの全行を読み取る」処理(v0.4.0 §Step4で `find_by_id()` から
	 * 抽出).テストダブル(`WPCV_Test_Fake_WPDB::get_results()`)がWHERE句を
	 * 解釈しないための設計は `find_by_id()` の docblock と同じ理由.
	 *
	 * @return array<int, array>
	 */
	private function all_rows() {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$rows = $this->wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Id から target_run 行を1件読み取る(`update_chunk_progress()` の内部ヘルパー).
	 *
	 * @param int $target_run_id 対象の target_run の id.
	 * @return array|null 見つからなければ null.
	 */
	private function find_by_id( $target_run_id ) {
		foreach ( $this->all_rows() as $row ) {
			if ( (int) $row['id'] === (int) $target_run_id ) {
				return $row;
			}
		}

		return null;
	}
}
