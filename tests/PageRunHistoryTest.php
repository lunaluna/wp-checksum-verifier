<?php
/**
 * WPCV_Page_Run_History のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-run-history.php';

use PHPUnit\Framework\TestCase;

/**
 * `render()`/`render_list()`/`render_detail()` は `menu_page_url()`・`add_query_arg()`
 * 等、`tests/wp-stubs.php` に無い WP コア関数へ依存するため単体テストの対象にしない
 * (`WPCV_Page_Settings` と同じ方針)。レンダリングから分離した純粋ロジック
 * (`target_run_reason_label()`/`current_page_from_request()`/`total_pages()`)だけを
 * 単体テストする.
 */
class PageRunHistoryTest extends TestCase {

	/**
	 * `target_run_reason_label()` が既知の error_code を人間可読な説明文へ変換
	 * することを確認する.
	 *
	 * @return void
	 */
	public function test_target_run_reason_label_returns_description_for_known_error_code() {
		$label = WPCV_Page_Run_History::target_run_reason_label( array( 'error_code' => WPCV_Error_Code::TARGET_MISSING ) );

		$this->assertSame( WPCV_Error_Code::all()[ WPCV_Error_Code::TARGET_MISSING ], $label );
	}

	/**
	 * `target_run_reason_label()` が未知の error_code をそのまま返すことを確認する
	 * (将来追加されたerror_codeの表示が空欄にならないための防御).
	 *
	 * @return void
	 */
	public function test_target_run_reason_label_returns_raw_code_for_unknown_error_code() {
		$label = WPCV_Page_Run_History::target_run_reason_label( array( 'error_code' => 'not_a_real_code' ) );

		$this->assertSame( 'not_a_real_code', $label );
	}

	/**
	 * `target_run_reason_label()` が error_code 未設定(success等)なら空文字を
	 * 返すことを確認する.
	 *
	 * @return void
	 */
	public function test_target_run_reason_label_returns_empty_string_when_no_error_code() {
		$this->assertSame( '', WPCV_Page_Run_History::target_run_reason_label( array( 'error_code' => null ) ) );
		$this->assertSame( '', WPCV_Page_Run_History::target_run_reason_label( array() ) );
	}

	/**
	 * `current_page_from_request()` が未指定( null )のとき1を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_current_page_from_request_defaults_to_one_when_absent() {
		$this->assertSame( 1, WPCV_Page_Run_History::current_page_from_request( null ) );
	}

	/**
	 * `current_page_from_request()` が有効な数値をそのまま整数化して返すことを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_current_page_from_request_parses_valid_page_number() {
		$this->assertSame( 3, WPCV_Page_Run_History::current_page_from_request( '3' ) );
	}

	/**
	 * `current_page_from_request()` が0・数値化できない値を1にclampすることを
	 * 確認する(負の値は `absint()` の仕様どおり絶対値になる。1未満へのclampは
	 * `absint()` が返し得る0のみを対象とする).
	 *
	 * @return void
	 */
	public function test_current_page_from_request_clamps_invalid_values_to_one() {
		$this->assertSame( 1, WPCV_Page_Run_History::current_page_from_request( '0' ) );
		$this->assertSame( 1, WPCV_Page_Run_History::current_page_from_request( 'not-a-number' ) );
	}

	/**
	 * `total_pages()` が全件数をper_pageで割った切り上げを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_total_pages_rounds_up() {
		$this->assertSame( 3, WPCV_Page_Run_History::total_pages( 41, 20 ) );
		$this->assertSame( 2, WPCV_Page_Run_History::total_pages( 40, 20 ) );
	}

	/**
	 * `total_pages()` が全件数0でも最低1ページを返すことを確認する
	 * (「0ページ」を表示させないための下限).
	 *
	 * @return void
	 */
	public function test_total_pages_returns_at_least_one() {
		$this->assertSame( 1, WPCV_Page_Run_History::total_pages( 0, 20 ) );
	}
}
