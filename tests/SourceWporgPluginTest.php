<?php
/**
 * WPCV_Source_Wporg_Plugin のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-source-wporg-plugin.php';

use PHPUnit\Framework\TestCase;

/**
 * §3.4: 公式プラグイン checksum 照合のテスト.
 */
class SourceWporgPluginTest extends TestCase {

	/**
	 * 各テストの前にスタブの状態をリセットする.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset(
			$GLOBALS['_wpcv_test_remote_get_calls'],
			$GLOBALS['_wpcv_test_remote_response'],
			$GLOBALS['_wpcv_test_remote_error']
		);
	}

	/**
	 * slug が指定されていない場合に例外を投げることを確認する
	 * (呼び出し側の実装ミス。slug はディレクトリ名から常に決定できる).
	 *
	 * @return void
	 */
	public function test_throws_when_slug_missing() {
		$this->expectException( InvalidArgumentException::class );

		$source = new WPCV_Source_Wporg_Plugin();
		$source->get_manifest( array( 'version' => '5.3' ) );
	}

	/**
	 * version が指定されていない場合、HTTP リクエストを行わずに
	 * manifest_status = missing / error_code = version_unknown を返すことを確認する
	 * (§3.4: checksum verifier はバージョンを推測しない).
	 *
	 * @return void
	 */
	public function test_returns_version_unknown_when_version_missing() {
		$source = new WPCV_Source_Wporg_Plugin();
		$result = $source->get_manifest( array( 'slug' => 'akismet' ) );

		$this->assertSame( 'missing', $result['manifest_status'] );
		$this->assertSame( WPCV_Error_Code::VERSION_UNKNOWN, $result['error_code'] );
		$this->assertSame( array(), $result['files'] );
		$this->assertArrayNotHasKey( '_wpcv_test_remote_get_calls', $GLOBALS );
	}

	/**
	 * URL が slug・version から正しく組み立てられることを確認する.
	 *
	 * @return void
	 */
	public function test_builds_expected_manifest_url() {
		$GLOBALS['_wpcv_test_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode(
				array(
					'files' => array(
						'akismet.php' => array( 'sha256' => str_repeat( 'a', 64 ) ),
					),
				)
			),
		);

		$source = new WPCV_Source_Wporg_Plugin();
		$source->get_manifest(
			array(
				'slug'    => 'akismet',
				'version' => '5.3',
			)
		);

