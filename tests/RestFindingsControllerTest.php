<?php
/**
 * WPCV_Rest_Findings_Controller のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-plugin.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-wpcv-rest-token.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-wpcv-rest-support.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-wpcv-rest-findings-controller.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Rest_Findings_Controller` のテスト(v0.4.0 §Step7)。
 *
 * `handle_findings()` の run_id 解決(既定は最新run)・allowlist方式のfilter/sort
 * バリデーション(400)・pagination・`WPCV_Rest_Token::SCOPE_READ` 要求を検証する.
 */
class RestFindingsControllerTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_options'], $GLOBALS['_wpcv_test_transients'], $_SERVER['REMOTE_ADDR'] );
		wpcv_test_inject_run_repository();
		wpcv_test_inject_finding_repository();
	}

	/**
	 * 各テストの後、次のテストに影響しないよう `WPCV_Plugin` のキャッシュを戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_run_repository();
		wpcv_test_inject_finding_repository();
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * `check_permission()` が `SCOPE_READ` のトークンを要求することを確認する.
	 *
	 * @return void
	 */
	public function test_check_permission_requires_read_scope_token() {
		$read_token = WPCV_Rest_Token::generate( WPCV_Rest_Token::SCOPE_READ );

		$this->assertTrue(
			WPCV_Rest_Findings_Controller::check_permission(
				new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer ' . $read_token ) )
			)
		);
	}

	/**
	 * Runが1件も無い場合、空の結果を返すことを確認する(`run_id`未指定時).
	 *
	 * @return void
	 */
	public function test_handle_findings_returns_empty_result_when_no_runs_exist() {
		wpcv_test_inject_run_repository( new WPCV_Run_Repository( new WPCV_Test_Fake_WPDB() ) );

		$response = WPCV_Rest_Findings_Controller::handle_findings( new WP_REST_Request() );

		$this->assertSame( array(), $response->data['findings'] );
		$this->assertNull( $response->data['run_id'] );
		$this->assertSame( 0, $response->data['total'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	/**
	 * `run_id` 省略時は最新runのfindingsを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_handle_findings_defaults_to_latest_run() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_runs', array( 'started_at' => '2026-09-07 03:00:00', 'status' => 'success', 'run_trigger' => 'rest', 'runner' => 'sync' ) );
		$wpdb->insert( 'wp_wpcv_runs', array( 'started_at' => '2026-09-08 03:00:00', 'status' => 'success', 'run_trigger' => 'rest', 'runner' => 'sync' ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'old.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 2, 'path' => 'new.php' ) ) );

		wpcv_test_inject_run_repository( new WPCV_Run_Repository( $wpdb ) );
		wpcv_test_inject_finding_repository( new WPCV_Finding_Repository( $wpdb ) );

		$response = WPCV_Rest_Findings_Controller::handle_findings( new WP_REST_Request() );

		$this->assertSame( 2, $response->data['run_id'] );
		$this->assertCount( 1, $response->data['findings'] );
		$this->assertSame( 'new.php', $response->data['findings'][0]['path'] );
	}

	/**
	 * `run_id` を明示指定すると、そのrunのfindingsを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_handle_findings_accepts_explicit_run_id() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_runs', array( 'started_at' => '2026-09-07 03:00:00', 'status' => 'success', 'run_trigger' => 'rest', 'runner' => 'sync' ) );
		$wpdb->insert( 'wp_wpcv_runs', array( 'started_at' => '2026-09-08 03:00:00', 'status' => 'success', 'run_trigger' => 'rest', 'runner' => 'sync' ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'old.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 2, 'path' => 'new.php' ) ) );

		wpcv_test_inject_run_repository( new WPCV_Run_Repository( $wpdb ) );
		wpcv_test_inject_finding_repository( new WPCV_Finding_Repository( $wpdb ) );

		$response = WPCV_Rest_Findings_Controller::handle_findings( new WP_REST_Request( array( 'run_id' => 1 ) ) );

		$this->assertSame( 1, $response->data['run_id'] );
		$this->assertSame( 'old.php', $response->data['findings'][0]['path'] );
	}

	/**
	 * `dimension`/`status`/`severity`/`sort`/`order` に許可されない値を渡すと
	 * `WP_Error`(400)を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_handle_findings_rejects_invalid_param_values() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_runs', array( 'started_at' => '2026-09-08 03:00:00', 'status' => 'success', 'run_trigger' => 'rest', 'runner' => 'sync' ) );
		wpcv_test_inject_run_repository( new WPCV_Run_Repository( $wpdb ) );
		wpcv_test_inject_finding_repository( new WPCV_Finding_Repository( $wpdb ) );

		foreach ( array( 'dimension', 'status', 'severity', 'sort', 'order' ) as $param ) {
			$response = WPCV_Rest_Findings_Controller::handle_findings( new WP_REST_Request( array( $param => 'not-a-real-value' ) ) );

			$this->assertInstanceOf( WP_Error::class, $response, "param={$param}" );
			$this->assertSame( 'wpcv_rest_invalid_param', $response->get_error_code(), "param={$param}" );
			$this->assertSame( 400, $response->get_error_data()['status'], "param={$param}" );
		}
	}

	/**
	 * `page`/`per_page` に応じてpaginationし、`total`/`total_pages` を
	 * 正しく計算することを確認する.
	 *
	 * @return void
	 */
	public function test_handle_findings_paginates_and_reports_totals() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_runs', array( 'started_at' => '2026-09-08 03:00:00', 'status' => 'success', 'run_trigger' => 'rest', 'runner' => 'sync' ) );
		foreach ( range( 1, 3 ) as $i ) {
			$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => "file{$i}.php" ) ) );
		}

		wpcv_test_inject_run_repository( new WPCV_Run_Repository( $wpdb ) );
		wpcv_test_inject_finding_repository( new WPCV_Finding_Repository( $wpdb ) );

		$response = WPCV_Rest_Findings_Controller::handle_findings(
			new WP_REST_Request(
				array(
					'per_page' => 2,
					'page'     => 2,
				)
			)
		);

		$this->assertCount( 1, $response->data['findings'] );
		$this->assertSame( 'file3.php', $response->data['findings'][0]['path'] );
		$this->assertSame( 3, $response->data['total'] );
		$this->assertSame( 2, $response->data['total_pages'] );
		$this->assertSame( 2, $response->data['page'] );
		$this->assertSame( 2, $response->data['per_page'] );
	}

	/**
	 * `include_suppressed`/`include_closed` が `'1'`(クエリ文字列相当)で
	 * 真として解釈されることを確認する.
	 *
	 * @return void
	 */
	public function test_handle_findings_parses_include_suppressed_query_string_value() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_runs', array( 'started_at' => '2026-09-08 03:00:00', 'status' => 'success', 'run_trigger' => 'rest', 'runner' => 'sync' ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'suppressed.php', 'suppressed_by' => 'soft_change' ) ) );

		wpcv_test_inject_run_repository( new WPCV_Run_Repository( $wpdb ) );
		wpcv_test_inject_finding_repository( new WPCV_Finding_Repository( $wpdb ) );

		$without = WPCV_Rest_Findings_Controller::handle_findings( new WP_REST_Request() );
		$this->assertSame( array(), $without->data['findings'] );

		$with = WPCV_Rest_Findings_Controller::handle_findings( new WP_REST_Request( array( 'include_suppressed' => '1' ) ) );
		$this->assertCount( 1, $with->data['findings'] );
	}
}
