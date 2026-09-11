<?php
/**
 * WPCV_Run_Planner のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-target-run-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Run_Planner::plan()`(v0.4.0 §Step2)のテスト.
 *
 * HTTP・ファイルシステムアクセスを一切行わない列挙ロジックのみを対象とする
 * (manifest取得・ファイル比較は`WPCV_Verifier`の責務。`WPCV_Run_Planner`の
 * クラス docblock 参照)。
 */
class RunPlannerTest extends TestCase {

	/**
	 * コアのみの `$context` から、`queued` 状態のコア(manifest比較)と
	 * `core:_scan`(未知ファイル走査の合成target。Step4)の2件だけが列挙される
	 * ことを確認する.
	 *
	 * @return void
	 */
	public function test_plan_lists_core_only_when_no_plugins_or_mu_plugins() {
		$planner     = new WPCV_Run_Planner( new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() ) );
		$target_runs = $planner->plan( array( 'version' => '6.8' ) );

		$this->assertCount( 2, $target_runs );
		$this->assertSame( 'core', $target_runs[0]['target_id'] );
		$this->assertSame( 'core', $target_runs[0]['dimension'] );
		$this->assertSame( 'wordpress', $target_runs[0]['slug'] );
		$this->assertSame( '6.8', $target_runs[0]['version'] );
		$this->assertSame( 'wporg', $target_runs[0]['source'] );
		$this->assertSame( 'queued', $target_runs[0]['status'] );
		$this->assertNull( $target_runs[0]['error_code'] );
		$this->assertSame( 0, $target_runs[0]['files_total'] );

