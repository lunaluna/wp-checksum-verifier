<?php
/**
 * WPCV_Chunk_Dispatcher のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-chunk-result-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `scan()` を呼び出しごとに事前登録したキューから返す、走査結果固定のフェイク
 * (`WPCV_Chunk_Dispatcher::process_core_scan()` が3領域分 `scan()` を順に呼ぶため、
 * 呼び出し順に異なる結果を返せる必要がある).
 */
class WPCV_Test_Fake_Scanner_Queue extends WPCV_Unknown_File_Scanner {

	/**
	 * @var array<int, array>
	 */
	private $queue;

	/**
	 * @param array<int, array> $queue `scan()` が呼ばれるたびに先頭から1つ返す.
	 */
	public function __construct( array $queue ) {
		$this->queue = $queue;
	}

	/**
	 * @param string $base_dir    無視する.
	 * @param array  $known_files 無視する.
	 * @param array  $args        無視する.
	 * @return array
	 */
	public function scan( $base_dir, array $known_files, array $args = array() ) {
		unset( $base_dir, $known_files, $args );

		return array(
			'items'     => array_shift( $this->queue ) ?? array(),
			'truncated' => false,
		);
	}
}

/**
 * `scan()` が常に `truncated: true` を返すフェイク(v0.4.0コードレビューCR-08是正の
 * テスト用。walk自体が予算切れで完了しなかったケースを実ファイルシステムに
 * 依存せず再現する).
 */
class WPCV_Test_Fake_Scanner_Truncated extends WPCV_Unknown_File_Scanner {

	/**
	 * @param string $base_dir    無視する.
	 * @param array  $known_files 無視する.
	 * @param array  $args        無視する.
	 * @return array
	 */
	public function scan( $base_dir, array $known_files, array $args = array() ) {
		unset( $base_dir, $known_files, $args );

		return array(
			'items'     => array( array( 'path' => 'wp-admin/should-not-be-used.php', 'severity' => 'high' ) ),
			'truncated' => true,
		);
	}
}

/**
 * `WPCV_Chunk_Dispatcher`(v0.4.0 §Step4)のテスト.
 *
 * `$continuation_scheduler` を注入したフェイクで記録するだけにし、実際の
 * Action Scheduler 関数には依存しない(クラス docblock 参照)。
 */
class ChunkDispatcherTest extends TestCase {

	/**
	 * 固定時刻でRun/Target_Run Repositoryを組み立てる.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @return array{run_repository: WPCV_Run_Repository, target_run_repository: WPCV_Target_Run_Repository, finding_repository: WPCV_Finding_Repository}
	 */
	private function make_repositories( WPCV_Test_Fake_WPDB $wpdb ) {
		$now = static function () {
			return '2026-09-11 12:00:00';
		};

		return array(
			'run_repository'        => new WPCV_Run_Repository( $wpdb, $now ),
			'target_run_repository' => new WPCV_Target_Run_Repository( $wpdb, $now ),
			'finding_repository'    => new WPCV_Finding_Repository( $wpdb ),
		);
	}

	/**
	 * Dispatcherを組み立てる.
	 *
	 * @param array         $repositories `make_repositories()` の戻り値.
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @param array         $overrides {
	 *     @type WPCV_Manifest_Source      $core_source
	 *     @type WPCV_Manifest_Source      $plugin_source
	 *     @type WPCV_Unknown_File_Scanner $scanner
	 * }
	 * @param array         $continuation_calls `$continuation_scheduler` の呼び出しを
	 *                                          記録する配列(参照渡し).
	 * @return WPCV_Chunk_Dispatcher
	 */
	private function make_dispatcher( array $repositories, WPCV_Test_Fake_WPDB $wpdb, array $overrides, array &$continuation_calls ) {
		$chunk_result_repository = new WPCV_Chunk_Result_Repository( $wpdb, $repositories['target_run_repository'], $repositories['finding_repository'], new WPCV_Suppression_Repository( $wpdb ) );

		return new WPCV_Chunk_Dispatcher(
			$repositories['run_repository'],
			$repositories['target_run_repository'],
			$chunk_result_repository,
			new WPCV_Chunk_Verifier(),
			$overrides['core_source'] ?? new WPCV_Test_Fake_Manifest_Source(
				array(
					'manifest_status' => 'ok',
					'error_code'      => null,
					'files'           => array(),
				)
			),
			$overrides['plugin_source'] ?? new WPCV_Test_Fake_Manifest_Source(
				array(
					'manifest_status' => 'ok',
					'error_code'      => null,
					'files'           => array(),
				)
			),
			$overrides['scanner'] ?? new WPCV_Unknown_File_Scanner(),
			static function () {
				return 'lease-owner-fixed';
			},
			static function ( $run_id, $delay_seconds ) use ( &$continuation_calls ) {
				$continuation_calls[] = array(
					'run_id'        => $run_id,
					'delay_seconds' => $delay_seconds,
				);
			},
			static function () {
				return strtotime( '2026-09-11 12:00:00' );
			}
		);
	}

