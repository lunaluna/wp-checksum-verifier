<?php
/**
 * WPCV_Alert_Composer のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-alert-composer.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Alert_Composer::compose()` のテスト(v0.5後半 §Step13・§4.1・§6).
 */
class AlertComposerTest extends TestCase {

	/**
	 * このテストが登録したフィルターを消す(他のテストへ漏らさないため.
	 * `_wpcv_test_filters`全体ではなく、自分が使うタグだけを消す).
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_wpcv_test_filters']['wpcv_alert_max_items'] );
		parent::tearDown();
	}

	/**
	 * 本文から、指定した見出しの次の行から空行の手前までを取り出す.
	 *
	 * @param string $body    本文.
	 * @param string $heading 見出しの行(完全一致).
	 * @return string[] 見出しが無ければ空配列.
	 */
	private function section_lines( $body, $heading ) {
		$lines = explode( "\n", $body );
		$start = array_search( $heading, $lines, true );

		if ( false === $start ) {
			return array();
		}

		$section = array();

		for ( $i = $start + 1; $i < count( $lines ) && '' !== $lines[ $i ]; $i++ ) {
			$section[] = $lines[ $i ];
		}

		return $section;
	}

	/**
	 * 未知ファイル 1,235 件の入力で、上位一覧は20件だけ載り、規模は
	 * 「By target」の1行にまとまることを確認する(プラン Step13 の完了条件).
	 *
	 * @return void
	 */
	public function test_large_input_is_capped_to_top_20_with_one_summary_line() {
		$items = array();

		for ( $i = 1; $i <= 1235; $i++ ) {
			$items[] = array(
				'severity'  => 'medium',
				'target_id' => 'core:_scan',
				'path'      => sprintf( 'unknown-%04d.php', $i ),
				'status'    => 'added',
			);
		}

		$result = WPCV_Alert_Composer::compose(
			array(
				'counts'    => array( 'new' => 1235 ),
				'top_items' => $items,
				'by_target' => array(
					array(
						'target_id' => 'core:_scan',
						'status'    => 'added',
						'count'     => 1235,
					),
				),
			)
		);

		$this->assertCount( 20, $this->section_lines( $result['body'], 'Top 20 by severity:' ) );
		$this->assertSame( array( '  core:_scan  added 1,235' ), $this->section_lines( $result['body'], 'By target:' ) );
		$this->assertStringContainsString( 'New: 1,235  Resolved: 0  Continuing: 0 (already reported)', $result['body'] );
	}

	/**
	 * 件名のサイト名から改行・制御文字を除き、HTMLエンティティを戻すことを確認する
	 * (§6: ヘッダーインジェクション対策).
	 *
	 * @return void
	 */
	public function test_subject_strips_newlines_and_decodes_site_name() {
		$result = WPCV_Alert_Composer::compose(
			array(
				'site_name' => "Tom &amp; Jerry\r\nBcc: attacker@example.com\x00",
				'counts'    => array(
					'new'      => 3,
					'resolved' => 1,
				),
			)
		);

		$this->assertStringNotContainsString( "\r", $result['subject'] );
		$this->assertStringNotContainsString( "\n", $result['subject'] );
		$this->assertStringNotContainsString( "\x00", $result['subject'] );
		$this->assertSame( '[WPCV] Tom & JerryBcc: attacker@example.com: 3 new findings, 1 resolved', $result['subject'] );
	}

	/**
	 * パスの制御文字を除き、ABSPATH で始まる絶対パスは相対パスに直すことを確認する(§6).
	 *
	 * @return void
	 */
	public function test_paths_are_stripped_of_control_chars_and_absolute_prefix() {
		$result = WPCV_Alert_Composer::compose(
			array(
				'top_items'      => array(
					array(
						'severity'  => 'high',
						'target_id' => 'plugin:foo',
						'path'      => "wp-content/plugins/foo/a\x07b\nc.php",
						'status'    => 'modified',
					),
				),
				'resolved_items' => array(
					array(
						'target_id' => 'plugin:bar',
						'path'      => ABSPATH . 'wp-content/plugins/bar/y.php',
						'status'    => 'modified',
					),
				),
			)
		);

		$this->assertSame( array( '  [high] plugin:foo  wp-content/plugins/foo/abc.php  modified' ), $this->section_lines( $result['body'], 'Top 1 by severity:' ) );
		$this->assertSame( array( '  plugin:bar  wp-content/plugins/bar/y.php  modified' ), $this->section_lines( $result['body'], 'Resolved:' ) );
		$this->assertStringNotContainsString( ABSPATH, $result['body'] );
	}

