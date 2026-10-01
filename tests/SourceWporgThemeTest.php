<?php
/**
 * WPCV_Source_Wporg_Theme のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-manifest-cache-repository.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-source-wporg-theme.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * v0.7 §Step2: 公式テーマの zip からマニフェストを作るソースのテスト.
 *
 * zip はテストの中で `ZipArchive` を使って実際に作る(プラン §5 の Step2 の完了条件).
 * 取得(`download_url()`)だけを差し替え、作った zip を一時ファイルにコピーして返す.
 * ソースは取得した一時ファイルを必ず消すので、コピーを渡す.
 */
class SourceWporgThemeTest extends TestCase {

	/**
	 * このテストで作った zip を置くディレクトリ.
	 *
	 * @var string
	 */
	private $work_dir;

	/**
	 * 偽の取得が受け取った引数(`array( url, timeout )` の配列).
	 *
	 * @var array<int, array{0: string, 1: int}>
	 */
	private $download_calls = array();

	/**
	 * 偽の取得が返した一時ファイルのパス(消されたかを確かめるため).
	 *
	 * @var string[]
	 */
	private $downloaded_paths = array();

	/**
	 * 各テストの前に作業ディレクトリを作り、フィルターのスタブを空にする.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_filters'], $GLOBALS['wpdb'] );

		$this->work_dir = sys_get_temp_dir() . '/wpcv-theme-zip-' . uniqid( '', true );
		mkdir( $this->work_dir );
		$this->download_calls   = array();
		$this->downloaded_paths = array();
	}

	/**
	 * 作業ディレクトリを消す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( glob( $this->work_dir . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->work_dir );
		unset( $GLOBALS['_wpcv_test_filters'] );
		parent::tearDown();
	}

	/**
	 * 名前 => 中身の配列から zip を作る. 中身に `array( 'symlink' => 先 )` を渡すと
	 * Unix のシンボリックリンクのエントリにする. 名前が `/` で終わればディレクトリ.
	 *
	 * @param array<string, string|array> $entries エントリ.
	 * @return string zip のパス.
	 */
	private function make_zip( array $entries ) {
		$path = $this->work_dir . '/src-' . uniqid() . '.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		foreach ( $entries as $name => $content ) {
			if ( '/' === substr( $name, -1 ) ) {
				$zip->addEmptyDir( rtrim( $name, '/' ) );
				continue;
			}

			if ( is_array( $content ) ) {
				$zip->addFromString( $name, $content['symlink'] );
				// 0120777: S_IFLNK + rwxrwxrwx. 上位16ビットに Unix のモードを置く.
				$zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, 0120777 << 16 );
				continue;
			}

