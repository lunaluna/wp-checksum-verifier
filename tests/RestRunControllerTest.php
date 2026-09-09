<?php
/**
 * WPCV_Rest_Run_Controller のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-context-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-plugin.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-runner-async.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-scheduler.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-wpcv-rest-token.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-wpcv-rest-run-controller.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Rest_Run_Controller` のテスト.
 *
 * v0.3 §Step8/§Step9の計画通り、冪等性判定ロジック(`WPCV_Repository`のフェイク
 * 経由)・パーミッションコールバックのトークン認証+レート制限・時間予算の
 * クランプ計算(`clamp_time_budget()`. `ini_get()`を介さない純粋関数)を検証する。
 * ルーティング登録自体(`register_routes()`)・Action Schedulerの実キュー処理は
 * 実WordPress環境が必要なため対象外(実地検証側の責務).
 */
class RestRunControllerTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset(
			$GLOBALS['_wpcv_test_is_multisite'],
			$GLOBALS['_wpcv_test_options'],
			$GLOBALS['_wpcv_test_user_capabilities'],
			$GLOBALS['_wpcv_test_as_enqueue_calls'],
			$GLOBALS['_wpcv_test_bloginfo'],
			$GLOBALS['_wpcv_test_transients']
		);
		wpcv_test_inject_repository();
	}

	/**
	 * 各テストの後、次のテストに影響しないよう `WPCV_Plugin` のキャッシュを戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_repository();
		parent::tearDown();
	}

	/**
	 * 正しいトークンをBearerヘッダーで渡せば許可されることを確認する
	 * (§Step9: cookie認証・capabilityとは無関係にトークンのみで判定する).
	 *
	 * @return void
	 */
	public function test_check_permission_allows_correct_bearer_token() {
		$token   = WPCV_Rest_Token::generate();
		$request = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer ' . $token ) );

		$this->assertTrue( WPCV_Rest_Run_Controller::check_permission( $request ) );
	}

	/**
	 * トークンが無効・未指定なら `WP_Error`(401)を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_check_permission_rejects_invalid_token() {
		WPCV_Rest_Token::generate();
		$request = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer wrong-token' ) );

		$result = WPCV_Rest_Run_Controller::check_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * ヘッダーが何も無ければ `WP_Error`(401)を返すことを確認する
	 * (`current_user_can()` によるcookie認証へのフォールバックはしない).
	 *
	 * @return void
	 */
	public function test_check_permission_rejects_missing_token() {
		$request = new WPCV_Test_Fake_Rest_Request();

		$result = WPCV_Rest_Run_Controller::check_permission( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * 失敗回数が上限に達すると、正しいトークンでも `429` で拒否されることを確認する.
	 *
	 * @return void
	 */
	public function test_check_permission_returns_429_after_rate_limit_exceeded() {
		$token           = WPCV_Rest_Token::generate();
		$wrong_request   = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer wrong-token' ) );
		$correct_request = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer ' . $token ) );

		for ( $i = 0; $i < WPCV_Rest_Token::RATE_LIMIT_MAX_ATTEMPTS; $i++ ) {
			WPCV_Rest_Run_Controller::check_permission( $wrong_request );
		}

		$result = WPCV_Rest_Run_Controller::check_permission( $correct_request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/**
	 * 直近runが `running` のままなら新規runを作らず、その run_id を
	 * そのまま返す(冪等性)ことを確認する.
	 *
	 * @return void
	 */
	public function test_handle_run_returns_existing_run_id_when_already_running() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-09 12:00:00',
				'status'      => 'running',
				'run_trigger' => 'rest',
				'runner'      => 'async',
			)
		);
		wpcv_test_inject_repository(
			new WPCV_Repository(
				$wpdb,
				static function () {
					return '2026-09-09 12:05:00';
				}
			)
		);

		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertSame( array( 'status' => 'running', 'run_id' => 1 ), $response->data );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );
	}

	/**
	 * 進行中の run が無ければ enqueue し(Action Scheduler が利用可能な
	 * テスト環境では常にenqueue経路を通る。`RunnerAsyncTest` の docblock参照)、
	 * `{"status":"enqueued", ...}` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_handle_run_enqueues_when_no_active_run() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		wpcv_test_inject_repository( new WPCV_Repository( $wpdb ) );

		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertSame( 'enqueued', $response->data['status'] );
		$this->assertFalse( $response->data['processed'] ); // ActionSchedulerクラス未ロードのためdrainは0件.
		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'] );
		list( $hook, $args ) = $GLOBALS['_wpcv_test_as_enqueue_calls'][0];
		$this->assertSame( WPCV_Runner_Async::HOOK, $hook );
		$this->assertSame( array( 'rest' ), $args );
	}

	/**
	 * `max_execution_time` が無制限(0)のとき、設定値をそのまま使うことを確認する.
	 *
	 * @return void
	 */
	public function test_clamp_time_budget_returns_configured_value_when_unlimited() {
		$this->assertSame( 15, WPCV_Rest_Run_Controller::clamp_time_budget( 15, 0 ) );
	}

	/**
	 * 設定値が `max_execution_time` の70%以内に収まる場合、設定値をそのまま使うことを確認する.
	 *
	 * @return void
	 */
	public function test_clamp_time_budget_keeps_configured_value_within_ceiling() {
		// max_execution_time=30 → 70% = 21. 設定値15はこれ以下なのでそのまま.
		$this->assertSame( 15, WPCV_Rest_Run_Controller::clamp_time_budget( 15, 30 ) );
	}

	/**
	 * 設定値が `max_execution_time` の70%を超える場合、70%相当にクランプされることを確認する.
	 *
	 * @return void
	 */
	public function test_clamp_time_budget_clamps_to_seventy_percent_ceiling() {
		// max_execution_time=10 → 70% = 7. 設定値15はこれを超えるため7にクランプ.
		$this->assertSame( 7, WPCV_Rest_Run_Controller::clamp_time_budget( 15, 10 ) );
	}

	/**
	 * 設定値が0以下でも最低1秒は確保されることを確認する.
	 *
	 * @return void
	 */
	public function test_clamp_time_budget_enforces_minimum_one_second() {
		$this->assertSame( 1, WPCV_Rest_Run_Controller::clamp_time_budget( 0, 0 ) );
		$this->assertSame( 1, WPCV_Rest_Run_Controller::clamp_time_budget( 15, 1 ) );
	}
}