	/**
	 * `reserve_run()`(既定で`planning`状態のrunを作る。v0.4.0コードレビュー
	 * CR-01是正)で予約したうえで、`mark_planning_running()`で`running`へ
	 * 進めて返す(`WPCV_Run_Starter::plan_and_save()`が本番で行う遷移を
	 * このテストファイルのfixtureとして再現する。`dispatch()`のclaim・
	 * 完了判定は`running`のrunにのみ働くため、それらを検証するテストは
	 * このヘルパー経由でrunを用意すること。`queued`/`planning`のまま待機
	 * させる分岐自体を検証するテスト・deadline超過を検証するテストは
	 * 対象外〔`reserve_run()`を直接使う〕).
	 *
	 * @param WPCV_Run_Repository $run_repository `wpcv_runs` の永続化層.
	 * @return array `reserve_run()` の戻り値と同じ形.
	 */
	private function reserve_and_start_running( WPCV_Run_Repository $run_repository ) {
		$reservation = $run_repository->reserve_run();
		$run_repository->mark_planning_running( $reservation['run_id'] );

		return $reservation;
	}

	/**
	 * 各テストの前に前回の残骸を掃除する(v0.4.0コードレビューCR-06是正で追加した
	 * `test_schedule_via_action_scheduler_*` が使うAction Scheduler関連の
	 * グローバルのみが対象。他のテストは `$continuation_scheduler` を注入した
	 * フェイクを使うため、この掃除の影響を受けない).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset(
			$GLOBALS['_wpcv_test_as_enqueue_calls'],
			$GLOBALS['_wpcv_test_as_enqueue_return_zero'],
			$GLOBALS['_wpcv_test_as_schedule_single_calls'],
			$GLOBALS['_wpcv_test_as_schedule_single_return_zero'],
			$GLOBALS['_wpcv_test_action_scheduler_initialized']
		);
	}

	/**
	 * 存在しない run_id を渡すと `run_not_found` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_returns_run_not_found_for_unknown_run() {
		$wpdb              = new WPCV_Test_Fake_WPDB();
		$repositories      = $this->make_repositories( $wpdb );
		$continuation_calls = array();
		$dispatcher        = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$result = $dispatcher->dispatch( 999, array( 'version' => '6.8' ) );

		$this->assertSame( 'run_not_found', $result['action'] );
		$this->assertSame( array(), $continuation_calls );
	}

	/**
	 * 既に終端状態(success等)のrunに対しては何もせず `run_already_terminal` を
	 * 返すことを確認する(重複配送されたAS actionのno-op).
	 *
	 * @return void
	 */
	public function test_dispatch_returns_already_terminal_for_finished_run() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$repositories['run_repository']->finish_run(
			$reservation['run_id'],
			array(
				'status'               => 'success',
				'targets_total'        => 0,
				'targets_verified'     => 0,
				'targets_unverifiable' => 0,
				'targets_failed'       => 0,
				'findings_total'       => 0,
			)
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$result = $dispatcher->dispatch( $reservation['run_id'], array( 'version' => '6.8' ) );

		$this->assertSame( 'run_already_terminal', $result['action'] );
		$this->assertSame( 'success', $result['status'] );
		$this->assertSame( array(), $continuation_calls );
	}

