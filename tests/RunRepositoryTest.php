<?php
/**
 * WPCV_Run_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wpcv_runs` の DB 永続化(§4.2: Repository 層。v0.4.0 §Step1で`WPCV_Repository`
 * から分割)のテスト. 分割前の `RepositoryTest` からrun関連のテストのみを移植した.
 */
class RunRepositoryTest extends TestCase {

	/**
	 * 固定時刻を返す Repository を作る.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @return WPCV_Run_Repository
	 */
	private function make_repository( WPCV_Test_Fake_WPDB $wpdb ) {
		return new WPCV_Run_Repository(
			$wpdb,
			static function () {
				return '2026-09-08 12:00:00';
			}
		);
	}

	/**
	 * reserve_run() が active run の無い状態で新規 planning 行を insert し、
	 * その run_id を返すことを確認する(引数省略時の既定 = 同期・planning。
	 * v0.4.0コードレビューCR-01是正で既定を`running`から`planning`へ変更した).
	 *
	 * @return void
	 */
	public function test_reserve_run_inserts_planning_row_by_default() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$reservation = $repository->reserve_run();

		$this->assertSame( 1, $reservation['run_id'] );
		$this->assertSame( 'planning', $reservation['status'] );
		$this->assertFalse( $reservation['active'] );
		$this->assertFalse( $reservation['lock_failed'] );

