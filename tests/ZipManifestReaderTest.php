<?php
/**
 * WPCV_Zip_Manifest_Reader のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-zip-manifest-reader.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Zip_Manifest_Reader`(v0.8 §Step3. D4)のテスト.
 *
 * WordPress.org のテーマ向けの検査(ルート = slug)は `SourceWporgThemeTest` が
 * 通しで確かめている. ここでは切り出したクラス単体と、v0.8 で足した
 * 「ルート名を問わず、最上位のディレクトリが1つだけなら受け入れる」(`$root = null`)を確かめる.
 */
class ZipManifestReaderTest extends TestCase {

	/**
	 * テストで作った zip のパス.
	 *
	 * @var string[]
	 */
	private $zips = array();

	/**
	 * 作った zip を消す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->zips as $zip ) {
			if ( file_exists( $zip ) ) {
				unlink( $zip );
			}
		}
		$this->zips = array();
		parent::tearDown();
	}

	/**
	 * 既定の上限.
	 *
	 * @return array
	 */
	private static function limits() {
		return array(
			'entries'     => 100,
			'entry_bytes' => 1048576,
			'total_bytes' => 10485760,
			'ratio'       => 100,
		);
	}

	/**
	 * zip を作る.
	 *
	 * @param array<string, string> $entries 名前 => 中身(名前が `/` で終わればディレクトリ).
	 * @param callable|null         $after   `function( ZipArchive $zip )`. 作成後の追加操作.
	 * @return string zip のパス.
	 */
	private function make_zip( array $entries, ?callable $after = null ) {
		$path = tempnam( sys_get_temp_dir(), 'wpcv-zip-' );
		unlink( $path );
		$path .= '.zip';

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );

		foreach ( $entries as $name => $content ) {
			if ( '/' === substr( $name, -1 ) ) {
				$zip->addEmptyDir( rtrim( $name, '/' ) );
			} else {
				$zip->addFromString( $name, $content );
			}
		}

		if ( null !== $after ) {
			$after( $zip );
		}

		$zip->close();
		$this->zips[] = $path;

