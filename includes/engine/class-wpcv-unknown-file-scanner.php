<?php
/**
 * WPCV_Unknown_File_Scanner クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * マニフェストに存在しない実在ファイルの検出(§3.3).
 *
 * マニフェスト(wp.org の checksum マニフェスト等)は「配布物に含まれるファイル」しか
 * 列挙しないため、マニフェストに無い(=配布時には存在しなかった)ファイルは別に走査
 * しないと検出できない。バックドア設置は往々にして「マニフェストに載っていない
 * ファイルの追加」という形を取るため、コア領域(wp-admin/wp-includes)・ABSPATH 直下
 * (非再帰)・MU プラグインディレクトリ配下のいずれについても、走査対象ディレクトリと
 * 「既知のパス集合」を受け取って比較する汎用エンジンとして実装する(どの既知パス
 * 集合を渡すか・どのディレクトリを渡すかは呼び出し側の責務とする).
 */
class WPCV_Unknown_File_Scanner {

	/**
	 * 走査時に常に無視するディレクトリ名(大文字小文字を区別する厳密一致).
	 *
	 * これらの配下には決して降りない(§3.3: 既定の除外).
	 *
	 * @var string[]
	 */
	const DEFAULT_EXCLUDED_DIR_NAMES = array( '.git', 'node_modules', '.well-known' );

	/**
	 * 危険度(severity)を high と判定する拡張子(§5.5 の severity 表と同じ分類).
	 *
	 * `.htaccess` / `.user.ini` はドット始まりの二重拡張子でこの一覧には乗らないため、
	 * `is_php_like_path()` 側で個別に判定する.
	 *
	 * @var string[]
	 */
	const PHP_LIKE_EXTENSIONS = array( 'php', 'phtml', 'phar', 'php5', 'php7', 'inc' );

	/**
	 * 指定ディレクトリ配下を走査し、$known_files に無い実在ファイルを検出する.
	 *
	 * @param string $base_dir    走査対象ディレクトリの絶対パス.
	 * @param array  $known_files 既知のパス集合。キーが ABSPATH 相対パス(値は使わない。
	 *                            照合ソースが返す `files` をそのまま渡せる).
	 * @param array  $args {
	 *     省略可能なオプション.
	 *
	 *     @type bool     $recursive            サブディレクトリに再帰するか. 既定 true
	 *                                           (ABSPATH 直下の走査では false を渡す。
	 *                                           §3.3: 直下・非再帰).
	 *     @type string   $php_severity         §5.5 の high 相当拡張子に付与する
	 *                                           severity. 既定 'high'.
	 *     @type string   $non_php_severity     それ以外の拡張子に付与する severity. 既定
	 *                                           'high'(wp-admin/wp-includes は拡張子を
	 *                                           問わず high。ABSPATH 直下の呼び出し側は
	 *                                           'medium' を渡す).
	 *     @type string[] $extra_excluded_paths 既定除外に加えて無視する ABSPATH 相対
	 *                                           パスの一覧(例: ABSPATH 直下の
	 *                                           `.htaccess` / `wp-config.php`).
	 * }
	 * @return array 検出項目の配列。各要素は
	 *               `array( 'path' => ABSPATH 相対パス, 'severity' => string )`.
	 */
	public function scan( $base_dir, array $known_files, array $args = array() ) {
		$recursive        = array_key_exists( 'recursive', $args ) ? (bool) $args['recursive'] : true;
		$php_severity     = isset( $args['php_severity'] ) ? (string) $args['php_severity'] : 'high';
		$non_php_severity = isset( $args['non_php_severity'] ) ? (string) $args['non_php_severity'] : 'high';
		$extra_excluded   = isset( $args['extra_excluded_paths'] ) ? (array) $args['extra_excluded_paths'] : array();

		$normalized_base = rtrim( WPCV_Path_Normalizer::to_forward_slashes( $base_dir ), '/' );

		if ( '' === $normalized_base || ! is_dir( $normalized_base ) ) {
			return array();
		}

		$base_relative = self::relative_base( $normalized_base );

		$found_paths = array();
		self::walk( $normalized_base, $base_relative, $recursive, $found_paths );

		$items = array();

		foreach ( $found_paths as $relative_path ) {
			if ( array_key_exists( $relative_path, $known_files ) ) {
				continue;
			}

			if ( in_array( $relative_path, $extra_excluded, true ) ) {
				continue;
			}

			$items[] = array(
				'path'     => $relative_path,
				'severity' => self::is_php_like_path( $relative_path ) ? $php_severity : $non_php_severity,
			);
		}

		return $items;
	}

	/**
	 * 走査開始ディレクトリの ABSPATH 相対パスを求める.
	 *
	 * `WPCV_Path_Normalizer::to_relative()` は「$base と完全に一致する(=相対パスが
	 * 空文字になる)」ケースを想定しておらず、末尾スラッシュの有無の違いで範囲外
	 * (元のパスをそのまま返す)と誤判定されるため、ABSPATH 自身が渡された場合だけ
	 * ここで明示的に空文字を返す.
	 *
	 * @param string $normalized_base 末尾スラッシュを除去済みの絶対パス.
	 * @return string ABSPATH 相対パス(末尾スラッシュ無し。ABSPATH 自身なら空文字).
	 */
	private static function relative_base( $normalized_base ) {
		$abspath_trimmed = rtrim( WPCV_Path_Normalizer::to_forward_slashes( ABSPATH ), '/' );

		if ( $normalized_base === $abspath_trimmed ) {
			return '';
		}

		return WPCV_Path_Normalizer::to_relative( $normalized_base );
	}

	/**
	 * ディレクトリを走査し、見つかったファイルの ABSPATH 相対パスを $results に集める.
	 *
	 * @param string $absolute_dir    走査中ディレクトリの絶対パス(スラッシュ区切り済み・
	 *                                末尾スラッシュ無し).
	 * @param string $relative_prefix ここまでの ABSPATH 相対パス(末尾スラッシュ無し。
	 *                                ABSPATH 自身なら空文字).
	 * @param bool   $recursive       サブディレクトリに降りるか.
	 * @param array  $results         結果を追記する配列(参照渡し).
	 * @return void
	 */
	private static function walk( $absolute_dir, $relative_prefix, $recursive, array &$results ) {
		// 権限エラー等で読めないディレクトリはスキップする(例外にしない。未知ファイル
		// 検出という性質上、読めないこと自体は致命的ではなく、他の対象の走査を
		// 継続すべきため).
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$entries = @scandir( $absolute_dir );

		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			if ( in_array( $entry, self::DEFAULT_EXCLUDED_DIR_NAMES, true ) ) {
				continue;
			}

			$absolute_path = $absolute_dir . '/' . $entry;
			$relative_path = '' === $relative_prefix ? $entry : $relative_prefix . '/' . $entry;

			if ( is_dir( $absolute_path ) ) {
				if ( $recursive ) {
					self::walk( $absolute_path, $relative_path, true, $results );
				}
				continue;
			}

			$results[] = $relative_path;
		}
	}

	/**
	 * パスが high severity 相当の拡張子かどうかを判定する(§5.5 の severity 表と同じ分類).
	 *
	 * @param string $relative_path 判定対象の相対パス.
	 * @return bool
	 */
	private static function is_php_like_path( $relative_path ) {
		$basename = strtolower( basename( $relative_path ) );

		if ( '.htaccess' === $basename || '.user.ini' === $basename ) {
			return true;
		}

		$extension = strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) );

		return in_array( $extension, self::PHP_LIKE_EXTENSIONS, true );
	}
}
