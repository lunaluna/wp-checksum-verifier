<?php
/**
 * WPCV_Capability と、管理画面の各経路がその判定を使うことのテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-capability.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-settings.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-run-history.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-findings.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-suppressions.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-admin-notices.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-admin-menu.php';

use PHPUnit\Framework\TestCase;

/**
 * 管理画面の権限(v0.9 §Step5・U4)のテスト.
 *
 * 既定(単一サイト = manage_options、マルチサイト = manage_network_options)が変わっていないこと、
 * フィルター `wpcv_required_capability` が全経路(メニュー・画面の表示・POST の処理)に効くこと、
 * 不正な戻り値は既定に倒れることを確かめる. 通知は `AdminNoticesTest` で確かめる.
 *
 * POST の処理は private なので `ReflectionMethod` で呼ぶ. 権限が足りない側(拒否)は、nonce の検証
 * 〔スタブで成功〕のあとの権限の判定で止まるので、リポジトリ等に触れずに確かめられる. 権限が
 * 足りる側(許可)は、先の処理が DB・HTTP に触れるため、メニューの権限の値と `WPCV_Capability` の
 * 単体テストで確かめる.
 */
class CapabilityTest extends TestCase {

	/**
	 * 各テストの前後で、このテスト自身が使うグローバルだけを掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reset_globals();
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		$this->reset_globals();
		parent::tearDown();
	}

	/**
	 * 共有グローバルのうち、このクラスが触るキーだけを消す.
	 *
	 * @return void
	 */
	private function reset_globals() {
		unset(
			$GLOBALS['_wpcv_test_is_multisite'],
			$GLOBALS['_wpcv_test_user_capabilities'],
			$GLOBALS['_wpcv_test_capability_checks'],
			$GLOBALS['_wpcv_test_menu_pages'],
			$GLOBALS['_wpcv_test_filters']['wpcv_required_capability'],
			$_POST[ WPCV_Page_Settings::NONCE_NAME ],
			$_POST[ WPCV_Page_Settings::RUN_NOW_NONCE_NAME ],
			$_POST[ WPCV_Page_Settings::SEND_TEST_ALERT_NONCE_NAME ],
			$_POST[ WPCV_Page_Settings::TOKEN_NONCE_NAME ],
			$_POST[ WPCV_Page_Suppressions::NONCE_NAME ],
			$_POST[ WPCV_Page_Findings::NONCE_NAME ]
		);
	}

	/**
	 * フィルターを登録する(全画面に同じ値を返す).
	 *
	 * @param callable $callback `( $capability, $screen )` を受け取る callable.
	 * @return void
	 */
	private static function add_capability_filter( callable $callback ) {
		$GLOBALS['_wpcv_test_filters']['wpcv_required_capability'][] = $callback;
	}

	/**
	 * 既定: 単一サイトは管理者(manage_options)、マルチサイトはスーパー管理者(manage_network_options).
	 * フィルターを使わなければ、全画面が既定のままであることを確認する(挙動を変えていない).
	 *
	 * @return void
	 */
	public function test_defaults_are_unchanged_for_every_screen() {
		foreach ( WPCV_Capability::SCREENS as $screen ) {
			$this->assertSame( 'manage_options', WPCV_Capability::required( $screen ), "single site: {$screen}" );
		}

		$GLOBALS['_wpcv_test_is_multisite'] = true;

		foreach ( WPCV_Capability::SCREENS as $screen ) {
			$this->assertSame( 'manage_network_options', WPCV_Capability::required( $screen ), "multisite: {$screen}" );
		}

		$this->assertSame( 'manage_network_options', WPCV_Capability::default_capability() );
	}

	/**
	 * フィルターは既定の権限と画面名を受け取り、返した値が使われることを確認する.
	 *
	 * @return void
	 */
	public function test_filter_receives_default_and_screen_and_its_value_is_used() {
		$seen = array();

		self::add_capability_filter(
			static function ( $capability, $screen ) use ( &$seen ) {
				$seen[] = array( $capability, $screen );

				return 'findings' === $screen ? 'edit_posts' : $capability;
			}
		);

		$this->assertSame( 'edit_posts', WPCV_Capability::required( WPCV_Capability::SCREEN_FINDINGS ) );
		$this->assertSame( 'manage_options', WPCV_Capability::required( WPCV_Capability::SCREEN_SETTINGS ), '他の画面は既定のまま.' );
		$this->assertSame(
			array(
				array( 'manage_options', 'findings' ),
				array( 'manage_options', 'settings' ),
			),
			$seen
		);
	}

