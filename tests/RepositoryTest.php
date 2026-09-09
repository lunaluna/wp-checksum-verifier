<?php
/**
 * WPCV_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Verifier` が返すデータ構造の DB 永続化(§4.2: Repository 層)のテスト.
 */
class RepositoryTest extends TestCase {

	/**
	 * 固定時刻を返す Repository を作る.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @return WPCV_Repository
	 */
	private function make_repository( WPCV_Test_Fake_WPDB $wpdb ) {
		return new WPCV_Repository(
			$wpdb,
			static function () {
				return '2026-09-08 12:00:00';
			}
		);
	}

	/**
	 * start_run() が runs テーブルに1行 insert し、insert_id を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_start_run_inserts_row_and_returns_id() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->start_run();

		$this->assertSame( 1, $run_id );
		$row = $wpdb->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'running', $row['status'] );
		$this->assertSame( '2026-09-08 12:00:00', $row['started_at'] );
		$this->assertSame( 'manual', $row['run_trigger'] );
		$this->assertSame( 'sync', $row['runner'] );
	}

	/**
	 * run_trigger / runner を明示指定した場合はその値が使われることを確認する.
	 *
	 * @return void
	 */
	public function test_start_run_accepts_explicit_trigger_and_runner() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$repository->start_run(
			array(
				'run_trigger' => 'cli',
				'runner'      => 'async',
			)
		);

		$row = $wpdb->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'cli', $row['run_trigger'] );
		$this->assertSame( 'async', $row['runner'] );
	}

	/**
	 * save_target_runs() が target_runs テーブルに行を insert し、
	 * target_id => target_run_id の対応表を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_save_target_runs_inserts_rows_and_returns_id_map() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

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

	/**
	 * save_findings() が target_run_ids から target_run_id を解決して
	 * findings テーブルに insert することを確認する.
	 *
	 * @return void
	 */
	public function test_save_findings_resolves_target_run_id() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

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
		$repository = $this->make_repository( $wpdb );

		$repository->save_findings(
			42,
			array(),
			array( wpcv_test_make_finding( array( 'target_id' => 'core' ) ) )
		);
	}

	/**
	 * finish_run() が対象の run 行を status = summary の値・finished_at・
	 * 各カウントで更新することを確認する.
	 *
	 * @return void
	 */
	public function test_finish_run_updates_row() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->start_run();

		$repository->finish_run(
			$run_id,
			array(
				'status'               => 'partial',
				'targets_total'        => 3,
				'targets_verified'     => 2,
				'targets_unverifiable' => 1,
				'targets_failed'       => 0,
				'findings_total'       => 5,
			)
		);

		$row = $wpdb->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'partial', $row['status'] );
		$this->assertSame( '2026-09-08 12:00:00', $row['finished_at'] );
		$this->assertSame( 3, $row['targets_total'] );
		$this->assertSame( 2, $row['targets_verified'] );
		$this->assertSame( 1, $row['targets_unverifiable'] );
		$this->assertSame( 5, $row['findings_total'] );
		// start_run() 時点の status/started_at が上書きされず残っていることも確認する.
		$this->assertSame( 'manual', $row['run_trigger'] );
	}

	/**
	 * finish_run() が指定した run 以外の行を更新しないことを確認する.
	 *
	 * @return void
	 */
	public function test_finish_run_does_not_touch_other_rows() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$first_run_id  = $repository->start_run();
		$second_run_id = $repository->start_run();

		$repository->finish_run(
			$second_run_id,
			array(
				'status'               => 'success',
				'targets_total'        => 1,
				'targets_verified'     => 1,
				'targets_unverifiable' => 0,
				'targets_failed'       => 0,
				'findings_total'       => 0,
			)
		);

		$this->assertSame( 'running', $wpdb->rows['wp_wpcv_runs'][ $first_run_id ]['status'] );
		$this->assertSame( 'success', $wpdb->rows['wp_wpcv_runs'][ $second_run_id ]['status'] );
	}

	/**
	 * 閾値(30分)より古い `started_at` を持つ `running` 行が `failed` に
	 * 更新されることを確認する.
	 *
	 * @return void
	 */
	public function test_sweep_stale_running_marks_old_running_row_as_failed() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 11:00:00',
				'status'      => 'running',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$swept = $repository->sweep_stale_running( 30 );

		$this->assertSame( 1, $swept );
		$row = $wpdb->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( '2026-09-08 12:00:00', $row['finished_at'] );
		$this->assertNotEmpty( $row['notes'] );
	}

	/**
	 * 閾値内(30分以内)の `started_at` を持つ `running` 行は対象外であることを確認する.
	 *
	 * @return void
	 */
	public function test_sweep_stale_running_ignores_recent_running_row() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 11:45:00',
				'status'      => 'running',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$swept = $repository->sweep_stale_running( 30 );

		$this->assertSame( 0, $swept );
		$this->assertSame( 'running', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
	}

	/**
	 * `running` 以外の状態の行は(古くても)対象外であることを確認する.
	 *
	 * @return void
	 */
	public function test_sweep_stale_running_ignores_non_running_rows() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 09:00:00',
				'status'      => 'success',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$swept = $repository->sweep_stale_running( 30 );

		$this->assertSame( 0, $swept );
		$this->assertSame( 'success', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
	}

	/**
	 * `running` 状態の run が無ければ `null` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_find_active_run_id_returns_null_when_none_running() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-09 11:00:00',
				'status'      => 'success',
				'run_trigger' => 'rest',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertNull( $repository->find_active_run_id() );
	}

	/**
	 * `running` 状態の run があれば、その id を返すことを確認する(v0.3 §Step8の
	 * REST冪等性判定).
	 *
	 * @return void
	 */
	public function test_find_active_run_id_returns_id_when_running() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-09 11:55:00',
				'status'      => 'running',
				'run_trigger' => 'rest',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertSame( 1, $repository->find_active_run_id() );
	}
}
