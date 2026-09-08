<?php
/**
 * WPCV_Plugin のテスト.
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
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-source-core.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-source-wporg-plugin.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-plugin.php';

use PHPUnit\Framework\TestCase;

/**
 * composition root として正しい型の `WPCV_Run_Coordinator` を組み立てられることを検証する.
 *
 * DB アクセスを伴う組み立て後の挙動(`run()` を呼んだときの実際の検証結果)は
 * `RunCoordinatorTest` で個別のテストダブルを使って検証済みのため、ここでは
 * 「正しい型のインスタンスが返る」「使い回される」ことのみを確認する
 * (実地での動作確認は Step 2 の WP-CLI コマンドとあわせて行う).
 */
class PluginTest extends TestCase {

	/**
	 * `run_coordinator()` が `WPCV_Run_Coordinator` のインスタンスを返すことを確認する.
	 *
	 * @return void
	 */
	public function test_run_coordinator_returns_run_coordinator_instance() {
		$this->assertInstanceOf( WPCV_Run_Coordinator::class, WPCV_Plugin::run_coordinator() );
	}

	/**
	 * 複数回呼び出しても同一インスタンスが返る(1回だけ組み立てる)ことを確認する.
	 *
	 * @return void
	 */
	public function test_run_coordinator_returns_same_instance_on_repeated_calls() {
		$this->assertSame( WPCV_Plugin::run_coordinator(), WPCV_Plugin::run_coordinator() );
	}
}
