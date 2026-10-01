<?php
/**
 * run の途中の更新への追従(v0.8 §Step1. §9-9)の dispatcher 接続のテスト.
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
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-current-version-reader.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-update-lock-detector.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-chunk-dispatcher.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-starter.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-coordinator.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-update-event-repository.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-update-event-matcher.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Chunk_Dispatcher` が version を処理する時点のディスクから読み直し(D13)、
 * chunk の照合中に入った更新を検知して取り直すこと(§3.3)のテスト.
 *
 * プラン §3.4 の組み合わせ表の各行に対応する:
 *
 * | 更新が入る時点                       | テスト                                                        |
 * |--------------------------------------|---------------------------------------------------------------|
 * | plan のあと・最初の chunk より前     | test_plugin_manifest_is_fetched_with_version_on_disk          |
 * | chunk の途中(version が変わる)     | test_update_during_chunk_is_retried_with_new_version          |
 * | chunk の途中(同じ version の入れ直し)| test_reinstall_event_during_chunk_is_retried                  |
 * | 更新なし                             | test_nothing_changed_fetches_manifest_once                    |
 * | chunk の途中で .maintenance が出る   | test_detect_stale_chunk_is_true_while_maintenance_is_active   |
 * | stat target: plan のあと             | test_stat_baseline_uses_version_on_disk                       |
 * | コアの更新                           | test_core_manifest_is_fetched_with_version_on_disk            |
 * | テーマ                               | test_theme_manifest_is_fetched_with_version_on_disk           |
 */
class FreshVersionDispatchTest extends TestCase {

