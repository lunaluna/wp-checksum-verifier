<?php
/**
 * WPCV_Run_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wpcv_runs` の DB 永続化(§4.2: Repository 層。v0.4.0 §Step1で`WPCV_Repository`
 * から分割)のテスト. 分割前の `RepositoryTest` からrun関連のテストのみを移植した.
 */
class RunRepositoryTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する(`wpcv_run_terminated`フックの記録用).
	 *
	 * `_wpcv_test_added_actions` はここで unset しない。他クラスのファイルが
	 * require された時点で1回だけ `add_action()` を呼んで登録している
	 * (`WPCV_Chunk_Dispatcher::HOOK`/`WPCV_Runner_Async::HOOK` 等)ため、ここで
	 * 消すとテストスイート全体を通しで実行したときにその登録が失われ、
	 * 他のテストファイル(`RunnerAsyncTest`等)を壊してしまう(実際に踏んだ事故).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_do_action_calls'], $GLOBALS['_wpcv_test_options'] );
	}

	/**
	 * タイムゾーンのテストが `_wpcv_test_options` を残さないよう、各テストの後に掃除する.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_wpcv_test_options'] );
		parent::tearDown();
	}

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
	 * reserve_run() が、run行の `$wpdb->insert()` がSQLエラーで `false` を返した
	 * 場合に `RuntimeException` を投げ、かつ advisory lock は `finally` で確実に
	 * 解放する(`RELEASE_LOCK` を呼ぶ)ことを確認する(v0.4.0コードレビュー
	 * CR-03是正: insert失敗を確認せず `insert_id` をそのまま返すと、直前の
	 * 成功したinsertのidを誤って新規runのidとして返してしまう不具合への対策).
	 *
	 * @return void
	 */
	public function test_reserve_run_throws_and_releases_lock_when_insert_fails() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$wpdb->insert_should_fail = true;

		$this->expectException( RuntimeException::class );

		try {
			$repository->reserve_run();
		} finally {
			$this->assertArrayNotHasKey( 'wp_wpcv_runs', $wpdb->rows );
			$this->assertStringContainsString( 'RELEASE_LOCK', $wpdb->query_calls[0] );
		}
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
		// v0.5後半 §Step12: failed/abortedになったrunは差分処理の対象にしない(§3.1「NULL→skipped」).
		$this->assertSame( 'skipped', $row['diff_status'] );
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
			// v0.5後半 §Step12: failed/abortedになったrunは差分処理の対象にしない.
			$this->assertSame( 'skipped', $wpdb->rows['wp_wpcv_runs'][1]['diff_status'], "status={$status}" );
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
		// v0.5後半 §Step10: success/partial を書く同じUPDATEでdiff_statusに
		// pendingを書く(差分処理〔Step12以降〕がclaimできる起点にするため).
		$this->assertSame( 'pending', $row['diff_status'] );
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
				'notes'       => 'stale な run として検知し failed 化しました.',
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
	 * `find_active_run()`はかつて`WPCV_Run_Status::ACTIVE`と同期しているべき
	 * SQLのIN句を(削除済みの`sweep_stale_running()`と)独自に持っており、
	 * `planning`追加時に片方だけ更新して見落とす事故が実際に起きかけた
	 * (`active_status_sql_list()`のdocblock参照)。このテストはその回帰を検出する.
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
	 * `find_previous_run()` が指定run未満で最大のidの行を返すことを確認する
	 * (v0.6 §Step7. statusは問わない).
	 *
	 * @return void
	 */
	public function test_find_previous_run_returns_closest_run_below_given_id() {
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
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-09 03:00:00',
				'status'      => 'running',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$previous = $repository->find_previous_run( 3 );

		$this->assertSame( 2, $previous['id'] );
		$this->assertSame( 'failed', $previous['status'] );
	}

	/**
	 * `find_previous_run()` が最初のrun(idが最小)に対しては `null` を返すことを
	 * 確認する(v0.6 §Step7).
	 *
	 * @return void
	 */
	public function test_find_previous_run_returns_null_for_first_run() {
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

		$repository = $this->make_repository( $wpdb );

		$this->assertNull( $repository->find_previous_run( 1 ) );
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
	 * 翌日の 00:00:00 ちょうどに予定された run は「当日分」に数えないことを確認する
	 * (コードレビュー指摘5で、日付部分の文字列比較から
	 * `scheduled_for >= 当日 00:00:00 AND scheduled_for < 翌日 00:00:00` の範囲指定に
	 * 変えたため、その境界を確かめる).
	 *
	 * @return void
	 */
	public function test_reserve_due_run_ignores_run_scheduled_at_next_midnight() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'status'        => 'success',
				'run_trigger'   => 'rest',
				'runner'        => 'sync',
				'scheduled_for' => '2026-09-09 00:00:00',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$reservation = $repository->reserve_due_run( 11, 0 );

		$this->assertTrue( $reservation['created'], '翌日分のrunは当日分に数えない' );
		$this->assertSame( '2026-09-08 11:00:00', $wpdb->rows['wp_wpcv_runs'][ $reservation['run_id'] ]['scheduled_for'] );
	}

	/**
	 * `now` を指定して `reserve_due_run()` 用のリポジトリを作る(現地暦日のテスト用).
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb      フェイク DB.
	 * @param string              $now_utc   固定する現在時刻(UTC. `Y-m-d H:i:s`).
	 * @return WPCV_Run_Repository
	 */
	private function make_repository_at( WPCV_Test_Fake_WPDB $wpdb, $now_utc ) {
		return new WPCV_Run_Repository(
			$wpdb,
			static function () use ( $now_utc ) {
				return $now_utc;
			}
		);
	}

	/**
	 * Asia/Tokyo で、現地の実行時刻(03:00)を過ぎていれば run を作り、`scheduled_for` は UTC で
	 * 保存することを確認する(プラン §4 #1・#12).
	 *
	 * @return void
	 */
	public function test_reserve_due_run_uses_site_timezone_for_due_time() {
		$GLOBALS['_wpcv_test_options']['timezone_string'] = 'Asia/Tokyo';

		// UTC 2026-09-07 19:00 = 現地 2026-09-08 04:00. 現地 03:00 は過ぎている(UTC 18:00 前日).
		$wpdb        = new WPCV_Test_Fake_WPDB();
		$repository  = $this->make_repository_at( $wpdb, '2026-09-07 19:00:00' );
		$reservation = $repository->reserve_due_run( 3, 0 );

		$this->assertTrue( $reservation['created'] );
		$this->assertSame( '2026-09-07 18:00:00', $wpdb->rows['wp_wpcv_runs'][ $reservation['run_id'] ]['scheduled_for'] );
	}

	/**
	 * 現地 0:00 から実行時刻までの間は run を作らないことを確認する. UTC の日付では前日・当日が
	 * 混ざる時間帯でも現地の暦日で判定する(プラン §4 #13).
	 *
	 * @return void
	 */
	public function test_reserve_due_run_does_not_create_before_local_due_time() {
		$GLOBALS['_wpcv_test_options']['timezone_string'] = 'Asia/Tokyo';

		// UTC 2026-09-07 16:00 = 現地 2026-09-08 01:00. 現地 03:00 はまだ来ていない.
		$wpdb        = new WPCV_Test_Fake_WPDB();
		$repository  = $this->make_repository_at( $wpdb, '2026-09-07 16:00:00' );
		$reservation = $repository->reserve_due_run( 3, 0 );

		$this->assertNull( $reservation['run_id'] );
		$this->assertArrayNotHasKey( 'wp_wpcv_runs', $wpdb->rows );
	}

	/**
	 * 同じ現地暦日の分が作成済みなら、UTC の日付が変わっていても2件目を作らないことを確認する(#12).
	 *
	 * @return void
	 */
	public function test_reserve_due_run_does_not_create_second_run_on_same_local_day() {
		$GLOBALS['_wpcv_test_options']['timezone_string'] = 'Asia/Tokyo';

		// 現地 2026-09-08 03:00 = UTC 2026-09-07 18:00 の分が作成済み.
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'status'        => 'success',
				'run_trigger'   => 'rest',
				'runner'        => 'sync',
				'scheduled_for' => '2026-09-07 18:00:00',
			)
		);

		// UTC 2026-09-08 10:00 = 現地 19:00(UTC の暦日は翌日になっている).
		$repository  = $this->make_repository_at( $wpdb, '2026-09-08 10:00:00' );
		$reservation = $repository->reserve_due_run( 3, 0 );

		$this->assertNull( $reservation['run_id'] );
		$this->assertCount( 1, $wpdb->rows['wp_wpcv_runs'] );
	}

	/**
	 * 現地の翌日の分は「当日分」に数えないことを確認する(現地暦日の終端の境界).
	 *
	 * @return void
	 */
	public function test_reserve_due_run_ignores_run_scheduled_on_next_local_day() {
		$GLOBALS['_wpcv_test_options']['timezone_string'] = 'Asia/Tokyo';

		// 現地 2026-09-09 00:00 = UTC 2026-09-08 15:00 ちょうど. 現地では翌日.
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'status'        => 'success',
				'run_trigger'   => 'rest',
				'runner'        => 'sync',
				'scheduled_for' => '2026-09-08 15:00:00',
			)
		);

		// 現地 2026-09-08 19:00 → 当日 03:00(UTC 2026-09-07 18:00)の分はまだ無い.
		$repository  = $this->make_repository_at( $wpdb, '2026-09-08 10:00:00' );
		$reservation = $repository->reserve_due_run( 3, 0 );

		$this->assertTrue( $reservation['created'] );
	}

	/**
	 * 夏時間の切り替え日(秋・1日が25時間)でも、2回ある時刻(01:30)は1回目に1件だけ作ることを確認する(#7).
	 *
	 * @return void
	 */
	public function test_reserve_due_run_creates_once_on_ambiguous_local_time() {
		$GLOBALS['_wpcv_test_options']['timezone_string'] = 'America/New_York';

		// 2026-11-01 01:30 は2回ある. 1回目 = EDT(UTC 05:30). 2回目 = EST(UTC 06:30).
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$first      = $this->make_repository_at( $wpdb, '2026-11-01 05:45:00' );
		$reserved_1 = $first->reserve_due_run( 1, 30 );
		$this->assertTrue( $reserved_1['created'] );
		$this->assertSame( '2026-11-01 05:30:00', $wpdb->rows['wp_wpcv_runs'][ $reserved_1['run_id'] ]['scheduled_for'] );

		// 1件目を完了扱いにする(active run があると、その run を返してしまうため).
		$wpdb->rows['wp_wpcv_runs'][ $reserved_1['run_id'] ]['status'] = 'success';

		// 2回目の 01:30(EST = UTC 06:30)を過ぎた後に呼んでも、もう作らない.
		$second     = $this->make_repository_at( $wpdb, '2026-11-01 06:45:00' );
		$reserved_2 = $second->reserve_due_run( 1, 30 );
		$this->assertNull( $reserved_2['run_id'] );
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

	// ------------------------------------------------------------------
	// v0.5後半 §Step12: `wpcv_run_terminated` フック
	// ------------------------------------------------------------------

	/**
	 * `mark_run_failed()`/`mark_run_aborted()`/`finish_run()` がそれぞれ実際に
	 * 更新できた場合に `wpcv_run_terminated` フックを1回だけ発火することを確認する
	 * (§3.4「実際に行を更新できたときだけ」・「状態遷移が成功した場所1か所に集める」).
	 *
	 * @return void
	 */
	public function test_terminal_transitions_fire_wpcv_run_terminated_hook() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_run_failed( $run_id, 'boom' );

		$this->assertSame(
			array( array( $run_id, 'failed' ) ),
			$GLOBALS['_wpcv_test_do_action_calls']['wpcv_run_terminated']
		);

		unset( $GLOBALS['_wpcv_test_do_action_calls'] );
		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_run_aborted( $run_id, 'deadline' );

		$this->assertSame(
			array( array( $run_id, 'aborted' ) ),
			$GLOBALS['_wpcv_test_do_action_calls']['wpcv_run_terminated']
		);

		unset( $GLOBALS['_wpcv_test_do_action_calls'] );
		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, array(
			'status'               => 'success',
			'targets_total'        => 1,
			'targets_verified'     => 1,
			'targets_unverifiable' => 0,
			'targets_failed'       => 0,
			'findings_total'       => 0,
		) );

		$this->assertSame(
			array( array( $run_id, 'success' ) ),
			$GLOBALS['_wpcv_test_do_action_calls']['wpcv_run_terminated']
		);
	}

	/**
	 * 対象行が既に active な状態のいずれでもない(=更新できない)場合、
	 * `wpcv_run_terminated` フックが発火しないことを確認する.
	 *
	 * @return void
	 */
	public function test_mark_run_failed_does_not_fire_hook_when_update_fails() {
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

		$this->assertFalse( $repository->mark_run_failed( 1, 'too late' ) );
		$this->assertArrayNotHasKey( 'wpcv_run_terminated', $GLOBALS['_wpcv_test_do_action_calls'] ?? array() );
	}

	// ------------------------------------------------------------------
	// v0.5後半 §Step12: 差分処理のclaim/lease(`claim_diff()`)
	// ------------------------------------------------------------------

	/**
	 * `diff_status = pending` の run を claim でき、`processing` へ遷移し
	 * `diff_owner`/`diff_lease_expires_at` が設定されることを確認する.
	 *
	 * @return void
	 */
	public function test_claim_diff_claims_pending_run() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );

		$result = $repository->claim_diff( $run_id, 'owner-a', 120, 5 );

		$this->assertTrue( $result['claimed'] );
		$this->assertFalse( $result['failed'] );
		$this->assertSame( 'processing', $result['run']['diff_status'] );
		$this->assertSame( 'owner-a', $result['run']['diff_owner'] );
		$this->assertSame( '2026-09-08 12:02:00', $result['run']['diff_lease_expires_at'] );
		$this->assertSame( 0, (int) ( $result['run']['diff_attempt_count'] ?? 0 ), '通常claimではattempt_countを消費しない' );
	}

	/**
	 * Lease有効中の `processing` を他のownerがclaimしようとしても失敗する
	 * (二重claimが弾かれる)ことを確認する.
	 *
	 * @return void
	 */
	public function test_claim_diff_rejects_second_claim_while_lease_is_active() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );

		$this->assertTrue( $repository->claim_diff( $run_id, 'owner-a' )['claimed'] );

		$second = $repository->claim_diff( $run_id, 'owner-b' );

		$this->assertFalse( $second['claimed'] );
		$this->assertFalse( $second['failed'] );
		$this->assertSame( 'owner-a', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_owner'], '他ownerに上書きされてはならない' );
	}

	/**
	 * Lease切れの `processing` を、試行上限内であれば別ownerが再claimできる
	 * (worker crashからの再開)ことを確認する.
	 *
	 * @return void
	 */
	public function test_claim_diff_reclaims_after_lease_expiry_within_attempt_limit() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );

		// lease有効期間を実質0秒にして、直後にlease切れの状態を作る.
		$this->assertTrue( $repository->claim_diff( $run_id, 'owner-a', -1, 5 )['claimed'] );

		$result = $repository->claim_diff( $run_id, 'owner-b', 120, 5 );

		$this->assertTrue( $result['claimed'] );
		$this->assertSame( 'owner-b', $result['run']['diff_owner'] );
		$this->assertSame( 1, (int) $result['run']['diff_attempt_count'], 'lease切れの検知1回分だけattempt_countが増える' );
	}

	/**
	 * Lease切れの検知が試行上限を超えたら `failed` へ倒し、`claimed` は
	 * 常にfalseになることを確認する.
	 *
	 * @return void
	 */
	public function test_claim_diff_marks_failed_after_exceeding_max_attempts() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );

		// 試行上限0回: 最初のclaimの後、lease切れの検知1回目で即座に上限超過になる.
		$this->assertTrue( $repository->claim_diff( $run_id, 'owner-a', -1, 0 )['claimed'] );

		$result = $repository->claim_diff( $run_id, 'owner-b', 120, 0 );

		$this->assertFalse( $result['claimed'] );
		$this->assertTrue( $result['failed'] );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
		$this->assertSame( 1, (int) $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_attempt_count'] );
	}

	/**
	 * `diff_status` が対象外(NULL・`done`・`skipped`等)の run は claim できず、
	 * 行にも一切触れないことを確認する.
	 *
	 * @return void
	 */
	public function test_claim_diff_returns_not_claimable_when_not_applicable() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-08 08:00:00',
				'status'      => 'success',
				'run_trigger' => 'cron',
				'runner'      => 'async',
				'diff_status' => 'done',
			)
		);

		$repository = $this->make_repository( $wpdb );

		$result = $repository->claim_diff( 1, 'owner-a' );

		$this->assertFalse( $result['claimed'] );
		$this->assertFalse( $result['failed'] );
		$this->assertSame( 'done', $wpdb->rows['wp_wpcv_runs'][1]['diff_status'] );
	}

	// ------------------------------------------------------------------
	// v0.5後半 §Step12: `finalize_diff_chunk()`/`finalize_diff_alerting()`
	// ------------------------------------------------------------------

	/**
	 * 未完了のchunk確定が `pending` へ戻し、`diff_cursor` を保存し、
	 * `diff_attempt_count` を変えないことを確認する(正常なyield).
	 *
	 * @return void
	 */
	public function test_finalize_diff_chunk_incomplete_reverts_to_pending_with_cursor() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );
		$repository->claim_diff( $run_id, 'owner-a' );

		$cursor = '{"target_run_id":5,"pass":1,"last_id":42}';
		$ok     = $repository->finalize_diff_chunk( $run_id, 'owner-a', $cursor, false );

		$this->assertTrue( $ok );
		$row = $wpdb->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'pending', $row['diff_status'] );
		$this->assertNull( $row['diff_owner'] );
		$this->assertNull( $row['diff_lease_expires_at'] );
		$this->assertSame( $cursor, $row['diff_cursor'] );
		$this->assertSame( 0, (int) ( $row['diff_attempt_count'] ?? 0 ) );
	}

	/**
	 * 完了時のchunk確定が `alerting` へ進め、件数(new/resolved/continuing)を
	 * 書き込み、`diff_cursor` をクリアすることを確認する.
	 *
	 * @return void
	 */
	public function test_finalize_diff_chunk_complete_advances_to_alerting_with_counts() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );
		$repository->claim_diff( $run_id, 'owner-a' );

		$ok = $repository->finalize_diff_chunk(
			$run_id,
			'owner-a',
			null,
			true,
			array(
				'new'        => 3,
				'resolved'   => 1,
				'continuing' => 2,
			)
		);

		$this->assertTrue( $ok );
		$row = $wpdb->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'alerting', $row['diff_status'] );
		$this->assertNull( $row['diff_owner'] );
		$this->assertNull( $row['diff_cursor'] );
		$this->assertSame( 3, (int) $row['findings_new'] );
		$this->assertSame( 1, (int) $row['findings_resolved'] );
		$this->assertSame( 2, (int) $row['findings_continuing'] );
	}

	/**
	 * `diff_owner` が一致しない(既に別ownerに再claimされた)確定の書き込みが
	 * fencingにより静かに無視される(falseを返すだけ)ことを確認する.
	 *
	 * @return void
	 */
	public function test_finalize_diff_chunk_fencing_rejects_stale_owner() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );
		$repository->claim_diff( $run_id, 'owner-a' );

		$ok = $repository->finalize_diff_chunk( $run_id, 'owner-stale', null, false );

		$this->assertFalse( $ok );
		$this->assertSame( 'processing', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
		$this->assertSame( 'owner-a', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_owner'] );
	}

	/**
	 * `finalize_diff_alerting()` が `alerting` を `done` へ進めることを確認する.
	 *
	 * @return void
	 */
	public function test_finalize_diff_alerting_advances_to_done() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );
		$repository->claim_diff( $run_id, 'owner-a' );
		$repository->finalize_diff_chunk( $run_id, 'owner-a', null, true, array( 'new' => 0, 'resolved' => 0, 'continuing' => 0 ) );
		// `alerting`はlease切れ相当(finalize_diff_chunk()参照)のためclaim_diff()で
		// 再claimできる. `dispatch_diff()`が実際に行う流れと同じ.
		$claim = $repository->claim_diff( $run_id, 'owner-b' );

		$this->assertTrue( $claim['claimed'] );
		$this->assertSame( 'alerting', $claim['run']['diff_status'] );
		$this->assertTrue( $repository->finalize_diff_alerting( $run_id, 'owner-b' ) );
		$this->assertSame( 'done', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
	}

	/**
	 * `finalize_diff_alerting()` が `alerting` 以外の run には何もしないことを確認する.
	 *
	 * @return void
	 */
	public function test_finalize_diff_alerting_no_ops_when_not_alerting() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );

		$this->assertFalse( $repository->finalize_diff_alerting( $run_id, 'owner-a' ) );
		$this->assertSame( 'pending', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
	}

	/**
	 * `finalize_diff_alerting()` は `diff_owner` が一致しない(既に別ownerに
	 * 再claimされた)場合、fencingにより静かに無視される(falseを返すだけ)ことを
	 * 確認する(v0.5後半 §Step14c. §3.1直下の注記「書き込みはすべてdiff_ownerを
	 * WHEREに含める」).
	 *
	 * @return void
	 */
	public function test_finalize_diff_alerting_fencing_rejects_stale_owner() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );
		$repository->claim_diff( $run_id, 'owner-a' );
		$repository->finalize_diff_chunk( $run_id, 'owner-a', null, true, array( 'new' => 0, 'resolved' => 0, 'continuing' => 0 ) );
		$repository->claim_diff( $run_id, 'owner-b' );

		$this->assertFalse( $repository->finalize_diff_alerting( $run_id, 'owner-stale' ) );
		$this->assertSame( 'alerting', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
	}

	/**
	 * `record_alert_result()`は、`alerting`をclaim中の正しいownerからの書き込み
	 * だけを反映することを確認する(コードレビュー指摘3で追加したfencing).
	 *
	 * @return void
	 */
	public function test_record_alert_result_writes_only_for_current_owner() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );
		$repository->claim_diff( $run_id, 'owner-a' );
		$repository->finalize_diff_chunk( $run_id, 'owner-a', null, true, array( 'new' => 0, 'resolved' => 0, 'continuing' => 0 ) );
		$repository->claim_diff( $run_id, 'owner-b' );

		$this->assertFalse( $repository->record_alert_result( $run_id, 'owner-stale', 'failed', 'boom' ) );
		$this->assertNull( $wpdb->rows['wp_wpcv_runs'][ $run_id ]['alert_status'] ?? null, '別ownerの結果は記録されない' );

		$this->assertTrue( $repository->record_alert_result( $run_id, 'owner-b', 'sent' ) );
		$this->assertSame( 'sent', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['alert_status'] );
		$this->assertSame( '2026-09-08 12:00:00', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['alert_attempted_at'] );
	}

	/**
	 * レビューで指摘された順序の再現: Aの送信中にlease切れ → Bが再claimして
	 * `sent`を記録し`done`へ進める → 遅れてAが`failed`を書こうとする.
	 * Aの書き込みは弾かれ、`alert_status`は`sent`のまま残ることを確認する
	 * (修正前は`failed`で上書きされ、管理画面に誤った失敗通知が出ていた).
	 *
	 * @return void
	 */
	public function test_record_alert_result_stale_owner_cannot_overwrite_after_done() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );
		$repository->claim_diff( $run_id, 'owner-x' );
		$repository->finalize_diff_chunk( $run_id, 'owner-x', null, true, array( 'new' => 0, 'resolved' => 0, 'continuing' => 0 ) );

		// Aがclaimした後、lease切れの状態を作る(Aはまだ送信中という想定).
		$this->assertTrue( $repository->claim_diff( $run_id, 'owner-a' )['claimed'] );
		$wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_lease_expires_at'] = '2026-09-08 11:00:00';

		// Bが再claimして送信成功・doneへ.
		$this->assertTrue( $repository->claim_diff( $run_id, 'owner-b' )['claimed'] );
		$this->assertTrue( $repository->record_alert_result( $run_id, 'owner-b', 'sent' ) );
		$this->assertTrue( $repository->finalize_diff_alerting( $run_id, 'owner-b' ) );

		// 遅れてAが失敗を記録しようとする.
		$this->assertFalse( $repository->record_alert_result( $run_id, 'owner-a', 'failed', 'timeout' ) );
		$this->assertFalse( $repository->finalize_diff_alerting( $run_id, 'owner-a' ) );

		$row = $wpdb->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'done', $row['diff_status'] );
		$this->assertSame( 'sent', $row['alert_status'] );
		$this->assertNull( $row['alert_error'] );
	}

	/**
	 * `alerting`をclaim中(lease有効)は、`processing`と同じく他のownerが
	 * claimしようとしても弾かれ、`diff_status`が`alerting`のまま・`diff_owner`も
	 * 上書きされないことを確認する(v0.5後半 §Step14c. `claim_diff()`の
	 * ALERTING分岐の組み合わせ表のセル).
	 *
	 * @return void
	 */
	public function test_claim_diff_rejects_second_claim_of_alerting_while_lease_is_active() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );
		$repository->claim_diff( $run_id, 'owner-a' );
		$repository->finalize_diff_chunk( $run_id, 'owner-a', null, true, array( 'new' => 0, 'resolved' => 0, 'continuing' => 0 ) );

		// lease有効期間120秒でalertingを再claimし、以後lease有効な状態を作る.
		$first = $repository->claim_diff( $run_id, 'owner-b', 120, 5 );
		$this->assertTrue( $first['claimed'] );
		$this->assertSame( 'alerting', $first['run']['diff_status'] );

		$second = $repository->claim_diff( $run_id, 'owner-c', 120, 5 );

		$this->assertFalse( $second['claimed'] );
		$this->assertFalse( $second['failed'] );
		$this->assertSame( 'alerting', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
		$this->assertSame( 'owner-b', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_owner'], '他ownerに上書きされてはならない' );
	}

	/**
	 * `alerting`のlease切れ検知が試行上限を超えたら`processing`と同じく`failed`へ
	 * 倒すことを確認する(v0.5後半 §Step14c. `claim_diff()`のALERTING分岐の
	 * 組み合わせ表のセル. `finalize_diff_chunk()`が書く「即座にlease切れ」相当の
	 * 値により、alertingへ入った直後の最初の再claimからこの経路に乗る ―― §14c
	 * 実装時にユーザー承認済みの設計上のトレードオフ〔進捗メモ参照〕).
	 *
	 * @return void
	 */
	public function test_claim_diff_marks_alerting_failed_after_exceeding_max_attempts() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );
		$repository->claim_diff( $run_id, 'owner-a' );
		$repository->finalize_diff_chunk( $run_id, 'owner-a', null, true, array( 'new' => 0, 'resolved' => 0, 'continuing' => 0 ) );

		// 試行上限0回: alertingへ入った直後の最初の再claimで即座に上限超過になる.
		$result = $repository->claim_diff( $run_id, 'owner-b', 120, 0 );

		$this->assertFalse( $result['claimed'] );
		$this->assertTrue( $result['failed'] );
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
		$this->assertSame( 1, (int) $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_attempt_count'] );
	}

	/**
	 * Lease切れの`alerting`を2つのworkerが同時に読んだとき、先にclaimした側だけが
	 * `claimed: true`になり、遅れた側は弾かれることを確認する(コードレビュー指摘で
	 * 修正. 修正前は`alerting`→`alerting`のCASが`diff_status`しか見ておらず、
	 * 両方が`claimed: true`を受け取ってメールを二重送信できた).
	 *
	 * Worker Bが行を読んだ後・UPDATEする前に、Worker Aのclaimを割り込ませて
	 * 競合を再現する(`WPCV_Test_Fake_WPDB_With_Interleave`参照).
	 *
	 * @return void
	 */
	public function test_claim_diff_prevents_double_claim_of_expired_alerting() {
		$wpdb       = new WPCV_Test_Fake_WPDB_With_Interleave();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );
		$repository->claim_diff( $run_id, 'owner-x' );
		$repository->finalize_diff_chunk( $run_id, 'owner-x', null, true, array( 'new' => 0, 'resolved' => 0, 'continuing' => 0 ) );

		$claim_a            = null;
		$wpdb->before_update = function () use ( $repository, $run_id, &$claim_a ) {
			$claim_a = $repository->claim_diff( $run_id, 'owner-a', 120, 5 );
		};

		$claim_b = $repository->claim_diff( $run_id, 'owner-b', 120, 5 );

		$this->assertTrue( $claim_a['claimed'], '先に割り込んだAはclaimできる' );
		$this->assertFalse( $claim_b['claimed'], '同じ行を先に読んでいたBは弾かれる' );
		$this->assertSame( 'alerting', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
		$this->assertSame( 'owner-a', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_owner'] );
	}

	/**
	 * Lease切れの`processing`を2つのworkerが同時に読んだとき、先に差し戻し+
	 * 再claimした側の`processing`を、遅れた側の差し戻しUPDATEが上書きしない
	 * ことを確認する(コードレビュー指摘2に関連して見つけた同種の競合. 修正前は
	 * 差し戻しのWHEREが`diff_status = processing`だけで、Aが再claimした直後の
	 * 行にBのUPDATEが一致し、AとBが同時に同じrunを処理できた).
	 *
	 * @return void
	 */
	public function test_claim_diff_prevents_double_claim_of_expired_processing() {
		$wpdb       = new WPCV_Test_Fake_WPDB_With_Interleave();
		$repository = $this->make_repository( $wpdb );

		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'status'                => 'success',
				'run_trigger'           => 'cron',
				'runner'                => 'sync',
				'diff_status'           => 'processing',
				'diff_owner'            => 'owner-dead',
				'diff_lease_expires_at' => '2026-09-08 11:00:00',
				'diff_attempt_count'    => 0,
			)
		);
		$run_id = 1;

		$claim_a            = null;
		$wpdb->before_update = function () use ( $repository, $run_id, &$claim_a ) {
			$claim_a = $repository->claim_diff( $run_id, 'owner-a', 120, 5 );
		};

		$claim_b = $repository->claim_diff( $run_id, 'owner-b', 120, 5 );

		$this->assertTrue( $claim_a['claimed'], '先に割り込んだAはclaimできる' );
		$this->assertFalse( $claim_b['claimed'], '同じ行を先に読んでいたBは弾かれる' );
		$this->assertSame( 'processing', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_status'] );
		$this->assertSame( 'owner-a', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_owner'], 'Aのclaimが上書きされてはならない' );
		$this->assertSame( 1, (int) $wpdb->rows['wp_wpcv_runs'][ $run_id ]['diff_attempt_count'], '試行回数はAの1回分だけ消費される' );
	}

	// ------------------------------------------------------------------
	// v0.5後半 §Step12: `find_stale_diff_run()`(取りこぼしの回収)
	// ------------------------------------------------------------------

	/**
	 * `pending`、またはlease切れの`processing`の run のうち、最も古い(id最小)
	 * ものだけを1件返すことを確認する(lease有効な`processing`・terminalな
	 * `done`は候補にしない).
	 *
	 * @return void
	 */
	public function test_find_stale_diff_run_returns_oldest_candidate_only() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		// id=1: lease有効なprocessing(対象外).
		$wpdb->insert( 'wp_wpcv_runs', array( 'status' => 'success', 'run_trigger' => 'cron', 'runner' => 'sync', 'diff_status' => 'processing', 'diff_owner' => 'x', 'diff_lease_expires_at' => '2026-09-08 23:59:59' ) );
		// id=2: lease切れのalerting(対象、最古の候補).
		$wpdb->insert( 'wp_wpcv_runs', array( 'status' => 'success', 'run_trigger' => 'cron', 'runner' => 'sync', 'diff_status' => 'alerting', 'diff_owner' => 'y', 'diff_lease_expires_at' => '2026-09-08 00:00:00' ) );
		// id=3: pending(対象).
		$wpdb->insert( 'wp_wpcv_runs', array( 'status' => 'success', 'run_trigger' => 'cron', 'runner' => 'sync', 'diff_status' => 'pending' ) );
		// id=4: done(対象外).
		$wpdb->insert( 'wp_wpcv_runs', array( 'status' => 'success', 'run_trigger' => 'cron', 'runner' => 'sync', 'diff_status' => 'done' ) );

		$this->assertSame( 2, $repository->find_stale_diff_run() );
	}

	/**
	 * 候補が1件も無ければ `null` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_find_stale_diff_run_returns_null_when_no_candidates() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$wpdb->insert( 'wp_wpcv_runs', array( 'status' => 'success', 'run_trigger' => 'cron', 'runner' => 'sync', 'diff_status' => 'done' ) );

		$this->assertNull( $repository->find_stale_diff_run() );
	}

	// ------------------------------------------------------------------
	// v0.5後半 §Step15a: run の連続失敗アラート
	// (`find_failure_streak()`/`record_failure_alert_result()`)
	// ------------------------------------------------------------------

	/**
	 * `wpcv_runs`の1行分を直接insertするヘルパー(§Step15aのテスト専用.
	 * `find_failure_streak()`はid順の並びだけに依存するため、`reserve_run()`等の
	 * 状態遷移を経由せず直接行を作る).
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb      フェイクwpdb.
	 * @param array               $overrides 上書きするフィールド(`status`は必須).
	 * @return int insertした行のid.
	 */
	private function insert_run_row_for_streak( WPCV_Test_Fake_WPDB $wpdb, array $overrides ) {
		$wpdb->insert(
			'wp_wpcv_runs',
			array_merge(
				array(
					'started_at'   => '2026-09-08 00:00:00',
					'run_trigger'  => 'cron',
					'runner'       => 'sync',
					'alert_status' => null,
				),
				$overrides
			)
		);

		return $wpdb->insert_id;
	}

	/**
	 * 閾値ちょうどの連続(L = N)では、まだ誰も通知していないため
	 * `notified = false`になり、`runs`が今回を含む新しい順で返ることを確認する.
	 *
	 * @return void
	 */
	public function test_find_failure_streak_returns_length_and_runs_at_threshold() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'success' ) );
		$run2 = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) );
		$run3 = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'aborted' ) );
		$run4 = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) );

		$result = $repository->find_failure_streak( $run4, 3 );

		$this->assertSame( 3, $result['length'] );
		$this->assertFalse( $result['notified'] );
		$this->assertSame( array( $run4, $run3, $run2 ), array_map( 'intval', array_column( $result['runs'], 'id' ) ) );
	}

	/**
	 * 実行中(`WPCV_Run_Status::ACTIVE`)のrunは数えないし途切れさせないことを
	 * 確認する(§2.3).
	 *
	 * @return void
	 */
	public function test_find_failure_streak_skips_active_run_without_breaking() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'success' ) );
		$run2 = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) );
		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'running' ) );
		$run4 = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) );

		$result = $repository->find_failure_streak( $run4, 2 );

		$this->assertSame( 2, $result['length'] );
		$this->assertSame( array( $run4, $run2 ), array_map( 'intval', array_column( $result['runs'], 'id' ) ), '実行中のrunは連続に含めない' );
	}

	/**
	 * 連続が閾値未満のときは`length`がその件数のまま返る(送るかどうかは
	 * 呼び出し元が`length < threshold`で判断する)ことを確認する.
	 *
	 * @return void
	 */
	public function test_find_failure_streak_returns_length_below_threshold() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'success' ) );
		$run2 = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) );

		$result = $repository->find_failure_streak( $run2, 3 );

		$this->assertSame( 1, $result['length'] );
	}

	/**
	 * 連続の中で、先頭からN番目以降(今回を除く)に`alert_status = sent`のrunが
	 * あれば`notified = true`になることを確認する(§2.2・§2.3と同じ式.
	 * `WPCV_Run_Repository::find_failure_streak()`のdocblock参照).
	 *
	 * @return void
	 */
	public function test_find_failure_streak_detects_already_notified_run() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'success' ) );
		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) ); // 位置1.
		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) ); // 位置2.
		// 位置3(閾値ちょうどで最初に送ったrun): 送信成功済み.
		$this->insert_run_row_for_streak(
			$wpdb,
			array(
				'status'       => 'failed',
				'alert_status' => 'sent',
			)
		);
		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) ); // 位置4.
		$run6 = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) ); // 位置5(今回).

		$result = $repository->find_failure_streak( $run6, 3 );

		$this->assertSame( 5, $result['length'] );
		$this->assertTrue( $result['notified'] );
	}

	/**
	 * 先頭からN番目より前(位置がN未満)にだけ`alert_status = sent`があっても、
	 * 通知済みとは判定しないことを確認する(境界値の確認.前のテストの反例).
	 *
	 * @return void
	 */
	public function test_find_failure_streak_ignores_sent_run_before_threshold_position() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'success' ) );
		// 位置1: 過去に閾値を下げていた時期に送信したことがある、という想定.
		$this->insert_run_row_for_streak(
			$wpdb,
			array(
				'status'       => 'failed',
				'alert_status' => 'sent',
			)
		);
		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) ); // 位置2.
		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) ); // 位置3.
		$this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) ); // 位置4.
		$run6 = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) ); // 位置5(今回).

		$result = $repository->find_failure_streak( $run6, 3 );

		$this->assertSame( 5, $result['length'] );
		$this->assertFalse( $result['notified'] );
	}

	/**
	 * `record_failure_alert_result()`が`status = failed`の行に記録できることを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_record_failure_alert_result_writes_to_failed_row() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) );

		$this->assertTrue( $repository->record_failure_alert_result( $run_id, 'sent', null, 'slack' ) );

		$row = $wpdb->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'sent', $row['alert_status'] );
		$this->assertSame( '2026-09-08 12:00:00', $row['alert_attempted_at'] );
		$this->assertNull( $row['alert_error'] );
		$this->assertSame( 'slack', $row['alert_channel_failures'] );
	}

	/**
	 * `status = aborted`の行にも記録できることを確認する(failed/abortedの
	 * どちらでも良い. `record_failure_alert_result()`のdocblock参照).
	 *
	 * @return void
	 */
	public function test_record_failure_alert_result_writes_to_aborted_row() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'aborted' ) );

		$this->assertTrue( $repository->record_failure_alert_result( $run_id, 'failed', 'boom' ) );

		$row = $wpdb->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'failed', $row['alert_status'] );
		$this->assertSame( 'boom', $row['alert_error'] );
	}

	/**
	 * `status`がfailed/abortedのいずれでもない行には書き込まないことを確認する
	 * (通常は`wpcv_run_terminated`フックがfailed/aborted以外で呼ばれないため
	 * 起こらない想定だが、防御的な安全側の挙動として確認する).
	 *
	 * @return void
	 */
	public function test_record_failure_alert_result_does_not_write_when_status_mismatches() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'success' ) );

		$this->assertFalse( $repository->record_failure_alert_result( $run_id, 'sent' ) );
		$this->assertNull( $wpdb->rows['wp_wpcv_runs'][ $run_id ]['alert_status'] );
	}

	/**
	 * `alert_error`/`alert_channel_failures`が500文字を超える分を切り捨てる
	 * ことを確認する(`record_alert_result()`と同じ仕様).
	 *
	 * @return void
	 */
	public function test_record_failure_alert_result_truncates_long_strings() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $this->insert_run_row_for_streak( $wpdb, array( 'status' => 'failed' ) );
		$long   = str_repeat( 'x', 600 );

		$repository->record_failure_alert_result( $run_id, 'failed', $long, $long );

		$row = $wpdb->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 500, strlen( $row['alert_error'] ) );
		$this->assertSame( 500, strlen( $row['alert_channel_failures'] ) );
	}

	/**
	 * `WPCV_Verifier::summarize()` 相当の固定summary配列を作る(`finish_run()` の
	 * 引数用. このテストファイルでは差分処理の前提として run を success/partial に
	 * するためだけに使うので内容は問わない).
	 *
	 * @return array
	 */
	private function make_summary() {
		return array(
			'status'               => 'success',
			'targets_total'        => 1,
			'targets_verified'     => 1,
			'targets_unverifiable' => 0,
			'targets_failed'       => 0,
			'findings_total'       => 0,
		);
	}

	/**
	 * `WPCV_Run_Repository`の読み取りメソッドが、runsテーブルを全件読む
	 * `SELECT`(WHEREもLIMITも無いもの)を発行しないことを確認する(コードレビュー
	 * 指摘5. 以前は`all_rows()`で全runを読んでPHPで絞り込んでおり、運用年数に
	 * 比例して無駄が増えていた).
	 *
	 * @return void
	 */
	public function test_read_methods_do_not_select_whole_runs_table() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		$run_id = $repository->reserve_run()['run_id'];
		$repository->mark_planning_running( $run_id );
		$repository->finish_run( $run_id, $this->make_summary() );

		$repository->find_by_id( $run_id );
		$repository->find_most_recent_run();
		$repository->find_most_recent_by_trigger( 'cli' );
		$repository->find_most_recent_terminal_run();
		$repository->find_all( array( 'page' => 2, 'per_page' => 10 ) );
		$repository->find_stale_diff_run();
		$repository->claim_diff( $run_id, 'owner-a' );
		$repository->reserve_due_run( 11, 0 );
		$repository->find_failure_streak( $run_id, 3 );

		$this->assertNotEmpty( $wpdb->get_results_calls );

		foreach ( $wpdb->get_results_calls as $query ) {
			if ( false === strpos( $query, 'wp_wpcv_runs' ) ) {
				continue;
			}

			$this->assertMatchesRegularExpression( '/\\s(WHERE|LIMIT)\\s/i', $query, "全件取得のSELECTが発行された: {$query}" );
		}
	}

	/**
	 * `find_all()`がSQLのORDER BY・LIMIT・OFFSETでページを切り出し、総件数を
	 * `COUNT(*)`で返すことを確認する(コードレビュー指摘5).
	 *
	 * @return void
	 */
	public function test_find_all_paginates_newest_first_with_total() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = $this->make_repository( $wpdb );

		for ( $i = 1; $i <= 25; $i++ ) {
			$wpdb->insert( 'wp_wpcv_runs', array( 'status' => 'success', 'run_trigger' => 'cron', 'runner' => 'sync' ) );
		}

		$page = $repository->find_all( array( 'page' => 2, 'per_page' => 10 ) );

		$this->assertSame( 25, $page['total'] );
		$this->assertSame( range( 15, 6 ), array_map( 'intval', array_column( $page['rows'], 'id' ) ) );
	}
}


/**
 * 最初の`update()`の直前に、1回だけコールバックを実行するフェイク(`claim_diff()`の
 * 競合テスト専用).
 *
 * 「Worker Bが行を読んだ後・UPDATEする前に、Worker Aが同じ行を更新した」という
 * 並行実行の順序を、1スレッドのテストで再現するために使う.コールバックは
 * 実行前に解除するため、コールバック内のupdate()では再度呼ばれない.
 */
class WPCV_Test_Fake_WPDB_With_Interleave extends WPCV_Test_Fake_WPDB {

	/**
	 * 次の`update()`の直前に1回だけ呼ぶcallable(呼んだら`null`に戻す).
	 *
	 * @var callable|null
	 */
	public $before_update = null;

	/**
	 * `before_update`があれば先に呼んでから、通常の`update()`を行う.
	 *
	 * @param string     $table        テーブル名.
	 * @param array      $data         更新するカラム => 値.
	 * @param array      $where        カラム => 値.
	 * @param array|null $format       無視する.
	 * @param array|null $where_format 無視する.
	 * @return int|false
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		if ( null !== $this->before_update ) {
			$callback            = $this->before_update;
			$this->before_update = null;
			$callback();
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}
}
