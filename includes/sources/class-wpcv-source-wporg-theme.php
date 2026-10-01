<?php
/**
 * WPCV_Source_Wporg_Theme クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 公式テーマ(WordPress.org)のマニフェスト取得(v0.7プラン §3.2・D1〜D3・D6).
 *
 * WordPress.org のテーマには checksum API が無い(§1.1)ため、
 * `https://downloads.wordpress.org/theme/{slug}.{version}.zip` を直接取得し、
 * zip の中のファイルごとに sha256 と md5 を計算してマニフェストを作る(D1).
 * `themes_api()` は呼ばない(存在しないテーマ・version は zip の URL が 404 を返す.
 * §2.1 で実測).
 *
 * zip はディスクに展開しない(D2). `ZipArchive::getStream()` 等で読みながら
 * ハッシュを取るので、zip slip(展開先の外への書き込み)は起こりえない. ただし
 * コアの `unzip_file()` が持つ保護(`validate_file()`・`__MACOSX` の除外など)も
 * 効かないため、エントリの検査は自前で行う(§3.2 の 7. WPMAR の
 * `WPMAR_PDF_Installer::validate_entry_name()` / `entry_is_symlink()` を移植し、
 * ルートの検査・制御文字・エントリ数・サイズ・圧縮率の上限を足した).
 * 検査で1つでも落ちたエントリがあれば、マニフェストは何も返さない
 * (`archive_rejected`).
 *
 * 作ったマニフェストは `WPCV_Manifest_Cache_Repository` に保存し、2回目以降
 * (次の run・同じ run の次の chunk)はそこから返す(`manifest_status = cached`).
 * キャッシュには md5 も保存する(D3). 返すマニフェストは通常 sha256 だけだが、
 * コア同梱テーマ(今のコアのマニフェストに `wp-content/themes/{slug}/` がある
 * テーマ)では、zip とコアの md5 のどちらかと一致すれば正とする(D7. v0.7 Step4.
 * `to_manifest_files()` 参照).
 *
 * 次の場合は HTTP を出さずに `missing` を返す(D6):
 * - version が空 → `version_unknown`
 * - stylesheet に `/` を含む(入れ子のテーマ. WordPress.org の slug は平坦) → `unknown_source`
 * - `Update URI` が空でなく、そのホストが `wordpress.org` / `w.org` 以外 → `unknown_source`(U6)
 */
class WPCV_Source_Wporg_Theme implements WPCV_Manifest_Source {

	/**
	 * ZIP の URL テンプレート. `{slug}.{version}.zip` を埋め込む.
	 */
	const ZIP_URL_TEMPLATE = 'https://downloads.wordpress.org/theme/%s.%s.zip';

	/**
	 * ZIP 取得のタイムアウト秒数の既定値(`wpcv_theme_zip_download_timeout` で変えられる).
	 *
	 * 実測(2026-10-01. `download_url()` で wp.org のテーマ24件. 最大は twentytwentyfive
	 * 7.8MB): エックスサーバー(共有ホスティング)で 1.37〜2.06 秒を2回(合計 40.9 秒)、
	 * ローカル開発環境で 0.57〜4.56 秒. 30秒はエックスサーバーの最大の約15倍、全計測の
	 * 最大の約6.5倍(2026-10-01 ユーザー確認済み. v0.7 Step8 で暫定から確定にした).
	 *
	 * 短くしすぎると、回線が一時的に遅いだけで `http_error` になり、その run では
	 * テーマが照合されない. `http_error` は「連続 unverifiable」のアラートの数にも入る
	 * (`WPCV_Generation_Differ::is_unverifiable_streak_member()`). 長くしても、取得が
	 * 遅いときにその run が少し長くなるだけ. ただし取得中もワーカーは target の lease を
	 * 持っているので、`WPCV_Target_Run_Repository::DEFAULT_LEASE_SECONDS`(120秒)より
	 * 十分短くしておく必要がある.
	 */
	const DEFAULT_DOWNLOAD_TIMEOUT = 30;

