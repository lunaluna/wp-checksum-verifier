<?php
/**
 * WPCV_Plugin クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 本番の WordPress 環境から `WPCV_Run_Coordinator` を組み立てる composition root(v0.3 §Step1).
 *
 * `WPCV_Chunk_Dispatcher` / 各 Repository / `WPCV_Run_Coordinator` はいずれも
 * コンストラクタ注入で依存(`WPCV_Manifest_Source` 実装・`$wpdb`)を受け取る設計
 * (単体テストでテストダブルに差し替えられるようにするため)にしてある。このクラスは唯一、
 * 実際の `global $wpdb` と本番用のソース実装(`WPCV_Source_Core` /
 * `WPCV_Source_Wporg_Plugin` / `WPCV_Unknown_File_Scanner`)を組み合わせて配線する
 * 場所として新設した(WP-CLI / WP-Cron / REST いずれのエントリポイントからも
 * 同じ組み立てを使い回すため、エントリポイントごとに `new` し直さない)。
 *
 * v0.4.0 §Step1で `WPCV_Repository` を `WPCV_Run_Repository`/
 * `WPCV_Target_Run_Repository`/`WPCV_Finding_Repository` へ責務分割したことに
 * 合わせ、`repository()` も3つのアクセサへ分割した(`WPCV_Run_Repository` の
 * クラス docblock 参照).
 *
 * v0.4.0 §Step4で `chunk_result_repository()`/`chunk_dispatcher()` を追加し、
 * `dispatch_chunk()`(`WPCV_Chunk_Dispatcher::HOOK` のフックハンドラ)を新設した。
 *
 * v0.4.0 §Step5で `run_coordinator()` の実体を「chunk dispatcherを完走まで
 * ループするadapter」へ書き換えた(`WPCV_Run_Coordinator` のクラス docblock 参照)。
 * `run_coordinator()` が使う `WPCV_Chunk_Dispatcher` インスタンスは
 * `chunk_dispatcher()`(AS action向け。継続を実際にenqueueする)とは別に、
 * continuation schedulerをno-opにした `sync_dispatcher()` を使う(同期ループ自身が
 * 「次のdispatch呼び出し」を供給するため。理由の詳細は `WPCV_Run_Coordinator` の
 * クラス docblock 参照).
 *
 * v0.4.0 §Step6で `sync_dispatcher()` を公開アクセサとして切り出し、
 * `WPCV_Rest_Run_Controller` の外部HTTP時間予算ループからも共有するようにした
 * (`sync_dispatcher()` のdocblock参照).
 *
 * v0.5後半 §Step12で `diff_dispatcher()` を追加し、`build_dispatcher()` が
 * 組み立てる `WPCV_Chunk_Dispatcher`(AS向け・同期ループ向けの両方)に注入する
 * ようにした(`diff_dispatcher()` のdocblock参照).
 */
class WPCV_Plugin {

	/**
	 * 組み立て済みの `WPCV_Run_Coordinator`(1リクエスト内で使い回す).
	 *
	 * @var WPCV_Run_Coordinator|null
	 */
	private static $run_coordinator = null;

	/**
	 * 組み立て済みの `WPCV_Run_Repository`(1リクエスト内で使い回す。`run_coordinator()`
	 * と共有する同一インスタンス).
	 *
	 * @var WPCV_Run_Repository|null
	 */
	private static $run_repository = null;

	/**
	 * 組み立て済みの `WPCV_Target_Run_Repository`(1リクエスト内で使い回す).
	 *
	 * @var WPCV_Target_Run_Repository|null
	 */
	private static $target_run_repository = null;

	/**
	 * 組み立て済みの `WPCV_Finding_Repository`(1リクエスト内で使い回す).
	 *
	 * @var WPCV_Finding_Repository|null
	 */
	private static $finding_repository = null;

	/**
	 * 組み立て済みの `WPCV_Chunk_Result_Repository`(1リクエスト内で使い回す。
	 * v0.4.0 §Step4で追加).
	 *
	 * @var WPCV_Chunk_Result_Repository|null
	 */
	private static $chunk_result_repository = null;

	/**
	 * 組み立て済みの `WPCV_Suppression_Repository`(1リクエスト内で使い回す。
	 * v0.4.0 §Step8で追加).
	 *
	 * @var WPCV_Suppression_Repository|null
	 */
	private static $suppression_repository = null;

