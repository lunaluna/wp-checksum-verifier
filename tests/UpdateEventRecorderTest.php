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
	 * `type = theme` は記録しないことを確認する(D11。v0.7までテーマはtargetとして
	 * 列挙されないため、念のための防御).
	 *
	 * @return void
	 */
	public function test_does_not_record_for_theme_type() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Update_Event_Repository( $wpdb );
		$recorder   = new WPCV_Update_Event_Recorder( $repository );

		$recorder->handle_upgrader_process_complete(
			new stdClass(),
			array(
				'type'   => 'theme',
				'action' => 'update',
			)
		);

		$table = $wpdb->base_prefix . 'wpcv_update_events';
		$this->assertArrayNotHasKey( $table, $wpdb->rows );
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
}
