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
	 * Minimal stub of WP_Error — used only as an is_wp_error() marker type in tests.
	 * WPCV_Source_Wporg_Plugin never reads its properties, only checks the type.
	 */
	class WP_Error {
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
	 * action id (mirrors the real function's `int` return on success).
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

		return count( $GLOBALS['_wpcv_test_as_enqueue_calls'] );
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
