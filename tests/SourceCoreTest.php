<?php
/**
 * WPCV_Source_Core のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-source-core.php';

use PHPUnit\Framework\TestCase;

/**
 * §3.2: コア照合(locale フォールバック含む)のテスト.
 */
class SourceCoreTest extends TestCase {

	/**
	 * 各テストの前にスタブの状態をリセットする.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_core_checksums'], $GLOBALS['_wpcv_test_core_checksums_calls'], $GLOBALS['wp_local_package'] );
	}

	/**
	 * ja ロケールのマニフェストが取得できる場合、フォールバックせず ok を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_returns_ok_when_locale_manifest_available() {
		$GLOBALS['wp_local_package']            = 'ja';
		$GLOBALS['_wpcv_test_core_checksums']    = array(
			'ja' => array( 'wp-login.php' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ),
		);

		$source = new WPCV_Source_Core();
		$result = $source->get_manifest( array( 'version' => '6.8' ) );

		$this->assertSame( 'ok', $result['manifest_status'] );
		$this->assertNull( $result['error_code'] );
		$this->assertSame(
			array(
				'algorithm' => WPCV_File_Hasher::ALGO_MD5,
				'hashes'    => array( 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' ),
			),
			$result['files']['wp-login.php']
		);
		$this->assertSame( array( array( '6.8', 'ja' ) ), $GLOBALS['_wpcv_test_core_checksums_calls'] );
	}

	/**
	 * ja ロケールのマニフェストが無い場合、en_US にフォールバックし
	 * manifest_status = locale_fallback になることを確認する(§3.2).
	 *
	 * @return void
	 */
	public function test_falls_back_to_en_us_when_locale_manifest_missing() {
		$GLOBALS['wp_local_package']         = 'ja';
		$GLOBALS['_wpcv_test_core_checksums'] = array(
			'en_US' => array( 'wp-login.php' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' ),
		);

		$source = new WPCV_Source_Core();
		$result = $source->get_manifest( array( 'version' => '6.8' ) );

		$this->assertSame( 'locale_fallback', $result['manifest_status'] );
		$this->assertNull( $result['error_code'] );
		$this->assertArrayHasKey( 'wp-login.php', $result['files'] );
		// ja を先に試し、失敗したら en_US を試す順序であることを確認する.
		$this->assertSame(
			array( array( '6.8', 'ja' ), array( '6.8', 'en_US' ) ),
			$GLOBALS['_wpcv_test_core_checksums_calls']
		);
	}

	/**
	 * en_US もマニフェストが無い場合、manifest_status = missing と
	 * error_code = manifest_not_found を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_returns_missing_when_no_manifest_available() {
		$GLOBALS['wp_local_package'] = 'ja';
		// $GLOBALS['_wpcv_test_core_checksums'] を設定しない = 常に false.

		$source = new WPCV_Source_Core();
		$result = $source->get_manifest( array( 'version' => '6.8' ) );

		$this->assertSame( 'missing', $result['manifest_status'] );
		$this->assertSame( WPCV_Error_Code::MANIFEST_NOT_FOUND, $result['error_code'] );
		$this->assertSame( array(), $result['files'] );
	}

	/**
	 * wp_local_package が未設定(未定義)の場合は en_US を直接使い、
	 * フォールバックの二重取得が発生しないことを確認する.
	 *
	 * @return void
	 */
	public function test_uses_en_us_directly_when_wp_local_package_unset() {
		$GLOBALS['_wpcv_test_core_checksums'] = array(
			'en_US' => array( 'wp-login.php' => 'cccccccccccccccccccccccccccccccc' ),
		);

		$source = new WPCV_Source_Core();
		$result = $source->get_manifest( array( 'version' => '6.8' ) );

		$this->assertSame( 'ok', $result['manifest_status'] );
		$this->assertSame( array( array( '6.8', 'en_US' ) ), $GLOBALS['_wpcv_test_core_checksums_calls'] );
	}

	/**
	 * version が指定されていない場合に例外を投げることを確認する
	 * (checksum verifier はバージョンを推測しない。§3.4 と同じ原則).
	 *
	 * @return void
	 */
	public function test_throws_when_version_missing() {
		$this->expectException( InvalidArgumentException::class );

		$source = new WPCV_Source_Core();
		$source->get_manifest( array() );
	}
}
