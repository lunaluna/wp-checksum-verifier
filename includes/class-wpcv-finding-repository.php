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
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
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
