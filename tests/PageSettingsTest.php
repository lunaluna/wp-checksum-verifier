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
}
