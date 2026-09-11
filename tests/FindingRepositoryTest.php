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

	/**
	 * `query()` が指定 run_id 以外の finding を含めないことを確認する
	 * (v0.4.0 §Step7).
	 *
	 * @return void
	 */
	public function test_query_filters_by_run_id() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'a.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 2, 'path' => 'b.php' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );
		$result     = $repository->query( array( 'run_id' => 1 ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'a.php', $result['rows'][0]['path'] );
	}

	/**
	 * `query()` が `dimension`/`status`/`severity` の allowlist フィルタを適用することを確認する.
	 *
	 * @return void
	 */
	public function test_query_filters_by_dimension_status_and_severity() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'core.php', 'dimension' => 'core', 'status' => 'modified', 'severity' => 'high' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'plugin.php', 'dimension' => 'plugin', 'status' => 'added', 'severity' => 'medium' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$this->assertSame(
			array( 'core.php' ),
			array_column( $repository->query( array( 'run_id' => 1, 'dimension' => array( 'core' ) ) )['rows'], 'path' )
		);
		$this->assertSame(
			array( 'plugin.php' ),
			array_column( $repository->query( array( 'run_id' => 1, 'status' => array( 'added' ) ) )['rows'], 'path' )
		);
		$this->assertSame(
			array( 'core.php' ),
			array_column( $repository->query( array( 'run_id' => 1, 'severity' => array( 'high' ) ) )['rows'], 'path' )
		);
	}

	/**
	 * `query()` が既定で suppressed/closed の finding を除外し、
	 * `include_suppressed`/`include_closed` で含められることを確認する.
	 *
	 * @return void
	 */
	public function test_query_excludes_suppressed_and_closed_by_default() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'open.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'suppressed.php', 'suppressed_by' => 'soft_change' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'closed.php', 'closed_at' => '2026-09-08 00:00:00', 'closed_reason' => 'fixed' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$default_result = $repository->query( array( 'run_id' => 1 ) );
		$this->assertSame( array( 'open.php' ), array_column( $default_result['rows'], 'path' ) );
		$this->assertSame( 1, $default_result['total'] );

		$with_suppressed = $repository->query( array( 'run_id' => 1, 'include_suppressed' => true ) );
		$this->assertContains( 'suppressed.php', array_column( $with_suppressed['rows'], 'path' ) );
		$this->assertNotContains( 'closed.php', array_column( $with_suppressed['rows'], 'path' ) );

		$with_closed = $repository->query( array( 'run_id' => 1, 'include_closed' => true ) );
		$this->assertContains( 'closed.php', array_column( $with_closed['rows'], 'path' ) );
	}

	/**
	 * `query()` が `sort`/`order` に従って並べ替えることを確認する
	 * (allowlist外の`sort`は`id`にフォールバックする).
	 *
	 * @return void
	 */
	public function test_query_sorts_by_allowlisted_column() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'b.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'a.php' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$asc = $repository->query( array( 'run_id' => 1, 'sort' => 'path', 'order' => 'asc' ) );
		$this->assertSame( array( 'a.php', 'b.php' ), array_column( $asc['rows'], 'path' ) );

		$desc = $repository->query( array( 'run_id' => 1, 'sort' => 'path', 'order' => 'desc' ) );
		$this->assertSame( array( 'b.php', 'a.php' ), array_column( $desc['rows'], 'path' ) );
	}

	/**
	 * `query()` が `page`/`per_page` に従って結果を分割し、`total` には
	 * pagination前の全件数を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_query_paginates_results() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		foreach ( range( 1, 5 ) as $i ) {
			$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => "file{$i}.php" ) ) );
		}

		$repository = new WPCV_Finding_Repository( $wpdb );

		$page1 = $repository->query( array( 'run_id' => 1, 'per_page' => 2, 'page' => 1 ) );
		$this->assertSame( array( 'file1.php', 'file2.php' ), array_column( $page1['rows'], 'path' ) );
		$this->assertSame( 5, $page1['total'] );

		$page3 = $repository->query( array( 'run_id' => 1, 'per_page' => 2, 'page' => 3 ) );
		$this->assertSame( array( 'file5.php' ), array_column( $page3['rows'], 'path' ) );
	}
}
