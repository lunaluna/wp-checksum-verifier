<?php
/**
 * WPCV_CLI_Command のテスト.
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
require_once dirname( __DIR__ ) . '/includes/cli/class-wpcv-cli-command.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wp wpcv run` の実体である `WPCV_CLI_Command::__invoke()` のテスト.
 *
 * 実際の `WPCV_Plugin::run_coordinator()`(composition root)は `global $wpdb`
 * を必要とするため、`doubles.php` の `wpcv_test_make_fake_run_coordinator()` /
 * `wpcv_test_inject_run_coordinator()` で手書きテストダブルに差し替える
 * (composition root 自体は `PluginTest` で別途検証済み).
 */
class CliCommandTest extends TestCase {

	/**
	 * ファイル読み込み時(`require_once` 直後、いずれかのテストの `setUp()` が
	 * `$GLOBALS` を掃除するより前)に記録された `WP_CLI::add_command()` の呼び出し引数.
	 *
	 * @var array|false
	 */
	private static $registered_command;

	/**
	 * クラス内の全テストに先立って一度だけ実行する.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$registered_command = end( $GLOBALS['_wpcv_test_wp_cli_calls']['add_command'] );
	}

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_wp_cli_calls'], $GLOBALS['_wpcv_test_bloginfo'], $GLOBALS['_wpcv_test_plugins'], $GLOBALS['_wpcv_test_mu_plugins'], $GLOBALS['_wpcv_test_as_enqueue_calls'] );
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
	 * 正常系: run が完走し、`WP_CLI::success()` が呼ばれることを確認する.
	 *
	 * @return void
	 */
	public function test_invoke_runs_verification_and_reports_success() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		wpcv_test_inject_run_coordinator( wpcv_test_make_fake_run_coordinator() );

		$command = new WPCV_CLI_Command();
		$command->__invoke( array(), array() );

		$this->assertArrayNotHasKey( 'error', $GLOBALS['_wpcv_test_wp_cli_calls'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_cli_calls']['success'] );
		$this->assertStringContainsString( 'run #1', $GLOBALS['_wpcv_test_wp_cli_calls']['success'][0] );
		$this->assertStringContainsString( 'success', $GLOBALS['_wpcv_test_wp_cli_calls']['success'][0] );
	}

	/**
	 * version が取得できない(空文字)場合、`WPCV_Run_Coordinator` が投げる
	 * `InvalidArgumentException` が `WP_CLI::error()` に変換されることを確認する.
	 *
	 * @return void
	 */
	public function test_invoke_converts_invalid_argument_exception_to_wp_cli_error() {
		// get_bloginfo('version') のスタブを未設定のままにし、空文字を返させる.
		wpcv_test_inject_run_coordinator( wpcv_test_make_fake_run_coordinator() );

		$command = new WPCV_CLI_Command();
		$command->__invoke( array(), array() );

		$this->assertArrayNotHasKey( 'success', $GLOBALS['_wpcv_test_wp_cli_calls'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_cli_calls']['error'] );
		$this->assertStringContainsString( 'requires', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0] );
		$this->assertStringContainsString( 'version', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0] );
	}

	/**
	 * `--async` 指定時、`WPCV_Runner_Async::enqueue_run()` 経由で enqueue され、
	 * `WP_CLI::success()` に action_id を含むメッセージが渡されることを確認する
	 * (`as_enqueue_async_action()` は `wp-stubs.php` に常設のスタブがあり、
	 * このテスト環境では常に「利用可能」側の分岐になる).
	 *
	 * @return void
	 */
	public function test_invoke_with_async_flag_enqueues_via_runner_async() {
		$command = new WPCV_CLI_Command();
		$command->__invoke( array(), array( 'async' => true ) );

		$this->assertCount( 1, $GLOBALS['_wpcv_test_as_enqueue_calls'] );
		list( $hook, $args ) = $GLOBALS['_wpcv_test_as_enqueue_calls'][0];
		$this->assertSame( WPCV_Runner_Async::HOOK, $hook );
		$this->assertSame( array( 'cli' ), $args );

		$this->assertArrayNotHasKey( 'error', $GLOBALS['_wpcv_test_wp_cli_calls'] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_wp_cli_calls']['success'] );
		$this->assertStringContainsString( 'action_id', $GLOBALS['_wpcv_test_wp_cli_calls']['success'][0] );
	}

	/**
	 * `format_result()` が summary を1行の要約文字列に整形することを確認する.
	 *
	 * @return void
	 */
	public function test_format_result_summarizes_counts() {
		$formatted = WPCV_CLI_Command::format_result(
			array(
				'run_id'  => 1,
				'summary' => array(
					'status'               => 'partial',
					'targets_total'        => 3,
					'targets_verified'     => 1,
					'targets_unverifiable' => 1,
					'targets_failed'       => 1,
					'findings_total'       => 5,
				),
			)
		);

		$this->assertSame( 'targets: 3 total / 1 verified / 1 unverifiable / 1 failed, findings: 5', $formatted );
	}

	/**
	 * ファイル読み込み時、`WP_CLI::add_command()` が正しい引数で呼ばれることを確認する
	 * (`class_exists( 'WP_CLI' )` を満たす本テストのスタブ環境下での確認).
	 *
	 * @return void
	 */
	public function test_command_registers_itself_with_wp_cli_on_require() {
		$this->assertNotFalse( self::$registered_command );
		$this->assertSame( 'wpcv run', self::$registered_command[0] );
		$this->assertSame( 'WPCV_CLI_Command', self::$registered_command[1] );
	}
}