	/**
	 * 取得した zip のサイズの上限(バイト. `wpcv_theme_zip_max_archive_bytes`).
	 *
	 * 暫定値(rev.3 §7.3. 2026-10-01 ユーザー確認済み). 実測の最大は 7.8MB
	 * (twentytwentyfive)、人気テーマでも 6.4MB 程度(プラン §2.2).
	 */
	const DEFAULT_MAX_ARCHIVE_BYTES = 104857600;

	/**
	 * ZIP のエントリ数の上限(`wpcv_theme_zip_max_entries`).
	 *
	 * 暫定値(rev.3 §7.3). 実測の最大は 257(プラン §2.2).
	 */
	const DEFAULT_MAX_ENTRIES = 20000;

	/**
	 * 1エントリの展開後のサイズの上限(バイト. `wpcv_theme_zip_max_entry_bytes`).
	 *
	 * 暫定値(rev.3 §7.3). 実測では1テーマの合計でも最大 8.2MB(プラン §2.2).
	 */
	const DEFAULT_MAX_ENTRY_BYTES = 52428800;

	/**
	 * 展開後の合計サイズの上限(バイト. `wpcv_theme_zip_max_total_bytes`).
	 *
	 * 暫定値(rev.3 §7.3). 実測の最大は 8.2MB(プラン §2.2).
	 */
	const DEFAULT_MAX_TOTAL_BYTES = 524288000;

	/**
	 * 1エントリの圧縮率(展開後 ÷ 圧縮後)の上限(`wpcv_theme_zip_max_compression_ratio`).
	 *
	 * 暫定値(rev.3 §7.3). 実測の最大は 18.7倍(twentyfifteen. プラン §2.2).
	 */
	const DEFAULT_MAX_COMPRESSION_RATIO = 100;

	/**
	 * `Update URI` のホストがこれらのどれかなら WordPress.org のテーマとみなす(D6).
	 *
	 * WordPress.org 側がどのホストを自分のものとみなすかは、コアのソースからは
	 * 確かめられない(コアはホスト名を `update_themes_{$hostname}` フィルターに回す
	 * だけ. `wp-includes/update.php:789-795`)。未確証(プラン §9-3).
	 *
	 * @var string[]
	 */
	const WPORG_UPDATE_URI_HOSTS = array( 'wordpress.org', 'w.org' );

	/**
	 * マニフェストのキャッシュ.
	 *
	 * @var WPCV_Manifest_Cache_Repository
	 */
	private $cache;

	/**
	 * ZIP を一時ファイルへ取得する callable. `( string $url, int $timeout ): string|WP_Error`.
	 * 戻り値は一時ファイルのパス(呼び出し側が消す)。`download_url()` と同じ契約.
	 *
	 * @var callable
	 */
	private $downloader;

	/**
	 * `ZipArchive` が使えるかを返す callable(テストで「無い環境」を再現するため).
	 *
	 * @var callable
	 */
	private $zip_available;

	/**
	 * コアのマニフェストの取得ソース(D7. v0.7 §Step4). `null` ならコア同梱テーマの
	 * 合成をしない(zip の sha256 だけで照合する).
	 *
	 * @var WPCV_Manifest_Source|null
	 */
	private $core_source;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Manifest_Cache_Repository $cache         マニフェストのキャッシュ.
	 * @param callable|null                  $downloader    zip の取得. 省略時は `download_url()`
	 *                                                      (`wp-admin/includes/file.php`. cron・
	 *                                                      CLI では読み込まれていないので、ここで読む).
	 * @param callable|null                  $zip_available `ZipArchive` が使えるかを返す. 省略時は
	 *                                                      `class_exists( 'ZipArchive' )`.
	 * @param WPCV_Manifest_Source|null      $core_source   コアのマニフェストの取得ソース(D7.
	 *                                                      `WPCV_Source_Core`). 省略時は合成しない.
	 */
	public function __construct( WPCV_Manifest_Cache_Repository $cache, ?callable $downloader = null, ?callable $zip_available = null, ?WPCV_Manifest_Source $core_source = null ) {
		$this->cache         = $cache;
		$this->core_source   = $core_source;
		$this->downloader    = $downloader ?? static function ( $url, $timeout ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			return download_url( $url, $timeout );
		};
		$this->zip_available = $zip_available ?? static function () {
			return class_exists( 'ZipArchive' );
		};
	}

