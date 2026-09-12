<?php
/**
 * 完全な WordPress ブートストラップ無しで PHPUnit を実行するための最小スタブ.
 *
 * WPMAR(参照実装)の tests/wp-stubs.php と同じ方針: brain/monkey は本プラグインの
 * ような名前空間を持たない設計(グローバル関数をそのまま呼ぶ)には噛み合わない
 * ため使わず、素の関数スタブを都度追加する。挙動をテストごとに変えたいスタブは
 * `$GLOBALS['_wpcv_test_*']` を読む形にする(WPMAR の命名規約を踏襲).
 *
 * @package WPChecksumVerifier
 */

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Stub esc_html.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub __() — 翻訳せずそのまま返す(テストは文言の内容ではなく分岐ロジックだけを
	 * 検証するため翻訳は不要).
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain. 無視する.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.WP.I18n.MissingTranslatorsComment
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'get_core_checksums' ) ) {
	/**
	 * Stub get_core_checksums() — $GLOBALS['_wpcv_test_core_checksums'][$locale] を
	 * 返す(無ければ false)。呼び出し引数は $GLOBALS['_wpcv_test_core_checksums_calls']
	 * に記録する(locale フォールバックの呼び出し順序をテストで検証するため).
	 *
	 * @param string $version Version.
	 * @param string $locale  Locale.
	 * @return array|false
	 */
	function get_core_checksums( $version, $locale ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['_wpcv_test_core_checksums_calls'][] = array( $version, $locale );

		if ( ! isset( $GLOBALS['_wpcv_test_core_checksums'][ $locale ] ) ) {
			return false;
		}

		return $GLOBALS['_wpcv_test_core_checksums'][ $locale ];
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stub of WP_Error. `WPCV_Source_Wporg_Plugin` だけを対象にしていた
	 * 頃は `is_wp_error()` のマーカー型としてしか使わなかったが、
	 * `WPCV_Rest_Run_Controller::check_permission()`(v0.3 §Step9)が
	 * `get_error_data()` でHTTPステータスを読むため、その分だけ実装を持たせる.
	 */
	class WP_Error {

		/**
		 * エラーコード.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * エラーメッセージ.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * エラーデータ(`array( 'status' => 401 )` 等).
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * コンストラクタ.
		 *
		 * @param string $code    エラーコード.
		 * @param string $message エラーメッセージ.
		 * @param mixed  $data    エラーデータ.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * エラーコードを返す.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * エラーメッセージを返す.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}

		/**
		 * エラーデータを返す.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Stub is_wp_error().
	 *
	 * @param mixed $thing Thing to check.
	 * @return bool
	 */
	function is_wp_error( $thing ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * Stub wp_remote_get() — $GLOBALS['_wpcv_test_remote_error'] が真値なら WP_Error を
	 * 返す。それ以外は $GLOBALS['_wpcv_test_remote_response'](無ければ 404 の空
	 * レスポンス)を返す。呼び出し引数は $GLOBALS['_wpcv_test_remote_get_calls'] に
	 * 記録する(URL 組み立てをテストで検証するため).
	 *
	 * @param string $url  URL.
	 * @param array  $args Args.
	 * @return array|WP_Error
	 */
	function wp_remote_get( $url, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['_wpcv_test_remote_get_calls'][] = array( $url, $args );

		if ( ! empty( $GLOBALS['_wpcv_test_remote_error'] ) ) {
			return new WP_Error();
		}

		return isset( $GLOBALS['_wpcv_test_remote_response'] )
			? $GLOBALS['_wpcv_test_remote_response']
			: array(
				'response' => array( 'code' => 404 ),
				'body'     => '',
			);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Stub wp_remote_retrieve_response_code().
	 *
	 * @param array $response Response.
	 * @return int|string
	 */
	function wp_remote_retrieve_response_code( $response ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $response['response']['code'] ) ? $response['response']['code'] : '';
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Stub apply_filters() — returns $value unchanged unless a callback is
	 * registered for $tag in $GLOBALS['_wpcv_test_filters'][$tag] (array of callables,
	 * applied in registration order, WordPress-style).
	 *
	 * @param string $tag   Filter tag.
	 * @param mixed  $value Value to filter.
	 * @return mixed
	 */
	function apply_filters( $tag, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( empty( $GLOBALS['_wpcv_test_filters'][ $tag ] ) ) {
			return $value;
		}

		$args = array_slice( func_get_args(), 1 );

		foreach ( $GLOBALS['_wpcv_test_filters'][ $tag ] as $callback ) {
			$args[0] = call_user_func_array( $callback, $args );
		}

		return $args[0];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Stub wp_remote_retrieve_body().
	 *
	 * @param array $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $response['body'] ) ? (string) $response['body'] : '';
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Stub get_bloginfo() — $GLOBALS['_wpcv_test_bloginfo'][$show] を返す(無ければ空文字).
	 * `WPCV_Context_Builder::build()` は 'version' しか読まない.
	 *
	 * @param string $show 取得したい項目名.
	 * @return string
	 */
	function get_bloginfo( $show = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpcv_test_bloginfo'][ $show ] ) ? $GLOBALS['_wpcv_test_bloginfo'][ $show ] : '';
	}
}

if ( ! function_exists( 'get_plugins' ) ) {
	/**
	 * Stub get_plugins() — $GLOBALS['_wpcv_test_plugins'] を返す(無ければ空配列).
	 * 実際の `get_plugins()` は `wp-admin/includes/plugin.php` の require を必要と
	 * するが、`WPCV_Context_Builder::build()` は `function_exists()` で既存関数を
	 * 優先するため、このスタブがある限り require は発生しない.
	 *
	 * @return array
	 */
	function get_plugins() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpcv_test_plugins'] ) ? $GLOBALS['_wpcv_test_plugins'] : array();
	}
}

if ( ! function_exists( 'get_mu_plugins' ) ) {
	/**
	 * Stub get_mu_plugins() — $GLOBALS['_wpcv_test_mu_plugins'] を返す(無ければ空配列).
	 *
	 * @return array
	 */
	function get_mu_plugins() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpcv_test_mu_plugins'] ) ? $GLOBALS['_wpcv_test_mu_plugins'] : array();
	}
}

// WP_PLUGIN_DIR / WPMU_PLUGIN_DIR は定数のためテストごとに値を変えられない。
// 実際の WordPress の既定値(WP_CONTENT_DIR 配下)と同じ形にしておき、
// ContextBuilderTest はこのパス配下にフィクスチャを置いて検証する.
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
}

if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) {
	define( 'WPMU_PLUGIN_DIR', ABSPATH . 'wp-content/mu-plugins' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Stub add_action() — records the call in
	 * $GLOBALS['_wpcv_test_added_actions'][$hook][] instead of actually wiring a
	 * dispatcher (production code under test calls the registered handler
	 * directly rather than via do_action(), so no dispatch stub is needed here).
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args count.
	 * @return true
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['_wpcv_test_added_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );

		return true;
	}
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Stub as_enqueue_async_action() — records the call in
	 * $GLOBALS['_wpcv_test_as_enqueue_calls'][] and returns a fake incrementing
	 * action id (mirrors the real function's `int` return on success), unless
	 * $GLOBALS['_wpcv_test_as_enqueue_return_zero'] is truthy, in which case it
	 * returns 0 (mirrors the real function's failure return, e.g. Action
	 * Scheduler not initialized or the args-too-long guard tripping).
	 *
	 * @param string $hook     Hook name.
	 * @param array  $args     Args passed to the hook.
	 * @param string $group    Group.
	 * @param bool   $unique   Unique.
	 * @param int    $priority Priority.
	 * @return int
	 */
	function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['_wpcv_test_as_enqueue_calls'][] = array( $hook, $args, $group, $unique, $priority );

		if ( ! empty( $GLOBALS['_wpcv_test_as_enqueue_return_zero'] ) ) {
			return 0;
		}

		return count( $GLOBALS['_wpcv_test_as_enqueue_calls'] );
	}
}

if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * Stub as_schedule_single_action() — records the call in
	 * $GLOBALS['_wpcv_test_as_schedule_single_calls'][] and returns a fake
	 * incrementing action id (mirrors the real function's `int` return on
	 * success), unless $GLOBALS['_wpcv_test_as_schedule_single_return_zero']
	 * is truthy, in which case it returns 0 (mirrors the real function's
	 * failure return). Added for v0.4.0コードレビューCR-06是正
	 * (`WPCV_Chunk_Dispatcher::schedule_via_action_scheduler()` の遅延予約経路のテスト用).
	 *
	 * @param int    $timestamp Unix timestamp.
	 * @param string $hook      Hook name.
	 * @param array  $args      Args passed to the hook.
	 * @param string $group     Group.
	 * @return int
	 */
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['_wpcv_test_as_schedule_single_calls'][] = array( $timestamp, $hook, $args, $group );

		if ( ! empty( $GLOBALS['_wpcv_test_as_schedule_single_return_zero'] ) ) {
			return 0;
		}

		return count( $GLOBALS['_wpcv_test_as_schedule_single_calls'] );
	}
}

