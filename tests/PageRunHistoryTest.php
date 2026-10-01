<?php
/**
 * WPCV_Page_Run_History のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';
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

	/**
	 * `format_diff_summary()` が §1.2 の表の全行どおりに表示文字列を組み立てることを
	 * 確認する(v0.5後半 §16).
	 *
	 * @return void
	 */
	public function test_format_diff_summary_covers_all_table_rows() {
		// NULL(v4より前のrun・failed/abortedは本来skippedを書く設計だが実データではNULL).
		$this->assertSame( '—', WPCV_Page_Run_History::format_diff_summary( array( 'diff_status' => null ) ) );
		$this->assertSame( '—', WPCV_Page_Run_History::format_diff_summary( array() ) );

		// skipped.
		$this->assertSame( '—', WPCV_Page_Run_History::format_diff_summary( array( 'diff_status' => WPCV_Diff_Status::SKIPPED ) ) );

		// pending / processing はdiff_statusの値をそのまま.
		$this->assertSame( WPCV_Diff_Status::PENDING, WPCV_Page_Run_History::format_diff_summary( array( 'diff_status' => WPCV_Diff_Status::PENDING ) ) );
		$this->assertSame( WPCV_Diff_Status::PROCESSING, WPCV_Page_Run_History::format_diff_summary( array( 'diff_status' => WPCV_Diff_Status::PROCESSING ) ) );

		// alerting / done は件数の表記(+new / −resolved / =continuing).
		$run = array(
			'diff_status'         => WPCV_Diff_Status::ALERTING,
			'findings_new'        => 5,
			'findings_resolved'   => 2,
			'findings_continuing' => 1,
		);
		$this->assertSame( '+5 / −2 / =1', WPCV_Page_Run_History::format_diff_summary( $run ) );

		$run['diff_status'] = WPCV_Diff_Status::DONE;
		$this->assertSame( '+5 / −2 / =1', WPCV_Page_Run_History::format_diff_summary( $run ) );

		// alerting/doneでも件数がNULLなら0とせず「—」.
		$this->assertSame(
			'+— / −— / =—',
			WPCV_Page_Run_History::format_diff_summary(
				array(
					'diff_status'         => WPCV_Diff_Status::DONE,
					'findings_new'        => null,
					'findings_resolved'   => null,
					'findings_continuing' => null,
				)
			)
		);

		// failed.
		$this->assertSame( WPCV_Diff_Status::FAILED, WPCV_Page_Run_History::format_diff_summary( array( 'diff_status' => WPCV_Diff_Status::FAILED ) ) );

		// 上記以外(未知の値)はそのまま出す.
		$this->assertSame( 'some_future_status', WPCV_Page_Run_History::format_diff_summary( array( 'diff_status' => 'some_future_status' ) ) );
	}

	/**
	 * `format_alert_status()` が `alert_status` の値をそのまま返し、NULL・未設定は
	 * 「—」になることを確認する(v0.5後半 §16・§1.2).
	 *
	 * @return void
	 */
	public function test_format_alert_status() {
		$this->assertSame( 'sent', WPCV_Page_Run_History::format_alert_status( array( 'alert_status' => 'sent' ) ) );
		$this->assertSame( 'not_needed', WPCV_Page_Run_History::format_alert_status( array( 'alert_status' => 'not_needed' ) ) );
		$this->assertSame( 'no_recipient', WPCV_Page_Run_History::format_alert_status( array( 'alert_status' => 'no_recipient' ) ) );
		$this->assertSame( 'failed', WPCV_Page_Run_History::format_alert_status( array( 'alert_status' => 'failed' ) ) );
		$this->assertSame( '—', WPCV_Page_Run_History::format_alert_status( array( 'alert_status' => null ) ) );
		$this->assertSame( '—', WPCV_Page_Run_History::format_alert_status( array() ) );
	}

	/**
	 * `format_diff_counts()` が `diff_status` に関わらず3つの件数(NULLは「—」)を
	 * そのまま並べることを確認する(v0.5後半 §16・§1.3の実行履歴詳細行).
	 *
	 * @return void
	 */
	public function test_format_diff_counts() {
		$this->assertSame(
			'5 / 2 / 1',
			WPCV_Page_Run_History::format_diff_counts(
				array(
					'findings_new'        => 5,
					'findings_resolved'   => 2,
					'findings_continuing' => 1,
				)
			)
		);

		$this->assertSame( '— / — / —', WPCV_Page_Run_History::format_diff_counts( array() ) );
	}

	/**
	 * `format_update_event_created_by()` が `user_id=0`(cron・WP-CLI由来の自動更新)を
	 * 「User #0」ではなく人間向けの文言にすることを確認する(v0.6 §Step7)。
	 * 非0の場合は`WPCV_Page_Suppressions::format_created_by()`(`get_userdata()`
	 * に依存)に委譲するが、この関数は`tests/wp-stubs.php`にスタブが無いため
	 * このファイルの既定方針どおり単体テストの対象にしない(クラスdocblock参照).
	 *
	 * @return void
	 */
	public function test_format_update_event_created_by_labels_zero_as_automatic() {
		$this->assertSame( 'Automatic (cron/WP-CLI)', WPCV_Page_Run_History::format_update_event_created_by( 0 ) );
	}
}
