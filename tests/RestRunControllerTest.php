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
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-chunk-result-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-starter.php';
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
 * v0.4.0 §Step6の計画通り、日次due判定・時間予算内でのdispatcherループに
 * 書き換えた `handle_run()` の応答契約(active run前進・新規run作成・
 * 未due・当日分作成済み・予算内で進捗が無い場合・検証失敗)と、パーミッション
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
			$GLOBALS['_wpcv_test_mu_plugins'],
			$_SERVER['REMOTE_ADDR']
		);
		wpcv_test_inject_run_repository();
		wpcv_test_inject_target_run_repository();
		wpcv_test_inject_sync_dispatcher();
	}

	/**
	 * 各テストの後、次のテストに影響しないよう `WPCV_Plugin` のキャッシュを戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_run_repository();
		wpcv_test_inject_target_run_repository();
		wpcv_test_inject_sync_dispatcher();
		unset( $_SERVER['REMOTE_ADDR'] );
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
	 * 失敗回数が上限に達すると、正しいトークンでも `429` で拒否されることを確認する
	 * (`REMOTE_ADDR` が設定されている前提。v0.3.1 §Step5で `REMOTE_ADDR` が空の
	 * 場合はレート制限自体を適用しないよう変更したため、このテストでは明示的に
	 * 設定する。`test_check_permission_does_not_rate_limit_when_remote_addr_missing()`
	 * が空の場合の挙動を別途検証する).
	 *
	 * @return void
	 */
	public function test_check_permission_returns_429_after_rate_limit_exceeded() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

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
	 * `REMOTE_ADDR` が取得できない場合、何度失敗してもレート制限が発動しないことを
	 * 確認する(v0.3.1 §Step5。プラン§P1「`REMOTE_ADDR` が空の場合に全呼び出し元が
	 * 同じrate-limit bucketへ入らない」への対策. `WPCV_Rest_Token::is_rate_limited()`
	 * の空文字ガードが、REST層の実際の呼び出し経路〔`client_identifier()`〕からも
	 * 機能することの確認).
	 *
	 * @return void
	 */
	public function test_check_permission_does_not_rate_limit_when_remote_addr_missing() {
		unset( $_SERVER['REMOTE_ADDR'] );

		$wrong_request = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer wrong-token' ) );

		for ( $i = 0; $i < WPCV_Rest_Token::RATE_LIMIT_MAX_ATTEMPTS + 5; $i++ ) {
			$result = WPCV_Rest_Run_Controller::check_permission( $wrong_request );

			// 429(レート制限)ではなく401(トークン不一致)のままであることを確認する.
			$this->assertSame( 401, $result->get_error_data()['status'] );
		}
	}

	/**
	 * Active run(`queued`/`running`)が既にある場合、新規runを作らずそれに対して
	 * dispatchを繰り返し前進させ、完走すれば終端状態を返すことを確認する
	 * (v0.4.0 §Step6: 5分間隔の外部cronが連打しても進行中のrunがそのまま
	 * 前進し続けることの確認。due判定〔`run_hour`/`run_minute`〕は一切設定しない
	 * ことで、active runの前進がdue判定に左右されないことも合わせて確認する).
	 *
	 * @return void
	 */
	public function test_handle_run_advances_existing_active_run_without_creating_new_one() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		$made                           = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_repository( $made['run_repository'] );
		wpcv_test_inject_target_run_repository( $made['target_run_repository'] );
		wpcv_test_inject_sync_dispatcher( $made['dispatcher'] );

		// cron由来のactive run(scheduled_forを持たない)を、REST以外の経路が
		// 既に予約・planning済みの状態として用意する.
		$reservation = $made['run_repository']->reserve_run(
			array(
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);
		WPCV_Run_Starter::plan_and_save(
			$made['run_repository'],
			new WPCV_Run_Planner(),
			$made['target_run_repository'],
			$reservation['run_id'],
			WPCV_Context_Builder::build( 'cron' )
		);

		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertSame( $reservation['run_id'], $response->data['run_id'] );
		$this->assertSame( 'success', $response->data['status'] );
		$this->assertSame( 0, $response->data['pending_targets'] );
		$this->assertSame( 0, $response->data['retry_targets'] );
		$this->assertNull( $response->data['next_retry_at'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		// 新規runは作られていない(1件のまま)。global AS runner も呼ばれていない.
		$this->assertCount( 1, $made['wpdb']->rows['wp_wpcv_runs'] );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );
	}

	/**
	 * Active runが無く、設定実行時刻(UTC)を過ぎ、本日分のrunがまだ無い場合、
	 * `scheduled_for` 付きの新規runを作成し、時間予算内でdispatchを繰り返して
	 * 完走させることを確認する(v0.4.0 §Step6).
	 *
	 * @return void
	 */
	public function test_handle_run_creates_and_completes_due_run_within_budget() {
		$GLOBALS['_wpcv_test_bloginfo']                              = array( 'version' => '6.8' );
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array(
			'run_hour'   => 11,
			'run_minute' => 0,
		);

		$made = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_repository( $made['run_repository'] );
		wpcv_test_inject_target_run_repository( $made['target_run_repository'] );
		wpcv_test_inject_sync_dispatcher( $made['dispatcher'] );

		// 固定 now(wpcv_test_make_fake_environment() 参照)は 2026-09-08 12:00:00.
		// 設定実行時刻 11:00 は既に過ぎている.
		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 1, $response->data['run_id'] );
		$this->assertSame( 'success', $response->data['status'] );
		$this->assertSame( 0, $response->data['pending_targets'] );
		$this->assertSame( 0, $response->data['retry_targets'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		// global AS runner は一切呼ばれていない(enqueue も drain も無い).
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );

		$row = $made['wpdb']->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'rest', $row['run_trigger'] );
		$this->assertSame( 'sync', $row['runner'] );
		$this->assertSame( '2026-09-08 11:00:00', $row['scheduled_for'] );
	}

	/**
	 * Active runが無く、設定実行時刻(UTC)をまだ過ぎていない場合、新規runを作らず
	 * 直近runの状態をそのまま報告することを確認する(v0.4.0 §Step6).
	 *
	 * @return void
	 */
	public function test_handle_run_reports_last_status_when_not_yet_due() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array(
			'run_hour'   => 13,
			'run_minute' => 0,
		);

		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'    => '2026-09-07 11:00:05',
				'finished_at'   => '2026-09-07 11:00:10',
				'status'        => 'success',
				'run_trigger'   => 'rest',
				'runner'        => 'sync',
				'scheduled_for' => '2026-09-07 11:00:00',
			)
		);
		$now = static function () {
			return '2026-09-08 12:00:00';
		};
		wpcv_test_inject_run_repository( new WPCV_Run_Repository( $wpdb, $now ) );
		wpcv_test_inject_target_run_repository( new WPCV_Target_Run_Repository( $wpdb, $now ) );

		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertSame( 1, $response->data['run_id'] );
		$this->assertSame( 'success', $response->data['status'] );
		$this->assertSame( 0, $response->data['pending_targets'] );
		$this->assertSame( 0, $response->data['retry_targets'] );
		// 設定実行時刻(13:00)をまだ過ぎていないため、新規runは作られていない.
		$this->assertCount( 1, $wpdb->rows['wp_wpcv_runs'] );
	}

	/**
	 * Runが1件も存在しない状態で、まだ due でない場合は `run_id`/`status` とも
	 * `null` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_handle_run_reports_nulls_when_no_run_exists_yet_and_not_due() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array(
			'run_hour'   => 13,
			'run_minute' => 0,
		);

		$now = static function () {
			return '2026-09-08 12:00:00';
		};
		wpcv_test_inject_run_repository( new WPCV_Run_Repository( new WPCV_Test_Fake_WPDB(), $now ) );
		wpcv_test_inject_target_run_repository( new WPCV_Target_Run_Repository( new WPCV_Test_Fake_WPDB(), $now ) );

		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertSame(
			array(
				'run_id'          => null,
				'status'          => null,
				'pending_targets' => 0,
				'retry_targets'   => 0,
				'next_retry_at'   => null,
			),
			$response->data
		);
	}

	/**
	 * Active runが無く設定実行時刻を過ぎていても、本日分のrunが(ステータスを
	 * 問わず)既に存在する場合は2件目を作成しないことを確認する(v0.4.0 §Step6:
	 * 5分間隔の外部cronが連打しても同一日に1件しか作られないことの確認).
	 *
	 * @return void
	 */
	public function test_handle_run_does_not_create_second_run_for_same_day() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array(
			'run_hour'   => 11,
			'run_minute' => 0,
		);

		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'    => '2026-09-08 11:00:05',
				'finished_at'   => '2026-09-08 11:00:10',
				'status'        => 'success',
				'run_trigger'   => 'rest',
				'runner'        => 'sync',
				'scheduled_for' => '2026-09-08 11:00:00',
			)
		);
		$now = static function () {
			return '2026-09-08 12:00:00';
		};
		wpcv_test_inject_run_repository( new WPCV_Run_Repository( $wpdb, $now ) );
		wpcv_test_inject_target_run_repository( new WPCV_Target_Run_Repository( $wpdb, $now ) );

		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertSame( 1, $response->data['run_id'] );
		$this->assertCount( 1, $wpdb->rows['wp_wpcv_runs'] );
	}

	/**
	 * Dispatchが進捗を生まない(他workerがまだ有効なleaseでclaim中の)状況では、
	 * ループを即座に止め(busy-spinしない)、runが終端に達していないままの状態を
	 * 報告することを確認する(v0.4.0 §Step6「1chunkが予算を超えないようStep3の
	 * 内部yieldを使う」を守るための、時間予算ループ自体の停止条件の確認。
	 * 万一ループが止まらない実装に退行した場合、このテストは(実時間で)ハング
	 * する形で検知できる ―― 予算を短く設定しているため最悪でも数秒で収束する).
	 *
	 * @return void
	 */
	public function test_handle_run_stops_looping_when_no_progress_is_possible() {
		$GLOBALS['_wpcv_test_bloginfo']                              = array( 'version' => '6.8' );
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array(
			'external_http_time_budget_seconds' => 2,
		);

		$made = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_repository( $made['run_repository'] );
		wpcv_test_inject_target_run_repository( $made['target_run_repository'] );
		wpcv_test_inject_sync_dispatcher( $made['dispatcher'] );

		$reservation = $made['run_repository']->reserve_run(
			array(
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);
		// 他workerが有効なleaseでclaim中のtarget_runを1件だけ用意する(claim_next()
		// が候補無しと判定し、runもまだ終端でないため dispatch() が 'waiting' を
		// 返す状況を模す).
		$made['target_run_repository']->save_target_runs(
			$reservation['run_id'],
			array( wpcv_test_make_target_run( array( 'status' => 'running' ) ) )
		);

		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertSame( $reservation['run_id'], $response->data['run_id'] );
		$this->assertSame( 'running', $response->data['status'] );
		$this->assertSame( 0, $response->data['pending_targets'] );
		$this->assertSame( 0, $response->data['retry_targets'] );
	}

	/**
	 * Advisory lock の取得に失敗した場合、`WP_Error`(503)を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_handle_run_returns_busy_error_when_lock_fails() {
		$wpdb                 = new WPCV_Test_Fake_WPDB();
		$wpdb->get_var_return = '0';
		wpcv_test_inject_run_repository( new WPCV_Run_Repository( $wpdb ) );

		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wpcv_rest_busy', $response->get_error_code() );
		$this->assertSame( 503, $response->get_error_data()['status'] );
		$this->assertArrayNotHasKey( 'wp_wpcv_runs', $wpdb->rows );
	}

	/**
	 * Planning中に例外が発生した場合、`WP_Error`(500)を返し、run は failed 記録
	 * されることを確認する。`WPCV_Context_Builder::build()` が version を取得
	 * できない(`_wpcv_test_bloginfo` 未設定)ことで `WPCV_Run_Planner::plan()` の
	 * バリデーション例外を誘発する.
	 *
	 * @return void
	 */
	public function test_handle_run_returns_error_when_planning_throws() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array(
			'run_hour'   => 11,
			'run_minute' => 0,
		);

		$made = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_repository( $made['run_repository'] );
		wpcv_test_inject_target_run_repository( $made['target_run_repository'] );
		wpcv_test_inject_sync_dispatcher( $made['dispatcher'] );

		// $GLOBALS['_wpcv_test_bloginfo'] を設定しないことで version が空文字になり、
		// WPCV_Run_Planner::plan() がバリデーション例外を投げる.
		$response = WPCV_Rest_Run_Controller::handle_run( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wpcv_rest_run_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );

		$row = $made['wpdb']->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'failed', $row['status'] );
	}
}
