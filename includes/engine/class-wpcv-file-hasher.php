<?php
/**
 * WPCV_File_Hasher クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ファイルのハッシュ算出とサイズ取得.
 *
 * 読み取り不能なファイル(パーミッション等)は例外にせず null を返す。
 * 呼び出し側(WPCV_Verifier)が findings.status = unreadable として記録する
 * 判断材料にするため、失敗は「情報」として扱う(§5.5).
 */
class WPCV_File_Hasher {

	/** ハッシュアルゴリズム: sha256. §3.4/§3.5 で優先して使う. */
	const ALGO_SHA256 = 'sha256';

	/** ハッシュアルゴリズム: md5. コア照合(§3.2)ではこちらのみが提供される. */
	const ALGO_MD5 = 'md5';

	/**
	 * ファイルのハッシュを算出する.
	 *
	 * @param string $absolute_path 絶対パス.
	 * @param string $algorithm     self::ALGO_SHA256 / self::ALGO_MD5.
	 * @return string|null 16進ハッシュ文字列. 読み取れない場合は null.
	 */
	public static function hash( $absolute_path, $algorithm = self::ALGO_SHA256 ) {
		if ( ! is_readable( $absolute_path ) || is_dir( $absolute_path ) ) {
			return null;
		}

		// is_readable() 確認後もレース条件(確認直後の削除・権限変更等)で hash_file() が
		// 失敗し得るため、その警告を抑止する。失敗は戻り値 false で判定して null にする.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$hash = @hash_file( $algorithm, $absolute_path );

		return false === $hash ? null : $hash;
	}

	/**
	 * ファイルサイズを取得する.
	 *
	 * @param string $absolute_path 絶対パス.
	 * @return int|null バイト数. 読み取れない場合は null.
	 */
	public static function size( $absolute_path ) {
		if ( ! is_readable( $absolute_path ) || is_dir( $absolute_path ) ) {
			return null;
		}

		// hash() と同様、レース条件による失敗の警告を抑止する.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$size = @filesize( $absolute_path );

		return false === $size ? null : $size;
	}
}
