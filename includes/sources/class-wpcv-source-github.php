<?php
/**
 * WPCV_Source_GitHub クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Releases のアセットの zip からマニフェストを作る(v0.8プラン §4.3・D1〜D7・D12).
 *
 * 照合するのは**インストールされている version の Release** のアセット(D1. `/releases/latest`
 * は使わない). Release の特定とアセットの取得は `WPCV_GitHub_Client`、zip の検査とハッシュは
 * `WPCV_Zip_Manifest_Reader` が行い、このクラスは次を受け持つ:
 *
 * - 照合してよいかの判定(version が空・`.git` があるディレクトリ)
 * - アセットの選び方(D3)
 * - `digest` の照合(D5)・zip の中のメインファイルの version の照合(D6)
 * - キャッシュ(`wpcv_manifest_cache`. source = `github`・slug = `{owner}/{repo}`. D7)
 *
 * `get_manifest()` の流れ(`$context` は下のメソッド docblock):
 *
 * 1. version が空 → `version_unknown`(HTTP なし)
 * 2. `base_dir` の直下に `.git` がある → `unknown_source`(HTTP なし. D12. 開発用のチェックアウトの
 *    ツリーは配布 zip と中身が違う. WPCV は `lib/action-scheduler` を同梱する)
 * 3. キャッシュにあれば `cached`
 * 4. `ZipArchive` が無い → `ziparchive_missing`(HTTP なし)
 * 5. Release を探す → アセットを選ぶ → ダウンロード → `digest` → zip の検査 → メインファイルの version
 * 6. キャッシュに保存して返す(`ok`)
 *
 * 4 を 3 のあとに置くのは、キャッシュがあれば `ZipArchive` が無くても照合できるため(wp.org のテーマと同じ).
 * 一時ファイルは `finally` で必ず消す.
 */
class WPCV_Source_GitHub implements WPCV_Manifest_Source {

	/**
	 * 取得した zip のサイズの上限(バイト. `wpcv_github_zip_max_archive_bytes`).
	 *
	 * テーマの zip(v0.7. `WPCV_Source_Wporg_Theme::DEFAULT_MAX_ARCHIVE_BYTES`)と同じ暫定値
	 * (プラン §7. 2026-10-01 に v0.7 で承認済み). GitHub の実測は WPCV の zip が 620KB.
	 */
	const DEFAULT_MAX_ARCHIVE_BYTES = 104857600;

	/**
	 * ZIP のエントリ数の上限(`wpcv_github_zip_max_entries`). v0.7 と同じ暫定値.
	 */
	const DEFAULT_MAX_ENTRIES = 20000;

	/**
	 * 1エントリの展開後のサイズの上限(バイト. `wpcv_github_zip_max_entry_bytes`). v0.7 と同じ暫定値.
	 */
	const DEFAULT_MAX_ENTRY_BYTES = 52428800;

	/**
	 * 展開後の合計サイズの上限(バイト. `wpcv_github_zip_max_total_bytes`). v0.7 と同じ暫定値.
	 */
	const DEFAULT_MAX_TOTAL_BYTES = 524288000;

	/**
	 * 1エントリの圧縮率の上限(`wpcv_github_zip_max_compression_ratio`). v0.7 と同じ暫定値.
	 */
	const DEFAULT_MAX_COMPRESSION_RATIO = 100;

	/**
	 * マニフェストのキャッシュ.
	 *
	 * @var WPCV_Manifest_Cache_Repository
	 */
	private $cache;

	/**
	 * GitHub のクライアント.
	 *
	 * @var WPCV_GitHub_Client
	 */
	private $client;

	/**
	 * `ZipArchive` が使えるかを返す callable(テストで「無い環境」を再現するため).
	 *
	 * @var callable
	 */
	private $zip_available;

