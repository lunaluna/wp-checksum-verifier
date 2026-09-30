<?php
/**
 * WPCV_Chunk_Dispatcher クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chunk分割実行の中核。DBからclaim可能な target_run を1件取得して1chunk分だけ
 * 処理し、続きが必要なら自分自身を再度呼び出す「wake-up」を予約する
 * (v0.4.0 §Step4)。
 *
 * プラン§Step4の設計方針どおり、Action Scheduler の queue 空判定・claim状態を
 * 進捗の正本にはしない。正本は常に `wpcv_target_runs` テーブル(status・cursor・
 * lease・attempt_count)であり、AS action はこのクラスを「起こす」ための手段に
 * 過ぎない(同じ理由でREST/WP-Cron/CLI asyncも将来同じ `dispatch()` を呼ぶ設計だが、
 * それらの繋ぎ替えはStep5・6で行う。Step4時点では本クラスはAction Scheduler
 * 経由でのみ到達可能で、既存のCLI同期・REST・WP-Cron・asyncフォールバックが
 * 使っている一括 `WPCV_Run_Coordinator` には一切手を入れていない).
 *
 * `dispatch()` 1回の責務は次のいずれか1つだけ:
 *
 * 1. Lease切れ(stale worker)の掃除(`sweep_expired_leases()`)
 * 2. Run自体のdeadline超過を検知して `aborted` へ倒す
 * 3. Runが`queued`/`planning`(target_runsの列挙・保存が別プロセスでまだ完了
 *    していない)なら、claimを試みず待機する
 * 4. `.maintenance`/updater lockが有効なら、claimを試みず延期する(v0.6 §Step6.
 *    D10。target はSKIPPEDにせず、単に継続を後ろへずらすだけ)
 * 5. Claim可能な target_run を1件claimし、1chunk分処理する
 * 6. Claim対象が無ければ、全target_runが終端状態かどうかを見て run を確定する
 *    (終端でなければ、他workerの処理待ちとして遅延re-checkを予約するだけ)
 *
 * v0.4.0コードレビューCR-01是正: 3.を追加する前は、runが`queued`/`running`の
 * いずれかで「active」と判定されるだけで、target_runsがまだ1件も保存されて
 * いない状態(`WPCV_Run_Starter::plan_and_save()`が列挙・保存している最中)でも
 * 5.の分岐に進めてしまい、`WPCV_Verifier::summarize( array() )`が「0件中0件
 * success」を`success`として返してしまう実際のレースコンディションがあった
 * (同一runを複数プロセスが並行して触る経路 ―― Action Schedulerワーカーが
 * planning中に別のREST `POST /run` ポーリングが同じrunを見つけて`dispatch()`
 * を呼ぶ等 ―― で発生し得た)。`planning`状態を新設し、target_runsの保存が
 * 完了するまでrunを`running`にしないことで解消した(`WPCV_Run_Status`・
 * `WPCV_Run_Starter`のクラスdocblock参照).
 *
 * `$context`(version/plugins/plugin_dir/mu_plugin_dir/mu_plugins)は
 * `WPCV_Runner_Async` と同じ理由(AS の args 8,000文字制限。
 * `WPCV_Runner_Async` のクラス docblock 参照)で呼び出し元が毎回
 * `WPCV_Context_Builder::build()` 等で組み立て直したものを渡す設計とし、
 * このクラス自身は保持しない。これには副作用として、plugin対象の
 * `plugin_root_dir`・現在のversionを毎回「実行時点の最新状態」から再解決する
 * ことになり、実行中にプラグインが更新された場合の検知(§8.5)にも寄与する
 * (`resolve_current_plugin_context()` 参照)。
 *
 * v0.4.0コードレビューCR-07是正: `sweep_deadline_and_expired_leases()`を追加した。
 * WP-Cron(`WPCV_Scheduler`)・「今すぐ実行」(`WPCV_Page_Settings`)が新規runを
 * 受け付ける前に「既存のactive runが停止していないか」を確認する用途で、旧
 * v0.3の`WPCV_Run_Repository::sweep_stale_running()`(`started_at`基準・
 * 既定180分)をそのまま使い続けていたことが原因で、6時間`deadline_at`の下で
 * 正常に進行中のchunk実行runを誤って`failed`にしてしまう競合があった(詳細は
 * `sweep_deadline_and_expired_leases()`のdocblock参照)。生存判定を
 * `deadline_at`+target leaseに一本化するため、それらの呼び出し元は
 * (削除済みの)`sweep_stale_running()`ではなくこのメソッドを使う.
 *
 * v0.5 §Step6: stat 差分検知 target(`{dimension}:{slug}:_stat`)の処理を追加した
 * (`process_stat_target()` 参照)。本体 target の照合結果を見て、照合できなかった
 * ものだけ stat 走査する.
 */
class WPCV_Chunk_Dispatcher {

	/**
	 * Chunk処理後に「続きがある」ことを知らせる Action Scheduler フック名.
	 *
	 * @var string
	 */
	const HOOK = 'wpcv_dispatch_chunk';

	/**
	 * Action Scheduler へ enqueue するときの group(`WPCV_Runner_Async::GROUP` と同じ値).
	 *
	 * @var string
	 */
	const GROUP = 'wpcv';

	/**
	 * `verify_manifest_chunk()`/`verify_unknown_files_chunk()` に渡す既定の予算.
	 *
	 * 未実測: 暫定値。実測の上で見直すこと(§数値を決める前に実測するルール)。
	 * `max_seconds` は典型的な共有ホスティングの `max_execution_time`(30〜60秒。
	 * プラン§2.2)よりかなり短く取り、manifest取得のHTTP往復・DB書き込みの
	 * オーバーヘッド分の余裕を残す.
	 *
	 * @var int
	 */
	const DEFAULT_BUDGET_MAX_SECONDS = 20;

	/**
	 * `verify_manifest_chunk()`/`verify_unknown_files_chunk()` に渡す既定のファイル件数上限.
	 *
	 * 未実測: 暫定値.
	 *
	 * @var int
	 */
	const DEFAULT_BUDGET_MAX_FILES = 500;

	/**
	 * Stat 差分検知で、変更 finding を1件にまとめる最小件数(rev.3 §3.7-c).
	 *
	 * 未実測: 暫定値(2026-09-26 ユーザー合意)。実際にプラグインを更新して変わる
	 * ファイルの割合を実地検証で測ってから確定する(プラン rev.3 §9.3 #8).
	 * `wpcv_stat_rollup_min_count` フィルターで変えられる(v0.5 §Step8).
	 *
	 * @var int
	 */
	const DEFAULT_STAT_ROLLUP_MIN_COUNT = 20;

	/**
	 * Stat 差分検知で、変更 finding を1件にまとめる割合(比較したファイル数に対する割合).
	 *
	 * 未実測: 暫定値(`DEFAULT_STAT_ROLLUP_MIN_COUNT` と同じ扱い).
	 * `wpcv_stat_rollup_ratio` フィルターで変えられる(v0.5 §Step8).
	 *
	 * @var float
	 */
	const DEFAULT_STAT_ROLLUP_RATIO = 0.5;

	/**
	 * `wpcv_runs` の永続化層.
	 *
	 * @var WPCV_Run_Repository
	 */
	private $run_repository;

	/**
	 * `wpcv_target_runs` の永続化層.
	 *
	 * @var WPCV_Target_Run_Repository
	 */
	private $target_run_repository;

	/**
	 * Chunk結果をtransactionで確定する調整役.
	 *
	 * @var WPCV_Chunk_Result_Repository
	 */
	private $chunk_result_repository;

	/**
	 * Chunk単位のmanifest比較・未知ファイル走査エンジン.
	 *
	 * @var WPCV_Chunk_Verifier
	 */
	private $chunk_verifier;

	/**
	 * コアの checksum マニフェスト取得ソース.
	 *
	 * @var WPCV_Manifest_Source
	 */
	private $core_source;

	/**
	 * 公式プラグインの checksum マニフェスト取得ソース.
	 *
	 * @var WPCV_Manifest_Source
	 */
	private $plugin_source;

	/**
	 * 未知ファイル走査エンジン.
	 *
	 * @var WPCV_Unknown_File_Scanner
	 */
	private $scanner;

	/**
	 * `claim_next()` に渡す一意な lease owner 文字列を生成する callable.
	 *
	 * @var callable
	 */
	private $lease_owner_factory;

	/**
	 * 「続きがある」ことを知らせる(継続をenqueueする)callable.
	 *
	 * `function( int $run_id, int $delay_seconds ): void`。`$delay_seconds` が
	 * 0 なら即時enqueue、正の値なら `time() + $delay_seconds` に単発予約する.
	 *
	 * 単体テストで実際の Action Scheduler 関数を必要とせずに済むよう、
	 * `WPCV_Runner_Async::enqueue_run()` の `$availability_checker` と同じ理由で
	 * 注入可能にしている.
	 *
	 * @var callable
	 */
	private $continuation_scheduler;