	/**
	 * v0.4.0コードレビューCR-01の直接的な回帰テスト: run が `planning`(target_runs
	 * の列挙・保存が別プロセスでまだ完了していない)で、target_runsが1件も
	 * 無い状態でも、`dispatch()`はそれを「完了」と誤認して`run_finalized`
	 * (success)にしてはならない。この保護が無いと、`WPCV_Verifier::summarize(
	 * array() )`が「0件中0件success」を`success`として返してしまい、実際には
	 * 1つのtargetも検証していないrunが正常完了として公開されてしまう
	 * (`WPCV_Chunk_Dispatcher`のクラスdocblock「CR-01是正」参照).
	 *
	 * @return void
	 */
	public function test_dispatch_does_not_finalize_planning_run_with_no_targets_yet() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $repositories['run_repository']->reserve_run();
		$run_id       = $reservation['run_id'];

		// reserve_run() の既定どおり planning のまま(まだ plan_and_save() を
		// 呼んでいない = target_runsは1件も無い)であることが前提.
		$this->assertSame( WPCV_Run_Status::PLANNING, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
		$this->assertSame( array(), $repositories['target_run_repository']->find_all_by_run( $run_id ) );

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$result = $dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$this->assertSame( 'waiting_for_plan', $result['action'] );
		// runはtarget 0件のままsuccessに確定されていないことを確認する
		// (このアサーションが無いと、`run_finalized`/successへの回帰を
		// 見逃す)。
		$this->assertSame( WPCV_Run_Status::PLANNING, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
		$this->assertNotSame( 'success', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
	}

	/**
	 * `queued`状態(Action Scheduler enqueue直後、workerがまだ手を付けていない)の
	 * runも同様にclaim対象0件を「完了」と誤認しないことを確認する(CR-01是正の
	 * 別経路 ―— `WPCV_Runner_Async::run_async_action()`が`mark_queued_planning()`
	 * を呼ぶ前に、別プロセスが同じrunを`dispatch()`する競合を想定).
	 *
	 * @return void
	 */
	public function test_dispatch_does_not_finalize_queued_run_with_no_targets_yet() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $repositories['run_repository']->reserve_run(
			array(
				'run_trigger'    => 'cron',
				'runner'         => 'async',
				'initial_status' => WPCV_Run_Status::QUEUED,
			)
		);
		$run_id = $reservation['run_id'];

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$result = $dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$this->assertSame( 'waiting_for_plan', $result['action'] );
		$this->assertSame( WPCV_Run_Status::QUEUED, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
	}

	/**
	 * `deadline_at` を過去にすると、runと非終端のtarget_runがすべて `aborted` に
	 * なることを確認する(v0.4.0 §Step4「run deadline超過sweep」)。
	 *
	 * `reserve_and_start_running()` を使わず `reserve_run()` を直接呼び、runを
	 * `planning`のままにする(v0.4.0コードレビューCR-01是正の回帰テストを兼ねる:
	 * `planning`のまま止まったrun ―― 列挙・保存を担当するworkerがクラッシュした
	 * 場合等 ―― もdeadline超過sweepの対象になり、`queued`/`planning`の待機分岐
	 * より先にdeadline判定が効くことを確認する。`WPCV_Chunk_Dispatcher::dispatch()`
	 * のクラスdocblock参照).
	 *
	 * @return void
	 */
	public function test_dispatch_aborts_run_and_non_terminal_targets_when_deadline_passed() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $repositories['run_repository']->reserve_run();
		$run_id       = $reservation['run_id'];

		$this->assertSame( WPCV_Run_Status::PLANNING, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );

		// deadline_at を過去に書き換える(`reserve_run()` は未来の値しか作れないため直接操作).
		$wpdb->rows['wp_wpcv_runs'][ $run_id ]['deadline_at'] = '2000-01-01 00:00:00';

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array( wpcv_test_make_target_run( array( 'status' => WPCV_Target_Status::QUEUED ) ) )
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$result = $dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$this->assertSame( 'aborted', $result['action'] );
		$this->assertSame( WPCV_Run_Status::ABORTED, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
		$this->assertSame( WPCV_Target_Status::ABORTED, $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['core'] ]['status'] );
		// deadline超過時は継続をenqueueしない(runは既に終端に達したため).
		$this->assertSame( array(), $continuation_calls );
	}

	/**
	 * `sweep_deadline_and_expired_leases()`(v0.4.0コードレビューCR-07是正で追加。
	 * `dispatch()`を経由せずWP-Cron/「今すぐ実行」の受付処理から直接呼べる、
	 * lease掃除+deadline超過チェックのみのメソッド)が、deadline超過している
	 * activeなrunをabortし、非終端のtargetもabortすることを確認する
	 * (`dispatch()`側の同種テストと同じ状況を、claim・chunk処理を経由せずに
	 * 再現できることの確認).
	 *
	 * @return void
	 */
	public function test_sweep_deadline_and_expired_leases_aborts_run_and_targets_when_deadline_passed() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $repositories['run_repository']->reserve_run();
		$run_id       = $reservation['run_id'];

		$wpdb->rows['wp_wpcv_runs'][ $run_id ]['deadline_at'] = '2000-01-01 00:00:00';

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array( wpcv_test_make_target_run( array( 'status' => WPCV_Target_Status::QUEUED ) ) )
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$dispatcher->sweep_deadline_and_expired_leases( $run_id );

		$this->assertSame( WPCV_Run_Status::ABORTED, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
		$this->assertSame( WPCV_Target_Status::ABORTED, $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['core'] ]['status'] );
	}

