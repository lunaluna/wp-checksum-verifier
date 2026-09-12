<?php
/**
 * WPCV_Rest_Status_Controller のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-plugin.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-scheduler.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-wpcv-rest-token.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-wpcv-rest-support.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-wpcv-rest-status-controller.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Rest_Status_Controller` のテスト(v0.4.0 §Step7)。
 *
 * `handle_status()` が current/last run・target集計・最終活動時刻・次回予定を
 * 正しく組み立てること、パーミッションコールバックが `SCOPE_READ` を要求する
 * ことを検証する。ルーティング登録自体は実WordPress環境が必要なため対象外.
 */
class RestStatusControllerTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset(
			$GLOBALS['_wpcv_test_options'],
			$GLOBALS['_wpcv_test_transients'],
			$_SERVER['REMOTE_ADDR']
		);
		wpcv_test_inject_run_repository();
		wpcv_test_inject_target_run_repository();
	}

	/**
	 * 各テストの後、次のテストに影響しないよう `WPCV_Plugin` のキャッシュを戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_run_repository();
		wpcv_test_inject_target_run_repository();
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * 固定時刻を返す Repository を作る(RunRepositoryTest と同じ固定時刻).
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @return WPCV_Run_Repository
	 */
	private function make_run_repository( WPCV_Test_Fake_WPDB $wpdb ) {
		return new WPCV_Run_Repository(
			$wpdb,
			static function () {
				return '2026-09-08 12:00:00';
			}
		);
	}

	/**
	 * `check_permission()` が `SCOPE_READ` のトークンを要求することを確認する
	 * (`SCOPE_RUN` のトークンでは拒否される).
	 *
	 * @return void
	 */
	public function test_check_permission_requires_read_scope_token() {
		$read_token = WPCV_Rest_Token::generate( WPCV_Rest_Token::SCOPE_READ );
		$run_token  = WPCV_Rest_Token::generate( WPCV_Rest_Token::SCOPE_RUN );

		$this->assertTrue(
			WPCV_Rest_Status_Controller::check_permission(
				new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer ' . $read_token ) )
			)
		);

		$result = WPCV_Rest_Status_Controller::check_permission(
			new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer ' . $run_token ) )
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Runが1件も無い状態では `current_run`/`last_run` とも `null` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_handle_status_returns_nulls_when_no_runs_exist() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		wpcv_test_inject_run_repository( $this->make_run_repository( $wpdb ) );
		wpcv_test_inject_target_run_repository( new WPCV_Target_Run_Repository( $wpdb ) );

		$response = WPCV_Rest_Status_Controller::handle_status( new WP_REST_Request() );

		$this->assertNull( $response->data['current_run'] );
		$this->assertNull( $response->data['last_run'] );
		$this->assertIsString( $response->data['next_scheduled_at'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	/**
	 * 進行中(`running`)のrunを `current_run` として、targetの状態別件数込みで
	 * 報告することを確認する.
	 *
	 * @return void
	 */
	public function test_handle_status_describes_current_run_with_target_tally() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'    => '2026-09-08 11:00:00',
				'status'        => 'running',
				'run_trigger'   => 'cron',
				'runner'        => 'async',
				'scheduled_for' => '2026-09-08 11:00:00',
				'deadline_at'   => '2026-09-08 17:00:00',
			)
		);
		$wpdb->insert(
			'wp_wpcv_target_runs',
			array(
				'run_id'       => 1,
				'target_id'    => 'core',
				'status'       => 'success',
				'finished_at'  => '2026-09-08 11:05:00',
				'heartbeat_at' => '2026-09-08 11:04:00',
			)
		);
		$wpdb->insert(
			'wp_wpcv_target_runs',
			array(
				'run_id'       => 1,
				'target_id'    => 'plugin:foo',
				'status'       => 'running',
				'heartbeat_at' => '2026-09-08 11:10:00',
			)
		);
		$wpdb->insert(
			'wp_wpcv_target_runs',
			array(
				'run_id'    => 1,
				'target_id' => 'plugin:bar',
				'status'    => 'queued',
			)
		);

		wpcv_test_inject_run_repository( $this->make_run_repository( $wpdb ) );
		wpcv_test_inject_target_run_repository( new WPCV_Target_Run_Repository( $wpdb ) );

		$response = WPCV_Rest_Status_Controller::handle_status( new WP_REST_Request() );

		$current = $response->data['current_run'];
		$this->assertSame( 1, $current['run_id'] );
		$this->assertSame( 'running', $current['status'] );
		$this->assertSame( 'cron', $current['run_trigger'] );
		$this->assertSame( '2026-09-08 11:00:00', $current['scheduled_for'] );
		$this->assertSame( '2026-09-08 17:00:00', $current['deadline_at'] );
		// heartbeat_at(11:10:00)がfinished_at(11:05:00)より新しいため、こちらが返る.
		$this->assertSame( '2026-09-08 11:10:00', $current['last_activity_at'] );
		$this->assertSame(
			array(
				'queued'       => 1,
				'retry'        => 0,
				'running'      => 1,
				'success'      => 1,
				'unverifiable' => 0,
				'failed'       => 0,
				'skipped'      => 0,
				'aborted'      => 0,
				'total'        => 3,
			),
			$current['targets']
		);

		// 進行中のrunしか無いため last_run は null のまま.
		$this->assertNull( $response->data['last_run'] );
	}

	/**
	 * Terminal状態のrunを `last_run` として報告し、activeなrunが無ければ
	 * `current_run` が `null` であることを確認する.
	 *
	 * @return void
	 */
	public function test_handle_status_describes_last_terminal_run() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'     => '2026-09-07 03:00:00',
				'finished_at'    => '2026-09-07 03:10:00',
				'status'         => 'partial',
				'run_trigger'    => 'cron',
				'runner'         => 'async',
				'findings_total' => 2,
			)
		);

		wpcv_test_inject_run_repository( $this->make_run_repository( $wpdb ) );
		wpcv_test_inject_target_run_repository( new WPCV_Target_Run_Repository( $wpdb ) );

		$response = WPCV_Rest_Status_Controller::handle_status( new WP_REST_Request() );

		$this->assertNull( $response->data['current_run'] );
		$this->assertSame( 'partial', $response->data['last_run']['status'] );
		$this->assertSame( 2, $response->data['last_run']['findings_total'] );
		$this->assertNull( $response->data['last_run']['last_activity_at'] );
	}
}
