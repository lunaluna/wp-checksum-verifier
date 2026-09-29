<?php
/**
 * WPCV_Admin_Notices のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-settings.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-admin-notices.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-plugin.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Admin_Notices::register()` のテスト(v0.5後半 §Step14d・§2.5).
 *
 * `register()`が`add_action('admin_notices', ...)`(またはマルチサイトでは
 * `network_admin_notices`)に登録したコールバックを、`do_action()`スタブ経由で
 * 実際に発火させ、出力(`ob_start()`)を確認する統合テスト.
 */
class AdminNoticesTest extends TestCase {

	/**
	 * 各テストの前後で、このテスト自身が使うグローバルだけを掃除する.
	 *
	 * `_wpcv_test_added_actions['admin_notices']`/`['network_admin_notices']`は
	 * このクラス以外がrequire時点で登録することは無いフック名のため、キーごと
	 * 消してよい(`feedback-shared-test-global-unset-hidden-by-suite`参照.
	 * `_wpcv_test_added_actions`全体を丸ごと消すのは他ファイルを壊すため厳禁).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resetGlobals();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_run_repository( null );
		$this->resetGlobals();
		parent::tearDown();
	}

	/**
	 * @return void
	 */
	private function resetGlobals() {
		unset(
			$GLOBALS['_wpcv_test_current_screen_id'],
			$GLOBALS['_wpcv_test_is_multisite'],
			$GLOBALS['_wpcv_test_user_capabilities'],
			$GLOBALS['_wpcv_test_do_action_calls']['admin_notices'],
			$GLOBALS['_wpcv_test_do_action_calls']['network_admin_notices'],
			$GLOBALS['_wpcv_test_added_actions']['admin_notices'],
			$GLOBALS['_wpcv_test_added_actions']['network_admin_notices']
		);
	}

	/**
	 * `wp_wpcv_runs`に1行作り、`WPCV_Plugin::run_repository()`を差し替える.
	 *
	 * @param array $overrides 上書きするフィールド.
	 * @return void
	 */
	private function seed_terminal_run( array $overrides = array() ) {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array_merge(
				array(
					'status'      => 'success',
					'run_trigger' => 'cron',
					'runner'      => 'sync',
				),
				$overrides
			)
		);

		wpcv_test_inject_run_repository( new WPCV_Run_Repository( $wpdb ) );
	}

	/**
	 * 対象画面・権限ありで`alert_status = no_recipient`のとき、警告を出すことを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_shows_warning_when_no_recipient() {
		$this->seed_terminal_run( array( 'alert_status' => 'no_recipient' ) );

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'no alert recipients', $output );
	}

	/**
	 * 対象画面・権限ありで`alert_status = failed`のとき、`alert_error`を含む
	 * エラーを出すことを確認する.
	 *
	 * @return void
	 */
	public function test_shows_error_with_message_when_failed() {
		$this->seed_terminal_run(
			array(
				'alert_status' => 'failed',
				'alert_error'  => 'SMTP connect() failed',
			)
		);

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'SMTP connect() failed', $output );
	}

	/**
	 * `alert_error`が無い`failed`でも、汎用メッセージでエラーを出すことを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_shows_generic_error_when_failed_without_message() {
		$this->seed_terminal_run( array( 'alert_status' => 'failed' ) );

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'failed to send', $output );
	}

	/**
	 * `alert_status = sent`のときは何も表示しないことを確認する(§2.5
	 * 「メール成功→通知を消す」).
	 *
	 * @return void
	 */
	public function test_shows_nothing_when_sent() {
		$this->seed_terminal_run( array( 'alert_status' => 'sent' ) );

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * 直近の終端runが無ければ何も表示しないことを確認する.
	 *
	 * @return void
	 */
	public function test_shows_nothing_when_no_terminal_run() {
		wpcv_test_inject_run_repository( new WPCV_Run_Repository( new WPCV_Test_Fake_WPDB() ) );

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * 対象外の画面では何も表示しないことを確認する(WPCVの画面・ダッシュボード・
	 * プラグイン一覧に限定. §2.5).
	 *
	 * @return void
	 */
	public function test_shows_nothing_on_unrelated_screen() {
		$this->seed_terminal_run( array( 'alert_status' => 'no_recipient' ) );

		$GLOBALS['_wpcv_test_current_screen_id'] = 'edit-post';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * ダッシュボード・プラグイン一覧も対象画面に含まれることを確認する.
	 *
	 * @return void
	 */
	public function test_shows_notice_on_dashboard_and_plugins_screen() {
		$this->seed_terminal_run( array( 'alert_status' => 'no_recipient' ) );
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		foreach ( array( 'dashboard', 'plugins' ) as $screen_id ) {
			$GLOBALS['_wpcv_test_current_screen_id'] = $screen_id;

			ob_start();
			do_action( 'admin_notices' );
			$output = ob_get_clean();

			$this->assertStringContainsString( 'notice-warning', $output, "screen={$screen_id}" );
		}
	}

	/**
	 * 権限が無いユーザーには表示しないことを確認する(Step16のテスト要件
	 * 「通知がmanage_options/manage_network_optionsの無いユーザーに出ない」に
	 * 対応).
	 *
	 * @return void
	 */
	public function test_shows_nothing_without_capability() {
		$this->seed_terminal_run( array( 'alert_status' => 'no_recipient' ) );

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array();

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * マルチサイトでは`network_admin_notices`に登録され、ネットワーク管理画面の
	 * ダッシュボード・プラグイン一覧(`dashboard-network`/`plugins-network`)も
	 * 対象になることを確認する.
	 *
	 * @return void
	 */
	public function test_registers_network_admin_notices_on_multisite() {
		$this->seed_terminal_run( array( 'alert_status' => 'no_recipient' ) );

		$GLOBALS['_wpcv_test_is_multisite']      = true;
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_network_options' );
		$GLOBALS['_wpcv_test_current_screen_id'] = 'plugins-network';

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'network_admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );

		// 単一サイト向けの`admin_notices`には登録されないことも確認する.
		ob_start();
		do_action( 'admin_notices' );
		$this->assertSame( '', ob_get_clean() );
	}
}
