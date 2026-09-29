<?php
/**
 * WPCV_Target_Resolver のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';

use PHPUnit\Framework\TestCase;

/**
 * Stat 差分検知 target の target_id ヘルパー(v0.5 §Step6)のテスト.
 */
class TargetResolverTest extends TestCase {

	/**
	 * `build_stat_id()` が本体の target_id に `:_stat` を付け、
	 * `body_id_of_stat()` で元に戻せることを確認する.
	 *
	 * @return void
	 */
	public function test_build_stat_id_round_trips_with_body_id_of_stat() {
		$this->assertSame( 'plugin:custom-plugin:_stat', WPCV_Target_Resolver::build_stat_id( 'plugin:custom-plugin' ) );
		$this->assertSame( 'muplugin:loader.php:_stat', WPCV_Target_Resolver::build_stat_id( 'muplugin:loader.php' ) );
		$this->assertSame( 'plugin:custom-plugin', WPCV_Target_Resolver::body_id_of_stat( 'plugin:custom-plugin:_stat' ) );
	}

	/**
	 * `is_stat_id()` が接尾辞だけで判定し、`core:_scan` や接尾辞そのものは
	 * stat target とみなさないことを確認する.
	 *
	 * @return void
	 */
	public function test_is_stat_id_detects_only_stat_suffix() {
		$this->assertTrue( WPCV_Target_Resolver::is_stat_id( 'plugin:custom-plugin:_stat' ) );
		$this->assertFalse( WPCV_Target_Resolver::is_stat_id( 'plugin:custom-plugin' ) );
		$this->assertFalse( WPCV_Target_Resolver::is_stat_id( 'core:_scan' ) );
		$this->assertFalse( WPCV_Target_Resolver::is_stat_id( ':_stat' ) );
	}

	/**
	 * Stat target でない target_id を `body_id_of_stat()` に渡すと例外になることを確認する.
	 *
	 * @return void
	 */
	public function test_body_id_of_stat_throws_for_non_stat_id() {
		$this->expectException( InvalidArgumentException::class );

		WPCV_Target_Resolver::body_id_of_stat( 'plugin:custom-plugin' );
	}
}