		$row = $wpdb->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'planning', $row['status'] );
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
	 * mark_queued_planning() が queued 行だけを planning へ更新し、
	 * true を返すことを確認する(v0.4.0コードレビューCR-01是正で
	 * `mark_queued_running()`から改名・遷移先を`planning`へ変更).
	 *
	 * @return void
	 */
	public function test_mark_queued_planning_transitions_queued_row() {
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

		$this->assertTrue( $repository->mark_queued_planning( 1 ) );
		$this->assertSame( 'planning', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
	}

	/**
	 * mark_queued_planning() は対象行が既に queued でなければ(stale sweep に
	 * 先を越された等)何もせず false を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_mark_queued_planning_returns_false_when_not_queued() {
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

		$this->assertFalse( $repository->mark_queued_planning( 1 ) );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
	}

	/**
	 * mark_planning_running() が planning 行だけを running へ更新し、true を
	 * 返すことを確認する(v0.4.0コードレビューCR-01是正で新設。
	 * `WPCV_Run_Starter::plan_and_save()`がtarget_runsの保存直後に呼ぶ遷移).
	 *
	 * @return void
	 */
	public function test_mark_planning_running_transitions_planning_row() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 12:00:00',
				'status'      => 'planning',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertTrue( $repository->mark_planning_running( 1 ) );
		$this->assertSame( 'running', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
	}

	/**
	 * mark_planning_running() は対象行が既に planning でなければ(stale sweep・
	 * deadline超過sweepに先を越された等)何もせず false を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_mark_planning_running_returns_false_when_not_planning() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 08:00:00',
				'status'      => 'aborted',
				'run_trigger' => 'cron',
				'runner'      => 'async',
				'notes'       => 'deadline超過により aborted 化済み',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertFalse( $repository->mark_planning_running( 1 ) );
		$this->assertSame( 'aborted', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
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
		$repository->mark_planning_running( $reservation['run_id'] );

		$this->assertTrue( $repository->mark_run_failed( $reservation['run_id'], 'RuntimeException: boom' ) );

		$row = $wpdb->rows['wp_wpcv_runs'][ $reservation['run_id'] ];
		$this->assertSame( 'failed', $row['status'] );
		$this->assertSame( '2026-09-08 12:00:00', $row['finished_at'] );
		$this->assertSame( 'RuntimeException: boom', $row['notes'] );
	}

	/**
	 * mark_run_failed() が(reserve_run()の既定である)planning 行も failed へ
	 * 更新できることを確認する(v0.4.0コードレビューCR-01是正:
	 * `update_active_run()`が3段階CASに拡張されたことの直接確認. `WPCV_Run_Starter::
	 * plan_and_save()`が列挙・保存中に例外を投げた場合の実際の状態に対応する).
	 *
	 * @return void
	 */
	public function test_mark_run_failed_updates_planning_row() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$reservation = $repository->reserve_run();

		$this->assertSame( 'planning', $wpdb->rows['wp_wpcv_runs'][ $reservation['run_id'] ]['status'] );
		$this->assertTrue( $repository->mark_run_failed( $reservation['run_id'], 'RuntimeException: boom during planning' ) );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][ $reservation['run_id'] ]['status'] );
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
	 * mark_run_aborted() が active な状態(running/queued/planning)いずれからも
	 * abortedへ更新できることを確認する(v0.4.0コードレビューCR-01是正:
	 * `mark_run_failed()`と共有する`update_active_run()`の3段階CASを、
	 * `mark_run_aborted()`側からも直接確認する。それまでは`ChunkDispatcherTest`
	 * 経由の間接的な確認しか無かった).
	 *
	 * @return void
	 */
	public function test_mark_run_aborted_updates_row_from_any_active_status() {
		foreach ( array( 'running', 'queued', 'planning' ) as $status ) {
			$wpdb = new WPCV_Test_Fake_WPDB();
			$wpdb->insert(
				'wp_wpcv_runs',
				array(
					'started_at'  => '2026-09-08 08:00:00',
					'status'      => $status,
					'run_trigger' => 'cron',
					'runner'      => 'async',
				)
			);

			$repository = $this->make_repository( $wpdb );

			$this->assertTrue( $repository->mark_run_aborted( 1, 'deadline exceeded' ), "status={$status}" );
			$this->assertSame( 'aborted', $wpdb->rows['wp_wpcv_runs'][1]['status'], "status={$status}" );
			$this->assertSame( 'deadline exceeded', $wpdb->rows['wp_wpcv_runs'][1]['notes'], "status={$status}" );
		}
	}

	/**
	 * mark_run_aborted() は対象行が既にterminal状態なら何もせずfalseを返す
	 * ことを確認する.
	 *
	 * @return void
	 */
	public function test_mark_run_aborted_returns_false_when_terminal() {
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

		$this->assertFalse( $repository->mark_run_aborted( 1, 'should not apply' ) );
		$this->assertSame( 'success', $wpdb->rows['wp_wpcv_runs'][1]['status'] );
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
		// finish_run() は running 状態のみを対象にするため、reserve_run() の既定
		// (v0.4.0コードレビューCR-01是正で `planning`)から遷移させておく.
		$repository->mark_planning_running( $run_id );

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
	 * 古い `planning` の run も stale として `failed` に更新することを確認する
	 * (v0.4.0コードレビューCR-01是正: `active_status_sql_list()`のdocblock参照).
	 *
	 * @return void
	 */
	public function test_sweep_stale_running_marks_old_planning_row_as_failed() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 11:00:00',
				'status'      => 'planning',
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

	/**
	 * `planning` 状態の run も active として id を返すことを確認する(v0.4.0
	 * コードレビューCR-01是正)。
	 *
	 * `find_active_run()`/`sweep_stale_running()` は本来 `WPCV_Run_Status::ACTIVE`
	 * と同期しているべきSQLのIN句を独自に持っており、`planning`追加時に
	 * 片方だけ更新して見落とす事故が実際に起きかけた(`active_status_sql_list()`
	 * のdocblock参照)。このテストはその回帰を検出する.
	 *
	 * @return void
	 */
	public function test_find_active_run_id_returns_id_when_planning() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-09 11:55:00',
				'status'      => 'planning',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertSame( 1, $repository->find_active_run_id() );
	}

	/**
	 * `find_most_recent_run()` が run 行を1件も持たない場合に `null` を返すことを
	 * 確認する(v0.4.0 §Step6).
	 *
	 * @return void
	 */
	public function test_find_most_recent_run_returns_null_when_no_runs() {
		$repository = $this->make_repository( new WPCV_Test_Fake_WPDB() );

		$this->assertNull( $repository->find_most_recent_run() );
	}

	/**
	 * `find_most_recent_run()` が最も id の大きい(最後に作成された)行を返すことを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_find_most_recent_run_returns_highest_id_row() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-07 03:00:00',
				'status'      => 'success',
				'run_trigger' => 'rest',
				'runner'      => 'sync',
			)
		);
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 03:00:00',
				'status'      => 'failed',
				'run_trigger' => 'rest',
				'runner'      => 'sync',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$run = $repository->find_most_recent_run();

		$this->assertSame( 2, $run['id'] );
		$this->assertSame( 'failed', $run['status'] );
	}

	/**
	 * `find_most_recent_terminal_run()` が terminal 状態の行が1件も無ければ
	 * `null` を返すことを確認する(v0.4.0 §Step7).
	 *
	 * @return void
	 */
	public function test_find_most_recent_terminal_run_returns_null_when_none_terminal() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 03:00:00',
				'status'      => 'running',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertNull( $repository->find_most_recent_terminal_run() );
	}

	/**
	 * `find_most_recent_terminal_run()` が、最新行がactiveでも、それより古い
	 * terminal行を正しく返すことを確認する(`find_most_recent_run()` との違いの確認).
	 *
	 * @return void
	 */
	public function test_find_most_recent_terminal_run_skips_active_latest_row() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-07 03:00:00',
				'finished_at' => '2026-09-07 03:05:00',
				'status'      => 'success',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 03:00:00',
				'status'      => 'running',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$most_recent_terminal = $repository->find_most_recent_terminal_run();
		$this->assertSame( 1, $most_recent_terminal['id'] );
		$this->assertSame( 'success', $most_recent_terminal['status'] );

		// 対照として find_most_recent_run() はactiveな2件目をそのまま返すことも確認する.
		$this->assertSame( 2, $repository->find_most_recent_run()['id'] );
	}

	/**
	 * `reserve_due_run()` が active run(`queued`/`running`)を返すとき、due判定を
	 * 行わず(=まだ due でなくても)既存 run をそのまま返すことを確認する
	 * (v0.4.0 §Step6: 5分間隔の外部cronが連打しても進行中のrunがそのまま
	 * 前進し続けることの確認).
	 *
	 * @return void
	 */
	public function test_reserve_due_run_returns_active_run_regardless_of_due_time() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 11:00:00',
				'status'      => 'running',
				'run_trigger' => 'rest',
				'runner'      => 'sync',
			)
		);

		$repository = $this->make_repository( $wpdb );

		// 設定実行時刻(23:00)はまだ過ぎていないが、active runがあればdue判定より
		// 優先してそれを返す.
		$reservation = $repository->reserve_due_run( 23, 0 );

		$this->assertSame( 1, $reservation['run_id'] );
		$this->assertSame( 'running', $reservation['status'] );
		$this->assertTrue( $reservation['active'] );
		$this->assertFalse( $reservation['created'] );
		$this->assertCount( 1, $wpdb->rows['wp_wpcv_runs'] );
	}

	/**
	 * `reserve_due_run()` が、active runが無く設定実行時刻を過ぎ、本日分の run が
	 * まだ無い場合に、`scheduled_for` 付きで新規 run を作成することを確認する.
	 *
	 * @return void
	 */
	public function test_reserve_due_run_creates_run_when_due_and_not_yet_created_today() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		// 固定 now は 2026-09-08 12:00:00. 設定実行時刻 11:00 は既に過ぎている.
		$reservation = $repository->reserve_due_run(
			11,
			0,
			array(
				'run_trigger' => 'rest',
				'runner'      => 'sync',
			)
		);

		$this->assertSame( 1, $reservation['run_id'] );
		// v0.4.0コードレビューCR-01是正で `reserve_due_run()` の既定 initial_status も
		// `running` から `planning` へ変更した(`reserve_run()` と同じ理由).
		$this->assertSame( 'planning', $reservation['status'] );
		$this->assertFalse( $reservation['active'] );
		$this->assertTrue( $reservation['created'] );

		$row = $wpdb->rows['wp_wpcv_runs'][1];
		$this->assertSame( '2026-09-08 11:00:00', $row['scheduled_for'] );
		$this->assertSame( 'rest', $row['run_trigger'] );
	}

	/**
	 * `reserve_due_run()` が、設定実行時刻をまだ過ぎていない場合は何も作成せず
	 * `run_id: null` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_reserve_due_run_does_nothing_when_not_yet_due() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		// 固定 now は 2026-09-08 12:00:00. 設定実行時刻 13:00 はまだ来ていない.
		$reservation = $repository->reserve_due_run( 13, 0 );

		$this->assertNull( $reservation['run_id'] );
		$this->assertFalse( $reservation['active'] );
		$this->assertFalse( $reservation['created'] );
		$this->assertFalse( $reservation['lock_failed'] );
		$this->assertArrayNotHasKey( 'wp_wpcv_runs', $wpdb->rows );
	}

	/**
	 * `reserve_due_run()` が、本日分の run が(ステータスを問わず)既に存在する
	 * 場合は2件目を作成しないことを確認する(v0.4.0 §Step6: 5分間隔の外部cronが
	 * 連打しても同一日に1件しか作られないことの確認).
	 *
	 * @return void
	 */
	public function test_reserve_due_run_does_not_create_second_run_for_same_day() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'    => '2026-09-08 11:00:05',
				'finished_at'   => '2026-09-08 11:00:10',
				'status'        => 'success',
				'run_trigger'   => 'rest',
				'runner'        => 'sync',
				'scheduled_for' => '2026-09-08 11:00:00',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$reservation = $repository->reserve_due_run( 11, 0 );

		$this->assertNull( $reservation['run_id'] );
		$this->assertFalse( $reservation['created'] );
		$this->assertCount( 1, $wpdb->rows['wp_wpcv_runs'] );
	}

	/**
	 * `reserve_due_run()` も `reserve_run()` と同じく advisory lock の取得に失敗
	 * した場合 `lock_failed: true` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_reserve_due_run_returns_lock_failed_when_get_lock_fails() {
		$wpdb                 = new WPCV_Test_Fake_WPDB();
		$wpdb->get_var_return = '0';
		$repository           = $this->make_repository( $wpdb );

		$reservation = $repository->reserve_due_run( 11, 0 );

		$this->assertTrue( $reservation['lock_failed'] );
		$this->assertNull( $reservation['run_id'] );
		$this->assertArrayNotHasKey( 'wp_wpcv_runs', $wpdb->rows );
	}

	/**
	 * `find_all()` が新しい(id最大の)行から順に返すことを確認する(v0.4.0 §Step9:
	 * `WPCV_Page_Run_History` の実行履歴一覧画面向け).
	 *
	 * @return void
	 */
	public function test_find_all_returns_rows_newest_first() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-07 03:00:00',
				'status'      => 'success',
				'run_trigger' => 'cron',
				'runner'      => 'sync',
			)
		);
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 03:00:00',
				'status'      => 'failed',
				'run_trigger' => 'cron',
				'runner'      => 'sync',
			)
		);
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-09 03:00:00',
				'status'      => 'partial',
				'run_trigger' => 'cron',
				'runner'      => 'sync',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$result = $repository->find_all();

		$this->assertSame( 3, $result['total'] );
		$this->assertSame( array( 3, 2, 1 ), array_column( $result['rows'], 'id' ) );
	}

	/**
	 * `find_all()` の `page`/`per_page` がpaginationとして機能することを確認する.
	 *
	 * @return void
	 */
	public function test_find_all_paginates() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		for ( $i = 0; $i < 5; $i++ ) {
			$wpdb->insert(
				'wp_wpcv_runs',
				array(
					'started_at'  => '2026-09-07 03:00:00',
					'status'      => 'success',
					'run_trigger' => 'cron',
					'runner'      => 'sync',
				)
			);
		}

		$repository = $this->make_repository( $wpdb );

		$page1 = $repository->find_all(
			array(
				'page'     => 1,
				'per_page' => 2,
			)
		);
		$page2 = $repository->find_all(
			array(
				'page'     => 2,
				'per_page' => 2,
			)
		);

		$this->assertSame( 5, $page1['total'] );
		$this->assertSame( array( 5, 4 ), array_column( $page1['rows'], 'id' ) );
		$this->assertSame( array( 3, 2 ), array_column( $page2['rows'], 'id' ) );
	}

	/**
	 * `find_all()` が run が1件も無ければ空配列と total 0 を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_find_all_returns_empty_when_no_runs() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$result = $repository->find_all();

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( array(), $result['rows'] );
	}

	/**
	 * `find_most_recent_by_trigger()` が指定した run_trigger のうち最も新しい行を
	 * 返すことを確認する(v0.4.0 §Step10: 状態パネルの「最後にCLIで実行した時刻」).
	 *
	 * @return void
	 */
	public function test_find_most_recent_by_trigger_returns_highest_id_matching_row() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-07 03:00:00',
				'status'      => 'success',
				'run_trigger' => 'cli',
				'runner'      => 'sync',
			)
		);
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 03:00:00',
				'status'      => 'success',
				'run_trigger' => 'cron',
				'runner'      => 'sync',
			)
		);
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-09 03:00:00',
				'status'      => 'success',
				'run_trigger' => 'cli',
				'runner'      => 'sync',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$run = $repository->find_most_recent_by_trigger( 'cli' );

		$this->assertSame( 3, $run['id'] );
	}

	/**
	 * `find_most_recent_by_trigger()` が該当する run が無ければ `null` を返す
	 * ことを確認する.
	 *
	 * @return void
	 */
	public function test_find_most_recent_by_trigger_returns_null_when_none_match() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-07 03:00:00',
				'status'      => 'success',
				'run_trigger' => 'cron',
				'runner'      => 'sync',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$this->assertNull( $repository->find_most_recent_by_trigger( 'cli' ) );
	}
}