		$this->assertSame(
			'https://downloads.wordpress.org/plugin-checksums/akismet/5.3.json',
			$GLOBALS['_wpcv_test_remote_get_calls'][0][0]
		);
	}

	/**
	 * wp_remote_get() が WP_Error を返す場合、error_code = http_error になることを確認する.
	 *
	 * @return void
	 */
	public function test_returns_http_error_on_wp_error() {
		$GLOBALS['_wpcv_test_remote_error'] = true;

		$source = new WPCV_Source_Wporg_Plugin();
		$result = $source->get_manifest(
			array(
				'slug'    => 'akismet',
				'version' => '5.3',
			)
		);

		$this->assertSame( 'missing', $result['manifest_status'] );
		$this->assertSame( WPCV_Error_Code::HTTP_ERROR, $result['error_code'] );
	}

	/**
	 * HTTP 404(未発行 / 存在しない slug・version)の場合、
	 * error_code = manifest_not_found になることを確認する(§8.6).
	 *
	 * @return void
	 */
	public function test_returns_manifest_not_found_on_404() {
		$GLOBALS['_wpcv_test_remote_response'] = array(
			'response' => array( 'code' => 404 ),
			'body'     => '',
		);

		$source = new WPCV_Source_Wporg_Plugin();
		$result = $source->get_manifest(
			array(
				'slug'    => 'no-such-plugin',
				'version' => '1.0',
			)
		);

		$this->assertSame( 'missing', $result['manifest_status'] );
		$this->assertSame( WPCV_Error_Code::MANIFEST_NOT_FOUND, $result['error_code'] );
	}

	/**
	 * 404 以外の非 200(例: 500)は error_code = http_error になることを確認する.
	 *
	 * @return void
	 */
	public function test_returns_http_error_on_non_200_non_404() {
		$GLOBALS['_wpcv_test_remote_response'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '',
		);

		$source = new WPCV_Source_Wporg_Plugin();
		$result = $source->get_manifest(
			array(
				'slug'    => 'akismet',
				'version' => '5.3',
			)
		);

		$this->assertSame( 'missing', $result['manifest_status'] );
		$this->assertSame( WPCV_Error_Code::HTTP_ERROR, $result['error_code'] );
	}

	/**
	 * 200 だが不正な JSON(files キーが無い)の場合、
	 * error_code = manifest_not_found になることを確認する(壊れたレスポンスを
	 * success として通さない).
	 *
	 * @return void
	 */
	public function test_returns_manifest_not_found_on_malformed_body() {
		$GLOBALS['_wpcv_test_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"plugin":"akismet"}',
		);

		$source = new WPCV_Source_Wporg_Plugin();
		$result = $source->get_manifest(
			array(
				'slug'    => 'akismet',
				'version' => '5.3',
			)
		);

		$this->assertSame( 'missing', $result['manifest_status'] );
		$this->assertSame( WPCV_Error_Code::MANIFEST_NOT_FOUND, $result['error_code'] );
	}

	/**
	 * sha256 が存在する場合は sha256 を優先して使うことを確認する(§3.4).
	 *
	 * @return void
	 */
	public function test_prefers_sha256_when_both_present() {
		$GLOBALS['_wpcv_test_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode(
				array(
					'files' => array(
						'akismet.php' => array(
							'md5'    => str_repeat( 'm', 32 ),
							'sha256' => str_repeat( 's', 64 ),
						),
					),
				)
			),
		);

		$source = new WPCV_Source_Wporg_Plugin();
		$result = $source->get_manifest(
			array(
				'slug'    => 'akismet',
				'version' => '5.3',
			)
		);

		$this->assertSame( 'ok', $result['manifest_status'] );
		$this->assertNull( $result['error_code'] );
		$this->assertSame(
			array(
				'algorithm' => WPCV_File_Hasher::ALGO_SHA256,
				'hashes'    => array( str_repeat( 's', 64 ) ),
			),
			$result['files']['akismet.php']
		);
	}

	/**
	 * sha256 が無い場合のみ md5 にフォールバックすることを確認する(§3.4).
	 *
	 * @return void
	 */
	public function test_falls_back_to_md5_when_sha256_absent() {
		$GLOBALS['_wpcv_test_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode(
				array(
					'files' => array(
						'readme.txt' => array( 'md5' => str_repeat( 'm', 32 ) ),
					),
				)
			),
		);

		$source = new WPCV_Source_Wporg_Plugin();
		$result = $source->get_manifest(
			array(
				'slug'    => 'akismet',
				'version' => '5.3',
			)
		);

		$this->assertSame(
			array(
				'algorithm' => WPCV_File_Hasher::ALGO_MD5,
				'hashes'    => array( str_repeat( 'm', 32 ) ),
			),
			$result['files']['readme.txt']
		);
	}

	/**
	 * ハッシュ値が配列(複数候補)の場合でもそのまま保持することを確認する
	 * (§3.4: 値は文字列と配列の両方がありうる).
	 *
	 * @return void
	 */
	public function test_keeps_multiple_hash_candidates_as_array() {
		$GLOBALS['_wpcv_test_remote_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => json_encode(
				array(
					'files' => array(
						'akismet.php' => array(
							'sha256' => array( str_repeat( '1', 64 ), str_repeat( '2', 64 ) ),
						),
					),
				)
			),
		);

		$source = new WPCV_Source_Wporg_Plugin();
		$result = $source->get_manifest(
			array(
				'slug'    => 'akismet',
				'version' => '5.3',
			)
		);

		$this->assertSame(
			array( str_repeat( '1', 64 ), str_repeat( '2', 64 ) ),
			$result['files']['akismet.php']['hashes']
		);
	}
}
