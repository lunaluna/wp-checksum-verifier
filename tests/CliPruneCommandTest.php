<?php
/**
 * WPCV_CLI_Prune_Command のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-diff-status.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-advisory-lock.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-retention-cleaner.php';
require_once dirname( __DIR__ ) . '/includes/cli/class-wpcv-cli-prune-command.php';
require_once __DIR__ . '/doubles.php';

// require の直後(他のテストの setUp が `_wpcv_test_wp_cli_calls` を掃除するより前)に、
// `wp wpcv prune` の登録を記録しておく.
foreach ( $GLOBALS['_wpcv_test_wp_cli_calls']['add_command'] ?? array() as $wpcv_test_call ) {
	if ( 'wpcv prune' === $wpcv_test_call[0] ) {
		$GLOBALS['_wpcv_test_prune_command_registration'] = $wpcv_test_call;
	}
}
unset( $wpcv_test_call );

use PHPUnit\Framework\TestCase;

/**
 * `wp wpcv prune` の実体 `WPCV_CLI_Prune_Command::__invoke()` のテスト(0.10.0 プラン §8.3・§8.5).
 *
 * 削除の判定そのものは `RetentionCleanerTest` が見ている. ここでは、コマンドが設定を読み、
 * `prune()` を正しい引数で呼び、繰り返し・件数の表示をすることを、`prune()` を差し替えて確かめる.
 */
class CliPruneCommandTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_wp_cli_calls'], $GLOBALS['_wpcv_test_options'] );
	}

	/**
	 * 各テストの後に状態を残さない.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_wpcv_test_wp_cli_calls'], $GLOBALS['_wpcv_test_options'] );
		parent::tearDown();
	}

	/**
	 * `prune()` を差し替えた削除処理を作る.
	 *
	 * @param array[] $results `prune()` が順に返す結果.
	 * @return WPCV_Retention_Cleaner|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function make_cleaner( array $results ) {
		$cleaner = $this->createMock( WPCV_Retention_Cleaner::class );
		$cleaner->method( 'prune' )->willReturnOnConsecutiveCalls( ...$results );

		return $cleaner;
	}

	/**
	 * 件数の結果を作る.
	 *
	 * @param int  $runs      run の件数.
	 * @param int  $target    target_run の件数.
	 * @param int  $findings  finding の件数.
	 * @param bool $remaining 上限で止めたか.
	 * @return array
	 */
	private static function result( $runs, $target, $findings, $remaining = false ) {
		return array(
			'suppressions' => 0,
			'target_runs'  => $target,
			'findings'     => $findings,
			'runs'         => $runs,
			'remaining'    => $remaining,
		);
	}

	/**
	 * `wp wpcv prune` が登録されていることを確認する.
	 *
	 * @return void
	 */
	public function test_command_is_registered() {
		$registered = $GLOBALS['_wpcv_test_prune_command_registration'] ?? false;

		$this->assertIsArray( $registered );
		$this->assertSame( 'WPCV_CLI_Prune_Command', $registered[1] );
	}

	/**
	 * 保持期間が無期限(0)のときは `prune()` を呼ばず、その旨を表示することを確認する(§8.5 #1).
	 *
	 * @return void
	 */
	public function test_unlimited_retention_does_nothing() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array( 'retention_months' => 0 );

		$cleaner = $this->createMock( WPCV_Retention_Cleaner::class );
		$cleaner->expects( $this->never() )->method( 'prune' );

		( new WPCV_CLI_Prune_Command( $cleaner ) )->__invoke( array(), array() );

		$this->assertStringContainsString( '無期限', $GLOBALS['_wpcv_test_wp_cli_calls']['success'][0] );
		$this->assertArrayNotHasKey( 'error', $GLOBALS['_wpcv_test_wp_cli_calls'] );
	}

	/**
	 * `--dry-run` は dry-run 付きで1回だけ呼び、件数を表示することを確認する(§8.5 #10).
	 *
	 * @return void
	 */
	public function test_dry_run_calls_prune_once_with_dry_run_flag() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array( 'retention_months' => 6 );

		$cleaner = $this->createMock( WPCV_Retention_Cleaner::class );
		$cleaner->expects( $this->once() )->method( 'prune' )->with( 6, 0, true )->willReturn( self::result( 3, 10, 7 ) );

		( new WPCV_CLI_Prune_Command( $cleaner ) )->__invoke( array(), array( 'dry-run' => true ) );

		$message = $GLOBALS['_wpcv_test_wp_cli_calls']['success'][0];
		$this->assertStringContainsString( 'dry-run', $message );
		$this->assertStringContainsString( 'runs: 3, target_runs: 10, findings: 7', $message );
	}

	/**
	 * 上限で止まる間は繰り返し、件数を合計して表示することを確認する(§8.5 #3).
	 *
	 * @return void
	 */
	public function test_repeats_until_no_remaining_and_sums_counts() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array( 'retention_months' => 12 );

		$cleaner = $this->createMock( WPCV_Retention_Cleaner::class );
		$cleaner->expects( $this->exactly( 3 ) )->method( 'prune' )->with( 12, 0, false )->willReturnOnConsecutiveCalls(
			self::result( 1, 500, 100, true ),
			self::result( 1, 500, 100, true ),
			self::result( 2, 40, 5, false )
		);

		( new WPCV_CLI_Prune_Command( $cleaner ) )->__invoke( array(), array() );

		$this->assertCount( 2, $GLOBALS['_wpcv_test_wp_cli_calls']['line'] );
		$this->assertStringContainsString( 'runs: 4, target_runs: 1040, findings: 205', $GLOBALS['_wpcv_test_wp_cli_calls']['success'][0] );
	}

	/**
	 * 削除で例外が出たら、エラーとして表示し、成功とは表示しないことを確認する.
	 *
	 * @return void
	 */
	public function test_failure_is_reported_as_error() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array( 'retention_months' => 12 );

		$cleaner = $this->createMock( WPCV_Retention_Cleaner::class );
		$cleaner->method( 'prune' )->willThrowException( new RuntimeException( 'db error' ) );

		( new WPCV_CLI_Prune_Command( $cleaner ) )->__invoke( array(), array() );

		$this->assertStringContainsString( 'db error', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0] );
		$this->assertArrayNotHasKey( 'success', $GLOBALS['_wpcv_test_wp_cli_calls'] );
	}

	/**
	 * 別の削除が実行中(lock が取れない)なら、エラーで止めて成功とは表示しないことを確認する(指摘2).
	 *
	 * @return void
	 */
	public function test_stops_with_error_when_another_prune_is_running() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Settings::OPTION_NAME ] = array( 'retention_months' => 12 );

		$first            = self::result( 1, 500, 100, true );
		$locked           = self::result( 0, 0, 0, true );
		$locked['locked'] = true;

		$cleaner = $this->createMock( WPCV_Retention_Cleaner::class );
		$cleaner->expects( $this->exactly( 2 ) )->method( 'prune' )->willReturnOnConsecutiveCalls( $first, $locked );

		( new WPCV_CLI_Prune_Command( $cleaner ) )->__invoke( array(), array() );

		$this->assertStringContainsString( '実行中', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0] );
		$this->assertStringContainsString( 'target_runs: 500', $GLOBALS['_wpcv_test_wp_cli_calls']['error'][0], 'ここまでの合計を出す' );
		$this->assertArrayNotHasKey( 'success', $GLOBALS['_wpcv_test_wp_cli_calls'] );
	}
}