	/**
	 * `sweep_deadline_and_expired_leases()` が、deadlineをまだ超過していない
	 * activeなrunには何もしないことを確認する(v0.4.0コードレビューCR-07是正の
	 * 核心: `started_at`基準の旧stale判定なら誤ってfailed化していたはずの
	 * 「経過時間は長いがdeadline内」のrunが、生存判定を`deadline_at`に一本化
	 * したことで正しく維持されることの確認).
	 *
	 * @return void
	 */
	public function test_sweep_deadline_and_expired_leases_does_nothing_when_deadline_not_yet_passed() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $repositories['run_repository']->reserve_run();
		$run_id       = $reservation['run_id'];

		// `reserve_run()` が設定した(未来の)deadline_atをそのまま使う.
		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$dispatcher->sweep_deadline_and_expired_leases( $run_id );

		$this->assertSame( WPCV_Run_Status::PLANNING, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
	}

	/**
	 * `sweep_deadline_and_expired_leases()` が、既に終端状態のrunには何もしない
	 * (誤って再abort扱いにしない)ことを確認する.
	 *
	 * @return void
	 */
	public function test_sweep_deadline_and_expired_leases_does_nothing_when_run_already_terminal() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $repositories['run_repository']->reserve_run();
		$run_id       = $reservation['run_id'];

		$wpdb->rows['wp_wpcv_runs'][ $run_id ]['status']      = WPCV_Run_Status::SUCCESS;
		$wpdb->rows['wp_wpcv_runs'][ $run_id ]['deadline_at'] = '2000-01-01 00:00:00';

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$dispatcher->sweep_deadline_and_expired_leases( $run_id );

		$this->assertSame( WPCV_Run_Status::SUCCESS, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
	}

	/**
	 * `sweep_deadline_and_expired_leases()` が、存在しないrun_idに対して何も
	 * せず静かに戻ることを確認する.
	 *
	 * @return void
	 */
	public function test_sweep_deadline_and_expired_leases_does_nothing_when_run_not_found() {
		$wpdb                = new WPCV_Test_Fake_WPDB();
		$repositories        = $this->make_repositories( $wpdb );
		$continuation_calls  = array();
		$dispatcher          = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$dispatcher->sweep_deadline_and_expired_leases( 999 );

		$this->assertArrayNotHasKey( 'wp_wpcv_runs', $wpdb->rows );
	}

