<?php
/**
 * WPCV_Scheduler のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-context-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-plugin.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-runner-async.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-scheduler.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Scheduler` のテスト.
 *
 * `next_timestamp_after()` の計算・二重予約防止・`handle_event()` の
 * オーケストレーション(stale sweep → enqueue → 再予約)を検証する。
 * 実際に WP-Cron が発火して run が完走することの確認は実地検証(v0.3計画書
 * §14参照)側の責務.
 */
class SchedulerTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset(
			$GLOBALS['_wpcv_test_is_multisite'],
			$GLOBALS['_wpcv_test_options'],
			$GLOBALS['_wpcv_test_site_options'],
			$GLOBALS['_wpcv_test_scheduled_hooks'],
			$GLOBALS['_wpcv_test_schedule_single_event_calls'],
			$GLOBALS['_wpcv_test_clear_scheduled_hook_calls'],
			$GLOBALS['_wpcv_test_as_enqueue_calls'],
			$GLOBALS['_wpcv_test_bloginfo']
		);
		wpcv_test_inject_repository();
	}

	/**
	 * 各テストの後、次のテストに影響しないよう `WPCV_Plugin` のキャッシュを戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_repository();
		parent::tearDown();
	}

	/**
	 * 当日中にまだ来ていない時刻を指定した場合、当日のその時刻が返ることを確認する.
	 *
	 * @return void
	 */
	public function test_next_timestamp_after_returns_today_when_time_not_yet_passed() {
		$now = gmmktime( 1, 0, 0, 9, 9, 2026 );

		$this->assertSame(
			gmmktime( 3, 0, 0, 9, 9, 2026 ),
			WPCV_Scheduler::next_timestamp_after( $now, 3, 0 )
		);
	}

	/**
	 * 当日中に既に過ぎている時刻を指定した場合、翌日のその時刻が返ることを確認する.
	 *
	 * @return void
	 */
	public function test_next_timestamp_after_returns_tomorrow_when_time_already_passed() {
		$now = gmmktime( 5, 0, 0, 9, 9, 2026 );

		$this->assertSame(
			gmmktime( 3, 0, 0, 9, 10, 2026 ),
			WPCV_Scheduler::next_timestamp_after( $now, 3, 0 )
		);
	}

	/**
	 * `activate()` は未予約のときだけ `wp_schedule_single_event()` を呼ぶことを確認する.
	 *
	 * @return void
	 */
	public function test_activate_schedules_when_not_already_scheduled() {
		WPCV_Scheduler::activate();

		$this->assertCount( 1, $GLOBALS['_wpcv_test_schedule_single_event_calls'] );
		$this->assertNotFalse( wp_next_scheduled( WPCV_Scheduler::HOOK ) );
	}

	/**
	 * `activate()` は既に予約済みなら何もしない(二重予約防止)ことを確認する.
	 *
	 * @return void
	 */
	public function test_activate_does_nothing_when_already_scheduled() {
		wp_schedule_single_event( 12345, WPCV_Scheduler::HOOK );
		unset( $GLOBALS['_wpcv_test_schedule_single_event_calls'] );

		WPCV_Scheduler::activate();

		$this->assertArrayNotHasKey( '_wpcv_test_schedule_single_event_calls', $GLOBALS );
	}

	/**
	 * `deactivate()` は `wp_clear_scheduled_hook()` を呼ぶことを確認する.
	 *
	 * @return void
	 */
	public function test_deactivate_clears_scheduled_hook() {
		wp_schedule_single_event( 12345, WPCV_Scheduler::HOOK );

		WPCV_Scheduler::deactivate();

		$this->assertFalse( wp_next_scheduled( WPCV_Scheduler::HOOK ) );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_clear_scheduled_hook_calls'] );
	}

	/**
	 * `reschedule()` は既に予約済みでもクリアしてから新しい時刻で予約し直す
	 * (設定変更を即座に反映するため)ことを確認する.
	 *
	 * @return void
	 */
	public function test_reschedule_overwrites_existing_schedule() {
		wp_schedule_single_event( 12345, WPCV_Scheduler::HOOK );
		unset( $GLOBALS['_wpcv_test_schedule_single_event_calls'] );

		WPCV_Scheduler::reschedule();

		$this->assertCount( 1, $GLOBALS['_wpcv_test_clear_scheduled_hook_calls'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_schedule_single_event_calls'] );
		$this->assertNotSame( 12345, wp_next_scheduled( WPCV_Scheduler::HOOK ) );
	}

	/**
	 * `handle_event()` が stale run 検知 → enqueue → 再予約の順で行うことを確認する.
	 *
	 * @return void
	 */
	public function test_handle_event_sweeps_stale_runs_enqueues_and_reschedules() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$now  = static function () {
			return '2026-09-09 12:00:00';
		};

		// stale 判定の閾値(`WPCV_Scheduler::STALE_THRESHOLD_MINUTES` = 180分 = 3時間)
		// より古い running 行を1件仕込む(09:00 より前は stale).
		$wpdb->insert(
			'wp_wpcv_runs',
			array(
				'started_at'  => '2026-09-09 08:00:00',
				'status'      => 'running',
				'run_trigger' => 'cron',
				'runner'      => 'async',
			)
		);

		wpcv_test_inject_repository( new WPCV_Repository( $wpdb, $now ) );

		WPCV_Scheduler::handle_event();

		// 1. stale run が failed 化されている.
		$this->assertSame( 'failed', $wpdb->rows['wp_wpcv_runs'][1]['status'] );

		// 2. `WPCV_Runner_Async::enqueue_run( 'cron' )` が enqueue 経路を通っている
		// (テスト環境では `as_enqueue_async_action()` スタブが常設されているため
		// 可用性チェックは常に真になる. `RunnerAsyncTest` の docblock参照).
		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'] );
		list( $hook, $args ) = $GLOBALS['_wpcv_test_as_enqueue_calls'][0];
		$this->assertSame( WPCV_Runner_Async::HOOK, $hook );
		$this->assertSame( array( 'cron' ), $args );

		// 3. 次回分が自己連鎖で再予約されている.
		$this->assertNotFalse( wp_next_scheduled( WPCV_Scheduler::HOOK ) );
	}
}