	/**
	 * フィルターが文字列以外・空文字を返したときは、既定の権限に倒れる(権限が意図せず外れない).
	 *
	 * @return void
	 */
	public function test_invalid_filter_results_fall_back_to_the_default() {
		foreach ( array( '', null, false, array( 'edit_posts' ), 42 ) as $invalid ) {
			unset( $GLOBALS['_wpcv_test_filters']['wpcv_required_capability'] );

			self::add_capability_filter(
				static function () use ( $invalid ) {
					return $invalid;
				}
			);

			$this->assertSame( 'manage_options', WPCV_Capability::required( WPCV_Capability::SCREEN_RUNS ), var_export( $invalid, true ) );
		}
	}

	/**
	 * 未知の画面名(呼び出し側の綴り間違い)は、フィルターを通さず既定を返す(誤って権限を緩めない).
	 *
	 * @return void
	 */
	public function test_unknown_screen_ignores_the_filter() {
		self::add_capability_filter(
			static function () {
				return 'read';
			}
		);

		$this->assertSame( 'manage_options', WPCV_Capability::required( 'findngs' ) );
	}

	/**
	 * メニュー: 単一サイトの4つのメニュー(トップレベル + 3サブ)が、既定の権限で登録される.
	 *
	 * @return void
	 */
	public function test_site_menu_uses_the_default_capability() {
		WPCV_Admin_Menu::add_site_menu();

		$this->assertSame(
			array(
				'wpcv-settings'     => 'manage_options',
				'wpcv-runs'         => 'manage_options',
				'wpcv-findings'     => 'manage_options',
				'wpcv-suppressions' => 'manage_options',
			),
			$GLOBALS['_wpcv_test_menu_pages']
		);
	}

	/**
	 * メニュー: マルチサイト(ネットワーク管理画面)は manage_network_options で登録される.
	 *
	 * @return void
	 */
	public function test_network_menu_uses_the_network_capability() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;

		WPCV_Admin_Menu::add_network_menu();

