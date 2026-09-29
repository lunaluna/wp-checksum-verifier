<?php
/**
 * uninstall.php のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * §7-3是正(v0.5.1)の回帰テスト.
 *
 * `uninstall.php` はプラグイン本体のクラスを読み込まない素の手続き型スクリプト
 * のため、`global $wpdb`・オプション関数のスタブを用意したうえで直接 require する
 * (`WPCV_Migrator`/`WPCV_Settings`/`WPCV_Rest_Token` のクラス定数とは独立に
 * オプション名の文字列一致で確認する。他のテストファイルと同様、`WP_UNINSTALL_PLUGIN`
 * を検証する冒頭の exit ガードは、定数を定義してから require することで通過させる).
 */
class UninstallTest extends TestCase {

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
			$GLOBALS['_wpcv_test_delete_option_calls'],
			$GLOBALS['_wpcv_test_delete_site_option_calls'],
			$GLOBALS['wpdb']
		);
	}

	/**
	 * `uninstall.php` は複数回 require されないよう1テストにつき1度きりの実行しか
	 * できない(トップレベルのグローバルコードのため2回目以降は require_once で
	 * 無視される). 全テストで使い回せるよう、最初の1回だけ実行してその時点の
	 * `$wpdb`/削除呼び出しの記録を返す.
	 *
	 * @return WPCV_Test_Fake_WPDB
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$wpdb           = new WPCV_Test_Fake_WPDB();
		$GLOBALS['wpdb'] = $wpdb;

		require dirname( __DIR__ ) . '/uninstall.php';

		return $wpdb;
	}

	/**
	 * 5テーブルすべてに `DROP TABLE IF EXISTS` が発行されることを確認する
	 * (既存の削除処理. 回帰確認).
	 *
	 * @return void
	 */
	public function test_drops_all_five_installation_level_tables() {
		$wpdb = $this->run_uninstall();

		$expected_tables = array(
			'wp_wpcv_findings',
			'wp_wpcv_target_runs',
			'wp_wpcv_runs',
			'wp_wpcv_suppressions',
			'wp_wpcv_file_states',
		);

		foreach ( $expected_tables as $table ) {
			$found = false;

			foreach ( $wpdb->query_calls as $query ) {
				if ( "DROP TABLE IF EXISTS {$table}" === $query ) {
					$found = true;
					break;
				}
			}

			$this->assertTrue( $found, "{$table} への DROP TABLE が見つからない." );
		}
	}

	/**
	 * §7-3是正: `wpcv_db_version` に加え、設定(`wpcv_settings`)とREST APIトークンの
	 * ハッシュ2つ(`wpcv_rest_token_hash`/`wpcv_rest_token_hash_read`)も
	 * `delete_option()` されることを確認する.
	 *
	 * @return void
	 */
	public function test_deletes_settings_and_rest_token_options() {
		$this->run_uninstall();

		$this->assertContains( 'wpcv_db_version', $GLOBALS['_wpcv_test_delete_option_calls'] );
		$this->assertContains( 'wpcv_settings', $GLOBALS['_wpcv_test_delete_option_calls'] );
		$this->assertContains( 'wpcv_rest_token_hash', $GLOBALS['_wpcv_test_delete_option_calls'] );
		$this->assertContains( 'wpcv_rest_token_hash_read', $GLOBALS['_wpcv_test_delete_option_calls'] );
	}

	/**
	 * 単一サイトでは `delete_site_option()` を一切呼ばないことを確認する
	 * (§Step2. 単一サイトの挙動が変わっていないことの回帰確認).
	 *
	 * @return void
	 */
	public function test_does_not_call_delete_site_option_on_single_site() {
		$this->run_uninstall();

		$this->assertArrayNotHasKey( '_wpcv_test_delete_site_option_calls', $GLOBALS );
	}

	/**
	 * §Step2是正(v0.5.1・rev.3/roadmap §2.1 ★1a): マルチサイトでは
	 * `wp_sitemeta` の site option(`delete_site_option()`)も4つとも消えることを
	 * 確認する. これが無いと、削除→再インストール時に
	 * `WPCV_Migrator::get_stored_version()` が古いバージョンを読み続け、
	 * `maybe_upgrade()` がテーブルを作らない不具合になる(alpine-dealer.local
	 * での実地検証で確認済み).
	 *
	 * @return void
	 */
	public function test_deletes_site_options_on_multisite() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;

		$this->run_uninstall();

		$this->assertContains( 'wpcv_db_version', $GLOBALS['_wpcv_test_delete_site_option_calls'] );
		$this->assertContains( 'wpcv_settings', $GLOBALS['_wpcv_test_delete_site_option_calls'] );
		$this->assertContains( 'wpcv_rest_token_hash', $GLOBALS['_wpcv_test_delete_site_option_calls'] );
		$this->assertContains( 'wpcv_rest_token_hash_read', $GLOBALS['_wpcv_test_delete_site_option_calls'] );
	}

	/**
	 * マルチサイトでも `delete_option()`(メインサイトの `wp_options`)は
	 * 引き続き呼ぶことを確認する. `uninstall_plugin()`(WordPressコア)は
	 * switch_to_blog() をしないため常にメインサイトのコンテキストで動く.
	 * v0.3.0以前がメインサイトの `wp_options` に書き込んでいた名残の掃除にもなる
	 * (uninstall.php のコメント参照).
	 *
	 * @return void
	 */
	public function test_also_calls_delete_option_on_multisite() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;

		$this->run_uninstall();

		$this->assertContains( 'wpcv_db_version', $GLOBALS['_wpcv_test_delete_option_calls'] );
		$this->assertContains( 'wpcv_settings', $GLOBALS['_wpcv_test_delete_option_calls'] );
		$this->assertContains( 'wpcv_rest_token_hash', $GLOBALS['_wpcv_test_delete_option_calls'] );
		$this->assertContains( 'wpcv_rest_token_hash_read', $GLOBALS['_wpcv_test_delete_option_calls'] );
	}
}
