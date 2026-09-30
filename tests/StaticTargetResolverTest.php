<?php
/**
 * WPCV_Static_Target_Resolver のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-static-target-resolver.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Static_Target_Resolver`(v0.6 §Step9. プラン§5.3 L1)のテスト。
 *
 * `tests/fixtures/fake-root/`(ABSPATH固定)配下に実ファイルを作って確認する
 * (`UnknownFileScannerTest`等と同じ方針)。`config_file_paths()`/`dropin_paths()`は
 * 引数で基準ディレクトリを差し替えられるため、ABSPATH自体は汚さず、
 * 一時サブディレクトリを都度作って掃除する.
 */
class StaticTargetResolverTest extends TestCase {

	/**
	 * このテストで使う一時ディレクトリの一覧(掃除対象).
	 *
	 * @var string[]
	 */
	private $created_dirs = array();

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->created_dirs as $dir ) {
			$this->remove_path( $dir );
		}
		$this->created_dirs = array();

		unset( $GLOBALS['_wpcv_test_is_multisite'] );

		parent::tearDown();
	}

	/**
	 * ファイル・ディレクトリを再帰的に削除する(存在しなければ何もしない).
	 *
	 * @param string $path 絶対パス.
	 * @return void
	 */
	private function remove_path( $path ) {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( scandir( $path ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$this->remove_path( $path . '/' . $entry );
			}
			rmdir( $path );
			return;
		}

		if ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * `wp-admin`直下に空の`index.php`を置いた「本物のWordPressルートらしい」
	 * 一時ディレクトリを作る(`resolve_wp_config_path()`が`wp-admin/includes/plugin.php`を
	 * requireできるよう、`dropin_paths()`のテストでも同じ一時ルートを使う).
	 *
	 * @return string 作成した一時ディレクトリの絶対パス(末尾スラッシュ無し).
	 */
	private function make_temp_root() {
		$dir                  = sys_get_temp_dir() . '/wpcv-static-target-resolver-test-' . uniqid( '', true );
		$this->created_dirs[] = $dir;
		mkdir( $dir, 0777, true );

		return $dir;
	}

	/**
	 * @param string $relative_path `$root`からの相対パス.
	 * @param string $root          `make_temp_root()`が返した絶対パス.
	 * @param string $content       ファイルの中身. 既定は空文字.
	 * @return void
	 */
	private function put_file( $root, $relative_path, $content = '' ) {
		$absolute_path = $root . '/' . $relative_path;
		$dir           = dirname( $absolute_path );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		file_put_contents( $absolute_path, $content );
	}

	/**
	 * `wp-config.php`がABSPATH直下にあれば、それだけを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_config_file_paths_finds_wp_config_directly_under_abspath() {
		$root = $this->make_temp_root();
		$this->put_file( $root, 'wp-config.php', '<?php' );

		$paths = WPCV_Static_Target_Resolver::config_file_paths( $root );

		$this->assertSame( array( $root . '/wp-config.php' ), $paths );
	}

	/**
	 * `wp-config.php`がABSPATHの1つ上の階層にあり、`wp-settings.php`が
	 * 同階層に無い(=別インストールの一部ではない)場合に見つかることを確認する
	 * (v0.6 §Step9の完了条件. コアの`wp-load.php`L47-55と同じ判定).
	 *
	 * @return void
	 */
	public function test_config_file_paths_finds_wp_config_one_level_above_abspath() {
		$parent = $this->make_temp_root();
		$abspath = $parent . '/public';
		mkdir( $abspath, 0777, true );
		$this->put_file( $parent, 'wp-config.php', '<?php' );

		$paths = WPCV_Static_Target_Resolver::config_file_paths( $abspath );

		$this->assertSame( array( $parent . '/wp-config.php' ), $paths );
	}

	/**
	 * `wp-config.php`が1つ上の階層にあっても、同じ階層に`wp-settings.php`が
	 * 存在する(=別のWordPressインストールの一部)場合は対象外にすることを確認する.
	 *
	 * @return void
	 */
	public function test_config_file_paths_ignores_wp_config_one_level_above_when_wp_settings_present() {
		$parent  = $this->make_temp_root();
		$abspath = $parent . '/public';
		mkdir( $abspath, 0777, true );
		$this->put_file( $parent, 'wp-config.php', '<?php' );
		$this->put_file( $parent, 'wp-settings.php', '<?php' );

		$paths = WPCV_Static_Target_Resolver::config_file_paths( $abspath );

		$this->assertSame( array(), $paths );
	}

	/**
	 * `.htaccess`/`.user.ini`は実在するものだけを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_config_file_paths_includes_only_existing_htaccess_and_user_ini() {
		$root = $this->make_temp_root();
		$this->put_file( $root, 'wp-config.php', '<?php' );
		$this->put_file( $root, '.htaccess', '# rules' );

		$paths = WPCV_Static_Target_Resolver::config_file_paths( $root );

		$this->assertSame(
			array( $root . '/wp-config.php', $root . '/.htaccess' ),
			$paths
		);
	}

	/**
	 * `wp-config.php`もその1つ上の階層のものも無ければ空配列を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_config_file_paths_returns_empty_array_when_nothing_found() {
		$root = $this->make_temp_root();

		$this->assertSame( array(), WPCV_Static_Target_Resolver::config_file_paths( $root ) );
	}

	/**
	 * `_get_dropins()`の一覧のうち、実在するファイルだけを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_dropin_paths_returns_only_existing_dropins() {
		$root = $this->make_temp_root();
		$this->put_file( $root, 'object-cache.php', '<?php' );
		$this->put_file( $root, 'advanced-cache.php', '<?php' );
		// `_get_dropins()`の一覧に無いファイルは対象外.
		$this->put_file( $root, 'not-a-dropin.php', '<?php' );

		$paths = WPCV_Static_Target_Resolver::dropin_paths( $root );

		sort( $paths );
		$expected = array( $root . '/advanced-cache.php', $root . '/object-cache.php' );
		sort( $expected );

		$this->assertSame( $expected, $paths );
	}

	/**
	 * ドロップインが1つも無ければ空配列を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_dropin_paths_returns_empty_array_when_none_exist() {
		$root = $this->make_temp_root();

		$this->assertSame( array(), WPCV_Static_Target_Resolver::dropin_paths( $root ) );
	}

	/**
	 * マルチサイトでは`_get_dropins()`自体が返す一覧が増える(`sunrise.php`等)ため、
	 * それらも実在すれば拾われることを確認する(v0.6 §Step9の完了条件
	 * 「マルチサイトでドロップインの一覧が変わるテスト」).
	 *
	 * @return void
	 */
	public function test_dropin_paths_includes_multisite_only_dropins_when_multisite() {
		$root = $this->make_temp_root();
		$this->put_file( $root, 'sunrise.php', '<?php' );

		// シングルサイトでは`sunrise.php`は`_get_dropins()`の一覧に無いため拾われない.
		$this->assertSame( array(), WPCV_Static_Target_Resolver::dropin_paths( $root ) );

		$GLOBALS['_wpcv_test_is_multisite'] = true;

		$this->assertSame( array( $root . '/sunrise.php' ), WPCV_Static_Target_Resolver::dropin_paths( $root ) );
	}
}
