<?php
/**
 * WPCV_Target_Run_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wpcv_target_runs` の DB 永続化(§4.2: Repository 層。v0.4.0 §Step1で
 * `WPCV_Repository` から分割)のテスト.
 */
class TargetRunRepositoryTest extends TestCase {

	/**
	 * save_target_runs() が target_runs テーブルに行を insert し、
	 * target_id => target_run_id の対応表を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_save_target_runs_inserts_rows_and_returns_id_map() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

		$target_run_ids = $repository->save_target_runs(
			42,
			array(
				wpcv_test_make_target_run( array( 'target_id' => 'core' ) ),
				wpcv_test_make_target_run(
					array(
						'target_id' => 'plugin:akismet',
						'dimension' => 'plugin',
						'slug'      => 'akismet',
					)
				),
			)
		);

		$this->assertSame(
			array(
				'core'           => 1,
				'plugin:akismet' => 2,
			),
			$target_run_ids
		);

		$row = $wpdb->rows['wp_wpcv_target_runs'][2];
		$this->assertSame( 42, $row['run_id'] );
		$this->assertSame( 'plugin:akismet', $row['target_id'] );
		$this->assertSame( 'akismet', $row['slug'] );
	}
}