	/**
	 * 組み立て済みの `WPCV_File_State_Repository`(1リクエスト内で使い回す。
	 * v0.5 §Step2で追加).
	 *
	 * @var WPCV_File_State_Repository|null
	 */
	private static $file_state_repository = null;

	/**
	 * 組み立て済みの `WPCV_Chunk_Dispatcher`(1リクエスト内で使い回す。
	 * v0.4.0 §Step4で追加).
	 *
	 * @var WPCV_Chunk_Dispatcher|null
	 */
	private static $chunk_dispatcher = null;

	/**
	 * 組み立て済みの `WPCV_Diff_Dispatcher`(1リクエスト内で使い回す。
	 * v0.5後半 §Step12で追加).
	 *
	 * @var WPCV_Diff_Dispatcher|null
	 */
	private static $diff_dispatcher = null;

	/**
	 * Continuation schedulerをno-opにした `WPCV_Chunk_Dispatcher`(1リクエスト内で
	 * 使い回す。v0.4.0 §Step5で `run_coordinator()` 向けに導入、§Step6で
	 * `WPCV_Rest_Run_Controller` の時間予算ループとも共有するようにした).
	 *
	 * @var WPCV_Chunk_Dispatcher|null
	 */
	private static $sync_dispatcher = null;

	/**
	 * 組み立て済みの `WPCV_Alert_Sender`(1リクエスト内で使い回す。
	 * v0.5後半 §Step14で追加. この時点ではまだどこからも呼ばれない ―― 配線は
	 * Step14cで`alerting`段階から行う).
	 *
	 * @var WPCV_Alert_Sender|null
	 */
	private static $alert_sender = null;

	/**
	 * 組み立て済みの `WPCV_Run_Failure_Alerter`(1リクエスト内で使い回す。
	 * v0.5後半 §Step15aで追加).
	 *
	 * @var WPCV_Run_Failure_Alerter|null
	 */
	private static $run_failure_alerter = null;

	/**
	 * 組み立て済みの `WPCV_Update_Event_Repository`(1リクエスト内で使い回す。
	 * v0.6 §Step1で追加).
	 *
	 * @var WPCV_Update_Event_Repository|null
	 */
	private static $update_event_repository = null;

	/**
	 * 組み立て済みの `WPCV_Update_Event_Recorder`(1リクエスト内で使い回す。
	 * v0.6 §Step2で追加).
	 *
	 * @var WPCV_Update_Event_Recorder|null
	 */
	private static $update_event_recorder = null;

	/**
	 * 組み立て済みの `WPCV_Update_Event_Matcher`(1リクエスト内で使い回す。
	 * v0.6 §Step3で追加、§Step4で`WPCV_Chunk_Dispatcher`とも共有するようにした).
	 *
	 * @var WPCV_Update_Event_Matcher|null
	 */
	private static $update_event_matcher = null;

	/**
	 * 組み立て済みの `WPCV_Update_Lock_Detector`(1リクエスト内で使い回す.
	 * v0.6 §Step6).
	 *
	 * @var WPCV_Update_Lock_Detector|null
	 */
	private static $update_lock_detector = null;

	/**
	 * 組み立て済みの `WPCV_Manifest_Cache_Repository`(1リクエスト内で使い回す.
	 * v0.7 §Step3).
	 *
	 * @var WPCV_Manifest_Cache_Repository|null
	 */
	private static $manifest_cache_repository = null;

	/**
	 * 組み立て済みの `WPCV_Manifest_Cache_Cleaner`(1リクエスト内で使い回す.
	 * v0.7 §Step7).
	 *
	 * @var WPCV_Manifest_Cache_Cleaner|null
	 */
	private static $manifest_cache_cleaner = null;

	/**
	 * 本番用に配線された `WPCV_Run_Coordinator` を返す.
	 *
	 * @return WPCV_Run_Coordinator
	 */
	public static function run_coordinator() {
		if ( null === self::$run_coordinator ) {
			self::$run_coordinator = self::build_run_coordinator();
		}

		return self::$run_coordinator;
	}

