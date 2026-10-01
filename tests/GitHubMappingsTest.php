<?php
/**
 * WPCV_GitHub_Mappings のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-github-client.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-github-mappings.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_GitHub_Mappings`(v0.8 §Step6. §4.1・U2)のテスト.
 */
class GitHubMappingsTest extends TestCase {

	/**
	 * 各テストの前に設定・フィルターを空にする.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_options'], $GLOBALS['_wpcv_test_is_multisite'], $GLOBALS['_wpcv_test_filters'] );
	}

	/**
	 * 各テストの後にフィルターを空にする.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_wpcv_test_options'], $GLOBALS['_wpcv_test_filters'] );
		parent::tearDown();
	}

	/**
	 * 正しい行は、asset の有無にかかわらず取り込まれる. 空行と `#` の行は無視する.
	 *
	 * @return void
	 */
	public function test_parse_text_accepts_valid_lines_and_skips_blank_and_comments() {
		$result = WPCV_GitHub_Mappings::parse_text(
			"# 対応付け\n\nplugin:fresh lunaluna/fresh\n  theme:acme\t lunaluna/acme  acme-pro \r\n"
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame(
			array(
				array(
					'target' => 'plugin:fresh',
					'repo'   => 'lunaluna/fresh',
					'asset'  => '',
				),
				array(
					'target' => 'theme:acme',
					'repo'   => 'lunaluna/acme',
					'asset'  => 'acme-pro',
				),
			),
			$result['entries']
		);
	}

	/**
	 * 不正な行は捨てて、行番号と理由を返す.
	 *
	 * @return void
	 */
	public function test_parse_text_reports_invalid_lines() {
		$result = WPCV_GitHub_Mappings::parse_text(
			implode(
				"\n",
				array(
					'plugin:fresh',                           // 1: 項目が足りない.
					'plugin:a lunaluna/a b c',                // 2: 項目が多い.
					'mu-plugin:x lunaluna/x',                 // 3: target の種類が違う.
					'plugin:bad/slug lunaluna/x',             // 4: slug の文字が不正.
					'plugin:.. lunaluna/x',                   // 5: `..` の slug.
					'plugin:ok no-slash-repo',                // 6: repo の形が不正.
					'plugin:ok2 ../x',                        // 7: repo に `..`.
					'plugin:ok3 lunaluna/x bad asset',        // 8: 項目が多い(asset に空白).
					'plugin:ok4 lunaluna/x we!rd',            // 9: asset の文字が不正.
					'plugin:good lunaluna/good',              // 10: 正しい.
				)
			)
		);

		$this->assertSame( array( 'plugin:good' ), array_column( $result['entries'], 'target' ) );

		$reasons = array_column( $result['errors'], 'reason', 'line' );
		ksort( $reasons );

		$this->assertSame(
			array(
				1 => WPCV_GitHub_Mappings::REASON_INVALID_FORMAT,
				2 => WPCV_GitHub_Mappings::REASON_INVALID_FORMAT,
				3 => WPCV_GitHub_Mappings::REASON_INVALID_TARGET,
				4 => WPCV_GitHub_Mappings::REASON_INVALID_TARGET,
				5 => WPCV_GitHub_Mappings::REASON_INVALID_TARGET,
				6 => WPCV_GitHub_Mappings::REASON_INVALID_REPO,
				7 => WPCV_GitHub_Mappings::REASON_INVALID_REPO,
				8 => WPCV_GitHub_Mappings::REASON_INVALID_FORMAT,
				9 => WPCV_GitHub_Mappings::REASON_INVALID_FORMAT,
			),
			$reasons
		);
	}

	/**
	 * 同じ target が2回出たら、後のものを捨てる(1件目が残る).
	 *
	 * @return void
	 */
	public function test_parse_text_drops_duplicate_target_keeping_first() {
		$result = WPCV_GitHub_Mappings::parse_text( "plugin:fresh lunaluna/first\nplugin:fresh lunaluna/second\n" );

		$this->assertSame( array( 'lunaluna/first' ), array_column( $result['entries'], 'repo' ) );
		$this->assertSame(
			array(
				array(
					'line'   => 2,
					'reason' => WPCV_GitHub_Mappings::REASON_DUPLICATE_TARGET,
				),
			),
			$result['errors']
		);
	}

	/**
	 * 保存した配列をテキストに戻し、もう一度読むと同じになる.
	 *
	 * @return void
	 */
	public function test_format_text_round_trips() {
		$entries = array(
			array(
				'target' => 'plugin:fresh',
				'repo'   => 'lunaluna/fresh',
				'asset'  => '',
			),
			array(
				'target' => 'theme:acme',
				'repo'   => 'lunaluna/acme',
				'asset'  => 'acme-pro',
			),
		);

		$text = WPCV_GitHub_Mappings::format_text( $entries );

		$this->assertSame( "plugin:fresh lunaluna/fresh\ntheme:acme lunaluna/acme acme-pro", $text );
		$this->assertSame( $entries, WPCV_GitHub_Mappings::parse_text( $text )['entries'] );
	}

	/**
	 * `normalize()` は target_id をキーにした表にし、不正な件を捨てる.
	 *
	 * @return void
	 */
	public function test_normalize_builds_map_and_drops_invalid() {
		$result = WPCV_GitHub_Mappings::normalize(
			array(
				array(
					'target' => 'plugin:fresh',
					'repo'   => 'lunaluna/fresh',
				),
				'not an array',
				array( 'target' => 'plugin:broken' ),
				array(
					'target' => 'plugin:fresh',
					'repo'   => 'lunaluna/dup',
				),
			)
		);

		$this->assertSame(
			array(
				'plugin:fresh' => array(
					'repo'  => 'lunaluna/fresh',
					'asset' => '',
				),
			),
			$result['map']
		);
		$this->assertSame( array( 2, 3, 4 ), array_column( $result['errors'], 'line' ) );
	}

	/**
	 * `resolve()` は設定の値のあとにフィルターを通す. フィルターの結果も検査される.
	 *
	 * @return void
	 */
	public function test_resolve_applies_settings_then_filter_and_validates() {
		WPCV_Settings::update_github_mappings(
			array(
				array(
					'target' => 'plugin:fresh',
					'repo'   => 'lunaluna/fresh',
				),
			)
		);

		$GLOBALS['_wpcv_test_filters']['wpcv_github_mappings'][] = static function ( $entries ) {
			$entries[] = array(
				'target' => 'theme:acme',
				'repo'   => 'lunaluna/acme',
				'asset'  => 'acme',
			);
			$entries[] = array(
				'target' => 'theme:bad',
				'repo'   => 'not-a-repo',
			);

			return $entries;
		};

		$this->assertSame(
			array(
				'plugin:fresh' => array(
					'repo'  => 'lunaluna/fresh',
					'asset' => '',
				),
				'theme:acme'   => array(
					'repo'  => 'lunaluna/acme',
					'asset' => 'acme',
				),
			),
			WPCV_GitHub_Mappings::resolve()
		);
	}

	/**
	 * 対応付けが無ければ空の表. フィルターが配列以外を返しても空として扱う.
	 *
	 * @return void
	 */
	public function test_resolve_is_empty_by_default_and_ignores_non_array_filter_result() {
		$this->assertSame( array(), WPCV_GitHub_Mappings::resolve() );

		$GLOBALS['_wpcv_test_filters']['wpcv_github_mappings'][] = static function () {
			return 'garbage';
		};

		$this->assertSame( array(), WPCV_GitHub_Mappings::resolve() );
	}
}
