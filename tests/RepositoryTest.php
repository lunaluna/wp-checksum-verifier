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
	 * reserve_run() が active run の無い状態で新規 running 行を insert し、
	 * その run_id を返すことを確認する(引数省略時の既定 = 同期・running).
	 *
	 * @return void
	 */
	public function test_reserve_run_inserts_running_row_by_default() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$reservation = $repository->reserve_run();

		$this->assertSame( 1, $reservation['run_id'] );
		$this->assertSame( 'running', $reservation['status'] );
		$this->assertFalse( $reservation['active'] );
		$this->assertFalse( $reservation['lock_failed'] );

		$row = $wpdb->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'running', $row['status'] );
		$this->assertSame( '2026-09-08 12:00:00', $row['started_at'] );
		$this->assertSame( 'manual', $row['run_trigger'] );
		$this->assertSame( 'sync', $row['runner'] );
	}

	/**
	 * run_trigger / runner / initial_status を明示指定した場合はその値が使われることを確認する.
	 *
	 * @return void
	 */
	public function test_reserve_run_accepts_explicit_trigger_runner_and_initial_status() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$reservation = $repository->reserve_run(
			array(
				'run_trigger'    => 'cli',
				'runner'         => 'async',
				'initial_status' => 'queued',
			)
		);

		$this->assertSame( 'queued', $reservation['status'] );

		$row = $wpdb->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( 'cli', $row['run_trigger'] );
		$this->assertSame( 'async', $row['runner'] );
	}

	/**
	 * 既に active(queued または running)な run がある場合、新規行を作らず
	 * その run_id を `active: true` で返すことを確認する(同時受付の直列化).
	 *
	 * @return void
	 */
	public function test_reserve_run_returns_existing_active_run_without_creating_new_row() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 11:59:00',
				'status'      => 'running',
				'run_trigger' => 'rest',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$reservation = $repository->reserve_run();

		$this->assertSame( 1, $reservation['run_id'] );
		// v0.3.1 §Step4: active 時も実際の status(この場合 running)を返す.
		$this->assertSame( 'running', $reservation['status'] );
		$this->assertTrue( $reservation['active'] );
		$this->assertFalse( $reservation['lock_failed'] );
		$this->assertCount( 1, $wpdb->rows['wp_wpcv_runs'] );
	}

	/**
	 * advisory lock(GET_LOCK())の取得に失敗した場合、run を作成せず
	 * `lock_failed: true` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_reserve_run_returns_lock_failed_when_get_lock_fails() {
		$wpdb                 = new WPCV_Test_Fake_WPDB();
		$wpdb->get_var_return = '0';
		$repository           = $this->make_repository( $wpdb );

		$reservation = $repository->reserve_run();

		$this->assertTrue( $reservation['lock_failed'] );
		$this->assertNull( $reservation['run_id'] );
		$this->assertArrayNotHasKey( 'wp_wpcv_runs', $wpdb->rows );
	}

	/**
	 * reserve_run() が GET_LOCK()/RELEASE_LOCK() の両方を(成功時も)呼ぶことを確認する
	 * (RELEASE_LOCK() を finally で呼ぶことのリーク防止確認).
	 *
	 * @return void
	 */
	public function test_reserve_run_releases_lock_after_success() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$repository->reserve_run();

		$this->assertCount( 1, $wpdb->get_var_calls );
		$this->assertCount( 1, $wpdb->query_calls );
		$this->assertStringContainsString( 'GET_LOCK', $wpdb->get_var_calls[0] );
		$this->assertStringContainsString( 'RELEASE_LOCK', $wpdb->query_calls[0] );
	}

	/**
	 * mark_queued_running() が queued 行だけを running へ更新し、
	 * true を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_mark_queued_running_transitions_queued_row() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 12:00:00',
				'status'      => 'queued',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertTrue( $repository->mark_queued_running( 1 ) );
		$this->assertSame( 'running', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
	}

	/**
	 * mark_queued_running() は対象行が既に queued でなければ(stale sweep に
	 * 先を越された等)何もせず false を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_mark_queued_running_returns_false_when_not_queued() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 08:00:00',
				'status'      => 'failed',
				'run_trigger' => 'cron',
				'runner'      => 'async',
				'notes'       => 'stale sweep により failed 化済み',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertFalse( $repository->mark_queued_running( 1 ) );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
	}

	/**
	 * mark_run_failed() が running 行を failed へ更新し、notes と finished_at を
	 * 記録することを確認する.
	 *
	 * @return void
	 */
	public function test_mark_run_failed_updates_running_row() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$reservation = $repository->reserve_run();

		$this->assertTrue( $repository->mark_run_failed( $reservation['run_id'], 'RuntimeException: boom' ) );

		$row = $wpdb->rows['wp_wpcv_runs'][ $reservation['run_id'] ];
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( '2026-09-08 12:00:00', $row['finished_at'] );
		$this->assertSame( 'RuntimeException: boom', $row['notes'] );
	}

	/**
	 * mark_run_failed() は対象行が既に running でなければ何もせず false を返すことを確認する
	 * (プラン§P1「stale化後に旧ワーカーが成功で上書きできる」の対策確認).
	 *
	 * @return void
	 */
	public function test_mark_run_failed_returns_false_when_not_running() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 08:00:00',
				'status'      => 'success',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertFalse( $repository->mark_run_failed( 1, 'should not apply' ) );
		$this->assertSame( 'success', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
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

		$run_id = $repository->reserve_run()['run_id'];

		$updated = $repository->finish_run(
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

		$this->assertTrue( $updated );

		$row = $wpdb->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'partial', $row['status'] );
		$this->assertSame( '2026-09-08 12:00:00', $row['finished_at'] );
		$this->assertSame( 3, $row['targets_total'] );
		$this->assertSame( 2, $row['targets_verified'] );
		$this->assertSame( 1, $row['targets_unverifiable'] );
		$this->assertSame( 5, $row['findings_total'] );
		// reserve_run() 時点の run_trigger が上書きされず残っていることも確認する.
		$this->assertSame( 'manual', $row['run_trigger'] );
	}

	/**
	 * finish_run() が対象行が既に running でなければ何も更新せず false を返すことを確認する
	 * (プラン§P1「stale化後に旧ワーカーが成功で上書きできる」の対策確認).
	 *
	 * @return void
	 */
	public function test_finish_run_does_not_overwrite_non_running_row() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 08:00:00',
				'status'      => 'failed',
				'run_trigger' => 'cron',
				'runner'      => 'async',
				'finished_at' => '2026-09-08 11:30:00',
				'notes'       => 'sweep_stale_running() により stale な run として検知し failed 化しました.',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$updated = $repository->finish_run(
			1,
			array(
				'status'               => 'success',
				'targets_total'        => 1,
				'targets_verified'     => 1,
				'targets_unverifiable' => 0,
				'targets_failed'       => 0,
				'findings_total'       => 0,
			)
		);

		$this->assertFalse( $updated );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
	}

	/**
	 * finish_run() が指定した run 以外の行を更新しないことを確認する.
	 *
	 * @return void
	 */
	public function test_finish_run_does_not_touch_other_rows() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 12:00:00',
				'status'      => 'running',
				'run_trigger' => 'manual',
				'runner'      => 'sync',
			)
		);
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 12:00:00',
				'status'      => 'running',
				'run_trigger' => 'manual',
				'runner'      => 'sync',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$first_run_id  = 1;
		$second_run_id = 2;

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
	 * 閾値より古い `queued` 行も(`running` と同様に) `failed` に更新されることを確認する
	 * (v0.3.1 §Step1: enqueue はできたが Action Scheduler ワーカーが拾わなかった
	 * run も永久ブロック要因になるため、`queued` もスイープ対象に拡張).
	 *
	 * @return void
	 */
	public function test_sweep_stale_running_marks_old_queued_row_as_failed() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 11:00:00',
				'status'      => 'queued',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$swept = $repository->sweep_stale_running( 30 );

		$this->assertSame( 1, $swept );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
	}

	/**
	 * stale sweep に先を越されて failed 化された run を、後から戻ってきた
	 * 旧ワーカーが `finish_run()` で成功へ上書きできないことを確認する
	 * (プラン§P1「stale化後に旧ワーカーが成功で上書きできる」の統合的な確認.
	 * `test_finish_run_does_not_overwrite_non_running_row()` の実際のスイープ経由版).
	 *
	 * @return void
	 */
	public function test_stale_swept_run_cannot_be_overwritten_by_late_finish_run() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 08:00:00',
				'status'      => 'running',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertSame( 1, $repository->sweep_stale_running( 30 ) );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][1]['status'] );

		// 旧ワーカーが検証を終え、今頃になって finish_run() を呼ぶ状況を模す.
		$updated = $repository->finish_run(
			1,
			array(
				'status'               => 'success',
				'targets_total'        => 1,
				'targets_verified'     => 1,
				'targets_unverifiable' => 0,
				'targets_failed'       => 0,
				'findings_total'       => 0,
			)
		);

		$this->assertFalse( $updated );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
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

	/**
	 * `queued` 状態の run も active として id を返すことを確認する(v0.3.1 §Step1).
	 *
	 * @return void
	 */
	public function test_find_active_run_id_returns_id_when_queued() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-09 11:55:00',
				'status'      => 'queued',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertSame( 1, $repository->find_active_run_id() );
	}
}
