<?php
/**
 * WPCV_GitHub_Client クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Releases の REST API を呼ぶクライアント(v0.8 §Step4. D2・D8・D9).
 *
 * 責務は次の2つだけ(マニフェストの組み立てや zip の検査は `WPCV_Source_GitHub` と
 * `WPCV_Zip_Manifest_Reader` が行う):
 *
 * 1. `find_release_by_version()`: インストールされている version の Release を探す(D2).
 *    `/releases/latest` は使わない(最新版と比べると、更新前のサイトで全ファイルが
 *    `modified` になる). tag は `{version}` → `v{version}` の順に試す(lunaluna の
 *    リポジトリでは両方の形が混在している. プラン §2.2).
 * 2. `download_asset()`: Release のアセットを一時ファイルへ取得する(D8). トークンが無ければ
 *    `browser_download_url`(API の回数を消費しない). あれば Assets API
 *    (`Accept: application/octet-stream` + `Authorization: Bearer`. 非公開リポジトリは
 *    `browser_download_url` では取得できない. `lib/l2d-updater` の Step A8 で実機確認済み).
 *
 * レート制限(D9): 403 / 429 で `x-ratelimit-remaining` が 0、または `retry-after` がある応答を
 * `rate_limited` とし、解除の時刻までを site transient に置く. その間は HTTP を出さずに
 * `rate_limited` を返す(1つの target で制限に当たれば、残りも当たるので回数を無駄にしない).
 * 待つ時間は GitHub の応答に従う: `retry-after`(秒)があればそれ、無ければ
 * `x-ratelimit-reset`(UTC エポック秒). どちらも無いときだけ 60 秒にする(公式ドキュメント
 * 〔rate-limits-for-the-rest-api〕の「Otherwise, wait for at least one minute」. 実測値ではない).
 *
 * トークンは `WPCV_GITHUB_TOKEN` 定数とフィルター `wpcv_github_token` だけから取る(DB には
 * 保存しない. U3). **ログ・エラー文・画面にトークンを出さない**: このクラスの戻り値は
 * `WPCV_Error_Code` の値だけで、HTTP のメッセージ・ヘッダーを返さない.
 *
 * HTTP は注入できる callable にしてある(テストで GitHub に接続しないため.
 * `lib/l2d-updater` の `http_get()` と同じ考え方).
 */
class WPCV_GitHub_Client {

	/**
	 * API のベース URL.
	 */
	const API_BASE = 'https://api.github.com';

	/**
	 * `X-GitHub-Api-Version` の既定値.
	 *
	 * 公式ドキュメント(api-versions. 2026-10-01 確認)で対応している値は `2026-03-10` と
	 * `2022-11-28`、ヘッダーを付けないときの既定は `2022-11-28`. 既定が将来変わっても
	 * 応答の形が変わらないよう、既定と同じ値を明示する. 対応が終わった version を指定すると
	 * 410 が返る(`http_error` になる)ので、そのときは `wpcv_github_api_version` で変える.
	 */
	const API_VERSION = '2022-11-28';

	/**
	 * API のタイムアウト秒数の既定値(`wpcv_github_api_timeout`).
	 *
	 * 実測(2026-10-01. 未認証で `releases/tags/{tag}`): ローカル 0.23〜0.52 秒(11件)、
	 * エックスサーバー(共有ホスティング)0.26 秒. 10秒は実測の最大の約19倍で、
	 * `lib/l2d-updater` と同じ値. target の lease(`WPCV_Target_Run_Repository::DEFAULT_LEASE_SECONDS`.
	 * 120秒)より十分短い. 短くしすぎると、回線が一時的に遅いだけで `http_error` になる.
	 */
	const DEFAULT_API_TIMEOUT = 10;

	/**
	 * アセットの取得のタイムアウト秒数の既定値(`wpcv_github_download_timeout`).
	 *
	 * Zip の取得に v0.7 で確定した値(エックスサーバー最大 2.06 秒の約15倍. v0.7 Step8).
	 * GitHub の実測(2026-10-01. `browser_download_url`): ローカル 0.58〜0.63 秒(54KB・620KB)、
	 * エックスサーバー 0.92 秒(WPMAR 1.6.0 の zip 567KB).
	 */
	const DEFAULT_DOWNLOAD_TIMEOUT = 30;

	/**
	 * レート制限の解除時刻を置く site transient の名前.
	 */
	const RATE_LIMIT_TRANSIENT = 'wpcv_github_rate_limited_until';

	/**
	 * 解除の時刻が応答から分からないときの待ち時間(秒).
	 *
	 * 公式ドキュメントの記述(コンストラクタ前のクラス docblock 参照). 実測値ではない.
	 */
	const RATE_LIMIT_FALLBACK_SECONDS = 60;