			$zip->addFromString( $name, $content );
		}

		$zip->close();

		return $path;
	}

	/**
	 * テーマ `acme` の正常な zip のエントリ.
	 *
	 * @return array<string, string>
	 */
	private function valid_entries() {
		return array(
			'acme/'                     => '',
			'acme/style.css'            => "/* Theme Name: Acme */\n",
			'acme/templates/'           => '',
			'acme/templates/index.html' => '<!-- wp:post-content /-->',
			'__MACOSX/acme/._style.css' => 'junk',
		);
	}

	/**
	 * 指定した zip を返す偽の取得を持つソースを作る.
	 *
	 * @param string|WP_Error|null           $zip_or_error zip のパス、または返すエラー. null なら
	 *                                                     取得が呼ばれたらテストを失敗させる.
	 * @param WPCV_Manifest_Cache_Repository $cache        キャッシュ.
	 * @param bool                           $zip_ok       `ZipArchive` が使えることにするか.
	 * @param WPCV_Manifest_Source|null      $core_source  コアのマニフェスト(D7. v0.7 §Step4).
	 * @return WPCV_Source_Wporg_Theme
	 */
	private function make_source( $zip_or_error, WPCV_Manifest_Cache_Repository $cache, $zip_ok = true, ?WPCV_Manifest_Source $core_source = null ) {
		return new WPCV_Source_Wporg_Theme(
			$cache,
			function ( $url, $timeout ) use ( $zip_or_error ) {
				$this->download_calls[] = array( $url, $timeout );

				if ( null === $zip_or_error ) {
					$this->fail( 'download must not be called' );
				}

				if ( $zip_or_error instanceof WP_Error ) {
					return $zip_or_error;
				}

				$tmp = $this->work_dir . '/dl-' . uniqid() . '.tmp';
				copy( $zip_or_error, $tmp );
				$this->downloaded_paths[] = $tmp;

				return $tmp;
			},
			static function () use ( $zip_ok ) {
				return $zip_ok;
			},
			$core_source
		);
	}

	/**
	 * 新しいキャッシュ(空のフェイク wpdb)を作る.
	 *
	 * @return WPCV_Manifest_Cache_Repository
	 */
	private function make_cache() {
		return new WPCV_Manifest_Cache_Repository( new WPCV_Test_Fake_WPDB() );
	}

	/**
	 * フィルターのスタブに固定値を返すコールバックを登録する.
	 *
	 * @param string $tag   フィルター名.
	 * @param mixed  $value 返す値.
	 * @return void
	 */
	private function set_filter( $tag, $value ) {
		$GLOBALS['_wpcv_test_filters'][ $tag ][] = static function () use ( $value ) {
			return $value;
		};
	}

	/**
	 * 取得した一時ファイルがすべて消されていることを確かめる.
	 *
	 * @return void
	 */
	private function assert_downloads_deleted() {
		foreach ( $this->downloaded_paths as $path ) {
			$this->assertFileDoesNotExist( $path );
		}
	}

	/**
	 * 正常な zip から sha256 のマニフェストを作り、`{slug}/` を取った相対パスを
	 * キーにし、`__MACOSX/` とディレクトリを含めないことを確認する. URL・タイムアウト・
	 * キャッシュへの保存(md5 も含む)・一時ファイルの削除も確かめる.
	 *
	 * @return void
	 */
	public function test_builds_sha256_manifest_from_valid_zip_and_caches_it() {
		$wpdb   = new WPCV_Test_Fake_WPDB();
		$cache  = new WPCV_Manifest_Cache_Repository( $wpdb );
		$zip    = $this->make_zip( $this->valid_entries() );
		$result = $this->make_source( $zip, $cache )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$style = "/* Theme Name: Acme */\n";
		$index = '<!-- wp:post-content /-->';

		$this->assertSame(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array(
					'style.css'            => array(
						'algorithm' => 'sha256',
						'hashes'    => array( hash( 'sha256', $style ) ),
					),
					'templates/index.html' => array(
						'algorithm' => 'sha256',
						'hashes'    => array( hash( 'sha256', $index ) ),
					),
				),
			),
			$result
		);

		$this->assertSame( array( array( 'https://downloads.wordpress.org/theme/acme.1.2.zip', 30 ) ), $this->download_calls );
		$this->assert_downloads_deleted();

		$cached = $cache->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'acme', '1.2' );
		$this->assertSame( md5( $style ), $cached['files']['style.css']['md5'] );
		$this->assertSame( filesize( $zip ), $cached['archive_bytes'] );
	}

	/**
	 * 2回目はキャッシュから返し(`cached`)、zip を取得しないことを確認する.
	 *
	 * @return void
	 */
	public function test_second_call_returns_cached_without_download() {
		$cache = $this->make_cache();
		$zip   = $this->make_zip( $this->valid_entries() );

		$first = $this->make_source( $zip, $cache )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$second = $this->make_source( null, $cache )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$this->assertSame( 'cached', $second['manifest_status'] );
		$this->assertNull( $second['error_code'] );
		$this->assertSame( $first['files'], $second['files'] );
	}

	/**
	 * キャッシュがあれば、`ZipArchive` が無い環境でも照合できることを確認する
	 * (キャッシュの確認は `ZipArchive` の確認より先. §3.2 の順序).
	 *
	 * @return void
	 */
	public function test_cache_hit_does_not_require_ziparchive() {
		$cache = $this->make_cache();
		$cache->save(
			WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME,
			'acme',
			'1.2',
			array(
				'style.css' => array(
					'sha256' => str_repeat( 'a', 64 ),
					'md5'    => str_repeat( 'b', 32 ),
				),
			)
		);

		$result = $this->make_source( null, $cache, false )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$this->assertSame( 'cached', $result['manifest_status'] );
		$this->assertSame( array( str_repeat( 'a', 64 ) ), $result['files']['style.css']['hashes'] );
	}

	/**
	 * D6 ①: version が空なら HTTP を出さず `version_unknown`.
	 *
	 * @return void
	 */
	public function test_empty_version_returns_version_unknown_without_http() {
		$result = $this->make_source( null, $this->make_cache() )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '',
			)
		);

		$this->assertSame( WPCV_Error_Code::VERSION_UNKNOWN, $result['error_code'] );
		$this->assertSame( 'missing', $result['manifest_status'] );
		$this->assertSame( array(), $result['files'] );
	}

	/**
	 * D6 ②: 入れ子のテーマ(stylesheet に `/`)は HTTP を出さず `unknown_source`.
	 *
	 * @return void
	 */
	public function test_nested_stylesheet_returns_unknown_source_without_http() {
		$result = $this->make_source( null, $this->make_cache() )->get_manifest(
			array(
				'slug'    => 'collection/acme',
				'version' => '1.2',
			)
		);

		$this->assertSame( WPCV_Error_Code::UNKNOWN_SOURCE, $result['error_code'] );
	}

	/**
	 * D6 ③(U6): Update URI が WordPress.org 以外を指す(`false` を含む)なら
	 * HTTP を出さず `unknown_source`.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function non_wporg_update_uris() {
		return array(
			'other host'         => array( 'https://example.com/themes/acme' ),
			'false'              => array( 'false' ),
			'bare other host'    => array( 'example.com/acme' ),
			'wordpress.org-like' => array( 'https://wordpress.org.example.com/acme' ),
			'subdomain'          => array( 'https://make.wordpress.org/acme' ),
		);
	}

	/**
	 * D6 ③ のテスト本体.
	 *
	 * @dataProvider non_wporg_update_uris
	 *
	 * @param string $update_uri Update URI.
	 * @return void
	 */
	public function test_non_wporg_update_uri_returns_unknown_source_without_http( $update_uri ) {
		$result = $this->make_source( null, $this->make_cache() )->get_manifest(
			array(
				'slug'       => 'acme',
				'version'    => '1.2',
				'update_uri' => $update_uri,
			)
		);

		$this->assertSame( WPCV_Error_Code::UNKNOWN_SOURCE, $result['error_code'] );
	}

	/**
	 * Update URI が WordPress.org を指す(`wordpress.org` / `w.org`. 大文字小文字・
	 * スキームの有無は問わない)なら、通常どおり zip と照合する.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function wporg_update_uris() {
		return array(
			'https wordpress.org' => array( 'https://wordpress.org/themes/acme/' ),
			'w.org'               => array( 'https://w.org/themes/acme' ),
			'bare upper case'     => array( 'WordPress.ORG/themes/acme' ),
			'spaces only'         => array( '   ' ),
		);
	}

	/**
	 * WordPress.org を指す Update URI のテスト本体.
	 *
	 * @dataProvider wporg_update_uris
	 *
	 * @param string $update_uri Update URI.
	 * @return void
	 */
	public function test_wporg_update_uri_is_verified_against_zip( $update_uri ) {
		$zip    = $this->make_zip( $this->valid_entries() );
		$result = $this->make_source( $zip, $this->make_cache() )->get_manifest(
			array(
				'slug'       => 'acme',
				'version'    => '1.2',
				'update_uri' => $update_uri,
			)
		);

		$this->assertSame( 'ok', $result['manifest_status'] );
	}

	/**
	 * `ZipArchive` が無ければ HTTP を出さず `ziparchive_missing`.
	 *
	 * @return void
	 */
	public function test_ziparchive_missing_without_http() {
		$result = $this->make_source( null, $this->make_cache(), false )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$this->assertSame( WPCV_Error_Code::ZIPARCHIVE_MISSING, $result['error_code'] );
	}

	/**
	 * `download_url()` の失敗の振り分け: 404 だけが `manifest_not_found`、500 などの
	 * 200 以外(`download_url()` はすべて `http_404` のコードで返す)と通信の失敗は
	 * `http_error`.
	 *
	 * @return array<string, array{0: WP_Error, 1: string}>
	 */
	public static function download_errors() {
		return array(
			'404'            => array( new WP_Error( 'http_404', 'Not Found', array( 'code' => 404 ) ), WPCV_Error_Code::MANIFEST_NOT_FOUND ),
			'500'            => array( new WP_Error( 'http_404', 'Internal Server Error', array( 'code' => 500 ) ), WPCV_Error_Code::HTTP_ERROR ),
			'request failed' => array( new WP_Error( 'http_request_failed', 'cURL error 28' ), WPCV_Error_Code::HTTP_ERROR ),
		);
	}

	/**
	 * 取得の失敗のテスト本体.
	 *
	 * @dataProvider download_errors
	 *
	 * @param WP_Error $error    偽の取得が返すエラー.
	 * @param string   $expected 期待する error_code.
	 * @return void
	 */
	public function test_download_errors_are_mapped( WP_Error $error, $expected ) {
		$cache  = $this->make_cache();
		$result = $this->make_source( $error, $cache )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$this->assertSame( $expected, $result['error_code'] );
		$this->assertNull( $cache->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'acme', '1.2' ) );
	}

	/**
	 * エントリの検査で拒否される zip(`archive_rejected`). どれもキャッシュに残らず、
	 * 一時ファイルは消える.
	 *
	 * @return array<string, array{0: array<string, string|array>}>
	 */
	public static function rejected_archives() {
		$ok = array( 'acme/style.css' => 'x' );

		return array(
			'parent traversal'     => array( $ok + array( 'acme/../evil.php' => 'x' ) ),
			'backslash traversal'  => array( $ok + array( 'acme\\..\\evil.php' => 'x' ) ),
			'absolute path'        => array( $ok + array( '/etc/passwd' => 'x' ) ),
			'drive letter'         => array( $ok + array( 'C:/evil.php' => 'x' ) ),
			'control character'    => array( $ok + array( "acme/ev\x01il.php" => 'x' ) ),
			'dot segment'          => array( $ok + array( 'acme/./style2.css' => 'x' ) ),
			'empty segment'        => array( $ok + array( 'acme//style2.css' => 'x' ) ),
			'other root'           => array( $ok + array( 'other/style.css' => 'x' ) ),
			'file at root'         => array( $ok + array( 'readme.txt' => 'x' ) ),
			'root name as file'    => array( $ok + array( 'acme' => 'x' ) ),
			'symlink'              => array( $ok + array( 'acme/link' => array( 'symlink' => '/etc/passwd' ) ) ),
			'duplicate after norm' => array( $ok + array( 'acme\\style.css' => 'y' ) ),
		);
	}

	/**
	 * 拒否される zip のテスト本体.
	 *
	 * @dataProvider rejected_archives
	 *
	 * @param array<string, string|array> $entries zip のエントリ.
	 * @return void
	 */
	public function test_rejected_archives( array $entries ) {
		$cache  = $this->make_cache();
		$result = $this->make_source( $this->make_zip( $entries ), $cache )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, $result['error_code'] );
		$this->assertSame( array(), $result['files'] );
		$this->assertNull( $cache->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, 'acme', '1.2' ) );
		$this->assert_downloads_deleted();
	}

	/**
	 * 上限(フィルターで小さくして確かめる)を超えた zip は `archive_rejected`.
	 * 既定の上限では同じ zip が通ることも確かめ、上限の判定だけで落ちたことを示す.
	 *
	 * @return array<string, array{0: string, 1: int|float}>
	 */
	public static function exceeded_limits() {
		return array(
			'archive bytes'     => array( 'wpcv_theme_zip_max_archive_bytes', 100 ),
			'entries'           => array( 'wpcv_theme_zip_max_entries', 3 ),
			'entry bytes'       => array( 'wpcv_theme_zip_max_entry_bytes', 1000 ),
			'total bytes'       => array( 'wpcv_theme_zip_max_total_bytes', 1500 ),
			'compression ratio' => array( 'wpcv_theme_zip_max_compression_ratio', 2 ),
		);
	}

	/**
	 * 上限のテスト本体. zip は「ルートのディレクトリ + 1200バイトの圧縮しやすい
	 * ファイル2つ + 小さいファイル1つ」の4エントリ.
	 *
	 * @dataProvider exceeded_limits
	 *
	 * @param string    $filter フィルター名.
	 * @param int|float $value  小さくした上限.
	 * @return void
	 */
	public function test_limits_reject_archive( $filter, $value ) {
		$entries = array(
			'acme/'          => '',
			'acme/a.css'     => str_repeat( 'a', 1200 ),
			'acme/b.css'     => str_repeat( 'b', 1200 ),
			'acme/style.css' => 'x',
		);

		$baseline = $this->make_source( $this->make_zip( $entries ), $this->make_cache() )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);
		$this->assertSame( 'ok', $baseline['manifest_status'] );

		$this->set_filter( $filter, $value );

		$result = $this->make_source( $this->make_zip( $entries ), $this->make_cache() )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, $result['error_code'] );
		$this->assert_downloads_deleted();
	}

	/**
	 * タイムアウトは `wpcv_theme_zip_download_timeout` で変えられることを確認する.
	 *
	 * @return void
	 */
	public function test_download_timeout_is_filterable() {
		$this->set_filter( 'wpcv_theme_zip_download_timeout', 5 );

		$this->make_source( $this->make_zip( $this->valid_entries() ), $this->make_cache() )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$this->assertSame( 5, $this->download_calls[0][1] );
	}

	/**
	 * zip として開けないファイルは `archive_invalid`.
	 *
	 * @return void
	 */
	public function test_broken_zip_returns_archive_invalid() {
		$path = $this->work_dir . '/broken.zip';
		file_put_contents( $path, 'this is not a zip file' );

		$result = $this->make_source( $path, $this->make_cache() )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$this->assertSame( WPCV_Error_Code::ARCHIVE_INVALID, $result['error_code'] );
		$this->assert_downloads_deleted();
	}

	/**
	 * slug・version が URL 用にエンコードされることを確認する.
	 *
	 * @return void
	 */
	public function test_url_encodes_slug_and_version() {
		$this->make_source( new WP_Error( 'http_404', 'Not Found', array( 'code' => 404 ) ), $this->make_cache() )->get_manifest(
			array(
				'slug'    => 'a b',
				'version' => '1.0+x',
			)
		);

		$this->assertSame( 'https://downloads.wordpress.org/theme/a%20b.1.0%2Bx.zip', $this->download_calls[0][0] );
	}

	/**
	 * slug が無いのは呼び出し側の実装ミスなので例外(プラグインのソースと同じ).
	 *
	 * @return void
	 */
	public function test_missing_slug_throws() {
		$this->expectException( InvalidArgumentException::class );

		$this->make_source( null, $this->make_cache() )->get_manifest( array( 'version' => '1.2' ) );
	}

	/**
	 * コアのマニフェスト(md5)を返す偽物. 呼ばれた回数を `$calls` に数える.
	 *
	 * @param array<string, string> $md5_by_path ABSPATH 相対パス => md5. null なら取得失敗.
	 * @param int                   $calls       呼ばれた回数(参照).
	 * @return WPCV_Manifest_Source
	 */
	private function core_source( $md5_by_path, &$calls = 0 ) {
		return new class( $md5_by_path, $calls ) implements WPCV_Manifest_Source {

			/**
			 * ABSPATH 相対パス => md5(null なら取得失敗).
			 *
			 * @var array|null
			 */
			private $md5_by_path;

			/**
			 * 呼ばれた回数(参照).
			 *
			 * @var int
			 */
			private $calls;

			/**
			 * コンストラクタ.
			 *
			 * @param array|null $md5_by_path ABSPATH 相対パス => md5.
			 * @param int        $calls       呼ばれた回数(参照).
			 */
			public function __construct( $md5_by_path, &$calls ) {
				$this->md5_by_path = $md5_by_path;
				$this->calls       = &$calls;
			}

			/**
			 * マニフェストを返す.
			 *
			 * @param array $context コンテキスト.
			 * @return array
			 */
			public function get_manifest( array $context ) {
				++$this->calls;

				if ( null === $this->md5_by_path ) {
					return array(
						'manifest_status' => 'missing',
						'error_code'      => WPCV_Error_Code::HTTP_ERROR,
						'files'           => array(),
					);
				}

				$files = array();

				foreach ( $this->md5_by_path as $path => $md5 ) {
					$files[ $path ] = array(
						'algorithm' => 'md5',
						'hashes'    => array( $md5 ),
					);
				}

				return array(
					'manifest_status' => 'ok',
					'error_code'      => null,
					'files'           => $files,
				);
			}
		};
	}

	/**
	 * D7: 今のコアのマニフェストにこのテーマのファイルがあれば(コア同梱テーマ)、
	 * ファイルごとに zip の md5 とコアの md5 の両方を候補にする. 同じ値なら1つにまとめ、
	 * コアにだけあるファイルは正解に含めない(欠落として出さない). 2回目(キャッシュ)も
	 * 同じ結果になる.
	 *
	 * @return void
	 */
	public function test_core_bundled_theme_accepts_zip_or_core_md5() {
		$style = "/* Theme Name: Acme */\n";
		$index = '<!-- wp:post-content /-->';
		$core  = $this->core_source(
			array(
				'wp-content/themes/acme/style.css'            => md5( 'core-bundled style' ),
				'wp-content/themes/acme/templates/index.html' => md5( $index ),
				'wp-content/themes/acme/core-only.php'        => md5( 'only in core' ),
				'wp-content/themes/acme-child/style.css'      => md5( 'other theme' ),
				'wp-login.php'                                => md5( 'login' ),
			)
		);
		$cache = $this->make_cache();

		$expected = array(
			'style.css'            => array(
				'algorithm' => 'md5',
				'hashes'    => array( md5( $style ), md5( 'core-bundled style' ) ),
			),
			'templates/index.html' => array(
				'algorithm' => 'md5',
				'hashes'    => array( md5( $index ) ),
			),
		);

		$context = array(
			'slug'         => 'acme',
			'version'      => '1.2',
			'core_version' => '7.1.2',
		);

		$first = $this->make_source( $this->make_zip( $this->valid_entries() ), $cache, true, $core )->get_manifest( $context );
		$this->assertSame( 'ok', $first['manifest_status'] );
		$this->assertSame( $expected, $first['files'] );

		$second = $this->make_source( null, $cache, true, $core )->get_manifest( $context );
		$this->assertSame( 'cached', $second['manifest_status'] );
		$this->assertSame( $expected, $second['files'] );
	}

	/**
	 * コアのマニフェストにこのテーマのファイルが無ければ(`acme-child` のように先頭が
	 * 同じ別のテーマしか無い場合も)、zip の sha256 だけで照合する.
	 *
	 * @return void
	 */
	public function test_theme_not_bundled_in_core_uses_sha256() {
		$core   = $this->core_source( array( 'wp-content/themes/acme-child/style.css' => md5( 'x' ) ) );
		$result = $this->make_source( $this->make_zip( $this->valid_entries() ), $this->make_cache(), true, $core )->get_manifest(
			array(
				'slug'         => 'acme',
				'version'      => '1.2',
				'core_version' => '7.1.2',
			)
		);

		$this->assertSame( 'sha256', $result['files']['style.css']['algorithm'] );
	}

	/**
	 * コアのマニフェストを取得できなければ、zip の sha256 だけで照合する(docblock 参照).
	 *
	 * @return void
	 */
	public function test_core_manifest_failure_falls_back_to_sha256() {
		$result = $this->make_source( $this->make_zip( $this->valid_entries() ), $this->make_cache(), true, $this->core_source( null ) )->get_manifest(
			array(
				'slug'         => 'acme',
				'version'      => '1.2',
				'core_version' => '7.1.2',
			)
		);

		$this->assertSame( 'ok', $result['manifest_status'] );
		$this->assertSame( 'sha256', $result['files']['style.css']['algorithm'] );
	}

	/**
	 * `core_version` が無ければ、コアのマニフェストを取りに行かない.
	 *
	 * @return void
	 */
	public function test_without_core_version_core_manifest_is_not_requested() {
		$calls = 0;
		$core  = $this->core_source( array( 'wp-content/themes/acme/style.css' => md5( 'x' ) ), $calls );

		$result = $this->make_source( $this->make_zip( $this->valid_entries() ), $this->make_cache(), true, $core )->get_manifest(
			array(
				'slug'    => 'acme',
				'version' => '1.2',
			)
		);

		$this->assertSame( 0, $calls );
		$this->assertSame( 'sha256', $result['files']['style.css']['algorithm'] );
	}
}