	/**
	 * Claim可能なtargetが無く、全target_runが終端状態ならrunを確定させることを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_finalizes_run_when_all_targets_terminal() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$repositories['target_run_repository']->save_target_runs(
			$run_id,
			array(
				wpcv_test_make_target_run( array( 'status' => WPCV_Target_Status::SUCCESS ) ),
				wpcv_test_make_target_run(
					array(
						'target_id' => 'plugin:foo',
						'dimension' => 'plugin',
						'slug'      => 'foo',
						'status'    => WPCV_Target_Status::UNVERIFIABLE,
					)
				),
			)
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$result = $dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$this->assertSame( 'run_finalized', $result['action'] );
		$this->assertSame( 'partial', $result['summary']['status'] );
		$this->assertSame( 'partial', $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
		$this->assertSame( array(), $continuation_calls );
	}

	/**
	 * Claim可能なtargetが無く、まだ非終端(他workerが処理中)のtargetが残っている
	 * 場合、runは確定させず、lease有効期間相当の遅延で継続をenqueueすることを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_schedules_delayed_recheck_when_waiting_for_other_worker() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array( wpcv_test_make_target_run( array( 'status' => WPCV_Target_Status::RUNNING ) ) )
		);
		// lease未失効(retry_afterが未来ではないが、statusがrunningのままなので
		// claim_next()の候補にはならない).
		$wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['core'] ]['lease_expires_at'] = '2099-01-01 00:00:00';

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$result = $dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$this->assertSame( 'waiting', $result['action'] );
		$this->assertSame( WPCV_Run_Status::RUNNING, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );
		$this->assertCount( 1, $continuation_calls );
		$this->assertSame( $run_id, $continuation_calls[0]['run_id'] );
		$this->assertSame( WPCV_Target_Run_Repository::DEFAULT_LEASE_SECONDS, $continuation_calls[0]['delay_seconds'] );
	}

	/**
	 * Coreのmanifest比較(素の `core` target)が空manifestで即座に完了し、
	 * `SUCCESS` へ進み、即時継続(delay 0)がenqueueされることを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_completes_core_manifest_target_and_schedules_immediate_continuation() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array( wpcv_test_make_target_run( array( 'status' => WPCV_Target_Status::QUEUED ) ) )
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$result = $dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$this->assertSame( 'processed', $result['action'] );
		$this->assertSame( 'core', $result['target_id'] );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['core'] ];
		$this->assertSame( WPCV_Target_Status::SUCCESS, $row['status'] );

		$this->assertCount( 1, $continuation_calls );
		$this->assertSame( 0, $continuation_calls[0]['delay_seconds'] );
	}

	/**
	 * Manifestを正常取得して `success` まで完了したtarget_runは、`manifest_status`が
	 * plannerの既定値 `missing` のまま残らず、manifest sourceが返した値(`ok`)へ
	 * 更新されることを確認する(v0.4.0コードレビューCR-09是正)。`wpcv_test_make_target_run()`の
	 * 既定値は `manifest_status: 'ok'` だとこの不具合を検出できないため、実際の
	 * `WPCV_Run_Planner::queued_target_run()` と同じ `missing` を明示的に与える.
	 *
	 * @return void
	 */
	public function test_dispatch_updates_manifest_status_from_missing_to_ok_on_success() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array(
				wpcv_test_make_target_run(
					array(
						'status'          => WPCV_Target_Status::QUEUED,
						'manifest_status' => 'missing',
					)
				),
			)
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['core'] ];
		$this->assertSame( WPCV_Target_Status::SUCCESS, $row['status'] );
		$this->assertSame( 'ok', $row['manifest_status'] );
	}

	/**
	 * Coreのmanifest取得が失敗した場合、`unverifiable` かつ manifest source の
	 * `error_code` がそのまま記録されることを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_marks_core_unverifiable_when_manifest_fetch_fails() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array( wpcv_test_make_target_run( array( 'status' => WPCV_Target_Status::QUEUED ) ) )
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher(
			$repositories,
			$wpdb,
			array(
				'core_source' => new WPCV_Test_Fake_Manifest_Source(
					array(
						'manifest_status' => 'missing',
						'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
						'files'           => array(),
					)
				),
			),
			$continuation_calls
		);

		$dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['core'] ];
		$this->assertSame( WPCV_Target_Status::UNVERIFIABLE, $row['status'] );
		$this->assertSame( WPCV_Error_Code::MANIFEST_NOT_FOUND, $row['error_code'] );
	}

	/**
	 * `core:_scan` は、coreのmanifest取得自体が失敗した場合、走査を行わず
	 * `unverifiable` になることを確認する(§16-D: 既知ファイルの集合を確定
	 * できないため、未知ファイル走査そのものを行わないという既存の一括実行と
	 * 同じ判断).
	 *
	 * @return void
	 */
	public function test_dispatch_marks_core_scan_unverifiable_when_manifest_fetch_fails() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array(
				wpcv_test_make_target_run(
					array(
						'target_id' => 'core:_scan',
						'slug'      => '_scan',
						'status'    => WPCV_Target_Status::QUEUED,
					)
				),
			)
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher(
			$repositories,
			$wpdb,
			array(
				'core_source' => new WPCV_Test_Fake_Manifest_Source(
					array(
						'manifest_status' => 'missing',
						'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
						'files'           => array(),
					)
				),
			),
			$continuation_calls
		);

		$dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['core:_scan'] ];
		$this->assertSame( WPCV_Target_Status::UNVERIFIABLE, $row['status'] );
		$this->assertSame( WPCV_Error_Code::MANIFEST_NOT_FOUND, $row['error_code'] );
	}

	/**
	 * `core:_scan` は、coreのmanifestが取得できれば3領域分 `scan()` を呼び、
	 * 検出したfindingsを保存して完了することを確認する(フェイクscannerで
	 * 実ファイルシステムに依存せず検証する).
	 *
	 * @return void
	 */
	public function test_dispatch_completes_core_scan_target_with_findings_from_all_areas() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array(
				wpcv_test_make_target_run(
					array(
						'target_id' => 'core:_scan',
						'slug'      => '_scan',
						'status'    => WPCV_Target_Status::QUEUED,
					)
				),
			)
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher(
			$repositories,
			$wpdb,
			array(
				'scanner' => new WPCV_Test_Fake_Scanner_Queue(
					array(
						array( array( 'path' => 'wp-admin/evil.php', 'severity' => 'high' ) ),
						array(),
						array(),
					)
				),
			),
			$continuation_calls
		);

		$dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['core:_scan'] ];
		$this->assertSame( WPCV_Target_Status::SUCCESS, $row['status'] );
		$this->assertSame( 1, $row['findings_total'] );

		$findings = array_values( $wpdb->rows['wp_wpcv_findings'] );
		$this->assertCount( 1, $findings );
		$this->assertSame( 'wporg', $findings[0]['source'] );
		$this->assertSame( 'added', $findings[0]['status'] );
	}

	/**
	 * `core:_scan` は、未知ファイル走査(walk)自体が時間・メモリ予算内に完了できな
	 * かった場合、chunk_verifierを呼ばず(=fingerprint計算・cursor更新を行わず)
	 * target_runを進捗を変えずにretryへ戻すことを確認する(v0.4.0コードレビュー
	 * CR-08是正)。
	 *
	 * @return void
	 */
	public function test_dispatch_marks_core_scan_retry_when_walk_is_truncated() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array(
				wpcv_test_make_target_run(
					array(
						'target_id' => 'core:_scan',
						'slug'      => '_scan',
						'status'    => WPCV_Target_Status::QUEUED,
					)
				),
			)
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher(
			$repositories,
			$wpdb,
			array(
				'scanner' => new WPCV_Test_Fake_Scanner_Truncated(),
			),
			$continuation_calls
		);

		$dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['core:_scan'] ];
		$this->assertSame( WPCV_Target_Status::RETRY, $row['status'] );
		$this->assertSame( WPCV_Error_Code::TIMEOUT, $row['error_code'] );
		$this->assertNull( $row['lease_owner'] );
		// 進捗(files_total)はhelperの既定値(10)のまま変わっていないことを確認
		// (walkが打ち切られたため chunk_verifier 自体を呼んでいない).
		$this->assertSame( 10, $row['files_total'] );
		$this->assertSame( 0, (int) ( $row['attempt_count'] ?? 0 ) );

		$this->assertSame( array(), $wpdb->rows['wp_wpcv_findings'] ?? array() );

		// 次回dispatchですぐ再claimできるよう、継続を即時(delay=0)でenqueueして
		// いることも確認する(lease切れ検知〔backoff付き〕とは異なる経路であるため).
		$this->assertSame(
			array( array( 'run_id' => $run_id, 'delay_seconds' => 0 ) ),
			$continuation_calls
		);
	}

	/**
	 * Plugin targetは、`$context['plugins']` から現在のslug/versionを再解決して
	 * 処理することを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_completes_plugin_target_by_resolving_current_context() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array(
				wpcv_test_make_target_run(
					array(
						'target_id' => 'plugin:akismet',
						'dimension' => 'plugin',
						'slug'      => 'akismet',
						'version'   => '5.3',
						'status'    => WPCV_Target_Status::QUEUED,
					)
				),
			)
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$context = array(
			'version'    => '6.8',
			'plugins'    => array(
				'akismet/akismet.php' => array( 'Version' => '5.3' ),
			),
			'plugin_dir' => '/var/www/wp-content/plugins',
		);

		$dispatcher->dispatch( $run_id, $context );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['plugin:akismet'] ];
		$this->assertSame( WPCV_Target_Status::SUCCESS, $row['status'] );
	}

	/**
	 * Plan時点では存在したプラグインが、実行時点の `$context['plugins']` から
	 * 見つからない場合、`unverifiable`/`target_missing` になることを確認する
	 * (v0.4.0 §Step4で新設した状態。分割実行特有のケース).
	 *
	 * @return void
	 */
	public function test_dispatch_marks_plugin_target_missing_when_no_longer_present() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array(
				wpcv_test_make_target_run(
					array(
						'target_id' => 'plugin:removed-plugin',
						'dimension' => 'plugin',
						'slug'      => 'removed-plugin',
						'status'    => WPCV_Target_Status::QUEUED,
					)
				),
			)
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['plugin:removed-plugin'] ];
		$this->assertSame( WPCV_Target_Status::UNVERIFIABLE, $row['status'] );
		$this->assertSame( WPCV_Error_Code::TARGET_MISSING, $row['error_code'] );
	}

	/**
	 * Muplugin loaderは、chunk処理を伴わず即座に `unverifiable`/`unknown_source`
	 * になることを確認する(§3.6。wp.org/GitHubマッピング未実装の既定挙動).
	 *
	 * @return void
	 */
	public function test_dispatch_marks_muplugin_loader_unverifiable_immediately() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array(
				wpcv_test_make_target_run(
					array(
						'target_id' => 'muplugin:loader.php',
						'dimension' => 'muplugin',
						'slug'      => 'loader.php',
						'status'    => WPCV_Target_Status::QUEUED,
					)
				),
			)
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['muplugin:loader.php'] ];
		$this->assertSame( WPCV_Target_Status::UNVERIFIABLE, $row['status'] );
		$this->assertSame( WPCV_Error_Code::UNKNOWN_SOURCE, $row['error_code'] );
	}

	/**
	 * `muplugin:_scan` は `mu_plugin_dir` が `$context` に無い場合、走査を行わず
	 * 差分ゼロの成功として終端化することを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_completes_muplugin_scan_as_success_when_dir_absent_from_context() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array(
				wpcv_test_make_target_run(
					array(
						'target_id' => 'muplugin:_scan',
						'dimension' => 'muplugin',
						'slug'      => '_scan',
						'status'    => WPCV_Target_Status::QUEUED,
					)
				),
			)
		);

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher( $repositories, $wpdb, array(), $continuation_calls );

		$dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['muplugin:_scan'] ];
		$this->assertSame( WPCV_Target_Status::SUCCESS, $row['status'] );
	}

	/**
	 * Claim済みtargetの処理中に例外が発生した場合、run全体は止めず、
	 * そのtarget_runのみ `failed` にして継続をenqueueすることを確認する.
	 *
	 * @return void
	 */
	public function test_dispatch_marks_only_the_claimed_target_failed_on_exception() {
		$wpdb         = new WPCV_Test_Fake_WPDB();
		$repositories = $this->make_repositories( $wpdb );
		$reservation  = $this->reserve_and_start_running( $repositories['run_repository'] );
		$run_id       = $reservation['run_id'];

		$target_run_ids = $repositories['target_run_repository']->save_target_runs(
			$run_id,
			array( wpcv_test_make_target_run( array( 'status' => WPCV_Target_Status::QUEUED ) ) )
		);

		$throwing_source = new class() implements WPCV_Manifest_Source {
			/**
			 * @param array $context 無視する.
			 * @return never
			 */
			public function get_manifest( array $context ) {
				unset( $context );
				throw new RuntimeException( 'boom' );
			}
		};

		$continuation_calls = array();
		$dispatcher         = $this->make_dispatcher(
			$repositories,
			$wpdb,
			array( 'core_source' => $throwing_source ),
			$continuation_calls
		);

		$result = $dispatcher->dispatch( $run_id, array( 'version' => '6.8' ) );

		$this->assertSame( 'processed', $result['action'] );

		$row = $wpdb->rows['wp_wpcv_target_runs'][ $target_run_ids['core'] ];
		$this->assertSame( WPCV_Target_Status::FAILED, $row['status'] );
		$this->assertStringContainsString( 'boom', $row['error_message'] );
		$this->assertSame( WPCV_Run_Status::RUNNING, $wpdb->rows['wp_wpcv_runs'][ $run_id ]['status'] );

		$this->assertCount( 1, $continuation_calls );
		$this->assertSame( 0, $continuation_calls[0]['delay_seconds'] );
	}

	/**
	 * `schedule_via_action_scheduler()`(`$continuation_scheduler` の既定実装。
	 * private static)を `ReflectionMethod` 経由で直接呼び、`as_enqueue_async_action()`
	 * が正の action ID を返せば例外を投げないことを確認する(v0.4.0コード
	 * レビューCR-06是正の正常系).
	 *
	 * @return void
	 */
	public function test_schedule_via_action_scheduler_does_not_throw_when_enqueue_succeeds() {
		$GLOBALS['_wpcv_test_action_scheduler_initialized'] = true;

		$method = new ReflectionMethod( WPCV_Chunk_Dispatcher::class, 'schedule_via_action_scheduler' );
		$method->setAccessible( true );

		$method->invoke( null, 42, 0 );

		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'] );
	}

	/**
	 * `schedule_via_action_scheduler()` が、即時予約(`$delay_seconds = 0`)経路で
	 * `as_enqueue_async_action()` が `0`(予約失敗)を返した場合に `RuntimeException`
	 * を投げることを確認する(v0.4.0コードレビューCR-06是正: 戻り値を確認せず
	 * 捨てていたため、予約失敗時にrunを再度起こす手段が無いまま永久に
	 * 停止していた不具合への対策).
	 *
	 * @return void
	 */
	public function test_schedule_via_action_scheduler_throws_when_immediate_enqueue_fails() {
		$GLOBALS['_wpcv_test_action_scheduler_initialized'] = true;
		$GLOBALS['_wpcv_test_as_enqueue_return_zero']       = true;

		$method = new ReflectionMethod( WPCV_Chunk_Dispatcher::class, 'schedule_via_action_scheduler' );
		$method->setAccessible( true );

		$this->expectException( RuntimeException::class );

		$method->invoke( null, 42, 0 );
	}

	/**
	 * `schedule_via_action_scheduler()` が、遅延予約(`$delay_seconds > 0`)経路で
	 * `as_schedule_single_action()` が `0`(予約失敗)を返した場合に
	 * `RuntimeException` を投げることを確認する(v0.4.0コードレビューCR-06是正:
	 * 即時予約と同じ不具合が遅延予約側にもあった).
	 *
	 * @return void
	 */
	public function test_schedule_via_action_scheduler_throws_when_delayed_schedule_fails() {
		$GLOBALS['_wpcv_test_action_scheduler_initialized']   = true;
		$GLOBALS['_wpcv_test_as_schedule_single_return_zero'] = true;

		$method = new ReflectionMethod( WPCV_Chunk_Dispatcher::class, 'schedule_via_action_scheduler' );
		$method->setAccessible( true );

		$this->expectException( RuntimeException::class );

		$method->invoke( null, 42, 120 );
	}

	/**
	 * `schedule_via_action_scheduler()` が、Action Scheduler未初期化時は
	 * (従来どおり)何もせず例外も投げないことを確認する(可用性が無い場合の
	 * 既存の早期returnがCR-06是正で変わっていないことの回帰確認).
	 *
	 * @return void
	 */
	public function test_schedule_via_action_scheduler_does_nothing_when_action_scheduler_not_initialized() {
		$method = new ReflectionMethod( WPCV_Chunk_Dispatcher::class, 'schedule_via_action_scheduler' );
		$method->setAccessible( true );

		$method->invoke( null, 42, 0 );

		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );
	}
}
