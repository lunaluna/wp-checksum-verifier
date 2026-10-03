<?php
/**
 * WPCV_Page_Findings のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-affected-sites.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-run-history.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-findings.php';

use PHPUnit\Framework\TestCase;

/**
 * `render()`/`render_filter_form()`/`render_findings_table()`/`maybe_handle_action()` は
 * `wp_nonce_field()`・`check_admin_referer()`・`submit_button()`等、
 * `tests/wp-stubs.php`に無いWPコア関数へ依存するため単体テストの対象にしない
 * (`WPCV_Page_Settings`/`WPCV_Page_Run_History`と同じ方針)。レンダリングから
 * 分離した純粋ロジック(`resolve_filter()`/`build_suppression_data()`)だけを
 * 単体テストする.
 */
class PageFindingsTest extends TestCase {

	/**
	 * `resolve_filter()` がallowlist内の値をそのまま返すことを確認する.
	 *
	 * @return void
	 */
	public function test_resolve_filter_returns_value_when_allowed() {
		$this->assertSame( 'plugin', WPCV_Page_Findings::resolve_filter( 'plugin', WPCV_Target_Resolver::DIMENSIONS ) );
	}

	/**
	 * `resolve_filter()` がallowlist外の値・非文字列を空文字(絞り込み無し)へ
	 * fallbackすることを確認する.
	 *
	 * @return void
	 */
	public function test_resolve_filter_falls_back_to_empty_string_when_disallowed() {
		$this->assertSame( '', WPCV_Page_Findings::resolve_filter( 'not-a-real-dimension', WPCV_Target_Resolver::DIMENSIONS ) );
		$this->assertSame( '', WPCV_Page_Findings::resolve_filter( array( 'plugin' ), WPCV_Target_Resolver::DIMENSIONS ) );
		$this->assertSame( '', WPCV_Page_Findings::resolve_filter( '', WPCV_Target_Resolver::DIMENSIONS ) );
	}

	/**
	 * `build_suppression_data()` が `exclude_target` action から
	 * dimension/slugのみのデータを組み立てることを確認する.
	 *
	 * @return void
	 */
	public function test_build_suppression_data_for_exclude_target() {
		$finding = array(
			'dimension'      => 'plugin',
			'slug'           => 'hello-dolly',
			'path'           => 'readme.txt',
			'hash_algorithm' => 'sha256',
			'actual_hash'    => 'abc123',
			'version'        => '1.7.2',
		);

		$data = WPCV_Page_Findings::build_suppression_data( WPCV_Suppression_Type::EXCLUDE_TARGET, $finding, '既知のfalse positive', 5 );

		$this->assertSame(
			array(
				'type'       => 'exclude_target',
				'dimension'  => 'plugin',
				'slug'       => 'hello-dolly',
				'reason'     => '既知のfalse positive',
				'created_by' => 5,
			),
			$data
		);
	}

	/**
	 * `build_suppression_data()` が `exclude_path` action からpatternに
	 * finding.pathを使うデータを組み立てることを確認する.
	 *
	 * @return void
	 */
	public function test_build_suppression_data_for_exclude_path() {
		$finding = array(
			'dimension'      => 'core',
			'slug'           => '_scan',
			'path'           => '.DS_Store',
			'hash_algorithm' => 'sha256',
			'actual_hash'    => 'abc123',
			'version'        => '6.8',
		);

		$data = WPCV_Page_Findings::build_suppression_data( WPCV_Suppression_Type::EXCLUDE_PATH, $finding, 'macOSのシステムファイル', 5 );

		$this->assertSame( 'exclude_path', $data['type'] );
		$this->assertSame( '.DS_Store', $data['pattern'] );
		$this->assertSame( 'core', $data['dimension'] );
		$this->assertSame( '_scan', $data['slug'] );
		$this->assertSame( 'macOSのシステムファイル', $data['reason'] );
		$this->assertSame( 5, $data['created_by'] );
	}

