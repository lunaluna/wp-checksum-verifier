<?php
/**
 * WPCV_Path_Normalizer クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * パスの正規化とパストラバーサル対策.
 *
 * `findings.path`(§5.5)・抑制ルールのパスパターン(§7.2)はいずれも ABSPATH 相対の
 * スラッシュ区切りで保存する。保存時・照合時の両方で `..` を拒否すること
 * (§12.5、WPMAR の対策方針を踏襲).
 */
class WPCV_Path_Normalizer {

	/**
	 * 絶対パスをスラッシュ区切りに統一する(Windows 環境のバックスラッシュ対応).
	 *
	 * @param string $path パス.
	 * @return string
	 */
	public static function to_forward_slashes( $path ) {
		return str_replace( '\\', '/', $path );
	}

	/**
	 * 絶対パスを基準ディレクトリからの相対パスに変換する.
	 *
	 * @param string      $absolute_path 変換対象の絶対パス.
	 * @param string|null $base          基準ディレクトリ. 省略時は ABSPATH.
	 * @return string 相対パス. `$base` 配下でない場合はスラッシュ統一のみ行った
	 *                元のパスをそのまま返す(呼び出し側が範囲外として扱うこと).
	 */
	public static function to_relative( $absolute_path, $base = null ) {
		$base = null === $base ? ABSPATH : $base;
		$base = rtrim( self::to_forward_slashes( $base ), '/' ) . '/';
		$path = self::to_forward_slashes( $absolute_path );

		if ( 0 !== strpos( $path, $base ) ) {
			return $path;
		}

		return substr( $path, strlen( $base ) );
	}

	/**
	 * 相対パスとして安全かどうかを判定する.
	 *
	 * 空文字・null バイト・先頭が `/`(絶対パス相当)・`..` セグメントを含む
	 * 場合は不正とする.
	 *
	 * @param string $relative_path 判定対象.
	 * @return bool
	 */
	public static function is_safe_relative_path( $relative_path ) {
		if ( '' === $relative_path ) {
			return false;
		}

		if ( false !== strpos( $relative_path, "\0" ) ) {
			return false;
		}

		$normalized = self::to_forward_slashes( $relative_path );

		if ( '/' === substr( $normalized, 0, 1 ) ) {
			return false;
		}

		$segments = explode( '/', $normalized );

		return ! in_array( '..', $segments, true );
	}
}