	/**
	 * 現在時刻(Unix timestamp)を返す callable(deadline超過判定に使う).
	 *
	 * `WPCV_Run_Repository`/`WPCV_Target_Run_Repository` と同じ理由(テストで
	 * 固定時刻を注入できるようにするため)で引数で差し替え可能にする。これを
	 * 固定 `time()` にすると、テストが固定の過去日時を `$wpdb` の `now` callable
	 * に注入していてもdeadline判定だけは実際の壁時計時刻を見てしまい、
	 * `deadline_at`(固定の過去日時 + 6時間)が常に「過去」と誤判定されて
	 * すべてのrunが即座に `aborted` になる、という実際に踏んだ不具合がある
	 * (Repository群の `now` callableとdeadline判定の時刻源がずれていたため).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * `wpcv_file_states` の永続化層(v0.5 §Step6. stat 差分検知のベースライン).
	 *
	 * Stat target を処理するときだけ使う。既存のテスト・呼び出し元の引数を
	 * 変えずに済むよう省略可能にしている。未設定のまま stat target を claim した
	 * 場合は例外になり、`dispatch()` の catch でその target_run が failed になる.
	 *
	 * @var WPCV_File_State_Repository|null
	 */
	private $file_state_repository;

	/**
	 * 差分処理(v0.5後半 §Step12)のdispatcher。`dispatch()`が「runが終端に達した」と
	 * 判定した際、差分処理がまだ残っていれば(`diff_status`が
	 * `pending`/`processing`/`alerting`)ここへ委譲する(§配線参照)。`null`なら
	 * 従来通り`run_already_terminal`を返すだけ(既存テストを壊さないための
	 * 後方互換設計。`WPCV_File_State_Repository`と同じ導入パターン).
	 *
	 * @var WPCV_Diff_Dispatcher|null
	 */
	private $diff_dispatcher;

	/**
	 * D5・D6の突き合わせ(v0.6 §Step4. `run_stat_scan()`のD7・D8判定に使う).
	 * `null`なら既存(v0.5)のままversion変化=常にrebuildになる.
	 *
	 * @var WPCV_Update_Event_Matcher|null
	 */
	private $update_event_matcher;

	/**
	 * `.maintenance`/updater lock の判定(v0.6 §Step6. D10).
	 * `null`なら既存(v0.5まで)のまま延期を一切行わない(既存呼び出し元との後方互換).
	 *
	 * @var WPCV_Update_Lock_Detector|null
	 */
	private $update_lock_detector;

	/**
	 * Stat 差分検知を行う本体 target の `error_code`(rev.3 §3.4).
	 *
	 * 「照合元の配布物がそもそも無い」ことを示すものに限る。`http_error`/
	 * `rate_limited` のような一時的な障害を含めると、wp.org 側の不調で
	 * 数万件のベースラインが不意に作られてしまうため、意図的に含めない.
	 *
	 * @var string[]
	 */
	const STAT_ELIGIBLE_ERROR_CODES = array(
		WPCV_Error_Code::MANIFEST_NOT_FOUND,
		WPCV_Error_Code::UNKNOWN_SOURCE,
		WPCV_Error_Code::VERSION_UNKNOWN,
	);

	/**
	 * `.maintenance`/updater lock による延期(D10)の再チェック間隔(秒).
	 *
	 * 実測(2026-09-30. test-armfu.local. `wp_maybe_auto_update()` を実際に
	 * 実行): 小さいプラグイン2件(hello-dolly・akismet)の更新で5秒、コア
	 * (7.1.1→7.1.2)+中規模プラグイン1件(google-site-kit、約6MB)の更新で21秒。
	 * この実測値を踏まえ、待ちすぎず・頻繁に再チェックしすぎない値として30秒とした
	 * (更新が終わっていなければ`schedule_continuation()`により自分自身を再度
	 * この間隔で起こすだけなので、共有ホスティング等で実測より長くかかる場合でも
	 * 単に再チェック回数が増えるだけで正しさには影響しない).
	 *
	 * @var int
	 */
	const DEFER_SECONDS = 30;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Run_Repository             $run_repository           `wpcv_runs` の永続化層.
	 * @param WPCV_Target_Run_Repository      $target_run_repository    `wpcv_target_runs` の永続化層.
	 * @param WPCV_Chunk_Result_Repository    $chunk_result_repository  Chunk結果の確定役.
	 * @param WPCV_Chunk_Verifier             $chunk_verifier           Chunk単位の検証エンジン.
	 * @param WPCV_Manifest_Source            $core_source              §3.2 コア照合ソース.
	 * @param WPCV_Manifest_Source            $plugin_source            §3.4 公式プラグイン照合ソース.
	 * @param WPCV_Unknown_File_Scanner       $scanner                  §3.3 未知ファイル走査エンジン.
	 * @param callable|null                   $lease_owner_factory      省略時は `uniqid( 'wpcv_', true )`.
	 * @param callable|null                   $continuation_scheduler   省略時は Action Scheduler の
	 *                                                                   `as_enqueue_async_action()`/
	 *                                                                   `as_schedule_single_action()`
	 *                                                                   (利用不可なら何もしない).
	 * @param callable|null                   $now                      現在時刻(Unix timestamp)を
	 *                                                                   返す callable. 省略時は `time()`.
	 * @param WPCV_File_State_Repository|null $file_state_repository `wpcv_file_states` の永続化層
	 *                                                                (v0.5 §Step6).
	 * @param WPCV_Diff_Dispatcher|null       $diff_dispatcher       差分処理(v0.5後半 §Step12)の
	 *                                                               dispatcher.
	 * @param WPCV_Update_Event_Matcher|null  $update_event_matcher  D5・D6の突き合わせ(v0.6 §Step4.
	 *                                                                stat targetのD7・D8判定に使う).
	 *                                                                `null`なら既存(v0.5)のまま
	 *                                                                version変化=常にrebuildになる
	 *                                                                (既存呼び出し元との後方互換).
	 * @param WPCV_Update_Lock_Detector|null  $update_lock_detector `.maintenance`/updater lockの
	 *                                                                判定(v0.6 §Step6. D10). `null`
	 *                                                                なら延期を行わない(既存呼び出し元
	 *                                                                との後方互換).
	 */
	public function __construct(
		WPCV_Run_Repository $run_repository,
		WPCV_Target_Run_Repository $target_run_repository,
		WPCV_Chunk_Result_Repository $chunk_result_repository,
		WPCV_Chunk_Verifier $chunk_verifier,
		WPCV_Manifest_Source $core_source,
		WPCV_Manifest_Source $plugin_source,
		WPCV_Unknown_File_Scanner $scanner,
		?callable $lease_owner_factory = null,
		?callable $continuation_scheduler = null,
		?callable $now = null,
		?WPCV_File_State_Repository $file_state_repository = null,
		?WPCV_Diff_Dispatcher $diff_dispatcher = null,
		?WPCV_Update_Event_Matcher $update_event_matcher = null,
		?WPCV_Update_Lock_Detector $update_lock_detector = null
	) {
		$this->run_repository          = $run_repository;
		$this->target_run_repository   = $target_run_repository;
		$this->chunk_result_repository = $chunk_result_repository;
		$this->chunk_verifier          = $chunk_verifier;
		$this->core_source             = $core_source;
		$this->plugin_source           = $plugin_source;
		$this->scanner                 = $scanner;

		$this->lease_owner_factory = $lease_owner_factory ?? static function () {
			return uniqid( 'wpcv_', true );
		};

		$this->continuation_scheduler = $continuation_scheduler ?? array( __CLASS__, 'schedule_via_action_scheduler' );

		$this->now = $now ?? static function () {
			return time();
		};

		$this->file_state_repository = $file_state_repository;
		$this->diff_dispatcher       = $diff_dispatcher;
		$this->update_event_matcher  = $update_event_matcher;
		$this->update_lock_detector  = $update_lock_detector;
	}