	/**
	 * `owner/repo` として受け付ける形.
	 *
	 * 暫定: GitHub の文字種の規則は Step6 で確認する(`プラン §4.1`).
	 */
	const REPO_PATTERN = '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#';

	/**
	 * API を GET する callable. `( string $url, array $args ): array|WP_Error`.
	 *
	 * @var callable
	 */
	private $http_get;

	/**
	 * `browser_download_url` を一時ファイルへ取得する callable.
	 * `( string $url, int $timeout ): string|WP_Error`(`download_url()` と同じ契約).
	 *
	 * @var callable
	 */
	private $downloader;

	/**
	 * 現在時刻(Unix timestamp)を返す callable.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * コンストラクタ.
	 *
	 * @param callable|null $http_get   API の GET. 省略時は `wp_safe_remote_get()`.
	 * @param callable|null $downloader 公開アセットの取得. 省略時は `download_url()`
	 *                                  (`wp-admin/includes/file.php`. cron・CLI では読み込まれていないので、ここで読む).
	 * @param callable|null $now        現在時刻. 省略時は `time()`.
	 */
	public function __construct( ?callable $http_get = null, ?callable $downloader = null, ?callable $now = null ) {
		$this->http_get = $http_get ?? static function ( $url, $args ) {
			return wp_safe_remote_get( $url, $args );
		};

		$this->downloader = $downloader ?? static function ( $url, $timeout ) {
			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			return download_url( $url, $timeout );
		};

		$this->now = $now ?? static function () {
			return time();
		};
	}

	/**
	 * GitHub のトークンが設定されているか(値は返さない. 設定画面・サイトヘルス用).
	 *
	 * @return bool
	 */
	public static function has_token() {
		return '' !== self::resolve_token( '' );
	}

	/**
	 * トークンを取り出す(`WPCV_GITHUB_TOKEN` 定数 → フィルター `wpcv_github_token`).
	 *
	 * @param string $repo `owner/repo`(フィルターの第2引数. リポジトリごとにトークンを変えたい運用者向け).
	 * @return string トークン. 無ければ空文字.
	 */
	private static function resolve_token( $repo ) {
		$token = defined( 'WPCV_GITHUB_TOKEN' ) && is_string( WPCV_GITHUB_TOKEN ) ? trim( WPCV_GITHUB_TOKEN ) : '';

		/**
		 * GitHub API のトークンを差し込む・変える(v0.8 §Step4. U3).
		 *
		 * 値をログや画面に出さないこと. DB に保存しないこと.
		 *
		 * @param string $token 既定は `WPCV_GITHUB_TOKEN` 定数の値(無ければ空文字).
		 * @param string $repo  `owner/repo`.
		 */
		$token = apply_filters( 'wpcv_github_token', $token, (string) $repo );

		return trim( (string) $token );
	}

