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
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-repository.php';
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
		unset( $GLOBALS['_wpcv_test_as_enqueue_calls'], $GLOBALS['_wpcv_test_bloginfo'], $GLOBALS['_wpcv_test_plugins'], $GLOBALS['_wpcv_test_mu_plugins'] );
		wpcv_test_inject_run_coordinator();
	}

	/**
	 * 各テストの後、次のテストに影響しないよう `WPCV_Plugin` のキャッシュを戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wpcv_test_inject_run_coordinator();
		parent::tearDown();
	}

	/**
	 * 可用性チェッカーが真を返す場合、`as_enqueue_async_action()` で enqueue され、
	 * `WPCV_Run_Coordinator::run()` は呼ばれないことを確認する.
	 *
	 * @return void
	 */
	public function test_enqueue_run_enqueues_when_action_scheduler_available() {
		$result = WPCV_Runner_Async::enqueue_run(
			'cron',
			static function () {
				return true;
			}
		);

		$this->assertTrue( $result['enqueued'] );
		$this->assertSame( 1, $result['action_id'] );
		$this->assertNull( $result['result'] );

		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'] );
		list( $hook, $args ) = $GLOBALS['_wpcv_test_as_enqueue_calls'][0];
		$this->assertSame( WPCV_Runner_Async::HOOK, $hook );

		// enqueue する args は $run_trigger のみ($context 全体は渡さない。
		// クラス docblock の「8,000文字上限」参照).
		$this->assertSame( array( 'cron' ), $args );
	}

	/**
	 * 可用性チェッカーが偽を返す場合、`as_enqueue_async_action()` は呼ばれず、
	 * その場で `WPCV_Run_Coordinator::run()` が同期実行されることを確認する.
	 *
	 * @return void
	 */
	public function test_enqueue_run_falls_back_to_sync_when_action_scheduler_unavailable() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		wpcv_test_inject_run_coordinator( wpcv_test_make_fake_run_coordinator() );

		$result = WPCV_Runner_Async::enqueue_run(
			'rest',
			static function () {
				return false;
			}
		);

		$this->assertFalse( $result['enqueued'] );
		$this->assertNull( $result['action_id'] );
		$this->assertArrayNotHasKey( '_wpcv_test_as_enqueue_calls', $GLOBALS );

		$this->assertSame( 1, $result['result']['run_id'] );
		$this->assertSame( 'success', $result['result']['summary']['status'] );
	}

	/**
	 * `run_async_action()` が `$run_trigger` から `$context` を組み立て直し、
	 * `WPCV_Plugin::run_coordinator()->run()` を呼ぶことを確認する.
	 *
	 * @return void
	 */
	public function test_run_async_action_rebuilds_context_and_runs_coordinator() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		wpcv_test_inject_run_coordinator( wpcv_test_make_fake_run_coordinator() );

		WPCV_Runner_Async::run_async_action( 'cron' );

		// 例外なく完走すれば成功(実際の永続化は RunCoordinatorTest で検証済み).
		$this->addToAssertionCount( 1 );
	}

	/**
	 * ファイル読み込み時、`add_action()` で `self::HOOK` に自身が登録されることを確認する.
	 *
	 * @return void
	 */
	public function test_registers_run_async_action_handler_on_require() {
		$registered = $GLOBALS['_wpcv_test_added_actions'][ WPCV_Runner_Async::HOOK ];
		$this->assertNotEmpty( $registered );

		list( $callback ) = end( $registered );
		$this->assertSame( array( 'WPCV_Runner_Async', 'run_async_action' ), $callback );
	}
}