	/**
	 * 上位一覧が severity 降順(high > medium > low、未知の値は最後)→ target_id →
	 * path の順に並ぶことを確認する(入力の並びに依存しない).
	 *
	 * @return void
	 */
	public function test_top_items_are_sorted_by_severity_target_and_path() {
		$make = static function ( $severity, $target_id, $path ) {
			return array(
				'severity'  => $severity,
				'target_id' => $target_id,
				'path'      => $path,
				'status'    => 'modified',
			);
		};

		$result = WPCV_Alert_Composer::compose(
			array(
				'top_items' => array(
					$make( 'low', 'plugin:a', 'z.php' ),
					$make( 'unknown', 'plugin:a', 'a.php' ),
					$make( 'high', 'plugin:b', 'a.php' ),
					$make( 'medium', 'plugin:a', 'a.php' ),
					$make( 'high', 'plugin:a', 'b.php' ),
					$make( 'high', 'plugin:a', 'a.php' ),
				),
			)
		);

		$this->assertSame(
			array(
				'  [high] plugin:a  a.php  modified',
				'  [high] plugin:a  b.php  modified',
				'  [high] plugin:b  a.php  modified',
				'  [medium] plugin:a  a.php  modified',
				'  [low] plugin:a  z.php  modified',
				'  [unknown] plugin:a  a.php  modified',
			),
			$this->section_lines( $result['body'], 'Top 6 by severity:' )
		);
	}

	/**
	 * 「Targets:」行の数え方を確認する(rev.3 §12.3-(h)): stat target は success の
	 * ときだけ change-tracked として数え、checksum_covered で skipped の stat target と
	 * 除外で skipped の target は数えない. aborted は failed に含める.
	 *
	 * @return void
	 */
	public function test_targets_line_counts_stat_targets_separately() {
		$result = WPCV_Alert_Composer::compose(
			array(
				'target_runs' => array(
					array( 'target_id' => 'core', 'status' => 'success' ),
					array( 'target_id' => 'plugin:foo', 'status' => 'success' ),
					array( 'target_id' => 'plugin:foo:_stat', 'status' => 'skipped', 'error_code' => WPCV_Error_Code::CHECKSUM_COVERED ),
					array( 'target_id' => 'plugin:paid:_stat', 'status' => 'success' ),
					array( 'target_id' => 'plugin:paid', 'status' => 'unverifiable' ),
					array( 'target_id' => 'plugin:excluded', 'status' => 'skipped', 'error_code' => WPCV_Error_Code::EXCLUDED ),
					array( 'target_id' => 'plugin:broken', 'status' => 'failed' ),
					array( 'target_id' => 'plugin:slow', 'status' => 'aborted' ),
				),
			)
		);

		$this->assertStringContainsString( 'Targets: 2 verified / 1 change-tracked / 1 unverifiable / 2 failed', $result['body'] );
	}

	/**
	 * 「By target」は target_id 昇順・status 昇順に並び、同じ組み合わせの行は合計し、
	 * 0件の行は出さないことを確認する.
	 *
	 * @return void
	 */
	public function test_by_target_groups_sorts_and_sums() {
		$result = WPCV_Alert_Composer::compose(
			array(
				'by_target' => array(
					array( 'target_id' => 'plugin:foo', 'status' => 'stat_changed', 'count' => 1 ),
					array( 'target_id' => 'core:_scan', 'status' => 'added', 'count' => 1000 ),
					array( 'target_id' => 'plugin:foo', 'status' => 'modified', 'count' => 3 ),
					array( 'target_id' => 'core:_scan', 'status' => 'added', 'count' => 235 ),
					array( 'target_id' => 'plugin:zero', 'status' => 'added', 'count' => 0 ),
				),
			)
		);

		$this->assertSame(
			array(
				'  core:_scan  added 1,235',
				'  plugin:foo  modified 3, stat_changed 1',
			),
			$this->section_lines( $result['body'], 'By target:' )
		);
	}

	/**
	 * 省略可の節(上位一覧・By target・Resolved・Not verified today・Unverifiable・
	 * Removed targets・Details)は、入力が空のとき出さないことを確認する.
	 *
	 * @return void
	 */
	public function test_empty_sections_are_omitted() {
		$result = WPCV_Alert_Composer::compose(
			array(
				'run' => array(
					'id'          => 12,
					'finished_at' => '2026-09-26 05:45:00',
					'run_trigger' => 'cron',
				),
			)
		);

		$this->assertSame(
			"Run #12 (2026-09-26 05:45:00 UTC, cron)\n"
			. "Targets: 0 verified / 0 change-tracked / 0 unverifiable / 0 failed\n"
			. "New: 0  Resolved: 0  Continuing: 0 (already reported)\n",
			$result['body']
		);
	}

	/**
	 * 同封する節(version 変更で検証しなかった target・連続 unverifiable・削除された
	 * target の数・詳細 URL)が §4.1 の書式で出ることを確認する.
	 *
	 * @return void
	 */
	public function test_optional_sections_are_rendered() {
		$result = WPCV_Alert_Composer::compose(
			array(
				'baseline_rebuilt'       => array(
					array( 'target_id' => 'plugin:baz', 'from_version' => '1.2.0', 'to_version' => '1.3.0' ),
				),
				'unverifiable_streaks'   => array(
					array( 'target_id' => 'plugin:qux', 'error_code' => WPCV_Error_Code::HTTP_ERROR ),
				),
				'unverifiable_threshold' => 3,
				'removed_targets'        => 2,
				'details_url'            => 'https://example.com/wp-admin/admin.php?page=wpcv-findings',
			)
		);

		$this->assertSame( array( '  plugin:baz 1.2.0 -> 1.3.0' ), $this->section_lines( $result['body'], 'Not verified today (baseline rebuilt after a version change):' ) );
		$this->assertSame( array( '  plugin:qux  http_error' ), $this->section_lines( $result['body'], 'Unverifiable 3 times in a row:' ) );
		$this->assertStringContainsString( "\nRemoved targets: 2\n", $result['body'] );
		$this->assertStringEndsWith( "\nDetails: https://example.com/wp-admin/admin.php?page=wpcv-findings\n", $result['body'] );
	}

