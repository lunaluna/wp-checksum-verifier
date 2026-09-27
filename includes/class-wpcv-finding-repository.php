<?php
/**
 * WPCV_Finding_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_findings` テーブルの永続化を担当する(v0.4.0 §Step1でWPCV_Repositoryから分割).
 *
 * 分割の経緯は `WPCV_Run_Repository` のクラス docblock 参照.
 *
 * v0.4.0 §Step7で読み取り用の `query()`(pagination・allowlist方式のfilter/sort)を
 * 追加した。`WPCV_API::get_latest_findings()`(§10 Public API contract。WPMAR
 * 連携用に契約を固定済み)と絞り込み条件(dimension/status/severity/
 * include_suppressed/include_closed)が似ているが、あえて別実装にしている。
 * `WPCV_API` はWPMAR向けの安定した契約であり内部実装を変えるリスクを負いたくない
 * のに対し、こちらはpagination・sort・任意のrun_id指定という異なる要件を持つ
 * (このクラスの `query()` docblock参照).
 */
class WPCV_Finding_Repository {

	/**
	 * `query()` の `per_page` 既定値.
	 *
	 * 未実測: WP REST APIコアの既定(10)よりやや大きい値として20を選んだが、
	 * 実運用でのfindings件数の分布を見て見直す余地がある.
	 *
	 * @var int
	 */
	const DEFAULT_PER_PAGE = 20;