	/**
	 * 同じリクエストの中で作ったマニフェスト(キャッシュに保存できなかった場合の再取得を防ぐ. D7).
	 * `{owner}/{repo}` + NUL + version をキーにした `files`(`{ path: { sha256, md5 } }`).
	 *
	 * @var array<string, array>
	 */
	private $memo = array();

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Manifest_Cache_Repository $cache         マニフェストのキャッシュ.
	 * @param WPCV_GitHub_Client             $client        GitHub のクライアント.
	 * @param callable|null                  $zip_available `ZipArchive` が使えるかを返す. 省略時は
	 *                                                      `class_exists( 'ZipArchive' )`.
	 */
	public function __construct( WPCV_Manifest_Cache_Repository $cache, WPCV_GitHub_Client $client, ?callable $zip_available = null ) {
		$this->cache         = $cache;
		$this->client        = $client;
		$this->zip_available = $zip_available ?? static function () {
			return class_exists( 'ZipArchive' );
		};
	}

	/**
	 * GitHub Releases のマニフェストを取得する.
	 *
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string $dimension `plugin`|`theme`. 必須(メインファイルの `Version` を読む文脈を決める).
	 *     @type string $slug      プラグインの slug・テーマの stylesheet. 必須(アセットの選び方に使う).
	 *     @type string $version   インストールされている version. 空なら HTTP を出さず `version_unknown`.
	 *     @type string $repo      `owner/repo`. 必須.
	 *     @type string $asset     アセット名の前方一致(対応付けの指定. 省略可).
	 *     @type string $base_dir  インストール先のディレクトリ(`.git` の判定に使う. 空なら判定しない.
	 *                             単一ファイルのプラグインは空にする).
	 *     @type string $main_file ルートからの相対パスのメインファイル(プラグインは `foo.php`、テーマは
	 *                             `style.css`). zip の中の `Version` ヘッダーの照合に使う(D6).
	 * }
	 * @return array インターフェースの docblock を参照.
	 *
	 * @throws InvalidArgumentException 必須の `slug`・`repo` が無い場合(呼び出し側の実装ミス).
	 */
	public function get_manifest( array $context ) {
		if ( empty( $context['slug'] ) || empty( $context['repo'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Source_GitHub::get_manifest() requires $context[\'slug\'] and $context[\'repo\'].' ) );
		}

		$dimension = isset( $context['dimension'] ) ? (string) $context['dimension'] : 'plugin';
		$slug      = (string) $context['slug'];
		$repo      = (string) $context['repo'];
		$version   = isset( $context['version'] ) ? (string) $context['version'] : '';
		$asset     = isset( $context['asset'] ) ? (string) $context['asset'] : '';
		$base_dir  = isset( $context['base_dir'] ) ? (string) $context['base_dir'] : '';
		$main_file = isset( $context['main_file'] ) ? (string) $context['main_file'] : '';

		// 1. version を推測しない(wp.org と同じ方針).
		if ( '' === $version ) {
			return self::missing( WPCV_Error_Code::VERSION_UNKNOWN );
		}

		// 2. D12: 開発用のチェックアウト・シンボリックリンクは GitHub と照合しない.
		if ( self::has_git_entry( $base_dir ) ) {
			return self::missing( WPCV_Error_Code::UNKNOWN_SOURCE );
		}

		$memo_key = $repo . "\0" . $version;

		if ( isset( $this->memo[ $memo_key ] ) ) {
			return self::ok( 'cached', $this->memo[ $memo_key ] );
		}

		// 3. キャッシュ.
		$cached = $this->cache->find( WPCV_Manifest_Cache_Repository::SOURCE_GITHUB, $repo, $version );

		if ( null !== $cached ) {
			return self::ok( 'cached', $cached['files'] );
		}

		// 4. ZipArchive.
		if ( ! call_user_func( $this->zip_available ) ) {
			return self::missing( WPCV_Error_Code::ZIPARCHIVE_MISSING );
		}

		// 5. Release → アセット → ダウンロード → 検査.
		$found = $this->client->find_release_by_version( $repo, $version );

		if ( null !== $found['error_code'] ) {
			return self::missing( $found['error_code'] );
		}

		$selected = self::select_asset( (array) ( $found['release']['assets'] ?? array() ), $slug, $version, $asset );

		if ( is_string( $selected ) ) {
			return self::missing( $selected );
		}

		$max_bytes = (int) apply_filters( 'wpcv_github_zip_max_archive_bytes', self::DEFAULT_MAX_ARCHIVE_BYTES, $repo );
		$download  = $this->client->download_asset( $repo, $selected, $max_bytes );

		if ( null !== $download['error_code'] ) {
			return self::missing( $download['error_code'] );
		}

		$tmp = (string) $download['path'];

		try {
			$archive_bytes = (int) filesize( $tmp );

			if ( $archive_bytes > $max_bytes ) {
				return self::missing( WPCV_Error_Code::ARCHIVE_REJECTED );
			}

			// D5: GitHub が付けたダイジェストと、実際に受け取ったバイト列を比べる.
			if ( ! self::digest_matches( $selected, $tmp ) ) {
				return self::missing( WPCV_Error_Code::ARCHIVE_INVALID );
			}

			$files = WPCV_Zip_Manifest_Reader::read(
				$tmp,
				null,
				array(
					'entries'     => (int) apply_filters( 'wpcv_github_zip_max_entries', self::DEFAULT_MAX_ENTRIES, $repo ),
					'entry_bytes' => (int) apply_filters( 'wpcv_github_zip_max_entry_bytes', self::DEFAULT_MAX_ENTRY_BYTES, $repo ),
					'total_bytes' => (int) apply_filters( 'wpcv_github_zip_max_total_bytes', self::DEFAULT_MAX_TOTAL_BYTES, $repo ),
					'ratio'       => (float) apply_filters( 'wpcv_github_zip_max_compression_ratio', self::DEFAULT_MAX_COMPRESSION_RATIO, $repo ),
				)
			);

			if ( is_string( $files ) ) {
				return self::missing( $files );
			}

			// D6: zip の中のメインファイルの version が、インストールされている version と
			// 違えば、対応付けの誤り(別のリポジトリ)か tag と中身の食い違い.
			if ( ! self::main_file_version_matches( $tmp, $dimension, $main_file, $version ) ) {
				return self::missing( WPCV_Error_Code::ASSET_AMBIGUOUS );
			}

			// 保存できなくても(`{owner}/{repo}` が100文字を超える場合など)今回の照合は続けられる.
			// 同じリクエストの中では `memo` で再取得を防ぐ.
			$this->memo[ $memo_key ] = $files;
			$this->cache->save( WPCV_Manifest_Cache_Repository::SOURCE_GITHUB, $repo, $version, $files, $archive_bytes );

			return self::ok( 'ok', $files );
		} finally {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * Release のアセットから、照合に使う zip を1つ選ぶ(D3).
	 *
	 * 1. 対応付けに `asset` の指定があれば、その名前で始まり `.zip` で終わるもの
	 * 2. 無ければ `{slug}.{version}.zip` と完全一致するもの(l2d-updater の配布規約)
	 * 3. それも無ければ `{slug}` で始まり `.zip` で終わるもの(l2d-updater の `extract_zip_url()` と同じ判定)
	 *
	 * 1・3 で候補が2つ以上なら `asset_ambiguous`、0 なら `no_release_asset`. zipball / tarball は
	 * 使わない(自動生成の zip はルートのディレクトリ名がリポジトリ名・タグを含み、配布物と
	 * 中身が違うことがあるため).
	 *
	 * @param array  $assets  Release の `assets`.
	 * @param string $slug    プラグインの slug・テーマの stylesheet.
	 * @param string $version インストールされている version.
	 * @param string $prefix  対応付けの `asset`(空なら指定なし).
	 * @return array|string 選んだアセット. 選べなければ `WPCV_Error_Code` の値.
	 */
	private static function select_asset( array $assets, $slug, $version, $prefix ) {
		$zips = array();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['name'] ) ) {
				continue;
			}

			// アップロードの途中・失敗のアセットは使わない. state が無い応答は使う.
			if ( isset( $asset['state'] ) && 'uploaded' !== $asset['state'] ) {
				continue;
			}

			if ( '.zip' === substr( (string) $asset['name'], -4 ) ) {
				$zips[] = $asset;
			}
		}

		if ( '' !== $prefix ) {
			return self::pick_single( self::filter_by_prefix( $zips, $prefix ) );
		}

		$exact = $slug . '.' . $version . '.zip';

		foreach ( $zips as $asset ) {
			if ( $exact === (string) $asset['name'] ) {
				return $asset;
			}
		}

		return self::pick_single( self::filter_by_prefix( $zips, $slug ) );
	}

