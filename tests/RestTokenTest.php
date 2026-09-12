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
	 * `Bearer` scheme は大文字小文字を区別しないことを確認する(v0.3.1 §Step5。
	 * `extract_from_request()` は `stripos()` で判定しており既に大文字小文字
	 * 非依存だったが、明示的なテストが無かったため回帰防止として追加する).
	 *
	 * @return void
	 */
	public function test_extract_from_request_bearer_scheme_is_case_insensitive() {
		$request = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'bearer abc123' ) );

		$this->assertSame( 'abc123', WPCV_Rest_Token::extract_from_request( $request ) );

		$request_mixed_case = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'BeArEr abc123' ) );

		$this->assertSame( 'abc123', WPCV_Rest_Token::extract_from_request( $request_mixed_case ) );
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

	/**
	 * 識別子が空文字の場合、何度失敗を記録しても `is_rate_limited('')` は常に
	 * false を返すことを確認する(v0.3.1 §Step5。プラン§P1「`REMOTE_ADDR` が
	 * 空の場合に全呼び出し元が同じrate-limit bucketへ入らない」への対策。
	 * `REMOTE_ADDR` を取得できない複数の呼び出し元が同じ空文字バケツを共有して
	 * 巻き添えでロックアウトされる事態を、識別子が無い場合はそもそもレート制限を
	 * 適用しないことで防ぐ).
	 *
	 * @return void
	 */
	public function test_rate_limit_is_never_applied_for_empty_identifier() {
		for ( $i = 0; $i < WPCV_Rest_Token::RATE_LIMIT_MAX_ATTEMPTS + 5; $i++ ) {
			WPCV_Rest_Token::record_failed_attempt( '' );
		}

		$this->assertFalse( WPCV_Rest_Token::is_rate_limited( '' ) );
	}

	/**
	 * `clear_failed_attempts('')` が例外を投げず何もしないことを確認する
	 * (空文字ガードの網羅性確認).
	 *
	 * @return void
	 */
	public function test_clear_failed_attempts_is_noop_for_empty_identifier() {
		WPCV_Rest_Token::clear_failed_attempts( '' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * `SCOPE_RUN`(既定)と `SCOPE_READ` のトークンが別々に保存され、互いの検証に
	 * 通らないことを確認する(v0.4.0 §Step7: run/read scope分離).
	 *
	 * @return void
	 */
	public function test_run_and_read_scope_tokens_are_independent() {
		$run_token  = WPCV_Rest_Token::generate( WPCV_Rest_Token::SCOPE_RUN );
		$read_token = WPCV_Rest_Token::generate( WPCV_Rest_Token::SCOPE_READ );

		$this->assertTrue( WPCV_Rest_Token::verify_against( $run_token, null, WPCV_Rest_Token::SCOPE_RUN ) );
		$this->assertTrue( WPCV_Rest_Token::verify_against( $read_token, null, WPCV_Rest_Token::SCOPE_READ ) );

		// run scopeのトークンではread scopeを、逆もまた通らない.
		$this->assertFalse( WPCV_Rest_Token::verify_against( $run_token, null, WPCV_Rest_Token::SCOPE_READ ) );
		$this->assertFalse( WPCV_Rest_Token::verify_against( $read_token, null, WPCV_Rest_Token::SCOPE_RUN ) );
	}

	/**
	 * `has_stored_token()` がscopeごとに独立して発行状況を報告することを確認する.
	 *
	 * @return void
	 */
	public function test_has_stored_token_is_independent_per_scope() {
		$this->assertFalse( WPCV_Rest_Token::has_stored_token( WPCV_Rest_Token::SCOPE_RUN ) );
		$this->assertFalse( WPCV_Rest_Token::has_stored_token( WPCV_Rest_Token::SCOPE_READ ) );

		WPCV_Rest_Token::generate( WPCV_Rest_Token::SCOPE_READ );

		$this->assertFalse( WPCV_Rest_Token::has_stored_token( WPCV_Rest_Token::SCOPE_RUN ) );
		$this->assertTrue( WPCV_Rest_Token::has_stored_token( WPCV_Rest_Token::SCOPE_READ ) );
	}

	/**
	 * `WPCV_REST_TOKEN` 定数(相当の override)は `SCOPE_RUN` にのみ適用され、
	 * `SCOPE_READ` の検証には影響しないことを確認する(クラス docblock
	 * 「定数はSCOPE_RUNのみに適用する」の確認. 定数自体はPHPの言語仕様上
	 * 未定義に戻せないため、`verify_against()` の `$token_override` 引数で
	 * 定数相当の値を模す).
	 *
	 * @return void
	 */
	public function test_token_override_does_not_apply_to_read_scope() {
		$this->assertFalse( WPCV_Rest_Token::verify_against( 'override-token', 'override-token', WPCV_Rest_Token::SCOPE_READ ) );
	}

	/**
	 * `check_permission()` が正しいトークン(該当scope)なら `true` を返すことを確認する
	 * (v0.4.0 §Step7: 3コントローラーで共有するパーミッションチェックの確認).
	 *
	 * @return void
	 */
	public function test_check_permission_allows_correct_token_for_scope() {
		$token   = WPCV_Rest_Token::generate( WPCV_Rest_Token::SCOPE_READ );
		$request = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer ' . $token ) );

		$this->assertTrue( WPCV_Rest_Token::check_permission( $request, WPCV_Rest_Token::SCOPE_READ ) );
	}

	/**
	 * `check_permission()` が、scopeの異なる(=無効な)トークンを `WP_Error`(401)で
	 * 拒否することを確認する.
	 *
	 * @return void
	 */
	public function test_check_permission_rejects_token_for_wrong_scope() {
		$run_token = WPCV_Rest_Token::generate( WPCV_Rest_Token::SCOPE_RUN );
		$request   = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer ' . $run_token ) );

		$result = WPCV_Rest_Token::check_permission( $request, WPCV_Rest_Token::SCOPE_READ );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * `check_permission()` が失敗回数の上限に達すると `429` で拒否することを確認する
	 * (`WPCV_Rest_Run_Controller::check_permission()` から移植した既存テストの
	 * 対象を共通実装へ差し替えたもの).
	 *
	 * @return void
	 */
	public function test_check_permission_returns_429_after_rate_limit_exceeded() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		$token           = WPCV_Rest_Token::generate( WPCV_Rest_Token::SCOPE_READ );
		$wrong_request   = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer wrong-token' ) );
		$correct_request = new WPCV_Test_Fake_Rest_Request( array( 'Authorization' => 'Bearer ' . $token ) );

		for ( $i = 0; $i < WPCV_Rest_Token::RATE_LIMIT_MAX_ATTEMPTS; $i++ ) {
			WPCV_Rest_Token::check_permission( $wrong_request, WPCV_Rest_Token::SCOPE_READ );
		}

		$result = WPCV_Rest_Token::check_permission( $correct_request, WPCV_Rest_Token::SCOPE_READ );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 429, $result->get_error_data()['status'] );

		unset( $_SERVER['REMOTE_ADDR'] );
	}
}
