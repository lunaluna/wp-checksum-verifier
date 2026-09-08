<?php
/**
 * WPCV_Settings のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Settings::get_run_time()` / `update_run_time()` のテスト.
 *
 * 単一サイト(`get_option`/`update_option`)とマルチサイト(`get_site_option`/
 * `update_site_option`)の分岐、既定値とのマージ、範囲外入力の clamp を検証する.
 */
class SettingsTest extends TestCase {

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
			$GLOBALS['_wpcv_test_site_options'],
			$GLOBALS['_wpcv_test_update_option_calls'],
			$GLOBALS['_wpcv_test_update_site_option_calls']
		);
	}

	/**
	 * 未保存の状態では既定値(03:00 UTC)が返ることを確認する.
	 *
	 * @return void
	 */
	public function test_get_run_time_returns_defaults_when_unset() {
		$this->assertSame(
			array(
				'hour'   => WPCV_Settings::DEFAULT_RUN_HOUR,
				'minute' => WPCV_Settings::DEFAULT_RUN_MINUTE,
			),
			WPCV_Settings::get_run_time()
		);
	}

	/**
	 * 単一サイトでは `update_option()`/`get_option()`(`wp_options`)を使うことを確認する.
	 *
	 * @return void
	 */
	public function test_update_run_time_uses_options_table_on_single_site() {
		$GLOBALS['_wpcv_test_is_multisite'] = false;

		WPCV_Settings::update_run_time( 5, 30 );

		$this->assertSame( array( 'hour' => 5, 'minute' => 30 ), WPCV_Settings::get_run_time() );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_update_option_calls'] );
		$this->assertArrayNotHasKey( '_wpcv_test_update_site_option_calls', $GLOBALS );
	}

	/**
	 * マルチサイトでは `update_site_option()`/`get_site_option()`(`wp_sitemeta`)を
	 * 使うことを確認する.
	 *
	 * @return void
	 */
	public function test_update_run_time_uses_site_options_table_on_multisite() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;

		WPCV_Settings::update_run_time( 5, 30 );

		$this->assertSame( array( 'hour' => 5, 'minute' => 30 ), WPCV_Settings::get_run_time() );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_update_site_option_calls'] );
		$this->assertArrayNotHasKey( '_wpcv_test_update_option_calls', $GLOBALS );
	}

	/**
	 * 範囲外の時・分は 0-23 / 0-59 に clamp されることを確認する.
	 *
	 * @return void
	 */
	public function test_update_run_time_clamps_out_of_range_values() {
		WPCV_Settings::update_run_time( 99, -5 );

		$this->assertSame( array( 'hour' => 23, 'minute' => 0 ), WPCV_Settings::get_run_time() );
	}
}