	/**
	 * 名前が前方一致するアセットだけを返す.
	 *
	 * @param array  $zips   `.zip` のアセット.
	 * @param string $prefix 前方一致の文字列.
	 * @return array
	 */
	private static function filter_by_prefix( array $zips, $prefix ) {
		return array_values(
			array_filter(
				$zips,
				static function ( $asset ) use ( $prefix ) {
					return 0 === strpos( (string) $asset['name'], $prefix );
				}
			)
		);
	}

	/**
	 * 候補がちょうど1つならそれを、0なら `no_release_asset`、2つ以上なら `asset_ambiguous` を返す.
	 *
	 * @param array $candidates アセットの候補.
	 * @return array|string
	 */
	private static function pick_single( array $candidates ) {
		if ( array() === $candidates ) {
			return WPCV_Error_Code::NO_RELEASE_ASSET;
		}

		return 1 === count( $candidates ) ? $candidates[0] : WPCV_Error_Code::ASSET_AMBIGUOUS;
	}

	/**
	 * アセットの `digest`(`sha256:...`)と、取得したファイルの sha256 が一致するか(D5).
	 *
	 * `digest` が無い(null・空・別のアルゴリズム)なら検査しない(公式ドキュメントでは
	 * `digest` は null がありうる. プラン §2.3).
	 *
	 * @param array  $asset アセット.
	 * @param string $path  取得したファイルのパス.
	 * @return bool 検査しない場合も true.
	 */
	private static function digest_matches( array $asset, $path ) {
		$digest = isset( $asset['digest'] ) ? (string) $asset['digest'] : '';

		if ( 0 !== strpos( $digest, 'sha256:' ) ) {
			return true;
		}

		$actual = hash_file( 'sha256', $path );

		return false !== $actual && hash_equals( strtolower( substr( $digest, 7 ) ), $actual );
	}