	/**
	 * 1回分のdispatchを行う(クラス docblock 参照).
	 *
	 * @param int   $run_id  対象の run の id.
	 * @param array $context `WPCV_Context_Builder::build()` と同じ形
	 *                        (version/plugins/plugin_dir/mu_plugin_dir/mu_plugins).
	 * @return array{action: string} 少なくとも `action` キーを持つ結果
	 *               (`run_not_found`|`run_already_terminal`|`aborted`|
	 *               `waiting_for_plan`|`run_finalized`|`waiting`|`processed`|
	 *               `deferred`(v0.6 §Step6. D10)。テスト・観測用).
	 */
	public function dispatch( $run_id, array $context ) {
		$run_id = (int) $run_id;

		$this->target_run_repository->sweep_expired_leases( $run_id );

		$run = $this->run_repository->find_by_id( $run_id );

		if ( null === $run ) {
			return array( 'action' => 'run_not_found' );
		}

		if ( ! WPCV_Run_Status::is_active( $run['status'] ) ) {
			// 検証(target_runs)自体は終端に達した。差分処理(v0.5後半 §Step12)が
			// まだ残っていれば`WPCV_Diff_Dispatcher`へ委譲する(§配線).
			if ( null !== $this->diff_dispatcher && in_array( $run['diff_status'] ?? null, array( WPCV_Diff_Status::PENDING, WPCV_Diff_Status::PROCESSING, WPCV_Diff_Status::ALERTING ), true ) ) {
				return $this->delegate_to_diff_dispatcher( $run_id, $run['status'] );
			}

			// 既に終端に達している(他workerが先に確定させた、stale
			// sweepでfailed化された等)。継続をenqueueしても意味が無いため、
			// ここで静かに終わる(重複配送されたAS actionのno-op).
			return array(
				'action' => 'run_already_terminal',
				'status' => $run['status'],
			);
		}

		// `queued`/`planning`のまま止まったrun(worker crash等)もここで拾えるよう、
		// claim対象の有無を見る前にdeadlineだけを先に判定する.
		if ( $this->abort_if_deadline_exceeded( $run_id, $run ) ) {
			return array( 'action' => 'aborted' );
		}

		if ( in_array( $run['status'], array( WPCV_Run_Status::QUEUED, WPCV_Run_Status::PLANNING ), true ) ) {
			// v0.4.0コードレビューCR-01是正: target_runsの列挙・保存がまだ完了して
			// いない(別プロセスが`WPCV_Run_Starter::plan_and_save()`の途中)。
			// target_runsが1件も無い可能性があるため、ここでclaim対象0件を
			// 「完了」と誤認してrunをsuccess/partial確定させてはならない
			// (実際に検出されたレースコンディション。このクラスのdocblock参照)。
			// 列挙・保存を担当している側が`mark_planning_running()`で`running`へ
			// 遷移させるまで、ここでは何もせず待機する.
			$this->schedule_continuation( $run_id, 0 );

			return array( 'action' => 'waiting_for_plan' );
		}

		// v0.6 §Step6(D10): claim対象の有無を見る前に、`.maintenance`/updater
		// lockの有無を確かめる。更新処理中に別のtargetをclaimして更新途中の
		// ファイルを読んでしまうことを避けるため(target をSKIPPEDにはせず、
		// 単に継続を後ろへずらすだけ. クラス docblock 参照).
		if ( null !== $this->update_lock_detector && $this->update_lock_detector->is_deferred() ) {
			$this->schedule_continuation( $run_id, self::DEFER_SECONDS );

			return array( 'action' => 'deferred' );
		}

		$lease_owner = call_user_func( $this->lease_owner_factory );
		$claimed     = $this->target_run_repository->claim_next( $run_id, $lease_owner );

		if ( null === $claimed ) {
			return $this->handle_no_claimable_target( $run_id );
		}

		try {
			$this->process_claimed_target( $run_id, $claimed, $context );
		} catch ( Throwable $e ) {
			// 個別targetの処理失敗でrun全体を止めない(他のtargetは処理を
			// 継続できるため)。`WPCV_Run_Coordinator::run()` がrun全体を
			// failedにするのとは異なるレイヤーの判断(こちらはtarget単位).
			$this->target_run_repository->finalize_immediate(
				$claimed['id'],
				array(
					'status'        => WPCV_Target_Status::FAILED,
					'error_message' => get_class( $e ) . ': ' . $e->getMessage(),
				),
				$claimed['lease_owner']
			);
		}

		$this->schedule_continuation( $run_id, 0 );

		return array(
			'action'    => 'processed',
			'target_id' => $claimed['target_id'],
		);
	}

	/**
	 * `WPCV_Diff_Dispatcher::dispatch_diff()` へ委譲し、結果に応じて継続予約の
	 * 要否を判断する(v0.5後半 §Step12・§Step14c・§配線).
	 *
	 * `diff_claimed`(1単位処理できた。まだ続きがある可能性が高い)・
	 * `diff_finalized`(全target完了・`alerting`へ進んだ直後。次のdispatchで
	 * アラート送信〔手順6〕に進むためもう1回継続予約する。Step12時点は終端として
	 * 継続予約しない設計だったが、Step14cで送信を接続するにあたり変更した)・
	 * `diff_alerted`(送信して`done`へ進めた直後。次のdispatchで`run_already_terminal`
	 * を確認して自然に止まる)は、いずれも即座に継続予約する。`diff_not_claimable`
	 * (他プロセスがlease保持中)は`waiting`と同じ考え方で、lease有効期間相当の
	 * 遅延で再チェックする(§配線)。`diff_failed`は終端のため継続予約しない.
	 *
	 * @param int    $run_id       対象の run の id.
	 * @param string $run_status   `wpcv_runs.status`(呼び出し元が既に読んでいる値.
	 *                              レスポンスに含めるためだけに使う).
	 * @return array{action: string, status: string}
	 */
	private function delegate_to_diff_dispatcher( $run_id, $run_status ) {
		$result = $this->diff_dispatcher->dispatch_diff( $run_id );
		$action = $result['action'];

		if ( in_array( $action, array( 'diff_claimed', 'diff_finalized', 'diff_alerted' ), true ) ) {
			$this->schedule_continuation( $run_id, 0 );
		} elseif ( 'diff_not_claimable' === $action ) {
			$this->schedule_continuation( $run_id, WPCV_Run_Repository::DIFF_LEASE_SECONDS );
		}

		return array(
			'action' => $action,
			'status' => $run_status,
		);
	}

	/**
	 * 指定runのlease切れ掃除とdeadline超過チェックだけを行う(claim・chunk処理は
	 * 一切行わない。v0.4.0コードレビューCR-07是正)。
	 *
	 * WP-Cron(`WPCV_Scheduler`)・「今すぐ実行」(`WPCV_Page_Settings`)の受付処理は、
	 * 新規runを予約する前に「既存のactiveなrunが本当に生きているか」を確認する
	 * 必要がある(でなければ、詰まったrunがいつまでも新規runの受付をブロックし
	 * 続ける)。旧v0.3実装はここで `WPCV_Run_Repository::sweep_stale_running()`
	 * (`started_at`からの経過時間のみで判定。既定180分)を使っていたが、これは
	 * 「1 action = 1 run全体」だった旧モデルの閾値であり、v0.4.0のchunk分割実行が
	 * 前提とする「複数回の`dispatch()`呼び出しにまたがって、6時間の`deadline_at`
	 * まで前進し続けてよい」という生存モデルと衝突する。target
	 * lease(`update_chunk_progress()`等)・heartbeatが健全に更新され続けている
	 * 限り、`started_at`から3時間以上経っていても run 自体は正常に進行中であり
	 * 得るため、`sweep_stale_running()`をそのまま使うとこの正常なrunを誤って
	 * `failed`にしてしまう(実際に指摘された競合)。
	 *
	 * このメソッドは `dispatch()` が冒頭で行う「lease切れ掃除→deadline超過
	 * チェック」の部分だけを、claim・chunk処理を経由せずに単独で呼べるように
	 * 切り出したもの(`dispatch()`の`abort_if_deadline_exceeded()`呼び出しと
	 * 同じロジックを共有し、二重実装によるドリフトを避ける).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return void
	 */
	public function sweep_deadline_and_expired_leases( $run_id ) {
		$run_id = (int) $run_id;

		$this->target_run_repository->sweep_expired_leases( $run_id );

		$run = $this->run_repository->find_by_id( $run_id );

		if ( null === $run || ! WPCV_Run_Status::is_active( $run['status'] ) ) {
			return;
		}

		$this->abort_if_deadline_exceeded( $run_id, $run );
	}

	/**
	 * `$run['deadline_at']` を過ぎていれば run を `aborted` にし、非終端の
	 * target_run も併せて `aborted` にする(`dispatch()`/
	 * `sweep_deadline_and_expired_leases()` で共有するヘルパー).
	 *
	 * @param int   $run_id 対象の run の id.
	 * @param array $run    `find_by_id()` が返した run 行.
	 * @return bool 超過していて実際に abort した場合は true.
	 */
	private function abort_if_deadline_exceeded( $run_id, array $run ) {
		if ( empty( $run['deadline_at'] ) || ! $this->is_past( $run['deadline_at'] ) ) {
			return false;
		}

		$this->run_repository->mark_run_aborted( $run_id, 'run deadline を超過したため aborted にしました.' );
		$this->target_run_repository->abort_non_terminal_for_run( $run_id );

		return true;
	}

