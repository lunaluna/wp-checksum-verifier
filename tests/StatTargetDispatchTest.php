<?php
/**
 * Stat 差分検知 target の dispatcher 接続(v0.5 §Step6)のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
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
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-type.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-suppression-matcher.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-suppression-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-chunk-result-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-file-state-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-starter.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Chunk_Dispatcher` が stat 差分検知 target(`{dimension}:{slug}:_stat`)を
 * 本体 target の照合結果に応じて振り分け、ベースラインを永続化することのテスト
 * (v0.5 §Step6. rev.3 §3.4).
 *
 * ABSPATH(tests/fixtures/fake-root/)配下に実ファイルを作り、
 * `wpcv_test_make_fake_environment()` の coordinator で run を完走させて確かめる.
 */
class StatTargetDispatchTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clean_fixtures();
	}

	/**
	 * 各テストの後に作成したフィクスチャを掃除する.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->clean_fixtures();
		unset( $GLOBALS['_wpcv_test_filters'] );
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
	 * @param array  $made      `wpcv_test_make_fake_environment()` の戻り値.
	 * @param string $target_id target_id.
	 * @param int    $run_id    run の id.
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
	 * `wpcv_file_states` の行を path => 行 で返す.
	 *
	 * @param array $made `wpcv_test_make_fake_environment()` の戻り値.
	 * @return array<string, array>
	 */
	private function file_states_by_path( array $made ) {
		$by_path = array();
		foreach ( $made['wpdb']->rows['wp_wpcv_file_states'] ?? array() as $row ) {
			$by_path[ $row['path'] ] = $row;
		}

		return $by_path;
	}

	/**
	 * Stat target の findings だけを返す.
	 *
	 * @param array $made   `wpcv_test_make_fake_environment()` の戻り値.
	 * @param int   $run_id run の id.
	 * @return array
	 */
	private function stat_findings( array $made, $run_id ) {
		return array_values(
			array_filter(
				$made['wpdb']->rows['wp_wpcv_findings'] ?? array(),
				static function ( $row ) use ( $run_id ) {
					return WPCV_Target_Resolver::is_stat_id( $row['target_id'] ) && (int) $run_id === (int) $row['run_id'];
				}
			)
		);
	}

	/**
	 * 照合元が無い(`manifest_not_found`)プラグインは stat target が走り、
	 * 初回はベースラインだけを作って finding を出さないことを確認する(rev.3 §3.4/§3.6).
	 *
	 * @return void
	 */
	public function test_stat_target_builds_baseline_without_findings_on_first_run() {
		$this->put_fixture_file( 'wp-content/plugins/custom-plugin/custom-plugin.php', 'main' );
		$this->put_fixture_file( 'wp-content/plugins/custom-plugin/assets/app.js', 'js' );

		$made   = wpcv_test_make_fake_environment();
		$result = $this->reserve_and_run(
			$made,
			array(
				'version'    => '6.8',
				'plugins'    => array( 'custom-plugin/custom-plugin.php' => array( 'Version' => '1.0.0' ) ),
				'plugin_dir' => ABSPATH . 'wp-content/plugins',
			)
		);

		$body = $this->find_target_run( $made, 'plugin:custom-plugin' );
		$stat = $this->find_target_run( $made, 'plugin:custom-plugin:_stat' );

		$this->assertSame( WPCV_Target_Status::UNVERIFIABLE, $body['status'] );
		$this->assertSame( WPCV_Error_Code::MANIFEST_NOT_FOUND, $body['error_code'] );
		$this->assertSame( WPCV_Target_Status::SUCCESS, $stat['status'] );
		$this->assertSame( 'plugin', $stat['dimension'] );
		$this->assertSame( 'custom-plugin', $stat['slug'] );
		$this->assertSame( 2, (int) $stat['files_total'] );

		$this->assertSame( array(), $this->stat_findings( $made, $result['run_id'] ) );

		$states = $this->file_states_by_path( $made );
		$this->assertCount( 2, $states );
		$this->assertArrayHasKey( 'wp-content/plugins/custom-plugin/custom-plugin.php', $states );
		$this->assertSame( 'plugin:custom-plugin:_stat', $states['wp-content/plugins/custom-plugin/custom-plugin.php']['target_id'] );
		$this->assertSame( '1.0.0', $states['wp-content/plugins/custom-plugin/custom-plugin.php']['baseline_version'] );
	}

	/**
	 * 本体がチェックサム照合できた場合、stat target は走査せずに
	 * `skipped`/`checksum_covered` になり、ベースラインも作らないことを確認する.
	 * run の集計にも含めない(公式プラグインだけのサイトが partial にならない).
	 *
	 * @return void
	 */
	public function test_stat_target_is_skipped_as_checksum_covered_when_body_verified() {
		$this->put_fixture_file( 'wp-content/plugins/akismet/akismet.php', 'main' );

		$plugin_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => array(
					'akismet.php' => array(
						'algorithm' => 'sha256',
						'hashes'    => array( hash( 'sha256', 'main' ) ),
					),
				),
			)
		);

		$made   = wpcv_test_make_fake_environment( null, $plugin_source );
		$result = $this->reserve_and_run(
			$made,
			array(
				'version'    => '6.8',
				'plugins'    => array( 'akismet/akismet.php' => array( 'Version' => '5.3' ) ),
				'plugin_dir' => ABSPATH . 'wp-content/plugins',
			)
		);

		$stat = $this->find_target_run( $made, 'plugin:akismet:_stat' );
		$this->assertSame( WPCV_Target_Status::SKIPPED, $stat['status'] );
		$this->assertSame( WPCV_Error_Code::CHECKSUM_COVERED, $stat['error_code'] );
		$this->assertSame( array(), $this->file_states_by_path( $made ) );

		$this->assertSame( 'success', $result['summary']['status'] );
		$this->assertSame( 3, $result['summary']['targets_total'] );
	}

	/**
	 * 2回目の run で、書き換えたファイルは `stat_changed`(detail 付き)、
	 * 追加したファイルは `added` になり、ベースラインが今回の値へ更新されることを確認する.
	 *
	 * @return void
	 */
	public function test_second_run_reports_stat_changed_and_added_files() {
		$main = 'wp-content/plugins/custom-plugin/custom-plugin.php';
		$this->put_fixture_file( $main, 'main' );
		touch( ABSPATH . $main, 1700000000 );

		$context = array(
			'version'    => '6.8',
			'plugins'    => array( 'custom-plugin/custom-plugin.php' => array( 'Version' => '1.0.0' ) ),
			'plugin_dir' => ABSPATH . 'wp-content/plugins',
		);

		$made  = wpcv_test_make_fake_environment();
		$first = $this->reserve_and_run( $made, $context );
		$this->assertSame( array(), $this->stat_findings( $made, $first['run_id'] ) );

		// 中身を書き換え(size が変わる)、mtime も進める. 新しいファイルも1つ置く.
		file_put_contents( ABSPATH . $main, 'main-modified' );
		touch( ABSPATH . $main, 1700000100 );
		clearstatcache();
		$this->put_fixture_file( 'wp-content/plugins/custom-plugin/shell.php', 'x' );

		$second   = $this->reserve_and_run( $made, $context );
		$findings = $this->stat_findings( $made, $second['run_id'] );

		$by_path = array();
		foreach ( $findings as $finding ) {
			$by_path[ $finding['path'] ] = $finding;
		}

		$this->assertCount( 2, $findings );
		$this->assertSame( 'stat_changed', $by_path[ $main ]['status'] );
		$this->assertSame( 'stat', $by_path[ $main ]['source'] );

		$detail = json_decode( $by_path[ $main ]['detail'], true );
		$this->assertSame( 4, $detail['size']['old'] );
		$this->assertSame( 13, $detail['size']['new'] );
		$this->assertSame( 1700000000, $detail['mtime']['old'] );
		$this->assertSame( 1700000100, $detail['mtime']['new'] );
		$this->assertFalse( $detail['timestomp'] );

		$this->assertSame( 'added', $by_path['wp-content/plugins/custom-plugin/shell.php']['status'] );
		$this->assertNull( $by_path['wp-content/plugins/custom-plugin/shell.php']['detail'] );

		$states = $this->file_states_by_path( $made );
		$this->assertSame( 13, (int) $states[ $main ]['file_size'] );
		$this->assertSame( $second['run_id'], (int) $states[ $main ]['last_seen_run_id'] );
		$this->assertSame( $first['run_id'], (int) $states[ $main ]['first_seen_run_id'] );
	}

	/**
	 * 単一ファイルのプラグインは、`WP_PLUGIN_DIR` 全体ではなくそのファイル1つだけを
	 * stat 走査することを確認する(他のプラグインのファイルを拾わない).
	 *
	 * @return void
	 */
	public function test_single_file_plugin_stats_only_its_own_file() {
		$this->put_fixture_file( 'wp-content/plugins/solo.php', 'solo' );
		$this->put_fixture_file( 'wp-content/plugins/other-plugin/other.php', 'other' );

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run(
			$made,
			array(
				'version'    => '6.8',
				'plugins'    => array( 'solo.php' => array( 'Version' => '0.1' ) ),
				'plugin_dir' => ABSPATH . 'wp-content/plugins',
			)
		);

		$this->assertSame( array( 'wp-content/plugins/solo.php' ), array_keys( $this->file_states_by_path( $made ) ) );
		$this->assertSame( WPCV_Target_Status::SUCCESS, $this->find_target_run( $made, 'plugin:solo:_stat' )['status'] );
	}

	/**
	 * Mu-plugin の loader は照合元が無い(`unknown_source`)ため stat target が走り、
	 * loader ファイル1つだけがベースラインになることを確認する.
	 *
	 * @return void
	 */
	public function test_muplugin_loader_stat_target_stats_loader_file() {
		$this->put_fixture_file( 'wp-content/mu-plugins/loader.php', 'loader' );
		$this->put_fixture_file( 'wp-content/mu-plugins/vendor/lib.php', 'lib' );

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run(
			$made,
			array(
				'version'       => '6.8',
				'mu_plugin_dir' => ABSPATH . 'wp-content/mu-plugins',
				'mu_plugins'    => array( 'loader.php' => array() ),
			)
		);

		$stat = $this->find_target_run( $made, 'muplugin:loader.php:_stat' );
		$this->assertSame( WPCV_Target_Status::SUCCESS, $stat['status'] );
		$this->assertSame( array( 'wp-content/mu-plugins/loader.php' ), array_keys( $this->file_states_by_path( $made ) ) );
	}

	/**
	 * 本体 target がまだ終わっていなければ、stat target は処理せず retry に戻し、
	 * `retry_after` で本体の lease 期間ぶん claim されないようにすることを確認する.
	 *
	 * @return void
	 */
	public function test_stat_target_is_deferred_while_body_is_not_terminal() {
		$made   = wpcv_test_make_fake_environment();
		$run_id = $made['run_repository']->reserve_run()['run_id'];
		$made['run_repository']->mark_planning_running( $run_id );

		$ids = $made['target_run_repository']->save_target_runs(
			$run_id,
			array(
				// 本体は別 worker が処理中(lease がまだ有効).
				wpcv_test_make_target_run(
					array(
						'target_id' => 'plugin:custom-plugin',
						'dimension' => 'plugin',
						'slug'      => 'custom-plugin',
						'status'    => WPCV_Target_Status::RUNNING,
					)
				),
				wpcv_test_make_target_run(
					array(
						'target_id' => 'plugin:custom-plugin:_stat',
						'dimension' => 'plugin',
						'slug'      => 'custom-plugin',
						'source'    => 'stat',
						'status'    => WPCV_Target_Status::QUEUED,
					)
				),
			)
		);

		$made['wpdb']->rows['wp_wpcv_target_runs'][ $ids['plugin:custom-plugin'] ]['lease_expires_at'] = '2026-09-08 13:00:00';

		$made['dispatcher']->dispatch( $run_id, array( 'version' => '6.8' ) );

		$row = $made['wpdb']->rows['wp_wpcv_target_runs'][ $ids['plugin:custom-plugin:_stat'] ];
		$this->assertSame( WPCV_Target_Status::RETRY, $row['status'] );
		$this->assertNull( $row['error_code'] );
		$this->assertNull( $row['lease_owner'] );
		$this->assertSame(
			gmdate( 'Y-m-d H:i:s', strtotime( '2026-09-08 12:00:00' ) + WPCV_Target_Run_Repository::DEFAULT_LEASE_SECONDS ),
			$row['retry_after']
		);
	}

	/**
	 * 本体が一時的な障害(`http_error`)で unverifiable の場合は、stat 走査を行わず
	 * `skipped` にし、本体の error_code を引き継ぐことを確認する(rev.3 §3.4:
	 * wp.org の不調で大量のベースラインが作られるのを防ぐ).
	 *
	 * @return void
	 */
	public function test_stat_target_is_skipped_when_body_failed_transiently() {
		$this->put_fixture_file( 'wp-content/plugins/custom-plugin/custom-plugin.php', 'main' );

		$plugin_source = new WPCV_Test_Fake_Manifest_Source(
			array(
				'manifest_status' => 'error',
				'error_code'      => WPCV_Error_Code::HTTP_ERROR,
				'files'           => array(),
			)
		);

		$made = wpcv_test_make_fake_environment( null, $plugin_source );
		$this->reserve_and_run(
			$made,
			array(
				'version'    => '6.8',
				'plugins'    => array( 'custom-plugin/custom-plugin.php' => array( 'Version' => '1.0.0' ) ),
				'plugin_dir' => ABSPATH . 'wp-content/plugins',
			)
		);

		$stat = $this->find_target_run( $made, 'plugin:custom-plugin:_stat' );
		$this->assertSame( WPCV_Target_Status::SKIPPED, $stat['status'] );
		$this->assertSame( WPCV_Error_Code::HTTP_ERROR, $stat['error_code'] );
		$this->assertSame( array(), $this->file_states_by_path( $made ) );
	}

	/**
	 * `custom-plugin` 用の run コンテキストを組み立てる.
	 *
	 * @param string $version プラグインの version.
	 * @return array
	 */
	private function custom_plugin_context( $version = '1.0.0' ) {
		return array(
			'version'    => '6.8',
			'plugins'    => array( 'custom-plugin/custom-plugin.php' => array( 'Version' => $version ) ),
			'plugin_dir' => ABSPATH . 'wp-content/plugins',
		);
	}

	/**
	 * 削除したファイルは次の run で `missing` として1回だけ出て、ベースラインから
	 * 消えるため、その次の run では出ないことを確認する(v0.5 §Step7. rev.3 §3.3).
	 *
	 * @return void
	 */
	public function test_deleted_file_is_reported_as_missing_only_once() {
		$this->put_fixture_file( 'wp-content/plugins/custom-plugin/custom-plugin.php', 'main' );
		$this->put_fixture_file( 'wp-content/plugins/custom-plugin/old.php', 'old' );

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run( $made, $this->custom_plugin_context() );

		unlink( ABSPATH . 'wp-content/plugins/custom-plugin/old.php' );

		$second   = $this->reserve_and_run( $made, $this->custom_plugin_context() );
		$findings = $this->stat_findings( $made, $second['run_id'] );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'missing', $findings[0]['status'] );
		$this->assertSame( 'wp-content/plugins/custom-plugin/old.php', $findings[0]['path'] );
		$this->assertArrayNotHasKey( 'wp-content/plugins/custom-plugin/old.php', $this->file_states_by_path( $made ) );
		$this->assertSame( 1, (int) $this->find_target_run( $made, 'plugin:custom-plugin:_stat', $second['run_id'] )['findings_total'] );

		$third = $this->reserve_and_run( $made, $this->custom_plugin_context() );
		$this->assertSame( array(), $this->stat_findings( $made, $third['run_id'] ) );
	}

	/**
	 * Run と run の間に本体の version が変わった(通常の更新)場合、変更を finding に
	 * せずにベースラインを作り直し、`baseline_rebuilt` を記録することを確認する.
	 * 次の run では通常の比較に戻り、`baseline_rebuilt` は付かない(rev.3 §3.7-a/b).
	 *
	 * @return void
	 */
	public function test_version_change_between_runs_rebuilds_baseline_without_findings() {
		$main = 'wp-content/plugins/custom-plugin/custom-plugin.php';
		$this->put_fixture_file( $main, 'main' );
		$this->put_fixture_file( 'wp-content/plugins/custom-plugin/removed-in-update.php', 'x' );

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run( $made, $this->custom_plugin_context( '1.0.0' ) );

		// 更新で中身が変わり、ファイルが1つ消え、1つ増えた状況を模す.
		file_put_contents( ABSPATH . $main, 'main-v1.1' );
		unlink( ABSPATH . 'wp-content/plugins/custom-plugin/removed-in-update.php' );
		$this->put_fixture_file( 'wp-content/plugins/custom-plugin/new-in-update.php', 'y' );
		clearstatcache();

		$second = $this->reserve_and_run( $made, $this->custom_plugin_context( '1.1.0' ) );
		$stat   = $this->find_target_run( $made, 'plugin:custom-plugin:_stat', $second['run_id'] );

		$this->assertSame( array(), $this->stat_findings( $made, $second['run_id'] ) );
		$this->assertSame( WPCV_Target_Status::SUCCESS, $stat['status'] );
		$this->assertSame( WPCV_Error_Code::BASELINE_REBUILT, $stat['error_code'] );

		$states = $this->file_states_by_path( $made );
		$this->assertSame( array( $main, 'wp-content/plugins/custom-plugin/new-in-update.php' ), array_keys( $states ) );
		foreach ( $states as $row ) {
			$this->assertSame( '1.1.0', $row['baseline_version'] );
		}

		$third = $this->reserve_and_run( $made, $this->custom_plugin_context( '1.1.0' ) );
		$this->assertSame( array(), $this->stat_findings( $made, $third['run_id'] ) );
		$this->assertNull( $this->find_target_run( $made, 'plugin:custom-plugin:_stat', $third['run_id'] )['error_code'] );
	}

	/**
	 * 複数 chunk にまたがる target でも、作り直しの `baseline_rebuilt` が最後の chunk まで
	 * 引き継がれ、全ファイルが新しい version のベースラインになることを確認する.
	 *
	 * @return void
	 */
	public function test_baseline_rebuilt_is_kept_across_chunks() {
		$count = WPCV_Chunk_Dispatcher::DEFAULT_BUDGET_MAX_FILES + 10;
		for ( $i = 0; $i < $count; $i++ ) {
			$this->put_fixture_file( sprintf( 'wp-content/plugins/custom-plugin/f%04d.php', $i ), 'x' );
		}

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run( $made, $this->custom_plugin_context( '1.0.0' ) );

		$second = $this->reserve_and_run( $made, $this->custom_plugin_context( '2.0.0' ) );
		$stat   = $this->find_target_run( $made, 'plugin:custom-plugin:_stat', $second['run_id'] );

		$this->assertSame( WPCV_Target_Status::SUCCESS, $stat['status'] );
		$this->assertSame( WPCV_Error_Code::BASELINE_REBUILT, $stat['error_code'] );
		$this->assertSame( $count, (int) $stat['files_total'] );
		$this->assertSame( array(), $this->stat_findings( $made, $second['run_id'] ) );

		$states = $this->file_states_by_path( $made );
		$this->assertCount( $count, $states );
		$this->assertSame( array( '2.0.0' ), array_values( array_unique( array_column( $states, 'baseline_version' ) ) ) );
	}

	/**
	 * Version を変えずに大量のファイルが変わった場合、個別の finding ではなく
	 * target ルートを path にした1件の集約 finding になることを確認する(rev.3 §3.7-c).
	 *
	 * @return void
	 */
	public function test_mass_change_without_version_bump_is_rolled_up() {
		$count = WPCV_Chunk_Dispatcher::DEFAULT_STAT_ROLLUP_MIN_COUNT + 5;
		for ( $i = 0; $i < $count; $i++ ) {
			$this->put_fixture_file( sprintf( 'wp-content/plugins/custom-plugin/f%02d.php', $i ), 'x' );
		}

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run( $made, $this->custom_plugin_context() );

		for ( $i = 0; $i < $count; $i++ ) {
			$path = ABSPATH . sprintf( 'wp-content/plugins/custom-plugin/f%02d.php', $i );
			file_put_contents( $path, 'changed' );
			touch( $path, time() + 100 );
		}
		clearstatcache();

		$second   = $this->reserve_and_run( $made, $this->custom_plugin_context() );
		$findings = $this->stat_findings( $made, $second['run_id'] );

		$this->assertCount( 1, $findings );
		$this->assertSame( 'stat_changed', $findings[0]['status'] );
		$this->assertSame( 'wp-content/plugins/custom-plugin', $findings[0]['path'] );

		$detail = json_decode( $findings[0]['detail'], true );
		$this->assertTrue( $detail['rollup'] );
		$this->assertSame( $count, $detail['count'] );
		$this->assertSame( $count, $detail['files_scanned'] );
		$this->assertCount( WPCV_Verifier::ROLLUP_SAMPLE_PATHS, $detail['sample_paths'] );
	}

	/**
	 * `wpcv_stat_rollup_min_count` フィルターで閾値を上げると、同じ大量変更でも
	 * まとめずに個別の finding になることを確認する(v0.5 §Step8).
	 *
	 * @return void
	 */
	public function test_rollup_threshold_can_be_changed_by_filter() {
		$GLOBALS['_wpcv_test_filters']['wpcv_stat_rollup_min_count'][] = static function () {
			return 1000;
		};

		$count = WPCV_Chunk_Dispatcher::DEFAULT_STAT_ROLLUP_MIN_COUNT + 5;
		for ( $i = 0; $i < $count; $i++ ) {
			$this->put_fixture_file( sprintf( 'wp-content/plugins/custom-plugin/f%02d.php', $i ), 'x' );
		}

		$made = wpcv_test_make_fake_environment();
		$this->reserve_and_run( $made, $this->custom_plugin_context() );

		for ( $i = 0; $i < $count; $i++ ) {
			$path = ABSPATH . sprintf( 'wp-content/plugins/custom-plugin/f%02d.php', $i );
			file_put_contents( $path, 'changed' );
			touch( $path, time() + 100 );
		}
		clearstatcache();

		$second = $this->reserve_and_run( $made, $this->custom_plugin_context() );

		$this->assertCount( $count, $this->stat_findings( $made, $second['run_id'] ) );
	}
}
