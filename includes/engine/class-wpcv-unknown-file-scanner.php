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
 *
 * v0.4.0コードレビューCR-08是正: `scan()` はディレクトリツリー全体を再帰的に
 * `walk()` した後でまとめて結果配列を組み立てる設計だったため、対象ディレクトリに
 * 大量のファイルがある場合(攻撃者が大量のダミーファイルを設置して検出を妨害する
 * ケースを含む)、chunk dispatcherの時間・メモリ予算を確認する前にtimeout/OOMする
 * 恐れがあった。`$args['budget']`(`WPCV_Chunk_Budget::exceeded()` と同じ形。
 * `max_seconds`/`memory_limit_bytes` のみ意味を持つ。`max_files` は「見つかった
 * 未知ファイル件数」ではなく「walkが訪れた全エントリ数」に対して誤って適用すると
 * 通常規模のインストールでも即座に打ち切られてしまうため、呼び出し元は渡さない
 * こと)を受け取り、`walk()` 自身が予算超過を検知したら即座に走査を打ち切って
 * `truncated: true` を返す。呼び出し元(`WPCV_Chunk_Dispatcher`)は `truncated` の
 * 場合、この不完全な結果集合を fingerprint 計算・chunk 処理には使わず、target_run を
 * 進捗を変えずに retry へ戻す責務を持つ(`WPCV_Target_Run_Repository::
 * mark_scan_incomplete()` 参照)。
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
	 * 現在時刻を秒(float。`microtime( true )` 相当)で返す callable(walkの時間予算判定に使う).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * 現在のメモリ使用量をバイト数(`memory_get_usage( true )` 相当)で返す callable.
	 *
	 * @var callable
	 */
	private $memory_usage;

	/**
	 * コンストラクタ.
	 *
	 * @param callable|null $now          省略時は `microtime( true )`.
	 * @param callable|null $memory_usage 省略時は `memory_get_usage( true )`.
	 */
	public function __construct( ?callable $now = null, ?callable $memory_usage = null ) {
		$this->now          = $now ?? static function () {
			return microtime( true );
		};
		$this->memory_usage = $memory_usage ?? static function () {
			return memory_get_usage( true );
		};
	}

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
	 *     @type array    $budget               walk自体の時間・メモリ予算(クラス
	 *                                           docblock「CR-08是正」参照。省略時は
	 *                                           無制限. `max_files` は指定しないこと).
	 * }
	 * @return array {
	 *     @type array $items      検出項目の配列。各要素は
	 *                             `array( 'path' => ABSPATH 相対パス, 'severity' => string )`.
	 *     @type bool  $truncated  walkが予算超過で完了できなかった場合 true(この
	 *                             場合 `items` は不完全な部分集合であり、呼び出し元は
	 *                             fingerprint計算・chunk処理に使ってはならない).
	 * }
	 */
	public function scan( $base_dir, array $known_files, array $args = array() ) {
		$recursive        = array_key_exists( 'recursive', $args ) ? (bool) $args['recursive'] : true;
		$php_severity     = isset( $args['php_severity'] ) ? (string) $args['php_severity'] : 'high';
		$non_php_severity = isset( $args['non_php_severity'] ) ? (string) $args['non_php_severity'] : 'high';
		$extra_excluded   = isset( $args['extra_excluded_paths'] ) ? (array) $args['extra_excluded_paths'] : array();
		$budget           = isset( $args['budget'] ) ? (array) $args['budget'] : array();

		$normalized_base = rtrim( WPCV_Path_Normalizer::to_forward_slashes( $base_dir ), '/' );

		if ( '' === $normalized_base || ! is_dir( $normalized_base ) ) {
			return array(
				'items'     => array(),
				'truncated' => false,
			);
		}

		$base_relative = self::relative_base( $normalized_base );

		$found_paths = array();
		$truncated   = false;
		$start       = call_user_func( $this->now );

		$this->walk( $normalized_base, $base_relative, $recursive, $found_paths, $start, $budget, $truncated );

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

		return array(
			'items'     => $items,
			'truncated' => $truncated,
		);
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
	 * `$budget`(`max_seconds`/`memory_limit_bytes`のみ
	 * 意味を持つ。クラスdocblock参照)を毎エントリ確認し、超過を検知したら
	 * `$truncated` を立てて即座に(再帰呼び出しも含め)走査を打ち切る。`$results`は
	 * 打ち切り時点までに見つかった分がそのまま残る(呼び出し元 `scan()` は
	 * `$truncated` が真の場合これを不完全な部分集合として扱う).
	 *
	 * @param string $absolute_dir    走査中ディレクトリの絶対パス(スラッシュ区切り済み・
	 *                                末尾スラッシュ無し).
	 * @param string $relative_prefix ここまでの ABSPATH 相対パス(末尾スラッシュ無し。
	 *                                ABSPATH 自身なら空文字).
	 * @param bool   $recursive       サブディレクトリに降りるか.
	 * @param array  $results         結果を追記する配列(参照渡し).
	 * @param float  $start           walk開始時刻(`$this->now`の戻り値).
	 * @param array  $budget          `max_seconds`/`memory_limit_bytes`/
	 *                                `memory_threshold_ratio`(いずれも省略可).
	 * @param bool   $truncated       予算超過を検知したら true にする(参照渡し).
	 * @return void
	 */
	private function walk( $absolute_dir, $relative_prefix, $recursive, array &$results, $start, array $budget, bool &$truncated ) {
		if ( $truncated ) {
			return;
		}

		// 権限エラー等で読めないディレクトリはスキップする(例外にしない。未知ファイル
		// 検出という性質上、読めないこと自体は致命的ではなく、他の対象の走査を
		// 継続すべきため).
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$entries = @scandir( $absolute_dir );

		if ( false === $entries ) {
			return;
		}

		foreach ( $entries as $entry ) {
			if ( $truncated ) {
				return;
			}

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
					$this->walk( $absolute_path, $relative_path, true, $results, $start, $budget, $truncated );
				}
			} else {
				$results[] = $relative_path;
			}

			if ( WPCV_Chunk_Budget::exceeded( $start, 0, $budget, $this->now, $this->memory_usage ) ) {
				$truncated = true;
				return;
			}
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
