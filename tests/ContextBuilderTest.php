<?php
/**
 * WPCV_Context_Builder のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-context-builder.php';

use PHPUnit\Framework\TestCase;

/**
 * 実際の WordPress 環境(の代わりにスタブ)から `$context` を組み立てられることを検証する.
 */
class ContextBuilderTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_bloginfo'], $GLOBALS['_wpcv_test_plugins'], $GLOBALS['_wpcv_test_mu_plugins'] );
	}

	/**
	 * バージョン・run_trigger・プラグイン一覧・ディレクトリが正しく組み立てられることを確認する.
	 *
	 * @return void
	 */
	public function test_build_assembles_context_from_wordpress_environment() {
		$GLOBALS['_wpcv_test_bloginfo'] = array( 'version' => '6.8' );
		$GLOBALS['_wpcv_test_plugins']  = array(
			'akismet/akismet.php' => array( 'Version' => '5.3' ),
		);
		$GLOBALS['_wpcv_test_mu_plugins'] = array(
			'loader.php' => array(),
		);

		$context = WPCV_Context_Builder::build( 'cli' );

		$this->assertSame( '6.8', $context['version'] );
		$this->assertSame( 'cli', $context['run_trigger'] );
		$this->assertSame( $GLOBALS['_wpcv_test_plugins'], $context['plugins'] );
		$this->assertSame( WP_PLUGIN_DIR, $context['plugin_dir'] );
		$this->assertSame( WPMU_PLUGIN_DIR, $context['mu_plugin_dir'] );
		$this->assertSame( $GLOBALS['_wpcv_test_mu_plugins'], $context['mu_plugins'] );
	}

	/**
	 * run_trigger を省略した場合、既定値 'manual' になることを確認する.
	 *
	 * @return void
	 */
	public function test_build_defaults_run_trigger_to_manual() {
		$context = WPCV_Context_Builder::build();

		$this->assertSame( 'manual', $context['run_trigger'] );
	}

	/**
	 * プラグインが存在しない場合、plugins が空配列になることを確認する
	 * (`WPCV_Run_Coordinator::run()` はこの場合 plugin_dir を必須にしない).
	 *
	 * @return void
	 */
	public function test_build_returns_empty_plugins_array_when_none_installed() {
		$context = WPCV_Context_Builder::build();

		$this->assertSame( array(), $context['plugins'] );
	}
}
