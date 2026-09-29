<?php
/**
 * WPCV_Finding_Key クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `finding` の差分キー(`wpcv_findings.finding_key`)を計算する(v0.5後半プラン §1.4).
 *
 * 差分処理(v0.5後半プラン §2. Step12以降)は、同一targetの「今回のrun」と
 * 「基準のrun」で同じキーを持つfindingがあるかどうかで NEW/CONTINUING/RESOLVED を
 * 判定する. DBに触れない純粋な計算のみを行い、`WPCV_Finding_Repository::save_findings()`
 * (保存時)と差分処理(比較時)の両方から共有する.
 *
 * `expected_hash`/`hash_algorithm` をキーに含めるのは、同じ version でマニフェストが
 * 修正され期待値が変わった場合に、別のfinding(NEW)として扱うため(同じpath・statusの
 * 行として上書き=継続と誤判定させないための設計. プラン §1.4参照).
 */
class WPCV_Finding_Key {

	/**
	 * `finding` 1件分の差分キーを計算する.
	 *
	 * 各値を「長さを前置きした文字列」に変換してから連結する(`encode()` 参照)。
	 * 単純な区切り文字(`|` 等)で連結すると、path に区切り文字と同じ文字が
	 * 含まれる場合に別々の入力から同じキーが作れてしまう(衝突)。長さを前置き
	 * すれば、どのバイト列を渡しても一意に決まり、NULL と空文字列も区別できる.
	 *
	 * @param string      $target_id      target_id.
	 * @param string      $version        version.
	 * @param string      $path           path.
	 * @param string      $status         status.
	 * @param string|null $hash_algorithm hash_algorithm(値が無ければ null).
	 * @param string|null $expected_hash  expected_hash(`added` 等では null).
	 * @param string|null $actual_hash    actual_hash(`missing`/`unreadable` 等では null).
	 * @return string sha256のhex文字列(64文字固定長. `char(64)` 列にそのまま保存できる).
	 */
	public static function compute( $target_id, $version, $path, $status, $hash_algorithm, $expected_hash, $actual_hash ) {
		$data = self::encode( $target_id )
			. self::encode( $version )
			. self::encode( $path )
			. self::encode( $status )
			. self::encode( $hash_algorithm )
			. self::encode( $expected_hash )
			. self::encode( $actual_hash );

		return hash( 'sha256', $data );
	}

	/**
	 * 1つの値を「長さを前置きした文字列」に変換する(プラン §1.4の`F(v)`).
	 *
	 * `strlen()` はバイト数を返す(マルチバイト文字数ではない)ため、UTF-8で
	 * ない(壊れた)バイト列を含む path でも安全に一意な長さを求められる.
	 *
	 * @param string|null $value 値.
	 * @return string
	 */
	private static function encode( $value ) {
		if ( null === $value ) {
			return '-';
		}

		$value = (string) $value;

		return strlen( $value ) . ':' . $value;
	}
}