	/**
	 * `owner/repo` の形として受け付けるか.
	 *
	 * 文字種に加え、`.` だけのセグメント(`../x` など)を拒否する. URL のパスに入るため、
	 * `..` が親ディレクトリとして解釈されるのを防ぐ.
	 *
	 * @param string $repo `owner/repo`.
	 * @return bool
	 */
	public static function is_valid_repo( $repo ) {
		if ( 1 !== preg_match( self::REPO_PATTERN, (string) $repo ) ) {
			return false;
		}

		foreach ( explode( '/', (string) $repo ) as $segment ) {
			if ( '' === trim( $segment, '.' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * レート制限が解除される時刻(Unix timestamp)を返す.
	 *
	 * @return int|null 制限中でなければ null.
	 */
	public function get_rate_limited_until() {
		$until = get_site_transient( self::RATE_LIMIT_TRANSIENT );

		if ( ! is_numeric( $until ) || (int) $until <= (int) call_user_func( $this->now ) ) {
			return null;
		}

		return (int) $until;
	}

	/**
	 * インストールされている version の Release を探す(D2).
	 *
	 * @param string $repo    `owner/repo`.
	 * @param string $version インストールされている version.
	 * @return array{release: array|null, error_code: string|null} 見つかれば `release`(API の
	 *         応答をデコードした配列). 見つからなければ `error_code`:
	 *         `unknown_source`(`$repo` の形が不正. HTTP なし)・`rate_limited`・`http_error`・
	 *         `manifest_not_found`(すべての tag の候補が 404).
	 */
	public function find_release_by_version( $repo, $version ) {
		$repo    = (string) $repo;
		$version = (string) $version;

		if ( ! self::is_valid_repo( $repo ) ) {
			return self::release_failure( WPCV_Error_Code::UNKNOWN_SOURCE );
		}

		if ( null !== $this->get_rate_limited_until() ) {
			return self::release_failure( WPCV_Error_Code::RATE_LIMITED );
		}

		/**
		 * Release を探すときに試す tag の候補と順番を変える(v0.8 §Step4. D2).
		 *
		 * 既定は `{version}` → `v{version}`. 最初の候補が 404 のときだけ次を試す.
		 *
		 * @param string[] $candidates 既定 `array( $version, 'v' . $version )`.
		 * @param string   $repo       `owner/repo`.
		 * @param string   $version    インストールされている version.
		 */
		$candidates = apply_filters( 'wpcv_github_tag_candidates', array( $version, 'v' . $version ), $repo, $version );
		$candidates = array_values(
			array_unique(
				array_filter(
					array_map( 'strval', (array) $candidates ),
					static function ( $tag ) {
						return '' !== $tag;
					}
				)
			)
		);

		foreach ( $candidates as $tag ) {
			$response = call_user_func(
				$this->http_get,
				self::API_BASE . '/repos/' . $repo . '/releases/tags/' . rawurlencode( $tag ),
				$this->api_args( $repo )
			);

			if ( is_wp_error( $response ) ) {
				return self::release_failure( WPCV_Error_Code::HTTP_ERROR );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 404 === $code ) {
				continue;
			}

			if ( 200 === $code ) {
				$release = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( ! is_array( $release ) || ! isset( $release['tag_name'] ) ) {
					return self::release_failure( WPCV_Error_Code::HTTP_ERROR );
				}

				return array(
					'release'    => $release,
					'error_code' => null,
				);
			}

			return self::release_failure( $this->classify_failure( $response, $code ) );
		}

		return self::release_failure( WPCV_Error_Code::MANIFEST_NOT_FOUND );
	}

	/**
	 * Release のアセットを一時ファイルへ取得する(D8).
	 *
	 * @param string $repo      `owner/repo`(トークンの解決に使う).
	 * @param array  $asset     Release の `assets` の1要素(`url`・`browser_download_url`・`size`).
	 * @param int    $max_bytes 取得してよい最大バイト数. 申告サイズがこれを超えれば取得しない.
	 * @return array{path: string|null, error_code: string|null} 成功したら一時ファイルのパス
	 *         (**呼び出し側が消す**). 失敗したら `error_code`: `archive_rejected`(申告サイズが
	 *         上限超え)・`rate_limited`・`http_error`.
	 */
	public function download_asset( $repo, array $asset, $max_bytes ) {
		$repo      = (string) $repo;
		$max_bytes = (int) $max_bytes;

		if ( isset( $asset['size'] ) && (int) $asset['size'] > $max_bytes ) {
			return self::download_failure( WPCV_Error_Code::ARCHIVE_REJECTED );
		}

		$token   = self::resolve_token( $repo );
		$timeout = max( 1, (int) apply_filters( 'wpcv_github_download_timeout', self::DEFAULT_DOWNLOAD_TIMEOUT, $repo ) );

		if ( '' === $token ) {
			$url = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';

			if ( '' === $url ) {
				return self::download_failure( WPCV_Error_Code::HTTP_ERROR );
			}

			// `browser_download_url` は API の回数を消費しない(プラン §2.1 で実測). 回数を
			// 使わないので、レート制限中でも取得してよい.
			$tmp = call_user_func( $this->downloader, $url, $timeout );

			if ( is_wp_error( $tmp ) ) {
				return self::download_failure( WPCV_Error_Code::HTTP_ERROR );
			}

			return array(
				'path'       => (string) $tmp,
				'error_code' => null,
			);
		}

		// トークンあり: Assets API. 回数を消費するので、制限中なら出さない.
		if ( null !== $this->get_rate_limited_until() ) {
			return self::download_failure( WPCV_Error_Code::RATE_LIMITED );
		}

		$url = isset( $asset['url'] ) ? (string) $asset['url'] : '';

		if ( '' === $url ) {
			return self::download_failure( WPCV_Error_Code::HTTP_ERROR );
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam( 'wpcv-github-asset' );

		if ( ! $tmp ) {
			return self::download_failure( WPCV_Error_Code::HTTP_ERROR );
		}

		$args             = $this->api_args( $repo );
		$args['timeout']  = $timeout;
		$args['stream']   = true;
		$args['filename'] = $tmp;
		// 1バイト多く許す: 上限ちょうどで打ち切られても、呼び出し側が「上限超え」と判定できるように.
		$args['limit_response_size'] = $max_bytes + 1;
		$args['headers']['Accept']   = 'application/octet-stream';

		$response = call_user_func( $this->http_get, $url, $args );

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );

			return self::download_failure( WPCV_Error_Code::HTTP_ERROR );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			wp_delete_file( $tmp );

			return self::download_failure( $this->classify_failure( $response, $code ) );
		}

		return array(
			'path'       => $tmp,
			'error_code' => null,
		);
	}

	/**
	 * プラグインのバージョン(User-Agent に入れる)を、メインファイルのヘッダーから読む.
	 *
	 * バージョンの定数は無い(定数にすると、リリースのたびに更新する場所が増える)ので、
	 * ヘッダーを1回だけ読んで覚える. 読めなければ `dev`.
	 *
	 * @return string
	 */
	private static function plugin_version() {
		static $version = null;

		if ( null === $version ) {
			$file    = dirname( __DIR__, 2 ) . '/wp-checksum-verifier.php';
			$data    = is_readable( $file ) ? get_file_data( $file, array( 'Version' => 'Version' ), 'plugin' ) : array();
			$version = isset( $data['Version'] ) && '' !== $data['Version'] ? (string) $data['Version'] : 'dev';
		}

		return $version;
	}

	/**
	 * GitHub API へのリクエスト引数(ヘッダーとタイムアウト).
	 *
	 * @param string $repo `owner/repo`(トークンの解決に使う).
	 * @return array `wp_safe_remote_get()` に渡す引数.
	 */
	private function api_args( $repo ) {
		/**
		 * `X-GitHub-Api-Version` の値を変える(v0.8 §Step4. `API_VERSION` の docblock 参照).
		 *
		 * @param string $version 既定 `WPCV_GitHub_Client::API_VERSION`.
		 */
		$api_version = (string) apply_filters( 'wpcv_github_api_version', self::API_VERSION );

		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => 'wp-checksum-verifier/' . self::plugin_version(),
			'X-GitHub-Api-Version' => $api_version,
		);

		$token = self::resolve_token( $repo );

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return array(
			/**
			 * GitHub API のタイムアウト秒数を変える(v0.8 §Step4. `DEFAULT_API_TIMEOUT` の docblock 参照).
			 *
			 * @param int    $timeout 既定 `WPCV_GitHub_Client::DEFAULT_API_TIMEOUT`.
			 * @param string $repo    `owner/repo`.
			 */
			'timeout' => max( 1, (int) apply_filters( 'wpcv_github_api_timeout', self::DEFAULT_API_TIMEOUT, (string) $repo ) ),
			'headers' => $headers,
		);
	}

	/**
	 * 200 でも 404 でもなかった応答を、`rate_limited` か `http_error` に分ける(D9).
	 *
	 * `rate_limited` のときは、解除の時刻を site transient に置く.
	 *
	 * @param array|WP_Error $response HTTP の応答.
	 * @param int            $code     ステータスコード.
	 * @return string `WPCV_Error_Code` の値.
	 */
	private function classify_failure( $response, $code ) {
		if ( 403 !== $code && 429 !== $code ) {
			return WPCV_Error_Code::HTTP_ERROR;
		}

		$headers     = is_array( $response ) && isset( $response['headers'] ) ? $response['headers'] : array();
		$retry_after = isset( $headers['retry-after'] ) ? $headers['retry-after'] : null;
		$remaining   = isset( $headers['x-ratelimit-remaining'] ) ? $headers['x-ratelimit-remaining'] : null;
		$reset       = isset( $headers['x-ratelimit-reset'] ) ? $headers['x-ratelimit-reset'] : null;

		$has_retry_after = is_numeric( $retry_after );
		$exhausted       = is_numeric( $remaining ) && 0 === (int) $remaining;

		if ( ! $has_retry_after && ! $exhausted ) {
			// 権限の無い 403 など(制限ではない).
			return WPCV_Error_Code::HTTP_ERROR;
		}

		$now = (int) call_user_func( $this->now );

		if ( $has_retry_after ) {
			$until = $now + max( 0, (int) $retry_after );
		} elseif ( is_numeric( $reset ) ) {
			$until = (int) $reset;
		} else {
			$until = $now + self::RATE_LIMIT_FALLBACK_SECONDS;
		}

		// 解除の時刻がすでに過ぎている(時計のずれ等)ときも、少なくとも1秒は置く.
		$until = max( $until, $now + 1 );

		set_site_transient( self::RATE_LIMIT_TRANSIENT, $until, $until - $now );

		return WPCV_Error_Code::RATE_LIMITED;
	}

	/**
	 * `find_release_by_version()` の失敗の戻り値.
	 *
	 * @param string $error_code `WPCV_Error_Code` の値.
	 * @return array{release: null, error_code: string}
	 */
	private static function release_failure( $error_code ) {
		return array(
			'release'    => null,
			'error_code' => $error_code,
		);
	}

	/**
	 * `download_asset()` の失敗の戻り値.
	 *
	 * @param string $error_code `WPCV_Error_Code` の値.
	 * @return array{path: null, error_code: string}
	 */
	private static function download_failure( $error_code ) {
		return array(
			'path'       => null,
			'error_code' => $error_code,
		);
	}
}
