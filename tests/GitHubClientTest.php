<?php
/**
 * WPCV_GitHub_Client のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-github-client.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_GitHub_Client`(v0.8 §Step4. D2・D8・D9)のテスト.
 *
 * HTTP は注入した callable で固定の応答を返し、GitHub には接続しない.
 * トークンは定数 `WPCV_GITHUB_TOKEN` を1回だけ定義できないため(テストプロセス全体で
 * 残る)、フィルター `wpcv_github_token` で与える.
 */
class GitHubClientTest extends TestCase {

	/**
	 * HTTP 呼び出しの記録.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private $calls = array();

	/**
	 * テストごとの状態を消す.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calls = array();
		unset( $GLOBALS['_wpcv_test_site_transients'], $GLOBALS['_wpcv_test_filters'] );
	}

	/**
	 * テストごとの状態を消す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_wpcv_test_site_transients'], $GLOBALS['_wpcv_test_filters'] );
		parent::tearDown();
	}

	/**
	 * 応答を順番に返す HTTP callable を作る(呼び出しは `$this->calls` に記録).
	 *
	 * @param array $responses 応答(`array|WP_Error`)を呼ばれる順に.
	 * @return callable
	 */
	private function http( array $responses ) {
		return function ( $url, $args ) use ( &$responses ) {
			$this->calls[] = array( $url, $args );

			return array_shift( $responses );
		};
	}

