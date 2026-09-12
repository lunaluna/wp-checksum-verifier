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

	/**
	 * 既存option内に旧`rest_time_budget_seconds`キーが残っていても、`get_run_time()`の
	 * 結果には影響しないことを確認する(v0.3.1 §Step4「既存optionのキーは読み捨て、
	 * migrationは不要」の確認. `WPCV_Settings`のクラスdocblock参照).
	 *
	 * @return void
	 */
	public function test_get_run_time_ignores_stale_rest_time_budget_key() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array(
			'run_hour'                 => 5,
			'run_minute'               => 30,
			'rest_time_budget_seconds' => 20,
		);

		$this->assertSame( array( 'hour' => 5, 'minute' => 30 ), WPCV_Settings::get_run_time() );
	}

	/**
	 * 未保存の状態では既定値が返ることを確認する(v0.4.0 §Step6).
	 *
	 * @return void
	 */
	public function test_get_external_http_time_budget_seconds_returns_default_when_unset() {
		$this->assertSame(
			WPCV_Settings::DEFAULT_EXTERNAL_HTTP_TIME_BUDGET_SECONDS,
			WPCV_Settings::get_external_http_time_budget_seconds()
		);
	}

	/**
	 * 保存した値がそのまま返ることを確認する.
	 *
	 * @return void
	 */
	public function test_update_external_http_time_budget_seconds_persists_value() {
		WPCV_Settings::update_external_http_time_budget_seconds( 15 );

		$this->assertSame( 15, WPCV_Settings::get_external_http_time_budget_seconds() );
	}

	/**
	 * 範囲外(5-55外)の値は clamp されることを確認する.
	 *
	 * @return void
	 */
	public function test_update_external_http_time_budget_seconds_clamps_out_of_range_values() {
		WPCV_Settings::update_external_http_time_budget_seconds( 999 );
		$this->assertSame( 55, WPCV_Settings::get_external_http_time_budget_seconds() );

		WPCV_Settings::update_external_http_time_budget_seconds( 0 );
		$this->assertSame( 5, WPCV_Settings::get_external_http_time_budget_seconds() );
	}

	/**
	 * 既存の `run_hour`/`run_minute` 設定に影響を与えないことを確認する.
	 *
	 * @return void
	 */
	public function test_update_external_http_time_budget_seconds_does_not_touch_run_time() {
		WPCV_Settings::update_run_time( 5, 30 );

		WPCV_Settings::update_external_http_time_budget_seconds( 15 );

		$this->assertSame( array( 'hour' => 5, 'minute' => 30 ), WPCV_Settings::get_run_time() );
	}

	/**
	 * 未保存の状態では既定値(false)が返ることを確認する(v0.4.0 §Step8。
	 * `DEFAULT_STRICT_MODE`のdocblock参照).
	 *
	 * @return void
	 */
	public function test_get_strict_mode_returns_default_when_unset() {
		$this->assertFalse( WPCV_Settings::get_strict_mode() );
	}

	/**
	 * 保存した値がそのまま返ることを確認する(v0.4.0コードレビューCR-10是正:
	 * この永続化自体はStep8から実装済みで、今回のCR-10対応で管理画面の保存
	 * フォームから初めて呼ばれるようになった).
	 *
	 * @return void
	 */
	public function test_update_strict_mode_persists_value() {
		WPCV_Settings::update_strict_mode( true );
		$this->assertTrue( WPCV_Settings::get_strict_mode() );

		WPCV_Settings::update_strict_mode( false );
		$this->assertFalse( WPCV_Settings::get_strict_mode() );
	}

	/**
	 * 既存の `run_hour`/`run_minute` 設定に影響を与えないことを確認する
	 * (`update_external_http_time_budget_seconds()` と同じ懸念。設定値は単一の
	 * option配列にまとめて保存されるため、他フィールドの保存が意図せず他の
	 * フィールドを既定値へ巻き戻さないことを確認する).
	 *
	 * @return void
	 */
	public function test_update_strict_mode_does_not_touch_run_time() {
		WPCV_Settings::update_run_time( 5, 30 );

		WPCV_Settings::update_strict_mode( true );

		$this->assertSame( array( 'hour' => 5, 'minute' => 30 ), WPCV_Settings::get_run_time() );
	}
}
