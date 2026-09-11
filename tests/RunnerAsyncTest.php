<?php
/**
 * WPCV_Runner_Async のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-context-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-plugin.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-runner-async.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Runner_Async::enqueue_run()` / `run_async_action()` のテスト.
 *
 * 可用性チェッカーを注入可能にしてあるため、`function_exists( 'as_enqueue_async_action' )`
 * を実際にスタブ関数で真にする必要は無い(`WPCV_Runner_Async` の docblock 参照:
 * PHP は一度定義した関数を未定義に戻せないため、固定チェックにするとテスト間で
 * 状態が漏れる。`as_enqueue_async_action()` 自体のスタブは「呼ばれたことの記録」
 * 専用として `wp-stubs.php` に常設してある).
 */
class RunnerAsyncTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_as_enqueue_calls'], $GLOBALS['_wpcv_test_bloginfo'], $GLOBALS['_wpcv_test_plugins'], $GLOBALS['_wpcv_test_mu_plugins'], $GLOBALS['_wpcv_test_as_enqueue_return_zero'], $GLOBALS['_wpcv_test_action_scheduler_initialized'] );
		wpcv_test_inject_run_coordinator();
		wpcv_test_inject_run_repository();
	}

	/**
	 * 各テストの後、次のテストに影響しないよう `WPCV_Plugin` のキャッシュを戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_run_coordinator();
		wpcv_test_inject_run_repository();
		parent::tearDown();
	}

	/**
	 * 可用性チェッカーが真を返す場合、事前に `queued` run を予約してから
	 * `as_enqueue_async_action()` で enqueue され、`WPCV_Run_Coordinator::run()` は
	 * 呼ばれないことを確認する(v0.3.1 §Step2).
	 *
	 * @return void
	 */
	public function test_enqueue_run_enqueues_when_action_scheduler_available() {
		$made = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$result = WPCV_Runner_Async::enqueue_run(
			'cron',
			static function () {
				return true;
			}
		);

		$this->assertTrue( $result['enqueued'] );
		$this->assertSame( 1, $result['action_id'] );
		$this->assertNull( $result['result'] );
		$this->assertFalse( $result['busy'] );

		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'] );
		list( $hook, $args, $group, $unique ) = $GLOBALS['_wpcv_test_as_enqueue_calls'][0];
		$this->assertSame( WPCV_Runner_Async::HOOK, $hook );

		// enqueue する args は run_id と $run_trigger のみ($context 全体は渡さない。
		// クラス docblock の「8,000文字上限」参照).v0.3.1 §Step2でrun_idが加わった.
		$this->assertSame( array( 1, 'cron' ), $args );
		$this->assertSame( WPCV_Runner_Async::GROUP, $group );
		$this->assertTrue( $unique );

		// enqueue 前に queued run が予約されていることを確認する.
		$row = $made['wpdb']->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'queued', $row['status'] );
		$this->assertSame( 'cron', $row['run_trigger'] );
		$this->assertSame( 'async', $row['runner'] );
	}

	/**
	 * 可用性チェッカーが真で、かつ既に active な run がある場合、enqueue を試みず
	 * `busy: true` を返すことを確認する(v0.3.1 §Step2: enqueue待ち中の再受付が
	 * 同じactive runを返し、2件目をenqueueしないことのプラン記載テスト).
	 *
	 * @return void
	 */
	public function test_enqueue_run_reports_busy_before_enqueue_when_active_run_exists() {
		$made = wpcv_test_make_fake_environment();
		$made['run_repository']->reserve_run();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$result = WPCV_Runner_Async::enqueue_run(
			'cron',
			static function () {
				return true;
			}
		);

		$this->assertFalse( $result['enqueued'] );
		$this->assertTrue( $result['busy'] );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );
		$this->assertCount( 1, $made['wpdb']->rows['wp_wpcv_runs'] );
	}

	/**
	 * `as_enqueue_async_action()` が `0`(enqueue失敗)を返した場合、成功扱いせず
	 * 予約済みの queued run を failed にすることを確認する(プラン§P1
	 * 「enqueue失敗を成功扱いする」への対策. v0.3.1 §Step2).
	 *
	 * @return void
	 */
	public function test_enqueue_run_marks_queued_run_failed_when_as_enqueue_returns_zero() {
		$GLOBALS['_wpcv_test_as_enqueue_return_zero'] = true;

		$made = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$result = WPCV_Runner_Async::enqueue_run(
			'cron',
			static function () {
				return true;
			}
		);

		$this->assertFalse( $result['enqueued'] );
		$this->assertNull( $result['action_id'] );
		$this->assertNull( $result['result'] );
		$this->assertFalse( $result['busy'] );

		$row = $made['wpdb']->rows['wp_wpcv_runs'][1];
		$this->assertSame( 'failed', $row['status'] );
		$this->assertNotEmpty( $row['notes'] );
	}

	/**
	 * 既定(引数省略時)の可用性チェッカーが `ActionScheduler::is_initialized()` を
	 * 見ることを確認する(v0.3.1 §Step2「AS関数の存在だけでなく、初期化済みかを
	 * 判定する」)。未初期化(既定 false)なら同期フォールバックへ倒れる.
	 *
	 * @return void
	 */
	public function test_enqueue_run_default_checker_falls_back_when_action_scheduler_not_initialized() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		$made                           = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		// $GLOBALS['_wpcv_test_action_scheduler_initialized'] を設定しない
		// (既定 false = ActionScheduler::is_initialized() が偽を返す).
		$result = WPCV_Runner_Async::enqueue_run( 'cron' );

		$this->assertFalse( $result['enqueued'] );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );
		$this->assertSame( 'success', $result['result']['summary']['status'] );
	}

	/**
	 * 既定の可用性チェッカーが、`ActionScheduler::is_initialized()` が真のときは
	 * enqueue 経路を選ぶことを確認する.
	 *
	 * @return void
	 */
	public function test_enqueue_run_default_checker_enqueues_when_action_scheduler_initialized() {
		$GLOBALS['_wpcv_test_action_scheduler_initialized'] = true;

		$made = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$result = WPCV_Runner_Async::enqueue_run( 'cron' );

		$this->assertTrue( $result['enqueued'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'] );
	}

	/**
	 * 可用性チェッカーが偽を返す場合、`as_enqueue_async_action()` は呼ばれず、
	 * その場で `WPCV_Run_Coordinator::run()` が同期実行されることを確認する.
	 *
	 * @return void
	 */
	public function test_enqueue_run_falls_back_to_sync_when_action_scheduler_unavailable() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		$made                           = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$result = WPCV_Runner_Async::enqueue_run(
			'rest',
			static function () {
				return false;
			}
		);

		$this->assertFalse( $result['enqueued'] );
		$this->assertNull( $result['action_id'] );
		$this->assertFalse( $result['busy'] );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );

		$this->assertSame( 1, $result['result']['run_id'] );
		$this->assertSame( 'success', $result['result']['summary']['status'] );
	}

	/**
	 * 可用性チェッカーが偽で、かつ既に active な run がある場合、検証を実行せず
	 * `busy: true` を返すことを確認する(v0.3.1 §Step1: 同期フォールバックも
	 * `reserve_run()` を通すようになったことで同時実行が防止されることの確認).
	 *
	 * @return void
	 */
	public function test_enqueue_run_reports_busy_when_active_run_exists() {
		$made = wpcv_test_make_fake_environment();
		$made['run_repository']->reserve_run();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$result = WPCV_Runner_Async::enqueue_run(
			'rest',
			static function () {
				return false;
			}
		);

		$this->assertFalse( $result['enqueued'] );
		$this->assertTrue( $result['busy'] );
		$this->assertNull( $result['result'] );
	}

	/**
	 * `run_async_action()` が enqueue 時点で予約されていた `queued` run を
	 * `mark_queued_running()` で引き継ぎ、`$run_trigger` から `$context` を
	 * 組み立て直して `WPCV_Plugin::run_coordinator()->run()` を呼ぶことを確認する
	 * (v0.3.1 §Step2).
	 *
	 * @return void
	 */
	public function test_run_async_action_transitions_queued_run_and_runs_coordinator() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		$made                           = wpcv_test_make_fake_environment();
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		$run_id = $made['run_repository']->reserve_run(
			array(
				'run_trigger'    => 'cron',
				'runner'         => 'async',
				'initial_status' => WPCV_Run_Status::QUEUED,
			)
		)['run_id'];

		WPCV_Runner_Async::run_async_action( $run_id, 'cron' );

		$row = $made['wpdb']->rows['wp_wpcv_runs'][ $run_id ];
		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( 'async', $row['runner'] );
		$this->assertSame( 'cron', $row['run_trigger'] );
	}

	/**
	 * 対象 run が `queued` ではない(stale sweep に先を越された、または同じ
	 * Action Scheduler action が2度発火した等)場合、`run_async_action()` は検証を
	 * 実行せず no-op で戻ることを確認する(v0.3.1 §Step2「同じAS actionが
	 * 再実行されても検証は1回だけ行う」).
	 *
	 * @return void
	 */
	public function test_run_async_action_is_noop_when_run_not_queued() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		$made                           = wpcv_test_make_fake_environment();
		$made['wpdb']->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-10 08:00:00',
				'status'      => 'failed',
				'run_trigger' => 'cron',
				'runner'      => 'async',
				'notes'       => 'stale sweep により failed 化済み',
			)
		);
		wpcv_test_inject_run_coordinator( $made['coordinator'] );
		wpcv_test_inject_run_repository( $made['run_repository'] );

		WPCV_Runner_Async::run_async_action( 1, 'cron' );

		// 検証が実行されていない(target_runs テーブルが作られていない)ことを確認する.
		$this->assertArrayNotHasKey( 'wp_wpcv_target_runs', $made['wpdb']->rows );
		$this->assertSame( 'failed', $made['wpdb']->rows['wp_wpcv_runs'][1]['status'] );
	}

	/**
	 * ファイル読み込み時、`add_action()` で `self::HOOK` に自身が
	 * `accepted_args = 2`(run_id・run_trigger)で登録されることを確認する
	 * (v0.3.1 §Step2で1引数から2引数へ変更).
	 *
	 * @return void
	 */
	public function test_registers_run_async_action_handler_on_require() {
		$registered = $GLOBALS['_wpcv_test_added_actions'][ WPCV_Runner_Async::HOOK ];
		$this->assertNotEmpty( $registered );

		list( $callback, , $accepted_args ) = end( $registered );
		$this->assertSame( array( 'WPCV_Runner_Async', 'run_async_action' ), $callback );
		$this->assertSame( 2, $accepted_args );
	}
}
