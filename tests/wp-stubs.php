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
