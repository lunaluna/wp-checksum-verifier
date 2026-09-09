<?php
/**
 * WPCV_Rest_Token のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-wpcv-rest-token.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Rest_Token` のテスト.
 *
 * トークンのハッシュ生成・検証、`WPCV_REST_TOKEN` 定数による上書きの優先順位、
 * ヘッダー抽出の優先順位(`Authorization: Bearer` > `X-WPCV-Token`)、レート制限の
 * カウント増減を検証する.
 */
class RestTokenTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset(
			$GLOBALS['_wpcv_test_is_multisite'],
			$GLOBALS['_wpcv_test_options'],
			$GLOBALS['_wpcv_test_site_options'],
			$GLOBALS['_wpcv_test_update_option_calls'],
			$GLOBALS['_wpcv_test_transients']
		);
	}

	/**
	 * `generate()` が64文字の16進数文字列を返し、以後 `has_stored_token()` が
	 * 真になることを確認する.
	 *
	 * @return void
	 */
	public function test_generate_returns_hex_token_and_marks_as_stored() {
		$this->assertFalse( WPCV_Rest_Token::has_stored_token() );

		$token = WPCV_Rest_Token::generate();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
		$this->assertTrue( WPCV_Rest_Token::has_stored_token() );
	}

	/**
	 * `generate()` は autoload を無効化して保存することを確認する(単一サイト).
	 *
	 * @return void
	 */
	public function test_generate_stores_with_autoload_disabled_on_single_site() {
		$GLOBALS['_wpcv_test_is_multisite'] = false;

		WPCV_Rest_Token::generate();

		$this->assertCount( 1, $GLOBALS['_wpcv_test_update_option_calls'] );
		list( $name, $value, $autoload ) = $GLOBALS['_wpcv_test_update_option_calls'][0] + array( null, null, null );
		$this->assertSame( 'wpcv_rest_token_hash', $name );
		$this->assertIsString( $value );
		$this->assertFalse( $autoload );
	}

	/**
	 * 正しいトークンなら検証を通過することを確認する.
	 *
	 * @return void
	 */
	public function test_verify_against_succeeds_with_correct_token() {
		$token = WPCV_Rest_Token::generate();

		$this->assertTrue( WPCV_Rest_Token::verify_against( $token, null ) );
	}

	/**
	 * 誤ったトークン・空文字は検証に失敗することを確認する.
	 *
	 * @return void
	 */
	public function test_verify_against_fails_with_wrong_or_empty_token() {
		WPCV_Rest_Token::generate();

		$this->assertFalse( WPCV_Rest_Token::verify_against( 'wrong-token', null ) );
		$this->assertFalse( WPCV_Rest_Token::verify_against( '', null ) );
	}

	/**
	 * トークンが未発行なら、どんな値でも検証に失敗することを確認する.
	 *
	 * @return void
	 */
	public function test_verify_against_fails_when_no_token_issued() {
		$this->assertFalse( WPCV_Rest_Token::verify_against( 'anything', null ) );
	}

	/**
	 * `$token_override`(`WPCV_REST_TOKEN` 定数相当)が指定されている場合、
	 * 保存済みトークンより優先されることを確認する.
	 *
	 * @return void
	 */
	public function test_verify_against_prefers_override_over_stored_token() {
		$stored_token = WPCV_Rest_Token::generate();

		// override 側の値でリクエストが来れば通る.
		$this->assertTrue( WPCV_Rest_Token::verify_against( 'override-token', 'override-token' ) );

		// override が設定されている間は、保存済みトークンの値では通らない
		// (定数が「設定画面の値より優先」であることの確認).
		$this->assertFalse( WPCV_Rest_Token::verify_against( $stored_token, 'override-token' ) );
	}

	/**
	 * `Authorization: Bearer <token>` ヘッダーからトークンを抽出できることを確認する.
	 *
	 * @return void
	 */
	public function test_extract_from_request_reads_authorization_bearer_header() {
		$request = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer abc123' ) );

		$this->assertSame( 'abc123', WPCV_Rest_Token::extract_from_request( $request ) );
	}

	/**
	 * `Authorization` が無ければ `X-WPCV-Token` ヘッダーを見ることを確認する.
	 *
	 * @return void
	 */
	public function test_extract_from_request_falls_back_to_custom_header() {
		$request = new WPCV_Test_Fake_Rest_Request( array( 'X-WPCV-Token' => 'xyz789' ) );

		$this->assertSame( 'xyz789', WPCV_Rest_Token::extract_from_request( $request ) );
	}

	/**
	 * `Authorization: Bearer` が優先されることを確認する(両方指定時).
	 *
	 * @return void
	 */
	public function test_extract_from_request_prefers_authorization_over_custom_header() {
		$request = new WPCV_Test_Fake_Rest_Request(
			array(
				'Authorization' => 'Bearer bearer-token',
				'X-WPCV-Token'  => 'custom-header-token',
			)
		);

		$this->assertSame( 'bearer-token', WPCV_Rest_Token::extract_from_request( $request ) );
	}

	/**
	 * どちらのヘッダーも無ければ空文字を返し、`get_param()`(クエリパラメータ)は
	 * 一切呼ばれないことを確認する(§12.3: クエリパラメータでの指定は不可).
	 *
	 * @return void
	 */
	public function test_extract_from_request_returns_empty_string_without_reading_query_params() {
		$request = new WPCV_Test_Fake_Rest_Request();

		// WPCV_Test_Fake_Rest_Request::get_param() は呼ばれると例外を投げる実装
		// のため、例外が起きずここまで到達すれば「読んでいない」ことの証明になる.
		$this->assertSame( '', WPCV_Rest_Token::extract_from_request( $request ) );
	}

	/**
	 * `RATE_LIMIT_MAX_ATTEMPTS` 回失敗するとレート制限がかかることを確認する.
	 *
	 * @return void
	 */
	public function test_rate_limit_triggers_after_max_attempts() {
		$identifier = '203.0.113.1';

		for ( $i = 0; $i < WPCV_Rest_Token::RATE_LIMIT_MAX_ATTEMPTS - 1; $i++ ) {
			WPCV_Rest_Token::record_failed_attempt( $identifier );
			$this->assertFalse( WPCV_Rest_Token::is_rate_limited( $identifier ) );
		}

		WPCV_Rest_Token::record_failed_attempt( $identifier );

		$this->assertTrue( WPCV_Rest_Token::is_rate_limited( $identifier ) );
	}

	/**
	 * `clear_failed_attempts()` で失敗カウントがリセットされることを確認する.
	 *
	 * @return void
	 */
	public function test_clear_failed_attempts_resets_counter() {
		$identifier = '203.0.113.2';

		for ( $i = 0; $i < WPCV_Rest_Token::RATE_LIMIT_MAX_ATTEMPTS; $i++ ) {
			WPCV_Rest_Token::record_failed_attempt( $identifier );
		}
		$this->assertTrue( WPCV_Rest_Token::is_rate_limited( $identifier ) );

		WPCV_Rest_Token::clear_failed_attempts( $identifier );

		$this->assertFalse( WPCV_Rest_Token::is_rate_limited( $identifier ) );
	}

	/**
	 * 識別子が異なれば、レート制限のカウントは独立していることを確認する.
	 *
	 * @return void
	 */
	public function test_rate_limit_counters_are_independent_per_identifier() {
		for ( $i = 0; $i < WPCV_Rest_Token::RATE_LIMIT_MAX_ATTEMPTS; $i++ ) {
			WPCV_Rest_Token::record_failed_attempt( '203.0.113.3' );
		}

		$this->assertTrue( WPCV_Rest_Token::is_rate_limited( '203.0.113.3' ) );
		$this->assertFalse( WPCV_Rest_Token::is_rate_limited( '203.0.113.4' ) );
	}
}
