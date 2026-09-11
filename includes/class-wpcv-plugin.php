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
 * `WPCV_Verifier` / 各 Repository / `WPCV_Run_Coordinator` はいずれもコンストラクタ
 * 注入で依存(`WPCV_Manifest_Source` 実装・`$wpdb`)を受け取る設計(単体テストで
 * テストダブルに差し替えられるようにするため)にしてある。このクラスは唯一、
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
 * `run_coordinator()`(一括実行)と `chunk_dispatcher()`(chunk分割実行)は
 * Step5でCLI/REST/WP-Cronの繋ぎ替えが完了するまでの間、並行して存在する.
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
	 * 組み立て済みの `WPCV_Chunk_Dispatcher`(1リクエスト内で使い回す。
	 * v0.4.0 §Step4で追加).
	 *
	 * @var WPCV_Chunk_Dispatcher|null
	 */
	private static $chunk_dispatcher = null;

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

			self::$chunk_result_repository = new WPCV_Chunk_Result_Repository( $wpdb, self::target_run_repository(), self::finding_repository() );
		}

		return self::$chunk_result_repository;
	}

	/**
	 * 本番用に配線された `WPCV_Chunk_Dispatcher` を返す(v0.4.0 §Step4).
	 *
	 * @return WPCV_Chunk_Dispatcher
	 */
	public static function chunk_dispatcher() {
		if ( null === self::$chunk_dispatcher ) {
			self::$chunk_dispatcher = new WPCV_Chunk_Dispatcher(
				self::run_repository(),
				self::target_run_repository(),
				self::chunk_result_repository(),
				new WPCV_Chunk_Verifier(),
				new WPCV_Source_Core(),
				new WPCV_Source_Wporg_Plugin(),
				new WPCV_Unknown_File_Scanner()
			);
		}

		return self::$chunk_dispatcher;
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
	 * @return WPCV_Run_Coordinator
	 */
	private static function build_run_coordinator() {
		$verifier = new WPCV_Verifier(
			new WPCV_Source_Core(),
			new WPCV_Source_Wporg_Plugin(),
			new WPCV_Unknown_File_Scanner()
		);

		return new WPCV_Run_Coordinator(
			$verifier,
			self::run_repository(),
			self::target_run_repository(),
			self::finding_repository()
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
