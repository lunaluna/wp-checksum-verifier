<?php
/**
 * L2dwpghul_GitHub_Updater クラスファイル.
 *
 * `Update URI: false` を維持したまま、GitHub Releases を情報源として
 * プラグインの更新を WordPress の管理画面から行えるようにする.
 *
 * - pre_set_site_transient_update_plugins: 更新トランジェントに自前のエントリを差し込む
 * - plugins_api: 「詳細を表示」モーダル用の情報を返す
 * - upgrader_process_complete: 更新完了後にキャッシュとトランジェントを掃除する
 *
 * @package L2dWpGithubUpdateLib
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // 直接のアクセスを防止.
}

/**
 * GitHub Releases ベースの自己更新機構: L2dwpghul_GitHub_Updater.
 *
 * 1 リクエスト内で複数プラグインがこのクラスを共有するため、
 * プラグイン固有の値はすべてコンストラクタに渡す設定でインスタンスへ持たせる
 * (クラス定数は使わない).
 */
class L2dwpghul_GitHub_Updater {

	/**
	 * 利用側プラグインのメインファイルの絶対パス.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * 更新元の GitHub リポジトリ (owner/repo).
	 *
	 * @var string
	 */
	private $github_repo;

	/**
	 * プラグインスラッグ.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * リリース情報キャッシュのサイトトランジェントキー.
	 *
	 * @var string
	 */
	private $cache_key;

	/**
	 * 旧来のフィルタ名互換のためのプレフィックス. 空文字なら旧名は適用しない.
	 *
	 * @var string
	 */
	private $filter_prefix;

	/**
	 * リリース情報キャッシュの既定 TTL (秒).
	 *
	 * @var int
	 */
	private $cache_ttl;

	/**
	 * 取得失敗時のバックオフ TTL (秒).
	 *
	 * @var int
	 */
	private $backoff_ttl;

	/**
	 * 配布用アセットの名前マッチングパターン.
	 *
	 * 文字列なら前方一致文字列として、callable なら $name を渡して真偽値を
	 * 返す判定関数として extract_zip_url() に渡される.
	 *
	 * @var string|callable
	 */
	private $asset_pattern;

	/**
	 * $config で明示指定された表示名(未指定ならヘッダーから導出).
	 *
	 * @var string|null
	 */
	private $name_override;

	/**
	 * $config で明示指定された作者 HTML(未指定ならヘッダーから導出).
	 *
	 * @var string|null
	 */
	private $author_override;

	/**
	 * ヘッダー読み取り結果のメモ化キャッシュ(get_file_data() の戻り値).
	 *
	 * @var array|null
	 */
	private $headers;

	/**
	 * Prerelease チャンネル(/releases 一覧から draft を除いた最新)を
	 * 使うかどうか。既定は false で /releases/latest を使う.
	 *
	 * @var bool
	 */
	private $allow_prerelease;

	/**
	 * プライベートリポジトリ用の GitHub API 認証トークン. 空文字なら
	 * 未認証(公開リポジトリ)として扱う.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * 設定を受け取り、フックを登録する.
	 *
	 * 必須キーは plugin_file(プラグインのメインファイルの絶対パス)と
	 * github_repo(更新元の GitHub リポジトリ owner/repo)の 2 つ。任意キーは
	 * slug / name / author / cache_key / filter_prefix / cache_ttl /
	 * backoff_ttl / asset_pattern / allow_prerelease / token.
	 *
	 * @param array $config 設定の連想配列.
	 */
	public function __construct( array $config ) {
		if ( empty( $config['plugin_file'] ) || empty( $config['github_repo'] ) ) {
			_doing_it_wrong( __METHOD__, 'plugin_file と github_repo は必須の設定キーです.', '1.0.0' );
			return;
		}

		$this->plugin_file      = $config['plugin_file'];
		$this->github_repo      = $config['github_repo'];
		$this->slug             = ! empty( $config['slug'] ) ? $config['slug'] : dirname( plugin_basename( $this->plugin_file ) );
		$this->cache_key        = ! empty( $config['cache_key'] ) ? $config['cache_key'] : ( 'l2dwpghul_updater_' . md5( $this->github_repo ) );
		$this->filter_prefix    = ! empty( $config['filter_prefix'] ) ? $config['filter_prefix'] : '';
		$this->cache_ttl        = isset( $config['cache_ttl'] ) ? (int) $config['cache_ttl'] : 21600;
		$this->backoff_ttl      = isset( $config['backoff_ttl'] ) ? (int) $config['backoff_ttl'] : 1800;
		$this->asset_pattern    = ! empty( $config['asset_pattern'] ) ? $config['asset_pattern'] : $this->slug;
		$this->name_override    = ! empty( $config['name'] ) ? $config['name'] : null;
		$this->author_override  = ! empty( $config['author'] ) ? $config['author'] : null;
		$this->allow_prerelease = ! empty( $config['allow_prerelease'] );
		$this->token            = ! empty( $config['token'] ) ? $config['token'] : '';

		$this->init();
	}