		$this->assertSame(
			array( 'manage_network_options' ),
			array_values( array_unique( $GLOBALS['_wpcv_test_menu_pages'] ) )
		);
		$this->assertCount( 4, $GLOBALS['_wpcv_test_menu_pages'] );
	}

	/**
	 * メニュー: フィルターで画面ごとに変えた権限が、そのままメニューに反映される.
	 *
	 * @return void
	 */
	public function test_menu_follows_the_filter_per_screen() {
		self::add_capability_filter(
			static function ( $capability, $screen ) {
				$map = array(
					'settings'     => 'cap_settings',
					'runs'         => 'cap_runs',
					'findings'     => 'cap_findings',
					'suppressions' => 'cap_suppressions',
				);

				return $map[ $screen ] ?? $capability;
			}
		);

		WPCV_Admin_Menu::add_site_menu();

		$this->assertSame(
			array(
				'wpcv-settings'     => 'cap_settings',
				'wpcv-runs'         => 'cap_runs',
				'wpcv-findings'     => 'cap_findings',
				'wpcv-suppressions' => 'cap_suppressions',
			),
			$GLOBALS['_wpcv_test_menu_pages']
		);
	}

	/**
	 * 表示: 4つの画面の `render()` は、フィルターで変えた権限で判定する. 既定の権限(manage_options)
	 * だけを持つ人は、変えられた画面では拒否される(何も出力しない).
	 *
	 * @return void
	 */
	public function test_render_checks_the_filtered_capability_and_denies_others() {
		self::add_capability_filter(
			static function () {
				return 'cap_custom';
			}
		);

		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		foreach ( array( 'WPCV_Page_Settings', 'WPCV_Page_Run_History', 'WPCV_Page_Findings', 'WPCV_Page_Suppressions' ) as $page ) {
			$GLOBALS['_wpcv_test_capability_checks'] = array();

			ob_start();
			$page::render();
			$output = ob_get_clean();

			$this->assertSame( '', $output, "{$page}: 拒否されたので何も出さない" );
			$this->assertSame( array( 'cap_custom' ), $GLOBALS['_wpcv_test_capability_checks'], "{$page}: 判定に使った権限" );
		}
	}

	/**
	 * POST の処理: すべての処理が、フィルターで変えた権限で判定し、足りなければ何もしない.
	 *
	 * @return void
	 */
	public function test_post_handlers_check_the_filtered_capability_and_deny_others() {
		self::add_capability_filter(
			static function () {
				return 'cap_custom';
			}
		);

		$GLOBALS['_wpcv_test_user_capabilities'] = array( 'manage_options' );

		$_POST[ WPCV_Page_Settings::NONCE_NAME ]                  = '1';
		$_POST[ WPCV_Page_Settings::RUN_NOW_NONCE_NAME ]          = '1';
		$_POST[ WPCV_Page_Settings::SEND_TEST_ALERT_NONCE_NAME ]  = '1';
		$_POST[ WPCV_Page_Settings::TOKEN_NONCE_NAME ]            = '1';
		$_POST[ WPCV_Page_Suppressions::NONCE_NAME ]              = '1';
		$_POST[ WPCV_Page_Findings::NONCE_NAME ]                  = '1';

		// 処理名 => array( クラス, メソッド, 引数, 拒否のときの戻り値 ).
		$handlers = array(
			array( 'WPCV_Page_Settings', 'maybe_handle_save', array(), false ),
			array( 'WPCV_Page_Settings', 'maybe_handle_run_now', array(), null ),
			array( 'WPCV_Page_Settings', 'maybe_handle_send_test_alert', array(), null ),
			array( 'WPCV_Page_Settings', 'maybe_handle_generate_token', array( WPCV_Page_Settings::TOKEN_NONCE_NAME, WPCV_Page_Settings::TOKEN_NONCE_ACTION, 'run' ), null ),
			array( 'WPCV_Page_Suppressions', 'maybe_handle_revoke', array(), null ),
			array( 'WPCV_Page_Findings', 'maybe_handle_action', array(), null ),
		);

		foreach ( $handlers as $handler ) {
			list( $class, $method, $args, $denied ) = $handler;

			$GLOBALS['_wpcv_test_capability_checks'] = array();

			$reflection = new ReflectionMethod( $class, $method );
			$reflection->setAccessible( true );

			$this->assertSame( $denied, $reflection->invokeArgs( null, $args ), "{$class}::{$method}" );
			$this->assertSame( array( 'cap_custom' ), $GLOBALS['_wpcv_test_capability_checks'], "{$class}::{$method}: 判定に使った権限" );
		}
	}

	/**
	 * フィルターを使わなければ、既定の権限(manage_options)を持つ人は拒否されない
	 * (= 判定に使われる権限が既定のまま). 画面と POST の両方で、判定の権限が既定であることを確認する.
	 *
	 * @return void
	 */
	public function test_without_filter_every_path_checks_the_default_capability() {
		$GLOBALS['_wpcv_test_user_capabilities'] = array(); // 拒否させて、判定の権限だけを記録する.

		$_POST[ WPCV_Page_Suppressions::NONCE_NAME ] = '1';
		$_POST[ WPCV_Page_Findings::NONCE_NAME ]     = '1';

		foreach ( array( 'WPCV_Page_Settings', 'WPCV_Page_Run_History', 'WPCV_Page_Findings', 'WPCV_Page_Suppressions' ) as $page ) {
			$GLOBALS['_wpcv_test_capability_checks'] = array();

			ob_start();
			$page::render();
			ob_end_clean();

			$this->assertSame( array( 'manage_options' ), $GLOBALS['_wpcv_test_capability_checks'], $page );
		}

		foreach ( array( array( 'WPCV_Page_Suppressions', 'maybe_handle_revoke' ), array( 'WPCV_Page_Findings', 'maybe_handle_action' ) ) as $handler ) {
			$GLOBALS['_wpcv_test_capability_checks'] = array();

			$reflection = new ReflectionMethod( $handler[0], $handler[1] );
			$reflection->setAccessible( true );
			$reflection->invoke( null );

			$this->assertSame( array( 'manage_options' ), $GLOBALS['_wpcv_test_capability_checks'], $handler[1] );
		}
	}
}
