<?php
/**
 * WPCV_API クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * §10 で定義する Public API(WPMAR 連携用)の実装.
 *
 * 固定するのは Public API contract(戻り値配列のキー)のみ。DB スキーマは
 * WPCV_DB_VERSION による migration の内部実装詳細であり、この契約には含まない
 * (§5.1)。返り値のキーは削除・改名しないこと。追加は許容する.
 */
class WPCV_API {

	/**
	 * 最新の実行サマリを取得する.
	 *
	 * @return array|null run 行(status, finished_at, 各カウント). run が1件も無ければ null.
	 */
	public static function get_latest_run() {
		global $wpdb;

		$table = $wpdb->base_prefix . 'wpcv_runs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * 検証対象(target)単位の検証結果を取得する(最新の run に属するもの). unverifiable の理由を含む.
	 *
	 * @param array $args {
	 *     絞り込み条件.
	 *
	 *     @type array $dimension core|plugin|theme|muplugin.
	 *     @type array $status    success|unverifiable|failed|skipped|retried.
	 *     @type int   $limit     既定 0(無制限).
	 * }
	 * @return array target_runs 配列(§5.3 のスキーマに準拠). 対象の run が無ければ空配列.
	 */
	public static function get_latest_target_runs( $args = array() ) {
		global $wpdb;

		$latest_run = self::get_latest_run();
		if ( null === $latest_run ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'dimension' => array(),
				'status'    => array(),
				'limit'     => 0,
			)
		);

		$table  = $wpdb->base_prefix . 'wpcv_target_runs';
		$where  = array( 'run_id = %d' );
		$params = array( (int) $latest_run['id'] );

		self::add_in_clause( $where, $params, 'dimension', $args['dimension'] );
		self::add_in_clause( $where, $params, 'status', $args['status'] );

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';

		if ( $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = (int) $args['limit'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from a fixed set of static clauses (table/column names we control) plus %s/%d placeholders; the sniff cannot trace $sql back through prepare() when it is assembled across multiple lines, but every value still passes through $wpdb->prepare() below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		/**
		 * 取得した target_runs をフィルタする(§10).
		 *
		 * @param array $rows target_runs 配列.
		 * @param array $args 呼び出し時に渡された引数(デフォルト適用後).
		 */
		return apply_filters( 'wpcv_api_target_runs', $rows, $args );
	}

	/**
	 * 最新の検証結果(findings)を取得する.
	 *
	 * @param array $args {
	 *     絞り込み条件.
	 *
	 *     @type array $dimension           core|plugin|theme|muplugin.
	 *     @type array $status              modified|added|missing|unreadable.
	 *     @type array $severity            high|medium|low.
	 *     @type bool  $include_suppressed  既定 false.
	 *     @type bool  $include_closed      既定 false.
	 *     @type int   $limit               既定 0(無制限).
	 * }
	 * @return array findings 配列(§5.5 のスキーマに準拠). 対象の run が無ければ空配列.
	 */
	public static function get_latest_findings( $args = array() ) {
		global $wpdb;

		$latest_run = self::get_latest_run();
		if ( null === $latest_run ) {
			return array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'dimension'          => array(),
				'status'             => array(),
				'severity'           => array(),
				'include_suppressed' => false,
				'include_closed'     => false,
				'limit'              => 0,
			)
		);

		$table  = $wpdb->base_prefix . 'wpcv_findings';
		$where  = array( 'run_id = %d' );
		$params = array( (int) $latest_run['id'] );

		self::add_in_clause( $where, $params, 'dimension', $args['dimension'] );
		self::add_in_clause( $where, $params, 'status', $args['status'] );
		self::add_in_clause( $where, $params, 'severity', $args['severity'] );

		if ( ! $args['include_suppressed'] ) {
			$where[] = 'suppressed_by IS NULL';
		}

		if ( ! $args['include_closed'] ) {
			$where[] = 'closed_at IS NULL';
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';

		if ( $args['limit'] > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = (int) $args['limit'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is built from a fixed set of static clauses (table/column names we control) plus %s/%d placeholders; the sniff cannot trace $sql back through prepare() when it is assembled across multiple lines, but every value still passes through $wpdb->prepare() below.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		/**
		 * 取得した findings をフィルタする(§10).
		 *
		 * @param array $rows findings 配列.
		 * @param array $args 呼び出し時に渡された引数(デフォルト適用後).
		 */
		return apply_filters( 'wpcv_api_findings', $rows, $args );
	}

	/**
	 * プラグインが利用可能かを返す(WPMAR の依存チェック用).
	 *
	 * スキーマが WPCV_DB_VERSION まで migration 済みであることを「利用可能」の
	 * 条件とする. 有効化直後で migration が未完了の一瞬を除き、通常は常に true.
	 *
	 * マルチサイト対応の読み取り(`wp_sitemeta` の site option を正とする)は
	 * `WPCV_Migrator::get_stored_version()` に一本化してある(v0.3.1 §Step5。
	 * 以前はここで `get_option()` を直接読んでおり、マルチサイトで
	 * `WPCV_Migrator::maybe_upgrade()` とは別に同種のバグを抱えていた).
	 *
	 * @return bool
	 */
	public static function is_available() {
		return WPCV_Migrator::get_stored_version() >= WPCV_DB_VERSION;
	}

	/**
	 * `$column IN (%s, %s, ...)` 形式の WHERE 句を追加する(値が空なら何もしない).
	 *
	 * @param string[] $where  WHERE 句の配列(参照渡し).
	 * @param array    $params プレースホルダに束縛する値の配列(参照渡し).
	 * @param string   $column 列名(呼び出し側の固定値のみを渡すこと. ユーザー入力を渡さない).
	 * @param mixed    $values 値(スカラーまたは配列). 空配列・空文字なら何もしない.
	 * @return void
	 */
	private static function add_in_clause( array &$where, array &$params, $column, $values ) {
		$is_not_empty_string = static function ( $value ) {
			return '' !== $value;
		};
		$values              = array_filter( (array) $values, $is_not_empty_string );

		if ( empty( $values ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
		$where[]      = "{$column} IN ({$placeholders})";

		foreach ( $values as $value ) {
			$params[] = (string) $value;
		}
	}
}