	/**
	 * 本番用に配線された `WPCV_Run_Repository` を返す.
	 *
	 * `WPCV_Run_Coordinator::run()` を経由しない単発の DB 操作(`WPCV_Scheduler`/
	 * `WPCV_Page_Settings` が受付処理の冒頭で `find_active_run_id()` を呼ぶ場合
	 * など。v0.4.0コードレビューCR-07是正で、旧`sweep_stale_running()`直接呼び出しは
	 * `WPCV_Chunk_Dispatcher::sweep_deadline_and_expired_leases()`経由に置き換えた)
	 * 向けに、`run_coordinator()` が内部で使うのと同じインスタンスを公開する.
	 *
	 * @return WPCV_Run_Repository
	 */
	public static function run_repository() {
		if ( null === self::$run_repository ) {
			self::$run_repository = self::build_run_repository();
		}

		return self::$run_repository;
	}

	/**
	 * 本番用に配線された `WPCV_Target_Run_Repository` を返す.
	 *
	 * @return WPCV_Target_Run_Repository
	 */
	public static function target_run_repository() {
		if ( null === self::$target_run_repository ) {
			self::$target_run_repository = self::build_target_run_repository();
		}

		return self::$target_run_repository;
	}

	/**
	 * 本番用に配線された `WPCV_Finding_Repository` を返す.
	 *
	 * @return WPCV_Finding_Repository
	 */
	public static function finding_repository() {
		if ( null === self::$finding_repository ) {
			self::$finding_repository = self::build_finding_repository();
		}

		return self::$finding_repository;
	}

	/**
	 * 本番用に配線された `WPCV_Chunk_Result_Repository` を返す(v0.4.0 §Step4).
	 *
	 * @return WPCV_Chunk_Result_Repository
	 */
	public static function chunk_result_repository() {
		if ( null === self::$chunk_result_repository ) {
			global $wpdb;

			self::$chunk_result_repository = new WPCV_Chunk_Result_Repository( $wpdb, self::target_run_repository(), self::finding_repository(), self::suppression_repository(), self::file_state_repository() );
		}

		return self::$chunk_result_repository;
	}

	/**
	 * 本番用に配線された `WPCV_Suppression_Repository` を返す(v0.4.0 §Step8).
	 *
	 * @return WPCV_Suppression_Repository
	 */
	public static function suppression_repository() {
		if ( null === self::$suppression_repository ) {
			global $wpdb;

			self::$suppression_repository = new WPCV_Suppression_Repository( $wpdb );
		}

		return self::$suppression_repository;
	}

	/**
	 * 本番用に配線された `WPCV_File_State_Repository` を返す(v0.5 §Step2).
	 *
	 * @return WPCV_File_State_Repository
	 */
	public static function file_state_repository() {
		if ( null === self::$file_state_repository ) {
			global $wpdb;

			self::$file_state_repository = new WPCV_File_State_Repository( $wpdb );
		}

		return self::$file_state_repository;
	}

	/**
	 * 本番用に配線された `WPCV_Chunk_Dispatcher` を返す(v0.4.0 §Step4).
	 *
	 * @return WPCV_Chunk_Dispatcher
	 */
	public static function chunk_dispatcher() {
		if ( null === self::$chunk_dispatcher ) {
			self::$chunk_dispatcher = self::build_dispatcher();
		}

		return self::$chunk_dispatcher;
	}

	/**
	 * 本番用に配線された `WPCV_Diff_Dispatcher` を返す(v0.5後半 §Step12).
	 *
	 * `chunk_dispatcher()`/`sync_dispatcher()`の両方が(`build_dispatcher()`経由で)
	 * 同じインスタンスを共有する(差分処理には`WPCV_Chunk_Dispatcher`のような
	 * AS向け/同期ループ向けの使い分けが無いため。`WPCV_Diff_Dispatcher`のクラス
	 * docblock「独自のcontinuation schedulerを持たない」参照).
	 *
	 * @return WPCV_Diff_Dispatcher
	 */
	public static function diff_dispatcher() {
		if ( null === self::$diff_dispatcher ) {
			self::$diff_dispatcher = new WPCV_Diff_Dispatcher(
				self::run_repository(),
				self::target_run_repository(),
				self::finding_repository(),
				self::file_state_repository(),
				self::alert_sender(),
				self::update_event_matcher(),
				self::suppression_repository()
			);
		}

		return self::$diff_dispatcher;
	}

