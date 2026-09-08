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
