<?php
/**
 * WPCV_File_Hasher のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';

use PHPUnit\Framework\TestCase;

/**
 * ファイルハッシュ・サイズ取得のテスト. 実ファイルを一時ディレクトリに書いて検証する.
 */
class FileHasherTest extends TestCase {

	/**
	 * テスト用一時ファイルのパス.
	 *
	 * @var string
	 */
	private $tmp_file;

	/**
	 * 各テストの前に一時ファイルを1つ用意する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tmp_file = tempnam( sys_get_temp_dir(), 'wpcv-hasher-test-' );
		file_put_contents( $this->tmp_file, "hello world\n" );
	}

	/**
	 * 各テストの後に一時ファイルを削除する.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( is_string( $this->tmp_file ) && file_exists( $this->tmp_file ) ) {
			unlink( $this->tmp_file );
		}
		parent::tearDown();
	}

	/**
	 * sha256 が PHP 標準の hash_file() の結果と一致することを確認する.
	 *
	 * @return void
	 */
	public function test_hash_sha256_matches_native_hash_file() {
		$this->assertSame(
			hash_file( 'sha256', $this->tmp_file ),
			WPCV_File_Hasher::hash( $this->tmp_file, WPCV_File_Hasher::ALGO_SHA256 )
		);
	}

	/**
	 * md5 も算出できることを確認する(コア照合は md5 のみ提供されるため. §3.2).
	 *
	 * @return void
	 */
	public function test_hash_md5_matches_native_hash_file() {
		$this->assertSame(
			hash_file( 'md5', $this->tmp_file ),
			WPCV_File_Hasher::hash( $this->tmp_file, WPCV_File_Hasher::ALGO_MD5 )
		);
	}

	/**
	 * 存在しないファイルは null を返すことを確認する(例外にしない. §5.5 の unreadable 判定材料).
	 *
	 * @return void
	 */
	public function test_hash_returns_null_for_missing_file() {
		$this->assertNull( WPCV_File_Hasher::hash( $this->tmp_file . '-does-not-exist' ) );
	}

	/**
	 * ディレクトリを渡した場合も null を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_hash_returns_null_for_directory() {
		$this->assertNull( WPCV_File_Hasher::hash( sys_get_temp_dir() ) );
	}

	/**
	 * ファイルサイズが実際のバイト数と一致することを確認する.
	 *
	 * @return void
	 */
	public function test_size_matches_actual_bytes() {
		$this->assertSame( 12, WPCV_File_Hasher::size( $this->tmp_file ) );
	}

	/**
	 * 存在しないファイルのサイズ取得は null を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_size_returns_null_for_missing_file() {
		$this->assertNull( WPCV_File_Hasher::size( $this->tmp_file . '-does-not-exist' ) );
	}
}