if ( ! class_exists( 'ActionScheduler' ) ) {
	/**
	 * Minimal stub of ActionScheduler(実クラスは `lib/action-scheduler/classes/abstracts/ActionScheduler.php`)。
	 * `WPCV_Runner_Async::enqueue_run()` の既定の可用性チェック
	 * (`class_exists('ActionScheduler') && ActionScheduler::is_initialized()`)を
	 * テストできるようにするためのスタブ. `$GLOBALS['_wpcv_test_action_scheduler_initialized']`
	 * (既定 false)を返す.
	 */
	class ActionScheduler {

		/**
		 * Stub ActionScheduler::is_initialized().
		 *
		 * @param string|null $function_name 無視する(実クラスは `_doing_it_wrong()` 用に使うが、本スタブでは不要).
		 * @return bool
		 */
		public static function is_initialized( $function_name = null ) {
			unset( $function_name );

			return ! empty( $GLOBALS['_wpcv_test_action_scheduler_initialized'] );
		}
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * Stub is_multisite() — returns $GLOBALS['_wpcv_test_is_multisite'](既定 false).
	 *
	 * @return bool
	 */
	function is_multisite() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return ! empty( $GLOBALS['_wpcv_test_is_multisite'] );
	}
}

if ( ! function_exists( 'is_main_site' ) ) {
	/**
	 * Stub is_main_site() — 実際の is_main_site() と同じく、非マルチサイトでは
	 * 常に true(`wp-includes/functions.php` の実装を実地確認済み)。マルチサイト
	 * では $GLOBALS['_wpcv_test_is_main_site'](既定 true)を返す.
	 *
	 * @param int|null $site_id    無視する.
	 * @param int|null $network_id 無視する.
	 * @return bool
	 */
	function is_main_site( $site_id = null, $network_id = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $site_id, $network_id );

		if ( ! is_multisite() ) {
			return true;
		}

		return ! isset( $GLOBALS['_wpcv_test_is_main_site'] ) || ! empty( $GLOBALS['_wpcv_test_is_main_site'] );
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	/**
	 * Stub wp_parse_args() — 実際の wp_parse_args() と同じマージ順序
	 * (`array_merge( $defaults, $parsed_args )`。後勝ちだが $args に無いキーは
	 * $defaults から補われる)を再現する簡易実装. 本プラグインは常に配列同士の
	 * マージにしか使わないため、文字列(クエリ文字列)入力の parse_str() 分岐は
	 * 実装しない.
	 *
	 * @param array|object $args     マージ元.
	 * @param array        $defaults 既定値.
	 * @return array
	 */
	function wp_parse_args( $args, $defaults = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$parsed_args = is_object( $args ) ? get_object_vars( $args ) : (array) $args;

		return array_merge( $defaults, $parsed_args );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Stub absint() — 本物と同じく `abs( (int) $value )` を返す
	 * (`WPCV_Page_Run_History::current_page_from_request()` 等が依存する).
	 *
	 * @param mixed $value 変換対象.
	 * @return int
	 */
	function absint( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub get_option() — $GLOBALS['_wpcv_test_options'][$name] を返す(無ければ $default).
	 *
	 * @param string $name    オプション名.
	 * @param mixed  $default 既定値.
	 * @return mixed
	 */
	function get_option( $name, $default = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpcv_test_options'][ $name ] ) ? $GLOBALS['_wpcv_test_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Stub update_option() — $GLOBALS['_wpcv_test_options'][$name] に保存する. 呼び出し
	 * 引数(autoload含む)は $GLOBALS['_wpcv_test_update_option_calls'][] に記録する.
	 *
	 * @param string    $name     オプション名.
	 * @param mixed     $value    保存する値.
	 * @param bool|null $autoload autoload指定. 省略時は本番の既定(null相当)を
	 *                            表す `null` を記録する.
	 * @return true
	 */
	function update_option( $name, $value, $autoload = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['_wpcv_test_options'][ $name ]      = $value;
		$GLOBALS['_wpcv_test_update_option_calls'][] = array( $name, $value, $autoload );

		return true;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * Stub get_site_option() — $GLOBALS['_wpcv_test_site_options'][$name] を返す
	 * (無ければ $default).
	 *
	 * @param string $name    オプション名.
	 * @param mixed  $default 既定値.
	 * @return mixed
	 */
	function get_site_option( $name, $default = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpcv_test_site_options'][ $name ] ) ? $GLOBALS['_wpcv_test_site_options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_site_option' ) ) {
	/**
	 * Stub update_site_option() — $GLOBALS['_wpcv_test_site_options'][$name] に保存する.
	 * 呼び出し引数は $GLOBALS['_wpcv_test_update_site_option_calls'][] に記録する.
	 *
	 * @param string $name  オプション名.
	 * @param mixed  $value 保存する値.
	 * @return true
	 */
	function update_site_option( $name, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['_wpcv_test_site_options'][ $name ]      = $value;
		$GLOBALS['_wpcv_test_update_site_option_calls'][] = array( $name, $value );

		return true;
	}
}

if ( ! function_exists( 'get_main_site_id' ) ) {
	/**
	 * Stub get_main_site_id() — $GLOBALS['_wpcv_test_main_site_id'](既定 1)を返す.
	 *
	 * @param int|null $network_id 無視する.
	 * @return int
	 */
	function get_main_site_id( $network_id = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $network_id );

		return isset( $GLOBALS['_wpcv_test_main_site_id'] ) ? (int) $GLOBALS['_wpcv_test_main_site_id'] : 1;
	}
}

if ( ! function_exists( 'get_blog_option' ) ) {
	/**
	 * Stub get_blog_option() — このダブルはマルチサイトの blog 分離を再現せず、
	 * `$GLOBALS['_wpcv_test_options']` を(実際の `wp_options` と同じ想定で)
	 * そのまま読む単純な実装(`WPCV_Migrator::get_stored_version()` の
	 * legacy フォールバックをテストする用途にはこれで十分. `WPCV_Test_Fake_WPDB`
	 * 同様、実際のマルチサイトDB分離までは再現しない簡易フェイク).
	 *
	 * @param int    $id            無視する.
	 * @param string $option        オプション名.
	 * @param mixed  $default_value 既定値.
	 * @return mixed
	 */
	function get_blog_option( $id, $option, $default_value = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $id );

		return get_option( $option, $default_value );
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Stub wp_next_scheduled() — $GLOBALS['_wpcv_test_scheduled_hooks'][$hook] を
	 * 返す(無ければ false. 本番の「予約が無ければ false」と同じ意味).
	 *
	 * @param string $hook Hook name.
	 * @return int|false
	 */
	function wp_next_scheduled( $hook ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpcv_test_scheduled_hooks'][ $hook ] ) ? $GLOBALS['_wpcv_test_scheduled_hooks'][ $hook ] : false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Stub wp_schedule_single_event() — $GLOBALS['_wpcv_test_scheduled_hooks'][$hook] に
	 * $timestamp を記録する(以降の wp_next_scheduled() 呼び出しに反映させるため).
	 * 呼び出し引数は $GLOBALS['_wpcv_test_schedule_single_event_calls'][] にも記録する.
	 *
	 * @param int    $timestamp Unix timestamp.
	 * @param string $hook      Hook name.
	 * @param array  $args      Args.
	 * @return true
	 */
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['_wpcv_test_scheduled_hooks'][ $hook ]      = $timestamp;
		$GLOBALS['_wpcv_test_schedule_single_event_calls'][] = array( $timestamp, $hook, $args );

		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	/**
	 * Stub wp_clear_scheduled_hook() — $GLOBALS['_wpcv_test_scheduled_hooks'][$hook] を
	 * 削除する. 呼び出し回数は $GLOBALS['_wpcv_test_clear_scheduled_hook_calls'][] に
	 * 記録する.
	 *
	 * @param string $hook Hook name.
	 * @param array  $args Args.
	 * @return int
	 */
	function wp_clear_scheduled_hook( $hook, $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $GLOBALS['_wpcv_test_scheduled_hooks'][ $hook ] );
		$GLOBALS['_wpcv_test_clear_scheduled_hook_calls'][] = array( $hook, $args );

		return 1;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Stub wp_salt() — テスト全体で固定の文字列を返す(実際の salt の値そのものは
	 * `WPCV_Rest_Token` の検証ロジックにとって意味を持たず、「同じ入力なら同じ
	 * ハッシュになる」ことだけが重要なため).
	 *
	 * @param string $scheme Scheme. 無視する.
	 * @return string
	 */
	function wp_salt( $scheme = 'auth' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $scheme );

		return 'wpcv-test-fixed-salt';
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Stub get_transient() — $GLOBALS['_wpcv_test_transients'][$key] を返す
	 * (無ければ false. 本番の「未設定/期限切れ」と同じ意味).
	 *
	 * @param string $key Transient key.
	 * @return mixed
	 */
	function get_transient( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return isset( $GLOBALS['_wpcv_test_transients'][ $key ] ) ? $GLOBALS['_wpcv_test_transients'][ $key ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Stub set_transient() — $GLOBALS['_wpcv_test_transients'][$key] に保存する
	 * (有効期限は本テストダブルでは再現しない. `WPCV_Rest_Token` のテストは
	 * ウィンドウ経過による自然失効ではなく `clear_failed_attempts()` による
	 * 明示的な削除だけを検証するため).
	 *
	 * @param string $key        Transient key.
	 * @param mixed  $value      値.
	 * @param int    $expiration 有効期限(秒). 無視する.
	 * @return true
	 */
	function set_transient( $key, $value, $expiration = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $expiration );
		$GLOBALS['_wpcv_test_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Stub delete_transient().
	 *
	 * @param string $key Transient key.
	 * @return true
	 */
	function delete_transient( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $GLOBALS['_wpcv_test_transients'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Stub sanitize_text_field() — 改行・タグを取り除く程度の簡易実装で十分
	 * (`WPCV_Rest_Run_Controller::client_identifier()` がIPアドレス文字列に使う
	 * だけで、厳密な本番相当の実装は不要なため).
	 *
	 * @param string $value 入力値.
	 * @return string
	 */
	function sanitize_text_field( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return trim( wp_strip_all_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Stub wp_strip_all_tags().
	 *
	 * @param string $value 入力値.
	 * @return string
	 */
	function wp_strip_all_tags( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return wp_unslash( strip_tags( (string) $value ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Stub wp_unslash() — テストではスラッシュ付加が起きないため、そのまま返す.
	 *
	 * @param mixed $value 入力値.
	 * @return mixed
	 */
	function wp_unslash( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $value;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Stub current_user_can() — $GLOBALS['_wpcv_test_user_capabilities'](文字列の
	 * 配列)に `$capability` が含まれているかどうかを返す(既定は空配列 = 常に false).
	 *
	 * @param string $capability Capability.
	 * @return bool
	 */
	function current_user_can( $capability ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$allowed = isset( $GLOBALS['_wpcv_test_user_capabilities'] ) ? $GLOBALS['_wpcv_test_user_capabilities'] : array();

		return in_array( $capability, $allowed, true );
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Stub register_rest_route() — records the call in
	 * $GLOBALS['_wpcv_test_registered_rest_routes'][] instead of wiring real routing.
	 *
	 * @param string $rest_namespace Namespace.
	 * @param string $route          Route.
	 * @param array  $args           Args.
	 * @param bool   $override       Override.
	 * @return true
	 */
	function register_rest_route( $rest_namespace, $route, $args = array(), $override = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$GLOBALS['_wpcv_test_registered_rest_routes'][] = array( $rest_namespace, $route, $args, $override );

		return true;
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * Minimal stub of WP_REST_Server — only the constants our controllers read.
	 */
	class WP_REST_Server {
		const CREATABLE = 'POST';
		const READABLE  = 'GET';
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal stub of WP_REST_Request. v0.3 §Step8のハンドラはリクエスト
	 * パラメータを読まないため空のマーカー型で足りていたが、v0.4.0 §Step7の
	 * `GET /status`/`GET /findings` はクエリパラメータ(dimension/status/severity/
	 * sort/order/page/per_page/run_id/include_suppressed/include_closed)を
	 * 読むため `get_param()` を実装する。実 WordPress の `WP_REST_Request` は
	 * クエリ文字列・JSONボディの両方から自動でパラメータを解決するが、この
	 * スタブは単体テストが渡した連想配列をそのまま返すだけで十分.
	 */
	class WP_REST_Request {

		/**
		 * パラメータ名 => 値.
		 *
		 * @var array<string,mixed>
		 */
		private $params;

		/**
		 * コンストラクタ.
		 *
		 * @param array<string,mixed> $params パラメータ名 => 値.
		 */
		public function __construct( array $params = array() ) {
			$this->params = $params;
		}

		/**
		 * パラメータを返す.
		 *
		 * @param string $name パラメータ名.
		 * @return mixed 未指定なら `null`.
		 */
		public function get_param( $name ) {
			return isset( $this->params[ $name ] ) ? $this->params[ $name ] : null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal stub of WP_REST_Response — records the body and headers so tests can
	 * assert on them without a real REST server.
	 */
	class WP_REST_Response {

		/**
		 * レスポンスボディ.
		 *
		 * @var mixed
		 */
		public $data;

		/**
		 * ヘッダー(ヘッダー名 => 値).
		 *
		 * @var array<string,string>
		 */
		private $headers = array();

		/**
		 * コンストラクタ.
		 *
		 * @param mixed $data   レスポンスボディ.
		 * @param int   $status HTTPステータスコード. 本テストダブルでは未使用.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data = $data;
			unset( $status );
		}

		/**
		 * ヘッダーを設定する.
		 *
		 * @param string $key   ヘッダー名.
		 * @param string $value 値.
		 * @return void
		 */
		public function header( $key, $value ) {
			$this->headers[ $key ] = $value;
		}

		/**
		 * 設定済みのヘッダーを返す(テスト用アクセサ).
		 *
		 * @return array<string,string>
		 */
		public function get_headers() {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal stub of WP_CLI — records each call's arguments in
	 * $GLOBALS['_wpcv_test_wp_cli_calls'][$method][] for assertions. Unlike the
	 * real WP_CLI::error(), this stub does not exit the process, so tests can
	 * assert on the recorded message instead of catching a process exit.
	 */
	class WP_CLI {

		/**
		 * Stub WP_CLI::add_command().
		 *
		 * @param string          $name     Command name.
		 * @param callable|string $callable Command implementation.
		 * @param array           $args     Extra options.
		 * @return void
		 */
		public static function add_command( $name, $callable, $args = array() ) {
			$GLOBALS['_wpcv_test_wp_cli_calls']['add_command'][] = array( $name, $callable, $args );
		}

		/**
		 * Stub WP_CLI::success().
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( $message ) {
			$GLOBALS['_wpcv_test_wp_cli_calls']['success'][] = $message;
		}

		/**
		 * Stub WP_CLI::error() — records the message instead of exiting.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function error( $message ) {
			$GLOBALS['_wpcv_test_wp_cli_calls']['error'][] = $message;
		}

		/**
		 * Stub WP_CLI::line().
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function line( $message = '' ) {
			$GLOBALS['_wpcv_test_wp_cli_calls']['line'][] = $message;
		}
	}
}