	/**
	 * 本番用に配線された、continuation schedulerがno-opの `WPCV_Chunk_Dispatcher` を
	 * 返す(v0.4.0 §Step5/§Step6)。
	 *
	 * `chunk_dispatcher()`(AS action向け。継続を実際にenqueueする)とは別インスタンス
	 * として、呼び出し元自身が「次のdispatch呼び出し」を供給するループ
	 * (`WPCV_Run_Coordinator`の完走ループ、`WPCV_Rest_Run_Controller`の時間予算
	 * ループ)から共有する。AS へ enqueue すると、これらのループ自身が既に
	 * 継続を呼び出しているため二重に不要なAS actionが積み上がってしまう
	 * (`WPCV_Run_Coordinator` のクラス docblock 参照).
	 *
	 * @return WPCV_Chunk_Dispatcher
	 */
	public static function sync_dispatcher() {
		if ( null === self::$sync_dispatcher ) {
			self::$sync_dispatcher = self::build_dispatcher( static function () {} );
		}

		return self::$sync_dispatcher;
	}

	/**
	 * 本番用に配線された `WPCV_Alert_Sender` を返す(v0.5後半 §Step14).
	 *
	 * @return WPCV_Alert_Sender
	 */
	public static function alert_sender() {
		if ( null === self::$alert_sender ) {
			self::$alert_sender = new WPCV_Alert_Sender(
				self::run_repository(),
				self::target_run_repository(),
				self::finding_repository()
			);
		}

		return self::$alert_sender;
	}

	/**
	 * 本番用に配線された `WPCV_Run_Failure_Alerter` を返す(v0.5後半 §Step15a).
	 *
	 * @return WPCV_Run_Failure_Alerter
	 */
	public static function run_failure_alerter() {
		if ( null === self::$run_failure_alerter ) {
			self::$run_failure_alerter = new WPCV_Run_Failure_Alerter( self::run_repository(), self::alert_sender() );
		}

		return self::$run_failure_alerter;
	}

	/**
	 * 本番用に配線された `WPCV_Update_Event_Repository` を返す(v0.6 §Step1).
	 *
	 * @return WPCV_Update_Event_Repository
	 */
	public static function update_event_repository() {
		if ( null === self::$update_event_repository ) {
			global $wpdb;

			self::$update_event_repository = new WPCV_Update_Event_Repository( $wpdb );
		}

		return self::$update_event_repository;
	}

	/**
	 * 本番用に配線された `WPCV_Update_Event_Recorder` を返す(v0.6 §Step2).
	 *
	 * @return WPCV_Update_Event_Recorder
	 */
	public static function update_event_recorder() {
		if ( null === self::$update_event_recorder ) {
			self::$update_event_recorder = new WPCV_Update_Event_Recorder( self::update_event_repository() );
		}

		return self::$update_event_recorder;
	}

	/**
	 * 本番用に配線された `WPCV_Update_Event_Matcher` を返す(v0.6 §Step3。
	 * §Step4で`WPCV_Chunk_Dispatcher`とも共有するようにした).
	 *
	 * @return WPCV_Update_Event_Matcher
	 */
	public static function update_event_matcher() {
		if ( null === self::$update_event_matcher ) {
			self::$update_event_matcher = new WPCV_Update_Event_Matcher( self::update_event_repository(), self::run_repository() );
		}

		return self::$update_event_matcher;
	}

	/**
	 * 本番用に配線された `WPCV_Update_Lock_Detector` を返す(v0.6 §Step6).
	 *
	 * @return WPCV_Update_Lock_Detector
	 */
	public static function update_lock_detector() {
		if ( null === self::$update_lock_detector ) {
			self::$update_lock_detector = new WPCV_Update_Lock_Detector();
		}

		return self::$update_lock_detector;
	}

	/**
	 * 本番用に配線された `WPCV_Manifest_Cache_Repository` を返す(v0.7 §Step3.
	 * テーマのマニフェストのキャッシュ. Step4 でコアのマニフェスト、Step7 で
	 * run 終端の掃除からも使う).
	 *
	 * @return WPCV_Manifest_Cache_Repository
	 */
	public static function manifest_cache_repository() {
		if ( null === self::$manifest_cache_repository ) {
			global $wpdb;

			self::$manifest_cache_repository = new WPCV_Manifest_Cache_Repository( $wpdb );
		}

		return self::$manifest_cache_repository;
	}

