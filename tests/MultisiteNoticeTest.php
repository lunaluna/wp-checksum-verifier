<?php
/**
 * WPCV_Multisite_Notice のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-multisite-notice.php';

use PHPUnit\Framework\TestCase;

/**
 * サブサイトのみで有効化されたときの警告(v0.9 §Step4・M1・U10)の判定のテスト.
 *
 * 描画(`maybe_render()`)は `get_current_screen()`・`is_plugin_active_for_network()` 等の
 * WordPress コア関数に依存するため単体テストの対象にせず、実環境(alpine-dealer.local)で確認する.
 */
class MultisiteNoticeTest extends TestCase {

	/**
	 * 警告が出るのは「マルチサイトで、ネットワーク有効でも main site で有効でもない」ときだけ.
	 * 3つの条件の全8通りを確認する.
	 *
	 * @return void
	 */
	public function test_should_warn_truth_table() {
		$cases = array(
			// is_multisite, network_active, main_site_active, expected.
			array( false, false, false, false ), // 単一サイト: 警告しない.
			array( false, false, true, false ),
			array( false, true, false, false ),
			array( false, true, true, false ),
			array( true, false, false, true ),  // サブサイトのみ: 警告する(自動実行が走らない).
			array( true, false, true, false ),  // main site で有効: 警告しない.
			array( true, true, false, false ),  // ネットワーク有効: 警告しない.
			array( true, true, true, false ),
		);

		foreach ( $cases as $case ) {
			list( $multisite, $network, $main, $expected ) = $case;

			$this->assertSame(
				$expected,
				WPCV_Multisite_Notice::should_warn( $multisite, $network, $main ),
				sprintf( 'multisite=%s network=%s main=%s', var_export( $multisite, true ), var_export( $network, true ), var_export( $main, true ) )
			);
		}
	}
}
