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

	/**
	 * Stat 差分検知は未保存なら有効(既定 true)で、保存した値がそのまま返り、
	 * 他の設定を巻き戻さないことを確認する(v0.5 §Step8).
	 *
	 * @return void
	 */
	public function test_stat_detection_defaults_to_enabled_and_persists() {
		$this->assertTrue( WPCV_Settings::get_stat_detection_enabled() );

		WPCV_Settings::update_strict_mode( true );
		WPCV_Settings::update_stat_detection_enabled( false );

		$this->assertFalse( WPCV_Settings::get_stat_detection_enabled() );
		$this->assertTrue( WPCV_Settings::get_strict_mode() );

		WPCV_Settings::update_stat_detection_enabled( true );
		$this->assertTrue( WPCV_Settings::get_stat_detection_enabled() );
	}

	/**
	 * 「更新イベントの無い version 変化を知らせる」(v0.6プラン §2.3・U3)は
	 * 未保存なら有効(既定 true)で、保存した値がそのまま返り、他の設定を
	 * 巻き戻さないことを確認する.
	 *
	 * @return void
	 */
	public function test_alert_unrecorded_version_change_defaults_to_enabled_and_persists() {
		$this->assertTrue( WPCV_Settings::get_alert_unrecorded_version_change_enabled() );

		WPCV_Settings::update_strict_mode( true );
		WPCV_Settings::update_alert_unrecorded_version_change_enabled( false );

		$this->assertFalse( WPCV_Settings::get_alert_unrecorded_version_change_enabled() );
		$this->assertTrue( WPCV_Settings::get_strict_mode() );

		WPCV_Settings::update_alert_unrecorded_version_change_enabled( true );
		$this->assertTrue( WPCV_Settings::get_alert_unrecorded_version_change_enabled() );
	}

	/**
	 * 既存の stat target の内容ハッシュ(v0.6 §Step11・U4)は未保存なら無効
	 * (既定`off`)で、保存した値がそのまま返り、他の設定を巻き戻さないことを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_content_hash_mode_defaults_to_off_and_persists() {
		$this->assertSame( WPCV_Settings::CONTENT_HASH_MODE_OFF, WPCV_Settings::get_content_hash_mode() );
		$this->assertFalse( WPCV_Settings::get_content_hash_stat_targets_enabled() );

		WPCV_Settings::update_strict_mode( true );
		WPCV_Settings::update_content_hash_mode( WPCV_Settings::CONTENT_HASH_MODE_STAT_TARGETS );

		$this->assertSame( WPCV_Settings::CONTENT_HASH_MODE_STAT_TARGETS, WPCV_Settings::get_content_hash_mode() );
		$this->assertTrue( WPCV_Settings::get_content_hash_stat_targets_enabled() );
		$this->assertTrue( WPCV_Settings::get_strict_mode() );

		WPCV_Settings::update_content_hash_mode( WPCV_Settings::CONTENT_HASH_MODE_OFF );
		$this->assertFalse( WPCV_Settings::get_content_hash_stat_targets_enabled() );
	}

	/**
	 * 許可した値(`off`/`stat_targets`)以外が保存されていた場合、既定値
	 * (`off`)として扱うことを確認する(option の手動書き換え等を想定).
	 *
	 * @return void
	 */
	public function test_content_hash_mode_falls_back_to_off_for_invalid_stored_value() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array( 'content_hash_mode' => 'bogus' );

		$this->assertSame( WPCV_Settings::CONTENT_HASH_MODE_OFF, WPCV_Settings::get_content_hash_mode() );
		$this->assertFalse( WPCV_Settings::get_content_hash_stat_targets_enabled() );
	}

	/**
	 * `update_content_hash_mode()` に許可されていない値を渡しても、既定値
	 * (`off`)として保存されることを確認する.
	 *
	 * @return void
	 */
	public function test_update_content_hash_mode_falls_back_to_off_for_invalid_value() {
		WPCV_Settings::update_content_hash_mode( 'bogus' );

		$this->assertSame( WPCV_Settings::CONTENT_HASH_MODE_OFF, WPCV_Settings::get_content_hash_mode() );
	}

	// ------------------------------------------------------------------
	// v0.5後半 §Step14: `parse_email_list()`(WPMAR からの移植)と `alert_to`.
	// 最初の3件は WPMAR `tests/SettingsTest.php` と同じ入力・同じ期待値にそろえている.
	// ------------------------------------------------------------------

	/**
	 * 有効なアドレスを受け付けることを確認する(WPMAR と同じ入力).
	 *
	 * @return void
	 */
	public function test_parse_email_list_accepts_valid_addresses() {
		$result = WPCV_Settings::parse_email_list( "user@example.com\nadmin@example.org" );

		$this->assertContains( 'user@example.com', $result );
		$this->assertContains( 'admin@example.org', $result );
	}

	/**
	 * 無効なアドレスを除くことを確認する(WPMAR と同じ入力).
	 *
	 * @return void
	 */
	public function test_parse_email_list_rejects_invalid_addresses() {
		$result = WPCV_Settings::parse_email_list( "valid@example.com\nnot-an-email\n@@broken" );

		$this->assertContains( 'valid@example.com', $result );
		$this->assertNotContains( 'not-an-email', $result );
		$this->assertNotContains( '@@broken', $result );
	}

	/**
	 * 重複を除くことを確認する(WPMAR と同じ入力).
	 *
	 * @return void
	 */
	public function test_parse_email_list_deduplicates() {
		$result = WPCV_Settings::parse_email_list( "foo@bar.com\nfoo@bar.com\nfoo@bar.com" );

		$this->assertCount( 1, $result );
	}

	/**
	 * 改行(CRLF 含む)・`,`・`;` のどれでも区切れ、前後の空白と空の要素を無視し、
	 * 入力順を保つことを確認する.
	 *
	 * @return void
	 */
	public function test_parse_email_list_splits_on_newlines_commas_and_semicolons() {
		$this->assertSame(
			array( 'a@example.com', 'b@example.com', 'c@example.com', 'd@example.com' ),
			WPCV_Settings::parse_email_list( " a@example.com ,b@example.com;\r\n\r\nc@example.com;;, d@example.com\n" )
		);
		$this->assertSame( array(), WPCV_Settings::parse_email_list( '' ) );
	}

	/**
	 * `alert_to` は未設定なら空配列で、保存時に検証され、他の設定を巻き戻さない
	 * ことを確認する.
	 *
	 * @return void
	 */
	public function test_alert_to_defaults_to_empty_and_persists_parsed_list() {
		$this->assertSame( array(), WPCV_Settings::get_alert_to() );

		WPCV_Settings::update_strict_mode( true );
		WPCV_Settings::update_alert_to( "ops@example.com\nnot-an-email\nops@example.com, dev@example.com" );

		$this->assertSame( array( 'ops@example.com', 'dev@example.com' ), WPCV_Settings::get_alert_to() );
		$this->assertTrue( WPCV_Settings::get_strict_mode() );

		WPCV_Settings::update_alert_to( '' );
		$this->assertSame( array(), WPCV_Settings::get_alert_to() );
	}

	/**
	 * Option を直接書き換えられて不正な値が入っていても、`get_alert_to()`は
	 * 検証し直した一覧だけを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_get_alert_to_revalidates_stored_value() {
		$GLOBALS['_wpcv_test_is_multisite']                           = false;
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array(
			'alert_to' => array( 'ok@example.com', "evil@example.com\r\nBcc: x@example.com", 'broken' ),
		);

		$this->assertSame( array( 'ok@example.com', 'evil@example.com' ), WPCV_Settings::get_alert_to() );
	}

	/**
	 * GitHub との対応付け(v0.8 §Step6)は、未設定なら空. 保存した値がそのまま返る.
	 *
	 * @return void
	 */
	public function test_github_mappings_default_empty_and_persist() {
		$this->assertSame( array(), WPCV_Settings::get_github_mappings() );

		WPCV_Settings::update_github_mappings(
			array(
				array(
					'target' => 'plugin:fresh',
					'repo'   => 'lunaluna/fresh',
				),
				array(
					'target' => 'theme:acme',
					'repo'   => 'lunaluna/acme',
					'asset'  => 'acme-pro',
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'target' => 'plugin:fresh',
					'repo'   => 'lunaluna/fresh',
					'asset'  => '',
				),
				array(
					'target' => 'theme:acme',
					'repo'   => 'lunaluna/acme',
					'asset'  => 'acme-pro',
				),
			),
			WPCV_Settings::get_github_mappings()
		);

		// 他の設定を壊さない.
		$this->assertSame( WPCV_Settings::DEFAULT_RUN_HOUR, WPCV_Settings::get_run_time()['hour'] );
	}

	/**
	 * Option が壊れた形(配列でない・配列でない件)に書き換えられていても、空・文字列として読む.
	 *
	 * @return void
	 */
	public function test_get_github_mappings_ignores_malformed_stored_value() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array( 'github_mappings' => 'nope' );
		$this->assertSame( array(), WPCV_Settings::get_github_mappings() );

		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array( 'github_mappings' => array( 'str', array( 'target' => 'plugin:x' ) ) );
		$this->assertSame(
			array(
				array(
					'target' => 'plugin:x',
					'repo'   => '',
					'asset'  => '',
				),
			),
			WPCV_Settings::get_github_mappings()
		);
	}
}
