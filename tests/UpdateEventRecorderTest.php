<?php
/**
 * WPCV_Update_Event_Recorder のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-update-event-repository.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-run-planner.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-update-event-recorder.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * テスト用の `Plugin_Upgrader` ダブル(`action = install` の経路専用.
 * `WPCV_Update_Event_Recorder::record_install()` が呼ぶ `plugin_info()` だけを実装する).
 */
class WPCV_Test_Fake_Plugin_Upgrader {

	/**
	 * `plugin_info()` が返す値.
	 *
	 * @var mixed
	 */
	private $plugin_info;

	/**
	 * コンストラクタ.
	 *
	 * @param mixed $plugin_info `plugin_info()` の戻り値としてそのまま返す.
	 */
	public function __construct( $plugin_info ) {
		$this->plugin_info = $plugin_info;
	}

	/**
	 * 固定値を返す.
	 *
	 * @return mixed
	 */
	public function plugin_info() {
		return $this->plugin_info;
	}
}

/**
 * `WPCV_Update_Event_Recorder` のテスト(v0.6プラン §Step2)。
 *
 * プラン§1.1の表(発火箇所と `$hook_extra` の形)の全行を、それぞれの
 * `$hook_extra` の形を模して検証する。`plugin_info()` を持たない `$upgrader`
 * (単体・一括更新)は `new stdClass()` で十分(`process_hook_extra()` は
 * install の分岐でしか `$upgrader` を使わないため).
 */
class UpdateEventRecorderTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['_wpcv_test_plugin_data'], $GLOBALS['_wpcv_test_current_user_id'] );
		$this->remove_fixture_themes();
	}

	/**
	 * 作ったテーマのフィクスチャを消す(v0.7 §Step6).
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->remove_fixture_themes();
		parent::tearDown();
	}

	/**
	 * ABSPATH の `wp-content` 以下を消す(このテストが作るのはテーマだけ).
	 *
	 * @return void
	 */
	private function remove_fixture_themes() {
		$root = ABSPATH . 'wp-content';

		if ( ! is_dir( $root ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $iterator as $entry ) {
			if ( $entry->isDir() ) {
				rmdir( $entry->getPathname() );
			} else {
				unlink( $entry->getPathname() );
			}
		}

		rmdir( $root );
	}

	/**
	 * `WP_PLUGIN_DIR . '/' . $plugin_file` の形で `get_plugin_data()` スタブに
	 * Version を登録する(`read_plugin_version()` がこの形でキーを引くため.
	 * D4参照).
	 *
	 * @param string $plugin_file `get_plugins()` のキー形式.
	 * @param string $version     登録する Version 値.
	 * @return void
	 */
	private function stub_plugin_version( $plugin_file, $version ) {
		$GLOBALS['_wpcv_test_plugin_data'][ WP_PLUGIN_DIR . '/' . $plugin_file ] = array( 'Version' => $version );
	}

	/**
	 * 単体更新(プラン§1.1の1行目. `type=plugin`・`action=update`・`plugin`)で
	 * 1件記録され、`source = plugin_update` になることを確認する.
	 *
	 * @return void
	 */
	public function test_records_single_plugin_update() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$this->stub_plugin_version( 'acme-widgets/acme-widgets.php', '1.2.0' );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'plugin' => 'acme-widgets/acme-widgets.php',
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertCount( 1, $wpdb->rows[ $table ] );

		$row = reset( $wpdb->rows[ $table ] );
		$this->assertSame( 'plugin:acme-widgets', $row['target_id'] );
		$this->assertSame( '1.2.0', $row['version'] );
		$this->assertSame( 'plugin_update', $row['source'] );
	}

	/**
	 * 一括更新(プラン§1.1の2行目. `bulk=true`・`plugins`配列)で、配列の
	 * 件数分だけ記録され、`source = plugin_bulk_update` になることを確認する.
	 *
	 * @return void
	 */
	public function test_records_bulk_plugin_update_for_each_plugin() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$this->stub_plugin_version( 'acme-widgets/acme-widgets.php', '1.2.0' );
		$this->stub_plugin_version( 'other-widgets/other-widgets.php', '2.0.0' );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'type'    => 'plugin',
				'action'  => 'update',
				'bulk'    => true,
				'plugins' => array( 'acme-widgets/acme-widgets.php', 'other-widgets/other-widgets.php' ),
			)
		);

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertCount( 2, $wpdb->rows[ $table ] );

		$target_ids = array_column( $wpdb->rows[ $table ], 'target_id' );
		sort( $target_ids );
		$this->assertSame( array( 'plugin:acme-widgets', 'plugin:other-widgets' ), $target_ids );

		foreach ( $wpdb->rows[ $table ] as $row ) {
			$this->assertSame( 'plugin_bulk_update', $row['source'] );
		}
	}

	/**
	 * 新規インストール・zipアップロードでの上書き・`wp plugin update --version=X`
	 * (プラン§1.1の3行目. `action=install`)で、`$upgrader->plugin_info()` から
	 * slugを求めて記録し、`source = plugin_install` になることを確認する.
	 *
	 * @return void
	 */
	public function test_records_install_action_using_plugin_info() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$this->stub_plugin_version( 'acme-widgets/acme-widgets.php', '1.3.0' );

		$recorder->handle_upgrader_process_complete(
			new WPCV_Test_Fake_Plugin_Upgrader( 'acme-widgets/acme-widgets.php' ),
			array(
				'type'   => 'plugin',
				'action' => 'install',
			)
		);

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertCount( 1, $wpdb->rows[ $table ] );

		$row = reset( $wpdb->rows[ $table ] );
		$this->assertSame( 'plugin:acme-widgets', $row['target_id'] );
		$this->assertSame( '1.3.0', $row['version'] );
		$this->assertSame( 'plugin_install', $row['source'] );
	}

	/**
	 * `plugin_info()` が空文字列を返す場合(取得できなかった場合)は何も
	 * 記録しないことを確認する.
	 *
	 * @return void
	 */
	public function test_install_action_records_nothing_when_plugin_info_is_empty() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$recorder->handle_upgrader_process_complete(
			new WPCV_Test_Fake_Plugin_Upgrader( '' ),
			array(
				'type'   => 'plugin',
				'action' => 'install',
			)
		);

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertArrayNotHasKey( $table, $wpdb->rows );
	}

	/**
	 * `$upgrader` が `plugin_info()` を持たない場合(型が想定と異なる場合)は
	 * 何も記録しないことを確認する.
	 *
	 * @return void
	 */
	public function test_install_action_records_nothing_when_upgrader_has_no_plugin_info_method() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'type'   => 'plugin',
				'action' => 'install',
			)
		);

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertArrayNotHasKey( $table, $wpdb->rows );
	}

	/**
	 * `type = core`(プラン§1.1の4行目)は `upgrader_process_complete` 側では
	 * 何も記録しないことを確認する(D2: コアは `_core_updated_successfully` で
	 * 処理する).
	 *
	 * @return void
	 */
	public function test_does_not_record_for_core_type() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'type'   => 'core',
				'action' => 'update',
			)
		);

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertArrayNotHasKey( $table, $wpdb->rows );
	}

	/**
	 * `type = translation`(プラン§1.1の5行目)は記録しないことを確認する(D11).
	 *
	 * @return void
	 */
	public function test_does_not_record_for_translation_type() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'type'   => 'translation',
				'action' => 'update',
			)
		);

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertArrayNotHasKey( $table, $wpdb->rows );
	}

	/**
	 * テーマのディレクトリと `style.css` を作る(v0.7 §Step6).
	 *
	 * @param string      $stylesheet テーマの stylesheet.
	 * @param string|null $version    `Version:` ヘッダーの値. null なら style.css を作らない.
	 * @return string テーマのディレクトリ.
	 */
	private function make_theme( $stylesheet, $version ) {
		$dir = ABSPATH . 'wp-content/themes/' . $stylesheet;

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		if ( null !== $version ) {
			file_put_contents( $dir . '/style.css', "/*\nTheme Name: {$stylesheet}\nVersion: {$version}\n*/\n" );
		}

		return $dir;
	}

	/**
	 * 記録された行を返す.
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @return array<int, array>
	 */
	private static function event_rows( WPCV_Test_Fake_WPDB $wpdb ) {
		return array_values( $wpdb->rows[ $wpdb->base_prefix . 'wpcv_update_events' ] ?? array() );
	}

	/**
	 * テーマの単体更新・自動更新(`type=theme`・`action=update`・`theme`)で、
	 * `style.css` から読み直した version が `theme_update` として記録される
	 * (v0.7 §Step6. D9).
	 *
	 * @return void
	 */
	public function test_records_single_theme_update_with_version_from_style_css() {
		$wpdb     = new WPCV_Test_Fake_WPDB();
		$recorder = new WPCV_Update_Event_Recorder( new WPCV_Update_Event_Repository( $wpdb ) );

		$this->make_theme( 'acme', '2.0.1' );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'theme'  => 'acme',
				'type'   => 'theme',
				'action' => 'update',
			)
		);

		$rows = self::event_rows( $wpdb );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'theme:acme', $rows[0]['target_id'] );
		$this->assertSame( '2.0.1', $rows[0]['version'] );
		$this->assertSame( 'theme_update', $rows[0]['source'] );
	}

	/**
	 * テーマの一括更新(`bulk=true`・`themes` 配列)で、テーマごとに `theme_bulk_update` が
	 * 記録される(v0.7 §Step6).
	 *
	 * @return void
	 */
	public function test_records_bulk_theme_update_for_each_theme() {
		$wpdb     = new WPCV_Test_Fake_WPDB();
		$recorder = new WPCV_Update_Event_Recorder( new WPCV_Update_Event_Repository( $wpdb ) );

		$this->make_theme( 'acme', '2.0' );
		$this->make_theme( 'beta', '3.1' );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'action' => 'update',
				'type'   => 'theme',
				'bulk'   => true,
				'themes' => array( 'acme', 'beta' ),
			)
		);

		$rows = self::event_rows( $wpdb );
		$this->assertSame( array( 'theme:acme', 'theme:beta' ), array_column( $rows, 'target_id' ) );
		$this->assertSame( array( '2.0', '3.1' ), array_column( $rows, 'version' ) );
		$this->assertSame( array( 'theme_bulk_update', 'theme_bulk_update' ), array_column( $rows, 'source' ) );
	}

	/**
	 * テーマのインストール(`action=install`. slug は無い)では `theme_info()` の
	 * テーマのディレクトリから version を読み、`theme_install` として記録する(v0.7 §Step6).
	 *
	 * @return void
	 */
	public function test_records_theme_install_using_theme_info() {
		$wpdb     = new WPCV_Test_Fake_WPDB();
		$recorder = new WPCV_Update_Event_Recorder( new WPCV_Update_Event_Repository( $wpdb ) );
		$dir      = $this->make_theme( 'acme', '1.0' );

		$upgrader = new class( $dir ) {

			/**
			 * テーマのディレクトリ.
			 *
			 * @var string
			 */
			private $dir;

			/**
			 * コンストラクタ.
			 *
			 * @param string $dir テーマのディレクトリ.
			 */
			public function __construct( $dir ) {
				$this->dir = $dir;
			}

			/**
			 * `Theme_Upgrader::theme_info()` の代わり(`WP_Theme` の代わりの物を返す).
			 *
			 * @return object
			 */
			public function theme_info() {
				$dir = $this->dir;

				return new class( $dir ) {

					/**
					 * テーマのディレクトリ.
					 *
					 * @var string
					 */
					private $dir;

					/**
					 * コンストラクタ.
					 *
					 * @param string $dir テーマのディレクトリ.
					 */
					public function __construct( $dir ) {
						$this->dir = $dir;
					}

					/**
					 * Stylesheet を返す.
					 *
					 * @return string
					 */
					public function get_stylesheet() {
						return basename( $this->dir );
					}

					/**
					 * ディレクトリを返す.
					 *
					 * @return string
					 */
					public function get_stylesheet_directory() {
						return $this->dir;
					}
				};
			}
		};

		$recorder->handle_upgrader_process_complete(
			$upgrader,
			array(
				'type'   => 'theme',
				'action' => 'install',
			)
		);

		$rows = self::event_rows( $wpdb );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'theme:acme', $rows[0]['target_id'] );
		$this->assertSame( '1.0', $rows[0]['version'] );
		$this->assertSame( 'theme_install', $rows[0]['source'] );
	}

	/**
	 * `style.css` が読めなければ version は null で記録する(D3 と同じ).
	 *
	 * @return void
	 */
	public function test_records_null_theme_version_when_style_css_is_missing() {
		$wpdb     = new WPCV_Test_Fake_WPDB();
		$recorder = new WPCV_Update_Event_Recorder( new WPCV_Update_Event_Repository( $wpdb ) );

		$this->make_theme( 'broken', null );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'theme'  => 'broken',
				'type'   => 'theme',
				'action' => 'update',
			)
		);

		$rows = self::event_rows( $wpdb );
		$this->assertCount( 1, $rows );
		$this->assertNull( $rows[0]['version'] );
	}

	/**
	 * テーマの名前が無い更新・`theme_info()` を持たない install・`type` の無い発火
	 * (子テーマと一緒に入る親テーマの `run()`)は何も記録しない(v0.7 §Step6).
	 *
	 * @return void
	 */
	public function test_does_not_record_theme_without_identifiable_stylesheet() {
		$wpdb     = new WPCV_Test_Fake_WPDB();
		$recorder = new WPCV_Update_Event_Recorder( new WPCV_Update_Event_Repository( $wpdb ) );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'type'   => 'theme',
				'action' => 'update',
			)
		);
		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'type'   => 'theme',
				'action' => 'install',
			)
		);
		$recorder->handle_upgrader_process_complete( new stdClass(), array() );

		$this->assertSame( array(), self::event_rows( $wpdb ) );
	}

	/**
	 * `get_plugin_data()` が Version を返さない(読めなかった)場合、
	 * `version = null` で記録されることを確認する(D3「読めなければNULL」).
	 *
	 * @return void
	 */
	public function test_records_null_version_when_version_cannot_be_read() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		// stub_plugin_version() を呼ばない = get_plugin_data() スタブは空配列を返す.
		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'plugin' => 'unknown-widgets/unknown-widgets.php',
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$row   = reset( $wpdb->rows[ $table ] );

		$this->assertNull( $row['version'] );
	}

	/**
	 * `created_by` に `get_current_user_id()` の値がそのまま使われることを確認する.
	 *
	 * @return void
	 */
	public function test_records_created_by_from_current_user() {
		$GLOBALS['_wpcv_test_current_user_id'] = 7;

		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$this->stub_plugin_version( 'acme-widgets/acme-widgets.php', '1.2.0' );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'plugin' => 'acme-widgets/acme-widgets.php',
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$row   = reset( $wpdb->rows[ $table ] );

		$this->assertSame( 7, $row['created_by'] );
	}

	/**
	 * `$hook_extra` が配列でない場合でも例外を投げず、何も記録しないことを確認する
	 * (防御的: WordPress側が将来 `null` 等を渡す可能性を考慮).
	 *
	 * @return void
	 */
	public function test_handles_non_array_hook_extra_without_throwing() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$recorder->handle_upgrader_process_complete( new stdClass(), null );

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertArrayNotHasKey( $table, $wpdb->rows );
	}

	/**
	 * `Repository::insert()` が例外を投げても、`handle_upgrader_process_complete()`
	 * は外へ例外を漏らさないことを確認する(クラスdocblock参照: 更新処理自体を
	 * 壊さないため).
	 *
	 * @return void
	 */
	public function test_handle_upgrader_process_complete_swallows_repository_exception() {
		$wpdb                     = new WPCV_Test_Fake_WPDB();
		$wpdb->insert_should_fail = true;
		$repository               = new WPCV_Update_Event_Repository( $wpdb );
		$recorder                 = new WPCV_Update_Event_Recorder( $repository );

		$this->stub_plugin_version( 'acme-widgets/acme-widgets.php', '1.2.0' );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'plugin' => 'acme-widgets/acme-widgets.php',
				'type'   => 'plugin',
				'action' => 'update',
			)
		);

		// 例外を投げずにここまで到達すれば成功(assertが無いとPHPUnitがriskyと
		// 見なすため、明示的に到達を確認する).
		$this->assertTrue( true );
	}

	/**
	 * `_core_updated_successfully` フックのハンドラが、`target_id = core`・
	 * `source = core_update` で記録することを確認する(プラン§1.2参照。
	 * `$wp_version` はディスクの読み直しではなくフックの引数そのものを使う).
	 *
	 * @return void
	 */
	public function test_handle_core_updated_successfully_records_core_event() {
		$GLOBALS['_wpcv_test_current_user_id'] = 3;

		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$recorder->handle_core_updated_successfully( '6.9' );

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertCount( 1, $wpdb->rows[ $table ] );

		$row = reset( $wpdb->rows[ $table ] );
		$this->assertSame( 'core', $row['target_id'] );
		$this->assertSame( '6.9', $row['version'] );
		$this->assertSame( 'core_update', $row['source'] );
		$this->assertSame( 3, $row['created_by'] );
	}

	/**
	 * `_core_updated_successfully` に空文字列が渡された場合、`version = null` で
	 * 記録されることを確認する(D3と同じ「読めなければNULL」の扱い).
	 *
	 * @return void
	 */
	public function test_handle_core_updated_successfully_records_null_version_for_empty_string() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$recorder->handle_core_updated_successfully( '' );

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$row   = reset( $wpdb->rows[ $table ] );

		$this->assertNull( $row['version'] );
	}

	/**
	 * `Repository::insert()` が例外を投げても、`handle_core_updated_successfully()`
	 * は外へ例外を漏らさないことを確認する.
	 *
	 * @return void
	 */
	public function test_handle_core_updated_successfully_swallows_repository_exception() {
		$wpdb                     = new WPCV_Test_Fake_WPDB();
		$wpdb->insert_should_fail = true;
		$repository               = new WPCV_Update_Event_Repository( $wpdb );
		$recorder                 = new WPCV_Update_Event_Recorder( $repository );

		$recorder->handle_core_updated_successfully( '6.9' );

		$this->assertTrue( true );
	}

	/**
	 * `handle_run_terminated()` が既定90日より古い行だけを削除することを確認する
	 * (v0.6 §Step7. Step1で実装した`delete_older_than()`がStep2・3のどちらでも
	 * 配線されないまま残っていたのを、`wpcv_run_terminated`フックに接続した).
	 *
	 * @return void
	 */
	public function test_handle_run_terminated_deletes_events_older_than_default_retention() {
		unset( $GLOBALS['_wpcv_test_filters'] );

		$wpdb = new WPCV_Test_Fake_WPDB();

		( new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-06-01 00:00:00';
		} ) )->insert( 'plugin:old', '1.0.0', 'plugin_update' );

		( new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-09-25 00:00:00';
		} ) )->insert( 'plugin:new', '1.0.0', 'plugin_update' );

		$recorder = new WPCV_Update_Event_Recorder(
			new WPCV_Update_Event_Repository( $wpdb, static function () {
				return '2026-09-29 00:00:00';
			} )
		);

		$recorder->handle_run_terminated( 42, 'success' );

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertCount( 1, $wpdb->rows[ $table ] );
		$this->assertSame( 'plugin:new', reset( $wpdb->rows[ $table ] )['target_id'] );
	}

	/**
	 * `handle_run_terminated()` が `wpcv_update_events_retention_days` フィルターで
	 * 保持日数を変更できることを確認する.
	 *
	 * @return void
	 */
	public function test_handle_run_terminated_respects_retention_days_filter() {
		unset( $GLOBALS['_wpcv_test_filters'] );
		$GLOBALS['_wpcv_test_filters']['wpcv_update_events_retention_days'][] = static function () {
			return 30;
		};

		$wpdb = new WPCV_Test_Fake_WPDB();

		( new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-08-15 00:00:00';
		} ) )->insert( 'plugin:old-enough-for-30-days', '1.0.0', 'plugin_update' );

		$recorder = new WPCV_Update_Event_Recorder(
			new WPCV_Update_Event_Repository( $wpdb, static function () {
				return '2026-09-29 00:00:00';
			} )
		);

		$recorder->handle_run_terminated( 42, 'success' );

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertCount( 0, $wpdb->rows[ $table ] );

		unset( $GLOBALS['_wpcv_test_filters'] );
	}

	/**
	 * `handle_run_terminated()` が `Repository::delete_older_than()` の例外を
	 * 外へ漏らさないことを確認する(掃除の失敗で他のリスナーやrun確定自体を
	 * 妨げないため).
	 *
	 * @return void
	 */
	public function test_handle_run_terminated_swallows_repository_exception() {
		$wpdb                     = new WPCV_Test_Fake_WPDB();
		$wpdb->delete_should_fail = true;

		( new WPCV_Update_Event_Repository( $wpdb, static function () {
			return '2026-06-01 00:00:00';
		} ) )->insert( 'plugin:old', '1.0.0', 'plugin_update' );

		$recorder = new WPCV_Update_Event_Recorder(
			new WPCV_Update_Event_Repository( $wpdb, static function () {
				return '2026-09-29 00:00:00';
			} )
		);

		$recorder->handle_run_terminated( 42, 'success' );

		$this->assertTrue( true );
	}
}
