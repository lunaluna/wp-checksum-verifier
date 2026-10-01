<?php
/**
 * WPCV_Manifest_Cache_Cleaner のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-source-core.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-manifest-cache-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-github-client.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-github-mappings.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-manifest-cache-cleaner.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * Run の終端でのマニフェストキャッシュの掃除(v0.7 §Step7. D4)のテスト.
 */
class ManifestCacheCleanerTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['wp_local_package'] );
	}

	/**
	 * 各テストの後に locale を戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_local_package'] );
		parent::tearDown();
	}

	/**
	 * 1件の target_run を入れる.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb      フェイク wpdb.
	 * @param int                 $run_id    run の id.
	 * @param string              $target_id target_id.
	 * @param string              $dimension dimension.
	 * @param string              $slug      slug.
	 * @param string|null         $version   version.
	 * @param string|null         $source    source(`github` のときだけ掃除の対象が変わる. v0.8 §Step6).
	 * @return void
	 */
	private static function insert_target_run( WPCV_Test_Fake_WPDB $wpdb, $run_id, $target_id, $dimension, $slug, $version, $source = null ) {
		$wpdb->insert(
			'wp_wpcv_target_runs',
			array(
				'run_id'    => $run_id,
				'target_id' => $target_id,
				'dimension' => $dimension,
				'slug'      => $slug,
				'version'   => $version,
				'source'    => $source,
				'status'    => 'success',
			)
		);
	}

	/**
	 * Run 1 の target(コア 7.1.2・テーマ acme 1.2 と、その `:_stat`/`:_scan`・
	 * version の無いテーマ)と、キャッシュの行(残すもの・消すもの)を用意する.
	 *
	 * @return array{0: WPCV_Test_Fake_WPDB, 1: WPCV_Manifest_Cache_Repository, 2: WPCV_Manifest_Cache_Cleaner}
	 */
	private function make_scenario() {
		$wpdb  = new WPCV_Test_Fake_WPDB();
		$cache = new WPCV_Manifest_Cache_Repository( $wpdb );
		$files = array(
			'style.css' => array(
				'sha256' => str_repeat( 'a', 64 ),
				'md5'    => str_repeat( 'b', 32 ),
			),
		);

		self::insert_target_run( $wpdb, 1, 'core', 'core', 'wordpress', '7.1.2' );
		self::insert_target_run( $wpdb, 1, 'core:_scan', 'core', '_scan', null );
		self::insert_target_run( $wpdb, 1, 'theme:acme', 'theme', 'acme', '1.2' );
		self::insert_target_run( $wpdb, 1, 'theme:acme:_stat', 'theme', 'acme', '1.2' );
		self::insert_target_run( $wpdb, 1, 'theme:acme:_scan', 'theme', 'acme', '1.2' );
		self::insert_target_run( $wpdb, 1, 'theme:noversion', 'theme', 'noversion', null );
		// 別の run の target は見ない.
		self::insert_target_run( $wpdb, 2, 'theme:other-run', 'theme', 'other-run', '9.9' );

		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'acme', '1.2', $files );
		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'acme', '1.1', $files );
		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'removed', '3.0', $files );
		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'other-run', '9.9', $files );
		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_CORE, 'en_US', '7.1.2', $files );
		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_CORE, 'en_US', '7.1.1', $files );
		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_CORE, 'ja', '7.1.2', $files );

		return array( $wpdb, $cache, new WPCV_Manifest_Cache_Cleaner( $cache, new WPCV_Target_Run_Repository( $wpdb ) ) );
	}

	/**
	 * キャッシュに残っている (source, slug, version) の一覧.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @return string[]
	 */
	private static function remaining( WPCV_Test_Fake_WPDB $wpdb ) {
		$keys = array();

		foreach ( $wpdb->rows['wp_wpcv_manifest_cache'] ?? array() as $row ) {
			$keys[] = $row['source'] . '/' . $row['slug'] . '/' . $row['version'];
		}

		sort( $keys );

		return $keys;
	}

	/**
	 * 成功した run の終端で、今回の run のテーマの (stylesheet, version) と、コアの
	 * (今の locale, version) だけが残る. 更新前の version・削除したテーマ・別の locale・
	 * 別の run にだけ出た target の行は消える(D4).
	 *
	 * @return void
	 */
	public function test_success_keeps_only_rows_of_this_run() {
		list( $wpdb, , $cleaner ) = $this->make_scenario();

		$cleaner->handle_run_terminated( 1, WPCV_Run_Status::SUCCESS );

		$this->assertSame( array( 'core/en_US/7.1.2', 'wporg_theme/acme/1.2' ), self::remaining( $wpdb ) );
	}

	/**
	 * Partial の run でも掃除する(テーマの照合ができない独自テーマがあれば、run は
	 * 普通 partial になるため). コアの locale は `$wp_local_package` に従う.
	 *
	 * @return void
	 */
	public function test_partial_cleans_and_uses_local_package_locale() {
		list( $wpdb, , $cleaner ) = $this->make_scenario();

		$GLOBALS['wp_local_package'] = 'ja';

		$cleaner->handle_run_terminated( 1, WPCV_Run_Status::PARTIAL );

		$this->assertSame( array( 'core/ja/7.1.2', 'wporg_theme/acme/1.2' ), self::remaining( $wpdb ) );
	}

	/**
	 * `failed` と `aborted` の run では何も消さない(列挙が途中で止まっている、または
	 * target_run が1件も無い可能性があるため).
	 *
	 * @return void
	 */
	public function test_failed_or_aborted_run_does_not_clean() {
		foreach ( array( WPCV_Run_Status::FAILED, WPCV_Run_Status::ABORTED ) as $status ) {
			list( $wpdb, , $cleaner ) = $this->make_scenario();

			$cleaner->handle_run_terminated( 1, $status );

			$this->assertCount( 7, $wpdb->rows['wp_wpcv_manifest_cache'], "status: {$status}" );
		}
	}

	/**
	 * 掃除の失敗(SQL エラー)は外に漏らさない(run の確定を妨げない).
	 *
	 * @return void
	 */
	public function test_swallows_repository_exception() {
		list( $wpdb, , $cleaner ) = $this->make_scenario();

		$wpdb->delete_should_fail = true;

		$cleaner->handle_run_terminated( 1, WPCV_Run_Status::SUCCESS );

		$this->assertCount( 7, $wpdb->rows['wp_wpcv_manifest_cache'] );
	}

	/**
	 * GitHub で照合した target(プラグイン・テーマ)は、今回の run の
	 * (`{owner}/{repo}`, version) だけが残る. 更新前の version・対応付けを外したリポジトリ・
	 * 別の run にだけ出た組の行は消える(v0.8 §Step6. §4.6).
	 *
	 * @return void
	 */
	public function test_github_rows_keep_only_mapped_repo_and_version_of_this_run() {
		$wpdb  = new WPCV_Test_Fake_WPDB();
		$cache = new WPCV_Manifest_Cache_Repository( $wpdb );
		$files = array(
			'a.php' => array(
				'sha256' => str_repeat( 'a', 64 ),
				'md5'    => str_repeat( 'b', 32 ),
			),
		);

		self::insert_target_run( $wpdb, 1, 'plugin:fresh', 'plugin', 'fresh', '1.1', 'github' );
		self::insert_target_run( $wpdb, 1, 'plugin:fresh:_stat', 'plugin', 'fresh', '1.1', 'stat' );
		self::insert_target_run( $wpdb, 1, 'theme:acme', 'theme', 'acme', '2.0', 'github' );
		// 対応付けを外したプラグイン(source は wporg に戻る).
		self::insert_target_run( $wpdb, 1, 'plugin:unmapped', 'plugin', 'unmapped', '3.0', 'wporg' );

		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_GITHUB, 'lunaluna/fresh', '1.1', $files );
		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_GITHUB, 'lunaluna/fresh', '1.0', $files );
		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_GITHUB, 'lunaluna/acme-theme', '2.0', $files );
		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_GITHUB, 'lunaluna/unmapped', '3.0', $files );

		$cleaner = new WPCV_Manifest_Cache_Cleaner(
			$cache,
			new WPCV_Target_Run_Repository( $wpdb ),
			static function () {
				return array(
					'plugin:fresh' => array(
						'repo'  => 'lunaluna/fresh',
						'asset' => '',
					),
					'theme:acme'   => array(
						'repo'  => 'lunaluna/acme-theme',
						'asset' => '',
					),
				);
			}
		);

		$cleaner->handle_run_terminated( 1, WPCV_Run_Status::SUCCESS );

		$this->assertSame( array( 'github/lunaluna/acme-theme/2.0', 'github/lunaluna/fresh/1.1' ), self::remaining( $wpdb ) );
	}

	/**
	 * GitHub で照合したテーマは、`wporg_theme` のキャッシュを残す理由にならない
	 * (照合のソースを GitHub に切り替えたテーマの古い wp.org の行が、残り続けない).
	 *
	 * @return void
	 */
	public function test_github_theme_does_not_keep_wporg_theme_row() {
		$wpdb  = new WPCV_Test_Fake_WPDB();
		$cache = new WPCV_Manifest_Cache_Repository( $wpdb );
		$files = array(
			'style.css' => array(
				'sha256' => str_repeat( 'a', 64 ),
				'md5'    => str_repeat( 'b', 32 ),
			),
		);

		self::insert_target_run( $wpdb, 1, 'theme:acme', 'theme', 'acme', '2.0', 'github' );

		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'acme', '2.0', $files );

		$cleaner = new WPCV_Manifest_Cache_Cleaner(
			$cache,
			new WPCV_Target_Run_Repository( $wpdb ),
			static function () {
				return array();
			}
		);

		$cleaner->handle_run_terminated( 1, WPCV_Run_Status::SUCCESS );

		$this->assertSame( array(), self::remaining( $wpdb ) );
	}
}
