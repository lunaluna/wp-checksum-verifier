<?php
/**
 * core:_config・dropin:_stat(v0.6 §Step9)のディスパッチテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-static-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-run-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-verifier.php';
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
require_once dirname( __DIR__ ) . '/includes/class-wpcv-migrator.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-chunk-result-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-file-state-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-starter.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-update-event-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-update-event-matcher.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `core:_config`/`dropin:_stat`(v0.6 §Step9. プラン§5.3 L1・L2・L8)のテスト.
 *
 * `StatTargetDispatchTest`と同じ方針(`tests/fixtures/fake-root/`=ABSPATH配下に
 * 実ファイルを作り、`wpcv_test_make_fake_environment()`+実際の
 * `WPCV_Run_Coordinator::run()`で完走させて確認する)。この2つのtargetは
 * `WPCV_Run_Planner::plan()`が本体の有無やcontextに関わらず常に列挙するため、
 * `plugins`/`mu_plugin_dir`を渡さない最小構成の`$context`でも毎回作られる.
 */
class StaticTargetDispatchTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clean_fixtures();
		unset( $GLOBALS['_wpcv_test_is_multisite'] );
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->clean_fixtures();
		unset( $GLOBALS['_wpcv_test_is_multisite'] );
		parent::tearDown();
	}

	/**
	 * ABSPATH 直下を `.gitkeep` 以外すべて削除する.
	 *
	 * @return void
	 */
	private function clean_fixtures() {
		foreach ( scandir( ABSPATH ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || '.gitkeep' === $entry ) {
				continue;
			}
			$this->remove_path( ABSPATH . $entry );
		}
	}

	/**
	 * ファイル・ディレクトリを再帰的に削除する.
	 *
	 * @param string $path 絶対パス.
	 * @return void
	 */
	private function remove_path( $path ) {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( scandir( $path ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$this->remove_path( $path . '/' . $entry );
			}
			rmdir( $path );
			return;
		}

		if ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * ABSPATH 相対パスを指定してテスト用ファイルを作る.
	 *
	 * @param string $relative_path ABSPATH 相対パス.
	 * @param string $content       ファイルの中身.
	 * @return void
	 */
	private function put_fixture_file( $relative_path, $content = '' ) {
		$absolute_path = ABSPATH . $relative_path;
		$dir           = dirname( $absolute_path );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		file_put_contents( $absolute_path, $content );
	}

	/**
	 * `wpcv_test_make_fake_environment()` で組み立てた環境で run を予約し、
	 * `coordinator->run()` を呼ぶ.
	 *
	 * @param array $made    `wpcv_test_make_fake_environment()` の戻り値.
	 * @param array $context `run()` に渡す `$context`.
	 * @return array `run()` の戻り値.
	 */
	private function reserve_and_run( array $made, array $context ) {
		$run_id = $made['run_repository']->reserve_run()['run_id'];

		return $made['coordinator']->run( $run_id, $context );
	}

	/**
	 * 指定 target_id の target_run 行を探す.
	 *
	 * @param array $made      `wpcv_test_make_fake_environment()` の戻り値.
	 * @param string $target_id target_id.
	 * @param int   $run_id    run の id.
	 * @return array|null
	 */
	private function find_target_run( array $made, $target_id, $run_id = 1 ) {
		foreach ( $made['wpdb']->rows['wp_wpcv_target_runs'] as $row ) {
			if ( $target_id === $row['target_id'] && (int) $run_id === (int) $row['run_id'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * 指定 target_id・run_id の findings を返す.
	 *
	 * @param array  $made      `wpcv_test_make_fake_environment()` の戻り値.
	 * @param string $target_id target_id.
	 * @param int    $run_id    run の id.
	 * @return array
	 */
	private function findings_for( array $made, $target_id, $run_id ) {
		return array_values(
			array_filter(
				$made['wpdb']->rows['wp_wpcv_findings'] ?? array(),
				static function ( $row ) use ( $target_id, $run_id ) {
					return $target_id === $row['target_id'] && (int) $run_id === (int) $row['run_id'];
				}
			)
		);
	}

	/**
	 * `core:_config`(`wp-config.php`)・`dropin:_stat`(`object-cache.php`)とも、
	 * 初回はベースラインのみでfindingを出さないことを確認する(v0.6 §Step9.
	 * プラン§5.3 L8「初回はベースラインのみ」).
	 *
	 * @return void
	 */
	public function test_first_run_builds_baseline_without_findings_for_both_targets() {
		$this->put_fixture_file( 'wp-config.php', str_repeat( 'a', 100 ) );
		$this->put_fixture_file( 'wp-content/object-cache.php', str_repeat( 'b', 50 ) );

		$made   = wpcv_test_make_fake_environment();
		$result = $this->reserve_and_run( $made, array( 'version' => '6.8' ) );

		$config = $this->find_target_run( $made, 'core:_config' );
		$dropin = $this->find_target_run( $made, 'dropin:_stat' );

		$this->assertNotNull( $config, 'core:_config のtarget_runが作られていない' );
		$this->assertNotNull( $dropin, 'dropin:_stat のtarget_runが作られていない' );

		$this->assertSame( WPCV_Target_Status::SUCCESS, $config['status'] );
		$this->assertSame( 'core', $config['dimension'] );
		$this->assertSame( '_config', $config['slug'] );
		$this->assertSame( 1, (int) $config['files_total'] );

		$this->assertSame( WPCV_Target_Status::SUCCESS, $dropin['status'] );
		$this->assertSame( 'dropin', $dropin['dimension'] );
		$this->assertSame( '_stat', $dropin['slug'] );
		$this->assertSame( 1, (int) $dropin['files_total'] );

		$this->assertSame( array(), $this->findings_for( $made, 'core:_config', $result['run_id'] ) );
		$this->assertSame( array(), $this->findings_for( $made, 'dropin:_stat', $result['run_id'] ) );
	}

	/**
	 * `wp-config.php`・ドロップインとも実在しない場合、`core:_config`/
	 * `dropin:_stat`はfiles_total=0で成功終端することを確認する
	 * (見つからないこと自体はエラーではない. §5.3 L1「実在するもののみ」).
	 *
	 * @return void
	 */
	public function test_first_run_with_no_config_files_or_dropins_present() {
		$made   = wpcv_test_make_fake_environment();
		$result = $this->reserve_and_run( $made, array( 'version' => '6.8' ) );

		$config = $this->find_target_run( $made, 'core:_config' );
		$dropin = $this->find_target_run( $made, 'dropin:_stat' );

		$this->assertSame( WPCV_Target_Status::SUCCESS, $config['status'] );
		$this->assertSame( 0, (int) $config['files_total'] );
		$this->assertSame( WPCV_Target_Status::SUCCESS, $dropin['status'] );
		$this->assertSame( 0, (int) $dropin['files_total'] );
		unset( $result );
	}

	/**
	 * 2回目のrunで、ファイルの追加(`added`)・削除(`missing`)・内容変更
	 * (`modified`)がそれぞれ検出されることを確認する(v0.6 §Step9の完了条件).
	 *
	 * **v0.6 §Step10で内容ハッシュ(層2)がこの2つのtargetに常時有効になったため、
	 * wp-config.phpの内容変更は`stat_changed`ではなく`modified`(expected_hash=
	 * 前回のcontent_hash・actual_hash=今回の値)として検出されるようになった
	 * (Step9実装時点ではまだ層2が無く`stat_changed`だった。2026-09-30更新)。
	 *
	 * @return void
	 */
	public function test_second_run_detects_added_missing_and_changed_files() {
		$original_config_content = str_repeat( 'a', 100 );
		$changed_config_content  = str_repeat( 'a', 200 );

		$this->put_fixture_file( 'wp-config.php', $original_config_content );
		$this->put_fixture_file( '.htaccess', 'rules' );
		$this->put_fixture_file( 'wp-content/object-cache.php', str_repeat( 'b', 50 ) );

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run( $made, array( 'version' => '6.8' ) );

		// wp-config.php: 内容変更(modified). .htaccess: 削除(missing).
		// object-cache.php: そのまま. advanced-cache.php: 追加(added).
		$this->put_fixture_file( 'wp-config.php', $changed_config_content );
		unlink( ABSPATH . '.htaccess' );
		$this->put_fixture_file( 'wp-content/advanced-cache.php', str_repeat( 'c', 30 ) );

		$second = $this->reserve_and_run( $made, array( 'version' => '6.8' ) );

		$config_findings = $this->findings_for( $made, 'core:_config', $second['run_id'] );
		$dropin_findings = $this->findings_for( $made, 'dropin:_stat', $second['run_id'] );

		$config_by_path = array();
		foreach ( $config_findings as $row ) {
			$config_by_path[ $row['path'] ] = $row;
		}
		$dropin_by_path = array();
		foreach ( $dropin_findings as $row ) {
			$dropin_by_path[ $row['path'] ] = $row;
		}

		$this->assertArrayHasKey( 'wp-config.php', $config_by_path );
		$this->assertSame( 'modified', $config_by_path['wp-config.php']['status'] );
		$this->assertSame( hash( 'sha256', $original_config_content ), $config_by_path['wp-config.php']['expected_hash'] );
		$this->assertSame( hash( 'sha256', $changed_config_content ), $config_by_path['wp-config.php']['actual_hash'] );

		$this->assertArrayHasKey( '.htaccess', $config_by_path );
		$this->assertSame( 'missing', $config_by_path['.htaccess']['status'] );

		$this->assertArrayHasKey( 'wp-content/advanced-cache.php', $dropin_by_path );
		$this->assertSame( 'added', $dropin_by_path['wp-content/advanced-cache.php']['status'] );

		$this->assertArrayNotHasKey( 'wp-content/object-cache.php', $dropin_by_path, '変化していないファイルはfindingを出さない' );
	}

	/**
	 * 1回目のrunでドロップインが0件(ファイルが1つも無い)だった場合でも、
	 * 2回目のrunで初めてドロップインが現れたら`added`として報告されることを
	 * 確認する(v0.6 §Step9実装中にtest-armfu.localの実地検証で発見した罠:
	 * `has_baseline_before_run()`〔`wpcv_file_states`の行の有無で判定〕を
	 * そのまま使うと、0件のまま続いた後にファイルが現れた回もbaseline_modeが
	 * trueのままになり、addedが出ないまま黙って取り込まれてしまっていた.
	 * `find_baseline_target_run()`〔過去にこのtarget_idがsuccessで終端した
	 * runがあるか〕に切り替えて修正した).
	 *
	 * @return void
	 */
	public function test_dropin_added_after_a_run_with_zero_dropins_is_reported() {
		// 1回目: ドロップインが1つも無い状態でベースラインを作る(files_total=0).
		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run( $made, array( 'version' => '6.8' ) );

		$first_dropin = $this->find_target_run( $made, 'dropin:_stat', 1 );
		$this->assertSame( 0, (int) $first_dropin['files_total'], '前提: 1回目はドロップイン0件のはず' );

		// 2回目: 初めてドロップインが現れる.
		$this->put_fixture_file( 'wp-content/object-cache.php', str_repeat( 'x', 20 ) );
		$second = $this->reserve_and_run( $made, array( 'version' => '6.8' ) );

		$dropin_findings = $this->findings_for( $made, 'dropin:_stat', $second['run_id'] );
		$by_path = array();
		foreach ( $dropin_findings as $row ) {
			$by_path[ $row['path'] ] = $row;
		}

		$this->assertArrayHasKey( 'wp-content/object-cache.php', $by_path, 'ドロップイン0件が続いた後に現れたファイルがaddedとして報告されていない' );
		$this->assertSame( 'added', $by_path['wp-content/object-cache.php']['status'] );
	}

	/**
	 * マルチサイトでは`_get_dropins()`自体が返す一覧が増える(`sunrise.php`等)ため、
	 * `dropin:_stat`がそれらも走査対象に含めることを確認する(v0.6 §Step9の
	 * 完了条件「マルチサイトでドロップインの一覧が変わるテスト」).
	 *
	 * @return void
	 */
	public function test_dropin_stat_includes_multisite_only_dropins_when_multisite() {
		$this->put_fixture_file( 'wp-content/sunrise.php', str_repeat( 'x', 10 ) );

		$GLOBALS['_wpcv_test_is_multisite'] = true;

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run( $made, array( 'version' => '6.8' ) );

		$dropin = $this->find_target_run( $made, 'dropin:_stat' );

		$this->assertSame( 1, (int) $dropin['files_total'], 'マルチサイトのみのドロップイン(sunrise.php)が走査対象に含まれていない' );
	}
}
