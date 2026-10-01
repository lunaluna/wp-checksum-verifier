<?php
/**
 * WPCV_Source_GitHub のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-current-version-reader.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-manifest-cache-repository.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-zip-manifest-reader.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-github-client.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-source-github.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Source_GitHub`(v0.8 §Step5. D3・D5・D6・D7・D12)のテスト.
 *
 * zip はテストの中で `ZipArchive` を使って実際に作る. GitHub には接続せず、
 * `WPCV_GitHub_Client` に注入した callable が Release の JSON と zip のコピーを返す
 * (ソースは取得した一時ファイルを必ず消すので、コピーを渡す).
 */
class SourceGitHubTest extends TestCase {

	/**
	 * このテストで作ったファイルを置くディレクトリ.
	 *
	 * @var string
	 */
	private $work_dir;

	/**
	 * Release を引く HTTP の呼び出し回数.
	 *
	 * @var int
	 */
	private $api_calls = 0;

	/**
	 * 偽の取得が返した一時ファイルのパス(消されたかを確かめるため).
	 *
	 * @var string[]
	 */
	private $downloaded_paths = array();

	/**
	 * 各テストの前に作業ディレクトリを作る.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_filters'], $GLOBALS['_wpcv_test_site_transients'], $GLOBALS['wpdb'] );

		$this->work_dir = sys_get_temp_dir() . '/wpcv-gh-source-' . uniqid( '', true );
		mkdir( $this->work_dir );
		$this->api_calls        = 0;
		$this->downloaded_paths = array();
	}

	/**
	 * 作業ディレクトリを消す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->remove_path( $this->work_dir );
		unset( $GLOBALS['_wpcv_test_filters'], $GLOBALS['_wpcv_test_site_transients'] );
		parent::tearDown();
	}

	/**
	 * ファイル・ディレクトリを(シンボリックリンクを辿らず)再帰的に消す.
	 *
	 * @param string $path パス.
	 * @return void
	 */
	private function remove_path( $path ) {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$this->remove_path( $path . '/' . $entry );
				}
			}
			rmdir( $path );
			return;
		}

		if ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * 名前 => 中身の配列から zip を作る.
	 *
	 * @param array<string, string> $entries エントリ(名前が `/` で終わればディレクトリ).
	 * @return string zip のパス.
	 */
	private function make_zip( array $entries ) {
		$path = $this->work_dir . '/src-' . uniqid() . '.zip';
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		foreach ( $entries as $name => $content ) {
			if ( '/' === substr( $name, -1 ) ) {
				$zip->addEmptyDir( rtrim( $name, '/' ) );
			} else {
				$zip->addFromString( $name, $content );
			}
		}

		$zip->close();

		return $path;
	}

	/**
	 * プラグイン `fresh`(version 1.0)の正常な zip のエントリ. ルートの名前は slug と違う.
	 *
	 * @param string $version メインファイルの `Version`.
	 * @return array<string, string>
	 */
	private static function plugin_entries( $version = '1.0' ) {
		return array(
			'fresh-main/'          => '',
			'fresh-main/fresh.php' => "<?php\n/**\n * Plugin Name: Fresh\n * Version: {$version}\n */\n",
			'fresh-main/inc/a.php' => '<?php // a',
		);
	}

	/**
	 * Release のアセットの1要素を作る.
	 *
	 * @param string      $name   アセット名.
	 * @param string|null $zip    zip のパス(`digest` を計算する). null なら digest は null.
	 * @param array       $extra  上書きするキー.
	 * @return array
	 */
	private static function asset( $name, $zip = null, array $extra = array() ) {
		return array_merge(
			array(
				'name'                 => $name,
				'state'                => 'uploaded',
				'size'                 => null === $zip ? 100 : (int) filesize( $zip ),
				'digest'               => null === $zip ? null : 'sha256:' . hash_file( 'sha256', $zip ),
				'url'                  => 'https://api.github.com/repos/lunaluna/fresh/releases/assets/1',
				'browser_download_url' => 'https://github.com/lunaluna/fresh/releases/download/1.0/' . $name,
			),
			$extra
		);
	}

	/**
	 * ソースを作る.
	 *
	 * @param array                               $assets       Release の `assets`.
	 * @param string|null                         $zip          取得で返す zip のパス(null なら取得は呼ばれない想定).
	 * @param WPCV_Manifest_Cache_Repository|null $cache        キャッシュ.
	 * @param bool                                $zip_ok       `ZipArchive` が使えることにするか.
	 * @param array|null                          $http_response 上書きする HTTP 応答(null なら 200 + Release).
	 * @return WPCV_Source_GitHub
	 */
	private function make_source( array $assets, $zip = null, ?WPCV_Manifest_Cache_Repository $cache = null, $zip_ok = true, ?array $http_response = null ) {
		$client = new WPCV_GitHub_Client(
			function () use ( $assets, $http_response ) {
				++$this->api_calls;

				return $http_response ?? array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'tag_name' => '1.0',
							'assets'   => $assets,
						)
					),
					'headers'  => array(),
				);
			},
			function () use ( $zip ) {
				if ( null === $zip ) {
					$this->fail( '取得が呼ばれないはずです.' );
				}

				$copy = $this->work_dir . '/dl-' . uniqid() . '.zip';
				copy( $zip, $copy );
				$this->downloaded_paths[] = $copy;

				return $copy;
			}
		);

		return new WPCV_Source_GitHub(
			$cache ?? new WPCV_Manifest_Cache_Repository( new WPCV_Test_Fake_WPDB() ),
			$client,
			static function () use ( $zip_ok ) {
				return $zip_ok;
			}
		);
	}

	/**
	 * 標準の context.
	 *
	 * @param array $overrides 上書きするキー.
	 * @return array
	 */
	private static function context( array $overrides = array() ) {
		return array_merge(
			array(
				'dimension' => 'plugin',
				'slug'      => 'fresh',
				'version'   => '1.0',
				'repo'      => 'lunaluna/fresh',
				'asset'     => '',
				'base_dir'  => '',
				'main_file' => 'fresh.php',
			),
			$overrides
		);
	}

	/**
	 * 正常: マニフェストが作れ、ルートの名前が slug と違っても受け入れる. 一時ファイルは消える.
	 *
	 * @return void
	 */
	public function test_builds_manifest_from_asset_and_removes_temp_file() {
		$zip    = $this->make_zip( self::plugin_entries() );
		$source = $this->make_source( array( self::asset( 'fresh.1.0.zip', $zip ) ), $zip );

		$manifest = $source->get_manifest( self::context() );

		$this->assertSame( 'ok', $manifest['manifest_status'] );
		$this->assertNull( $manifest['error_code'] );
		$this->assertSame( array( 'fresh.php', 'inc/a.php' ), array_keys( $manifest['files'] ) );
		$this->assertSame(
			array(
				'algorithm' => 'sha256',
				'hashes'    => array( hash( 'sha256', '<?php // a' ) ),
			),
			$manifest['files']['inc/a.php']
		);
		$this->assertCount( 1, $this->downloaded_paths );
		$this->assertFalse( file_exists( $this->downloaded_paths[0] ) );
	}

	/**
	 * 2回目はキャッシュから返り(`cached`)、HTTP も取得も出ない. キャッシュの行は source = github.
	 *
	 * @return void
	 */
	public function test_second_call_is_served_from_cache() {
		$wpdb   = new WPCV_Test_Fake_WPDB();
		$cache  = new WPCV_Manifest_Cache_Repository( $wpdb );
		$zip    = $this->make_zip( self::plugin_entries() );
		$source = $this->make_source( array( self::asset( 'fresh.1.0.zip', $zip ) ), $zip, $cache );

		$first  = $source->get_manifest( self::context() );
		$second = $source->get_manifest( self::context() );

		$this->assertSame( 'cached', $second['manifest_status'] );
		$this->assertSame( $first['files'], $second['files'] );
		$this->assertSame( 1, $this->api_calls );

		$rows = array_values( $wpdb->rows['wp_wpcv_manifest_cache'] );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'github', $rows[0]['source'] );
		$this->assertSame( 'lunaluna/fresh', $rows[0]['slug'] );
		$this->assertSame( '1.0', $rows[0]['version'] );

		// 新しいインスタンス(次のリクエスト)でも、キャッシュから返る.
		$next = $this->make_source( array(), null, $cache );
		$this->assertSame( 'cached', $next->get_manifest( self::context() )['manifest_status'] );
		$this->assertSame( 1, $this->api_calls );
	}

	/**
	 * `{owner}/{repo}` が長すぎてキャッシュに保存できなくても、同じインスタンスでは再取得しない(D7).
	 *
	 * @return void
	 */
	public function test_unsaveable_cache_key_is_memoized_in_instance() {
		$repo   = 'lunaluna/' . str_repeat( 'a', 100 );
		$wpdb   = new WPCV_Test_Fake_WPDB();
		$zip    = $this->make_zip( self::plugin_entries() );
		$source = $this->make_source( array( self::asset( 'fresh.1.0.zip', $zip ) ), $zip, new WPCV_Manifest_Cache_Repository( $wpdb ) );

		$first  = $source->get_manifest( self::context( array( 'repo' => $repo ) ) );
		$second = $source->get_manifest( self::context( array( 'repo' => $repo ) ) );

		$this->assertSame( 'ok', $first['manifest_status'] );
		$this->assertSame( 'cached', $second['manifest_status'] );
		$this->assertSame( 1, $this->api_calls );
		$this->assertEmpty( $wpdb->rows['wp_wpcv_manifest_cache'] ?? array() );
	}

	/**
	 * Version が空なら HTTP なしで `version_unknown`.
	 *
	 * @return void
	 */
	public function test_empty_version_is_version_unknown_without_http() {
		$manifest = $this->make_source( array() )->get_manifest( self::context( array( 'version' => '' ) ) );

		$this->assertSame( WPCV_Error_Code::VERSION_UNKNOWN, $manifest['error_code'] );
		$this->assertSame( 'missing', $manifest['manifest_status'] );
		$this->assertSame( 0, $this->api_calls );
	}

	/**
	 * `.git`(ディレクトリ・ファイル・リンク先)があるディレクトリは HTTP なしで `unknown_source`(D12).
	 *
	 * @return void
	 */
	public function test_git_checkout_is_unknown_source_without_http() {
		mkdir( $this->work_dir . '/dir-repo/.git', 0777, true );
		mkdir( $this->work_dir . '/file-repo', 0777, true );
		file_put_contents( $this->work_dir . '/file-repo/.git', 'gitdir: /somewhere' );
		symlink( $this->work_dir . '/dir-repo', $this->work_dir . '/linked' );

		foreach ( array( 'dir-repo', 'file-repo', 'linked' ) as $dir ) {
			$manifest = $this->make_source( array() )->get_manifest( self::context( array( 'base_dir' => $this->work_dir . '/' . $dir ) ) );

			$this->assertSame( WPCV_Error_Code::UNKNOWN_SOURCE, $manifest['error_code'], $dir );
		}

		$this->assertSame( 0, $this->api_calls );
	}

	/**
	 * `.git` が無いディレクトリ・存在しないディレクトリでは判定に引っかからない.
	 *
	 * @return void
	 */
	public function test_directory_without_git_is_checked_against_github() {
		mkdir( $this->work_dir . '/clean', 0777, true );
		$zip = $this->make_zip( self::plugin_entries() );

		foreach ( array( $this->work_dir . '/clean', $this->work_dir . '/missing' ) as $dir ) {
			$manifest = $this->make_source( array( self::asset( 'fresh.1.0.zip', $zip ) ), $zip )->get_manifest( self::context( array( 'base_dir' => $dir ) ) );

			$this->assertSame( 'ok', $manifest['manifest_status'], $dir );
		}
	}

	/**
	 * `digest` が合わなければ `archive_invalid`. `digest` が null なら検査しない(D5).
	 *
	 * @return void
	 */
	public function test_digest_mismatch_is_archive_invalid_and_null_digest_is_skipped() {
		$zip = $this->make_zip( self::plugin_entries() );

		$bad = $this->make_source( array( self::asset( 'fresh.1.0.zip', $zip, array( 'digest' => 'sha256:' . str_repeat( '0', 64 ) ) ) ), $zip )->get_manifest( self::context() );

		$this->assertSame( WPCV_Error_Code::ARCHIVE_INVALID, $bad['error_code'] );
		$this->assertFalse( file_exists( $this->downloaded_paths[0] ) );

		$none = $this->make_source( array( self::asset( 'fresh.1.0.zip', $zip, array( 'digest' => null ) ) ), $zip )->get_manifest( self::context() );

		$this->assertSame( 'ok', $none['manifest_status'] );
	}

	/**
	 * Zip の中のメインファイルの version が違う・無い・ヘッダーが無い → `asset_ambiguous`(D6).
	 *
	 * @return void
	 */
	public function test_main_file_version_mismatch_is_asset_ambiguous() {
		$cases = array(
			'other version' => self::plugin_entries( '2.0' ),
			'no main file'  => array( 'fresh-main/other.php' => '<?php' ),
			'no header'     => array( 'fresh-main/fresh.php' => "<?php\n// none\n" ),
		);

		foreach ( $cases as $name => $entries ) {
			$zip      = $this->make_zip( $entries );
			$manifest = $this->make_source( array( self::asset( 'fresh.1.0.zip', $zip ) ), $zip )->get_manifest( self::context() );

			$this->assertSame( WPCV_Error_Code::ASSET_AMBIGUOUS, $manifest['error_code'], $name );
		}
	}

	/**
	 * テーマは `style.css` の `Version` を見る. `main_file` が空なら検査しない.
	 *
	 * @return void
	 */
	public function test_theme_main_file_is_style_css() {
		$zip = $this->make_zip(
			array(
				'acme-theme/style.css'  => "/*\nTheme Name: Acme\nVersion: 1.0\n*/\n",
				'acme-theme/index.php'  => '<?php',
			)
		);

		$theme_context = self::context(
			array(
				'dimension' => 'theme',
				'slug'      => 'acme',
				'main_file' => 'style.css',
			)
		);

		$ok = $this->make_source( array( self::asset( 'acme.1.0.zip', $zip ) ), $zip )->get_manifest( $theme_context );
		$this->assertSame( 'ok', $ok['manifest_status'] );

		$mismatch = $this->make_source( array( self::asset( 'acme.1.0.zip', $zip ) ), $zip )->get_manifest( array_merge( $theme_context, array( 'version' => '1.1' ) ) );
		$this->assertSame( WPCV_Error_Code::ASSET_AMBIGUOUS, $mismatch['error_code'] );

		$unchecked = $this->make_source( array( self::asset( 'acme.1.0.zip', $zip ) ), $zip )->get_manifest( array_merge( $theme_context, array( 'version' => '1.1', 'main_file' => '' ) ) );
		$this->assertSame( 'ok', $unchecked['manifest_status'] );
	}

	/**
	 * アセットの選び方(D3). 期待するアセット名を `$expected` で示す(エラーコードなら失敗).
	 *
	 * @return array<string, array>
	 */
	public function asset_selection_provider() {
		return array(
			'exact slug.version.zip wins over other zips' => array( array( 'vendor-pdf.zip', 'fresh.1.0.zip', 'fresh-extra.zip' ), '', 'fresh.1.0.zip' ),
			'single prefix match on slug'                 => array( array( 'vendor-pdf.zip', 'fresh.zip' ), '', 'fresh.zip' ),
			'non zip assets are ignored'                  => array( array( 'fresh.1.0.zip.sha256', 'fresh.1.0.tar.gz', 'fresh.zip' ), '', 'fresh.zip' ),
			'two slug prefix matches are ambiguous'       => array( array( 'fresh-a.zip', 'fresh-b.zip' ), '', WPCV_Error_Code::ASSET_AMBIGUOUS ),
			'no zip is no_release_asset'                  => array( array( 'notes.txt', 'vendor-pdf.zip' ), '', WPCV_Error_Code::NO_RELEASE_ASSET ),
			'no assets is no_release_asset'               => array( array(), '', WPCV_Error_Code::NO_RELEASE_ASSET ),
			'mapping prefix picks the named asset'        => array( array( 'fresh.1.0.zip', 'fresh-pro.1.0.zip' ), 'fresh-pro', 'fresh-pro.1.0.zip' ),
			'mapping prefix ignores exact slug match'     => array( array( 'fresh.1.0.zip', 'other.zip' ), 'other', 'other.zip' ),
			'mapping prefix with two matches is ambiguous' => array( array( 'fresh-pro.1.0.zip', 'fresh-pro.2.0.zip' ), 'fresh-pro', WPCV_Error_Code::ASSET_AMBIGUOUS ),
			'mapping prefix with no match'                => array( array( 'fresh.1.0.zip' ), 'nothing', WPCV_Error_Code::NO_RELEASE_ASSET ),
		);
	}

	/**
	 * アセットの選び方(D3).
	 *
	 * @dataProvider asset_selection_provider
	 *
	 * @param string[] $names    Release のアセット名.
	 * @param string   $prefix   対応付けの `asset`.
	 * @param string   $expected 期待するアセット名、またはエラーコード.
	 * @return void
	 */
	public function test_asset_selection( array $names, $prefix, $expected ) {
		$zip    = $this->make_zip( self::plugin_entries() );
		$assets = array();

		foreach ( $names as $name ) {
			$assets[] = self::asset( $name, '.zip' === substr( $name, -4 ) ? $zip : null );
		}

		$picked = array();
		$client = new WPCV_GitHub_Client(
			static function () use ( $assets ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'tag_name' => '1.0',
							'assets'   => $assets,
						)
					),
					'headers'  => array(),
				);
			},
			function ( $url ) use ( $zip, &$picked ) {
				$picked[] = basename( $url );
				$copy     = $this->work_dir . '/dl-' . uniqid() . '.zip';
				copy( $zip, $copy );

				return $copy;
			}
		);

		$source   = new WPCV_Source_GitHub( new WPCV_Manifest_Cache_Repository( new WPCV_Test_Fake_WPDB() ), $client );
		$manifest = $source->get_manifest( self::context( array( 'asset' => $prefix ) ) );

		if ( 0 === strpos( $expected, 'fresh' ) || 0 === strpos( $expected, 'other' ) ) {
			$this->assertSame( 'ok', $manifest['manifest_status'], $expected );
			$this->assertSame( array( $expected ), $picked );
			return;
		}

		$this->assertSame( $expected, $manifest['error_code'] );
		$this->assertSame( array(), $picked );
	}

	/**
	 * アップロードが終わっていないアセット(`state` が `uploaded` 以外)は使わない.
	 *
	 * @return void
	 */
	public function test_non_uploaded_asset_is_ignored() {
		$zip      = $this->make_zip( self::plugin_entries() );
		$manifest = $this->make_source( array( self::asset( 'fresh.1.0.zip', $zip, array( 'state' => 'starter' ) ) ), $zip )->get_manifest( self::context() );

		$this->assertSame( WPCV_Error_Code::NO_RELEASE_ASSET, $manifest['error_code'] );
	}

	/**
	 * `ZipArchive` が無ければ(キャッシュも無いとき) HTTP なしで `ziparchive_missing`.
	 * キャッシュがあれば `ZipArchive` が無くても返す.
	 *
	 * @return void
	 */
	public function test_ziparchive_missing_only_matters_without_cache() {
		$cache  = new WPCV_Manifest_Cache_Repository( new WPCV_Test_Fake_WPDB() );
		$source = $this->make_source( array(), null, $cache, false );

		$this->assertSame( WPCV_Error_Code::ZIPARCHIVE_MISSING, $source->get_manifest( self::context() )['error_code'] );
		$this->assertSame( 0, $this->api_calls );

		$cache->save( WPCV_Manifest_Cache_Repository::SOURCE_GITHUB, 'lunaluna/fresh', '1.0', array( 'fresh.php' => array( 'sha256' => 'abc', 'md5' => 'def' ) ), 10 );

		$cached = $source->get_manifest( self::context() );

		$this->assertSame( 'cached', $cached['manifest_status'] );
		$this->assertSame( array( 'abc' ), $cached['files']['fresh.php']['hashes'] );
	}

	/**
	 * クライアントのエラー(tag が無い・レート制限・通信失敗)がそのまま返る.
	 *
	 * @return void
	 */
	public function test_client_errors_are_propagated() {
		$not_found = $this->make_source(
			array(),
			null,
			null,
			true,
			array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
				'headers'  => array(),
			)
		)->get_manifest( self::context() );

		$this->assertSame( WPCV_Error_Code::MANIFEST_NOT_FOUND, $not_found['error_code'] );

		$limited = $this->make_source(
			array(),
			null,
			null,
			true,
			array(
				'response' => array( 'code' => 429 ),
				'body'     => '',
				'headers'  => array( 'retry-after' => '30' ),
			)
		)->get_manifest( self::context() );

		$this->assertSame( WPCV_Error_Code::RATE_LIMITED, $limited['error_code'] );

		// 直前の `rate_limited` が site transient に残っているので消す.
		unset( $GLOBALS['_wpcv_test_site_transients'] );

		$failed = $this->make_source( array(), null, null, true, array( 'response' => array( 'code' => 500 ), 'body' => '', 'headers' => array() ) )->get_manifest( self::context() );

		$this->assertSame( WPCV_Error_Code::HTTP_ERROR, $failed['error_code'] );
	}

	/**
	 * 申告サイズが上限を超えるアセットは取得せず `archive_rejected`. フィルターで上限を変えられる.
	 *
	 * @return void
	 */
	public function test_oversized_asset_is_archive_rejected() {
		$zip = $this->make_zip( self::plugin_entries() );

		$GLOBALS['_wpcv_test_filters']['wpcv_github_zip_max_archive_bytes'][] = static function () {
			return 10;
		};

		$manifest = $this->make_source( array( self::asset( 'fresh.1.0.zip', $zip ) ), null )->get_manifest( self::context() );

		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, $manifest['error_code'] );
	}

	/**
	 * Zip でないファイルは `archive_invalid`、最上位が複数の zip は `archive_rejected`.
	 *
	 * @return void
	 */
	public function test_broken_or_unrooted_zip_is_rejected() {
		$broken = $this->work_dir . '/broken.zip';
		file_put_contents( $broken, 'not a zip' );

		$invalid = $this->make_source( array( self::asset( 'fresh.1.0.zip', $broken ) ), $broken )->get_manifest( self::context() );
		$this->assertSame( WPCV_Error_Code::ARCHIVE_INVALID, $invalid['error_code'] );

		$multi = $this->make_zip(
			array(
				'a/fresh.php' => "<?php\n/* Version: 1.0 */",
				'b/other.php' => '<?php',
			)
		);

		$rejected = $this->make_source( array( self::asset( 'fresh.1.0.zip', $multi ) ), $multi )->get_manifest( self::context() );
		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, $rejected['error_code'] );
	}

	/**
	 * 必須の slug・repo が無ければ例外(呼び出し側の実装ミス).
	 *
	 * @return void
	 */
	public function test_missing_required_context_throws() {
		$this->expectException( InvalidArgumentException::class );

		$this->make_source( array() )->get_manifest( array( 'slug' => 'fresh' ) );
	}
}
