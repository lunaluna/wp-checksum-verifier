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
 * v0.3 §Step8/§Step9・v0.3.1 §Step4の計画通り、同期専用になった `handle_run()`
 * の応答契約(新規run完了・active run・busy・検証失敗)とパーミッション
 * コールバックのトークン認証+レート制限を検証する。ルーティング登録自体
 * (`register_routes()`)は実WordPress環境が必要なため対象外(実地検証側の責務).
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
			$GLOBALS['_wpcv_test_transients'],
			$GLOBALS['_wpcv_test_plugins'],
			$GLOBALS['_wpcv_test_mu_plugins']
		);
		wpcv_test_inject_repository();
		wpcv_test_inject_run_coordinator();
	}

	/**
	 * 各テストの後、次のテストに影響しないよう `WPCV_Plugin` のキャッシュを戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_repository();
		wpcv_test_inject_run_coordinator();
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
	 * 直近runが `running`/`queued` のままなら新規runを作らず、その run_id と
	 * 実際の状態をそのまま返す(冪等性)ことを確認する(v0.3.1 §Step4).
	 *
	 * @return void
	 */
	public function test_handle_run_returns_existing_run_id_when_active() {
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
		// active run が見つかった時点で早期returnするため、検証(target_runs)は
		// 一切走らない(AS runnerも呼ばれない. プラン§Step4テスト
		// 「active queued/running runに対して新規runを作らない」の確認).
		$this->assertArrayNotHasKey( 'wp_wpcv_target_runs', $wpdb->rows );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );
	}

	/**
	 * 進行中の run が無ければ同期的に検証を完走し、終端状態(success/partial/failed)
	 * と run_id を200で返すことを確認する(v0.3.1 §Step4: RESTはAS runnerを呼ばず
	 * 常に同期実行する。プラン§Step4テスト「REST実行でAS runnerを呼ばない」の確認).
	 *
	 * @return void
	 */
	public function test_handle_run_completes_new_run_synchronously() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		$made                           = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_repository( $made['repository'] );

		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'success', $response->data['status'] );
		$this->assertSame( 1, $response->data['run_id'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		// AS runner は一切呼ばれていない(enqueue も drain も無い).
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );

		$row = $made['wpdb']->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'rest', $row['run_trigger'] );
		$this->assertSame( 'sync', $row['runner'] );
	}

	/**
	 * advisory lock の取得に失敗した場合、`WP_Error`(503)を返すことを確認する
	 * (v0.3.1 §Step4テスト「busyのstatus codeとbodyを検証する」).
	 *
	 * @return void
	 */
	public function test_handle_run_returns_busy_error_when_lock_fails() {
		$wpdb                 = new WPCV_Test_Fake_WPDB();
		$wpdb->get_var_return = '0';
		wpcv_test_inject_repository( new WPCV_Repository( $wpdb ) );

		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wpcv_rest_busy', $response->get_error_code() );
		$this->assertSame( 503, $response->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'wp_wpcv_runs', $wpdb->rows );
	}

	/**
	 * 検証中に例外が発生した場合、`WP_Error`(500)を返し、run は failed 記録
	 * されることを確認する(v0.3.1 §Step4テスト「同期例外のstatus codeとbodyを
	 * 検証する」)。`WPCV_Context_Builder::build()` が version を取得できない
	 * (`_wpcv_test_bloginfo` 未設定)ことで `WPCV_Run_Coordinator::run()` の
	 * バリデーション例外を誘発する.
	 *
	 * @return void
	 */
	public function test_handle_run_returns_error_when_verification_throws() {
		$made = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_repository( $made['repository'] );

		// $GLOBALS['_wpcv_test_bloginfo'] を設定しないことで version が空文字になり、
		// WPCV_Run_Coordinator::run() がバリデーション例外を投げる.
		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wpcv_rest_run_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );

		$row = $made['wpdb']->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'failed', $row['status'] );
	}
}