	/**
	 * フックを登録する.
	 *
	 * @return void
	 */
	private function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_source_directory' ), 10, 4 );
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download_package' ), 10, 4 );
	}

	/**
	 * 自前の更新情報を update_plugins トランジェントに差し込む.
	 *
	 * `Update URI: false` のためコアは版比較を行わないので、判定を自前で行う.
	 *
	 * @param mixed $transient update_plugins トランジェントの値.
	 * @return mixed 加工後のトランジェントの値.
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->fetch_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$basename = $this->get_basename();

		// $transient->checked にはヘッダー由来の実インストール版が入っている（コア: wp-includes/update.php).
		$installed = isset( $transient->checked[ $basename ] )
			? $transient->checked[ $basename ]
			: $this->get_headers()['version'];

		if ( version_compare( $release['version'], $installed, '>' ) ) {
			$transient->response[ $basename ] = $this->build_plugin_update_object( $release );
		} else {
			// 更新完了後などに残った通知を消してから no_update に登録する.
			unset( $transient->response[ $basename ] );
			$transient->no_update[ $basename ] = $this->build_plugin_update_object( $release );
		}

		return $transient;
	}

	/**
	 * 「詳細を表示」モーダル用の情報を返す.
	 *
	 * @param false|object|array $result 既定の戻り値.
	 * @param string             $action 要求されたアクション.
	 * @param object             $args   plugins_api への引数.
	 * @return false|object|array 加工後の戻り値.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $this->slug !== $args->slug ) {
			return $result;
		}

		$release = $this->fetch_latest_release();
		if ( ! $release ) {
			return $result;
		}

		$update  = $this->build_plugin_update_object( $release );
		$headers = $this->get_headers();

		return (object) array(
			'name'          => $this->get_name(),
			'slug'          => $this->slug,
			'version'       => $release['version'],
			'author'        => $this->get_author_html(),
			'homepage'      => $update->url,
			'requires'      => $update->requires,
			'requires_php'  => $update->requires_php,
			'tested'        => $update->tested,
			'last_updated'  => ! empty( $release['published_at'] ) ? $release['published_at'] : '',
			'download_link' => $update->package,
			'sections'      => array(
				'description' => ! empty( $headers['description'] ) ? $headers['description'] : '',
				'changelog'   => $this->format_changelog( $release['notes'] ),
			),
		);
	}

	/**
	 * プラグイン更新完了後にキャッシュを掃除する.
	 *
	 * @param WP_Upgrader $upgrader Upgrader インスタンス（未使用）.
	 * @param array       $options  更新処理の内容.
	 * @return void
	 */
	public function after_update( $upgrader, $options ) {
		if ( ! isset( $options['action'], $options['type'] ) || 'update' !== $options['action'] || 'plugin' !== $options['type'] ) {
			return;
		}

		if ( empty( $options['plugins'] ) || ! in_array( $this->get_basename(), $options['plugins'], true ) ) {
			return;
		}

		delete_site_transient( $this->cache_key );
		delete_site_transient( 'update_plugins' );
	}

	/**
	 * ZIP 内のルートディレクトリ名がスラッグと異なる場合にリネームする.
	 *
	 * 通常は build-zip.sh の規約(zip 内ルートディレクトリ名 = スラッグ)により
	 * 発生しないが、他人が作った zip 命名にも耐えるための多層防御として追加する。
	 * extract_zip_url() の fail closed(スラッグ前方一致必須)が第一の防壁で、
	 * これは第二の防壁。$hook_extra['plugin'] が自分の basename のときだけ
	 * 働かせ、他プラグインの更新を壊さないようにする.
	 *
	 * @param string|WP_Error $source        リネーム前のソースディレクトリパス.
	 * @param string          $remote_source  展開直後のリモートソースパス(未使用).
	 * @param WP_Upgrader     $upgrader       Upgrader インスタンス(未使用).
	 * @param array           $hook_extra     hook_extra 配列.
	 * @return string|WP_Error リネーム後のパス、対象外ならそのまま $source.
	 */
	public function rename_source_directory( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->get_basename() ) {
			return $source;
		}

		$desired_source = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $this->slug . '/';

		if ( $source === $desired_source ) {
			return $source;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem->move( $source, $desired_source, true ) ) {
			return new WP_Error(
				'l2dwpghul_rename_failed',
				sprintf( 'ディレクトリ名を %s に変更できませんでした.', $this->slug )
			);
		}

		return $desired_source;
	}

	/**
	 * プライベートリポジトリのパッケージを認証ヘッダー付きでダウンロードする.
	 *
	 * WordPress コアの download_url()(内部の wp_safe_remote_get())には
	 * 認証ヘッダーを渡す手段が無いため、upgrader_pre_download で false 以外
	 * を返してダウンロード処理自体をショートサーキットし、自前で
	 * Authorization ヘッダー付きの取得を行う。$package には GitHub Releases
	 * の Assets API URL(https://api.github.com/repos/{owner}/{repo}/releases/assets/{id})
	 * が入っている前提(extract_zip_url() が token 設定時にこの形式を選ぶ)。
	 * これは Step A8 でプライベートテストリポジトリを使い実機確認した設計:
	 * - browser_download_url に Authorization ヘッダーを付けても 404 になる
	 *   (github.com の配布ドメインは Bearer トークンを認識しない)
	 * - Assets API URL に Accept: application/octet-stream を付けると
	 *   ファイル本体が取得できる
	 *
	 * @param false|string|WP_Error $reply      既定の戻り値(通常 false).
	 * @param string                $package    パッケージ URI.
	 * @param WP_Upgrader           $upgrader   Upgrader インスタンス(未使用).
	 * @param array                 $hook_extra hook_extra 配列.
	 * @return false|string|WP_Error ダウンロード済み一時ファイルのパス、
	 *         対象外なら false、失敗時は WP_Error.
	 */
	public function pre_download_package( $reply, $package, $upgrader, $hook_extra ) {
		if ( false !== $reply ) {
			return $reply;
		}

		if ( '' === $this->token ) {
			return false;
		}

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->get_basename() ) {
			return false;
		}

		$tmpfname = wp_tempnam( $this->slug );

		if ( ! $tmpfname ) {
			return new WP_Error( 'l2dwpghul_no_tempfile', '一時ファイルを作成できませんでした.' );
		}

		$response = $this->http_get(
			$package,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmpfname,
				'headers'  => array(
					'Authorization' => 'Bearer ' . $this->token,
					'Accept'        => 'application/octet-stream',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error(
				'l2dwpghul_download_failed',
				sprintf( 'パッケージのダウンロードに失敗しました(HTTP %d).', wp_remote_retrieve_response_code( $response ) )
			);
		}

		return $tmpfname;
	}

	/**
	 * GitHub の最新リリース情報を取得する（サイトトランジェントでキャッシュ）.
	 *
	 * キャッシュの値が空配列(array())ならバックオフ中とみなし、`version` が
	 * 欠損した壊れた配列は有効なキャッシュとして扱わない.
	 *
	 * @return array{version:string,zip_url:string,notes:string,published_at:string}|null
	 *         取得できたリリース情報。取得不可なら null.
	 */
	private function fetch_latest_release() {
		if ( ! $this->is_enabled() ) {
			return null;
		}

		$cached = get_site_transient( $this->cache_key );

		if ( array() === $cached ) {
			return null;
		}

		if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
			return $cached;
		}

		$release = $this->request_latest_release();

		if ( null === $release ) {
			set_site_transient( $this->cache_key, array(), $this->get_backoff_ttl() );
			return null;
		}

		set_site_transient( $this->cache_key, $release, $this->get_cache_ttl() );

		return $release;
	}

	/**
	 * GitHub API から最新リリースを取得し、正規化した配列にして返す.
	 *
	 * @return array{version:string,zip_url:string,notes:string,published_at:string}|null
	 *         取得・解析に成功した場合のみ配列.
	 */
	private function request_latest_release() {
		$url = $this->allow_prerelease
			? sprintf( 'https://api.github.com/repos/%s/releases', $this->github_repo )
			: sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->github_repo );

		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'l2d-wp-github-update-lib/' . $this->slug,
		);

		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = $this->http_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$release_data = $this->allow_prerelease
			? $this->pick_first_non_draft_release( $body )
			: $body;

		if ( ! is_array( $release_data ) || empty( $release_data['tag_name'] ) ) {
			return null;
		}

		$zip_url = self::extract_zip_url( $release_data, $this->asset_pattern, '' !== $this->token );
		if ( ! $zip_url ) {
			return null;
		}

		return array(
			'version'      => self::normalize_version( $release_data['tag_name'] ),
			'zip_url'      => $zip_url,
			'notes'        => isset( $release_data['body'] ) ? (string) $release_data['body'] : '',
			'published_at' => isset( $release_data['published_at'] ) ? (string) $release_data['published_at'] : '',
		);
	}

	/**
	 * /releases 一覧(GitHub API は公開日時降順で返す)から、draft でない
	 * 最初のリリースを選ぶ. allow_prerelease が true のときだけ使う経路
	 * (/releases/latest は draft・prerelease をどちらも除外するが、
	 * こちらは prerelease を許可しつつ draft だけは除外する必要がある。
	 * Step A7 で実機確認済み).
	 *
	 * @param mixed $releases /releases のレスポンスボディ.
	 * @return array|null
	 */
	private function pick_first_non_draft_release( $releases ) {
		if ( ! is_array( $releases ) ) {
			return null;
		}

		foreach ( $releases as $release ) {
			if ( is_array( $release ) && empty( $release['draft'] ) ) {
				return $release;
			}
		}

		return null;
	}

	/**
	 * GitHub API への HTTP リクエストを行う. テストではこのメソッドをサブクラスで
	 * オーバーライドして固定レスポンスを返す.
	 *
	 * @param string $url  リクエスト URL.
	 * @param array  $args wp_remote_get() に渡す引数.
	 * @return array|WP_Error wp_remote_get() と同じ形式のレスポンス.
	 */
	protected function http_get( $url, $args ) {
		return wp_remote_get( $url, $args );
	}

	/**
	 * GitHub Release のタグ名からバージョン番号を抽出する.
	 *
	 * 先頭の "v"/"V" のみを取り除く（ltrim と異なり "vv1.0" のような文字集合の
	 * 誤った除去をしない）.
	 *
	 * @param string $tag GitHub のタグ名.
	 * @return string 正規化したバージョン文字列.
	 */
	public static function normalize_version( $tag ) {
		return preg_replace( '/^v/i', '', (string) $tag );
	}

	/**
	 * リリースのアセット一覧から配布用 ZIP の URL を選ぶ.
	 *
	 * GitHub 自動生成の zipball はディレクトリ名がプラグインスラッグと一致せず
	 * プラグインディレクトリを壊すため、フォールバックはしない（見つからなければ null）.
	 *
	 * $use_api_url が true のときは asset.url(Assets API のエンドポイント
	 * URL)を返す。プライベートリポジトリの asset.browser_download_url は
	 * Authorization ヘッダーを付けても取得できず(github.com の配布ドメインは
	 * Bearer トークンを認識しない)、Assets API + Accept: application/octet-stream
	 * が必要なことを Step A8 で実機確認した.
	 *
	 * @param array           $body        GitHub API のレスポンスボディ（デコード済み連想配列）.
	 * @param string|callable $pattern     文字列なら前方一致文字列として扱い、
	 *                                     callable なら $name を渡した戻り値の真偽で判定する.
	 * @param bool            $use_api_url true なら asset.url を、false なら
	 *                                     asset.browser_download_url を返す.
	 * @return string|null 見つかった ZIP の URL。見つからなければ null.
	 */
	public static function extract_zip_url( $body, $pattern, $use_api_url = false ) {
		foreach ( (array) ( isset( $body['assets'] ) ? $body['assets'] : array() ) as $asset ) {
			$url_field    = $use_api_url ? 'url' : 'browser_download_url';
			$download_url = isset( $asset[ $url_field ] ) ? $asset[ $url_field ] : '';

			if ( empty( $download_url ) ) {
				continue;
			}

			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';

			$matches = is_callable( $pattern )
				? (bool) call_user_func( $pattern, $name )
				: ( 0 === strpos( $name, (string) $pattern ) && '.zip' === substr( $name, -4 ) );

			if ( $matches ) {
				return $download_url;
			}
		}

		return null;
	}

	/**
	 * 更新情報オブジェクトを組み立てて update_plugins / no_update トランジェントに登録する.
	 *
	 * ハードコードせず、requires / requires_php / tested はプラグインヘッダーから読む.
	 *
	 * @param array{version:string,zip_url:string} $release fetch_latest_release() が返す配列.
	 * @return stdClass 更新情報オブジェクト.
	 */
	private function build_plugin_update_object( array $release ) {
		$headers = $this->get_headers();

		$update                = new stdClass();
		$update->id            = 'github.com/' . $this->github_repo;
		$update->slug          = $this->slug;
		$update->plugin        = $this->get_basename();
		$update->new_version   = $release['version'];
		$update->url           = 'https://github.com/' . $this->github_repo;
		$update->package       = $release['zip_url'];
		$update->requires      = $headers['requires'];
		$update->requires_php  = $headers['requires_php'];
		$update->tested        = $headers['tested'];
		$update->icons         = array();
		$update->banners       = array();
		$update->banners_rtl   = array();
		$update->compatibility = new stdClass();

		return $update;
	}

	/**
	 * リリースノートを「詳細を表示」モーダル用の HTML に整形する.
	 *
	 * @param string $notes GitHub Release の本文.
	 * @return string HTML 文字列.
	 */
	private function format_changelog( $notes ) {
		if ( '' === (string) $notes ) {
			return '<p>' . esc_html__( 'GitHub リリースページをご確認ください。', 'l2d-wp-github-update-lib' ) . '</p>';
		}

		return '<pre style="white-space:pre-wrap;font-family:inherit;">' . esc_html( $notes ) . '</pre>';
	}

	/**
	 * キルスイッチ用の enabled フィルタを適用する. filter_prefix があれば旧名も適用する.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		$enabled = apply_filters( 'l2dwpghul_updater_enabled', true, $this->slug );
		if ( $this->filter_prefix ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- 後方互換用. フィルタ名自体は利用側の filter_prefix 由来.
			$enabled = apply_filters( "{$this->filter_prefix}_github_updater_enabled", $enabled );
		}
		return (bool) $enabled;
	}

	/**
	 * キャッシュ TTL 用の cache_ttl フィルタを適用する. filter_prefix があれば旧名も適用する.
	 *
	 * @return int
	 */
	private function get_cache_ttl() {
		$ttl = apply_filters( 'l2dwpghul_updater_cache_ttl', $this->cache_ttl, $this->slug );
		if ( $this->filter_prefix ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- 後方互換用. フィルタ名自体は利用側の filter_prefix 由来.
			$ttl = apply_filters( "{$this->filter_prefix}_github_updater_cache_ttl", $ttl );
		}
		return (int) $ttl;
	}

	/**
	 * バックオフ TTL 用の backoff_ttl フィルタを適用する. filter_prefix があれば旧名も適用する.
	 *
	 * @return int
	 */
	private function get_backoff_ttl() {
		$ttl = apply_filters( 'l2dwpghul_updater_backoff_ttl', $this->backoff_ttl, $this->slug );
		if ( $this->filter_prefix ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- 後方互換用. フィルタ名自体は利用側の filter_prefix 由来.
			$ttl = apply_filters( "{$this->filter_prefix}_github_updater_backoff_ttl", $ttl );
		}
		return (int) $ttl;
	}

	/**
	 * このプラグインの basename を plugin_file から導出する.
	 *
	 * @return string
	 */
	private function get_basename() {
		return plugin_basename( $this->plugin_file );
	}

	/**
	 * プラグインヘッダーを取得する(plugin_file から初回のみ読み込み、以降はメモ化).
	 *
	 * @return array{name:string,version:string,requires:string,requires_php:string,tested:string,author:string,author_uri:string,description:string}
	 */
	private function get_headers() {
		if ( null === $this->headers ) {
			$this->headers = get_file_data(
				$this->plugin_file,
				array(
					'name'         => 'Plugin Name',
					'version'      => 'Version',
					'requires'     => 'Requires at least',
					'requires_php' => 'Requires PHP',
					'tested'       => 'Tested up to',
					'author'       => 'Author',
					'author_uri'   => 'Author URI',
					'description'  => 'Description',
				)
			);
		}

		return $this->headers;
	}

	/**
	 * 表示名を返す($config['name'] があればそれを優先する).
	 *
	 * @return string
	 */
	private function get_name() {
		if ( null !== $this->name_override ) {
			return $this->name_override;
		}
		return $this->get_headers()['name'];
	}

	/**
	 * 作者の HTML を返す($config['author'] があればそれを優先する).
	 *
	 * Author URI ヘッダーがあればリンク化する.
	 *
	 * @return string
	 */
	private function get_author_html() {
		if ( null !== $this->author_override ) {
			return $this->author_override;
		}

		$headers = $this->get_headers();
		if ( ! empty( $headers['author_uri'] ) ) {
			return sprintf( '<a href="%s">%s</a>', esc_url( $headers['author_uri'] ), esc_html( $headers['author'] ) );
		}

		return $headers['author'];
	}
}
