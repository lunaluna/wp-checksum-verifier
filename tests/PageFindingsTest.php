<?php
/**
 * WPCV_Page_Findings のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
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
}
