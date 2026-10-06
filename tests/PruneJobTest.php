<?php
/**
 * WPCV_Prune_Job のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-static-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-finding-key.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-generation-differ.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-chunk-result-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-file-state-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-current-version-reader.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-starter.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-github-client.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-github-mappings.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-context-builder.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-plugin.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-runner-async.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-update-event-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-update-event-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-advisory-lock.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-retention-cleaner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-prune-job.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * 管理画面の「古い履歴を今すぐ削除」の実体 `WPCV_Prune_Job` のテスト(0.10.0 プラン §8.4・§8.5).
 *
 * 削除の判定は `RetentionCleanerTest` が見ている. ここでは予約・繰り返し・状態の保存と、
 * 組み合わせ表(#1・#6〜#9)の動きを、`prune()` を差し替えて確かめる.
 */
class PruneJobTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reset_globals();
		wpcv_test_inject_retention_cleaner();

		// `request()` の受け付けの lock(GET_LOCK)用. 既定は取得成功('1').
		$GLOBALS['wpdb'] = new WPCV_Test_Fake_WPDB();
	}

	/**
	 * 各テストの後に状態を残さない.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->reset_globals();
		wpcv_test_inject_retention_cleaner();
		parent::tearDown();
	}

	/**
	 * このテストが使う `$GLOBALS` を消す.
	 *
	 * @return void
	 */
	private function reset_globals() {
		unset(
			$GLOBALS['_wpcv_test_options'],
			$GLOBALS['_wpcv_test_site_options'],
			$GLOBALS['_wpcv_test_is_multisite'],
			$GLOBALS['_wpcv_test_as_enqueue_calls'],
			$GLOBALS['_wpcv_test_as_enqueue_return_zero'],
			$GLOBALS['_wpcv_test_as_has_scheduled'],
			$GLOBALS['_wpcv_test_as_has_scheduled_calls'],
			$GLOBALS['_wpcv_test_action_scheduler_initialized'],
			$GLOBALS['_wpcv_test_as_schedule_single_calls'],
			$GLOBALS['_wpcv_test_as_schedule_single_return_zero'],
			$GLOBALS['wpdb']
		);
	}

	/**
	 * 保持期間を設定する.
	 *
	 * @param int $months 月数(0 は無期限).
	 * @return void
	 */
	private function set_retention( $months ) {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array( 'retention_months' => $months );
	}

	/**
	 * Action Scheduler を使える状態にする.
	 *
	 * @return void
	 */
	private function enable_action_scheduler() {
		$GLOBALS['_wpcv_test_action_scheduler_initialized'] = true;
	}

	/**
	 * `prune()` の結果を順に返す削除処理を差し込む.
	 *
	 * @param array[] $results 返す結果.
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function inject_cleaner( array $results ) {
		$cleaner = $this->createMock( WPCV_Retention_Cleaner::class );
		$cleaner->method( 'prune' )->willReturnOnConsecutiveCalls( ...$results );
		wpcv_test_inject_retention_cleaner( $cleaner );

		return $cleaner;
	}

	/**
	 * 件数の結果を作る.
	 *
	 * @param int  $runs      run の件数.
	 * @param int  $target    target_run の件数.
	 * @param bool $remaining 上限で止めたか.
	 * @return array
	 */
	private static function result( $runs, $target, $remaining ) {
		return array(
			'suppressions' => 1,
			'target_runs'  => $target,
			'findings'     => 2,
			'runs'         => $runs,
			'remaining'    => $remaining,
		);
	}

	/**
	 * 保持期間が無期限なら何もしない(§8.5 #1).
	 *
	 * @return void
	 */
	public function test_request_does_nothing_when_unlimited() {
		$this->set_retention( 0 );
		$this->enable_action_scheduler();

		$this->assertSame( WPCV_Prune_Job::RESULT_UNLIMITED, WPCV_Prune_Job::request()['result'] );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );
		$this->assertNull( WPCV_Prune_Job::get_status() );
	}

	/**
	 * 通常は Action Scheduler のアクションを `wpcv` グループで1つ予約し、状態を running にする(§8.4).
	 *
	 * @return void
	 */
	public function test_request_schedules_action_and_marks_running() {
		$this->set_retention( 12 );
		$this->enable_action_scheduler();

		$result = WPCV_Prune_Job::request();

		$this->assertSame( WPCV_Prune_Job::RESULT_SCHEDULED, $result['result'] );
		$this->assertNull( $result['error'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'] );
		$this->assertSame( WPCV_Prune_Job::HOOK, $GLOBALS['_wpcv_test_as_enqueue_calls'][0][0] );
		$this->assertSame( 'wpcv', $GLOBALS['_wpcv_test_as_enqueue_calls'][0][2] );

		$GLOBALS['_wpcv_test_as_has_scheduled'][ WPCV_Prune_Job::HOOK ] = true;
		$this->assertSame( WPCV_Prune_Job::STATE_RUNNING, WPCV_Prune_Job::get_status()['state'] );
	}

	/**
	 * すでに予約済み・実行中なら、二重に予約しない(§8.5 #6).
	 *
	 * @return void
	 */
	public function test_request_does_not_schedule_twice() {
		$this->set_retention( 12 );
		$this->enable_action_scheduler();
		$GLOBALS['_wpcv_test_as_has_scheduled'][ WPCV_Prune_Job::HOOK ] = true;

		$this->assertSame( WPCV_Prune_Job::RESULT_ALREADY_RUNNING, WPCV_Prune_Job::request()['result'] );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );
	}

	/**
	 * 予約に失敗したら、状態を failed にして error を返す(止まったまま「実行中」に見せない).
	 *
	 * @return void
	 */
	public function test_request_marks_failed_when_enqueue_fails() {
		$this->set_retention( 12 );
		$this->enable_action_scheduler();
		$GLOBALS['_wpcv_test_as_enqueue_return_zero'] = true;

		$result = WPCV_Prune_Job::request();

		$this->assertSame( 'enqueue_failed', $result['error'] );
		$this->assertSame( WPCV_Prune_Job::STATE_FAILED, WPCV_Prune_Job::get_status()['state'] );
	}

	/**
	 * Action Scheduler が使えないときは、この場で1回分だけ消し、続きがあれば partial にする(§8.5 #9).
	 *
	 * @return void
	 */
	public function test_request_runs_one_batch_inline_without_action_scheduler() {
		$this->set_retention( 12 );
		$this->inject_cleaner( array( self::result( 1, 500, true ) ) );

		$result = WPCV_Prune_Job::request();
		$status = WPCV_Prune_Job::get_status();

		$this->assertSame( WPCV_Prune_Job::RESULT_INLINE, $result['result'] );
		$this->assertNull( $result['error'] );
		$this->assertSame( WPCV_Prune_Job::STATE_PARTIAL, $status['state'] );
		$this->assertSame( 500, $status['totals']['target_runs'] );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );
	}

	/**
	 * Action Scheduler が使えず、1回で終わったときは done になる.
	 *
	 * @return void
	 */
	public function test_request_inline_finishes_as_done_when_nothing_remains() {
		$this->set_retention( 12 );
		$this->inject_cleaner( array( self::result( 2, 30, false ) ) );

		WPCV_Prune_Job::request();

		$status = WPCV_Prune_Job::get_status();
		$this->assertSame( WPCV_Prune_Job::STATE_DONE, $status['state'] );
		$this->assertNotNull( $status['finished_at'] );
	}

	/**
	 * アクションは上限で止まる間、次のアクションを予約し直し、件数を合計する(§8.5 #3).
	 *
	 * @return void
	 */
	public function test_run_action_reschedules_while_remaining_and_sums_totals() {
		$this->set_retention( 12 );
		$this->enable_action_scheduler();
		$this->inject_cleaner( array( self::result( 1, 500, true ), self::result( 2, 40, false ) ) );

		WPCV_Prune_Job::run_action();

		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'], '残りがあるので次を予約する' );
		$GLOBALS['_wpcv_test_as_has_scheduled'][ WPCV_Prune_Job::HOOK ] = true;
		$this->assertSame( WPCV_Prune_Job::STATE_RUNNING, WPCV_Prune_Job::get_status()['state'] );

		WPCV_Prune_Job::run_action();

		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'], '最後は予約しない' );
		unset( $GLOBALS['_wpcv_test_as_has_scheduled'] );
		$status = WPCV_Prune_Job::get_status();
		$this->assertSame( WPCV_Prune_Job::STATE_DONE, $status['state'] );
		$this->assertSame( 540, $status['totals']['target_runs'] );
		$this->assertSame( 3, $status['totals']['runs'] );
		$this->assertSame( 4, $status['totals']['findings'] );
	}

	/**
	 * 途中で保持期間を「無期限」に変えたら、次のアクションは何も消さず止まる(§8.5 #7).
	 *
	 * @return void
	 */
	public function test_run_action_stops_when_retention_becomes_unlimited() {
		$this->set_retention( 0 );
		$cleaner = $this->createMock( WPCV_Retention_Cleaner::class );
		$cleaner->expects( $this->never() )->method( 'prune' );
		wpcv_test_inject_retention_cleaner( $cleaner );

		WPCV_Prune_Job::run_action();

		$this->assertSame( WPCV_Prune_Job::STATE_CANCELLED, WPCV_Prune_Job::get_status()['state'] );
	}

	/**
	 * 途中で保持期間を短くしたら、次のアクションから新しい期間で消す(§8.5 #8).
	 *
	 * @return void
	 */
	public function test_run_action_uses_current_retention_each_time() {
		$this->enable_action_scheduler();
		$cleaner = $this->createMock( WPCV_Retention_Cleaner::class );
		$cleaner->expects( $this->exactly( 2 ) )->method( 'prune' )->willReturnCallback(
			function ( $months ) {
				static $call = 0;
				++$call;
				$this->assertSame( 1 === $call ? 24 : 3, $months );

				return self::result( 0, 1, 1 === $call );
			}
		);
		wpcv_test_inject_retention_cleaner( $cleaner );

		$this->set_retention( 24 );
		WPCV_Prune_Job::run_action();
		$this->set_retention( 3 );
		WPCV_Prune_Job::run_action();
	}

	/**
	 * 削除に失敗したら状態を failed にして、例外を投げ直す(Action Scheduler に失敗として記録させる).
	 *
	 * @return void
	 */
	public function test_run_action_records_failure_and_rethrows() {
		$this->set_retention( 12 );
		$cleaner = $this->createMock( WPCV_Retention_Cleaner::class );
		$cleaner->method( 'prune' )->willThrowException( new RuntimeException( 'db error' ) );
		wpcv_test_inject_retention_cleaner( $cleaner );

		try {
			WPCV_Prune_Job::run_action();
			$this->fail( '例外が投げ直されるはず' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'db error', $e->getMessage() );
		}

		$this->assertSame( WPCV_Prune_Job::STATE_FAILED, WPCV_Prune_Job::get_status()['state'] );
	}

	/**
	 * 状態が running でもアクションが無ければ interrupted として返す(無効化などで消えた場合).
	 *
	 * @return void
	 */
	public function test_get_status_reports_interrupted_when_action_is_gone() {
		$this->set_retention( 12 );
		$this->enable_action_scheduler();

		WPCV_Prune_Job::request();

		$this->assertSame( 'interrupted', WPCV_Prune_Job::get_status()['state'] );
	}

	/**
	 * マルチサイトでは状態を site option に保存する.
	 *
	 * @return void
	 */
	public function test_status_is_stored_as_site_option_on_multisite() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;
		$this->inject_cleaner( array( self::result( 0, 1, false ) ) );
		$GLOBALS['_wpcv_test_site_options'][ WPCV_Settings::OPTION_NAME ] = array( 'retention_months' => 12 );

		WPCV_Prune_Job::request();

		$this->assertArrayHasKey( WPCV_Prune_Job::STATUS_OPTION, $GLOBALS['_wpcv_test_site_options'] );
		$this->assertArrayNotHasKey( WPCV_Prune_Job::STATUS_OPTION, $GLOBALS['_wpcv_test_options'] ?? array() );
	}

	/**
	 * 無効化・アンインストールの後片付けに含まれる前提(§8.5 #11)を確かめる.
	 *
	 * - 無効化は `WPCV_Chunk_Dispatcher::deactivate()` が group `wpcv` のアクションをすべて取り消す.
	 *   削除アクションが同じ group にあれば、一緒に消える.
	 * - アンインストールは hook が `wpcv_` で始まる Action Scheduler の行を消す(`uninstall.php`).
	 *
	 * @return void
	 */
	public function test_action_is_covered_by_deactivation_and_uninstall_cleanup() {
		$this->assertSame( WPCV_Chunk_Dispatcher::GROUP, WPCV_Prune_Job::GROUP );
		$this->assertStringStartsWith( 'wpcv_', WPCV_Prune_Job::HOOK );

		$this->enable_action_scheduler();
		WPCV_Chunk_Dispatcher::deactivate();

		$this->assertSame( array( '', array(), WPCV_Prune_Job::GROUP ), $GLOBALS['_wpcv_test_as_unschedule_all_calls'][0] );
		unset( $GLOBALS['_wpcv_test_as_unschedule_all_calls'] );
	}

	/**
	 * 受け付けの lock が取れない(別の受け付けが進行中)ときは、予約せず「すでに削除中」を返すことを確認する(指摘2).
	 *
	 * @return void
	 */
	public function test_request_returns_already_running_when_request_lock_is_taken() {
		$this->set_retention( 12 );
		$this->enable_action_scheduler();
		$GLOBALS['wpdb']->get_var_return = '0';

		$this->assertSame( WPCV_Prune_Job::RESULT_ALREADY_RUNNING, WPCV_Prune_Job::request()['result'] );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );
		$this->assertNull( WPCV_Prune_Job::get_status(), '状態も書き換えない' );
	}

	/**
	 * 受け付けは lock を取り、「確認 → 予約」の後で必ず放すことを確認する.
	 *
	 * @return void
	 */
	public function test_request_takes_and_releases_request_lock() {
		$this->set_retention( 12 );
		$this->enable_action_scheduler();

		WPCV_Prune_Job::request();

		$this->assertStringContainsString( "GET_LOCK('wpcv_prune_request_wp_', 5)", $GLOBALS['wpdb']->get_var_calls[0] );
		$this->assertStringContainsString( "RELEASE_LOCK('wpcv_prune_request_wp_')", end( $GLOBALS['wpdb']->query_calls ) );
	}

	/**
	 * アクションの実行時に別の削除が lock を持っていれば、件数を足さずに 60 秒後へ予約し直すことを確認する(指摘2).
	 *
	 * @return void
	 */
	public function test_run_action_retries_later_when_prune_is_locked() {
		$this->set_retention( 12 );
		$this->enable_action_scheduler();
		$locked              = self::result( 0, 0, true );
		$locked['locked']    = true;
		$this->inject_cleaner( array( $locked ) );

		$before = time();
		WPCV_Prune_Job::run_action();

		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS, 'すぐには予約しない' );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_schedule_single_calls'] );
		list( $timestamp, $hook, , $group ) = $GLOBALS['_wpcv_test_as_schedule_single_calls'][0];
		$this->assertSame( WPCV_Prune_Job::HOOK, $hook );
		$this->assertSame( 'wpcv', $group );
		$this->assertGreaterThanOrEqual( $before + WPCV_Prune_Job::LOCKED_RETRY_DELAY_SECONDS, $timestamp );

		$GLOBALS['_wpcv_test_as_has_scheduled'][ WPCV_Prune_Job::HOOK ] = true;
		$status = WPCV_Prune_Job::get_status();
		$this->assertSame( WPCV_Prune_Job::STATE_RUNNING, $status['state'] );
		$this->assertSame( 0, $status['totals']['findings'], '何も消していないので件数を足さない' );
	}

	/**
	 * 予約し直しに失敗したら、止まったまま「実行中」に見せないよう failed にすることを確認する.
	 *
	 * @return void
	 */
	public function test_run_action_marks_failed_when_retry_cannot_be_scheduled() {
		$this->set_retention( 12 );
		$this->enable_action_scheduler();
		$GLOBALS['_wpcv_test_as_schedule_single_return_zero'] = true;
		$locked           = self::result( 0, 0, true );
		$locked['locked'] = true;
		$this->inject_cleaner( array( $locked ) );

		WPCV_Prune_Job::run_action();

		$this->assertSame( WPCV_Prune_Job::STATE_FAILED, WPCV_Prune_Job::get_status()['state'] );
	}

	/**
	 * Action Scheduler が無くその場で消すとき、lock が取れなければ状態を変えず「すでに削除中」を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_inline_returns_already_running_when_prune_is_locked() {
		$this->set_retention( 12 );
		$locked           = self::result( 0, 0, true );
		$locked['locked'] = true;
		$this->inject_cleaner( array( $locked ) );

		$this->assertSame( WPCV_Prune_Job::RESULT_ALREADY_RUNNING, WPCV_Prune_Job::request()['result'] );
		$this->assertNull( WPCV_Prune_Job::get_status() );
	}
}
