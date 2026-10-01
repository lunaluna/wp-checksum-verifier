<?php
/**
 * WPCV_Static_Target_Resolver クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 本体targetを持たない合成target(`core:_config`・`dropin:_stat`. v0.6 §Step9.
 * プラン§5.3 L1)が走査すべき絶対パスの一覧を求める。DBには一切触れない.
 *
 * `core:_config`は`wp-config.php`(ABSPATH直下、無ければ1つ上の階層。WordPress
 * コアの`wp-load.php`と同じ判定)・`.htaccess`・`.user.ini`のうち実在するものを
 * 返す。`dropin:_stat`は`_get_dropins()`(`wp-admin/includes/plugin.php`)の
 * 一覧のうち`WP_CONTENT_DIR`直下に実在するものを返す(マルチサイトなら
 * `_get_dropins()`自身がsunrise.php等を追加で返す).
 *
 * どちらも「実在するものだけ」を返す設計 ―― 存在しないファイルまで
 * target_run内のitemsに含めると、`added`/`missing`の判定(v0.5のstatの仕組み)が
 * 崩れるため.
 */
class WPCV_Static_Target_Resolver {

	/**
	 * `core:_config`が走査すべき絶対パスの一覧を返す(実在するもののみ).
	 *
	 * @param string|null $abspath 省略時は`ABSPATH`(テストでファイルシステムを
	 *                             差し替えられるようにするための引数).
	 * @return string[]
	 */
	public static function config_file_paths( $abspath = null ) {
		$abspath = rtrim( null === $abspath ? ABSPATH : $abspath, '/' ) . '/';
		$paths   = array();

		$wp_config_path = self::resolve_wp_config_path( $abspath );

		if ( null !== $wp_config_path ) {
			$paths[] = $wp_config_path;
		}

		foreach ( array( '.htaccess', '.user.ini' ) as $filename ) {
			$candidate = $abspath . $filename;

			if ( file_exists( $candidate ) ) {
				$paths[] = $candidate;
			}
		}

		return $paths;
	}

	/**
	 * `wp-config.php`の場所を、コアの`wp-load.php`(L47-55)と同じ判定で求める.
	 *
	 * ABSPATH直下に無ければ1つ上の階層を見るが、その階層に`wp-settings.php`が
	 * 存在する場合は「別のWordPressインストールの一部」とみなし対象外にする
	 * (コアと同じ判定条件).
	 *
	 * @param string $abspath 末尾スラッシュ付きのABSPATH.
	 * @return string|null 見つからなければ`null`.
	 */
	private static function resolve_wp_config_path( $abspath ) {
		if ( file_exists( $abspath . 'wp-config.php' ) ) {
			return $abspath . 'wp-config.php';
		}

		$parent = dirname( rtrim( $abspath, '/' ) ) . '/';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- コアのwp-load.phpも@file_exists()で同じ判定をしている(親ディレクトリが辿れない環境での警告抑止.クラスdocblock参照).
		if ( @file_exists( $parent . 'wp-config.php' ) && ! @file_exists( $parent . 'wp-settings.php' ) ) {
			return $parent . 'wp-config.php';
		}

		return null;
	}

	/**
	 * 走査対象の絶対パスから、`wpcv_file_states.path`・`findings.path`に保存する
	 * ABSPATH相対のパスを求める.
	 *
	 * ABSPATH配下なら通常どおり相対パスにする。ABSPATHの1つ上の階層にある
	 * `wp-config.php`(`resolve_wp_config_path()`が返し得る唯一のABSPATH外の
	 * パス)は、ABSPATH直下にある場合と同じ`wp-config.php`として扱う.
	 *
	 * こうしないと`WPCV_Path_Normalizer::to_relative()`が範囲外のパスをそのまま
	 * 返すため、サーバーの絶対パスがDB・画面・アラートメールに出てしまう
	 * (アラートの`clean_path()`はABSPATHで始まるパスしか直さない)。あわせて、
	 * 内容ハッシュの計算が`ABSPATH . path`で組み立てたありえないパスを読みに行き
	 * 常にnullになる不具合もあった(v0.6 §Step12でエックスサーバーの実地検証中に
	 * 発見。エックスサーバーは標準でこの配置になる).
	 *
	 * 攻撃者がABSPATH直下に別の`wp-config.php`を置いた場合、WordPressはそちらを
	 * 読むようになる(`wp-load.php`)。このときも同じ`wp-config.php`のまま内容が
	 * 変わるので、`modified`として検知される.
	 *
	 * @param string      $absolute_path 走査対象の絶対パス.
	 * @param string|null $abspath       省略時は`ABSPATH`(テスト用の引数).
	 * @return string ABSPATH相対のパス. どちらにも当たらなければ
	 *                `to_relative()`の結果(範囲外ならそのままのパス)を返す.
	 */
	public static function stored_path_for( $absolute_path, $abspath = null ) {
		$abspath       = rtrim( WPCV_Path_Normalizer::to_forward_slashes( null === $abspath ? ABSPATH : $abspath ), '/' ) . '/';
		$absolute_path = WPCV_Path_Normalizer::to_forward_slashes( $absolute_path );
		$parent_config = dirname( rtrim( $abspath, '/' ) ) . '/wp-config.php';

		if ( $absolute_path === $parent_config ) {
			return 'wp-config.php';
		}

		return WPCV_Path_Normalizer::to_relative( $absolute_path, $abspath );
	}

	/**
	 * `dropin:_stat`が走査すべき絶対パスの一覧を返す(実在するもののみ).
	 *
	 * @param string|null $wp_content_dir 省略時は`WP_CONTENT_DIR`(テストで
	 *                                    ファイルシステムを差し替えられるように
	 *                                    するための引数).
	 * @return string[]
	 */
	public static function dropin_paths( $wp_content_dir = null ) {
		if ( ! function_exists( '_get_dropins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$wp_content_dir = rtrim( null === $wp_content_dir ? WP_CONTENT_DIR : $wp_content_dir, '/' );
		$paths          = array();

		foreach ( array_keys( _get_dropins() ) as $filename ) {
			$candidate = $wp_content_dir . '/' . $filename;

			if ( file_exists( $candidate ) ) {
				$paths[] = $candidate;
			}
		}

		return $paths;
	}
}
