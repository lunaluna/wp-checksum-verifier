<?php
/**
 * WPCV_Path_Normalizer のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';

use PHPUnit\Framework\TestCase;

/**
 * §13: パス正規化とトラバーサル拒否のテスト.
 */
class PathNormalizerTest extends TestCase {

	/**
	 * ABSPATH 配下の絶対パスが相対パスに変換されることを確認する.
	 *
	 * @return void
	 */
	public function test_to_relative_strips_base() {
		$this->assertSame(
			'wp-content/plugins/foo/foo.php',
			WPCV_Path_Normalizer::to_relative( '/var/www/html/wp-content/plugins/foo/foo.php', '/var/www/html' )
		);
	}

	/**
	 * Windows 形式のバックスラッシュがスラッシュに統一されることを確認する.
	 *
	 * @return void
	 */
	public function test_to_relative_normalizes_backslashes() {
		$this->assertSame(
			'wp-content/plugins/foo/foo.php',
			WPCV_Path_Normalizer::to_relative( 'C:\\www\\wp-content\\plugins\\foo\\foo.php', 'C:\\www' )
		);
	}

	/**
	 * 基準ディレクトリ配下でないパスはそのまま(スラッシュ統一のみ)返ることを確認する.
	 *
	 * @return void
	 */
	public function test_to_relative_returns_as_is_when_outside_base() {
		$this->assertSame(
			'/etc/passwd',
			WPCV_Path_Normalizer::to_relative( '/etc/passwd', '/var/www/html' )
		);
	}

	/**
	 * 通常の相対パスは安全と判定されることを確認する.
	 *
	 * @return void
	 */
	public function test_is_safe_relative_path_accepts_normal_path() {
		$this->assertTrue( WPCV_Path_Normalizer::is_safe_relative_path( 'wp-content/plugins/foo/foo.php' ) );
	}

	/**
	 * 空文字が拒否されることを確認する.
	 *
	 * @return void
	 */
	public function test_is_safe_relative_path_rejects_empty_string() {
		$this->assertFalse( WPCV_Path_Normalizer::is_safe_relative_path( '' ) );
	}

	/**
	 * 先頭が `/` の絶対パス相当が拒否されることを確認する.
	 *
	 * @return void
	 */
	public function test_is_safe_relative_path_rejects_leading_slash() {
		$this->assertFalse( WPCV_Path_Normalizer::is_safe_relative_path( '/etc/passwd' ) );
	}

	/**
	 * `..` を含むパストラバーサルが拒否されることを確認する.
	 *
	 * @return void
	 */
	public function test_is_safe_relative_path_rejects_traversal() {
		$this->assertFalse( WPCV_Path_Normalizer::is_safe_relative_path( 'wp-content/../../../etc/passwd' ) );
	}

	/**
	 * バックスラッシュ表記のパストラバーサルも拒否されることを確認する
	 * (`to_forward_slashes()` で統一してから判定するため).
	 *
	 * @return void
	 */
	public function test_is_safe_relative_path_rejects_traversal_with_backslashes() {
		$this->assertFalse( WPCV_Path_Normalizer::is_safe_relative_path( 'wp-content\\..\\..\\wp-config.php' ) );
	}

	/**
	 * null バイトを含むパスが拒否されることを確認する.
	 *
	 * @return void
	 */
	public function test_is_safe_relative_path_rejects_null_byte() {
		$this->assertFalse( WPCV_Path_Normalizer::is_safe_relative_path( "wp-content/foo\0.php" ) );
	}
}
