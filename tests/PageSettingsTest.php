<?php
/**
 * WPCV_Page_Settings のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-settings.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Page_Settings::run_now_button_state()` のテスト.
 *
 * `render()`/`maybe_handle_save()`/`maybe_handle_run_now()` は `check_admin_referer()`・
 * `submit_button()`・`spawn_cron()` 等、`tests/wp-stubs.php` に無い WP コア関数へ多数
 * 依存するため単体テストの対象にしない(v0.3 §Step7の計画通り、`DISABLE_WP_CRON` に
 * よる分岐ロジックだけをレンダリングから分離してテストする)。実際に管理画面から
 * クリックして run が作られることの確認は実地検証側の責務.
 */
class PageSettingsTest extends TestCase {

	/**
	 * `DISABLE_WP_CRON` が真のとき、ボタンを無効化し案内文を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_run_now_button_state_disables_button_when_wp_cron_disabled() {
		$state = WPCV_Page_Settings::run_now_button_state( true );

		$this->assertTrue( $state['disabled'] );
		$this->assertIsString( $state['notice'] );
		$this->assertNotSame( '', $state['notice'] );
	}

	/**
	 * `DISABLE_WP_CRON` が偽のとき、ボタンを有効にし案内文を出さないことを確認する.
	 *
	 * @return void
	 */
	public function test_run_now_button_state_enables_button_when_wp_cron_enabled() {
		$state = WPCV_Page_Settings::run_now_button_state( false );

		$this->assertFalse( $state['disabled'] );
		$this->assertNull( $state['notice'] );
	}

	/**
	 * `format_active_run_notice()`(v0.4.0 §Step5)が run_id を含む案内文を
	 * 組み立てることを確認する.
	 *
	 * @return void
	 */
	public function test_format_active_run_notice_includes_run_id() {
		$notice = WPCV_Page_Settings::format_active_run_notice( 42 );

		$this->assertIsString( $notice );
		$this->assertStringContainsString( '42', $notice );
	}

	/**
	 * `format_run_summary()`(v0.4.0 §Step10)が `null` に対して「None」相当の
	 * 文字列を返すことを確認する(current_run/last_runが無い場合).
	 *
	 * @return void
	 */
	public function test_format_run_summary_returns_none_for_null() {
		$this->assertSame( 'None', WPCV_Page_Settings::format_run_summary( null ) );
	}

	/**
	 * `format_run_summary()` がrun_id・status・pending/retry件数・findings件数・
	 * last_activity_atを含む文字列を組み立てることを確認する.
	 *
	 * @return void
	 */
	public function test_format_run_summary_includes_run_details() {
		$run = array(
			'run_id'           => 37,
			'status'           => 'partial',
			'findings_total'   => 7,
			'last_activity_at' => '2026-09-11 12:00:00',
			'targets'          => array(
				'queued'       => 2,
				'retry'        => 1,
				'running'      => 0,
				'success'      => 36,
				'unverifiable' => 7,
				'failed'       => 0,
				'skipped'      => 0,
				'aborted'      => 0,
				'total'        => 43,
			),
		);

		$summary = WPCV_Page_Settings::format_run_summary( $run );

		$this->assertStringContainsString( '37', $summary );
		$this->assertStringContainsString( 'partial', $summary );
		$this->assertStringContainsString( 'pending: 2', $summary );
		$this->assertStringContainsString( 'retry: 1', $summary );
		$this->assertStringContainsString( 'findings: 7', $summary );
		$this->assertStringContainsString( '2026-09-11 12:00:00', $summary );
	}

	/**
	 * `format_run_summary()` が `last_activity_at` が `null` の場合、日時の
	 * 代わりにダッシュを表示することを確認する(target_runsが一度も
	 * claim・finalizeされていない直後のrun等).
	 *
	 * @return void
	 */
	public function test_format_run_summary_shows_dash_when_no_activity_yet() {
		$run = array(
			'run_id'           => 1,
			'status'           => 'running',
			'findings_total'   => 0,
			'last_activity_at' => null,
			'targets'          => array(
				'queued'  => 5,
				'retry'   => 0,
				'running' => 0,
				'total'   => 5,
			),
		);

		$summary = WPCV_Page_Settings::format_run_summary( $run );

		$this->assertStringContainsString( '—', $summary );
	}
}