		return $path;
	}

	/**
	 * ルートを指定すると、ルートを除いた相対パスで sha256 と md5 が返る.
	 *
	 * @return void
	 */
	public function test_named_root_returns_hashes_without_root() {
		$path = $this->make_zip(
			array(
				'foo/'           => '',
				'foo/foo.php'    => '<?php // foo',
				'foo/inc/a.php'  => 'a',
			)
		);

		$files = WPCV_Zip_Manifest_Reader::read( $path, 'foo', self::limits() );

		$this->assertSame( array( 'foo.php', 'inc/a.php' ), array_keys( $files ) );
		$this->assertSame( hash( 'sha256', '<?php // foo' ), $files['foo.php']['sha256'] );
		$this->assertSame( md5( 'a' ), $files['inc/a.php']['md5'] );
	}

	/**
	 * ルートの名前が違えば、ルート指定のときは拒否される.
	 *
	 * @return void
	 */
	public function test_named_root_rejects_other_root() {
		$path = $this->make_zip( array( 'bar/bar.php' => 'x' ) );

		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, WPCV_Zip_Manifest_Reader::read( $path, 'foo', self::limits() ) );
	}

	/**
	 * `$root = null` なら、最上位のディレクトリが1つだけで名前は何でもよい(GitHub の zip).
	 *
	 * @return void
	 */
	public function test_null_root_accepts_any_single_top_level_directory() {
		$path = $this->make_zip(
			array(
				'wp-checksum-verifier-0.8.0/'          => '',
				'wp-checksum-verifier-0.8.0/main.php' => 'main',
			)
		);

		$files = WPCV_Zip_Manifest_Reader::read( $path, null, self::limits() );

		$this->assertSame( array( 'main.php' ), array_keys( $files ) );
	}

	/**
	 * `$root = null` で、ディレクトリのエントリが無い zip(ファイルだけ)でも受け入れる.
	 *
	 * @return void
	 */
	public function test_null_root_accepts_zip_without_directory_entries() {
		$path = $this->make_zip( array( 'plug/plug.php' => 'p' ) );

		$this->assertSame( array( 'plug.php' ), array_keys( WPCV_Zip_Manifest_Reader::read( $path, null, self::limits() ) ) );
	}

	/**
	 * `$root = null` で最上位が2つ以上なら拒否する.
	 *
	 * @return void
	 */
	public function test_null_root_rejects_multiple_top_level_directories() {
		$path = $this->make_zip(
			array(
				'a/a.php' => 'a',
				'b/b.php' => 'b',
			)
		);

		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, WPCV_Zip_Manifest_Reader::read( $path, null, self::limits() ) );
	}

	/**
	 * `$root = null` で、最上位にファイルが直接あれば拒否する.
	 *
	 * @return void
	 */
	public function test_null_root_rejects_file_at_top_level() {
		$path = $this->make_zip( array( 'loose.php' => 'x' ) );

		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, WPCV_Zip_Manifest_Reader::read( $path, null, self::limits() ) );

		$mixed = $this->make_zip(
			array(
				'dir/dir.php' => 'x',
				'loose.php'   => 'y',
			)
		);

		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, WPCV_Zip_Manifest_Reader::read( $mixed, null, self::limits() ) );
	}

	/**
	 * `$root = null` で、ディレクトリのエントリだけの zip は空のマニフェストになる.
	 *
	 * @return void
	 */
	public function test_null_root_directory_only_zip_is_empty_manifest() {
		$path = $this->make_zip( array( 'only/' => '' ) );

		$this->assertSame( array(), WPCV_Zip_Manifest_Reader::read( $path, null, self::limits() ) );
	}

	/**
	 * `__MACOSX/` は数えず、ハッシュにも入れない.
	 *
	 * @return void
	 */
	public function test_macosx_entries_are_ignored() {
		$path = $this->make_zip(
			array(
				'foo/foo.php'        => 'x',
				'__MACOSX/foo/._foo' => 'junk',
			)
		);

		$this->assertSame( array( 'foo.php' ), array_keys( WPCV_Zip_Manifest_Reader::read( $path, null, self::limits() ) ) );
	}

	/**
	 * `..` を含むエントリは拒否する.
	 *
	 * @return void
	 */
	public function test_parent_directory_entry_is_rejected() {
		$path = $this->make_zip( array( 'foo/../evil.php' => 'x' ) );

		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, WPCV_Zip_Manifest_Reader::read( $path, 'foo', self::limits() ) );
	}

	/**
	 * シンボリックリンクのエントリは拒否する.
	 *
	 * @return void
	 */
	public function test_symlink_entry_is_rejected() {
		$path = $this->make_zip(
			array( 'foo/link' => '/etc/passwd' ),
			static function ( ZipArchive $zip ) {
				$zip->setExternalAttributesName( 'foo/link', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
			}
		);

		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, WPCV_Zip_Manifest_Reader::read( $path, 'foo', self::limits() ) );
	}

	/**
	 * 上限(エントリ数・1エントリのサイズ・合計サイズ)を超えれば拒否する.
	 *
	 * @return void
	 */
	public function test_limits_are_enforced() {
		$path = $this->make_zip(
			array(
				'foo/a.txt' => str_repeat( 'a', 600 ),
				'foo/b.txt' => str_repeat( 'b', 600 ),
			)
		);

		$entries = self::limits();
		$entries['entries'] = 1;
		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, WPCV_Zip_Manifest_Reader::read( $path, 'foo', $entries ) );

		$entry_bytes                = self::limits();
		$entry_bytes['entry_bytes'] = 500;
		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, WPCV_Zip_Manifest_Reader::read( $path, 'foo', $entry_bytes ) );

		$total_bytes                = self::limits();
		$total_bytes['total_bytes'] = 1000;
		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, WPCV_Zip_Manifest_Reader::read( $path, 'foo', $total_bytes ) );

		$ratio          = self::limits();
		$ratio['ratio'] = 2;
		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, WPCV_Zip_Manifest_Reader::read( $path, 'foo', $ratio ) );
	}

	/**
	 * Zip でないファイルは `archive_invalid`.
	 *
	 * @return void
	 */
	public function test_non_zip_is_invalid() {
		$path = tempnam( sys_get_temp_dir(), 'wpcv-notzip-' );
		file_put_contents( $path, 'not a zip' );
		$this->zips[] = $path;

		$this->assertSame( WPCV_Error_Code::ARCHIVE_INVALID, WPCV_Zip_Manifest_Reader::read( $path, 'foo', self::limits() ) );
		$this->assertSame( WPCV_Error_Code::ARCHIVE_INVALID, WPCV_Zip_Manifest_Reader::read( $path, null, self::limits() ) );
	}
}