	/**
	 * 上位件数が `wpcv_alert_max_items` フィルターで変えられ、Resolved にも同じ上限が
	 * かかることを確認する. 1未満の値は1にする.
	 *
	 * @return void
	 */
	public function test_max_items_filter_limits_top_and_resolved() {
		$GLOBALS['_wpcv_test_filters']['wpcv_alert_max_items'][] = static function () {
			return 2;
		};

		$items = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$items[] = array(
				'severity'  => 'high',
				'target_id' => 'plugin:foo',
				'path'      => "f{$i}.php",
				'status'    => 'modified',
			);
		}

		$result = WPCV_Alert_Composer::compose(
			array(
				'top_items'      => $items,
				'resolved_items' => $items,
			)
		);

		$this->assertCount( 2, $this->section_lines( $result['body'], 'Top 2 by severity:' ) );
		$this->assertCount( 2, $this->section_lines( $result['body'], 'Resolved:' ) );

		$GLOBALS['_wpcv_test_filters']['wpcv_alert_max_items'] = array(
			static function () {
				return 0;
			},
		);

		$this->assertSame( 1, WPCV_Alert_Composer::max_items() );
	}

	// ------------------------------------------------------------------
	// v0.5後半 §Step15a: run の連続失敗アラート(`compose_run_failure()`)
	// ------------------------------------------------------------------

	/**
	 * 件名に site name と連続件数が入り、本文に各runのid・status・開始日時・
	 * triggerが新しい順に並び、末尾にDetailsのURLが出ることを確認する(§3.3).
	 *
	 * @return void
	 */
	public function test_compose_run_failure_builds_subject_and_body() {
		$result = WPCV_Alert_Composer::compose_run_failure(
			array(
				'site_name'     => 'Example Site',
				'streak_length' => 3,
				'streak_runs'   => array(
					array(
						'id'          => 6,
						'status'      => 'failed',
						'started_at'  => '2026-09-08 12:00:00',
						'run_trigger' => 'cron',
					),
					array(
						'id'          => 5,
						'status'      => 'aborted',
						'started_at'  => '2026-09-08 06:00:00',
						'run_trigger' => 'manual',
					),
					array(
						'id'          => 4,
						'status'      => 'failed',
						'started_at'  => '2026-09-08 00:00:00',
						'run_trigger' => 'cli',
					),
				),
				'details_url'   => 'https://example.test/wp-admin/admin.php?page=wpcv-runs',
			)
		);

		$this->assertSame( '[WPCV] Example Site: 3 runs failed in a row', $result['subject'] );

		$lines = explode( "\n", $result['body'] );

		$this->assertSame( '3 runs failed in a row:', $lines[0] );
		$this->assertSame( '  #6  failed  2026-09-08 12:00:00 UTC  cron', $lines[1] );
		$this->assertSame( '  #5  aborted  2026-09-08 06:00:00 UTC  manual', $lines[2] );
		$this->assertSame( '  #4  failed  2026-09-08 00:00:00 UTC  cli', $lines[3] );
		$this->assertStringContainsString( 'Details: https://example.test/wp-admin/admin.php?page=wpcv-runs', $result['body'] );
	}

	/**
	 * `notes`/`error_message`に相当する情報を入力に渡していなくても本文に
	 * 出ない(そもそも入力欄自体が無い)ことと、`details_url`が空なら
	 * Details行が出ないことを確認する.
	 *
	 * @return void
	 */
	public function test_compose_run_failure_omits_details_section_when_url_is_empty() {
		$result = WPCV_Alert_Composer::compose_run_failure(
			array(
				'site_name'     => 'Example Site',
				'streak_length' => 1,
				'streak_runs'   => array(
					array(
						'id'          => 1,
						'status'      => 'failed',
						'started_at'  => '2026-09-08 00:00:00',
						'run_trigger' => 'cron',
					),
				),
			)
		);

		$this->assertStringNotContainsString( 'Details:', $result['body'] );
	}

	/**
	 * サイト名の改行・制御文字が件名から除かれることを確認する(§6.
	 * `clean_site_name()`と同じ安全化. `compose()`側のテストと同種の観点).
	 *
	 * @return void
	 */
	public function test_compose_run_failure_strips_control_chars_from_site_name() {
		$result = WPCV_Alert_Composer::compose_run_failure(
			array(
				'site_name'     => "Evil\nSite",
				'streak_length' => 1,
			)
		);

		$this->assertStringNotContainsString( "\n", $result['subject'] );
		$this->assertStringContainsString( 'EvilSite', $result['subject'] );
	}
}