	/**
	 * `build_suppression_data()` が `allowlist_hash` action からversion/hash_algorithm/
	 * expected_hash(=finding.actual_hash)を含むデータを組み立てることを確認する.
	 *
	 * @return void
	 */
	public function test_build_suppression_data_for_allowlist_hash() {
		$finding = array(
			'dimension'      => 'plugin',
			'slug'           => 'oembed-plus',
			'path'           => 'src/Embed.php',
			'hash_algorithm' => 'sha256',
			'actual_hash'    => 'deadbeef',
			'version'        => '2.4.0',
		);

		$data = WPCV_Page_Findings::build_suppression_data( WPCV_Suppression_Type::ALLOWLIST_HASH, $finding, '意図的なカスタマイズ', 7 );

		$this->assertSame(
			array(
				'type'           => 'allowlist_hash',
				'dimension'      => 'plugin',
				'slug'           => 'oembed-plus',
				'pattern'        => 'src/Embed.php',
				'expected_hash'  => 'deadbeef',
				'hash_algorithm' => 'sha256',
				'version'        => '2.4.0',
				'reason'         => '意図的なカスタマイズ',
				'created_by'     => 7,
			),
			$data
		);
	}

	/**
	 * `build_suppression_data()` が `allowlist_hash` action でactual_hashが無い場合
	 * `WP_Error`を返すことを確認する(v0.4.0 §Step8の設計判断: hashが無いfinding
	 * は承認対象にならない).
	 *
	 * @return void
	 */
	public function test_build_suppression_data_for_allowlist_hash_without_hash_returns_error() {
		$finding = array(
			'dimension'      => 'plugin',
			'slug'           => 'hello-dolly',
			'path'           => 'hello.php',
			'hash_algorithm' => 'sha256',
			'actual_hash'    => '',
			'version'        => '1.7.2',
		);

		$data = WPCV_Page_Findings::build_suppression_data( WPCV_Suppression_Type::ALLOWLIST_HASH, $finding, '承認理由', 5 );

		$this->assertInstanceOf( 'WP_Error', $data );
	}

	/**
	 * `build_suppression_data()` が未知のactionに対して`WP_Error`を返すことを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_build_suppression_data_for_unknown_action_returns_error() {
		$data = WPCV_Page_Findings::build_suppression_data( 'not_a_real_action', array(), '理由', 5 );

		$this->assertInstanceOf( 'WP_Error', $data );
	}

	/**
	 * `format_detail()` が stat_changed の前回値→今回値を、変わった項目だけ短い文にすることを
	 * 確認する(v0.5 §Step8).
	 *
	 * @return void
	 */
	public function test_format_detail_describes_changed_stat_values() {
		$text = WPCV_Page_Findings::format_detail(
			array(
				'detail' => wp_json_encode(
					array(
						'size'      => array(
							'old' => 4021,
							'new' => 4160,
						),
						'ctime'     => array(
							'old' => 1757000000,
							'new' => 1757600000,
						),
						'mtime'     => array(
							'old' => 1740000000,
							'new' => 1740000000,
						),
						'timestomp' => true,
					)
				),
			)
		);

		$this->assertStringContainsString( 'size: 4021 → 4160', $text );
		$this->assertStringContainsString( 'ctime: 2025-09-04 15:33:20 → 2025-09-11 14:13:20', $text );
		$this->assertStringNotContainsString( 'mtime:', $text );
		$this->assertStringContainsString( 'timestamp forgery', $text );
	}

	/**
	 * まとめた finding は件数と代表パスを、detail を持たない finding は空文字を返すことを
	 * 確認する(v0.5 §Step8).
	 *
	 * @return void
	 */
	public function test_format_detail_for_rollup_and_empty_detail() {
		$text = WPCV_Page_Findings::format_detail(
			array(
				'detail' => wp_json_encode(
					array(
						'rollup'        => true,
						'count'         => 24,
						'added'         => 0,
						'files_scanned' => 26,
						'sample_paths'  => array( 'a.php', 'b.php' ),
					)
				),
			)
		);

		$this->assertStringContainsString( '24 of 26 files changed', $text );
		$this->assertStringContainsString( 'a.php, b.php', $text );
		$this->assertSame( '', WPCV_Page_Findings::format_detail( array( 'detail' => null ) ) );
		$this->assertSame( '', WPCV_Page_Findings::format_detail( array() ) );
	}

