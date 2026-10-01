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
 * キャッシュには md5 も保存する(D3. コア同梱テーマとの合成〔D7・v0.7 Step4〕で
 * 使う). 返すマニフェストは sha256 だけ.
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
	 * 未実測(暫定値. 2026-10-01 ユーザー確認済み). ローカル開発環境の実測は1テーマ
	 * 最大 2.38 秒(7.8MB. プラン §2.2). 共有ホスティングでの取得時間は測っていない
	 * ため、v0.7 Step8 でエックスサーバーで測ってから決め直す. 取得中もワーカーは
	 * target の lease を持っているので、`WPCV_Target_Run_Repository::DEFAULT_LEASE_SECONDS`
	 * (120秒)より十分短くしておく必要がある.
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
	 * ハッシュを取るときに1回で読むバイト数.
	 */
	const READ_CHUNK_BYTES = 65536;

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
	 * コンストラクタ.
	 *
	 * @param WPCV_Manifest_Cache_Repository $cache         マニフェストのキャッシュ.
	 * @param callable|null                  $downloader    zip の取得. 省略時は `download_url()`
	 *                                                      (`wp-admin/includes/file.php`. cron・
	 *                                                      CLI では読み込まれていないので、ここで読む).
	 * @param callable|null                  $zip_available `ZipArchive` が使えるかを返す. 省略時は
	 *                                                      `class_exists( 'ZipArchive' )`.
	 */
	public function __construct( WPCV_Manifest_Cache_Repository $cache, ?callable $downloader = null, ?callable $zip_available = null ) {
		$this->cache         = $cache;
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
	 *     @type string $update_uri `style.css` の `Update URI` ヘッダー値(空でよい). D6 参照.
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
				'files'           => self::to_manifest_files( $cached['files'] ),
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
				'files'           => self::to_manifest_files( $result ),
			);
		} finally {
			// `download_url()` の一時ファイルは呼び出し側が消す(`file.php:1161` の WARNING).
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * ZIP を開き、全エントリを検査してからハッシュを取る(§3.2 の 6〜8).
	 *
	 * @param string $path zip のパス.
	 * @param string $slug テーマの stylesheet(zip のルートディレクトリ名と一致するはず).
	 * @return array<string, array{sha256: string, md5: string}>|string 成功したら
	 *         テーマ内の相対パスをキーにしたハッシュの配列. 失敗したら `WPCV_Error_Code` の値.
	 */
	private function hash_archive( $path, $slug ) {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $path, ZipArchive::CHECKCONS ) ) {
			return WPCV_Error_Code::ARCHIVE_INVALID;
		}

		try {
			$limits = array(
				'entries'     => (int) apply_filters( 'wpcv_theme_zip_max_entries', self::DEFAULT_MAX_ENTRIES, $slug ),
				'entry_bytes' => (int) apply_filters( 'wpcv_theme_zip_max_entry_bytes', self::DEFAULT_MAX_ENTRY_BYTES, $slug ),
				'total_bytes' => (int) apply_filters( 'wpcv_theme_zip_max_total_bytes', self::DEFAULT_MAX_TOTAL_BYTES, $slug ),
				'ratio'       => (float) apply_filters( 'wpcv_theme_zip_max_compression_ratio', self::DEFAULT_MAX_COMPRESSION_RATIO, $slug ),
			);

			$entries = self::inspect_entries( $zip, $slug, $limits );

			if ( null === $entries ) {
				return WPCV_Error_Code::ARCHIVE_REJECTED;
			}

			return self::hash_entries( $zip, $entries, $limits );
		} finally {
			$zip->close();
		}
	}

	/**
	 * 全エントリを `statIndex()` で検査し、ハッシュを取るファイルの一覧を返す
	 * (§3.2 の 7. 何も読まないうちに全部を検査する).
	 *
	 * @param ZipArchive $zip    開いた zip.
	 * @param string     $slug   テーマの stylesheet.
	 * @param array      $limits 上限(`hash_archive()` 参照).
	 * @return array<string, int>|null テーマ内の相対パス => エントリの index. 1つでも
	 *                                 検査に落ちたら null.
	 */
	private static function inspect_entries( ZipArchive $zip, $slug, array $limits ) {
		if ( $zip->count() > $limits['entries'] ) {
			return null;
		}

		$entries     = array();
		$total_bytes = 0;

		$count = $zip->count();

		for ( $index = 0; $index < $count; $index++ ) {
			// 名前の検査は元のバイト列で行う. 既定の読み方(エンコードの推測)では、
			// UTF-8 の印が無い名前の制御文字が CP437 の別の文字(`\x01` → `☺`)に
			// 置き換わり、検査をすり抜けるため(2026-10-01 に実際の zip で確認).
			$raw  = $zip->statIndex( $index, ZipArchive::FL_ENC_RAW );
			$stat = $zip->statIndex( $index );

			if ( false === $raw || false === $stat ) {
				return null;
			}

			$relative = self::validate_entry_name( (string) $raw['name'], (string) $stat['name'], $slug );

			if ( false === $relative ) {
				return null;
			}

			// `__MACOSX/`(macOS の Finder が作る付随ファイル)はテーマの一部ではない.
			if ( null === $relative ) {
				continue;
			}

			if ( self::entry_is_symlink( $zip, $index ) ) {
				return null;
			}

			// ディレクトリのエントリは数えるだけで読まない(エントリ数の上限には入る).
			if ( '/' === substr( (string) $stat['name'], -1 ) ) {
				continue;
			}

			$size      = (int) $stat['size'];
			$comp_size = (int) $stat['comp_size'];

			if ( $size > $limits['entry_bytes'] ) {
				return null;
			}

			$total_bytes += $size;

			if ( $total_bytes > $limits['total_bytes'] ) {
				return null;
			}

			// 圧縮後が0バイトで展開後が1バイト以上なら、圧縮率は無限大とみなす.
			if ( $size > 0 && ( 0 === $comp_size || $size / $comp_size > $limits['ratio'] ) ) {
				return null;
			}

			// 同じパスのエントリが2つあると、どちらがローカルのファイルの正解か決まらない.
			if ( isset( $entries[ $relative ] ) ) {
				return null;
			}

			$entries[ $relative ] = $index;
		}

		return $entries;
	}

	/**
	 * エントリの名前を検査し、テーマ内の相対パスを返す(WPMAR の
	 * `WPMAR_PDF_Installer::validate_entry_name()` を移植. トップレベルの許可リストは
	 * 「最初のセグメントが `{slug}`」に置き換えた).
	 *
	 * @param string $raw_name     元のバイト列の名前(`ZipArchive::FL_ENC_RAW`).
	 * @param string $decoded_name 既定の読み方の名前(パスとして使う).
	 * @param string $slug         テーマの stylesheet.
	 * @return string|null|false テーマ内の相対パス(ディレクトリのエントリは末尾の `/` を
	 *                           除いたもの. ルートのディレクトリは空文字列). `__MACOSX/`
	 *                           配下なら null. 拒否するなら false.
	 */
	private static function validate_entry_name( $raw_name, $decoded_name, $slug ) {
		if ( '' === $raw_name || '' === $decoded_name ) {
			return false;
		}

		// NUL・制御文字. NUL は libzip が読み出す時点で空白に置き換えるため、実際には
		// ここまで届かない(2026-10-01 に実際の zip で確認). 展開しないので害は無いが、
		// 別の libzip で素通りした場合に備えて検査は残す.
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $raw_name . $decoded_name ) ) {
			return false;
		}

		$normalized = str_replace( '\\', '/', $decoded_name );

		// 先頭が「/」の絶対パスと、Windows のドライブレターを拒否する.
		if ( '/' === $normalized[0] || 1 === preg_match( '#^[A-Za-z]:#', $normalized ) ) {
			return false;
		}

		// ディレクトリのエントリの末尾の `/` だけは許す.
		$segments = explode( '/', rtrim( $normalized, '/' ) );

		foreach ( $segments as $segment ) {
			// `..` に加え、`.` と空のセグメント(`a//b`)も拒否する. ローカルのパスと
			// 突き合わせるので、正規化されていない名前は受け入れない.
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		if ( '__MACOSX' === $segments[0] ) {
			return null;
		}

		// zip のルートは `{slug}/` の1つだけのはず(15テーマの実測ですべてこの形. §2.2).
		if ( $slug !== $segments[0] ) {
			return false;
		}

		// ルートのディレクトリ自体(`{slug}/`)は相対パスが空になる. 呼び出し側は
		// ディレクトリのエントリとして読み飛ばす. 末尾に `/` が無い `{slug}` は、
		// ルートに置かれたファイルなので拒否する.
		if ( 1 === count( $segments ) ) {
			return '/' === substr( $normalized, -1 ) ? '' : false;
		}

		return implode( '/', array_slice( $segments, 1 ) );
	}

	/**
	 * エントリが Unix のシンボリックリンクかどうか(WPMAR の
	 * `WPMAR_PDF_Installer::entry_is_symlink()` を移植).
	 *
	 * 外部属性の上位16ビットが Unix のファイルモードで、種別のビット(0xF000)が
	 * `S_IFLNK`(0xA000)ならシンボリックリンク.
	 *
	 * @param ZipArchive $zip   開いた zip.
	 * @param int        $index エントリの index.
	 * @return bool
	 */
	private static function entry_is_symlink( ZipArchive $zip, $index ) {
		$opsys = 0;
		$attr  = 0;

		if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
			return false;
		}

		if ( ZipArchive::OPSYS_UNIX !== $opsys ) {
			return false;
		}

		return 0xA000 === ( ( ( $attr >> 16 ) & 0xFFFF ) & 0xF000 );
	}

	/**
	 * 検査を通ったエントリを読みながら sha256 と md5 を取る(§3.2 の 8).
	 *
	 * `statIndex()` のサイズは zip の中に書かれた申告値で、実際の中身と違うことが
	 * ありうる. そのため読んだバイト数も数え、上限を超えたら打ち切る.
	 *
	 * @param ZipArchive         $zip     開いた zip.
	 * @param array<string, int> $entries `inspect_entries()` の戻り値.
	 * @param array              $limits  上限(`hash_archive()` 参照).
	 * @return array<string, array{sha256: string, md5: string}>|string 失敗したら `WPCV_Error_Code` の値.
	 */
	private static function hash_entries( ZipArchive $zip, array $entries, array $limits ) {
		$files       = array();
		$total_bytes = 0;

		foreach ( $entries as $relative => $index ) {
			// PHP 8.2 以上は index で読む(名前の読み方の違いで別のエントリを開かないため).
			// それより前は名前で読む(重複する名前は `inspect_entries()` で拒否済み).
			$stream = PHP_VERSION_ID >= 80200
				? $zip->getStreamIndex( $index )
				: $zip->getStream( (string) $zip->getNameIndex( $index ) );

			if ( false === $stream ) {
				return WPCV_Error_Code::ARCHIVE_INVALID;
			}

			$sha256      = hash_init( 'sha256' );
			$md5         = hash_init( 'md5' );
			$entry_bytes = 0;

			while ( ! feof( $stream ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading a ZipArchive stream (not a file on disk); WP_Filesystem does not apply.
				$chunk = fread( $stream, self::READ_CHUNK_BYTES );

				if ( false === $chunk ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fread above.
					fclose( $stream );

					return WPCV_Error_Code::ARCHIVE_INVALID;
				}

				$entry_bytes += strlen( $chunk );
				$total_bytes += strlen( $chunk );

				if ( $entry_bytes > $limits['entry_bytes'] || $total_bytes > $limits['total_bytes'] ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fread above.
					fclose( $stream );

					return WPCV_Error_Code::ARCHIVE_REJECTED;
				}

				hash_update( $sha256, $chunk );
				hash_update( $md5, $chunk );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fread above.
			fclose( $stream );

			$files[ $relative ] = array(
				'sha256' => hash_final( $sha256 ),
				'md5'    => hash_final( $md5 ),
			);
		}

		return $files;
	}

	/**
	 * キャッシュの形(`{ path: { sha256, md5 } }`)を、インターフェースの `files` の形
	 * (sha256 のみ)に変える(D3).
	 *
	 * @param array $cached_files キャッシュの `files`.
	 * @return array インターフェースの docblock にある `files` の形式.
	 */
	private static function to_manifest_files( array $cached_files ) {
		$files = array();

		foreach ( $cached_files as $path => $hashes ) {
			if ( ! is_array( $hashes ) || empty( $hashes['sha256'] ) ) {
				continue;
			}

			$files[ (string) $path ] = array(
				'algorithm' => WPCV_File_Hasher::ALGO_SHA256,
				'hashes'    => array( (string) $hashes['sha256'] ),
			);
		}

		return $files;
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
