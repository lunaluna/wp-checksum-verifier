<?php
/**
 * WPCV_Page_Settings のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-github-client.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-github-mappings.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-settings.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-run-history.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';

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
	 * 時・分は常に2桁で表示する(v0.8 §Step2. U8・U9).
	 *
	 * @return void
	 */
	public function test_format_two_digits_pads_hour_and_minute() {
		$this->assertSame( '00', WPCV_Page_Settings::format_two_digits( 0 ) );
		$this->assertSame( '05', WPCV_Page_Settings::format_two_digits( 5 ) );
		$this->assertSame( '05', WPCV_Page_Settings::format_two_digits( '5' ) );
		$this->assertSame( '23', WPCV_Page_Settings::format_two_digits( 23 ) );
		$this->assertSame( '59', WPCV_Page_Settings::format_two_digits( '59' ) );
	}

	/**
	 * 1桁の入力は整数で保存され、5 と 05 は同じ値になる(保存値は整数のまま).
	 *
	 * @return void
	 */
	public function test_single_digit_input_is_stored_as_integer() {
		unset( $GLOBALS['_wpcv_test_options'] );

		WPCV_Settings::update_run_time( absint( '05' ), absint( '5' ) );

		$this->assertSame(
			array(
				'hour'   => 5,
				'minute' => 5,
			),
			WPCV_Settings::get_run_time()
		);
		$this->assertSame( '05', WPCV_Page_Settings::format_two_digits( WPCV_Settings::get_run_time()['hour'] ) );
	}

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
			'run_id'              => 37,
			'status'              => 'partial',
			'findings_total'      => 7,
			'last_activity_at'    => '2026-09-11 12:00:00',
			'diff_status'         => WPCV_Diff_Status::ALERTING,
			'findings_new'        => 5,
			'findings_resolved'   => 0,
			'findings_continuing' => 1,
			'alert_status'        => 'sent',
			'targets'             => array(
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
		// v0.5後半 §16・§1.4: 差分・アラートの表示(WPCV_Page_Run_Historyの
		// format_diff_summary()/format_alert_status()を再利用していることの確認).
		$this->assertStringContainsString( 'diff: +5 / −0 / =1', $summary );
		$this->assertStringContainsString( 'alert: sent', $summary );
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
		// v0.5後半 §16・§1.4: current_run(検証中)は diff_status が無いため
		// diff: —・alert: — になることの確認.
		$this->assertStringContainsString( 'diff: —', $summary );
		$this->assertStringContainsString( 'alert: —', $summary );
	}

	/**
	 * 対応付けを捨てた理由が、行番号つきの文言になる(v0.8 §Step7).
	 *
	 * @return void
	 */
	public function test_format_mapping_error_names_line_and_reason() {
		$reasons = array(
			WPCV_GitHub_Mappings::REASON_INVALID_FORMAT,
			WPCV_GitHub_Mappings::REASON_INVALID_TARGET,
			WPCV_GitHub_Mappings::REASON_INVALID_REPO,
			WPCV_GitHub_Mappings::REASON_DUPLICATE_TARGET,
		);

		$messages = array();

		foreach ( $reasons as $reason ) {
			$message = WPCV_Page_Settings::format_mapping_error( 7, $reason );

			$this->assertStringContainsString( 'Line 7', $message, $reason );
			$messages[] = $message;
		}

		$this->assertCount( 4, array_unique( $messages ) );
	}

	/**
	 * フィルターだけが足した対応付けを、設定の対応付けと区別して列挙する.
	 *
	 * @return void
	 */
	public function test_filter_only_mappings_excludes_stored_targets() {
		$resolved = array(
			'plugin:stored'  => array(
				'repo'  => 'o/stored',
				'asset' => '',
			),
			'plugin:by-code' => array(
				'repo'  => 'o/by-code',
				'asset' => '',
			),
		);
		$stored   = array(
			array(
				'target' => 'plugin:stored',
				'repo'   => 'o/stored',
				'asset'  => '',
			),
		);

		$this->assertSame( array( 'plugin:by-code → o/by-code' ), WPCV_Page_Settings::filter_only_mappings( $resolved, $stored ) );
		$this->assertSame( array(), WPCV_Page_Settings::filter_only_mappings( array(), $stored ) );
	}

	/**
	 * コアのマニフェストに `wp-content/themes/{stylesheet}/` があるテーマの対応付けだけを
	 * 警告の対象にする(R2). プラグインや、コアに無いテーマは対象外. マニフェストが
	 * キャッシュされていなければ(null)警告しない.
	 *
	 * @return void
	 */
	public function test_find_core_bundled_themes_uses_core_manifest_paths() {
		$resolved = array(
			'theme:twentytwentyfive' => array(
				'repo'  => 'o/a',
				'asset' => '',
			),
			'theme:custom'           => array(
				'repo'  => 'o/b',
				'asset' => '',
			),
			'plugin:twentytwentyfive' => array(
				'repo'  => 'o/c',
				'asset' => '',
			),
		);
		$core     = array(
			'wp-content/themes/twentytwentyfive/style.css' => array(),
			'wp-includes/version.php'                     => array(),
			'wp-content/themes/twentytwentyfivex/a.css'    => array(),
		);

		$this->assertSame( array( 'twentytwentyfive' ), WPCV_Page_Settings::find_core_bundled_themes( $resolved, $core ) );
		$this->assertSame( array(), WPCV_Page_Settings::find_core_bundled_themes( $resolved, null ) );
		$this->assertSame( array(), WPCV_Page_Settings::find_core_bundled_themes( array(), $core ) );
	}
}