	/**
	 * `format_diff_state()` が §1.1 の表の全行どおりに表示文字列を組み立てることを
	 * 確認する(v0.5後半 §16).
	 *
	 * @return void
	 */
	public function test_format_diff_state_covers_all_table_rows() {
		// new / notified_at無し.
		$this->assertSame( 'new', WPCV_Page_Findings::format_diff_state( array( 'diff_state' => 'new', 'notified_at' => null ) ) );

		// new / notified_atあり.
		$this->assertSame(
			'new (emailed 2026-09-28 00:00:00)',
			WPCV_Page_Findings::format_diff_state( array( 'diff_state' => 'new', 'notified_at' => '2026-09-28 00:00:00' ) )
		);

		// continuing / notified_at無し.
		$this->assertSame( 'continuing', WPCV_Page_Findings::format_diff_state( array( 'diff_state' => 'continuing', 'notified_at' => null ) ) );

		// continuing / notified_atあり(前回の送信失敗により今回送り直した行).
		$this->assertSame(
			'continuing (emailed 2026-09-28 00:00:00)',
			WPCV_Page_Findings::format_diff_state( array( 'diff_state' => 'continuing', 'notified_at' => '2026-09-28 00:00:00' ) )
		);

		// event / notified_at無し・あり.
		$this->assertSame( 'event', WPCV_Page_Findings::format_diff_state( array( 'diff_state' => 'event', 'notified_at' => null ) ) );
		$this->assertSame(
			'event (emailed 2026-09-28 00:00:00)',
			WPCV_Page_Findings::format_diff_state( array( 'diff_state' => 'event', 'notified_at' => '2026-09-28 00:00:00' ) )
		);

		// diff_stateが無い(NULL. 差分処理がまだの run・失敗した run・v4より前の run・抑制).
		$this->assertSame( '—', WPCV_Page_Findings::format_diff_state( array( 'diff_state' => null, 'notified_at' => null ) ) );
		$this->assertSame( '—', WPCV_Page_Findings::format_diff_state( array() ) );

		// 未知の値はそのまま出す(target_run_reason_label()と同じ方針).
		$this->assertSame( 'some_future_state', WPCV_Page_Findings::format_diff_state( array( 'diff_state' => 'some_future_state', 'notified_at' => null ) ) );
	}

	// ------------------------------------------------------------------
	// v0.9 §Step4: マルチサイトの「Active on」列の整形.
	// ------------------------------------------------------------------

	/**
	 * `format_affected_sites()` が、状態ごとの短い文を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_format_affected_sites_by_state() {
		$base = array(
			'sites'           => array(),
			'network_enabled' => false,
			'total_sites'     => 3,
		);

		$this->assertSame( 'Network-wide (all sites)', WPCV_Page_Findings::format_affected_sites( array_merge( $base, array( 'state' => 'network' ) ) ) );
		$this->assertSame( 'Not active on any site', WPCV_Page_Findings::format_affected_sites( array_merge( $base, array( 'state' => 'none' ) ) ) );
		$this->assertSame(
			'Network-enabled, but not active on any site',
			WPCV_Page_Findings::format_affected_sites( array_merge( $base, array( 'state' => 'none', 'network_enabled' => true ) ) )
		);
		$this->assertSame( 'Not shown (the network has 12000 sites)', WPCV_Page_Findings::format_affected_sites( array_merge( $base, array( 'state' => 'unavailable', 'total_sites' => 12000 ) ) ) );
		$this->assertSame( '—', WPCV_Page_Findings::format_affected_sites( array_merge( $base, array( 'state' => 'not_applicable' ) ) ) );
	}

	/**
	 * サイトの一覧は名前を「, 」で並べ、親テーマには注記を付け、名前が空なら URL を使い、
	 * 表示の上限を超えた分は「and N more」にまとめることを確認する.
	 *
	 * @return void
	 */
	public function test_format_affected_sites_lists_names_with_parent_note_and_overflow() {
		$sites = array(
			array(
				'blog_id'  => 1,
				'name'     => 'Main',
				'url'      => 'https://example.test/',
				'relation' => 'active',
			),
			array(
				'blog_id'  => 2,
				'name'     => '',
				'url'      => 'https://example.test/two/',
				'relation' => 'active',
			),
			array(
				'blog_id'  => 3,
				'name'     => 'Three',
				'url'      => 'https://example.test/three/',
				'relation' => 'parent',
			),
		);

		$info = array(
			'state'           => 'sites',
			'sites'           => $sites,
			'network_enabled' => false,
			'total_sites'     => 3,
		);

		$this->assertSame( 'Main, https://example.test/two/, Three (parent theme)', WPCV_Page_Findings::format_affected_sites( $info ) );

		$many = array();

		for ( $i = 1; $i <= WPCV_Page_Findings::AFFECTED_SITES_DISPLAY_LIMIT + 2; $i++ ) {
			$many[] = array(
				'blog_id'  => $i,
				'name'     => 'S' . $i,
				'url'      => '',
				'relation' => 'active',
			);
		}

		$info['sites'] = $many;

		$this->assertSame( 'S1, S2, S3, S4, S5, and 2 more', WPCV_Page_Findings::format_affected_sites( $info ) );
	}
}
