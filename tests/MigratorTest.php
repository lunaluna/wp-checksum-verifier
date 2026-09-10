<?php
/**
 * WPCV_Migrator のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-migrator.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Migrator::get_stored_version()` のテスト(v0.3.1 §Step5).
 *
 * `maybe_upgrade()`/`create_or_update_tables()` は `global $wpdb` と実際の
 * `dbDelta()` に依存するため単体テスト対象外(実地検証側の責務。プロジェクト内
 * 既存の慣習を踏襲)。`get_stored_version()` はオプションの読み取りのみで
 * 完結するため、ここで検証する.
 */
class MigratorTest extends TestCase {

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
			$GLOBALS['_wpcv_test_update_site_option_calls'],
			$GLOBALS['_wpcv_test_main_site_id']
		);
	}

	/**
	 * 単一サイトでは `wp_options`(`get_option()`)の値をそのまま返すことを確認する.
	 *
	 * @return void
	 */
	public function test_get_stored_version_reads_option_on_single_site() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Migrator::DB_VERSION_OPTION ] = 3;

		$this->assertSame( 3, WPCV_Migrator::get_stored_version() );
	}

	/**
	 * 単一サイトで未保存なら 0 を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_get_stored_version_returns_zero_when_unset_on_single_site() {
		$this->assertSame( 0, WPCV_Migrator::get_stored_version() );
	}

	/**
	 * マルチサイトで site option が既に設定されていれば、それをそのまま返すことを確認する
	 * (`wp_options` 側に別の値があっても site option を優先する).
	 *
	 * @return void
	 */
	public function test_get_stored_version_prefers_site_option_on_multisite() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;
		$GLOBALS['_wpcv_test_site_options'][ WPCV_Migrator::DB_VERSION_OPTION ] = 2;
		$GLOBALS['_wpcv_test_options'][ WPCV_Migrator::DB_VERSION_OPTION ]      = 99;

		$this->assertSame( 2, WPCV_Migrator::get_stored_version() );
	}

	/**
	 * マルチサイトで site option が未設定の場合、main site の `wp_options` に
	 * 残る旧バージョンをフォールバックとして返し、かつ site option 側へ
	 * 書き込む(キャッシュする)ことを確認する(v0.3.1 §Step5. プラン§P2
	 * 「DB schema versionがマルチサイトでblog単位」の移行パスの確認).
	 *
	 * @return void
	 */
	public function test_get_stored_version_falls_back_to_legacy_option_and_caches_it() {
		$GLOBALS['_wpcv_test_is_multisite']                                = true;
		$GLOBALS['_wpcv_test_options'][ WPCV_Migrator::DB_VERSION_OPTION ] = 1;

		$this->assertSame( 1, WPCV_Migrator::get_stored_version() );
		$this->assertSame( 1, $GLOBALS['_wpcv_test_site_options'][ WPCV_Migrator::DB_VERSION_OPTION ] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_update_site_option_calls'] );
	}

	/**
	 * マルチサイトで site option・legacy option ともに未設定なら 0 を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_get_stored_version_returns_zero_when_nothing_stored_on_multisite() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;

		$this->assertSame( 0, WPCV_Migrator::get_stored_version() );
	}
}
