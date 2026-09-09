<?php
/**
 * WPCV_Rest_Run_Controller クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /wp-json/wpcv/v1/run`(v0.3 §Step8、モードC・簡略版).
 *
 * マネージド系ホスティング(`DISABLE_WP_CRON`前提)向けの自動実行経路。ファイル
 * 単位の分割実行・詳細な残作業報告(`{"pending": 87, ...}`等)はv0.4以降に送り、
 * v0.3では次の2点のみに絞る:
 *
 * 1. 冪等性: 直近runが`running`状態のままなら新規runを作らず、その run_id を
 *    そのまま返す(連打しても二重作成されない).
 * 2. オポチュニスティックなキュー消化: Action Schedulerが利用可能なら、設定
 *    された時間予算(`WPCV_Settings::get_rest_time_budget_seconds()`)の範囲内で
 *    キューを処理し、時間内に処理できた分だけ進める.
 *
 * 認証は現時点では既存のcookie認証+`manage_options`/`manage_network_options`の
 * capabilityチェックのみ(WordPressログインセッションを持たない外部システムcron
 * から呼べるトークン認証はv0.3 §Step9で追加する).
 *
 * `GET /status`/`GET /findings`はv0.3では実装しない(観測系機能はv0.4の実行履歴
 * 画面とまとめる方針).
 */
class WPCV_Rest_Run_Controller {

	/**
	 * REST名前空間.
	 *
	 * @var string
	 */
	const NAMESPACE_NAME = 'wpcv/v1';

	/**
	 * ルートのパス(名前空間からの相対パス).
	 *
	 * @var string
	 */
	const ROUTE = '/run';

	/**
	 * ルートを登録する. `rest_api_init` フックから呼ぶ.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_NAME,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_run' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * パーミッションコールバック.
	 *
	 * @return bool
	 */
	public static function check_permission() {
		return (bool) current_user_can( self::required_capability() );
	}

	/**
	 * `POST /run` のハンドラ.
	 *
	 * @param WP_REST_Request $request リクエスト(v0.3では未使用. パラメータを
	 *                                 持たないため).
	 * @return WP_REST_Response
	 */
	public static function handle_run( $request ) {
		unset( $request );

		$repository = WPCV_Plugin::repository();
		$repository->sweep_stale_running( WPCV_Scheduler::STALE_THRESHOLD_MINUTES );

		$active_run_id = $repository->find_active_run_id();

		if ( null !== $active_run_id ) {
			return self::response(
				array(
					'status' => 'running',
					'run_id' => $active_run_id,
				)
			);
		}

		$enqueue_result = WPCV_Runner_Async::enqueue_run( 'rest' );

		if ( ! $enqueue_result['enqueued'] ) {
			// Action Scheduler が利用不可 → `WPCV_Runner_Async::enqueue_run()` が
			// 内部で同期フォールバック実行済み(この呼び出しの中で run が完走している).
			return self::response(
				array(
					'status' => $enqueue_result['result']['summary']['status'],
					'run_id' => $enqueue_result['result']['run_id'],
				)
			);
		}

		$processed_actions = self::drain_queue_within_budget();

		return self::response(
			array(
				'status'    => 'enqueued',
				'processed' => 0 < $processed_actions,
			)
		);
	}

	/**
	 * 設定された時間予算の範囲内で Action Scheduler のキューを処理する.
	 *
	 * @return int 処理したアクション数.
	 */
	private static function drain_queue_within_budget() {
		if ( ! class_exists( 'ActionScheduler' ) ) {
			return 0;
		}

		$seconds = self::time_budget_seconds();

		$time_limit_filter = static function () use ( $seconds ) {
			return $seconds;
		};

		// Action Scheduler 自身のキューランナーが使う時間予算
		// (`action_scheduler_queue_runner_time_limit`. 既定30秒)を、このリクエスト
		// 中だけ設定値で上書きする. 恒久的なフィルタ登録にしない理由は、この時間
		// 予算がREST経由のオポチュニスティックな処理専用の値であり、WP-Cron等の
		// 他の実行経路(Step6)に影響させたくないため.
		add_filter( 'action_scheduler_queue_runner_time_limit', $time_limit_filter );

		$processed_actions = (int) ActionScheduler::runner()->run( 'REST' );

		remove_filter( 'action_scheduler_queue_runner_time_limit', $time_limit_filter );

		return $processed_actions;
	}

	/**
	 * 実際に使う時間予算(秒)を求める.
	 *
	 * @return int
	 */
	private static function time_budget_seconds() {
		$max_execution_time = (int) ini_get( 'max_execution_time' );

		return self::clamp_time_budget( WPCV_Settings::get_rest_time_budget_seconds(), $max_execution_time );
	}

	/**
	 * 設定値を `max_execution_time` の70%を上限にクランプする(純粋関数として
	 * 分離し、`ini_get()` を介さずテストできるようにしてある).
	 *
	 * @param int $configured_seconds 設定画面で保存された時間予算(秒).
	 * @param int $max_execution_time `ini_get( 'max_execution_time' )` の値
	 *                                (秒。0は無制限を意味する).
	 * @return int
	 */
	public static function clamp_time_budget( $configured_seconds, $max_execution_time ) {
		$configured_seconds = max( 1, (int) $configured_seconds );

		if ( $max_execution_time <= 0 ) {
			// 0 は無制限(WP-CLI実行時の既定等). REST(HTTPリクエスト)では通常
			// 有限値が設定されているが、無制限の環境では設定値をそのまま使う.
			return $configured_seconds;
		}

		$ceiling = (int) floor( $max_execution_time * 0.7 );

		return max( 1, min( $configured_seconds, $ceiling ) );
	}

	/**
	 * `Cache-Control: no-store` を付与したレスポンスを組み立てる.
	 *
	 * @param array $data レスポンスボディ.
	 * @return WP_REST_Response
	 */
	private static function response( array $data ) {
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * このエンドポイントに必要な capability を返す.
	 *
	 * @return string
	 */
	private static function required_capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}
}
