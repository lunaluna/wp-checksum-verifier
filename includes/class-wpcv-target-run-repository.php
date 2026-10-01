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
	 * `find_streak_for_target()` が1回に読む target_run の件数(v0.5後半
	 * §Step15b設計§3.1「1回に読む件数は未実測の暫定値を置き、コメントに
	 * 『未実測』と書く」).`WPCV_Run_Repository::FAILURE_STREAK_BATCH_SIZE`と
	 * 同じ値を流用する(通常は最初の1バッチで途切れる〔success等に遭遇する〕
	 * ため、ほとんどの呼び出しは1回のクエリで終わる).
	 *
	 * @var int
	 */
	const UNVERIFIABLE_STREAK_BATCH_SIZE = 50;

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
		//
		// v0.5 §Step7: ただし chunk 結果が `error_code` を持つ場合はそれを書く.
		// stat target のベースラインを作り直した run では `baseline_rebuilt` を
		// 完走後も残す必要がある(rev.3 §3.7-b. 「見ていない日」を監査可能にする).
		$data   = array(
			'cursor_path'          => $chunk_result['cursor_path'],
			'manifest_fingerprint' => $chunk_result['manifest_fingerprint'],
			'files_total'          => (int) $chunk_result['files_total'],
			'files_verified'       => $files_verified,
			'findings_total'       => $findings_total,
			'error_code'           => isset( $chunk_result['error_code'] ) ? (string) $chunk_result['error_code'] : null,
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
	 * Target_run の `source` を書き換える(v0.8 §Step6. R2).
	 *
	 * Planner が `github` にした target を、dispatcher が処理の時点で wp.org の処理に
	 * 切り替えた(コア同梱テーマ)ときに、実際に使った照合ソースと記録を一致させる.
	 *
	 * `$lease_owner` を(`id` に加えて)`status = running` とともに WHERE へ含めて fencing
	 * する理由は `update_chunk_progress()` と同じ.
	 *
	 * @param int    $target_run_id 対象の target_run の id.
	 * @param string $lease_owner   `claim_next()` がこの処理エピソードに割り当てた lease owner.
	 * @param string $source        新しい source(`wporg`|`github`|`stat`).
	 * @return bool 更新できたら true. false は対象行が無い、または fencing に失敗した.
	 *
	 * @throws RuntimeException `$wpdb->update()` が SQL エラーで `false` を返した場合.
	 */
	public function set_source( $target_run_id, $lease_owner, $source ) {
		$updated = $this->wpdb->update(
			$this->wpdb->base_prefix . 'wpcv_target_runs',
			array( 'source' => (string) $source ),
			array(
				'id'          => (int) $target_run_id,
				'status'      => WPCV_Target_Status::RUNNING,
				'lease_owner' => (string) $lease_owner,
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);

		if ( false === $updated ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Target_Run_Repository::set_source() の update に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}

		return $updated > 0;
	}

	/**
	 * 依存先の target_run(stat 差分検知 target にとっての本体 target)がまだ
	 * 終わっていないため、処理せずに retry へ戻し、指定秒数だけ claim されない
	 * ようにする(v0.5 §Step6. rev.3 §3.4).
	 *
	 * `mark_scan_incomplete()` を使わない理由は2つ。1つは `error_code` に
	 * `timeout` が入り「時間予算切れ」と誤解させること。もう1つは `retry_after` を
	 * 空にするため直後の `claim_next()` ですぐ再 claim され、本体が別 worker で
	 * 処理中の間 continuation が空回りし続けること.
	 *
	 * `attempt_count` は加算しない(正常な待機であり異常系ではないため).
	 * fencing は `mark_scan_incomplete()` と同じ(`status = running AND lease_owner`).
	 *
	 * @param int    $target_run_id       対象の target_run の id.
	 * @param string $lease_owner         `claim_next()` がこの処理エピソードに割り当てた lease owner.
	 * @param int    $retry_after_seconds 何秒後から再 claim を許すか.
	 * @return bool 更新できたら true(false は fencing 失敗).
	 *
	 * @throws RuntimeException `$wpdb->update()` がSQLエラーで `false` を返した場合.
	 */
	public function defer_for_dependency( $target_run_id, $lease_owner, $retry_after_seconds ) {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';

		$updated = $this->wpdb->update(
			$table,
			array(
				'status'           => WPCV_Target_Status::RETRY,
				'lease_owner'      => null,
				'lease_expires_at' => null,
				'retry_after'      => gmdate( 'Y-m-d H:i:s', strtotime( (string) call_user_func( $this->now ) ) + (int) $retry_after_seconds ),
			),
			array(
				'id'          => (int) $target_run_id,
				'status'      => WPCV_Target_Status::RUNNING,
				'lease_owner' => (string) $lease_owner,
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);

		if ( false === $updated ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Target_Run_Repository::defer_for_dependency() の update に失敗しました: %s',
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
	 * 候補の選定(SELECT)自体は原子的ではないが、claim の安全性は上記のCASのみに
	 * 依存しており、候補選定の非原子性は「同じ行を2 workerが同時に選ぶ」ことは
	 * あっても「2 workerが両方ともclaimに成功する」ことは無い、という性質を壊さない.
	 *
	 * 候補は `run_id` と `status`(schedulable)をSQLで絞り込み、id順に読む
	 * (`idx_run_status` を使う.コードレビュー指摘5. 以前は全runのtarget_runを
	 * 読んでPHPで絞り込んでおり、dispatchのたびに全履歴を読んでいた).
	 * `retry_after`(NULL、または現在時刻以前)の判定だけはPHPで行う
	 * (NULLを含む条件をSQLの1つの条件にまとめにくく、1 run分の候補は少ないため).
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

		$statuses = "'" . implode( "', '", WPCV_Target_Status::SCHEDULABLE ) . "'";
		$sql      = "SELECT * FROM {$table} WHERE run_id = %d AND status IN ( {$statuses} ) ORDER BY id ASC";

		$target = null;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name and hardcoded enums only) built above; dynamic values are bound via prepare().
		foreach ( $this->select_rows( $this->wpdb->prepare( $sql, (int) $run_id ) ) as $row ) {
			if ( ! empty( $row['retry_after'] ) && (string) $row['retry_after'] > $now_string ) {
				continue;
			}

			$target = $row;
			break;
		}

		if ( null === $target ) {
			return null;
		}

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

		// `running` のまま lease 期限を過ぎた行だけをSQLで絞り込む(コードレビュー
		// 指摘5. `idx_run_status` を使う).`lease_expires_at` がNULLの行は
		// SQLの比較で偽になるため、以前の「空なら対象外」と同じ結果になる.
		$sql = "SELECT * FROM {$table} WHERE run_id = %d AND status = %s AND lease_expires_at <= %s";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name and hardcoded enums only) built above; dynamic values are bound via prepare().
		foreach ( $this->select_rows( $this->wpdb->prepare( $sql, (int) $run_id, WPCV_Target_Status::RUNNING, $now_string ) ) as $row ) {
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
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';

		// `idx_run_id` で1 run分だけを読む(コードレビュー指摘5. 以前は全履歴を読んで
		// PHPで絞り込んでいた).id順は以前の`all_rows()`の並び(挿入順)と同じ.
		return $this->select_rows( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC", (int) $run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
	}

	/**
	 * これまでに1回でも検証対象になったことがある target_id の一覧を返す
	 * (v0.5後半 §Step12: `WPCV_Diff_Dispatcher` の target_removed〔アンインストール〕
	 * 検出用. 今回の run の target_run 一覧に含まれない target_id が見つかれば、
	 * その target はアンインストールされたとみなせる).
	 *
	 * 当初は`WPCV_Finding_Repository::find_unresolved_target_ids()`
	 * (`wpcv_findings`から`ended_in_run_id IS NULL`の行を全件取得して絞り込む
	 * 設計)だったが、実地検証(test-armfu.local、1万・10万件規模)でこれが
	 * インストール全体の累積findings件数に比例して重くなる(LIMIT無しの
	 * 全件取得)ことが判明したため、schema v5でこちらへ置き換えた. こちらは
	 * `wpcv_target_runs`(target_idの種類数だけに比例する。既存の
	 * `idx_target_id`が使える)を見るため、target_idが「まだ未解決のfindingを
	 * 持つか」を問わない(=戻り値は旧実装よりわずかに広い集合になりうるが、
	 * 呼び出し元の`end_all_for_target_run()`は対象が0件でも安全なno-opのため
	 * 実害は無い).
	 *
	 * @return string[] 重複なしの target_id 一覧.
	 */
	public function find_all_known_target_ids() {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$sql   = "SELECT DISTINCT target_id FROM {$table}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only, no bound values) built above.
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$target_ids = array();

		foreach ( $rows as $row ) {
			$target_ids[ $row['target_id'] ] = true;
		}

		return array_keys( $target_ids );
	}

	/**
	 * 指定 target の「基準」target_run を探す(v0.5後半プラン §2.1: その target の
	 * 直近の `status = success` の target_run〔今回の run より前〕.Step12の
	 * 差分処理〔`WPCV_Diff_Dispatcher`〕が呼び出し元).
	 *
	 * `usable` は §1.4「v4 より前の行(finding_key を持たない行)しか無い基準は
	 * 基準なし(first)として扱う」の判定材料であり、このメソッド自身は判定しない
	 * (finding の有無を知らないため.呼び出し元が
	 * `WPCV_Finding_Repository::is_baseline_usable()` の結果を渡してこの戻り値に
	 * 合成し `WPCV_Generation_Differ::determine_diff_mode()` へ渡す設計).
	 *
	 * 実地検証(test-armfu.local)で見つかった性能上の懸念への対応(v0.5後半 §Step12.
	 * schema変更は不要): `all_rows()`(`SELECT * FROM wpcv_target_runs`. テーブル
	 * 全件取得)を使わず、`target_id`/`status`/`run_id`をSQLのWHERE句に含めた
	 * クエリに変更した. これは既存の`idx_target_status_run(target_id, status,
	 * run_id)`(Step10で「基準target_runの検索に使う」目的で追加済みだったが、
	 * 実装がSQL側で絞り込んでおらず未使用のまま埋もれていた)を使わせるため.
	 * 特に、target_removed検出(`WPCV_Diff_Dispatcher::handle_target_removed()`)が
	 * 「今回runに現れない既知target」1件ごとにこのメソッドを呼ぶため、既知target数
	 * だけ`all_rows()`の全件取得を繰り返す形になっており、実測でtarget_runs
	 * 2,934行×既知target86件分の取得が1回のfinalizeで発生し4.5秒かかっていた.
	 *
	 * @param string $target_id      対象の target_id.
	 * @param int    $before_run_id  この run より前の target_run だけを対象にする.
	 * @return array{id: int, version: string|null, run_id: int}|null 見つからなければ `null`.
	 */
	public function find_baseline_target_run( $target_id, $before_run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$sql   = "SELECT * FROM {$table} WHERE target_id = %s AND status = %s AND run_id < %d ORDER BY run_id DESC LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; all dynamic values are bound via prepare() below.
		$rows = $this->select_rows( $this->wpdb->prepare( $sql, (string) $target_id, WPCV_Target_Status::SUCCESS, (int) $before_run_id ) );

		// 以前はテストダブルがSQLを解釈しなかったため、ここでPHP側でも絞り込み・
		// 並べ替えをやり直していた.コードレビュー指摘5でテストダブルがこの形の
		// SQLを解釈するようになったため、SQLの結果をそのまま使う.
		if ( empty( $rows ) ) {
			return null;
		}

		return array(
			'id'      => (int) $rows[0]['id'],
			'version' => $rows[0]['version'],
			// v0.6 §Step3: D5の突き合わせ(基準target_runのrun開始時刻が必要)のため追加.
			'run_id'  => (int) $rows[0]['run_id'],
		);
	}

	/**
	 * `diff_mode = version_changed` になった target_run に、WordPressの更新機構を
	 * 通った記録が見つからなかったことを示す `error_code` を書く(v0.6プラン
	 * §3.1・D5. Step3で`WPCV_Diff_Dispatcher`から呼ばれる).
	 *
	 * `update_diff_mode()`とは別メソッドにした ―― `diff_mode`の確定(276-287行目
	 * 付近の共通処理)と、更新イベントの突き合わせ(D5の判定。基準runの取得や
	 * `wpcv_update_events`への問い合わせを伴う)はタイミングが異なり、後者は
	 * `VERSION_CHANGED`と判定された場合にのみ行われるため.
	 *
	 * @param int $target_run_id 対象の target_run の id.
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->update()` がSQLエラーで `false` を返した場合.
	 */
	public function mark_version_changed_unrecorded( $target_run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';

		$updated = $this->wpdb->update(
			$table,
			array( 'error_code' => WPCV_Error_Code::VERSION_CHANGED_UNRECORDED ),
			array( 'id' => (int) $target_run_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Target_Run_Repository::mark_version_changed_unrecorded() の update に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}
	}

	/**
	 * 連続unverifiable(v0.5後半 §Step15b設計§2.1・§2.2)を、指定targetについて
	 * `$current_run_id`から遡って求める.`WPCV_Alert_Sender::send_for_run()`が、
	 * 今回の run で「数える」に該当したtarget_runについてのみ呼ぶ想定
	 * (設計書§3.2「今回のtarget_runのうち『数える』に当たるものについて呼び」).
	 *
	 * `WPCV_Run_Repository::find_failure_streak()`と同じ考え方(そちらの
	 * docblock参照)だが、次の点が異なる:
	 *
	 * - 「数える/途切れさせる」の分類対象はrunではなくtarget_run(§2.1の表)。
	 *   分類そのものはDBに触れない`WPCV_Generation_Differ::
	 *   is_unverifiable_streak_member()`に委ねる.
	 * - 「見ない」経路が2つある: (1) 所属するrunがfailed/abortedのとき(この
	 *   メソッドがrunのstatusを見て判定する)、(2) その target のtarget_runが
	 *   対象のrunにそもそも存在しないとき(アンインストール等)。こちらは、
	 *   このtarget_idで絞り込んだ`wpcv_target_runs`のクエリにその run の行が
	 *   現れないだけで自然に実現される ―― 全runを列挙する必要は無い.
	 *
	 * 読み方: 無制限の全件取得はしない.新しい順に
	 * `UNVERIFIABLE_STREAK_BATCH_SIZE`件ずつ読み、途切れる行が出るか、
	 * それ以上のtarget_runが無くなるまで続ける.「属するrun」の行
	 * (status/alert_status)は、target_runとJOINせず別クエリで`id IN (...)`
	 * により一括で読む(テストダブルがJOINを解釈しないため.設計書§3.1
	 * 「target_runを読む→属するrunをid IN(...)で読む」).
	 *
	 * 「通知済み」の判定は`WPCV_Run_Repository::find_failure_streak()`と全く
	 * 同じ式(そちらのdocblock参照.連続の長さL・閾値Nに対し、今回を除く
	 * 2件目〜`L - N + 1`件目のいずれかに`alert_status = sent`があれば通知済み).
	 *
	 * @param string $target_id      対象のtarget_id.
	 * @param int    $current_run_id 起点のrun(今回のrun)のid.
	 * @param int    $threshold      閾値N(`wpcv_alert_unverifiable_streak`.
	 *                               下限1は呼び出し元が適用済みの前提だが、
	 *                               念のためここでも適用する).
	 * @return array{length: int, notified: bool} `length`は数えた件数(今回を含む).
	 */
	public function find_streak_for_target( $target_id, $current_run_id, $threshold ) {
		$target_id      = (string) $target_id;
		$current_run_id = (int) $current_run_id;
		$threshold      = max( 1, (int) $threshold );

		$counted   = array();
		$before_id = $current_run_id + 1;

		while ( true ) {
			$batch = $this->fetch_target_run_streak_batch( $target_id, $before_id );

			if ( empty( $batch ) ) {
				break; // これ以上遡るtarget_runが無い(連続はここで終わる).
			}

			$runs_by_id = $this->fetch_runs_by_id( array_column( $batch, 'run_id' ) );
			$broke      = false;

			foreach ( $batch as $target_run ) {
				$run_id = (int) $target_run['run_id'];

				if ( $run_id >= $before_id ) {
					// テストダブルがWHEREを解釈せず全件を返した場合の保険.
					continue;
				}

				$run = $runs_by_id[ $run_id ] ?? null;

				if ( null === $run ) {
					// 属するrunが見つからない(通常起こらない). 安全側で見ない.
					continue;
				}

				if ( in_array( (string) ( $run['status'] ?? '' ), array( WPCV_Run_Status::FAILED, WPCV_Run_Status::ABORTED ), true ) ) {
					continue; // §2.1: runがfailed/abortedなら見ない.
				}

				if ( ! WPCV_Generation_Differ::is_unverifiable_streak_member( $target_run ) ) {
					$broke = true;
					break;
				}

				$counted[] = array( 'alert_status' => $run['alert_status'] ?? null );
			}

			if ( $broke ) {
				break;
			}

			$before_id = (int) $batch[ count( $batch ) - 1 ]['run_id'];

			if ( count( $batch ) < self::UNVERIFIABLE_STREAK_BATCH_SIZE ) {
				break; // このtargetのtarget_run履歴を読み切った.
			}
		}

		$length   = count( $counted );
		$notified = false;

		for ( $i = 1; $i <= $length - $threshold; $i++ ) {
			if ( 'sent' === (string) ( $counted[ $i ]['alert_status'] ?? '' ) ) {
				$notified = true;
				break;
			}
		}

		return array(
			'length'   => $length,
			'notified' => $notified,
		);
	}

	/**
	 * `find_streak_for_target()`が1バッチ分のtarget_run(`run_id`/`target_id`/
	 * `status`/`error_code`)を読む.
	 *
	 * @param string $target_id 対象のtarget_id.
	 * @param int    $before_id この値未満の`run_id`だけを対象にする.
	 * @return array<int, array>
	 */
	private function fetch_target_run_streak_batch( $target_id, $before_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';

		return $this->select_rows(
			$this->wpdb->prepare(
				"SELECT run_id, target_id, status, error_code FROM {$table} WHERE target_id = %s AND run_id < %d ORDER BY run_id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				(string) $target_id,
				(int) $before_id,
				self::UNVERIFIABLE_STREAK_BATCH_SIZE
			)
		);
	}

	/**
	 * `run_id`の一覧から、対応する`wpcv_runs`の行(`id`/`status`/`alert_status`)を
	 * まとめて読む(`find_streak_for_target()`専用.クラスdocblock相当の理由で
	 * JOINせず別クエリにする).
	 *
	 * @param array $run_ids 読み取る run の id 一覧(空なら空配列を返す).
	 * @return array<int, array> `id` => 行.
	 */
	private function fetch_runs_by_id( array $run_ids ) {
		$run_ids = array_values( array_unique( array_map( 'intval', $run_ids ) ) );

		if ( empty( $run_ids ) ) {
			return array();
		}

		$table        = $this->wpdb->base_prefix . 'wpcv_runs';
		$placeholders = implode( ', ', array_fill( 0, count( $run_ids ), '%d' ) );
		$sql          = "SELECT id, status, alert_status FROM {$table} WHERE id IN ( {$placeholders} )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name + placeholder count matches $run_ids) built above; all values are bound via prepare().
		$rows = $this->select_rows( $this->wpdb->prepare( $sql, $run_ids ) );

		$by_id = array();

		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		return $by_id;
	}

	/**
	 * 差分処理(v0.5後半 §Step12)が決定した `diff_mode`/`baseline_target_run_id` を
	 * 書き込む.
	 *
	 * このメソッド自身にfencingは無い(差分処理は run 単位の
	 * `WPCV_Run_Repository::claim_diff()` が排他制御を担い、この書き込みは
	 * そのlease保持中にだけ行われる前提のため.`WPCV_Target_Run_Repository`の
	 * 他メソッドが行うtarget単位のlease fencing〔`lease_owner`〕とは異なる層の
	 * 排他である).
	 *
	 * @param int      $target_run_id          対象の target_run の id.
	 * @param string   $diff_mode              `WPCV_Generation_Differ::DIFF_MODE_*`
	 *                                          のいずれか(stat targetは `event`).
	 * @param int|null $baseline_target_run_id 比較に使った基準の target_run の id
	 *                                          (基準が無ければ `null`).
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->update()` がSQLエラーで `false` を返した場合.
	 */
	public function update_diff_mode( $target_run_id, $diff_mode, $baseline_target_run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';

		$updated = $this->wpdb->update(
			$table,
			array(
				'diff_mode'              => (string) $diff_mode,
				'baseline_target_run_id' => null === $baseline_target_run_id ? null : (int) $baseline_target_run_id,
			),
			array( 'id' => (int) $target_run_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Target_Run_Repository::update_diff_mode() の update に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}
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
	 * 組み立て済みのSELECT文を実行して行の配列を返す(`find_by_id()`/`claim_next()`/
	 * `sweep_expired_leases()`/`find_all_by_run()`/`find_baseline_target_run()`で共有する.
	 * コードレビュー指摘5で、テーブル全件を読む`all_rows()`を置き換えた).
	 *
	 * `$sql`は呼び出し元が組み立て済みのもの(動的な値は`prepare()`済み、または
	 * `WPCV_Target_Status`の固定enumのみ)に限る.
	 *
	 * @param string $sql 実行するSELECT文.
	 * @return array<int, array> エラー時・0件時は空配列.
	 */
	private function select_rows( $sql ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $sql is built by the callers above (table name + prepare()d values or hardcoded enums only).
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Id から target_run 行を1件読み取る(`update_chunk_progress()` の内部ヘルパー).
	 *
	 * @param int $target_run_id 対象の target_run の id.
	 * @return array|null 見つからなければ null.
	 */
	private function find_by_id( $target_run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$rows  = $this->select_rows( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $target_run_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.

		return empty( $rows ) ? null : $rows[0];
	}
}
