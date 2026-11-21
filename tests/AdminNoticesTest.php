<?php
/**
 * WPCV_Admin_Notices のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-capability.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
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

		// 宛先は既定で設定済みにしておく. 宛先なしの警告を確かめるテストだけ
		// `clear_alert_to()` で空にする(警告は今の設定で判定するため).
		WPCV_Settings::update_alert_to( 'ops@example.com' );
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_run_repository( null );
		wpcv_test_inject_target_run_repository( null );
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
			$GLOBALS['_wpcv_test_filters']['wpcv_required_capability'],
			$GLOBALS['_wpcv_test_do_action_calls']['admin_notices'],
			$GLOBALS['_wpcv_test_do_action_calls']['network_admin_notices'],
			$GLOBALS['_wpcv_test_added_actions']['admin_notices'],
			$GLOBALS['_wpcv_test_added_actions']['network_admin_notices'],
			$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ],
			$GLOBALS['_wpcv_test_site_options'][ WPCV_Settings::OPTION_NAME ]
		);
	}

	/**
	 * アラートの宛先を空にする(単一サイト・マルチサイトの両方の option を消す).
	 *
	 * @return void
	 */
	private function clear_alert_to() {
		unset(
			$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ],
			$GLOBALS['_wpcv_test_site_options'][ WPCV_Settings::OPTION_NAME ]
		);
	}

	/**
	 * `wp_wpcv_runs`に1行作り、`WPCV_Plugin::run_repository()`を差し替える.
	 *
	 * @param array $overrides 上書きするフィールド.
	 * @return WPCV_Test_Fake_WPDB run と同じ wpdb(target_run を足すときに使う).
	 */
	private function seed_terminal_run( array $overrides = array() ) {
		$wpdb = new WPCV_Test_Fake_WPDB();

		// v0.9 §Step7: 通知が同じ run の target_run も読むので、同じ wpdb を使う repository を差し替える.
		wpcv_test_inject_target_run_repository( new WPCV_Target_Run_Repository( $wpdb ) );

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

		return $wpdb;
	}

	/**
	 * 対象画面・権限ありで宛先が空のとき、警告を出すことを確認する
	 * (直近の run も `no_recipient` の場合).
	 *
	 * @return void
	 */
	public function test_shows_warning_when_no_recipient() {
		$this->seed_terminal_run( array( 'alert_status' => 'no_recipient' ) );
		$this->clear_alert_to();

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
	 * 宛先を保存したあとは、直近の run が `no_recipient` のままでも警告を出さないことを
	 * 確認する(0.12.0 で修正した不具合. 以前は次の run が終わるまで警告が残り、
	 * WP-Cron が動かないサイトでは消えなかった).
	 *
	 * @return void
	 */
	public function test_hides_warning_once_alert_to_is_saved_even_if_last_run_was_no_recipient() {
		$this->seed_terminal_run( array( 'alert_status' => 'no_recipient' ) );

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * 宛先が空なら、run が1件も無くても(有効化した直後でも)警告を出すことを確認する.
	 *
	 * @return void
	 */
	public function test_shows_warning_when_alert_to_empty_without_any_run() {
		wpcv_test_inject_run_repository( new WPCV_Run_Repository( new WPCV_Test_Fake_WPDB() ) );
		$this->clear_alert_to();

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
	 * 宛先が空なら、直近の run が `not_needed`(送るものが無かった)でも警告を出すことを確認する.
	 *
	 * @return void
	 */
	public function test_shows_warning_when_alert_to_empty_and_last_run_not_needed() {
		$this->seed_terminal_run( array( 'alert_status' => 'not_needed' ) );
		$this->clear_alert_to();

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );

		$this->assertStringContainsString( 'no alert recipients', ob_get_clean() );
	}

	/**
	 * 宛先が空で、直近の run の送信が `failed` なら、宛先の警告と送信失敗のエラーを両方出すことを
	 * 確認する(片方が他方を隠さない).
	 *
	 * @return void
	 */
	public function test_shows_both_no_recipient_warning_and_failed_error() {
		$this->seed_terminal_run(
			array(
				'alert_status' => 'failed',
				'alert_error'  => 'SMTP connect() failed',
			)
		);
		$this->clear_alert_to();

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'no alert recipients', $output );
		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'SMTP connect() failed', $output );
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
		$this->clear_alert_to();

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
		$this->clear_alert_to();
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
		$this->clear_alert_to();

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
		$this->clear_alert_to();

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

	/**
	 * フィルター `wpcv_required_capability` で権限を変えると、通知もその権限で出し分けることを
	 * 確認する(v0.9 §Step5. 画面名は `notices`).既定の権限(manage_options)だけを持つ人には
	 * 出さず、変えた権限を持つ人には出す.
	 *
	 * @return void
	 */
	public function test_notice_follows_the_required_capability_filter() {
		$this->seed_terminal_run( array( 'alert_status' => 'no_recipient' ) );
		$this->clear_alert_to();

		$GLOBALS['_wpcv_test_filters']['wpcv_required_capability'][] = static function ( $capability, $screen ) {
			return 'notices' === $screen ? 'wpcv_custom_cap' : $capability;
		};
		$GLOBALS['_wpcv_test_current_screen_id']                     = 'toplevel_page_wpcv-settings';

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		ob_start();
		do_action( 'admin_notices' );
		$this->assertSame( '', ob_get_clean(), '既定の権限だけでは出ない.' );

		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'wpcv_custom_cap' );

		ob_start();
		do_action( 'admin_notices' );
		$this->assertStringContainsString( 'notice-warning', ob_get_clean(), '変えた権限があれば出る.' );
	}

	/**
	 * `source_access_denied` の本体 target が直近の run に1件以上あれば、件数付きの警告を出す
	 * (v0.9 §Step7・E4). アラートの送信結果(`alert_status`)とは独立に出る.
	 *
	 * @return void
	 */
	public function test_shows_access_denied_notice_with_count() {
		$wpdb = $this->seed_terminal_run( array( 'alert_status' => 'sent' ) );

		foreach ( array( 'plugin:a', 'plugin:b' ) as $target_id ) {
			$wpdb->insert(
				'wp_wpcv_target_runs',
				array(
					'run_id'     => 1,
					'target_id'  => $target_id,
					'status'     => 'unverifiable',
					'error_code' => 'source_access_denied',
				)
			);
		}

		// 同じ run の別の target(対象外)と、`:_stat` の行も入れておく.
		$wpdb->insert( 'wp_wpcv_target_runs', array( 'run_id' => 1, 'target_id' => 'plugin:c', 'status' => 'unverifiable', 'error_code' => 'manifest_not_found' ) );
		$wpdb->insert( 'wp_wpcv_target_runs', array( 'run_id' => 1, 'target_id' => 'plugin:a:_stat', 'status' => 'success', 'error_code' => null ) );

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( '2 target(s) cannot be compared with their source', $output );
		$this->assertStringNotContainsString( 'no alert recipients', $output );
	}

	/**
	 * アラート宛先の警告と access denied の警告は、両方出る(片方が他方を隠さない).
	 *
	 * @return void
	 */
	public function test_shows_both_no_recipient_and_access_denied_notices() {
		$wpdb = $this->seed_terminal_run( array( 'alert_status' => 'no_recipient' ) );
		$this->clear_alert_to();

		$wpdb->insert( 'wp_wpcv_target_runs', array( 'run_id' => 1, 'target_id' => 'plugin:a', 'status' => 'unverifiable', 'error_code' => 'source_access_denied' ) );

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'no alert recipients', $output );
		$this->assertStringContainsString( '1 target(s) cannot be compared', $output );
	}

	/**
	 * `source_access_denied` の target が無ければ、access denied の警告は出ない. トークンの値は出さない.
	 *
	 * @return void
	 */
	public function test_no_access_denied_notice_without_such_targets() {
		$wpdb = $this->seed_terminal_run( array( 'alert_status' => 'sent' ) );

		$wpdb->insert( 'wp_wpcv_target_runs', array( 'run_id' => 1, 'target_id' => 'plugin:c', 'status' => 'unverifiable', 'error_code' => 'http_error' ) );

		$GLOBALS['_wpcv_test_current_screen_id'] = 'toplevel_page_wpcv-settings';
		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		WPCV_Admin_Notices::register( array( 'toplevel_page_wpcv-settings' ) );

		ob_start();
		do_action( 'admin_notices' );

		$this->assertSame( '', ob_get_clean() );
	}
}