	/**
	 * `query()` の `per_page` 上限値.
	 *
	 * 未実測: WP REST APIコアの慣例(上限100)に合わせた値.
	 *
	 * @var int
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * `query()` の `sort` に指定できる列のallowlist.
	 *
	 * @var string[]
	 */
	const SORTABLE_COLUMNS = array( 'id', 'path', 'severity', 'status', 'version' );

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `delete()` / `get_results()` /
	 * `prepare()` / `base_prefix` / `last_error` を持つもの).
	 *
	 * `last_error` は v0.4.0コードレビューCR-03是正で追加した要件(`save_findings()`
	 * が `insert()` 失敗時の例外メッセージに使う)。`delete()` は同CR-04是正で
	 * 追加した要件(`delete_by_target_run_id()` が使う).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * コンストラクタ.
	 *
	 * @param object $wpdb `$wpdb` 相当のオブジェクト.
	 */
	public function __construct( $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * 検出結果(finding)群を保存する.
	 *
	 * @param int   $run_id         `WPCV_Run_Repository::reserve_run()` が返した run の id.
	 * @param array $target_run_ids `WPCV_Target_Run_Repository::save_target_runs()` が返した
	 *                              `target_id => target_run_id` の対応表.
	 * @param array $findings       `WPCV_Verifier` の各 `verify_*()` が返す findings の配列
	 *                              (id/run_id/target_run_id 無し。§5.5 のスキーマに準拠)。
	 *                              `suppressed_by`/`suppression_id`(v0.4.0 §Step8:
	 *                              `WPCV_Suppression_Matcher::apply()` の戻り値)は
	 *                              省略可(無ければ両方 null として保存する).
	 * @return void
	 *
	 * @throws InvalidArgumentException 対応する target_run_id が `$target_run_ids` に無い場合(同一バッチの
	 *                                   target_runs と findings の target_id は必ず
	 *                                   一致している前提が崩れている、呼び出し側の実装ミス).
	 * @throws RuntimeException         `$wpdb->insert()` が失敗した場合(v0.4.0コード
	 *                                   レビューCR-03是正)。DB容量不足・接続断・
	 *                                   権限不足・制約違反等でinsertが `false` を
	 *                                   返しても、これを確認せず処理を続けると
	 *                                   findingを1件も保存できないまま呼び出し元
	 *                                   (`WPCV_Chunk_Result_Repository::commit_chunk()`)が
	 *                                   後続のcursor更新をCOMMITしてしまい、
	 *                                   「findingは無いのにcursorだけ前進した」
	 *                                   不整合な状態が残る.
	 */
	public function save_findings( $run_id, array $target_run_ids, array $findings ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';

		foreach ( $findings as $finding ) {
			if ( ! isset( $target_run_ids[ $finding['target_id'] ] ) ) {
				throw new InvalidArgumentException(
					esc_html(
						sprintf(
							'WPCV_Finding_Repository::save_findings() has no matching target_run_id for target_id: %s',
							$finding['target_id']
						)
					)
				);
			}

			$inserted = $this->wpdb->insert(
				$table,
				array(
					'run_id'         => $run_id,
					'target_run_id'  => $target_run_ids[ $finding['target_id'] ],
					'target_id'      => $finding['target_id'],
					'dimension'      => $finding['dimension'],
					'slug'           => $finding['slug'],
					'version'        => $finding['version'],
					'source'         => $finding['source'],
					'path'           => $finding['path'],
					'status'         => $finding['status'],
					'severity'       => $finding['severity'],
					'hash_algorithm' => $finding['hash_algorithm'],
					'expected_hash'  => $finding['expected_hash'],
					'actual_hash'    => $finding['actual_hash'],
					'file_size'      => $finding['file_size'],
					'suppressed_by'  => isset( $finding['suppressed_by'] ) ? $finding['suppressed_by'] : null,
					'suppression_id' => isset( $finding['suppression_id'] ) ? $finding['suppression_id'] : null,
					// v0.5 §Step6: stat_changed の前回値→今回値(JSON). Step1 で列を
					// 追加したが保存処理が追従していなかった. 他の status では null.
					'detail'         => isset( $finding['detail'] ) ? $finding['detail'] : null,
					// v0.5後半 §Step10: 差分処理(Step12以降)が使う差分キー. 保存時に
					// 確定させ、差分処理側では計算し直さず読むだけにする(計算式の
					// 変更が起きても、過去に保存済みの finding_key は変わらないため).
					'finding_key'    => WPCV_Finding_Key::compute(
						$finding['target_id'],
						$finding['version'],
						$finding['path'],
						$finding['status'],
						$finding['hash_algorithm'],
						$finding['expected_hash'],
						$finding['actual_hash']
					),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
			);

			if ( false === $inserted ) {
				throw new RuntimeException(
					esc_html(
						sprintf(
							'WPCV_Finding_Repository::save_findings() の insert に失敗しました: %s',
							(string) $this->wpdb->last_error
						)
					)
				);
			}
		}
	}

	/**
	 * 指定 target_run_id を持つ findings をすべて削除する(v0.4.0コードレビュー
	 * CR-04是正)。
	 *
	 * `WPCV_Target_Run_Repository::reset_for_retry()`(fingerprint/version不一致を
	 * 検知したtargetのcursor・集計値をリセットする)と対にして呼ぶ想定
	 * (`WPCV_Chunk_Result_Repository::commit_chunk()` 参照)。cursor・集計値だけを
	 * 0へ戻して旧世代のfindings行を残したままにすると、再走査後に重複・陳腐化した
	 * findingが表示され、`findings_total`(リセット後0から積み直す)と
	 * `wpcv_findings`の実件数(旧世代分がそのまま残る)が食い違う不整合になる
	 * (レビュー指摘の実害).
	 *
	 * @param int $target_run_id 対象の target_run の id.
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->delete()` が失敗した場合(v0.4.0コード
	 *                          レビューCR-03是正と同じ理由。ここを確認せずに
	 *                          `reset_for_retry()`のcursorリセットだけをCOMMIT
	 *                          すると、旧世代findingが削除されないまま「削除した
	 *                          つもり」の状態になり、このメソッドを追加した目的
	 *                          そのものが達成できなくなる).
	 */
	public function delete_by_target_run_id( $target_run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';

		$deleted = $this->wpdb->delete(
			$table,
			array( 'target_run_id' => (int) $target_run_id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Finding_Repository::delete_by_target_run_id() の delete に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}
	}

	/**
	 * Findingsをfilter・sort・pagination付きで取得する(v0.4.0 §Step7:
	 * `WPCV_Rest_Findings_Controller` から使う。クラス docblock「あえて別実装に
	 * している」参照)。
	 *
	 * `run_id` は実SQLの `WHERE` にも含めるが(本番環境での効率のため)、
	 * それ以外のfilter(dimension/status/severity/suppressed/closed)・sort・
	 * paginationはPHP側で行う(`WPCV_Target_Run_Repository::claim_next()` 等
	 * 既存Repository群と同じ理由: テストダブル `WPCV_Test_Fake_WPDB::get_results()`
	 * がWHERE句を解釈しないため、production/テスト両方で正しく動く設計にするには
	 * PHP側での確定的なフィルタリングが必要).
	 *
	 * @param array $args {
	 *     絞り込み・sort・pagination条件.
	 *
	 *     @type int      $run_id              対象run(必須).
	 *     @type string[] $dimension           `WPCV_Target_Resolver::DIMENSIONS` の値の一覧
	 *                                         (空なら絞り込まない).
	 *     @type string[] $status              finding.statusの値の一覧.
	 *     @type string[] $severity            finding.severityの値の一覧.
	 *     @type bool     $include_suppressed  既定false(`suppressed_by`/`suppression_id`が
	 *                                         設定済みのfindingを除外する).
	 *     @type bool     $include_closed      既定false(`closed_at`が設定済みのfindingを除外する).
	 *     @type string   $sort                既定'id'。`SORTABLE_COLUMNS`のいずれか以外は
	 *                                         'id'にフォールバックする.
	 *     @type string   $order               'asc'(既定)|'desc'.
	 *     @type int      $page                既定1(1未満は1にclampする).
	 *     @type int      $per_page            既定`DEFAULT_PER_PAGE`
	 *                                         (1-`MAX_PER_PAGE`にclampする).
	 * }
	 * @return array{rows: array, total: int} `total` はpagination前の全件数.
	 */
	public function query( array $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'run_id'             => 0,
				'dimension'          => array(),
				'status'             => array(),
				'severity'           => array(),
				'include_suppressed' => false,
				'include_closed'     => false,
				'sort'               => 'id',
				'order'              => 'asc',
				'page'               => 1,
				'per_page'           => self::DEFAULT_PER_PAGE,
			)
		);

		$table = $this->wpdb->base_prefix . 'wpcv_findings';
		$sql   = "SELECT * FROM {$table} WHERE run_id = %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built on the line above; the sniff cannot trace it through prepare() on a separate line, but the run_id value is bound via %d below.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, (int) $args['run_id'] ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$filtered = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $args ) {
					return WPCV_Finding_Repository::matches( $row, $args );
				}
			)
		);

		usort( $filtered, self::comparator( (string) $args['sort'], (string) $args['order'] ) );

		$total    = count( $filtered );
		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		return array(
			'rows'  => array_slice( $filtered, $offset, $per_page ),
			'total' => $total,
		);
	}

	/**
	 * 1件のfinding行が `query()` の絞り込み条件をすべて満たすかどうかを判定する.
	 *
	 * @param array $row  finding行.
	 * @param array $args `query()` に渡された(既定適用済みの)引数.
	 * @return bool
	 */
	private static function matches( array $row, array $args ) {
		if ( (int) $row['run_id'] !== (int) $args['run_id'] ) {
			return false;
		}

		if ( ! empty( $args['dimension'] ) && ! in_array( $row['dimension'], $args['dimension'], true ) ) {
			return false;
		}

		if ( ! empty( $args['status'] ) && ! in_array( $row['status'], $args['status'], true ) ) {
			return false;
		}

		if ( ! empty( $args['severity'] ) && ! in_array( $row['severity'], $args['severity'], true ) ) {
			return false;
		}

		if ( ! $args['include_suppressed'] && ( ! empty( $row['suppressed_by'] ) || ! empty( $row['suppression_id'] ) ) ) {
			return false;
		}

		if ( ! $args['include_closed'] && ! empty( $row['closed_at'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * 指定 target_run が「使える基準」かどうかを判定する(v0.5後半 §Step12・§1.4:
	 * `WPCV_Generation_Differ::determine_diff_mode()` の `$baseline_target_run['usable']`
	 * に渡す値).
	 *
	 * 「使えない」のは、基準の target_run に finding が1件以上あるのに、その
	 * すべてが `finding_key` を持たない(v4 より前に保存された行しか無い)場合のみ。
	 * finding が1件も無い(=前回の検証で何も検出されなかった)場合は、比較の
	 * 基準として正当に使えるため `true` を返す(この2つを区別しないと、正常に
	 * 「クリーンだった」基準まで`first`扱いにしてしまう).
	 *
	 * @param int $target_run_id 対象の target_run の id.
	 * @return bool
	 */
	public function is_baseline_usable( $target_run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';
		$sql   = "SELECT * FROM {$table} WHERE target_run_id = %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; target_run_id is bound via %d below.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, (int) $target_run_id ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$found_any = false;

		foreach ( $rows as $row ) {
			if ( (int) $row['target_run_id'] !== (int) $target_run_id ) {
				continue;
			}

			$found_any = true;

			if ( null !== $row['finding_key'] ) {
				return true;
			}
		}

		return ! $found_any;
	}

	/**
	 * 指定 target_run の今回側 finding を `id` 昇順で K 件バッチ取得する
	 * (v0.5後半 §Step12・§1.4 Pass 1用. `WPCV_Diff_Dispatcher` から呼ぶ).
	 *
	 * 抑制の有無・`diff_state`が既に設定済みかを問わず、対象 target_run の
	 * finding をすべて対象にする(抑制済みの finding も「抑制終了」判定の
	 * 材料として Pass 1 が読む必要があるため。§1.4参照).
	 *
	 * @param int $target_run_id 対象の target_run の id.
	 * @param int $after_id      この id より大きい行だけを対象にする(前回バッチの続き.
	 *                           初回は 0).
	 * @param int $limit         最大取得件数.
	 * @return array<int, array> `id` 昇順. 件数が `$limit` 未満なら「この target_run を
	 *                           読み切った」ことを意味する.
	 */
	public function find_batch_by_target_run( $target_run_id, $after_id, $limit ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';
		// FORCE INDEXはStep12の実地検証(test-armfu.local)で見つかった性能上の
		// 懸念への対応(schema v5で追加した`idx_target_run_id_seq(target_run_id, id)`
		// をこのクエリに使わせる). `EXPLAIN ANALYZE`で確認したところ、この
		// FORCE INDEXが無いとMySQLの optimizer は`ORDER BY id ASC`をPRIMARY(id)
		// だけで満たそうとし、`idx_target_run_id_seq`が`possible_keys`に挙がって
		// いても選ばない(実測: PRIMARY経由で約87ms・100,506行スキャン に対し、
		// このindexを強制すると約1ms・500行のみ. 全体件数が増えるほどPRIMARY経由の
		// 差は開く).
		$sql = "SELECT * FROM {$table} FORCE INDEX (idx_target_run_id_seq) WHERE target_run_id = %d AND id > %d ORDER BY id ASC LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; all dynamic values are bound via %d below.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, (int) $target_run_id, (int) $after_id, (int) $limit ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		return self::filter_sort_and_limit(
			$rows,
			static function ( $row ) use ( $target_run_id, $after_id ) {
				return (int) $row['target_run_id'] === (int) $target_run_id && (int) $row['id'] > (int) $after_id;
			},
			(int) $limit
		);
	}

	/**
	 * 指定 target_run の基準側 finding を `id` 昇順で K 件バッチ取得する
	 * (v0.5後半 §Step12・§1.4 Pass 2用).
	 *
	 * `finding_key IS NOT NULL AND suppressed_by IS NULL AND suppression_id IS NULL
	 * AND ended_in_run_id IS NULL` で事前に絞り込み済み(§1.4「pass 2に到達する
	 * 基準行はまだ終わっていないものだけ」).
	 *
	 * @param int $target_run_id 対象の target_run の id.
	 * @param int $after_id      この id より大きい行だけを対象にする. 初回は 0.
	 * @param int $limit         最大取得件数.
	 * @return array<int, array> `id` 昇順.
	 */
	public function find_baseline_batch( $target_run_id, $after_id, $limit ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';
		// FORCE INDEXの理由は`find_batch_by_target_run()`と同じ(§実地検証参照).
		$sql = "SELECT * FROM {$table} FORCE INDEX (idx_target_run_id_seq) WHERE target_run_id = %d AND id > %d
			AND finding_key IS NOT NULL AND suppressed_by IS NULL AND suppression_id IS NULL AND ended_in_run_id IS NULL
			ORDER BY id ASC LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; all dynamic values are bound via %d below.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, (int) $target_run_id, (int) $after_id, (int) $limit ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		return self::filter_sort_and_limit(
			$rows,
			static function ( $row ) use ( $target_run_id, $after_id ) {
				return (int) $row['target_run_id'] === (int) $target_run_id
					&& (int) $row['id'] > (int) $after_id
					&& self::is_comparable( $row );
			},
			(int) $limit
		);
	}

	/**
	 * `$keys` のうち、指定 target_run に実在する `finding_key` の集合を返す
	 * (v0.5後半 §Step12・§1.4 Pass 1・Pass 2共通の「targeted lookup」).
	 *
	 * `$only_comparable` が true の場合、抑制済み・`finding_key` 無し・
	 * `ended_in_run_id` 設定済みの行はマッチ対象に含めない(Pass 1が今回側の
	 * finding をこの基準に照会する際の条件. §1.4参照)。false の場合は
	 * `finding_key` が一致するかどうかだけを見る(Pass 2が基準側の finding を
	 * 今回側に照会する際の条件. 今回側の抑制状態は問わない.§1.4「pass 2の
	 * 単純化したマッチ判定」参照).
	 *
	 * @param int      $target_run_id   対象の target_run の id.
	 * @param string[] $keys            照会する `finding_key` の一覧.
	 * @param bool     $only_comparable 既定 true.
	 * @return string[] マッチした `finding_key`(重複なし).
	 */
	public function find_matching_keys( $target_run_id, array $keys, $only_comparable = true ) {
		$keys = array_values( array_unique( $keys ) );

		if ( empty( $keys ) ) {
			return array();
		}

		$table        = $this->wpdb->base_prefix . 'wpcv_findings';
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "SELECT * FROM {$table} WHERE target_run_id = %d AND finding_key IN ( {$placeholders} )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only, placeholder count matches $args) built above; all dynamic values are bound via prepare() below.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, array_merge( array( (int) $target_run_id ), $keys ) ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$matched = array();

		foreach ( $rows as $row ) {
			if ( (int) $row['target_run_id'] !== (int) $target_run_id ) {
				continue;
			}

			if ( ! in_array( $row['finding_key'], $keys, true ) ) {
				continue;
			}

			if ( $only_comparable && ! self::is_comparable( $row ) ) {
				continue;
			}

			$matched[ $row['finding_key'] ] = true;
		}

		return array_keys( $matched );
	}

	/**
	 * 指定 id 群の finding を一括で終わらせる(v0.5後半 §Step12. `mark_ended_by_keys()`
	 * と異なり id で直接指定する版. 現時点では bulk mode〔§1.3〕からの利用は
	 * 想定していないが、Pass 1 の「抑制終了」対象(id で集めた集合)向けに用意する).
	 *
	 * `$wpdb->update()` は WHERE に `IN (...)` を組み立てられないため生SQLを使う
	 * (`WPCV_Test_Fake_WPDB::apply_bulk_update()` 参照。テストダブルもこの形の
	 * SQLだけを解釈する).
	 *
	 * @param int    $run_id     終わらせる run の id(`ended_in_run_id` に書く値).
	 * @param int[]  $ids        対象の finding の id 一覧(空なら何もしない).
	 * @param string $end_reason `WPCV_Generation_Differ::END_REASON_*` のいずれか.
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->query()` がSQLエラーで `false` を返した場合.
	 */
	public function mark_ended_by_ids( $run_id, array $ids, $end_reason ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		$table        = $this->wpdb->base_prefix . 'wpcv_findings';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "UPDATE {$table} SET ended_in_run_id = %d, end_reason = %s WHERE id IN ( {$placeholders} )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only, placeholder count matches $args) built above; all dynamic values are bound via prepare() below.
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, array_merge( array( (int) $run_id, (string) $end_reason ), $ids ) ) );

		if ( false === $result ) {
			throw new RuntimeException(
				esc_html(
					sprintf( 'WPCV_Finding_Repository::mark_ended_by_ids() の query に失敗しました: %s', (string) $this->wpdb->last_error )
				)
			);
		}
	}

	/**
	 * 指定 `finding_key` 群のうち、まだ終わっていない基準側 finding を一括で
	 * 終わらせる(v0.5後半 §Step12・§1.4 Pass 1の「抑制終了」・Pass 2の
	 * 「resolved」がどちらも使う).
	 *
	 * `ended_in_run_id IS NULL` を WHERE に含めるのは、既に別の理由で終わって
	 * いる行を上書きしないため(§1.4「pass 2に到達する基準行はまだ終わっていない
	 * ものだけ」という前提を、この書き込み自身でも保証する).
	 *
	 * @param int      $run_id        終わらせる run の id.
	 * @param int      $target_run_id 対象の target_run の id.
	 * @param string[] $keys          対象の `finding_key` 一覧(空なら何もしない).
	 * @param string   $end_reason    `WPCV_Generation_Differ::END_REASON_*` のいずれか.
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->query()` がSQLエラーで `false` を返した場合.
	 */
	public function mark_ended_by_keys( $run_id, $target_run_id, array $keys, $end_reason ) {
		$keys = array_values( array_unique( $keys ) );

		if ( empty( $keys ) ) {
			return;
		}

		$table        = $this->wpdb->base_prefix . 'wpcv_findings';
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "UPDATE {$table} SET ended_in_run_id = %d, end_reason = %s
			WHERE target_run_id = %d AND finding_key IN ( {$placeholders} ) AND ended_in_run_id IS NULL";

		$args = array_merge( array( (int) $run_id, (string) $end_reason, (int) $target_run_id ), $keys );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only, placeholder count matches $args) built above; all dynamic values are bound via prepare() below.
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, $args ) );

		if ( false === $result ) {
			throw new RuntimeException(
				esc_html(
					sprintf( 'WPCV_Finding_Repository::mark_ended_by_keys() の query に失敗しました: %s', (string) $this->wpdb->last_error )
				)
			);
		}
	}

	/**
	 * 指定 target_run の、まだ終わっていない finding をすべて同じ理由で終わらせる
	 * (v0.5後半 §Step12・§1.3 bulk mode用. `version_changed`/`excluded`/
	 * `target_removed` のように、基準側を無条件・全件終わらせるモード向け).
	 *
	 * @param int    $run_id        終わらせる run の id.
	 * @param int    $target_run_id 対象の target_run の id.
	 * @param string $end_reason    `WPCV_Generation_Differ::END_REASON_*` のいずれか.
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->query()` がSQLエラーで `false` を返した場合.
	 */
	public function end_all_for_target_run( $run_id, $target_run_id, $end_reason ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';
		$sql   = "UPDATE {$table} SET ended_in_run_id = %d, end_reason = %s WHERE target_run_id = %d AND ended_in_run_id IS NULL";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; all dynamic values are bound via prepare() below.
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, (int) $run_id, (string) $end_reason, (int) $target_run_id ) );

		if ( false === $result ) {
			throw new RuntimeException(
				esc_html(
					sprintf( 'WPCV_Finding_Repository::end_all_for_target_run() の query に失敗しました: %s', (string) $this->wpdb->last_error )
				)
			);
		}
	}

	/**
	 * 指定 id 群の finding に同じ `diff_state` を一括設定する(v0.5後半
	 * §Step12・§1.4 Pass 1用. 1バッチ内でnew/continuingが混在するため、
	 * `mark_diff_state_for_target_run()`(target_run全体に1つの値)とは異なり
	 * id単位でグルーピングして呼ぶ設計〔`WPCV_Diff_Dispatcher`側でnew用/
	 * continuing用の2回に分けて呼ぶ〕).
	 *
	 * @param int[]  $ids        対象の finding の id 一覧(空なら何もしない).
	 * @param string $diff_state `WPCV_Generation_Differ::DIFF_STATE_*` のいずれか.
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->query()` がSQLエラーで `false` を返した場合.
	 */
	public function mark_diff_state_by_ids( array $ids, $diff_state ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		$table        = $this->wpdb->base_prefix . 'wpcv_findings';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "UPDATE {$table} SET diff_state = %s WHERE id IN ( {$placeholders} )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only, placeholder count matches $args) built above; all dynamic values are bound via prepare() below.
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, array_merge( array( (string) $diff_state ), $ids ) ) );

		if ( false === $result ) {
			throw new RuntimeException(
				esc_html(
					sprintf( 'WPCV_Finding_Repository::mark_diff_state_by_ids() の query に失敗しました: %s', (string) $this->wpdb->last_error )
				)
			);
		}
	}

	/**
	 * 指定 target_run の finding に同じ `diff_state` を一括設定する(v0.5後半
	 * §Step12・§1.3 bulk mode用. `first`/`version_changed`の今回側〔全件new〕・
	 * stat の `event`〔抑制無しのみ〕が使う).
	 *
	 * `$only_unsuppressed` が true の場合、`suppressed_by IS NULL AND
	 * suppression_id IS NULL` を WHERE に含め、抑制済みの finding は触らない
	 * (既定値 `NULL` のまま残る。§1.4「抑制されていれば diff_state は NULL の
	 * まま」・§2.3「stat findingは抑制無しのみevent」と同じ考え方).
	 *
	 * @param int    $target_run_id      対象の target_run の id.
	 * @param string $diff_state         `WPCV_Generation_Differ::DIFF_STATE_*` のいずれか.
	 * @param bool   $only_unsuppressed  既定 true.
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->query()` がSQLエラーで `false` を返した場合.
	 */
	public function mark_diff_state_for_target_run( $target_run_id, $diff_state, $only_unsuppressed = true ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';
		$sql   = $only_unsuppressed
			? "UPDATE {$table} SET diff_state = %s WHERE target_run_id = %d AND suppressed_by IS NULL AND suppression_id IS NULL"
			: "UPDATE {$table} SET diff_state = %s WHERE target_run_id = %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; all dynamic values are bound via prepare() below.
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, (string) $diff_state, (int) $target_run_id ) );

		if ( false === $result ) {
			throw new RuntimeException(
				esc_html(
					sprintf( 'WPCV_Finding_Repository::mark_diff_state_for_target_run() の query に失敗しました: %s', (string) $this->wpdb->last_error )
				)
			);
		}
	}

	/**
	 * Run 1回分の差分集計(`new`/`resolved`/`continuing`)を求める(v0.5後半
	 * §Step12: `WPCV_Run_Repository::finalize_diff_chunk()` の `$counts` に渡す).
	 *
	 * `new`/`continuing` は今回の run(`run_id`列が一致)の finding を対象にする。
	 * `resolved` は「この run が終わらせた」finding(`ended_in_run_id`列が一致)を
	 * 対象にする ―― resolved になる finding 自体は基準(過去の別run)に属する行
	 * であり `run_id` 列は一致しないため、別の列で絞り込む必要がある(index
	 * `idx_ended_run` が効く).stat由来の `event` はこの3集計のいずれにも
	 * 含めない(専用の列を持たないため。v0.5後半プランに `findings_event` 相当の
	 * 列は無い. 将来必要になれば列追加とあわせて再検討すること).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return array{new: int, resolved: int, continuing: int}
	 */
	public function aggregate_diff_counts( $run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; run_id is bound via %d below.
		$current_rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE run_id = %d", (int) $run_id ), ARRAY_A );
		$current_rows = is_array( $current_rows ) ? $current_rows : array();

		$new        = 0;
		$continuing = 0;

		foreach ( $current_rows as $row ) {
			if ( (int) $row['run_id'] !== (int) $run_id ) {
				continue;
			}

			if ( WPCV_Generation_Differ::DIFF_STATE_NEW === $row['diff_state'] ) {
				++$new;
			} elseif ( WPCV_Generation_Differ::DIFF_STATE_CONTINUING === $row['diff_state'] ) {
				++$continuing;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; run_id is bound via %d below.
		$ended_rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$table} WHERE ended_in_run_id = %d", (int) $run_id ), ARRAY_A );
		$ended_rows = is_array( $ended_rows ) ? $ended_rows : array();

		$resolved = 0;

		foreach ( $ended_rows as $row ) {
			if ( (int) ( $row['ended_in_run_id'] ?? 0 ) !== (int) $run_id ) {
				continue;
			}

			if ( WPCV_Generation_Differ::END_REASON_RESOLVED === $row['end_reason'] ) {
				++$resolved;
			}
		}

		return array(
			'new'        => $new,
			'resolved'   => $resolved,
			'continuing' => $continuing,
		);
	}

	/**
	 * 指定 run の「通知の候補」(`diff_state` が new/continuing/event の finding)を
	 * `id` 昇順で K 件バッチ取得する(v0.5後半 §Step14. `WPCV_Alert_Sender`用).
	 *
	 * 抑制済みの finding は差分処理で`diff_state`がNULLのまま残るため(§2.2)、
	 * この条件だけで候補から外れる(§2.4「NULL(抑制)→通知しない」).
	 *
	 * `FORCE INDEX (idx_run_id)`は、InnoDB のセカンダリindexが主キー(id)を暗黙に
	 * 含み`(run_id, id)`の順に読めるため(schema v5 の`idx_target_run_id_seq`と
	 * 同じ理由. optimizer に任せると`ORDER BY id`を PRIMARY で満たそうとする.
	 * 1万・10万件での実測は Step14c で行う).
	 *
	 * @param int $run_id   対象の run の id.
	 * @param int $after_id この id より大きい行だけを対象にする(初回は 0).
	 * @param int $limit    最大取得件数.
	 * @return array<int, array> `id` 昇順. 件数が `$limit` 未満なら読み切ったことを意味する.
	 */
	public function find_notify_candidates_batch( $run_id, $after_id, $limit ) {
		$table  = $this->wpdb->base_prefix . 'wpcv_findings';
		$states = array(
			WPCV_Generation_Differ::DIFF_STATE_NEW,
			WPCV_Generation_Differ::DIFF_STATE_CONTINUING,
			WPCV_Generation_Differ::DIFF_STATE_EVENT,
		);
		$sql    = "SELECT * FROM {$table} FORCE INDEX (idx_run_id) WHERE run_id = %d AND id > %d AND diff_state IN ( %s, %s, %s ) ORDER BY id ASC LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; all dynamic values are bound via prepare() below.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, array_merge( array( (int) $run_id, (int) $after_id ), $states, array( (int) $limit ) ) ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		return self::filter_sort_and_limit(
			$rows,
			static function ( $row ) use ( $run_id, $after_id, $states ) {
				return (int) $row['run_id'] === (int) $run_id
					&& (int) $row['id'] > (int) $after_id
					&& in_array( $row['diff_state'], $states, true );
			},
			(int) $limit
		);
	}

	/**
	 * 指定した `finding_key` ごとに、過去の run での直近の `notified_at` を返す
	 * (v0.5後半 §Step14・§2.4「同じ finding_key の過去の notified_at」).
	 *
	 * `$exclude_run_id`(今回の run)の行は見ない. 送信のあと`notified_at`を
	 * 書いている途中でプロセスが止まり、やり直したとき(D10)に、今回すでに
	 * 書いた行を「過去の通知」と誤認して通知対象から外さないようにするため
	 * (やり直しでも同じ集合を送り直す).
	 *
	 * `idx_key_notified(finding_key, notified_at)`を使う. テストダブルは GROUP BY を
	 * 解釈せず全行を返すため、PHP 側でもキー・NULL・run を絞り直して最大値を取る.
	 *
	 * @param string[] $keys           照会する `finding_key`.
	 * @param int      $exclude_run_id 対象から外す run の id.
	 * @return array<string, string> `finding_key` => 直近の `notified_at`(一度も通知して
	 *                               いないキーは含まない).
	 */
	public function find_last_notified_at_by_keys( array $keys, $exclude_run_id ) {
		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );

		if ( empty( $keys ) ) {
			return array();
		}

		$table        = $this->wpdb->base_prefix . 'wpcv_findings';
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "SELECT finding_key, MAX( notified_at ) AS notified_at FROM {$table}
			WHERE finding_key IN ( {$placeholders} ) AND notified_at IS NOT NULL AND run_id <> %d
			GROUP BY finding_key";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only, placeholder count matches $args) built above; all dynamic values are bound via prepare() below.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, array_merge( $keys, array( (int) $exclude_run_id ) ) ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$wanted = array_flip( $keys );
		$latest = array();

		foreach ( $rows as $row ) {
			$key = (string) ( $row['finding_key'] ?? '' );

			if ( ! isset( $wanted[ $key ] ) || empty( $row['notified_at'] ) ) {
				continue;
			}

			// 本番の GROUP BY 結果には run_id 列が無い(SQL 側で除外済み). テストダブルの
			// 生の行にだけある.
			if ( isset( $row['run_id'] ) && (int) $row['run_id'] === (int) $exclude_run_id ) {
				continue;
			}

			if ( ! isset( $latest[ $key ] ) || (string) $row['notified_at'] > $latest[ $key ] ) {
				$latest[ $key ] = (string) $row['notified_at'];
			}
		}

		return $latest;
	}

	/**
	 * 指定 id 群の finding に `notified_at` を書く(v0.5後半 §Step14).
	 *
	 * メールが成功したときだけ呼ぶ(§2.4「notified_at を書くのは alert_status = sent
	 * のときだけ」). 同じ値を何度書いても結果は変わらない(D10 のやり直しで安全).
	 *
	 * @param int[]  $ids         対象の finding の id 一覧(空なら何もしない).
	 * @param string $notified_at MySQL DATETIME(UTC).
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->query()` がSQLエラーで `false` を返した場合.
	 */
	public function mark_notified_by_ids( array $ids, $notified_at ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return;
		}

		$table        = $this->wpdb->base_prefix . 'wpcv_findings';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "UPDATE {$table} SET notified_at = %s WHERE id IN ( {$placeholders} )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only, placeholder count matches $args) built above; all dynamic values are bound via prepare() below.
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, array_merge( array( (string) $notified_at ), $ids ) ) );

		if ( false === $result ) {
			throw new RuntimeException(
				esc_html(
					sprintf( 'WPCV_Finding_Repository::mark_notified_by_ids() の query に失敗しました: %s', (string) $this->wpdb->last_error )
				)
			);
		}
	}

	/**
	 * 指定 run の通知対象(`diff_state` が new/continuing/event)を target_id・
	 * status ごとに数える(v0.5後半 §Step14・§4.1「By target:」・§3.2「SQLの
	 * GROUP BY で取る」).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return array<int, array{target_id: string, status: string, count: int}>
	 */
	public function count_by_target_and_status( $run_id ) {
		$table  = $this->wpdb->base_prefix . 'wpcv_findings';
		$states = array(
			WPCV_Generation_Differ::DIFF_STATE_NEW,
			WPCV_Generation_Differ::DIFF_STATE_CONTINUING,
			WPCV_Generation_Differ::DIFF_STATE_EVENT,
		);
		$sql    = "SELECT target_id, status, COUNT(*) AS count FROM {$table}
			WHERE run_id = %d AND diff_state IN ( %s, %s, %s )
			GROUP BY target_id, status";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; all dynamic values are bound via prepare() below.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, array_merge( array( (int) $run_id ), $states ) ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		// テストダブル(`WPCV_Test_Fake_WPDB::get_results()`)は GROUP BY を解釈せず
		// テーブル全件(`diff_state`列込みの生の行)を返す。本番の集計結果には
		// `diff_state`列が無く`count`列がある(逆に生の行には`count`列が無い)ため、
		// この2つを区別してから集計し直す(`WPCV_Finding_Repository` の他メソッドと
		// 同じ「SQLで絞り、PHP側でも同じ条件で絞り直す」設計方針).
		$counted = array();

		foreach ( $rows as $row ) {
			if ( array_key_exists( 'count', $row ) && ! array_key_exists( 'diff_state', $row ) ) {
				$target_id = (string) $row['target_id'];
				$status    = (string) $row['status'];
				$count     = (int) $row['count'];
			} else {
				if ( (int) ( $row['run_id'] ?? 0 ) !== (int) $run_id || ! in_array( $row['diff_state'] ?? null, $states, true ) ) {
					continue;
				}

				$target_id = (string) $row['target_id'];
				$status    = (string) $row['status'];
				$count     = 1;
			}

			$key = $target_id . "\0" . $status;

			$counted[ $key ] = array(
				'target_id' => $target_id,
				'status'    => $status,
				'count'     => ( $counted[ $key ]['count'] ?? 0 ) + $count,
			);
		}

		return array_values( $counted );
	}

	/**
	 * 指定 run で解消した(`end_reason = resolved`)finding を、本文の一覧用に
	 * target_id → path の順で最大 N 件返す(v0.5後半 §Step14・§4.1「Resolved:」).
	 *
	 * @param int $run_id 対象の run の id(`ended_in_run_id` で絞る).
	 * @param int $limit  最大件数.
	 * @return array<int, array>
	 */
	public function find_resolved_items( $run_id, $limit ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';
		$sql   = "SELECT * FROM {$table} WHERE ended_in_run_id = %d AND end_reason = %s ORDER BY target_id ASC, path ASC LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; all dynamic values are bound via prepare() below.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, (int) $run_id, WPCV_Generation_Differ::END_REASON_RESOLVED, (int) $limit ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$rows = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $run_id ) {
					return (int) ( $row['ended_in_run_id'] ?? 0 ) === (int) $run_id
						&& WPCV_Generation_Differ::END_REASON_RESOLVED === ( $row['end_reason'] ?? null );
				}
			)
		);

		usort(
			$rows,
			static function ( $a, $b ) {
				return array( (string) $a['target_id'], (string) $a['path'] ) <=> array( (string) $b['target_id'], (string) $b['path'] );
			}
		);

		return array_slice( $rows, 0, (int) $limit );
	}

	/**
	 * 指定 run で「今回のrunに現れなかった(アンインストールされた)」として
	 * 終わった target の数を返す(v0.5後半 §Step14・§4.1「Removed targets:」).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return int
	 */
	public function count_removed_targets( $run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';
		$sql   = "SELECT DISTINCT target_id, ended_in_run_id, end_reason FROM {$table} WHERE ended_in_run_id = %d AND end_reason = %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; all dynamic values are bound via prepare() below.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, (int) $run_id, WPCV_Generation_Differ::END_REASON_TARGET_REMOVED ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$target_ids = array();

		foreach ( $rows as $row ) {
			if ( (int) ( $row['ended_in_run_id'] ?? 0 ) === (int) $run_id && WPCV_Generation_Differ::END_REASON_TARGET_REMOVED === ( $row['end_reason'] ?? null ) ) {
				$target_ids[ (string) $row['target_id'] ] = true;
			}
		}

		return count( $target_ids );
	}

	/**
	 * 1件の finding 行が、Pass 1/Pass 2 の「比較対象として扱ってよい」条件
	 * (`finding_key` 有り・抑制なし・未終了)を満たすかどうかを判定する
	 * (`find_baseline_batch()`・`find_matching_keys( $only_comparable = true )` 共通).
	 *
	 * @param array $row finding行.
	 * @return bool
	 */
	private static function is_comparable( array $row ) {
		return null !== $row['finding_key']
			&& empty( $row['suppressed_by'] )
			&& empty( $row['suppression_id'] )
			&& empty( $row['ended_in_run_id'] );
	}

	/**
	 * テストダブルがWHERE句・ORDER BY・LIMITを解釈しないための、PHP側での
	 * 絞り込み・id昇順ソート・件数制限をまとめたヘルパー(`find_batch_by_target_run()`・
	 * `find_baseline_batch()` 共通. 本番の実SQLは既に絞り込み・ソート・LIMIT済みだが、
	 * テストダブル経由では全件が返るため、ここで確定的に同じ結果になるようにする).
	 *
	 * @param array<int, array> $rows      `get_results()` の戻り値.
	 * @param callable          $predicate `function( array $row ): bool`.
	 * @param int               $limit     最大件数.
	 * @return array<int, array>
	 */
	private static function filter_sort_and_limit( array $rows, callable $predicate, $limit ) {
		$filtered = array_values( array_filter( $rows, $predicate ) );

		usort(
			$filtered,
			static function ( $a, $b ) {
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);

		return array_slice( $filtered, 0, $limit );
	}

	/**
	 * `usort()` に渡す比較関数を組み立てる.
	 *
	 * `$sort` が `SORTABLE_COLUMNS` に無ければ `id` にフォールバックする(呼び出し元
	 * `WPCV_Rest_Findings_Controller` は事前にallowlist検証して`400`を返す設計だが、
	 * このメソッド単体で呼ばれても安全に振る舞うための防御).severityの並びは
	 * 文字列の辞書順であり、重要度としての順位(high > medium > low)には対応しない
	 * (未実装. 必要になれば専用の順位マップを導入すること).
	 *
	 * @param string $sort  ソート列.
	 * @param string $order 'asc'|'desc'.
	 * @return callable
	 */
	private static function comparator( $sort, $order ) {
		$column    = in_array( $sort, self::SORTABLE_COLUMNS, true ) ? $sort : 'id';
		$direction = ( 'desc' === $order ) ? -1 : 1;

		return static function ( $a, $b ) use ( $column, $direction ) {
			if ( 'id' === $column ) {
				return $direction * ( (int) $a[ $column ] <=> (int) $b[ $column ] );
			}

			return $direction * strcmp( (string) $a[ $column ], (string) $b[ $column ] );
		};
	}
}