	/**
	 * 偽の照合ソースへの呼び出しの記録.
	 *
	 * @var array<int, array>
	 */
	private $source_calls = array();

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clean_fixtures();
		$this->source_calls = array();
		unset( $GLOBALS['_wpcv_test_options'], $GLOBALS['_wpcv_test_dispatcher_now'] );
	}

	/**
	 * 各テストの後に作成したフィクスチャを掃除する.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->clean_fixtures();
		unset( $GLOBALS['_wpcv_test_dispatcher_now'] );
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
	 * ABSPATH 相対パスにファイルを作る(上書きもする).
	 *
	 * @param string $relative_path ABSPATH 相対パス.
	 * @param string $content       中身.
	 * @return void
	 */
	private function put_fixture_file( $relative_path, $content ) {
		$path = ABSPATH . $relative_path;

		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0777, true );
		}

		file_put_contents( $path, $content );
		clearstatcache();
	}

	/**
	 * `Version` ヘッダー付きのプラグインのメインファイルの中身.
	 *
	 * @param string $version version.
	 * @param string $marker  中身を区別する印(同じ version の入れ直しの再現に使う).
	 * @return string
	 */
	private static function plugin_source_code( $version, $marker = '' ) {
		return "<?php\n/**\n * Plugin Name: Fresh\n * Version: {$version}\n */\n// {$marker}\n";
	}

	/**
	 * プラグイン `fresh/fresh.php` を持つ `$context`(`get_plugins()` 由来の古い version).
	 *
	 * @param string $version `$context` に入れる(古い可能性がある)version.
	 * @return array
	 */
	private function plugin_context( $version ) {
		return array(
			'version'    => '6.8',
			'plugins'    => array( 'fresh/fresh.php' => array( 'Version' => $version ) ),
			'plugin_dir' => ABSPATH . 'wp-content/plugins',
		);
	}

	/**
	 * 今のメインファイルと一致するマニフェストを返す.
	 *
	 * @return array
	 */
	private static function current_plugin_manifest() {
		$content = (string) file_get_contents( ABSPATH . 'wp-content/plugins/fresh/fresh.php' );

		return array(
			'manifest_status' => 'ok',
			'error_code'      => null,
			'files'           => array(
				'fresh.php' => array(
					'algorithm' => 'sha256',
					'hashes'    => array( hash( 'sha256', $content ) ),
				),
			),
		);
	}

	/**
	 * 呼び出しを記録し、`$responder` の戻り値を返す偽の照合ソース.
	 *
	 * @param callable $responder `function( array $context, int $call_number ): array`.
	 * @return WPCV_Manifest_Source
	 */
	private function recording_source( callable $responder ) {
		$calls = &$this->source_calls;

		return new class( $responder, $calls ) implements WPCV_Manifest_Source {

			/**
			 * 応答を作る callable.
			 *
			 * @var callable
			 */
			private $responder;

			/**
			 * 呼び出しの記録(テストのプロパティへの参照).
			 *
			 * @var array
			 */
			private $calls;

			/**
			 * コンストラクタ.
			 *
			 * @param callable $responder 応答を作る callable.
			 * @param array    $calls     呼び出しの記録(参照).
			 */
			public function __construct( callable $responder, array &$calls ) {
				$this->responder = $responder;
				$this->calls     = &$calls;
			}

			/**
			 * 呼び出しを記録して応答する.
			 *
			 * @param array $context 照合ソースへの context.
			 * @return array
			 */
			public function get_manifest( array $context ) {
				$this->calls[] = $context;

				return call_user_func( $this->responder, $context, count( $this->calls ) );
			}
		};
	}

	/**
	 * Run を予約して完走させる.
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
	 * @return array|null
	 */
	private function find_target_run( array $made, $target_id ) {
		foreach ( $made['wpdb']->rows['wp_wpcv_target_runs'] as $row ) {
			if ( $target_id === $row['target_id'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * 指定 target の findings の件数.
	 *
	 * @param array  $made      `wpcv_test_make_fake_environment()` の戻り値.
	 * @param string $target_id target_id.
	 * @return int
	 */
	private function count_findings( array $made, $target_id ) {
		$count = 0;

		foreach ( $made['wpdb']->rows['wp_wpcv_findings'] ?? array() as $row ) {
			if ( $target_id === $row['target_id'] ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * 更新なし: マニフェストは1回しか取らず、取り直しも起きない(基準).
	 *
	 * @return void
	 */
	public function test_nothing_changed_fetches_manifest_once() {
		$this->put_fixture_file( 'wp-content/plugins/fresh/fresh.php', self::plugin_source_code( '1.0' ) );

		$source = $this->recording_source(
			static function () {
				return self::current_plugin_manifest();
			}
		);
		$made   = wpcv_test_make_fake_environment( null, $source );

		$this->reserve_and_run( $made, $this->plugin_context( '1.0' ) );

		$this->assertCount( 1, $this->source_calls );
		$this->assertSame( 'success', $this->find_target_run( $made, 'plugin:fresh' )['status'] );
	}

	/**
	 * plan のあと・最初の chunk より前に更新が入った: `$context`(1.0)ではなく
	 * ディスク(1.1)の version で照合し、誤った `modified` を出さない(v0.7 §9-9 の実例).
	 *
	 * @return void
	 */
	public function test_plugin_manifest_is_fetched_with_version_on_disk() {
		$this->put_fixture_file( 'wp-content/plugins/fresh/fresh.php', self::plugin_source_code( '1.1' ) );

		$source = $this->recording_source(
			static function () {
				return self::current_plugin_manifest();
			}
		);
		$made   = wpcv_test_make_fake_environment( null, $source );

		$this->reserve_and_run( $made, $this->plugin_context( '1.0' ) );

		foreach ( $this->source_calls as $call ) {
			$this->assertSame( '1.1', $call['version'] );
		}

		$row = $this->find_target_run( $made, 'plugin:fresh' );

		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( '1.1', $row['version'] );
		$this->assertSame( 0, $this->count_findings( $made, 'plugin:fresh' ) );
	}

	/**
	 * chunk の途中で version が変わる更新が入った: 混ざった結果(更新前のマニフェストと
	 * 更新後のファイル)を確定せず、新しい version で取り直す.
	 *
	 * @return void
	 */
	public function test_update_during_chunk_is_retried_with_new_version() {
		$this->put_fixture_file( 'wp-content/plugins/fresh/fresh.php', self::plugin_source_code( '1.0' ) );

		$source = $this->recording_source(
			function ( array $context, $call_number ) {
				$manifest = self::current_plugin_manifest();

				if ( 1 === $call_number ) {
					// マニフェストを返したあと、照合の前に更新が入った(1.0 → 1.1).
					$this->put_fixture_file( 'wp-content/plugins/fresh/fresh.php', self::plugin_source_code( '1.1' ) );
				}

				return $manifest;
			}
		);
		$made   = wpcv_test_make_fake_environment( null, $source );

		$this->reserve_and_run( $made, $this->plugin_context( '1.0' ) );

		$this->assertCount( 2, $this->source_calls );
		$this->assertSame( '1.0', $this->source_calls[0]['version'] );
		$this->assertSame( '1.1', $this->source_calls[1]['version'] );

		$row = $this->find_target_run( $made, 'plugin:fresh' );

		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( '1.1', $row['version'] );
		$this->assertSame( 0, $this->count_findings( $made, 'plugin:fresh' ) );
	}

	/**
	 * chunk の途中で同じ version の入れ直しが入った: version は変わらないが、
	 * 更新イベントが chunk を始めた時刻以降に記録されているので取り直す(§3.3-3).
	 *
	 * @return void
	 */
	public function test_reinstall_event_during_chunk_is_retried() {
		$this->put_fixture_file( 'wp-content/plugins/fresh/fresh.php', self::plugin_source_code( '1.0', 'before' ) );

		$made_holder = array();
		$source      = $this->recording_source(
			function ( array $context, $call_number ) use ( &$made_holder ) {
				$manifest = self::current_plugin_manifest();

				if ( 1 === $call_number ) {
					// 同じ version の入れ直し: ファイルが変わり、更新イベントが記録される
					// (固定時計の 12:00:01. chunk の開始は 12:00:00).
					$this->put_fixture_file( 'wp-content/plugins/fresh/fresh.php', self::plugin_source_code( '1.0', 'after' ) );

					$later = static function () {
						return '2026-09-08 12:00:01';
					};
					( new WPCV_Update_Event_Repository( $made_holder['made']['wpdb'], $later ) )->insert( 'plugin:fresh', '1.0', 'plugin_update' );

					// 取り直しの chunk は 12:00:02 に始まる(固定時計のままだと同じ記録を見続ける).
					$GLOBALS['_wpcv_test_dispatcher_now'] = '2026-09-08 12:00:02';
				}

				return $manifest;
			}
		);

		$made                = wpcv_test_make_fake_environment( null, $source );
		$made_holder['made'] = $made;

		$this->reserve_and_run( $made, $this->plugin_context( '1.0' ) );

		$this->assertCount( 2, $this->source_calls );

		$row = $this->find_target_run( $made, 'plugin:fresh' );

		$this->assertSame( 'success', $row['status'] );
		$this->assertSame( 0, $this->count_findings( $made, 'plugin:fresh' ) );
	}

	/**
	 * `.maintenance` / updater lock が出ている間は、chunk の結果を捨てる(§3.3-2).
	 * lock が消えていれば捨てない.
	 *
	 * @return void
	 */
	public function test_detect_stale_chunk_is_true_while_maintenance_is_active() {
		$made = wpcv_test_make_fake_environment();

		$upgrading = 1000000;
		$detector  = new WPCV_Update_Lock_Detector(
			'/tmp/wpcv-test-abspath-not-used',
			static function () {
				return 1000000;
			},
			static function () use ( &$upgrading ) {
				return $upgrading;
			}
		);

		$dispatcher_property = new ReflectionProperty( WPCV_Chunk_Dispatcher::class, 'update_lock_detector' );
		$dispatcher_property->setAccessible( true );
		$dispatcher_property->setValue( $made['dispatcher'], $detector );

		$method = new ReflectionMethod( WPCV_Chunk_Dispatcher::class, 'detect_stale_chunk' );
		$method->setAccessible( true );

		$stale = $method->invoke( $made['dispatcher'], 'plugin:fresh', '1.0', null, '2026-09-08 12:00:00' );

		$this->assertSame( array( 'version' => '1.0' ), $stale );

		$upgrading = null;

		$this->assertNull( $method->invoke( $made['dispatcher'], 'plugin:fresh', '1.0', null, '2026-09-08 12:00:00' ) );
	}

	/**
	 * version を読み直せなければ(callable が null を返す)version の比較は行わない.
	 *
	 * @return void
	 */
	public function test_detect_stale_chunk_ignores_unreadable_version() {
		$made   = wpcv_test_make_fake_environment();
		$method = new ReflectionMethod( WPCV_Chunk_Dispatcher::class, 'detect_stale_chunk' );
		$method->setAccessible( true );

		$unreadable = static function () {
			return null;
		};

		$this->assertNull( $method->invoke( $made['dispatcher'], 'plugin:fresh', '1.0', $unreadable, '2026-09-08 12:00:00' ) );
	}

	/**
	 * stat target: plan のあとに更新が入っても、ベースラインの version はディスクの値で記録する.
	 *
	 * @return void
	 */
	public function test_stat_baseline_uses_version_on_disk() {
		$this->put_fixture_file( 'wp-content/plugins/fresh/fresh.php', self::plugin_source_code( '1.1' ) );

		// 既定のプラグインソースは manifest_not_found を返すので、stat で走査される.
		$made = wpcv_test_make_fake_environment();

		$this->reserve_and_run( $made, $this->plugin_context( '1.0' ) );

		$stat = $this->find_target_run( $made, 'plugin:fresh:_stat' );

		$this->assertSame( 'success', $stat['status'] );
		$this->assertSame( '1.1', $stat['version'] );

		$states = $made['wpdb']->rows['wp_wpcv_file_states'] ?? array();

		$this->assertNotEmpty( $states );
		foreach ( $states as $row ) {
			$this->assertSame( '1.1', $row['baseline_version'] );
		}
	}

	/**
	 * コア: `get_bloginfo( 'version' )` 由来の `$context['version']`(6.8)ではなく、
	 * `wp-includes/version.php` の値(7.0)で照合する.
	 *
	 * @return void
	 */
	public function test_core_manifest_is_fetched_with_version_on_disk() {
		$this->put_fixture_file( 'wp-includes/version.php', "<?php\n\$wp_version = '7.0';\n" );

		$core = $this->recording_source(
			static function () {
				return array(
					'manifest_status' => 'ok',
					'error_code'      => null,
					'files'           => array(),
				);
			}
		);
		$made = wpcv_test_make_fake_environment( $core );

		$this->reserve_and_run( $made, array( 'version' => '6.8' ) );

		$this->assertNotEmpty( $this->source_calls );
		foreach ( $this->source_calls as $call ) {
			$this->assertSame( '7.0', $call['version'] );
		}
	}

	/**
	 * テーマ: `$context` の version(1.0)ではなく `style.css` の値(2.0)で照合する.
	 *
	 * @return void
	 */
	public function test_theme_manifest_is_fetched_with_version_on_disk() {
		$this->put_fixture_file( 'wp-content/themes/fresh-theme/style.css', "/*\nTheme Name: Fresh\nVersion: 2.0\n*/\n" );

		$theme_source = $this->recording_source(
			static function () {
				return array(
					'manifest_status' => 'ok',
					'error_code'      => null,
					'files'           => array(),
				);
			}
		);
		$made         = wpcv_test_make_fake_environment( null, null, null, null, $theme_source );

		$this->reserve_and_run(
			$made,
			array(
				'version' => '6.8',
				'themes'  => array(
					'fresh-theme' => array(
						'version'        => '1.0',
						'template'       => 'fresh-theme',
						'stylesheet_dir' => ABSPATH . 'wp-content/themes/fresh-theme',
						'update_uri'     => '',
					),
				),
			)
		);

		$this->assertNotEmpty( $this->source_calls );
		$this->assertSame( '2.0', $this->source_calls[0]['version'] );
		$this->assertSame( '2.0', $this->find_target_run( $made, 'theme:fresh-theme' )['version'] );
	}
}
