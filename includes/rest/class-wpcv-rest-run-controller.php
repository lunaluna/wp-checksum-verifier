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
 * `POST /wp-json/wpcv/v1/run`(v0.3 §Step8、v0.3.1 §Step4で同期専用に契約を見直し).
 *
 * 旧バージョン(v0.3.0)では Action Scheduler が利用可能なら enqueue した上で、同一リクエスト
 * 内で `ActionScheduler::runner()->run()` を直接呼んでキューをオポチュニスティックに
 * 消化する設計だった。しかしこの呼び出しは WPCV 専用ではなくサイト全体の Action
 * Scheduler キューを処理してしまう(他プラグインの保留中 action も実行され得る)
 * という副作用があり、かつ「時間予算内で少しずつ前進する」という説明を保証できて
 * いなかった(1 action = 1 run 全体という v0.3.1 のモデルでは、時間予算を超えた
 * ところで安全に中断する仕組みが無いため)。v0.3.1 Step4 でこの副作用を除去し、
 * REST は常に同期実行(`WPCV_Run_Repository::reserve_run()` → `WPCV_Run_Coordinator::run()`)
 * に一本化した。大規模サイトでは1リクエストで完走できる規模に限られる
 * (README参照)。ファイル単位の分割実行・resume・厳密な時間予算管理はv0.4.0以降.
 *
 * 認証は`WPCV_Rest_Token`によるトークン専用(v0.3 §Step9)。cookie認証との併用は
 * しない(WordPressログインセッションを持たない外部システムcronから呼べる
 * ようにするための設計。`check_permission()` の docblock参照).
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
	 * `current_user_can()` によるcapabilityチェックとは併用しない(§12.3。
	 * WordPressログインセッションを持たない外部システムcronから呼べることが
	 * 目的のため、cookie認証を前提にしたコールバックにはしない). 認証失敗を
	 * `WPCV_Rest_Token` のレート制限に記録し、一定回数を超えたら
	 * トークンの正誤に関わらず`429`で拒否する.
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return true|WP_Error
	 */
	public static function check_permission( $request ) {
		$identifier = self::client_identifier();

		if ( WPCV_Rest_Token::is_rate_limited( $identifier ) ) {
			return new WP_Error(
				'wpcv_rest_rate_limited',
				__( 'Too many failed authentication attempts. Try again later.', 'wp-checksum-verifier' ),
				array( 'status' => 429 )
			);
		}

		$token = WPCV_Rest_Token::extract_from_request( $request );

		if ( WPCV_Rest_Token::verify( $token ) ) {
			WPCV_Rest_Token::clear_failed_attempts( $identifier );

			return true;
		}

		WPCV_Rest_Token::record_failed_attempt( $identifier );

		return new WP_Error(
			'wpcv_rest_forbidden',
			__( 'Invalid or missing REST token.', 'wp-checksum-verifier' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * レート制限の単位に使う呼び出し元の識別子(IPアドレス)を返す.
	 *
	 * `X-Forwarded-For` 等のクライアントが自由に指定できるヘッダーは信用しない
	 * (プロキシ経由の実運用でIPアドレスが偏る可能性はあるが、v0.3では
	 * 詐称されうる値をセキュリティ判定に使わないことを優先する).
	 *
	 * @return string
	 */
	private static function client_identifier() {
		return isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
	}

	/**
	 * `POST /run` のハンドラ(v0.3.1 §Step4で同期専用に契約を見直し).
	 *
	 * 他の同期系エントリポイント(`WPCV_CLI_Command::__invoke()`)と同じ
	 * `sweep_stale_running()` → `reserve_run()` → `WPCV_Run_Coordinator::run()`
	 * の流れに統一する(REST 独自の事前チェック〔`find_active_run_id()`〕は
	 * `reserve_run()` の advisory lock 内の判定と重複していたため廃止した).
	 *
	 * レスポンス契約:
	 * - 新規 run が完走: 200 + `{status: 終端状態(success|partial|failed), run_id}`.
	 * - 既に active(`queued`/`running`)な run がある: 200 +
	 *   `{status: 'queued'|'running', run_id: 既存runのid}`(冪等性. 連打しても
	 *   二重作成されない).
	 * - advisory lock の取得に失敗: `WP_Error`(503. 一時的な混雑を表すため
	 *   リトライ可能であることを示す).
	 * - 検証中に例外が発生(run は failed 記録済み): `WP_Error`(500).
	 *
	 * @param WP_REST_Request $request リクエスト(v0.3では未使用. パラメータを
	 *                                 持たないため).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_run( $request ) {
		unset( $request );

		$repository = WPCV_Plugin::run_repository();
		$repository->sweep_stale_running( WPCV_Scheduler::STALE_THRESHOLD_MINUTES );

		$reservation = $repository->reserve_run(
			array(
				'run_trigger' => 'rest',
				'runner'      => 'sync',
			)
		);

		if ( $reservation['lock_failed'] ) {
			return new WP_Error(
				'wpcv_rest_busy',
				__( 'The verifier is busy. Try again shortly.', 'wp-checksum-verifier' ),
				array( 'status' => 503 )
			);
		}

		if ( $reservation['active'] ) {
			return self::response(
				array(
					'status' => $reservation['status'],
					'run_id' => $reservation['run_id'],
				)
			);
		}

		$context = WPCV_Context_Builder::build( 'rest' );

		try {
			$result = WPCV_Plugin::run_coordinator()->run( $reservation['run_id'], $context );
		} catch ( Throwable $e ) {
			return new WP_Error(
				'wpcv_rest_run_failed',
				__( 'The verification run failed. Check the run history for details.', 'wp-checksum-verifier' ),
				array( 'status' => 500 )
			);
		}

		return self::response(
			array(
				'status' => $result['summary']['status'],
				'run_id' => $result['run_id'],
			)
		);
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
}