	/**
	 * `upgrader_process_complete`フックのハンドラ(v0.6 §Step2.
	 * `WPCV_Update_Event_Recorder`が実際の判定・記録を行う.
	 * `includes/runners/class-wpcv-update-event-recorder.php`の末尾で登録する.
	 * `dispatch_chunk()`/`handle_run_terminated()`と同じ理由〔クラスdocblock参照〕で、
	 * フック登録時点では`WPCV_Plugin`自身がまだ定義されていなくても構わない.
	 *
	 * @param WP_Upgrader $upgrader   更新処理を行った upgrader インスタンス.
	 * @param array       $hook_extra `type`/`action`/`plugin`/`plugins`/`bulk` 等.
	 * @return void
	 */
	public static function handle_upgrader_process_complete( $upgrader, $hook_extra ) {
		self::update_event_recorder()->handle_upgrader_process_complete( $upgrader, $hook_extra );
	}

	/**
	 * `_core_updated_successfully`フックのハンドラ(v0.6 §Step2).
	 *
	 * @param string $wp_version 更新後の WordPress version.
	 * @return void
	 */
	public static function handle_core_updated_successfully( $wp_version ) {
		self::update_event_recorder()->handle_core_updated_successfully( $wp_version );
	}

	/**
	 * `wpcv_run_terminated`フックのハンドラ(v0.6 §Step7. `WPCV_Update_Event_Recorder`が
	 * 保持期間を過ぎた更新イベントの掃除を行う.
	 * `includes/runners/class-wpcv-update-event-recorder.php`の末尾で登録する.
	 * `dispatch_chunk()`と同じ理由〔クラスdocblock参照〕で、フック登録時点では
	 * `WPCV_Plugin`自身がまだ定義されていなくても構わない).
	 *
	 * @param int    $run_id 終端に達した run の id.
	 * @param string $status 遷移後の `wpcv_runs.status`.
	 * @return void
	 */
	public static function handle_update_events_run_terminated( $run_id, $status ) {
		self::update_event_recorder()->handle_run_terminated( (int) $run_id, (string) $status );
	}

	/**
	 * 本番用に配線された `WPCV_Manifest_Cache_Cleaner` を返す(v0.7 §Step7).
	 *
	 * @return WPCV_Manifest_Cache_Cleaner
	 */
	public static function manifest_cache_cleaner() {
		if ( null === self::$manifest_cache_cleaner ) {
			self::$manifest_cache_cleaner = new WPCV_Manifest_Cache_Cleaner( self::manifest_cache_repository(), self::target_run_repository() );
		}

		return self::$manifest_cache_cleaner;
	}

	/**
	 * `wpcv_run_terminated`フックのハンドラ(v0.7 §Step7. `WPCV_Manifest_Cache_Cleaner`が
	 * 使われなくなったマニフェストキャッシュの行を消す. D4.
	 * `includes/runners/class-wpcv-manifest-cache-cleaner.php`の末尾で登録する).
	 *
	 * @param int    $run_id 終端に達した run の id.
	 * @param string $status 遷移後の `wpcv_runs.status`.
	 * @return void
	 */
	public static function handle_manifest_cache_run_terminated( $run_id, $status ) {
		self::manifest_cache_cleaner()->handle_run_terminated( (int) $run_id, (string) $status );
	}

	/**
	 * `wpcv_run_terminated`フックのハンドラ(v0.5後半 §Step15a.
	 * `WPCV_Run_Failure_Alerter`が実際の判定・送信を行う.
	 * `includes/runners/class-wpcv-run-failure-alerter.php`の末尾で登録する.
	 * `dispatch_chunk()`と同じ理由〔クラスdocblock参照〕で、フック登録時点では
	 * `WPCV_Plugin`自身がまだ定義されていなくても構わない ―― 実際に呼ばれるのは
	 * runが終端に達した時点であり、その時点ではすべて読み込み済みのため).
	 *
	 * @param int    $run_id 終端に達した run の id.
	 * @param string $status 遷移後の `wpcv_runs.status`.
	 * @return void
	 */
	public static function handle_run_terminated( $run_id, $status ) {
		self::run_failure_alerter()->handle( (int) $run_id, (string) $status );
	}