	/**
	 * 公式テーマのマニフェストを取得する.
	 *
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string $slug       テーマの stylesheet(`WP_Theme::get_stylesheet()`). 必須.
	 *     @type string $version    ローカルのテーマの version(`style.css` のヘッダー値)。
	 *                              空なら HTTP を出さず `version_unknown` を返す.
	 *     @type string $update_uri   `style.css` の `Update URI` ヘッダー値(空でよい). D6 参照.
	 *     @type string $core_version WordPress の version(D7. コアのマニフェストを引くのに使う.
	 *                                空ならコア同梱テーマの合成をしない).
	 * }
	 * @return array インターフェースの docblock を参照.
	 *
	 * @throws InvalidArgumentException 必須の slug が指定されていない場合(呼び出し側の実装ミス.
	 *                                   `WPCV_Source_Wporg_Plugin::get_manifest()` と同じ扱い).
	 */
	public function get_manifest( array $context ) {
		if ( empty( $context['slug'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Source_Wporg_Theme::get_manifest() requires $context[\'slug\'].' ) );
		}

		$slug       = (string) $context['slug'];
		$version    = isset( $context['version'] ) ? (string) $context['version'] : '';
		$update_uri = isset( $context['update_uri'] ) ? (string) $context['update_uri'] : '';

		$core_version = isset( $context['core_version'] ) ? (string) $context['core_version'] : '';

		// D6 ①. version はバージョンを推測しない(プラグインと同じ方針. §3.4).
		if ( '' === $version ) {
			return self::missing( WPCV_Error_Code::VERSION_UNKNOWN );
		}

		// D6 ②. `search_theme_directories()` は1段深いテーマを `dir/sub` という
		// stylesheet にする(`wp-includes/theme.php:539-556`)。WordPress.org の slug は平坦.
		if ( false !== strpos( $slug, '/' ) ) {
			return self::missing( WPCV_Error_Code::UNKNOWN_SOURCE );
		}

		// D6 ③(U6). 同じ slug の別物のテーマを WordPress.org の zip と照合しない.
		if ( '' !== trim( $update_uri ) && ! self::is_wporg_update_uri( $update_uri ) ) {
			return self::missing( WPCV_Error_Code::UNKNOWN_SOURCE );
		}

		$cached = $this->cache->find( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, $slug, $version );

		if ( null !== $cached ) {
			return array(
				'manifest_status' => 'cached',
				'error_code'      => null,
				'files'           => $this->to_manifest_files( $cached['files'], $slug, $core_version ),
			);
		}

		if ( ! call_user_func( $this->zip_available ) ) {
			return self::missing( WPCV_Error_Code::ZIPARCHIVE_MISSING );
		}

		$url     = sprintf( self::ZIP_URL_TEMPLATE, rawurlencode( $slug ), rawurlencode( $version ) );
		$timeout = max( 1, (int) apply_filters( 'wpcv_theme_zip_download_timeout', self::DEFAULT_DOWNLOAD_TIMEOUT, $slug ) );
		$tmp     = call_user_func( $this->downloader, $url, $timeout );

		if ( is_wp_error( $tmp ) ) {
			return self::missing( self::is_not_found_error( $tmp ) ? WPCV_Error_Code::MANIFEST_NOT_FOUND : WPCV_Error_Code::HTTP_ERROR );
		}

		$tmp = (string) $tmp;

		try {
			$archive_bytes = (int) filesize( $tmp );

			if ( $archive_bytes > (int) apply_filters( 'wpcv_theme_zip_max_archive_bytes', self::DEFAULT_MAX_ARCHIVE_BYTES, $slug ) ) {
				return self::missing( WPCV_Error_Code::ARCHIVE_REJECTED );
			}

			$result = $this->hash_archive( $tmp, $slug );

			if ( is_string( $result ) ) {
				return self::missing( $result );
			}

			// 保存できなくても(slug・version が列の長さを超える場合)、今回の照合は続けられる.
			$this->cache->save( WPCV_Manifest_Cache_Repository::SOURCE_WPORG_THEME, $slug, $version, $result, $archive_bytes );

			return array(
				'manifest_status' => 'ok',
				'error_code'      => null,
				'files'           => $this->to_manifest_files( $result, $slug, $core_version ),
			);
		} finally {
			// `download_url()` の一時ファイルは呼び出し側が消す(`file.php:1161` の WARNING).
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * ZIP を開き、全エントリを検査してからハッシュを取る(§3.2 の 6〜8.
	 * 実体は v0.8 §Step3 で `WPCV_Zip_Manifest_Reader` に切り出した).
	 *
	 * @param string $path zip のパス.
	 * @param string $slug テーマの stylesheet(zip のルートディレクトリ名と一致するはず).
	 * @return array<string, array{sha256: string, md5: string}>|string 成功したら
	 *         テーマ内の相対パスをキーにしたハッシュの配列. 失敗したら `WPCV_Error_Code` の値.
	 */
	private function hash_archive( $path, $slug ) {
		$limits = array(
			'entries'     => (int) apply_filters( 'wpcv_theme_zip_max_entries', self::DEFAULT_MAX_ENTRIES, $slug ),
			'entry_bytes' => (int) apply_filters( 'wpcv_theme_zip_max_entry_bytes', self::DEFAULT_MAX_ENTRY_BYTES, $slug ),
			'total_bytes' => (int) apply_filters( 'wpcv_theme_zip_max_total_bytes', self::DEFAULT_MAX_TOTAL_BYTES, $slug ),
			'ratio'       => (float) apply_filters( 'wpcv_theme_zip_max_compression_ratio', self::DEFAULT_MAX_COMPRESSION_RATIO, $slug ),
		);

		return WPCV_Zip_Manifest_Reader::read( $path, $slug, $limits );
	}

	/**
	 * キャッシュの形(`{ path: { sha256, md5 } }`)を、インターフェースの `files` の形に変える.
	 *
	 * 通常は sha256 だけを返す(D3). 今のコアのマニフェストにこのテーマのファイル
	 * (`wp-content/themes/{slug}/`)が含まれていれば(コア同梱テーマ. D7)、ファイルごとに
	 * 「zip の md5」と「コアのマニフェストの md5」のどちらかと一致すれば正とする
	 * (`algorithm = md5`、`hashes` に2つの候補). コアに同梱された版は、WordPress.org の
	 * 同じ version の zip と中身が違うことがあるため(7.1.2 の twentytwentyfive 1.5 で
	 * 3ファイル. プラン §2.3). 逆に、テーマだけを WordPress.org から更新した場合は
	 * zip の md5 と一致する.
	 *
	 * 正解のパスの集合は zip のパスにする. コアにだけあるファイルは欠落として出さない
	 * (コアの照合でも `wp-content/` 配下の欠落は外している. v0.5 U2).
	 *
	 * コアのマニフェストを取得できなかった場合は、zip の sha256 だけで照合する
	 * (コア同梱テーマでは、コア同梱版との差が `modified` として出る). コアの
	 * マニフェストもキャッシュされる(`WPCV_Source_Core`)ので、一度取得できれば
	 * 以後はこの状態にならない.
	 *
	 * @param array  $cached_files キャッシュの `files`.
	 * @param string $slug         テーマの stylesheet.
	 * @param string $core_version WordPress の version(空なら合成しない).
	 * @return array インターフェースの docblock にある `files` の形式.
	 */
	private function to_manifest_files( array $cached_files, $slug, $core_version ) {
		$core_md5 = $this->core_bundled_md5( $slug, $core_version );
		$files    = array();

		foreach ( $cached_files as $path => $hashes ) {
			if ( ! is_array( $hashes ) || empty( $hashes['sha256'] ) ) {
				continue;
			}

			$path = (string) $path;

			if ( array() === $core_md5 ) {
				$files[ $path ] = array(
					'algorithm' => WPCV_File_Hasher::ALGO_SHA256,
					'hashes'    => array( (string) $hashes['sha256'] ),
				);
				continue;
			}

			$candidates = array( (string) $hashes['md5'] );

			if ( isset( $core_md5[ $path ] ) && $core_md5[ $path ] !== $candidates[0] ) {
				$candidates[] = $core_md5[ $path ];
			}

			$files[ $path ] = array(
				'algorithm' => WPCV_File_Hasher::ALGO_MD5,
				'hashes'    => $candidates,
			);
		}

		return $files;
	}

	/**
	 * 今のコアのマニフェストから、このテーマのファイルの md5 を取り出す(D7).
	 *
	 * @param string $slug         テーマの stylesheet.
	 * @param string $core_version WordPress の version.
	 * @return array<string, string> テーマ内の相対パス => md5. コア同梱テーマでなければ空.
	 */
	private function core_bundled_md5( $slug, $core_version ) {
		if ( null === $this->core_source || '' === $core_version ) {
			return array();
		}

		$core = $this->core_source->get_manifest( array( 'version' => $core_version ) );

		if ( null !== $core['error_code'] ) {
			return array();
		}

		$prefix = 'wp-content/themes/' . $slug . '/';
		$md5    = array();

		foreach ( $core['files'] as $path => $spec ) {
			if ( 0 !== strpos( (string) $path, $prefix ) || WPCV_File_Hasher::ALGO_MD5 !== $spec['algorithm'] ) {
				continue;
			}

			$hashes = (array) $spec['hashes'];

			if ( isset( $hashes[0] ) ) {
				$md5[ substr( (string) $path, strlen( $prefix ) ) ] = (string) $hashes[0];
			}
		}

		return $md5;
	}

	/**
	 * `Update URI` が WordPress.org を指すかどうか(D6 ③).
	 *
	 * コアと同じく、URI のホスト名で判断する(`wp-includes/update.php:795` は
	 * `wp_parse_url( sanitize_url( $uri ), PHP_URL_HOST )`). `sanitize_url()` は
	 * スキームの無い値に `http://` を付けるので、ここでも同じようにしてから
	 * ホスト名を取る(`false` のような値はホスト名 `false` になり、WordPress.org
	 * ではないと判断される).
	 *
	 * @param string $update_uri `Update URI` ヘッダー値(空でないもの).
	 * @return bool
	 */
	private static function is_wporg_update_uri( $update_uri ) {
		$uri = trim( $update_uri );

		if ( false === strpos( $uri, '://' ) ) {
			$uri = 'http://' . ltrim( $uri, '/' );
		}

		$host = wp_parse_url( $uri, PHP_URL_HOST );

		return is_string( $host ) && in_array( strtolower( $host ), self::WPORG_UPDATE_URI_HOSTS, true );
	}

	/**
	 * `download_url()` の失敗が「zip が無い(404)」かどうか.
	 *
	 * `download_url()` は 200 以外の応答を、ステータスに関わらずすべて
	 * `http_404` というコードの `WP_Error` で返し、実際のステータスは
	 * `$data['code']` に入れる(`wp-admin/includes/file.php:1193-1220`. 7.1.2 で確認).
	 * そのためコードではなく `$data['code']` で見分ける. 500 番台などは一時的な
	 * 障害として `http_error` に回す.
	 *
	 * @param WP_Error $error `download_url()` が返したエラー.
	 * @return bool
	 */
	private static function is_not_found_error( $error ) {
		$data = $error->get_error_data();

		return 'http_404' === $error->get_error_code() && is_array( $data ) && isset( $data['code'] ) && 404 === (int) $data['code'];
	}

	/**
	 * マニフェストが無いときの戻り値.
	 *
	 * @param string $error_code `WPCV_Error_Code` の値.
	 * @return array
	 */
	private static function missing( $error_code ) {
		return array(
			'manifest_status' => 'missing',
			'error_code'      => $error_code,
			'files'           => array(),
		);
	}
}
