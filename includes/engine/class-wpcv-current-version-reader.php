<?php
/**
 * WPCV_Current_Version_Reader クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * プラグイン・テーマ・コアの「今のディスク上の version」を読む(v0.8 §Step1. D13).
 *
 * Run の途中で自動更新が入ると、`$context`(run の開始時、または Action Scheduler の
 * action ごとに作る)に入っている version は古いままになる. `$context` を作り直しても
 * 直らない理由は、同じプロセスの中では次のキャッシュが効くため:
 *
 * - `get_plugins()` は `wp_cache_get( 'plugins', 'plugins' )` を返す. 消すのは
 *   `wp_clean_plugins_cache()` で、更新したプロセスの中でしか呼ばれない.
 * - `wp_get_themes()` は関数内の `static $_themes` に `WP_Theme` を持つ.
 * - `get_bloginfo( 'version' )` は読み込み済みのグローバル `$wp_version` を返す.
 *
 * そこで、version は処理する時点のファイルから直接読む. `get_file_data()` は
 * ファイルの先頭 8KB を毎回読むだけでキャッシュしない(`wp-includes/functions.php` で確認).
 *
 * どの読み方も「読めなかったら null」を返す. 呼び出し側は null のときだけ
 * `$context` の値に戻す(ファイルが消えたプラグイン・テーマは、その後の処理が
 * target_missing などで扱う).
 */
class WPCV_Current_Version_Reader {

	/**
	 * プラグインのメインファイルの `Version` ヘッダーを読む.
	 *
	 * `get_plugins()` を通らない. v0.6 D4(更新イベントの記録)と同じ読み方.
	 *
	 * @param string $plugin_file_abs プラグインのメインファイルの絶対パス.
	 * @return string|null ファイルが読めない、または `Version` ヘッダーが無ければ null.
	 */
	public static function plugin_version( $plugin_file_abs ) {
		return self::read_header( (string) $plugin_file_abs, 'plugin' );
	}

	/**
	 * テーマの `style.css` の `Version` ヘッダーを読む.
	 *
	 * `wp_get_themes()` の static を通らない(v0.7 D9 と同じ読み方).
	 *
	 * @param string $stylesheet_dir テーマのディレクトリの絶対パス.
	 * @return string|null `style.css` が読めない、または `Version` ヘッダーが無ければ null.
	 */
	public static function theme_version( $stylesheet_dir ) {
		return self::read_header( rtrim( (string) $stylesheet_dir, '/\\' ) . '/style.css', 'theme' );
	}

	/**
	 * ヘッダーを含む文字列(ファイルの先頭部分)から `Version` ヘッダーを読む(v0.8 §Step5. D6).
	 *
	 * Zip の中のメインファイルの先頭を、ディスクに展開せずに読むための関数. 読み方を
	 * `get_file_data()` と揃えるため、一時ファイルに書いてそれを読ませる(WordPress の
	 * ヘッダーの解釈を自前で複製しない).
	 *
	 * @param string $head    ファイルの先頭部分(8KB 程度).
	 * @param string $context `get_file_data()` の第3引数(`plugin`|`theme`).
	 * @return string|null 一時ファイルを作れない、または `Version` ヘッダーが無ければ null.
	 */
	public static function version_from_head( $head, $context ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam( 'wpcv-head' );

		if ( ! $tmp ) {
			return null;
		}

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- 一時ファイルへの書き込み(WP_Filesystem の対象外).
			file_put_contents( $tmp, (string) $head );

			return self::read_header( $tmp, (string) $context );
		} finally {
			wp_delete_file( $tmp );
		}
	}

	/**
	 * コアの version を `wp-includes/version.php` から読む.
	 *
	 * `include` しない理由は v0.6 §Step6(`.maintenance` の読み方)と同じ: PHPStan が
	 * 動的 include の変数を追えないことと、グローバルの `$wp_version` を書き換えない
	 * ことのため. 代わりに `$wp_version = '...';` の行を正規表現で取る.
	 *
	 * @return string|null ファイルが読めない、または行が見つからなければ null.
	 */
	public static function core_version() {
		$wpinc = defined( 'WPINC' ) ? (string) WPINC : 'wp-includes';
		$path  = ABSPATH . $wpinc . '/version.php';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- ローカルの version.php を読むだけ(HTTP ではない).
		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			return null;
		}

		if ( 1 !== preg_match( '/^\s*\$wp_version\s*=\s*([\'"])([^\'"]*)\1\s*;/m', $contents, $matches ) ) {
			return null;
		}

		return (string) $matches[2];
	}

	/**
	 * ファイルの `Version` ヘッダーを読む(`plugin_version()`・`theme_version()` の共通処理).
	 *
	 * @param string $file    ヘッダーを持つファイルの絶対パス.
	 * @param string $context `get_file_data()` の第3引数(`plugin`|`theme`).
	 * @return string|null 読めない、またはヘッダーが空なら null.
	 */
	private static function read_header( $file, $context ) {
		if ( '' === $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		$data = get_file_data( $file, array( 'Version' => 'Version' ), $context );

		// ヘッダーが無い(空)ときは「読めなかった」と同じ扱いにする. 呼び出し側が
		// `$context` の値に戻す. version を持たないプラグインは `$context` の値も空なので
		// 結果は変わらず、`Version` ヘッダーを後から消された場合だけ古い値を使うが、
		// 実際に起こる更新ではまず無い.
		return isset( $data['Version'] ) && '' !== $data['Version'] ? (string) $data['Version'] : null;
	}
}
