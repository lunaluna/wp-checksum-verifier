<?php
/**
 * WPCV_Advisory_Lock のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-advisory-lock.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * MySQL の advisory lock のラッパー `WPCV_Advisory_Lock` のテスト(0.10.0 のコードレビュー指摘2).
 *
 * 実際の MySQL での排他は `WPCV_Run_Repository::reserve_run()` と同じ `GET_LOCK()` の仕組みに任せる.
 * ここでは、名前に `base_prefix` を含めること・待つ秒数・戻り値の解釈・放し方を確かめる.
 */
class AdvisoryLockTest extends TestCase {

	/**
	 * `GET_LOCK()` に、用途と `base_prefix` を含む名前と、待つ秒数を渡すことを確認する.
	 *
	 * @return void
	 */
	public function test_acquire_sends_get_lock_with_prefixed_name_and_timeout() {
		$wpdb              = new WPCV_Test_Fake_WPDB();
		$wpdb->base_prefix = 'wp2_';

		$this->assertTrue( ( new WPCV_Advisory_Lock( $wpdb, 'prune' ) )->acquire( 5 ) );
		$this->assertSame( "SELECT GET_LOCK('wpcv_prune_wp2_', 5)", $wpdb->get_var_calls[0] );
	}

	/**
	 * `GET_LOCK()` が 1 以外(0 = 取れない・NULL = エラー)なら false を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_acquire_returns_false_unless_one() {
		foreach ( array( '0', null ) as $value ) {
			$wpdb                 = new WPCV_Test_Fake_WPDB();
			$wpdb->get_var_return = $value;

			$this->assertFalse( ( new WPCV_Advisory_Lock( $wpdb, 'prune' ) )->acquire() );
			$this->assertStringContainsString( ', 0)', $wpdb->get_var_calls[0], '既定は待たない' );
		}
	}

	/**
	 * `release()` が同じ名前で `RELEASE_LOCK()` を呼ぶことを確認する.
	 *
	 * @return void
	 */
	public function test_release_sends_release_lock() {
		$wpdb = new WPCV_Test_Fake_WPDB();

		( new WPCV_Advisory_Lock( $wpdb, 'prune_request' ) )->release();

		$this->assertSame( array( "SELECT RELEASE_LOCK('wpcv_prune_request_wp_')" ), $wpdb->query_calls );
	}
}
