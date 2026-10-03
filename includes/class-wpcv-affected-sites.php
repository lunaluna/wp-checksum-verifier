<?php
/**
 * WPCV_Affected_Sites クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * マルチサイトで、ある plugin / theme の finding が「どのサイトに影響するか」を求める
 * (v0.9プラン §3.2).
 *
 * ファイルはネットワーク全体で共有なので、finding はサイトに属さない. このクラスは、運用者が
 * 「そのプラグイン・テーマを有効にしているサイト」を知るための**表示専用**の情報を、検出結果の
 * 画面を描くときに計算する. run の中では計算しない(Planner は HTTP・DB に触れない不変条件があり、
 * 全サイトの `switch_to_blog()` で run が遅くなるのも避けるため). schema は変えない.
 *
 * - plugin: ネットワーク有効(`active_sitewide_plugins`)なら「ネットワーク全体」. そうでなければ、
 *   各サイトの `active_plugins` に入っているサイト.
 * - theme: 各サイトの有効テーマ(`stylesheet`)がそのテーマのサイト、親テーマ(`template`)として使って
 *   いるサイト. どちらでもなくても、ネットワークで許可(`allowedthemes`)されていれば、その旨を返す.
 * - 単一サイト・plugin / theme 以外(core・mu-plugin・drop-in)は対象外.
 *
 * 読み取りは `switch_to_blog()` + `get_option()`(1サイトあたり1クエリ). 直接クエリは約4倍速かった
 * (alpine-dealer.local・20 サイトで 4.4 ms 対 15〜20 ms. 2026-10-03)が、`option_*` フィルターと
 * 分割DB構成(HyperDB 等)を迂回するため、標準の API を使う.
 *
 * サイト数が `SCAN_LIMIT` を超えるネットワークでは走査せず、「大規模のため表示しない」を返す.
 * 結果は同じリクエストの中でだけ使い回す(別リクエストにはキャッシュしない: 走査は 1 ms/サイト
 * 程度で、有効化・無効化への追従〔transient の無効化〕が要らなくなる).
 */
class WPCV_Affected_Sites {

	/**
	 * 走査するサイト数の上限の既定値(`wpcv_affected_sites_scan_limit` で変えられる).
	 *
	 * 未実測(暫定値): 実測は alpine-dealer.local の 20 サイトで 1 サイトあたり 0.8〜1.0 ms(1 回の測定).
	 * その外挿で 500 サイトは約 0.5 秒(検出結果の画面の描画に足される). 大きなネットワークで測り直すこと.
	 *
	 * @var int
	 */
	const SCAN_LIMIT = 500;

	/**
	 * 状態: 対象外(単一サイト、または plugin / theme 以外).
	 */
	const STATE_NOT_APPLICABLE = 'not_applicable';

	/**
	 * 状態: ネットワーク全体(plugin がネットワーク有効).
	 */
	const STATE_NETWORK = 'network';

	/**
	 * 状態: 1つ以上のサイトで有効.
	 */
	const STATE_SITES = 'sites';

	/**
	 * 状態: どのサイトでも有効ではない(ディスク上にあるだけ).
	 */
	const STATE_NONE = 'none';

	/**
	 * 状態: サイト数が上限を超えたため走査していない.
	 */
	const STATE_UNAVAILABLE = 'unavailable';

	/**
	 * 関係: そのサイトの有効なプラグイン・有効なテーマ.
	 */
	const RELATION_ACTIVE = 'active';

	/**
	 * 関係: そのサイトの有効な子テーマの親テーマ.
	 */
	const RELATION_PARENT = 'parent';

	/**
	 * スナップショットを返す callable(テストで注入する). 省略時は `collect_snapshot()`.
	 *
	 * @var callable
	 */
	private $snapshot_reader;

	/**
	 * 同じリクエストの中で使い回すスナップショット.
	 *
	 * @var array|null
	 */
	private $snapshot = null;

	/**
	 * コンストラクタ.
	 *
	 * @param callable|null $snapshot_reader スナップショットを返す callable. 省略時は実際のネットワークを走査する.
	 */
	public function __construct( ?callable $snapshot_reader = null ) {
		$this->snapshot_reader = $snapshot_reader ?? array( __CLASS__, 'collect_snapshot' );
	}

	/**
	 * 1つの finding の target(dimension・slug)について、影響するサイトを返す.
	 *
	 * @param string $dimension `plugin` / `theme` など.
	 * @param string $slug      slug(プラグインはディレクトリ名、テーマは stylesheet).
	 * @return array{state: string, sites: array<int, array{blog_id: int, name: string, url: string, relation: string}>, network_enabled: bool, total_sites: int}
	 */
	public function describe( $dimension, $slug ) {
		$empty = array(
			'state'           => self::STATE_NOT_APPLICABLE,
			'sites'           => array(),
			'network_enabled' => false,
			'total_sites'     => 0,
		);

		if ( ! is_multisite() || ! in_array( $dimension, array( WPCV_Target_Resolver::DIMENSION_PLUGIN, WPCV_Target_Resolver::DIMENSION_THEME ), true ) ) {
			return $empty;
		}

		if ( null === $this->snapshot ) {
			$this->snapshot = (array) call_user_func( $this->snapshot_reader );
		}

		return self::describe_from_snapshot( $this->snapshot, (string) $dimension, (string) $slug );
	}

