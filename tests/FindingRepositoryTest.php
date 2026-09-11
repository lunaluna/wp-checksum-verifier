<?php
/**
 * WPCV_Finding_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wpcv_findings` の DB 永続化(§4.2: Repository 層。v0.4.0 §Step1で
 * `WPCV_Repository` から分割)のテスト.
 */
class FindingRepositoryTest extends TestCase {

	/**
	 * save_findings() が target_run_ids から target_run_id を解決して
	 * findings テーブルに insert することを確認する.
	 *
	 * @return void
	 */
	public function test_save_findings_resolves_target_run_id() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$repository->save_findings(
			42,
			array( 'core' => 7 ),
			array( wpcv_test_make_finding( array( 'target_id' => 'core' ) ) )
		);

		$row = $wpdb->rows['wp_wpcv_findings'][1];
		$this->assertSame( 42, $row['run_id'] );
		$this->assertSame( 7, $row['target_run_id'] );
		$this->assertSame( 'core', $row['target_id'] );
		$this->assertSame( 'wp-admin/index.php', $row['path'] );
	}

	/**
	 * 対応する target_run_id が無い finding を渡すと例外を投げることを確認する
	 * (target_runs と findings の target_id は同一バッチ内で必ず一致している前提のため).
	 *
	 * @return void
	 */
	public function test_save_findings_throws_when_target_run_id_missing() {
		$this->expectException( InvalidArgumentException::class );

		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$repository->save_findings(
			42,
			array(),
			array( wpcv_test_make_finding( array( 'target_id' => 'core' ) ) )
		);
	}
}