		$this->assertSame( 'core:_scan', $target_runs[1]['target_id'] );
		$this->assertSame( 'core', $target_runs[1]['dimension'] );
		$this->assertSame( '_scan', $target_runs[1]['slug'] );
		$this->assertNull( $target_runs[1]['version'] );
		$this->assertNull( $target_runs[1]['source'] );
		$this->assertSame( 'queued', $target_runs[1]['status'] );
	}

	/**
	 * ディレクトリ型プラグイン(`{slug}/{file}.php`)の slug がディレクトリ名から
	 * 正しく解決されることを確認する(`WPCV_Run_Planner::resolve_plugin_slug_and_root()` 経由).
	 *
	 * @return void
	 */
	public function test_plan_resolves_directory_style_plugin_slug() {
		$planner     = new WPCV_Run_Planner( new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() ) );
		$target_runs = $planner->plan(
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'akismet/akismet.php' => array( 'Version' => '5.3' ),
				),
				'plugin_dir' => '/var/www/wp-content/plugins',
			)
		);

		$this->assertCount( 3, $target_runs );

		$plugin_row = null;
		foreach ( $target_runs as $row ) {
			if ( 'plugin:akismet' === $row['target_id'] ) {
				$plugin_row = $row;
			}
		}

		$this->assertNotNull( $plugin_row );
		$this->assertSame( 'plugin', $plugin_row['dimension'] );
		$this->assertSame( 'akismet', $plugin_row['slug'] );
		$this->assertSame( '5.3', $plugin_row['version'] );
		$this->assertSame( 'wporg', $plugin_row['source'] );
		$this->assertSame( 'queued', $plugin_row['status'] );
	}

	/**
	 * 単一ファイルプラグイン(スラッシュを含まないキー)の slug が、
	 * ファイル名から拡張子を除いたものになることを確認する(§3.4 のベストエフォート方針).
	 *
	 * @return void
	 */
	public function test_plan_resolves_single_file_plugin_slug_from_filename() {
		$planner     = new WPCV_Run_Planner( new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() ) );
		$target_runs = $planner->plan(
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'my-single-file-plugin.php' => array( 'Version' => '1.0' ),
				),
				'plugin_dir' => '/var/www/wp-content/plugins',
			)
		);

		$plugin_row = null;
		foreach ( $target_runs as $row ) {
			if ( 'plugin:my-single-file-plugin' === $row['target_id'] ) {
				$plugin_row = $row;
			}
		}

		$this->assertNotNull( $plugin_row );
		$this->assertSame( 'my-single-file-plugin', $plugin_row['slug'] );
	}

	/**
	 * バージョンが取得できない(空文字列)プラグインは version が null として
	 * 列挙されることを確認する(`WPCV_Verifier::verify_plugin()` の version_unknown
	 * 判定と一貫させるため).
	 *
	 * @return void
	 */
	public function test_plan_uses_null_version_when_plugin_version_is_empty() {
		$planner     = new WPCV_Run_Planner( new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() ) );
		$target_runs = $planner->plan(
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'no-version-plugin/no-version-plugin.php' => array(),
				),
				'plugin_dir' => '/var/www/wp-content/plugins',
			)
		);

		$plugin_row = null;
		foreach ( $target_runs as $row ) {
			if ( 'plugin:no-version-plugin' === $row['target_id'] ) {
				$plugin_row = $row;
			}
		}

		$this->assertNotNull( $plugin_row );
		$this->assertNull( $plugin_row['version'] );
	}

	/**
	 * `hello.php` はコアの checksums に含まれる(§3.2)ため、
	 * プラグイン次元では列挙されない(target_run が作られない)ことを確認する.
	 *
	 * @return void
	 */
	public function test_plan_skips_hello_php_as_plugin_target() {
		$planner     = new WPCV_Run_Planner( new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() ) );
		$target_runs = $planner->plan(
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'hello.php' => array( 'Version' => '1.7.2' ),
				),
				'plugin_dir' => '/var/www/wp-content/plugins',
			)
		);

		// core + core:_scan のみ(hello.php 分の target_run は増えない)ことを確認する.
		$this->assertCount( 2, $target_runs );
	}

	/**
	 * `mu_plugin_dir` を渡すと、loader ごとの target と合成target
	 * (`muplugin:_scan`)が列挙されることを確認する.
	 *
	 * @return void
	 */
	public function test_plan_lists_mu_plugin_loaders_and_scan_target_when_dir_given() {
		$planner     = new WPCV_Run_Planner( new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() ) );
		$target_runs = $planner->plan(
			array(
				'version'       => '6.8',
				'mu_plugin_dir' => '/var/www/wp-content/mu-plugins',
				'mu_plugins'    => array( 'loader.php' => array() ),
			)
		);

		// core + core:_scan + loader + muplugin:_scan の4件.
		$this->assertCount( 4, $target_runs );

		$target_ids = array_column( $target_runs, 'target_id' );
		$this->assertContains( 'muplugin:loader.php', $target_ids );
		$this->assertContains( 'muplugin:_scan', $target_ids );

		foreach ( $target_runs as $row ) {
			if ( 'muplugin:loader.php' === $row['target_id'] ) {
				$this->assertSame( 'muplugin', $row['dimension'] );
				$this->assertSame( 'loader.php', $row['slug'] );
				$this->assertNull( $row['source'] );
				$this->assertSame( 'queued', $row['status'] );
			}
		}
	}

	/**
	 * `mu_plugin_dir` を渡さない場合、MU プラグイン領域は列挙されないことを確認する.
	 *
	 * @return void
	 */
	public function test_plan_skips_mu_plugin_area_when_dir_absent() {
		$planner     = new WPCV_Run_Planner( new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() ) );
		$target_runs = $planner->plan( array( 'version' => '6.8' ) );

		$this->assertCount( 2, $target_runs );
	}

	/**
	 * version が指定されていない場合に例外を投げることを確認する.
	 *
	 * @return void
	 */
	public function test_plan_throws_when_version_missing() {
		$this->expectException( InvalidArgumentException::class );

		$planner = new WPCV_Run_Planner( new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() ) );
		$planner->plan( array() );
	}

	/**
	 * plugins が空でないのに plugin_dir が指定されていない場合、例外を投げることを確認する.
	 *
	 * @return void
	 */
	public function test_plan_throws_when_plugin_dir_missing() {
		$this->expectException( InvalidArgumentException::class );

		$planner = new WPCV_Run_Planner( new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() ) );
		$planner->plan(
			array(
				'version' => '6.8',
				'plugins' => array( 'akismet/akismet.php' => array() ),
			)
		);
	}

	/**
	 * `plan()` の戻り値をそのまま `WPCV_Target_Run_Repository::save_target_runs()` に
	 * 渡すと、queued状態のまま DB へ保存できることを確認する(列挙結果のスキーマが
	 * 実際の永続化層とかみ合っていることの統合確認).
	 *
	 * @return void
	 */
	public function test_planned_target_runs_can_be_saved_via_target_run_repository() {
		$planner     = new WPCV_Run_Planner( new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() ) );
		$target_runs = $planner->plan(
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'akismet/akismet.php' => array( 'Version' => '5.3' ),
				),
				'plugin_dir' => '/var/www/wp-content/plugins',
			)
		);

		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Target_Run_Repository( $wpdb );

		$target_run_ids = $repository->save_target_runs( 1, $target_runs );

		$this->assertCount( 3, $target_run_ids );
		foreach ( $wpdb->rows['wp_wpcv_target_runs'] as $row ) {
			$this->assertSame( 'queued', $row['status'] );
			$this->assertSame( 'missing', $row['manifest_status'] );
		}
	}

	/**
	 * 有効な `exclude_target` 抑制ルールに一致する target は `skipped`
	 * (`error_code` は `excluded`)として列挙され、検証自体を行わない状態で
	 * 作られることを確認する(v0.4.0 §Step8)。他の target(ここでは
	 * `core:_scan`)には影響しないことも合わせて確認する.
	 *
	 * @return void
	 */
	public function test_plan_marks_excluded_target_as_skipped() {
		$suppression_repository = new WPCV_Suppression_Repository( new WPCV_Test_Fake_WPDB() );
		$suppression_repository->insert(
			array(
				'type'       => WPCV_Suppression_Type::EXCLUDE_TARGET,
				'dimension'  => 'plugin',
				'slug'       => 'akismet',
				'reason'     => 'known false positive plugin',
				'created_by' => 1,
			)
		);

		$planner     = new WPCV_Run_Planner( $suppression_repository );
		$target_runs = $planner->plan(
			array(
				'version'    => '6.8',
				'plugins'    => array(
					'akismet/akismet.php' => array( 'Version' => '5.3' ),
				),
				'plugin_dir' => '/var/www/wp-content/plugins',
			)
		);

		$plugin_row = null;
		$core_row   = null;

		foreach ( $target_runs as $row ) {
			if ( 'plugin:akismet' === $row['target_id'] ) {
				$plugin_row = $row;
			}

			if ( 'core' === $row['target_id'] ) {
				$core_row = $row;
			}
		}

		$this->assertNotNull( $plugin_row );
		$this->assertSame( 'skipped', $plugin_row['status'] );
		$this->assertSame( 'excluded', $plugin_row['error_code'] );

		$this->assertNotNull( $core_row );
		$this->assertSame( 'queued', $core_row['status'] );
	}
}