	/**
	 * スナップショットから、1つの target の影響サイトを求める(DB に触れない純粋な判定).
	 *
	 * @param array  $snapshot  `collect_snapshot()` の戻り値と同じ形.
	 * @param string $dimension `plugin` / `theme`.
	 * @param string $slug      slug.
	 * @return array{state: string, sites: array<int, array{blog_id: int, name: string, url: string, relation: string}>, network_enabled: bool, total_sites: int}
	 */
	public static function describe_from_snapshot( array $snapshot, $dimension, $slug ) {
		$total  = (int) ( $snapshot['total_sites'] ?? 0 );
		$result = array(
			'state'           => self::STATE_NONE,
			'sites'           => array(),
			'network_enabled' => false,
			'total_sites'     => $total,
		);

		if ( ! empty( $snapshot['too_large'] ) ) {
			$result['state'] = self::STATE_UNAVAILABLE;

			return $result;
		}

		if ( WPCV_Target_Resolver::DIMENSION_PLUGIN === $dimension && isset( $snapshot['sitewide_plugins'][ $slug ] ) ) {
			$result['state'] = self::STATE_NETWORK;

			return $result;
		}

		if ( WPCV_Target_Resolver::DIMENSION_THEME === $dimension ) {
			$result['network_enabled'] = isset( $snapshot['allowed_themes'][ $slug ] );
		}

		foreach ( (array) ( $snapshot['sites'] ?? array() ) as $blog_id => $site ) {
			$relation = self::relation_for( $site, $dimension, $slug );

			if ( null === $relation ) {
				continue;
			}

			$result['sites'][] = array(
				'blog_id'  => (int) $blog_id,
				'name'     => (string) ( $site['name'] ?? '' ),
				'url'      => (string) ( $site['url'] ?? '' ),
				'relation' => $relation,
			);
		}

		if ( ! empty( $result['sites'] ) ) {
			$result['state'] = self::STATE_SITES;
		}

		return $result;
	}

	/**
	 * 1サイトが、指定した target とどういう関係にあるかを返す.
	 *
	 * @param array  $site      スナップショットの1サイト分(`plugins`・`stylesheet`・`template`).
	 * @param string $dimension `plugin` / `theme`.
	 * @param string $slug      slug.
	 * @return string|null `RELATION_*`. 関係が無ければ `null`.
	 */
	private static function relation_for( array $site, $dimension, $slug ) {
		if ( WPCV_Target_Resolver::DIMENSION_PLUGIN === $dimension ) {
			return isset( $site['plugins'][ $slug ] ) ? self::RELATION_ACTIVE : null;
		}

		if ( ( $site['stylesheet'] ?? null ) === $slug ) {
			return self::RELATION_ACTIVE;
		}

		// 子テーマが有効なサイトでは、親テーマもファイルとして使われている(親テーマは template の値).
		if ( ( $site['template'] ?? null ) === $slug ) {
			return self::RELATION_PARENT;
		}

		return null;
	}

	/**
	 * ネットワークを走査してスナップショットを作る.
	 *
	 * 作らないもの: 削除済み・スパムのサイト(公開されていない). アーカイブ済みは含める(表示は
	 * 参考情報であり、有効化の状態はそのサイトに残っているため).
	 *
	 * @return array{total_sites: int, too_large: bool, sitewide_plugins: array<string, bool>, allowed_themes: array<string, bool>, sites: array<int, array{name: string, url: string, plugins: array<string, bool>, stylesheet: string, template: string}>}
	 */
	public static function collect_snapshot() {
		$limit = max( 1, (int) apply_filters( 'wpcv_affected_sites_scan_limit', self::SCAN_LIMIT ) );

		$query = array(
			'deleted' => 0,
			'spam'    => 0,
		);

		$total    = (int) get_sites( array_merge( $query, array( 'count' => true ) ) );
		$snapshot = array(
			'total_sites'      => $total,
			'too_large'        => $total > $limit,
			'sitewide_plugins' => array(),
			'allowed_themes'   => array(),
			'sites'            => array(),
		);

		if ( $snapshot['too_large'] ) {
			return $snapshot;
		}

		foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $plugin_file ) {
			$snapshot['sitewide_plugins'][ self::slug_of_plugin_file( (string) $plugin_file ) ] = true;
		}

		foreach ( array_keys( (array) get_site_option( 'allowedthemes', array() ) ) as $stylesheet ) {
			$snapshot['allowed_themes'][ (string) $stylesheet ] = true;
		}

		$site_ids = get_sites(
			array_merge(
				$query,
				array(
					'fields'  => 'ids',
					'number'  => $limit,
					'orderby' => 'id',
					'order'   => 'ASC',
				)
			)
		);

		foreach ( $site_ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );

			$plugins = array();

			foreach ( (array) get_option( 'active_plugins', array() ) as $plugin_file ) {
				$plugins[ self::slug_of_plugin_file( (string) $plugin_file ) ] = true;
			}

			$snapshot['sites'][ (int) $blog_id ] = array(
				'name'       => (string) get_option( 'blogname', '' ),
				'url'        => home_url( '/' ),
				'plugins'    => $plugins,
				'stylesheet' => (string) get_option( 'stylesheet', '' ),
				'template'   => (string) get_option( 'template', '' ),
			);

			restore_current_blog();
		}

		return $snapshot;
	}

	/**
	 * `active_plugins` のプラグインファイル(`dir/file.php`)から、target の slug を求める.
	 * Planner が target を作るときと同じ規則を使う(`WPCV_Run_Planner::resolve_plugin_slug_and_root()`).
	 *
	 * @param string $plugin_file `active_plugins` の1要素.
	 * @return string
	 */
	private static function slug_of_plugin_file( $plugin_file ) {
		$resolved = WPCV_Run_Planner::resolve_plugin_slug_and_root( $plugin_file, '' );

		return (string) $resolved['slug'];
	}
}