	/**
	 * `WPCV_Chunk_Dispatcher::HOOK` のフックハンドラ. Action Scheduler の
	 * ワーカーから呼ばれる(v0.4.0 §Step4).
	 *
	 * `$context` は `WPCV_Runner_Async::run_async_action()` と同じ理由(AS の
	 * args 8,000文字制限)で、enqueue時点の値を保持せず毎回組み立て直す。
	 * 既存run(既に受付済み)に対する継続実行のため `run_trigger` は不要
	 * (`WPCV_Chunk_Dispatcher::dispatch()` は `$context['run_trigger']` を
	 * 読まない).
	 *
	 * @param int $run_id `WPCV_Chunk_Dispatcher::dispatch()` に渡す run の id.
	 * @return void
	 */
	public static function dispatch_chunk( $run_id ) {
		$context = WPCV_Context_Builder::build();

		self::chunk_dispatcher()->dispatch( (int) $run_id, $context );
	}

	/**
	 * `WPCV_Run_Coordinator` を実際の依存で組み立てる.
	 *
	 * `chunk_dispatcher()`(AS action向けシングルトン)とは別の `sync_dispatcher()`
	 * (continuation schedulerがno-opのインスタンス)を使う(`WPCV_Run_Coordinator`
	 * のクラス docblock 参照).
	 *
	 * @return WPCV_Run_Coordinator
	 */
	private static function build_run_coordinator() {
		return new WPCV_Run_Coordinator(
			new WPCV_Run_Planner( self::suppression_repository(), WPCV_Settings::get_stat_detection_enabled() ),
			self::run_repository(),
			self::target_run_repository(),
			self::sync_dispatcher()
		);
	}

	/**
	 * `WPCV_Chunk_Dispatcher` を実際の依存で組み立てる.
	 *
	 * `chunk_dispatcher()`(AS action向け。既定のcontinuation scheduler=実際の
	 * Action Scheduler呼び出し)と `build_run_coordinator()`(同期ループ向け。
	 * continuation schedulerをno-opに差し替え)の両方から呼ぶ共通の組み立て処理.
	 *
	 * @param callable|null $continuation_scheduler 省略時は `WPCV_Chunk_Dispatcher` の
	 *                                              既定(実際の Action Scheduler 呼び出し).
	 * @return WPCV_Chunk_Dispatcher
	 */
	private static function build_dispatcher( ?callable $continuation_scheduler = null ) {
		// v0.7 §Step4: コアのマニフェストもキャッシュする. テーマのソースも同じ
		// インスタンスを使い、コア同梱テーマの md5 を引く(D7).
		$core_source = new WPCV_Source_Core( self::manifest_cache_repository() );

		return new WPCV_Chunk_Dispatcher(
			self::run_repository(),
			self::target_run_repository(),
			self::chunk_result_repository(),
			new WPCV_Chunk_Verifier(),
			$core_source,
			new WPCV_Source_Wporg_Plugin(),
			new WPCV_Unknown_File_Scanner(),
			null,
			$continuation_scheduler,
			null,
			self::file_state_repository(),
			self::diff_dispatcher(),
			self::update_event_matcher(),
			self::update_lock_detector(),
			new WPCV_Source_Wporg_Theme( self::manifest_cache_repository(), null, null, $core_source )
		);
	}

	/**
	 * `WPCV_Run_Repository` を実際の `global $wpdb` で組み立てる.
	 *
	 * @return WPCV_Run_Repository
	 */
	private static function build_run_repository() {
		global $wpdb;

		return new WPCV_Run_Repository( $wpdb );
	}

	/**
	 * `WPCV_Target_Run_Repository` を実際の `global $wpdb` で組み立てる.
	 *
	 * @return WPCV_Target_Run_Repository
	 */
	private static function build_target_run_repository() {
		global $wpdb;

		return new WPCV_Target_Run_Repository( $wpdb );
	}

	/**
	 * `WPCV_Finding_Repository` を実際の `global $wpdb` で組み立てる.
	 *
	 * @return WPCV_Finding_Repository
	 */
	private static function build_finding_repository() {
		global $wpdb;

		return new WPCV_Finding_Repository( $wpdb );
	}
}
