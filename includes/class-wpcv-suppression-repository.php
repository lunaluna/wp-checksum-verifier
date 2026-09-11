<?php
/**
 * WPCV_Suppression_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_suppressions` テーブルの永続化を担当する(v0.4.0 §Step8).
 *
 * `WPCV_Target_Run_Repository`/`WPCV_Run_Repository` と同じ設計方針を踏襲する:
 * - 現在時刻は `$now` callable経由(テストで固定時刻を注入できるようにするため).
 * - テストダブル(`WPCV_Test_Fake_WPDB::get_results()`)がWHERE句を解釈しないため、
 *   絞り込みは常に「全行取得してPHPで判定する」方式にする(`all_rows()`)。
 *   抑制ルールは運用上せいぜい数百件程度であり、target_runsと同じ判断で問題ない.
 *
 * 実際のマッチング判定(glob展開・hash/version一致)は本クラスの責務にせず
 * `WPCV_Suppression_Matcher` に分離する(本クラスは「有効なルールをDBから
 * 取り出す」ことだけを担当し、判定ロジックを混在させない).
 */
class WPCV_Suppression_Repository {

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `update()` / `get_results()` /
	 * `base_prefix` / `insert_id` を持つもの).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * 現在時刻(UTC の MySQL DATETIME 文字列)を返す callable.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * コンストラクタ.
	 *
	 * @param object        $wpdb `$wpdb` 相当のオブジェクト.
	 * @param callable|null $now  現在時刻を返す callable. 省略時は `gmdate( 'Y-m-d H:i:s' )`.
	 */
	public function __construct( $wpdb, ?callable $now = null ) {
		$this->wpdb = $wpdb;
		$this->now  = $now ?? static function () {
			return gmdate( 'Y-m-d H:i:s' );
		};
	}

	/**
	 * 指定した dimension/slug に対する有効な `exclude_target` ルールを1件返す
	 * (`WPCV_Run_Planner::plan()` が target 列挙時点で使う).
	 *
	 * @param string $dimension 対象の dimension.
	 * @param string $slug      対象の slug.
	 * @return array|null 見つからなければ null.
	 */
	public function find_active_exclude_target_rule( $dimension, $slug ) {
		foreach ( $this->all_rows() as $row ) {
			if ( null !== $row['expired_at'] ) {
				continue;
			}

			if ( WPCV_Suppression_Type::EXCLUDE_TARGET !== $row['type'] ) {
				continue;
			}

			if ( (string) $dimension === (string) $row['dimension'] && (string) $slug === (string) $row['slug'] ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * 指定した dimension/slug に対する有効な `exclude_path`/`allowlist_hash` ルールを
	 * type別にまとめて返す(`WPCV_Chunk_Result_Repository::commit_chunk()` が
	 * finding単位のマッチングの直前に1回だけ呼ぶ).
	 *
	 * @param string $dimension 対象の dimension.
	 * @param string $slug      対象の slug.
	 * @return array{exclude_path: array, allowlist_hash: array}
	 */
	public function find_active_rules_for_target( $dimension, $slug ) {
		$result = array(
			'exclude_path'   => array(),
			'allowlist_hash' => array(),
		);

		foreach ( $this->all_rows() as $row ) {
			if ( null !== $row['expired_at'] ) {
				continue;
			}

			if ( (string) $dimension !== (string) $row['dimension'] || (string) $slug !== (string) $row['slug'] ) {
				continue;
			}

			if ( WPCV_Suppression_Type::EXCLUDE_PATH === $row['type'] ) {
				$result['exclude_path'][] = $row;
			} elseif ( WPCV_Suppression_Type::ALLOWLIST_HASH === $row['type'] ) {
				$result['allowlist_hash'][] = $row;
			}
		}

		return $result;
	}

	/**
	 * 抑制ルールを1件新規作成する(v0.4.0 §Step9の管理画面から呼ばれる想定).
	 *
	 * @param array $data {
	 *     登録する抑制ルールの内容.
	 *
	 *     @type string      $type           `WPCV_Suppression_Type` のいずれか. 必須.
	 *     @type string|null $dimension      対象の dimension.
	 *     @type string|null $slug           対象の slug.
	 *     @type string|null $pattern        `exclude_path`/`allowlist_hash` の対象パス(glob).
	 *     @type string|null $expected_hash  `allowlist_hash` の承認する hash 値.
	 *     @type string|null $hash_algorithm `allowlist_hash` の hash アルゴリズム.
	 *     @type string|null $version        `allowlist_hash` の対象 target version.
	 *     @type string      $reason         登録理由. 必須.
	 *     @type int         $created_by     登録した user id. 必須.
	 * }
	 * @return int 新規作成した行の id.
	 *
	 * @throws InvalidArgumentException `type`/`reason` が指定されていない場合.
	 */
	public function insert( array $data ) {
		if ( empty( $data['type'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Suppression_Repository::insert() requires $data[\'type\'].' ) );
		}

		if ( empty( $data['reason'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Suppression_Repository::insert() requires $data[\'reason\'].' ) );
		}

		$table = $this->wpdb->base_prefix . 'wpcv_suppressions';

		$row = array(
			'type'           => (string) $data['type'],
			'dimension'      => isset( $data['dimension'] ) ? (string) $data['dimension'] : null,
			'slug'           => isset( $data['slug'] ) ? (string) $data['slug'] : null,
			'pattern'        => isset( $data['pattern'] ) ? (string) $data['pattern'] : null,
			'expected_hash'  => isset( $data['expected_hash'] ) ? (string) $data['expected_hash'] : null,
			'hash_algorithm' => isset( $data['hash_algorithm'] ) ? (string) $data['hash_algorithm'] : null,
			'version'        => isset( $data['version'] ) ? (string) $data['version'] : null,
			'reason'         => (string) $data['reason'],
			'created_by'     => isset( $data['created_by'] ) ? (int) $data['created_by'] : 0,
			'created_at'     => call_user_func( $this->now ),
			// テストダブル(`WPCV_Test_Fake_WPDB::insert()`)は渡した連想配列を
			// そのまま行として保持するため、後で参照する列は未指定でも明示的に
			// null をセットしてキー自体を作っておく(`all_rows()` 経由の判定で
			// `$row['expired_at']` に触れる箇所が undefined index にならないため.
			// 本番の `$wpdb->insert()` でも NULL 列を明示指定するのと同じ扱い).
			'expired_at'     => null,
			'expired_reason' => null,
		);

		$format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' );

		$this->wpdb->insert( $table, $row, $format );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * 抑制ルールを1件失効させる(v0.4.0 §Step9の「取消」操作から呼ばれる想定).
	 *
	 * 既に失効済み(`expired_at` が非NULL)の行は対象外にする(二重失効の防止)。
	 * `WHERE expired_at IS NULL` をCAS条件に含めない(read-then-write)理由:
	 * テストダブル(`WPCV_Test_Fake_WPDB::update()`)の `WHERE` 判定が
	 * 厳密等価比較のみのため、`null` を含む条件を安全に表現できない
	 * (`WPCV_Target_Run_Repository::claim_next()` 等のCASは非NULL値のみを
	 * 条件にしている)。失効操作は運用者による手動操作であり、
	 * run/target_runのworker競合ほど厳密な排他は不要と判断した.
	 *
	 * @param int    $id     対象の行の id.
	 * @param string $reason 失効理由(自由文字列。24文字までに切り詰める).
	 * @return bool 更新できたら true。既に失効済み・対象行が無ければ false.
	 */
	public function expire( $id, $reason ) {
		$row = $this->find_by_id( $id );

		if ( null === $row || null !== $row['expired_at'] ) {
			return false;
		}

		$table = $this->wpdb->base_prefix . 'wpcv_suppressions';

		$updated = $this->wpdb->update(
			$table,
			array(
				'expired_at'     => call_user_func( $this->now ),
				'expired_reason' => substr( (string) $reason, 0, 24 ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return $updated > 0;
	}

	/**
	 * 全ての抑制ルールを返す(v0.4.0 §Step9の一覧画面から呼ばれる想定).
	 *
	 * @return array<int, array>
	 */
	public function find_all() {
		return $this->all_rows();
	}

	/**
	 * Id から抑制ルールを1件読み取る.
	 *
	 * @param int $id 対象の行の id.
	 * @return array|null 見つからなければ null.
	 */
	public function find_by_id( $id ) {
		foreach ( $this->all_rows() as $row ) {
			if ( (int) $row['id'] === (int) $id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * `wpcv_suppressions` の全行を読み取る(`WPCV_Target_Run_Repository::all_rows()` と
	 * 同じ理由でテーブル全体を取得しPHP側で絞り込む).
	 *
	 * @return array<int, array>
	 */
	private function all_rows() {
		$table = $this->wpdb->base_prefix . 'wpcv_suppressions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$rows = $this->wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
