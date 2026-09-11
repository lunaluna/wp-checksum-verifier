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
	 * 組み立て済みの `WPCV_Chunk_Dispatcher`(1リクエスト内で使い回す。
	 * v0.4.0 §Step4で追加).
	 *
	 * @var WPCV_Chunk_Dispatcher|null
	 */
	private static $chunk_dispatcher = null;

	/**
	 * Continuation schedulerをno-opにした `WPCV_Chunk_Dispatcher`(1リクエスト内で
	 * 使い回す。v0.4.0 §Step5で `run_coordinator()` 向けに導入、§Step6で
	 * `WPCV_Rest_Run_Controller` の時間予算ループとも共有するようにした).
	 *
	 * @var WPCV_Chunk_Dispatcher|null
	 */
	private static $sync_dispatcher = null;

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
	 * `WPCV_Run_Coordinator::run()` を経由しない単発の DB 操作(v0.3 §Step5の
	 * `sweep_stale_running()` を WP-Cron/REST ハンドラの冒頭で呼ぶ場合など)向けに、
	 * `run_coordinator()` が内部で使うのと同じインスタンスを公開する.
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

			self::$chunk_result_repository = new WPCV_Chunk_Result_Repository( $wpdb, self::target_run_repository(), self::finding_repository(), self::suppression_repository() );
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
			new WPCV_Run_Planner( self::suppression_repository() ),
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
		return new WPCV_Chunk_Dispatcher(
			self::run_repository(),
			self::target_run_repository(),
			self::chunk_result_repository(),
			new WPCV_Chunk_Verifier(),
			new WPCV_Source_Core(),
			new WPCV_Source_Wporg_Plugin(),
			new WPCV_Unknown_File_Scanner(),
			null,
			$continuation_scheduler
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