	/**
	 * 応答の配列を作る.
	 *
	 * @param int          $code    ステータスコード.
	 * @param array|string $body    本文(配列なら JSON にする).
	 * @param array        $headers ヘッダー(キーは小文字).
	 * @return array
	 */
	private static function response( $code, $body = '', array $headers = array() ) {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => is_array( $body ) ? wp_json_encode( $body ) : $body,
			'headers'  => $headers,
		);
	}

	/**
	 * クライアントを作る.
	 *
	 * @param array         $responses 応答.
	 * @param int           $now       現在時刻.
	 * @param callable|null $downloader 公開アセットの取得.
	 * @return WPCV_GitHub_Client
	 */
	private function client( array $responses, $now = 1000000, ?callable $downloader = null ) {
		return new WPCV_GitHub_Client(
			$this->http( $responses ),
			$downloader,
			static function () use ( $now ) {
				return $now;
			}
		);
	}

	/**
	 * トークンをフィルターで与える.
	 *
	 * @param string $token トークン.
	 * @return void
	 */
	private function with_token( $token ) {
		$GLOBALS['_wpcv_test_filters']['wpcv_github_token'][] = static function () use ( $token ) {
			return $token;
		};
	}

	/**
	 * `{version}` の tag で 200 なら、1回の GET で Release が返る.
	 *
	 * @return void
	 */
	public function test_finds_release_with_plain_version_tag() {
		$client = $this->client( array( self::response( 200, array( 'tag_name' => '1.9.2', 'assets' => array() ) ) ) );

		$result = $client->find_release_by_version( 'lunaluna/forced-auto-update-controller', '1.9.2' );

		$this->assertNull( $result['error_code'] );
		$this->assertSame( '1.9.2', $result['release']['tag_name'] );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( 'https://api.github.com/repos/lunaluna/forced-auto-update-controller/releases/tags/1.9.2', $this->calls[0][0] );
	}

	/**
	 * 最初の tag が 404 のときだけ `v{version}` を試す(D2).
	 *
	 * @return void
	 */
	public function test_falls_back_to_v_prefixed_tag_on_404() {
		$client = $this->client(
			array(
				self::response( 404, array( 'message' => 'Not Found' ) ),
				self::response( 200, array( 'tag_name' => 'v0.7.0' ) ),
			)
		);

		$result = $client->find_release_by_version( 'lunaluna/wp-checksum-verifier', '0.7.0' );

		$this->assertSame( 'v0.7.0', $result['release']['tag_name'] );
		$this->assertCount( 2, $this->calls );
		$this->assertStringEndsWith( '/releases/tags/v0.7.0', $this->calls[1][0] );
	}

	/**
	 * どの tag も 404 なら `manifest_not_found`.
	 *
	 * @return void
	 */
	public function test_manifest_not_found_when_all_candidates_are_404() {
		$client = $this->client( array( self::response( 404 ), self::response( 404 ) ) );

		$result = $client->find_release_by_version( 'lunaluna/x', '1.0' );

		$this->assertNull( $result['release'] );
		$this->assertSame( WPCV_Error_Code::MANIFEST_NOT_FOUND, $result['error_code'] );
		$this->assertCount( 2, $this->calls );
	}

	/**
	 * Tag の候補はフィルターで変えられる.
	 *
	 * @return void
	 */
	public function test_tag_candidates_filter_is_applied() {
		$GLOBALS['_wpcv_test_filters']['wpcv_github_tag_candidates'][] = static function ( $candidates, $repo, $version ) {
			unset( $candidates, $repo );

			return array( 'release-' . $version );
		};

		$client = $this->client( array( self::response( 200, array( 'tag_name' => 'release-2.0' ) ) ) );
		$client->find_release_by_version( 'lunaluna/x', '2.0' );

		$this->assertCount( 1, $this->calls );
		$this->assertStringEndsWith( '/releases/tags/release-2.0', $this->calls[0][0] );
	}

	/**
	 * 通信の失敗・想定外のステータス・壊れた JSON は `http_error`(次の tag は試さない).
	 *
	 * @return void
	 */
	public function test_http_failures_are_http_error() {
		$cases = array(
			'wp_error'    => new WP_Error(),
			'server'      => self::response( 500 ),
			'forbidden'   => self::response( 403, array( 'message' => 'Resource not accessible' ) ),
			'bad_token'   => self::response( 401 ),
			'broken_json' => self::response( 200, 'not json' ),
			'no_tag_name' => self::response( 200, array( 'id' => 1 ) ),
		);

		foreach ( $cases as $name => $response ) {
			$this->calls = array();
			$result      = $this->client( array( $response, self::response( 200, array( 'tag_name' => 'x' ) ) ) )->find_release_by_version( 'lunaluna/x', '1.0' );

			$this->assertSame( WPCV_Error_Code::HTTP_ERROR, $result['error_code'], $name );
			$this->assertCount( 1, $this->calls, $name );
		}
	}

	/**
	 * `owner/repo` の形が不正なら HTTP を出さず `unknown_source`.
	 *
	 * @return void
	 */
	public function test_invalid_repo_is_rejected_without_http() {
		foreach ( array( '', 'noslash', 'a/b/c', 'a/b c', '../x' ) as $repo ) {
			$result = $this->client( array() )->find_release_by_version( $repo, '1.0' );

			$this->assertSame( WPCV_Error_Code::UNKNOWN_SOURCE, $result['error_code'], $repo );
		}

		$this->assertCount( 0, $this->calls );
	}

	/**
	 * 403 + `x-ratelimit-remaining: 0` は `rate_limited`. 解除は `x-ratelimit-reset` の時刻(D9).
	 *
	 * @return void
	 */
	public function test_primary_rate_limit_uses_reset_header() {
		$client = $this->client(
			array( self::response( 403, array( 'message' => 'rate limit exceeded' ), array( 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1000600' ) ) )
		);

		$result = $client->find_release_by_version( 'lunaluna/x', '1.0' );

		$this->assertSame( WPCV_Error_Code::RATE_LIMITED, $result['error_code'] );
		$this->assertSame( 1000600, $client->get_rate_limited_until() );
		$this->assertSame( 600, $GLOBALS['_wpcv_test_site_transients'][ WPCV_GitHub_Client::RATE_LIMIT_TRANSIENT ]['expiration'] );
	}

	/**
	 * 429 + `retry-after` は、その秒数のあいだ `rate_limited`(`x-ratelimit-reset` より優先).
	 *
	 * @return void
	 */
	public function test_retry_after_takes_priority() {
		$client = $this->client(
			array( self::response( 429, '', array( 'retry-after' => '120', 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1009999' ) ) )
		);

		$result = $client->find_release_by_version( 'lunaluna/x', '1.0' );

		$this->assertSame( WPCV_Error_Code::RATE_LIMITED, $result['error_code'] );
		$this->assertSame( 1000120, $client->get_rate_limited_until() );
	}

	/**
	 * 403 でも、残りが0でも `retry-after` も無ければ制限ではない(権限の無い 403 など).
	 *
	 * @return void
	 */
	public function test_403_without_rate_limit_headers_is_not_rate_limited() {
		$client = $this->client( array( self::response( 403, '', array( 'x-ratelimit-remaining' => '42' ) ) ) );

		$result = $client->find_release_by_version( 'lunaluna/x', '1.0' );

		$this->assertSame( WPCV_Error_Code::HTTP_ERROR, $result['error_code'] );
		$this->assertNull( $client->get_rate_limited_until() );
	}

	/**
	 * 解除の時刻が応答から分からない制限は 60 秒(公式ドキュメントの「少なくとも1分」).
	 *
	 * @return void
	 */
	public function test_rate_limit_without_reset_uses_fallback_seconds() {
		$client = $this->client( array( self::response( 429, '', array( 'retry-after' => 'soon' ) ), self::response( 403, '', array( 'x-ratelimit-remaining' => '0' ) ) ) );

		// `retry-after` が数字でなく、残りの記載も無い → 制限とみなさない.
		$this->assertSame( WPCV_Error_Code::HTTP_ERROR, $client->find_release_by_version( 'lunaluna/x', '1.0' )['error_code'] );

		// 残りが0で reset も無い → 60 秒.
		$this->assertSame( WPCV_Error_Code::RATE_LIMITED, $client->find_release_by_version( 'lunaluna/x', '1.0' )['error_code'] );
		$this->assertSame( 1000000 + WPCV_GitHub_Client::RATE_LIMIT_FALLBACK_SECONDS, $client->get_rate_limited_until() );
	}

	/**
	 * 制限中は HTTP を出さずに `rate_limited`. 解除の時刻を過ぎれば、また出す(D9).
	 *
	 * @return void
	 */
	public function test_no_http_while_rate_limited_and_resumes_after_reset() {
		$GLOBALS['_wpcv_test_site_transients'][ WPCV_GitHub_Client::RATE_LIMIT_TRANSIENT ] = array(
			'value'      => 1000100,
			'expiration' => 100,
		);

		$during = $this->client( array( self::response( 200, array( 'tag_name' => '1.0' ) ) ), 1000050 );
		$this->assertSame( WPCV_Error_Code::RATE_LIMITED, $during->find_release_by_version( 'lunaluna/x', '1.0' )['error_code'] );
		$this->assertCount( 0, $this->calls );

		$after = $this->client( array( self::response( 200, array( 'tag_name' => '1.0' ) ) ), 1000101 );
		$this->assertNull( $after->find_release_by_version( 'lunaluna/x', '1.0' )['error_code'] );
		$this->assertCount( 1, $this->calls );
	}

	/**
	 * ヘッダー: 標準のヘッダーを付ける. トークンが無ければ Authorization は付かない.
	 *
	 * @return void
	 */
	public function test_request_headers_without_token() {
		$this->client( array( self::response( 200, array( 'tag_name' => '1.0' ) ) ) )->find_release_by_version( 'lunaluna/x', '1.0' );

		$headers = $this->calls[0][1]['headers'];

		$this->assertSame( 'application/vnd.github+json', $headers['Accept'] );
		$this->assertSame( '2022-11-28', $headers['X-GitHub-Api-Version'] );
		$this->assertStringStartsWith( 'wp-checksum-verifier/', $headers['User-Agent'] );
		$this->assertArrayNotHasKey( 'Authorization', $headers );
		$this->assertSame( WPCV_GitHub_Client::DEFAULT_API_TIMEOUT, $this->calls[0][1]['timeout'] );
		$this->assertFalse( WPCV_GitHub_Client::has_token() );
	}

	/**
	 * トークンがあれば `Authorization: Bearer` を付け、`has_token()` が真になる.
	 *
	 * @return void
	 */
	public function test_request_headers_with_token() {
		$this->with_token( 'ghp_secret_value' );

		$this->client( array( self::response( 200, array( 'tag_name' => '1.0' ) ) ) )->find_release_by_version( 'lunaluna/x', '1.0' );

		$this->assertSame( 'Bearer ghp_secret_value', $this->calls[0][1]['headers']['Authorization'] );
		$this->assertTrue( WPCV_GitHub_Client::has_token() );
	}

	/**
	 * トークンは戻り値のどこにも出ない(エラー時も).
	 *
	 * @return void
	 */
	public function test_token_never_appears_in_results() {
		$this->with_token( 'ghp_secret_value' );

		$cases = array(
			self::response( 401, array( 'message' => 'Bad credentials ghp_secret_value' ) ),
			new WP_Error(),
			self::response( 403, '', array( 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1000600' ) ),
		);

		foreach ( $cases as $response ) {
			$result = $this->client( array( $response ) )->find_release_by_version( 'lunaluna/x', '1.0' );

			$this->assertStringNotContainsString( 'ghp_secret_value', wp_json_encode( $result ) );
		}
	}

	/**
	 * トークンなしの公開アセットは `browser_download_url` を `download_url()` で取得する(API を使わない).
	 *
	 * @return void
	 */
	public function test_public_asset_uses_browser_download_url() {
		$downloaded = array();
		$client     = $this->client(
			array(),
			1000000,
			static function ( $url, $timeout ) use ( &$downloaded ) {
				$downloaded[] = array( $url, $timeout );

				return '/tmp/wpcv-fake-asset.zip';
			}
		);

		$result = $client->download_asset(
			'lunaluna/x',
			array(
				'url'                  => 'https://api.github.com/repos/lunaluna/x/releases/assets/1',
				'browser_download_url' => 'https://github.com/lunaluna/x/releases/download/1.0/x.1.0.zip',
				'size'                 => 1000,
			),
			1000000
		);

		$this->assertSame( '/tmp/wpcv-fake-asset.zip', $result['path'] );
		$this->assertNull( $result['error_code'] );
		$this->assertCount( 0, $this->calls );
		$this->assertSame( array( array( 'https://github.com/lunaluna/x/releases/download/1.0/x.1.0.zip', WPCV_GitHub_Client::DEFAULT_DOWNLOAD_TIMEOUT ) ), $downloaded );
	}

	/**
	 * 公開アセットの取得に失敗したら `http_error`.
	 *
	 * @return void
	 */
	public function test_public_asset_download_failure_is_http_error() {
		$client = $this->client(
			array(),
			1000000,
			static function () {
				return new WP_Error();
			}
		);

		$result = $client->download_asset( 'lunaluna/x', array( 'browser_download_url' => 'https://github.com/x.zip' ), 1000000 );

		$this->assertNull( $result['path'] );
		$this->assertSame( WPCV_Error_Code::HTTP_ERROR, $result['error_code'] );
	}

	/**
	 * 申告サイズが上限を超えるアセットは、HTTP を出さずに `archive_rejected`.
	 *
	 * @return void
	 */
	public function test_oversized_asset_is_rejected_without_http() {
		$result = $this->client( array() )->download_asset(
			'lunaluna/x',
			array(
				'browser_download_url' => 'https://github.com/x.zip',
				'size'                 => 2000,
			),
			1000
		);

		$this->assertSame( WPCV_Error_Code::ARCHIVE_REJECTED, $result['error_code'] );
		$this->assertCount( 0, $this->calls );
	}

	/**
	 * トークンありは Assets API(`Accept: application/octet-stream`・Bearer・stream)で取得する(D8).
	 *
	 * @return void
	 */
	public function test_asset_with_token_uses_assets_api() {
		$this->with_token( 'ghp_secret_value' );

		$client = $this->client( array( self::response( 200, '' ) ) );

		$result = $client->download_asset(
			'lunaluna/private-repo',
			array(
				'url'                  => 'https://api.github.com/repos/lunaluna/private-repo/releases/assets/9',
				'browser_download_url' => 'https://github.com/lunaluna/private-repo/releases/download/1.0/x.zip',
				'size'                 => 500,
			),
			1000
		);

		$this->assertNull( $result['error_code'] );
		$this->assertNotEmpty( $result['path'] );

		$args = $this->calls[0][1];

		$this->assertSame( 'https://api.github.com/repos/lunaluna/private-repo/releases/assets/9', $this->calls[0][0] );
		$this->assertSame( 'application/octet-stream', $args['headers']['Accept'] );
		$this->assertSame( 'Bearer ghp_secret_value', $args['headers']['Authorization'] );
		$this->assertTrue( $args['stream'] );
		$this->assertSame( $result['path'], $args['filename'] );
		$this->assertSame( 1001, $args['limit_response_size'] );

		unlink( $result['path'] );
	}

	/**
	 * Assets API の失敗は一時ファイルを残さず、制限なら `rate_limited` を記録する.
	 *
	 * @return void
	 */
	public function test_asset_with_token_failure_removes_temp_file_and_records_rate_limit() {
		$this->with_token( 'ghp_secret_value' );

		$client = $this->client( array( self::response( 429, '', array( 'retry-after' => '30' ) ) ) );

		$result = $client->download_asset( 'lunaluna/x', array( 'url' => 'https://api.github.com/repos/lunaluna/x/releases/assets/1' ), 1000 );

		$this->assertNull( $result['path'] );
		$this->assertSame( WPCV_Error_Code::RATE_LIMITED, $result['error_code'] );
		$this->assertFalse( file_exists( $this->calls[0][1]['filename'] ) );
		$this->assertSame( 1000030, $client->get_rate_limited_until() );

		// 制限中のトークンあり取得は HTTP を出さない.
		$again = $client->download_asset( 'lunaluna/x', array( 'url' => 'https://api.github.com/repos/lunaluna/x/releases/assets/1' ), 1000 );

		$this->assertSame( WPCV_Error_Code::RATE_LIMITED, $again['error_code'] );
		$this->assertCount( 1, $this->calls );
	}

	/**
	 * トークンなしの公開アセットの取得は、API の回数を使わないので制限中でも行う.
	 *
	 * @return void
	 */
	public function test_public_asset_download_still_runs_while_rate_limited() {
		$GLOBALS['_wpcv_test_site_transients'][ WPCV_GitHub_Client::RATE_LIMIT_TRANSIENT ] = array(
			'value'      => 1000100,
			'expiration' => 100,
		);

		$client = $this->client(
			array(),
			1000050,
			static function () {
				return '/tmp/wpcv-fake-asset.zip';
			}
		);

		$result = $client->download_asset( 'lunaluna/x', array( 'browser_download_url' => 'https://github.com/x.zip' ), 1000 );

		$this->assertSame( '/tmp/wpcv-fake-asset.zip', $result['path'] );
	}
}