	/**
	 * Claim対象が無かった場合の分岐(run確定判定、または待機).
	 *
	 * `dispatch()` はrunが`WPCV_Run_Status::RUNNING`の場合にのみここへ到達する
	 * (`queued`/`planning`は`dispatch()`自身が別分岐で待機を返す。クラス
	 * docblock「CR-01是正」参照)。`running`は`WPCV_Run_Starter::plan_and_save()`が
	 * target_runsの保存を終えてから遷移させる状態であるため、ここで
	 * `$target_runs`が空になることはない(`WPCV_Run_Planner::plan()`は最低でも
	 * core targetを1件返す)。したがって空配列に対する `WPCV_Verifier::summarize()`
	 * が「0件中0件success=success」を返す分岐(v0.4.0コードレビューCR-01で
	 * 問題になった経路)は、この呼び出し元の制約上到達しない.
	 *
	 * @param int $run_id 対象の run の id.
	 * @return array{action: string}
	 */
	private function handle_no_claimable_target( $run_id ) {
		$target_runs = $this->target_run_repository->find_all_by_run( $run_id );

		foreach ( $target_runs as $target_run ) {
			if ( ! WPCV_Target_Status::is_terminal( $target_run['status'] ) ) {
				// 他workerがまだ処理中(leaseがまだ有効)。次回のsweepで
				// stale判定できるよう、lease有効期間相当の遅延で自分自身を
				// 再度起こしておく(誰も呼ばなくなって永久に停止することを防ぐ).
				$this->schedule_continuation( $run_id, WPCV_Target_Run_Repository::DEFAULT_LEASE_SECONDS );

				return array( 'action' => 'waiting' );
			}
		}

		$summary = WPCV_Verifier::summarize( $target_runs );
		$this->run_repository->finish_run( $run_id, $summary );

		// v0.5後半 §Step12: `finish_run()`が同じUPDATEで`diff_status=pending`を
		// 書く(`WPCV_Run_Repository::finish_run()`のdocblock参照)。ここで
		// `run_finalized`を無条件に返すと、同期ループ(`WPCV_Run_Coordinator`・
		// CLI同期実行)は`run_finalized`がTERMINAL_ACTIONSに含まれるため即座に
		// 停止してしまい、差分処理へ一切進めない(AS駆動の経路も、`run_finalized`
		// では継続予約をしないため同様に止まる)。`diff_dispatcher`が注入されて
		// いれば、検証完了の直後にこの同じ呼び出しの中で差分処理へ引き継ぐ
		// (§配線。`diff_dispatcher`が無い〔既定null〕場合のみ従来通り
		// `run_finalized`を返す ―― 既存テストとの後方互換のため).
		if ( null !== $this->diff_dispatcher ) {
			return $this->delegate_to_diff_dispatcher( $run_id, (string) $summary['status'] );
		}

		return array(
			'action'  => 'run_finalized',
			'summary' => $summary,
		);
	}

