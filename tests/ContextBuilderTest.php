<?php
/**
 * WPCV_Context_Builder のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/sources/class-wpcv-github-client.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-github-mappings.php';
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
		unset( $GLOBALS['_wpcv_test_bloginfo'], $GLOBALS['_wpcv_test_plugins'], $GLOBALS['_wpcv_test_mu_plugins'], $GLOBALS['_wpcv_test_themes'], $GLOBALS['_wpcv_test_wp_get_themes_args'] );
	}

	/**
	 * テスト用のテーマ一覧を後のテストに残さない(v0.7 §Step3). 残すと、
	 * `WPCV_Context_Builder::build()` を使う別のファイルのテストの run に
	 * 架空のテーマが混ざり、run が partial になる(全体のスイートで実際に起きた).
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_wpcv_test_themes'], $GLOBALS['_wpcv_test_wp_get_themes_args'] );
		parent::tearDown();
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

	/**
	 * テーマの version・template・ディレクトリ・Update URI を stylesheet ごとにまとめ、
	 * エラーのあるテーマも含めるため `errors => null` で `wp_get_themes()` を呼ぶことを
	 * 確認する(v0.7 §3.3・D5).
	 *
	 * @return void
	 */
	public function test_build_describes_themes_including_broken_ones() {
		$GLOBALS['_wpcv_test_themes'] = array(
			'twentytwentyfive' => new WPCV_Test_Fake_Theme( array( 'Version' => '1.5' ), 'twentytwentyfive', '/var/www/wp-content/themes/twentytwentyfive' ),
			'child'            => new WPCV_Test_Fake_Theme(
				array(
					'Version'   => '0.1',
					'UpdateURI' => 'false',
				),
				'missing-parent',
				'/var/www/wp-content/themes/child'
			),
		);

		$context = WPCV_Context_Builder::build();

		$this->assertSame( array( 'errors' => null ), $GLOBALS['_wpcv_test_wp_get_themes_args'] );
		$this->assertSame(
			array(
				'twentytwentyfive' => array(
					'version'        => '1.5',
					'template'       => 'twentytwentyfive',
					'stylesheet_dir' => '/var/www/wp-content/themes/twentytwentyfive',
					'update_uri'     => '',
				),
				'child'            => array(
					'version'        => '0.1',
					'template'       => 'missing-parent',
					'stylesheet_dir' => '/var/www/wp-content/themes/child',
					'update_uri'     => 'false',
				),
			),
			$context['themes']
		);
	}
}
