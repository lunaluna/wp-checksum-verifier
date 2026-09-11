<?php
/**
 * WPCV_Page_Suppressions のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-suppressions.php';

use PHPUnit\Framework\TestCase;

/**
 * `render()`/`render_revoke_form()`/`maybe_handle_revoke()`は`wp_nonce_field()`・
 * `check_admin_referer()`・`get_userdata()`等、`tests/wp-stubs.php`に無いWPコア
 * 関数へ依存するため単体テストの対象にしない(`WPCV_Page_Settings`と同じ方針)。
 * レンダリングから分離した純粋ロジック(`format_target()`/`status_label()`)だけを
 * 単体テストする.
 */
class PageSuppressionsTest extends TestCase {

	/**
	 * `format_target()` が `exclude_target` に対してdimension:slugのみを
	 * 返すことを確認する.
	 *
	 * @return void
	 */
	public function test_format_target_for_exclude_target() {
		$row = array(
			'type'      => WPCV_Suppression_Type::EXCLUDE_TARGET,
			'dimension' => 'plugin',
			'slug'      => 'hello-dolly',
			'pattern'   => null,
		);

		$this->assertSame( 'plugin:hello-dolly', WPCV_Page_Suppressions::format_target( $row ) );
	}

	/**
	 * `format_target()` が `exclude_path` に対してpatternを付加することを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_format_target_for_exclude_path() {
		$row = array(
			'type'      => WPCV_Suppression_Type::EXCLUDE_PATH,
			'dimension' => 'core',
			'slug'      => '_scan',
			'pattern'   => '.DS_Store',
		);

		$this->assertSame( 'core:_scan / .DS_Store', WPCV_Page_Suppressions::format_target( $row ) );
	}

	/**
	 * `format_target()` が `allowlist_hash` に対してversionとhashの先頭8文字を
	 * 付加することを確認する.
	 *
	 * @return void
	 */
	public function test_format_target_for_allowlist_hash() {
		$row = array(
			'type'           => WPCV_Suppression_Type::ALLOWLIST_HASH,
			'dimension'      => 'plugin',
			'slug'           => 'oembed-plus',
			'pattern'        => 'src/Embed.php',
			'version'        => '2.4.0',
			'expected_hash'  => 'deadbeef00112233',
			'hash_algorithm' => 'sha256',
		);

		$this->assertSame(
			'plugin:oembed-plus / src/Embed.php (version: 2.4.0, hash: deadbeef…)',
			WPCV_Page_Suppressions::format_target( $row )
		);
	}

	/**
	 * `status_label()` が失効していない行に対して「Active」を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_status_label_returns_active_when_not_expired() {
		$row = array(
			'expired_at'     => null,
			'expired_reason' => null,
		);

		$this->assertSame( 'Active', WPCV_Page_Suppressions::status_label( $row ) );
	}

	/**
	 * `status_label()` が失効済みの行に対して失効日時と理由を含む文字列を
	 * 返すことを確認する.
	 *
	 * @return void
	 */
	public function test_status_label_includes_timestamp_and_reason_when_expired() {
		$row = array(
			'expired_at'     => '2026-09-11 10:00:00',
			'expired_reason' => 'no longer needed',
		);

		$label = WPCV_Page_Suppressions::status_label( $row );

		$this->assertStringContainsString( '2026-09-11 10:00:00', $label );
		$this->assertStringContainsString( 'no longer needed', $label );
	}
}