	/**
	 * Claimしたtarget_runを、dimension/slugに応じた処理へ振り分ける.
	 *
	 * @param int   $run_id      対象の run の id.
	 * @param array $target_run  `claim_next()` が返した target_run 行.
	 * @param array $context     `dispatch()` に渡された `$context`.
	 * @return void
	 */
	private function process_claimed_target( $run_id, array $target_run, array $context ) {
		$dimension = $target_run['dimension'];
		$slug      = $target_run['slug'];

		// v0.5 §Step6: stat target は本体と同じ dimension/slug を持つため、
		// dimension/slug による振り分けより先に target_id の接尾辞で判定する.
		if ( WPCV_Target_Resolver::is_stat_id( (string) $target_run['target_id'] ) ) {
			$this->process_stat_target( $run_id, $target_run, $context );
			return;
		}

		if ( WPCV_Target_Resolver::DIMENSION_CORE === $dimension && '_scan' === $slug ) {
			$this->process_core_scan( $run_id, $target_run, $context );
			return;
		}

		if ( WPCV_Target_Resolver::DIMENSION_CORE === $dimension ) {
			$this->process_manifest_chunk(
				$run_id,
				$target_run,
				$this->core_source,
				array( 'version' => (string) $context['version'] ),
				rtrim( ABSPATH, '/' ),
				(string) $context['version'],
				'wporg'
			);
			return;
		}

		if ( WPCV_Target_Resolver::DIMENSION_MUPLUGIN === $dimension && '_scan' === $slug ) {
			$this->process_muplugin_scan( $run_id, $target_run, $context );
			return;
		}

		if ( WPCV_Target_Resolver::DIMENSION_MUPLUGIN === $dimension ) {
			// §3.6: wp.org/GitHub マッピング未実装のため、loaderは常にunverifiable/
			// unknown_source(`WPCV_Verifier::verify_muplugin_area()` と同じ挙動).
			// chunk処理を伴わないため即時終端化する.
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'status'     => WPCV_Target_Status::UNVERIFIABLE,
					'error_code' => WPCV_Error_Code::UNKNOWN_SOURCE,
				),
				$target_run['lease_owner']
			);
			return;
		}

		if ( WPCV_Target_Resolver::DIMENSION_PLUGIN === $dimension ) {
			$this->process_plugin( $run_id, $target_run, $context );
			return;
		}

		// 現状(v0.4.0)ではtheme次元のtarget_runはplannerが列挙しないため
		// 到達しない想定だが、将来次元が増えた際に無言で無視しないよう明示的に
		// unverifiable/unknown_sourceで終端化しておく.
		$this->target_run_repository->finalize_immediate(
			$target_run['id'],
			array(
				'status'     => WPCV_Target_Status::UNVERIFIABLE,
				'error_code' => WPCV_Error_Code::UNKNOWN_SOURCE,
			),
			$target_run['lease_owner']
		);
	}

	/**
	 * Core(manifest比較のみ。未知ファイル走査は`core:_scan`で別途処理)を処理する.
	 *
	 * @param int   $run_id     対象の run の id.
	 * @param array $target_run claim済みのtarget_run行.
	 * @param array $context    `dispatch()` に渡された `$context`.
	 * @return void
	 */
	private function process_core_scan( $run_id, array $target_run, array $context ) {
		$manifest = $this->core_source->get_manifest( array( 'version' => (string) $context['version'] ) );

		if ( null !== $manifest['error_code'] ) {
			// §16-D: マニフェストが取得できなければ、どのファイルが「既知」かを
			// 確定できないため、未知ファイル走査自体を行わない
			// (`WPCV_Verifier::verify_core()` の早期returnと同じ判断).
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'manifest_status' => $manifest['manifest_status'],
					'status'          => WPCV_Target_Status::UNVERIFIABLE,
					'error_code'      => $manifest['error_code'],
				),
				$target_run['lease_owner']
			);
			return;
		}

		$scan_items = array();

		foreach ( WPCV_Verifier::core_unknown_file_areas() as $area ) {
			$area_args           = $area['args'];
			$area_args['budget'] = $this->walk_budget();

			$scan_result = $this->scanner->scan( $area['dir'], $manifest['files'], $area_args );

			if ( $scan_result['truncated'] ) {
				// v0.4.0コードレビューCR-08是正: ここまでの $scan_items は複数
				// 領域(wp-admin/wp-includes等)の一部でしかなく、この不完全な
				// 集合でfingerprintを計算・確定させると次回以降の drift 検知が
				// 意味を失う。chunk_verifierには渡さず、進捗を変えずに retry へ
				// 戻す(次回dispatchで最初から同じ内容を再走査する).
				$this->target_run_repository->mark_scan_incomplete( $target_run['id'], $target_run['lease_owner'] );
				return;
			}

			$scan_items = array_merge( $scan_items, $scan_result['items'] );
		}

		$this->process_scan_chunk( $run_id, $target_run, $scan_items, (string) $context['version'], 'wporg' );
	}

	/**
	 * Muplugin の合成走査target(`muplugin:_scan`)を処理する.
	 *
	 * @param int   $run_id     対象の run の id.
	 * @param array $target_run claim済みのtarget_run行.
	 * @param array $context    `dispatch()` に渡された `$context`.
	 * @return void
	 */
	private function process_muplugin_scan( $run_id, array $target_run, array $context ) {
		$mu_plugin_dir = isset( $context['mu_plugin_dir'] ) ? (string) $context['mu_plugin_dir'] : '';

		if ( '' === $mu_plugin_dir ) {
			// WPMU_PLUGIN_DIR自体が定義されていない(実行時点で構成が変わった等)。
			// 走査対象が無いため、差分ゼロの成功として終端化する.
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array( 'status' => WPCV_Target_Status::SUCCESS ),
				$target_run['lease_owner']
			);
			return;
		}

		$mu_plugins  = isset( $context['mu_plugins'] ) ? (array) $context['mu_plugins'] : array();
		$known_files = WPCV_Verifier::known_muplugin_loader_files( $mu_plugin_dir, array_keys( $mu_plugins ) );

		$scan_result = $this->scanner->scan(
			$mu_plugin_dir,
			$known_files,
			array(
				'recursive'        => true,
				'php_severity'     => 'high',
				'non_php_severity' => 'medium',
				'budget'           => $this->walk_budget(),
			)
		);

		if ( $scan_result['truncated'] ) {
			// v0.4.0コードレビューCR-08是正: core:_scanと同じ理由(直上の
			// process_core_scan()参照)で、不完全な走査結果は使わず retry へ戻す.
			$this->target_run_repository->mark_scan_incomplete( $target_run['id'], $target_run['lease_owner'] );
			return;
		}

		// §5.5: findings.version は NOT NULL のため空文字列にする
		// (`WPCV_Verifier::verify_muplugin_area()` の合成targetと同じ規約).
		$this->process_scan_chunk( $run_id, $target_run, $scan_result['items'], '', 'none' );
	}

	/**
	 * 公式プラグイン1件を処理する。現在の `$context['plugins']` から、
	 * target_run.slug と一致する plugin_file を解決し直す
	 * (クラス docblock 「実行時点の最新状態を再解決する」参照).
	 *
	 * @param int   $run_id     対象の run の id.
	 * @param array $target_run claim済みのtarget_run行.
	 * @param array $context    `dispatch()` に渡された `$context`.
	 * @return void
	 */
	private function process_plugin( $run_id, array $target_run, array $context ) {
		$resolved = $this->resolve_current_plugin_context( $context, $target_run['slug'] );

		if ( null === $resolved ) {
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'status'     => WPCV_Target_Status::UNVERIFIABLE,
					'error_code' => WPCV_Error_Code::TARGET_MISSING,
				),
				$target_run['lease_owner']
			);
			return;
		}

		$this->process_manifest_chunk(
			$run_id,
			$target_run,
			$this->plugin_source,
			array(
				'slug'    => $target_run['slug'],
				'version' => $resolved['version'],
			),
			$resolved['plugin_root_dir'],
			$resolved['version'],
			'wporg'
		);
	}

	/**
	 * `$context['plugins']` から、指定 slug に解決される plugin_file を探す.
	 *
	 * @param array  $context `dispatch()` に渡された `$context`.
	 * @param string $slug    探したい slug(target_run.slug).
	 * @return array{version: string, plugin_root_dir: string, plugin_file: string}|null 見つからなければ `null`
	 *               (plan時点では存在したが、実行時点でローカルから消えている. §Step4).
	 *               `plugin_file` は v0.5 §Step6 で追加(単一ファイルプラグインの stat 走査用).
	 */
	private function resolve_current_plugin_context( array $context, $slug ) {
		$plugins    = isset( $context['plugins'] ) ? (array) $context['plugins'] : array();
		$plugin_dir = isset( $context['plugin_dir'] ) ? (string) $context['plugin_dir'] : '';

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( in_array( (string) $plugin_file, WPCV_Run_Planner::CORE_BUNDLED_PLUGIN_FILES, true ) ) {
				continue;
			}

			$candidate = WPCV_Run_Planner::resolve_plugin_slug_and_root( (string) $plugin_file, $plugin_dir );

			if ( $candidate['slug'] === $slug ) {
				return array(
					'version'         => isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '',
					'plugin_root_dir' => $candidate['plugin_root_dir'],
					'plugin_file'     => (string) $plugin_file,
				);
			}
		}

		return null;
	}

	/**
	 * Stat 差分検知 target(`{dimension}:{slug}:_stat`)を処理する(v0.5 §Step6. rev.3 §3.4).
	 *
	 * 同じ run の本体 target_run の状態で振り分ける:
	 *
	 * | 本体 target_run の状態                                | stat target の扱い                          |
	 * |-------------------------------------------------------|---------------------------------------------|
	 * | 見つからない                                          | unverifiable / target_missing                |
	 * | 非終端(まだ処理中)                                  | `defer_for_dependency()` で retry へ戻す     |
	 * | success(照合できた)                                 | skipped / checksum_covered(stat I/O なし)   |
	 * | unverifiable かつ STAT_ELIGIBLE_ERROR_CODES           | stat 走査を実行                              |
	 * | それ以外(http_error/rate_limited/target_missing/failed 等) | skipped(error_code は本体の値を引き継ぐ) |
	 *
	 * @param int   $run_id     対象の run の id.
	 * @param array $target_run claim済みのtarget_run行.
	 * @param array $context    `dispatch()` に渡された `$context`.
	 * @return void
	 */
	private function process_stat_target( $run_id, array $target_run, array $context ) {
		$body_target_id = WPCV_Target_Resolver::body_id_of_stat( (string) $target_run['target_id'] );
		$body           = null;

		foreach ( $this->target_run_repository->find_all_by_run( $run_id ) as $candidate ) {
			if ( $candidate['target_id'] === $body_target_id ) {
				$body = $candidate;
				break;
			}
		}

		if ( null === $body ) {
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'status'     => WPCV_Target_Status::UNVERIFIABLE,
					'error_code' => WPCV_Error_Code::TARGET_MISSING,
				),
				$target_run['lease_owner']
			);
			return;
		}

		if ( ! WPCV_Target_Status::is_terminal( $body['status'] ) ) {
			// 本体がまだ処理中. 本体の lease が切れるまでの最大時間だけ待ってから
			// 再 claim させる(それより早く見に来ても、本体の worker が生きている限り
			// 状態は変わらない. 死んでいれば lease 切れ sweep で本体が retry に戻る).
			$this->target_run_repository->defer_for_dependency( $target_run['id'], $target_run['lease_owner'], WPCV_Target_Run_Repository::DEFAULT_LEASE_SECONDS );
			return;
		}

		if ( WPCV_Target_Status::SUCCESS === $body['status'] ) {
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'status'     => WPCV_Target_Status::SKIPPED,
					'error_code' => WPCV_Error_Code::CHECKSUM_COVERED,
				),
				$target_run['lease_owner']
			);
			return;
		}

		$body_error_code = isset( $body['error_code'] ) ? (string) $body['error_code'] : '';

		if ( WPCV_Target_Status::UNVERIFIABLE !== $body['status'] || ! in_array( $body_error_code, self::STAT_ELIGIBLE_ERROR_CODES, true ) ) {
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'status'        => WPCV_Target_Status::SKIPPED,
					'error_code'    => '' === $body_error_code ? null : $body_error_code,
					'error_message' => sprintf( '本体 target(%s)が %s のため stat 走査を行いませんでした.', $body_target_id, $body['status'] ),
				),
				$target_run['lease_owner']
			);
			return;
		}

		$this->run_stat_scan( $run_id, $target_run, $context );
	}

	/**
	 * Stat 走査の対象ファイルを集めて `verify_stat_chunk()` に渡し、結果を確定する
	 * (`process_stat_target()` 専用).
	 *
	 * 対象の決め方:
	 * - ディレクトリ型プラグイン: プラグインのディレクトリ全体を再帰的に走査する
	 * - 単一ファイルのプラグイン: そのファイル1つだけ(`WP_PLUGIN_DIR` 全体を走査しない)
	 * - mu-plugin の loader: そのファイル1つだけ
	 *
	 * @param int   $run_id     対象の run の id.
	 * @param array $target_run claim済みのtarget_run行.
	 * @param array $context    `dispatch()` に渡された `$context`.
	 * @return void
	 *
	 * @throws LogicException `WPCV_File_State_Repository` が注入されていない場合.
	 */
	private function run_stat_scan( $run_id, array $target_run, array $context ) {
		if ( null === $this->file_state_repository ) {
			throw new LogicException( 'WPCV_Chunk_Dispatcher requires a WPCV_File_State_Repository to process stat targets.' );
		}

		$scan = $this->collect_stat_items( $target_run, $context );

		if ( null === $scan ) {
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'status'     => WPCV_Target_Status::UNVERIFIABLE,
					'error_code' => WPCV_Error_Code::TARGET_MISSING,
				),
				$target_run['lease_owner']
			);
			return;
		}

		if ( $scan['truncated'] ) {
			// core:_scan と同じ理由(process_core_scan() 参照)で、不完全な走査結果は
			// 使わず retry へ戻す.
			$this->target_run_repository->mark_scan_incomplete( $target_run['id'], $target_run['lease_owner'] );
			return;
		}

		$target_id             = (string) $target_run['target_id'];
		$file_state_repository = $this->file_state_repository;

		// v0.5 §Step7(rev.3 §3.7-a): ベースラインが別の version で作られていれば捨てて
		// 作り直す。run と run の間の通常の更新はこちらで拾う(target_run の version は
		// plan 時点で既に新しい値のため、chunk 間の version 比較では気付けない).
		$version_changed = $file_state_repository->has_rows_with_other_baseline_version( $target_id, $scan['version'] );

		// v0.6 §Step4(§3.2・D7・D8): 前の chunk で既に判定済みなら、その結果を
		// target_run に残した error_code で引き継ぐ(判定は target の最初の chunk で
		// 1回だけ行う。chunk ごとに判定し直すと、途中で更新イベントが入ったときに
		// chunk によって扱いが変わってしまうため。§3.2参照).
		$previous_error_code      = $target_run['error_code'] ?? null;
		$was_rebuilding           = WPCV_Error_Code::BASELINE_REBUILT === $previous_error_code;
		$was_comparing_unrecorded = WPCV_Error_Code::VERSION_CHANGED_UNRECORDED === $previous_error_code;

		if ( $was_rebuilding ) {
			// 継続中: `delete_by_target()` を呼ぶべきかは、今回時点で実際に他の
			// baseline_version を持つ行が残っているかにそのまま従う(通常は
			// 最初の chunk で既に消えているため 2つ目以降は `false` になる。
			// `$rebuilding`〔error_code の維持〕とは意味が異なることに注意.
			// v0.5時点の`$rebuild = has_rows_with_other_baseline_version(...)`が
			// 毎chunk再評価されていたのと同じ挙動).
			$rebuild    = $version_changed;
			$rebuilding = true;
			$unrecorded = false;
		} elseif ( $was_comparing_unrecorded ) {
			$rebuild    = false;
			$rebuilding = false;
			$unrecorded = true;
		} elseif ( $version_changed ) {
			// D7: 記録なし・期間内・設定ON のときだけ、作り直さず今のベースラインと
			// 比べる(§3.2の表「あり・なし・いいえ・ON」の行).それ以外(記録あり・
			// 期間外・設定OFF)は今と同じ挙動(作り直す)にする.
			if ( $this->should_compare_stat_without_rebuild( $target_id, $scan['version'], (int) $run_id ) ) {
				$rebuild    = false;
				$rebuilding = false;
				$unrecorded = true;
			} else {
				$rebuild    = true;
				$rebuilding = true;
				$unrecorded = false;
			}
		} elseif ( $this->has_matching_update_event_for_stat( $target_id, $scan['version'], (int) $run_id ) ) {
			// D8: version変化が無くても、更新イベントの記録があれば黙って作り直す
			// (§3.2の表「なし・あり」の行。期間・設定は問わない).
			$rebuild    = true;
			$rebuilding = true;
			$unrecorded = false;
		} else {
			$rebuild    = false;
			$rebuilding = false;
			$unrecorded = false;
		}

		// rev.3 §3.6: 今回の run より前に書かれた行が1件も無ければ初回とみなす
		// (今回の run が書いた行は last_seen_run_id が同じなので含まれない).
		$baseline_mode = $rebuild || ! $file_state_repository->has_baseline_before_run( $target_id, (int) $run_id );

		$chunk_result = $this->chunk_verifier->verify_stat_chunk(
			array(
				'target_id'            => $target_id,
				'dimension'            => $target_run['dimension'],
				'slug'                 => $target_run['slug'],
				'version'              => $scan['version'],
				'source'               => 'stat',
				'run_id'               => (int) $run_id,
				'scan_items'           => $scan['items'],
				'baseline_mode'        => $baseline_mode,
				'load_previous_states' => static function ( array $paths ) use ( $file_state_repository, $target_id ) {
					$keys = array();
					foreach ( $paths as $path ) {
						$keys[] = WPCV_File_State_Repository::compute_state_key( $target_id, $path );
					}

					$by_path = array();
					foreach ( $file_state_repository->find_by_state_keys( $keys ) as $row ) {
						$by_path[ $row['path'] ] = $row;
					}

					return $by_path;
				},
				'cursor_path'          => $target_run['cursor_path'] ?? null,
				'previous_fingerprint' => $target_run['manifest_fingerprint'] ?? null,
				'previous_version'     => $target_run['version'],
				'budget'               => $this->default_budget(),
				'rollup'               => array(
					/**
					 * Stat 差分検知で変更 finding を1件にまとめる最小件数を変える(v0.5 §Step8).
					 *
					 * 既定値は未実測の暫定値のため、設定画面ではなくフィルターだけで
					 * 変えられるようにしている(2026-09-26 ユーザー判断).
					 *
					 * @param int    $min_count 既定 `DEFAULT_STAT_ROLLUP_MIN_COUNT`.
					 * @param string $target_id stat target の target_id.
					 */
					'min_count' => (int) apply_filters( 'wpcv_stat_rollup_min_count', self::DEFAULT_STAT_ROLLUP_MIN_COUNT, $target_id ),
					/**
					 * Stat 差分検知で変更 finding を1件にまとめる割合(0〜1)を変える(v0.5 §Step8).
					 *
					 * @param float  $ratio     既定 `DEFAULT_STAT_ROLLUP_RATIO`.
					 * @param string $target_id stat target の target_id.
					 */
					'ratio'     => (float) apply_filters( 'wpcv_stat_rollup_ratio', self::DEFAULT_STAT_ROLLUP_RATIO, $target_id ),
				),
				'target_root_path'     => $scan['root_path'],
			)
		);

		if ( ! $chunk_result['needs_retry'] ) {
			if ( $rebuild ) {
				// 古いベースラインの削除は commit_chunk() が同じトランザクションで行う.
				$chunk_result['rebuild_baseline'] = true;
			}

			if ( $rebuilding ) {
				$chunk_result['error_code'] = WPCV_Error_Code::BASELINE_REBUILT;
			} elseif ( $unrecorded ) {
				// D7(v0.6 §Step4): 比較は続けるが、記録が無かったことを残す.
				$chunk_result['error_code'] = WPCV_Error_Code::VERSION_CHANGED_UNRECORDED;
			}

			if ( $chunk_result['completed'] && ! $baseline_mode ) {
				$chunk_result = $this->add_missing_findings( $chunk_result, $target_run, (int) $run_id, $scan['version'] );
			}
		}

		$this->chunk_result_repository->commit_chunk( $run_id, $target_run['id'], $target_id, $chunk_result, $target_run['lease_owner'], $scan['version'] );
	}

	/**
	 * D7(v0.6 §Step4・§3.2): version変化があった stat target について、作り直さず
	 * 今のベースラインと比べ続けるべきかを判定する。記録あり・期間外
	 * (`wpcv_update_events_since`より前・未設定を含む)・設定OFFのいずれかなら
	 * `false`(=今と同じ作り直し)を返す.
	 *
	 * `$this->update_event_matcher`が注入されていない場合は常に`false`(既存
	 * v0.5の挙動のまま. コンストラクタのdocblock参照).
	 *
	 * @param string $target_id stat target の target_id(`{dimension}:{slug}:_stat`).
	 * @param string $version   今回の version(空文字列なら`null`として扱う).
	 * @param int    $run_id    今回の run の id.
	 * @return bool
	 */
	private function should_compare_stat_without_rebuild( $target_id, $version, $run_id ) {
		if ( null === $this->update_event_matcher || ! WPCV_Settings::get_alert_unrecorded_version_change_enabled() ) {
			return false;
		}

		// 基準runの開始時刻は「stat targetが最後に正常に処理されたrun」から引くが
		// (stat target自身のtarget_idで検索)、`wpcv_update_events`への記録は
		// 本体プラグインのtarget_id(接尾辞なし)で行われる(`WPCV_Update_Event_Recorder`
		// 参照)ため、記録の突き合わせには本体のtarget_idを使う.
		$baseline_target_run = $this->target_run_repository->find_baseline_target_run( $target_id, $run_id );
		$body_target_id      = WPCV_Target_Resolver::body_id_of_stat( $target_id );

		if ( $this->update_event_matcher->is_outside_tracked_period( $baseline_target_run ) ) {
			return false;
		}

		return ! $this->update_event_matcher->has_matching_event( $body_target_id, '' === $version ? null : $version, $baseline_target_run );
	}

	/**
	 * D8(v0.6 §Step4・§3.2): version変化が無かった stat target について、更新
	 * イベントの記録があるかを判定する(期間・設定は問わない。記録があれば
	 * 呼び出し元が黙って作り直す).
	 *
	 * `$this->update_event_matcher`が注入されていない場合は常に`false`(既存
	 * v0.5の挙動のまま).
	 *
	 * @param string $target_id stat target の target_id.
	 * @param string $version   今回の version(空文字列なら`null`として扱う).
	 * @param int    $run_id    今回の run の id.
	 * @return bool
	 */
	private function has_matching_update_event_for_stat( $target_id, $version, $run_id ) {
		if ( null === $this->update_event_matcher ) {
			return false;
		}

		// `should_compare_stat_without_rebuild()`と同じ理由(docblock参照)で、
		// 基準runはstat target自身のtarget_idで、記録の突き合わせは本体の
		// target_idで行う.
		$baseline_target_run = $this->target_run_repository->find_baseline_target_run( $target_id, $run_id );
		$body_target_id      = WPCV_Target_Resolver::body_id_of_stat( $target_id );

		return $this->update_event_matcher->has_matching_event( $body_target_id, '' === $version ? null : $version, $baseline_target_run );
	}

	/**
	 * Stat target の最後の chunk で、前回のベースラインにあったのに今回の run で
	 * 一度も見つからなかったファイルを `missing` finding にし、削除する行の id を
	 * chunk 結果に載せる(v0.5 §Step7. rev.3 §3.3).
	 *
	 * この時点ではまだ今回の chunk の upsert をしていないため、`find_stale()` は
	 * 今回の chunk で見つかったファイルも「古い」行として返す。それらは
	 * `baseline_rows` の path で除外する(前の chunk で見つかったファイルは
	 * 既に last_seen_run_id が今回の run になっているので最初から含まれない).
	 *
	 * 削除自体は `commit_chunk()` が findings と同じトランザクションで行う。
	 * 消した行は次の run で古い行として出てこないため、同じ削除が2回 finding に
	 * なることはない.
	 *
	 * @param array  $chunk_result `verify_stat_chunk()` の戻り値.
	 * @param array  $target_run   claim済みのtarget_run行.
	 * @param int    $run_id       今回の run の id.
	 * @param string $version      本体の現在の version.
	 * @return array `findings` と `stale_state_ids` を追加した chunk 結果.
	 */
	private function add_missing_findings( array $chunk_result, array $target_run, $run_id, $version ) {
		$seen = array();
		foreach ( $chunk_result['baseline_rows'] as $row ) {
			$seen[ $row['path'] ] = true;
		}

		$stale_ids = array();

		foreach ( $this->file_state_repository->find_stale( (string) $target_run['target_id'], $run_id ) as $row ) {
			if ( isset( $seen[ $row['path'] ] ) ) {
				continue;
			}

			$chunk_result['findings'][] = WPCV_Verifier::make_finding_for_stat_missing(
				(string) $target_run['target_id'],
				(string) $target_run['dimension'],
				(string) $target_run['slug'],
				(string) $version,
				'stat',
				(string) $row['path']
			);

			$stale_ids[] = (int) $row['id'];
		}

		$chunk_result['stale_state_ids'] = $stale_ids;

		return $chunk_result;
	}

	/**
	 * Stat 走査の対象ファイル(size/ctime/mtime 付き)と、本体の現在の version を返す
	 * (`run_stat_scan()` 専用).
	 *
	 * @param array $target_run claim済みのtarget_run行.
	 * @param array $context    `dispatch()` に渡された `$context`.
	 * @return array{items: array, truncated: bool, version: string, root_path: string}|null 対象が
	 *               実行時点で見つからなければ null. `root_path` は target のルートの
	 *               ABSPATH 相対パス(大量変更をまとめた finding の path に使う. v0.5 §Step7).
	 */
	private function collect_stat_items( array $target_run, array $context ) {
		if ( WPCV_Target_Resolver::DIMENSION_PLUGIN === $target_run['dimension'] ) {
			$resolved = $this->resolve_current_plugin_context( $context, $target_run['slug'] );

			if ( null === $resolved ) {
				return null;
			}

			$plugin_dir = rtrim( WPCV_Path_Normalizer::to_forward_slashes( (string) $context['plugin_dir'] ), '/' );
			$root_dir   = rtrim( WPCV_Path_Normalizer::to_forward_slashes( $resolved['plugin_root_dir'] ), '/' );

			if ( $root_dir === $plugin_dir ) {
				$scan              = $this->scanner->stat_file( $plugin_dir . '/' . $resolved['plugin_file'], 'high', 'medium' );
				$scan['root_path'] = WPCV_Path_Normalizer::to_relative( $plugin_dir . '/' . $resolved['plugin_file'] );
			} else {
				$scan = $this->scanner->scan(
					$root_dir,
					array(),
					array(
						'recursive'        => true,
						'php_severity'     => 'high',
						'non_php_severity' => 'medium',
						'budget'           => $this->walk_budget(),
						'collect_stat'     => true,
					)
				);

				$scan['root_path'] = WPCV_Path_Normalizer::to_relative( $root_dir );
			}

			$scan['version'] = $resolved['version'];

			return $scan;
		}

		if ( WPCV_Target_Resolver::DIMENSION_MUPLUGIN === $target_run['dimension'] ) {
			$mu_plugin_dir = isset( $context['mu_plugin_dir'] ) ? rtrim( WPCV_Path_Normalizer::to_forward_slashes( (string) $context['mu_plugin_dir'] ), '/' ) : '';

			if ( '' === $mu_plugin_dir ) {
				return null;
			}

			// §5.5: findings.version は NOT NULL のため空文字列にする(muplugin:_scan と同じ規約).
			$scan              = $this->scanner->stat_file( $mu_plugin_dir . '/' . $target_run['slug'], 'high', 'medium' );
			$scan['version']   = '';
			$scan['root_path'] = WPCV_Path_Normalizer::to_relative( $mu_plugin_dir . '/' . $target_run['slug'] );

			return $scan;
		}

		return null;
	}

	/**
	 * Manifestベース(core/plugin)のchunk処理を1回分行う.
	 *
	 * @param int                  $run_id          対象の run の id.
	 * @param array                $target_run      claim済みのtarget_run行.
	 * @param WPCV_Manifest_Source $source          manifest取得ソース.
	 * @param array                $manifest_context `$source->get_manifest()` に渡すcontext.
	 * @param string               $base_dir        manifestの相対パスを解決する基準ディレクトリ.
	 * @param string               $version         今回dispatcherが観測した「現在の」version.
	 * @param string               $source_label     findings.source に記録する値(常に `wporg`).
	 * @return void
	 */
	private function process_manifest_chunk( $run_id, array $target_run, WPCV_Manifest_Source $source, array $manifest_context, $base_dir, $version, $source_label ) {
		$manifest = $source->get_manifest( $manifest_context );

		if ( null !== $manifest['error_code'] ) {
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'manifest_status' => $manifest['manifest_status'],
					'status'          => WPCV_Target_Status::UNVERIFIABLE,
					'error_code'      => $manifest['error_code'],
				),
				$target_run['lease_owner']
			);
			return;
		}

		$chunk_result = $this->chunk_verifier->verify_manifest_chunk(
			array(
				'target_id'            => $target_run['target_id'],
				'dimension'            => $target_run['dimension'],
				'slug'                 => $target_run['slug'],
				'version'              => $version,
				'source'               => $source_label,
				'base_dir'             => $base_dir,
				'manifest_files'       => $manifest['files'],
				'cursor_path'          => $target_run['cursor_path'] ?? null,
				'previous_fingerprint' => $target_run['manifest_fingerprint'] ?? null,
				'previous_version'     => $target_run['version'],
				'budget'               => $this->default_budget(),
			)
		);

		// v0.4.0コードレビューCR-09是正: manifestを実際に取得できた(=ここまで到達した)
		// ことを`manifest_status`として確定させ、chunk_result_repositoryにcursor・
		// 集計値と同じ更新で永続化させる。これが無いと、成功したtarget_runでも
		// planner挿入時の既定値`missing`(`WPCV_Run_Planner`のクラスdocblock参照)の
		// まま残り、成功しているのに「manifest無し」を示す矛盾した記録になっていた
		// (レビュー指摘).
		$chunk_result['manifest_status'] = $manifest['manifest_status'];

		$this->chunk_result_repository->commit_chunk( $run_id, $target_run['id'], $target_run['target_id'], $chunk_result, $target_run['lease_owner'], $version );
	}

	/**
	 * 未知ファイル走査ベース(core:_scan/muplugin:_scan)のchunk処理を1回分行う.
	 *
	 * @param int    $run_id      対象の run の id.
	 * @param array  $target_run  claim済みのtarget_run行.
	 * @param array  $scan_items  `WPCV_Unknown_File_Scanner::scan()` の戻り値.
	 * @param string $version     findings.version に記録する値.
	 * @param string $source_label findings.source に記録する値.
	 * @return void
	 */
	private function process_scan_chunk( $run_id, array $target_run, array $scan_items, $version, $source_label ) {
		$chunk_result = $this->chunk_verifier->verify_unknown_files_chunk(
			array(
				'target_id'            => $target_run['target_id'],
				'dimension'            => $target_run['dimension'],
				'slug'                 => $target_run['slug'],
				'version'              => $version,
				'source'               => $source_label,
				'scan_items'           => $scan_items,
				'cursor_path'          => $target_run['cursor_path'] ?? null,
				'previous_fingerprint' => $target_run['manifest_fingerprint'] ?? null,
				'budget'               => $this->default_budget(),
			)
		);

		$this->chunk_result_repository->commit_chunk( $run_id, $target_run['id'], $target_run['target_id'], $chunk_result, $target_run['lease_owner'] );
	}

	/**
	 * `WPCV_Chunk_Verifier::verify_*_chunk()` に渡す既定の予算を組み立てる.
	 *
	 * @return array `max_files`/`max_seconds`/`memory_limit_bytes`(取得できる場合のみ).
	 */
	private function default_budget() {
		$budget = array(
			'max_files'   => self::DEFAULT_BUDGET_MAX_FILES,
			'max_seconds' => self::DEFAULT_BUDGET_MAX_SECONDS,
		);

		$memory_limit_bytes = self::memory_limit_bytes();

		if ( null !== $memory_limit_bytes ) {
			$budget['memory_limit_bytes'] = $memory_limit_bytes;
		}

		return $budget;
	}

	/**
	 * `WPCV_Unknown_File_Scanner::scan()` の walk 自体に渡す予算を組み立てる
	 * (v0.4.0コードレビューCR-08是正).
	 *
	 * `default_budget()` の `max_files`(既定500)は「chunk_verifierが1回で
	 * 比較・finding化する件数」の上限であり、walkが訪れる全エントリ数(既知ファイル
	 * 含む。core領域だけで数千件が普通)に対して同じ値を適用すると、通常規模の
	 * インストールでも即座に打ち切られ続けてしまう。そのため `max_files` を除いた
	 * `max_seconds`/`memory_limit_bytes` のみを walk へ渡す.
	 *
	 * @return array `max_seconds`/`memory_limit_bytes`(取得できる場合のみ).
	 */
	private function walk_budget() {
		$budget = $this->default_budget();

		unset( $budget['max_files'] );

		return $budget;
	}

	/**
	 * PHP の `memory_limit` ini設定をバイト数へ変換する(`-1`＝無制限の場合は `null`).
	 *
	 * @return int|null
	 */
	private static function memory_limit_bytes() {
		// `ini_get()` は該当キーが無い場合 `false` を返す(PHP自体の挙動)。
		// `(string) false` は空文字列になるため、直後の空文字列チェックへ
		// 素通しして問題ない(PHPStanが「常にfalse」と誤検知する === false との
		// 直接比較を避けるため、あえてこの経路にしている).
		$limit = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $limit || '-1' === $limit ) {
			return null;
		}

		$unit  = strtolower( substr( $limit, -1 ) );
		$value = (int) $limit;

		switch ( $unit ) {
			case 'g':
				return $value * 1024 * 1024 * 1024;
			case 'm':
				return $value * 1024 * 1024;
			case 'k':
				return $value * 1024;
			default:
				return $value;
		}
	}

	/**
	 * 与えられたMySQL DATETIME文字列(UTC)が現在時刻より過去かどうかを判定する.
	 *
	 * @param string $datetime `Y-m-d H:i:s` 形式のUTC日時文字列.
	 * @return bool
	 */
	private function is_past( $datetime ) {
		$timestamp = strtotime( (string) $datetime );

		return false !== $timestamp && $timestamp <= call_user_func( $this->now );
	}

	/**
	 * `$this->continuation_scheduler` を呼ぶ.
	 *
	 * 既定実装(`schedule_via_action_scheduler()`)は予約失敗時に例外を投げる
	 * (v0.4.0コードレビューCR-06是正)。ここでは捕捉せずそのまま呼び出し元
	 * (`dispatch()`)へ伝播させる ―― `dispatch()` はAction Schedulerのワーカー
	 * コンテキストからのみ呼ばれるため、例外はAction Scheduler自身がそのaction
	 * を failed として記録するだけで安全に吸収される(`schedule_via_action_scheduler()`
	 * のdocblock参照).
	 *
	 * @param int $run_id        対象の run の id.
	 * @param int $delay_seconds 0なら即時、正の値なら遅延.
	 * @return void
	 *
	 * @throws RuntimeException 既定実装が継続予約に失敗した場合.
	 */
	private function schedule_continuation( $run_id, $delay_seconds ) {
		call_user_func( $this->continuation_scheduler, $run_id, $delay_seconds );
	}

	/**
	 * `$continuation_scheduler` の既定実装(実際の Action Scheduler 呼び出し).
	 *
	 * 可用性チェックを固定にせず毎回 `function_exists()`/`ActionScheduler::is_initialized()`
	 * で確認するのは `WPCV_Runner_Async::enqueue_run()` と同じ理由
	 * (PHPUnitプロセス内でのスタブ漏れ対策。同クラスのdocblock参照).
	 *
	 * `as_enqueue_async_action()`/`as_schedule_single_action()` の戻り値
	 * (action ID。予約失敗時は `0`)を確認せず捨てていた(v0.4.0コードレビュー
	 * CR-06是正)。捨てたままだと、AS の args 文字数制限超過(`WPCV_Runner_Async`
	 * のクラスdocblock参照)や一時的なDB書き込み失敗で予約が失敗しても、
	 * このrunを再度起こす手段が無いまま `running` で永久に停止してしまう
	 * (レビュー指摘)。呼び出し元はAction Schedulerのワーカーコンテキスト
	 * (`WPCV_Plugin::dispatch_chunk()`/`WPCV_Runner_Async::run_async_action()`。
	 * いずれもAS action自体のフックハンドラ)からのみ到達するため、ここで
	 * 例外を投げてもAction Scheduler自身がフック実行中の例外を捕捉しその
	 * action を failed 記録するだけで済み、CR-05是正時に`plugins_loaded`への
	 * 例外化を避けた(毎リクエスト無条件フックでサイト全体を巻き込む)ような
	 * 副作用は無い.
	 *
	 * @param int $run_id        対象の run の id.
	 * @param int $delay_seconds 0なら即時、正の値なら遅延.
	 * @return void
	 *
	 * @throws RuntimeException `as_enqueue_async_action()`/`as_schedule_single_action()`
	 *                          が正の action ID を返さなかった場合.
	 */
	public static function schedule_via_action_scheduler( $run_id, $delay_seconds ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! class_exists( 'ActionScheduler' ) || ! ActionScheduler::is_initialized() ) {
			return;
		}

		if ( $delay_seconds > 0 ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				$action_id = as_schedule_single_action( time() + (int) $delay_seconds, self::HOOK, array( (int) $run_id ), self::GROUP );

				if ( (int) $action_id <= 0 ) {
					throw new RuntimeException(
						esc_html(
							sprintf(
								'WPCV_Chunk_Dispatcher::schedule_via_action_scheduler() は run #%d の継続予約(as_schedule_single_action)に失敗しました(戻り値: %d).',
								(int) $run_id,
								(int) $action_id
							)
						)
					);
				}
			}
			return;
		}

		$action_id = as_enqueue_async_action( self::HOOK, array( (int) $run_id ), self::GROUP );

		if ( (int) $action_id <= 0 ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Chunk_Dispatcher::schedule_via_action_scheduler() は run #%d の継続予約(as_enqueue_async_action)に失敗しました(戻り値: %d).',
						(int) $run_id,
						(int) $action_id
					)
				)
			);
		}
	}

	/**
	 * 無効化フックから呼ぶ. Action Scheduler に予約済みの継続アクション
	 * (self::GROUP に属するすべて. self::HOOK の chunk 継続と
	 * `WPCV_Runner_Async::HOOK`(`wpcv_run_async`)の起動の両方が対象)を
	 * キャンセルする(v0.5.1. rev.3/roadmap §2.1 ★1・§13).
	 *
	 * `as_unschedule_all_actions( '', array(), self::GROUP )` は hook を空にすると
	 * `ActionScheduler_Store::cancel_actions_by_group()` に委譲される(Action
	 * Scheduler本体の実装で確認済み). hookごとに `as_unschedule_all_actions()` を
	 * 個別に呼ぶより、このgroupが本プラグイン専用(他プラグインと共有しない
	 * 固定値 'wpcv'. 本クラス・`WPCV_Runner_Async` のクラスdocblock参照)である
	 * ことを利用してgroup単位でまとめて消したほうが、新しい継続アクションを
	 * 追加した際にこのメソッドを更新し忘れるリスクが無い.
	 *
	 * uninstall.php 側では対応しない: `uninstall_plugin()` を呼ぶ経路
	 * (管理画面の削除リンク・WP-CLIの `wp plugin uninstall`)はいずれも
	 * 無効化済みのプラグインにしか働かない(WP-CLI実ソース `Plugin_Command::
	 * uninstall()` の `is_plugin_active()` チェックで確認済み)ため、
	 * アンインストール時点でこのメソッドは既に実行済み. 加えて uninstall.php は
	 * プラグイン本体(bundled Action Scheduler含む)を読み込まないため、
	 * `as_unschedule_all_actions()` 自体が呼べない(§7-3是正のdocblock参照).
	 *
	 * 未対応のまま残ったactionが実行されるとどうなるか(実装前に実ソースで
	 * 確認済み): Action Scheduler の `ActionScheduler_Action::execute()` は
	 * `has_action( $hook )` が偽なら実行前に例外を投げてfailedとして記録する
	 * (無反応のdo_actionで静かに無視される訳ではない). 本メソッドが無いと、
	 * 無効化後に他プラグインの操作等で稀にAction Schedulerのワーカーが動いた
	 * 場合、削除済みのはずの本プラグインについてのエラーがログ・Scheduled
	 * Actions画面に残り続ける.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) || ! class_exists( 'ActionScheduler' ) || ! ActionScheduler::is_initialized() ) {
			return;
		}

		as_unschedule_all_actions( '', array(), self::GROUP );
	}
}

add_action( WPCV_Chunk_Dispatcher::HOOK, array( 'WPCV_Plugin', 'dispatch_chunk' ), 10, 1 );