	/**
	 * Zip の中のメインファイルの `Version` ヘッダーが、インストールされている version と同じか(D6).
	 *
	 * メインファイルが zip に無い、または `Version` ヘッダーが無いときも不一致とする.
	 * `$main_file` が空なら検査しない(呼び出し側がメインファイルを特定できないとき).
	 *
	 * @param string $zip_path  zip のパス.
	 * @param string $dimension `plugin`|`theme`.
	 * @param string $main_file ルートからの相対パス.
	 * @param string $version   インストールされている version.
	 * @return bool
	 */
	private static function main_file_version_matches( $zip_path, $dimension, $main_file, $version ) {
		if ( '' === $main_file ) {
			return true;
		}

		$head = WPCV_Zip_Manifest_Reader::read_entry_head( $zip_path, $main_file );

		if ( null === $head ) {
			return false;
		}

		$context = 'theme' === $dimension ? 'theme' : 'plugin';

		return WPCV_Current_Version_Reader::version_from_head( $head, $context ) === $version;
	}

	/**
	 * `base_dir` の直下に `.git`(ディレクトリでもファイルでもよい)があるか(D12).
	 *
	 * `git worktree` や submodule では `.git` がファイルになる. `base_dir` がシンボリックリンク
	 * (開発リポジトリへのリンク)のときは `realpath()` の先で見る.
	 *
	 * @param string $base_dir インストール先のディレクトリ. 空なら false.
	 * @return bool
	 */
	private static function has_git_entry( $base_dir ) {
		if ( '' === $base_dir ) {
			return false;
		}

		$real = realpath( $base_dir );

		if ( false === $real ) {
			return false;
		}

		$git = $real . '/.git';

		return file_exists( $git ) || is_link( $git );
	}

	/**
	 * キャッシュの形(`{ path: { sha256, md5 } }`)を、インターフェースの `files` の形に変えて返す.
	 *
	 * @param string $status `ok`|`cached`.
	 * @param array  $files  `{ path: { sha256, md5 } }`.
	 * @return array
	 */
	private static function ok( $status, array $files ) {
		$manifest = array();

		foreach ( $files as $path => $hashes ) {
			$manifest[ $path ] = array(
				'algorithm' => 'sha256',
				'hashes'    => array( (string) $hashes['sha256'] ),
			);
		}

		return array(
			'manifest_status' => $status,
			'error_code'      => null,
			'files'           => $manifest,
		);
	}

	/**
	 * マニフェストが取得できないときの戻り値(インターフェースの docblock 参照).
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
