<?php
/**
 * WPCV_Current_Version_Reader のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-current-version-reader.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Current_Version_Reader`(v0.8 §Step1. D13)のテスト.
 *
 * ABSPATH(tests/fixtures/fake-root/)配下に実ファイルを作って確かめる.
 */
class CurrentVersionReaderTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clean_fixtures();
	}

	/**
	 * 各テストの後に作成したフィクスチャを掃除する.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->clean_fixtures();
		parent::tearDown();
	}

	/**
	 * ABSPATH 直下を `.gitkeep` 以外すべて削除する.
	 *
	 * @return void
	 */
	private function clean_fixtures() {
		foreach ( scandir( ABSPATH ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || '.gitkeep' === $entry ) {
				continue;
			}
			$this->remove_path( ABSPATH . $entry );
		}
	}

	/**
	 * ファイル・ディレクトリを再帰的に削除する.
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
	 * ABSPATH 相対パスにファイルを作る.
	 *
	 * @param string $relative_path ABSPATH 相対パス.
	 * @param string $content       中身.
	 * @return string 絶対パス.
	 */
	private function put_fixture_file( $relative_path, $content ) {
		$path = ABSPATH . $relative_path;

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		file_put_contents( $path, $content );

		return $path;
	}

	/**
	 * プラグインのメインファイルの `Version` ヘッダーを読む.
	 *
	 * @return void
	 */
	public function test_plugin_version_reads_header() {
		$path = $this->put_fixture_file( 'wp-content/plugins/foo/foo.php', "<?php\n/**\n * Plugin Name: Foo\n * Version: 1.2.3\n */\n" );

		$this->assertSame( '1.2.3', WPCV_Current_Version_Reader::plugin_version( $path ) );
	}

	/**
	 * 同じファイルを書き換えると、次の呼び出しで新しい値が返る(キャッシュしない).
	 *
	 * @return void
	 */
	public function test_plugin_version_is_not_cached() {
		$path = $this->put_fixture_file( 'wp-content/plugins/foo/foo.php', "<?php\n/**\n * Version: 1.0\n */\n" );
		$this->assertSame( '1.0', WPCV_Current_Version_Reader::plugin_version( $path ) );

		file_put_contents( $path, "<?php\n/**\n * Version: 1.1\n */\n" );
		clearstatcache();

		$this->assertSame( '1.1', WPCV_Current_Version_Reader::plugin_version( $path ) );
	}

	/**
	 * ファイルが無い、またはヘッダーが無いときは null(呼び出し側が `$context` の値に戻す).
	 *
	 * @return void
	 */
	public function test_plugin_version_is_null_when_unreadable_or_headerless() {
		$headerless = $this->put_fixture_file( 'wp-content/plugins/foo/foo.php', "<?php\n// no header\n" );

		$this->assertNull( WPCV_Current_Version_Reader::plugin_version( ABSPATH . 'wp-content/plugins/missing/missing.php' ) );
		$this->assertNull( WPCV_Current_Version_Reader::plugin_version( $headerless ) );
		$this->assertNull( WPCV_Current_Version_Reader::plugin_version( '' ) );
	}

	/**
	 * テーマの `style.css` の `Version` ヘッダーを読む.
	 *
	 * @return void
	 */
	public function test_theme_version_reads_style_css() {
		$this->put_fixture_file( 'wp-content/themes/bar/style.css', "/*\nTheme Name: Bar\nVersion: 2.5\n*/\n" );

		$this->assertSame( '2.5', WPCV_Current_Version_Reader::theme_version( ABSPATH . 'wp-content/themes/bar' ) );
		$this->assertSame( '2.5', WPCV_Current_Version_Reader::theme_version( ABSPATH . 'wp-content/themes/bar/' ) );
		$this->assertNull( WPCV_Current_Version_Reader::theme_version( ABSPATH . 'wp-content/themes/missing' ) );
	}

	/**
	 * コアの version を `wp-includes/version.php` から読む(`include` しない).
	 *
	 * @return void
	 */
	public function test_core_version_reads_version_php() {
		$this->put_fixture_file( 'wp-includes/version.php', "<?php\n/**\n * @global string \$wp_version\n */\n\$wp_version = '7.1.2';\n\$wp_db_version = 61000;\n" );

		$this->assertSame( '7.1.2', WPCV_Current_Version_Reader::core_version() );
		$this->assertArrayNotHasKey( 'wp_version', $GLOBALS );
	}

	/**
	 * `version.php` が無い、または行が無ければ null.
	 *
	 * @return void
	 */
	public function test_core_version_is_null_when_missing_or_unparsable() {
		$this->assertNull( WPCV_Current_Version_Reader::core_version() );

		$this->put_fixture_file( 'wp-includes/version.php', "<?php\n// nothing\n" );

		$this->assertNull( WPCV_Current_Version_Reader::core_version() );
	}
}
