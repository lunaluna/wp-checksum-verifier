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
			$GLOBALS['_wpcv_test_delete_site_transient_calls'],
			$GLOBALS['_wpcv_test_site_ids'],
			$GLOBALS['_wpcv_test_blog_switches'],
			$GLOBALS['_wpcv_test_current_blog_id'],
			$GLOBALS['_wpcv_test_large_network'],
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
	 * 7テーブルすべてに `DROP TABLE IF EXISTS` が発行されることを確認する
	 * (既存の削除処理の回帰確認. v0.6 の `wpcv_update_events` と v0.7 §Step1 の
	 * `wpcv_manifest_cache` もここで確かめる).
	 *
	 * @return void
	 */
	public function test_drops_all_installation_level_tables() {
		$wpdb = $this->run_uninstall();

		$expected_tables = array(
			'wp_wpcv_findings',
			'wp_wpcv_target_runs',
			'wp_wpcv_runs',
			'wp_wpcv_suppressions',
			'wp_wpcv_file_states',
			'wp_wpcv_update_events',
			'wp_wpcv_manifest_cache',
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

	// ------------------------------------------------------------------
	// v0.9 §Step6: transient・Action Scheduler の行の掃除.
	// ------------------------------------------------------------------

	/**
	 * 発行された SQL(`query()` の記録)のうち、指定した文字列を含むものを返す.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb   フェイク wpdb.
	 * @param string              $needle 含まれる文字列.
	 * @return string[]
	 */
	private static function queries_containing( WPCV_Test_Fake_WPDB $wpdb, $needle ) {
		return array_values(
			array_filter(
				$wpdb->query_calls,
				static function ( $query ) use ( $needle ) {
					return false !== strpos( $query, $needle );
				}
			)
		);
	}

	/**
	 * Site transient 2つを、名前の完全一致で消すことを確認する. 同梱ライブラリのキャッシュは
	 * `l2dwpghul_updater_` + md5( repo ). 前方一致で消すと、同じライブラリを使う他のプラグインの
	 * キャッシュも消してしまうので、前方一致の SQL は発行しない.
	 *
	 * @return void
	 */
	public function test_deletes_site_transients_by_exact_name() {
		$wpdb = $this->run_uninstall();

		$this->assertSame(
			array(
				'wpcv_github_rate_limited_until',
				'l2dwpghul_updater_' . md5( 'lunaluna/wp-checksum-verifier' ),
			),
			$GLOBALS['_wpcv_test_delete_site_transient_calls']
		);
		$this->assertSame( array(), self::queries_containing( $wpdb, 'l2dwpghul' ), '他のプラグインのキャッシュを巻き込む前方一致の削除はしない.' );
	}

	/**
	 * Updater のキーは、本体ファイルが登録する GitHub のリポジトリ名(`github_repo`)と一致していること
	 * (どちらかを変えたとき、uninstall が別のキーを消す不一致を防ぐ).
	 *
	 * @return void
	 */
	public function test_updater_cache_key_matches_the_registered_repository() {
		$main = (string) file_get_contents( dirname( __DIR__ ) . '/wp-checksum-verifier.php' );

		$this->assertMatchesRegularExpression( "/'github_repo'\\s*=>\\s*'lunaluna\\/wp-checksum-verifier'/", $main );
	}

	/**
	 * REST トークンの失敗回数(transient と、その期限の行)を前方一致で消すことを確認する.
	 * 識別子(IP 等)ごとに名前が変わり列挙できないため. `_` は LIKE のワイルドカードなので
	 * `esc_like()` でエスケープされていること.
	 *
	 * @return void
	 */
	public function test_deletes_rest_token_failure_transients_with_escaped_like() {
		$wpdb = $this->run_uninstall();

		$queries = self::queries_containing( $wpdb, 'DELETE FROM wp_options' );

		$this->assertCount( 1, $queries );
		$this->assertStringContainsString( "option_name LIKE '\\\\_transient\\\\_wpcv\\\\_rest\\\\_token\\\\_fail\\\\_%'", $queries[0] );
		$this->assertStringContainsString( "OR option_name LIKE '\\\\_transient\\\\_timeout\\\\_wpcv\\\\_rest\\\\_token\\\\_fail\\\\_%'", $queries[0] );
	}

	/**
	 * Action Scheduler のテーブルが無いサイトでは、そのテーブルへの DELETE を一切発行しない.
	 *
	 * @return void
	 */
	public function test_skips_action_scheduler_cleanup_when_the_table_is_missing() {
		$wpdb = $this->run_uninstall();

		$this->assertSame( array(), self::queries_containing( $wpdb, 'actionscheduler' ) );
	}

	/**
	 * Action Scheduler のテーブルがあるとき、WPCV のアクション(フックが `wpcv_` で始まる)・そのログ・
	 * グループだけを消し、テーブルは消さない(他のプラグインと共有のため).
	 *
	 * @return void
	 */
	public function test_deletes_only_wpcv_rows_from_action_scheduler_tables() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$wpdb                  = new WPCV_Test_Fake_WPDB();
		$wpdb->existing_tables = array( 'wp_actionscheduler_actions' );
		$GLOBALS['wpdb']       = $wpdb;

		require dirname( __DIR__ ) . '/uninstall.php';

		$logs    = self::queries_containing( $wpdb, 'DELETE l FROM wp_actionscheduler_logs' );
		$actions = self::queries_containing( $wpdb, 'DELETE FROM wp_actionscheduler_actions' );
		$groups  = self::queries_containing( $wpdb, 'DELETE FROM wp_actionscheduler_groups' );

		$this->assertCount( 1, $logs );
		$this->assertCount( 1, $actions );
		$this->assertCount( 1, $groups );

		// どの DELETE も、WPCV のフック(`wpcv_` で始まる)かグループ `wpcv` に絞られている.
		$this->assertStringContainsString( "a.hook LIKE 'wpcv\\\\_%'", $logs[0] );
		$this->assertStringContainsString( "hook LIKE 'wpcv\\\\_%'", $actions[0] );
		$this->assertStringContainsString( "slug = 'wpcv'", $groups[0] );
		$this->assertStringContainsString( 'NOT EXISTS', $groups[0] );

		// テーブル自体は消さない(`DROP TABLE` は WPCV の7テーブルだけ).
		$this->assertSame( array(), self::queries_containing( $wpdb, 'DROP TABLE IF EXISTS wp_actionscheduler' ) );
	}

	/**
	 * 単一サイトでは、サイトの切り替えをしない.
	 *
	 * @return void
	 */
	public function test_single_site_does_not_switch_blogs() {
		$this->run_uninstall();

		$this->assertArrayNotHasKey( '_wpcv_test_blog_switches', $GLOBALS );
	}

	/**
	 * マルチサイトでは、メインサイト以外の全サイトを順に切り替えて、サイトごとの行(REST の失敗回数)を
	 * 消す(サイトごとに自分の `options` テーブルへ). メインサイトは切り替えずに済ませる.
	 *
	 * @return void
	 */
	public function test_multisite_cleans_every_other_site() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;
		$GLOBALS['_wpcv_test_site_ids']     = array( 1, 3, 4, 7 );

		$wpdb = $this->run_uninstall();

		$this->assertSame( array( 3, 4, 7 ), $GLOBALS['_wpcv_test_blog_switches'] );

		foreach ( array( 'wp_options', 'wp_3_options', 'wp_4_options', 'wp_7_options' ) as $table ) {
			$this->assertCount( 1, self::queries_containing( $wpdb, "DELETE FROM {$table} WHERE" ), $table );
		}
	}

	/**
	 * 巨大なネットワーク(`wp_is_large_network()`)では、他のサイトを順に切り替えない
	 * (アンインストールの所要時間が読めないため). メインサイトの掃除だけ行う.
	 *
	 * @return void
	 */
	public function test_large_network_cleans_only_the_main_site() {
		$GLOBALS['_wpcv_test_is_multisite']   = true;
		$GLOBALS['_wpcv_test_site_ids']       = array( 1, 3, 4 );
		$GLOBALS['_wpcv_test_large_network']  = true;

		$wpdb = $this->run_uninstall();

		$this->assertArrayNotHasKey( '_wpcv_test_blog_switches', $GLOBALS );
		$this->assertCount( 1, self::queries_containing( $wpdb, 'DELETE FROM wp_options WHERE' ) );
	}

	/**
	 * 100 サイトを超えるネットワークでも、全サイトを取りこぼさず切り替える(100 件ずつの取得).
	 *
	 * @return void
	 */
	public function test_multisite_pages_through_more_than_one_hundred_sites() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;
		$GLOBALS['_wpcv_test_site_ids']     = range( 1, 250 );

		$this->run_uninstall();

		$this->assertSame( range( 2, 250 ), $GLOBALS['_wpcv_test_blog_switches'] );
	}
}
