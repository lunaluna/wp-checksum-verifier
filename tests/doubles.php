<?php
/**
 * 複数のテストファイルで共有するテストダブル.
 *
 * `VerifierTest` と `RunCoordinatorTest` がどちらも固定結果を返す
 * `WPCV_Manifest_Source` を必要とし、`RepositoryTest` と `RunCoordinatorTest`
 * がどちらも `$wpdb` ダブルを必要とするため、重複を避けてここに集約する
 * (2箇所目の利用が出た時点で共通化する、という判断)。`WPCV_Test_Fake_Rest_Request`
 * も同じ理由で`RestTokenTest`・`RestRunControllerTest`から共用する.
 *
 * @package WPChecksumVerifier
 */

require_once dirname( __DIR__ ) . '/includes/sources/interface-wpcv-manifest-source.php';

/**
 * テスト用の固定結果を返す `WPCV_Manifest_Source` 実装.
 *
 * 実際の Source_Core / Source_Wporg_Plugin(HTTP・WP 関数依存)を経由せず、
 * 呼び出し側のオーケストレーションロジックだけを検証するために使う.
 */
class WPCV_Test_Fake_Manifest_Source implements WPCV_Manifest_Source {

	/**
	 * get_manifest() が返す固定値、または target_id => 固定値 のマップ.
	 *
	 * @var array
	 */
	private $result;

	/**
	 * コンストラクタ.
	 *
	 * @param array $result get_manifest() の戻り値としてそのまま返す配列.
	 */
	public function __construct( array $result ) {
		$this->result = $result;
	}

	/**
	 * 固定値をそのまま返す.
	 *
	 * @param array $context 無視する.
	 * @return array
	 */
	public function get_manifest( array $context ) {
		unset( $context );
		return $this->result;
	}
}

/**
 * テスト用の最小 `$wpdb` ダブル.
 *
 * `insert()` / `update()` の呼び出しをそのままメモリ上の配列に記録するだけの
 * 実装で、実際の SQL は発行しない。`WPCV_Repository` が呼ぶメソッド群だけを
 * 満たす(実 wpdb クラスは実装しない。ダックタイピングで十分なため).
 */
class WPCV_Test_Fake_WPDB {

	/**
	 * インストールレベルのテーブル接頭辞(本番の `$wpdb->base_prefix` に相当).
	 *
	 * @var string
	 */
	public $base_prefix = 'wp_';

	/**
	 * 直近の `insert()` が採番した id(本番の `$wpdb->insert_id` に相当).
	 *
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * 直近の失敗したクエリのエラーメッセージ(本番の `$wpdb->last_error` に相当).
	 *
	 * DB容量不足・接続断・権限不足・制約違反等で `$wpdb->insert()/update()/query()` が
	 * `false` を返す経路をテストで再現するために追加した(v0.4.0コードレビュー
	 * CR-03是正)。`$insert_should_fail`/`$update_should_fail`/`$query_should_fail` を参照.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * `true` にすると、以降の `insert()` 呼び出しがすべて `false` を返す
	 * (本番の `$wpdb->insert()` がSQLエラー時に返す値を模す. v0.4.0コード
	 * レビューCR-03是正)。行への反映は一切行わない.
	 *
	 * @var bool
	 */
	public $insert_should_fail = false;

	/**
	 * `true` にすると、以降の `update()` 呼び出しがすべて `false` を返す
	 * (本番の `$wpdb->update()` がSQLエラー時に返す値を模す. v0.4.0コード
	 * レビューCR-03是正)。WHEREに一致する行数に関わらず常に `false` を返す点が、
	 * 「一致する行が無い」場合の `0` と区別すべき対象.
	 *
	 * @var bool
	 */
	public $update_should_fail = false;

	/**
	 * `true` にすると、以降の `query()` 呼び出しがすべて `false` を返す
	 * (本番の `$wpdb->query()` がSQLエラー時に返す値を模す. v0.4.0コード
	 * レビューCR-03是正)。`START TRANSACTION`/`COMMIT`/`ROLLBACK` の呼び出し自体は
	 * `query_calls` に記録され続けるため、「呼ばれたこと」のアサーションはこの
	 * フラグの影響を受けない.
	 *
	 * @var bool
	 */
	public $query_should_fail = false;

	/**
	 * `true` にすると、以降の `delete()` 呼び出しがすべて `false` を返す
	 * (本番の `$wpdb->delete()` がSQLエラー時に返す値を模す. v0.4.0コード
	 * レビューCR-04是正で追加した `WPCV_Finding_Repository::delete_by_target_run_id()`
	 * のテスト用).
	 *
	 * @var bool
	 */
	public $delete_should_fail = false;

	/**
	 * `get_col( "DESCRIBE {$table}" )` が返す列名配列を、テーブル名をキーに
	 * 保持する(本番の実DBスキーマ相当。v0.4.0コードレビューCR-05是正で追加した
	 * `WPCV_Migrator::schema_is_current()` のテスト用)。未設定のテーブルは
	 * 空配列(列が1つも無い = dbDeltaが何も作れなかった状態)として扱う.
	 *
	 * @var array<string, string[]>
	 */
	public $columns_by_table = array();

	/**
	 * テーブルごとの行(id をキーにした連想配列).
	 *
	 * @var array<string, array<int, array>>
	 */
	public $rows = array();

	/**
	 * テーブルごとの次の auto increment id.
	 *
	 * @var array<string, int>
	 */
	private $next_id = array();

	/**
	 * `get_var()` が返す値(`reserve_run()` の `GET_LOCK()` 呼び出し用)。
	 *
	 * 既定は `'1'`(lock 取得成功を模す。実際の MySQL の `GET_LOCK()` も成功時に
	 * 整数 `1` を返す)。lock 取得失敗を模したいテストは `'0'` を設定する.
	 *
	 * @var string|null
	 */
	public $get_var_return = '1';

	/**
	 * `get_var()` に渡されたクエリ文字列の記録(アサーション用).
	 *
	 * @var array<int, string>
	 */
	public $get_var_calls = array();

	/**
	 * `get_results()` に渡されたクエリ文字列の記録(アサーション用.
	 * コードレビュー指摘5: 全件取得のSELECTが発行されていないことを確かめるため).
	 *
	 * @var array<int, string>
	 */
	public $get_results_calls = array();

	/**
	 * `query()` に渡されたクエリ文字列の記録(`RELEASE_LOCK()` が確実に呼ばれた
	 * ことをテストで確認できるようにするため).
	 *
	 * @var array<int, string>
	 */
	public $query_calls = array();

	/**
	 * 行を追加する.
	 *
	 * `$insert_should_fail` が真の場合、行への反映を一切行わず `false` を返す
	 * (本番の `$wpdb->insert()` がSQLエラー時に返す値を模す。v0.4.0コードレビュー
	 * CR-03是正).
	 *
	 * @param string     $table  テーブル名.
	 * @param array      $data   カラム => 値.
	 * @param array|null $format 無視する(本番の型指定に相当。ダブルでは検証しない).
	 * @return int|false 常に1(本番の `$wpdb->insert()` の成功時と同じ)。
	 *                    `$insert_should_fail` が真なら `false`.
	 */
	public function insert( $table, $data, $format = null ) {
		unset( $format );

		if ( $this->insert_should_fail ) {
			$this->last_error = 'WPCV_Test_Fake_WPDB: insert_should_fail が true のため insert() を失敗させました.';

			return false;
		}

		if ( 'wp_wpcv_findings' === $table ) {
			// 本番の `WPCV_Finding_Repository::save_findings()` は
			// diff_state/ended_in_run_id/end_reason 等(差分処理が後から書く列)を
			// insert時には一切渡さない ―― 実DBならNULL default列としてSELECT時に
			// 返ってくるが、このダブルは渡されたキーしか保持しないため、後続の
			// 差分処理コード(`WPCV_Finding_Repository`の各readメソッド)がこれらの
			// キーへの直接アクセスで「Undefined array key」になる. 実スキーマの
			// NULL defaultをここで模して補う(統合テストで`save_findings()`本体を
			// 経由させたときに顕在化した欠落. 2026-09-26).
			$data = array_merge( self::findings_column_defaults(), $data );
		}

		if ( ! isset( $this->next_id[ $table ] ) ) {
			$this->next_id[ $table ] = 1;
		}

		$id         = $this->next_id[ $table ]++;
		$data['id'] = $id;

		$this->rows[ $table ][ $id ] = $data;
		$this->insert_id             = $id;

		return 1;
	}

	/**
	 * `wp_wpcv_findings`のうち、`save_findings()`がinsert時に渡さない列の
	 * NULL defaultを返す(`insert()`参照。列名は`wpcv_test_make_finding_row()`の
	 * 追加分と同じ).
	 *
	 * @return array<string, null>
	 */
	private static function findings_column_defaults() {
		return array(
			'closed_at'       => null,
			'closed_reason'   => null,
			'diff_state'      => null,
			'notified_at'     => null,
			'ended_in_run_id' => null,
			'end_reason'      => null,
		);
	}

	/**
	 * 条件に一致する行を更新する.
	 *
	 * `$update_should_fail` が真の場合、WHEREに一致する行の有無に関わらず一切
	 * 反映せず `false` を返す(本番の `$wpdb->update()` がSQLエラー時に返す値を
	 * 模す。「一致する行が無い」場合の `0` とは区別する。v0.4.0コードレビュー
	 * CR-03是正).
	 *
	 * @param string     $table        テーブル名.
	 * @param array      $data         更新するカラム => 値.
	 * @param array      $where        カラム => 値(すべて一致する行を更新).
	 * @param array|null $format       無視する.
	 * @param array|null $where_format 無視する.
	 * @return int|false 更新した行数。`$update_should_fail` が真なら `false`.
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		unset( $format, $where_format );

		if ( $this->update_should_fail ) {
			$this->last_error = 'WPCV_Test_Fake_WPDB: update_should_fail が true のため update() を失敗させました.';

			return false;
		}

		$updated = 0;

		foreach ( $this->rows[ $table ] as $id => $row ) {
			$matches = true;

			foreach ( $where as $column => $value ) {
				if ( ! isset( $row[ $column ] ) || $row[ $column ] !== $value ) {
					$matches = false;
					break;
				}
			}

			if ( $matches ) {
				$this->rows[ $table ][ $id ] = array_merge( $row, $data );
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * 条件に一致する行を削除する(v0.4.0コードレビューCR-04是正で追加した
	 * `WPCV_Finding_Repository::delete_by_target_run_id()` 用).
	 *
	 * `$delete_should_fail` が真の場合、WHEREに一致する行の有無に関わらず一切
	 * 削除せず `false` を返す(本番の `$wpdb->delete()` がSQLエラー時に返す値を
	 * 模す).
	 *
	 * @param string     $table  テーブル名.
	 * @param array      $where  カラム => 値(すべて一致する行を削除).
	 * @param array|null $format 無視する.
	 * @return int|false 削除した行数。`$delete_should_fail` が真なら `false`.
	 */
	public function delete( $table, $where, $format = null ) {
		unset( $format );

		if ( $this->delete_should_fail ) {
			$this->last_error = 'WPCV_Test_Fake_WPDB: delete_should_fail が true のため delete() を失敗させました.';

			return false;
		}

		$deleted = 0;

		if ( ! isset( $this->rows[ $table ] ) ) {
			return $deleted;
		}

		foreach ( $this->rows[ $table ] as $id => $row ) {
			$matches = true;

			foreach ( $where as $column => $value ) {
				if ( ! isset( $row[ $column ] ) || $row[ $column ] !== $value ) {
					$matches = false;
					break;
				}
			}

			if ( $matches ) {
				unset( $this->rows[ $table ][ $id ] );
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * 単一列を読み取る(`WPCV_Migrator::schema_is_current()` の
	 * `DESCRIBE {$table}` 専用の簡易フェイク。v0.4.0コードレビューCR-05是正)。
	 *
	 * 実 `$wpdb` と異なり SQL を解釈しない。クエリ文字列から `DESCRIBE {table}` の
	 * テーブル名だけを正規表現で拾い、`$columns_by_table` に設定済みの列名配列を
	 * そのまま返す(実DBの `DESCRIBE` が返す最初の列 `Field` 相当).
	 *
	 * @param string $query         SQL文字列(`DESCRIBE {table}` を含む前提).
	 * @param int    $column_offset 無視する(本プラグインは常に既定の0で呼ぶ).
	 * @return string[]
	 */
	public function get_col( $query, $column_offset = 0 ) {
		unset( $column_offset );

		if ( 1 !== preg_match( '/DESCRIBE\s+(\S+)/i', $query, $matches ) ) {
			return array();
		}

		$table = $matches[1];

		return isset( $this->columns_by_table[ $table ] ) ? $this->columns_by_table[ $table ] : array();
	}

	/**
	 * 行を読み取る(各Repositoryの読み取りメソッド向けの簡易フェイク).
	 *
	 * 次の単純な形のSELECTだけは解釈して、WHERE・ORDER BY・LIMIT/OFFSETを反映する
	 * (`select_simple()`. コードレビュー指摘5で追加.本番のRepositoryが全件取得を
	 * やめてSQLで絞り込めるようにするため):
	 *
	 *     SELECT * | 列名, ... FROM {table} [FORCE INDEX (...)]
	 *       [WHERE 条件 AND 条件 ...]
	 *       [ORDER BY 列 ASC|DESC [, 列 ASC|DESC ...]]
	 *       [LIMIT n [OFFSET m]]
	 *
	 * 条件は `parse_where_conditions_strict()` が扱える形のみ.それ以外の形
	 * (集計関数・GROUP BY・OR・FIELD() など)は従来どおりSQLを解釈せず、
	 * テーブルの全行をそのまま返す(その場合、絞り込みは呼び出し側のPHPコードが行う.
	 * 既存のRepositoryメソッドの多くがこの前提で書かれている).
	 *
	 * 列名を指定したSELECTでも、行は全列を持ったまま返す(呼び出し側は必要な列しか
	 * 読まないため、絞り込む必要が無い).
	 *
	 * @param string $query  SQL文字列(`FROM {table}` を含む前提).
	 * @param string $output 無視する(本プラグインは常に `ARRAY_A` で呼ぶ).
	 * @return array<int, array>
	 */
	public function get_results( $query, $output = 'ARRAY_A' ) {
		unset( $output );

		$this->get_results_calls[] = $query;

		$selected = $this->select_simple( $query );

		if ( null !== $selected ) {
			return $selected;
		}

		if ( 1 !== preg_match( '/FROM\s+(\S+)/i', $query, $matches ) ) {
			return array();
		}

		$table = $matches[1];

		return isset( $this->rows[ $table ] ) ? array_values( $this->rows[ $table ] ) : array();
	}

	/**
	 * 単一の値を返す.
	 *
	 * `SELECT COUNT(*) FROM {table} [WHERE ...]` の形(条件は `get_results()` と同じ
	 * 範囲)だけは、実際に行を数えて返す(コードレビュー指摘5で追加.実行履歴一覧の
	 * 総件数に使う).それ以外(`WPCV_Repository::reserve_run()` の `GET_LOCK()` 等)は
	 * 実 SQL を実行せず、`$this->get_var_return` をそのまま返す.どちらの場合も
	 * クエリは `get_var_calls` に記録する.
	 *
	 * @param string $query クエリ文字列.
	 * @return string|null
	 */
	public function get_var( $query ) {
		$this->get_var_calls[] = $query;

		if ( 1 === preg_match( '/^\s*SELECT\s+COUNT\(\s*\*\s*\)\s+FROM\s+(\S+)(?:\s+WHERE\s+(.+?))?\s*$/is', $query, $matches ) ) {
			$conditions = isset( $matches[2] ) ? $this->parse_where_conditions_strict( $matches[2] ) : array();

			if ( null !== $conditions ) {
				return (string) count( $this->filter_rows( $matches[1], $conditions ) );
			}
		}

		return $this->get_var_return;
	}

	/**
	 * `get_results()` 専用: 単純な形のSELECTを解釈して結果を返す
	 * (解釈できない形なら `null`.`get_results()` のdocblock参照).
	 *
	 * @param string $query SQL文字列.
	 * @return array<int, array>|null
	 */
	private function select_simple( $query ) {
		// `FORCE INDEX (...)` は本番のoptimizer向けのヒントのため、読み飛ばす.
		$pattern = '/^\s*SELECT\s+(\*|\w+(?:\s*,\s*\w+)*)\s+FROM\s+(\S+)(?:\s+FORCE\s+INDEX\s*\(\s*\w+\s*\))?'
			. '(?:\s+WHERE\s+(.+?))?'
			. '(?:\s+ORDER\s+BY\s+(\w+\s+(?:ASC|DESC)(?:\s*,\s*\w+\s+(?:ASC|DESC))*))?'
			. '(?:\s+LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?)?\s*$/is';

		if ( 1 !== preg_match( $pattern, $query, $matches ) ) {
			return null;
		}

		$where_str  = isset( $matches[3] ) ? $matches[3] : '';
		$conditions = '' === $where_str ? array() : $this->parse_where_conditions_strict( $where_str );

		if ( null === $conditions ) {
			return null;
		}

		$rows = $this->filter_rows( $matches[2], $conditions );

		if ( ! empty( $matches[4] ) ) {
			// `列 ASC|DESC, 列 ASC|DESC, ...` を左から順に比べる.
			$order_by = array();

			foreach ( explode( ',', $matches[4] ) as $piece ) {
				$parts      = preg_split( '/\s+/', trim( $piece ) );
				$order_by[] = array( $parts[0], 0 === strcasecmp( $parts[1], 'DESC' ) );
			}

			usort(
				$rows,
				static function ( $a, $b ) use ( $order_by ) {
					foreach ( $order_by as list( $column, $descending ) ) {
						$cmp = ( $a[ $column ] ?? null ) <=> ( $b[ $column ] ?? null );

						if ( 0 !== $cmp ) {
							return $descending ? -$cmp : $cmp;
						}
					}

					return 0;
				}
			);
		}

		if ( isset( $matches[5] ) && '' !== $matches[5] ) {
			$offset = isset( $matches[6] ) && '' !== $matches[6] ? (int) $matches[6] : 0;
			$rows   = array_slice( $rows, $offset, (int) $matches[5] );
		}

		return $rows;
	}

	/**
	 * 指定テーブルのうち、すべての条件を満たす行を返す(`select_simple()`・
	 * `get_var()` の COUNT で共有する).
	 *
	 * @param string $table      テーブル名.
	 * @param array  $conditions `parse_where_conditions_strict()` の戻り値.
	 * @return array<int, array>
	 */
	private function filter_rows( $table, array $conditions ) {
		$rows = array();

		foreach ( $this->rows[ $table ] ?? array() as $row ) {
			if ( $this->row_matches_conditions( $row, $conditions ) ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * `parse_where_conditions()` の厳密版: 1つでも扱えない条件があれば `null` を返す
	 * (黙って条件を落とすと、本番と異なる行を返してしまうため).`select_simple()`・
	 * `get_var()` 専用.
	 *
	 * `parse_where_conditions()` が扱う3種(`=`・`IN (...)`・`IS NULL`)に加えて、
	 * `IS NOT NULL` と比較演算子(`<`・`<=`・`>`・`>=`)も扱う(日時文字列の範囲指定・
	 * idのカーソル用).
	 * `OR`・括弧のネストは扱わない.
	 *
	 * @param string $where_str `WHERE` 句.
	 * @return array<int, array>|null
	 */
	private function parse_where_conditions_strict( $where_str ) {
		// `<>`/`!=` は扱わない(比較演算子の正規表現が `<` と誤って解釈するのを防ぐ).
		if ( 1 === preg_match( '/\sOR\s|<>|!=/i', $where_str ) ) {
			return null;
		}

		$conditions = array();

		foreach ( preg_split( '/\s+AND\s+/i', trim( $where_str ) ) as $piece ) {
			$piece = trim( $piece );

			if ( 1 === preg_match( '/^(\w+)\s+IS\s+NOT\s+NULL$/i', $piece, $matches ) ) {
				$conditions[] = array( 'is_not_null', $matches[1] );
				continue;
			}

			if ( 1 === preg_match( '/^(\w+)\s*(<=|>=|<|>)\s*(.+)$/s', $piece, $matches ) ) {
				$conditions[] = array( 'cmp', $matches[1], $this->parse_sql_value_literal( trim( $matches[3] ) ), $matches[2] );
				continue;
			}

			$parsed = $this->parse_where_conditions( $piece );

			if ( 1 !== count( $parsed ) ) {
				return null;
			}

			$conditions[] = $parsed[0];
		}

		return $conditions;
	}

	/**
	 * クエリを実行する(`WPCV_Repository::reserve_run()` の `RELEASE_LOCK()`、および
	 * `WPCV_Chunk_Result_Repository::commit_chunk()` 等の `START TRANSACTION`/
	 * `COMMIT`/`ROLLBACK` 用の簡易フェイク)。実 SQL は実行せず、呼び出しを記録
	 * するだけ.
	 *
	 * `$query_should_fail` が真の場合、呼び出しの記録(`query_calls`)はそのまま
	 * 行いつつ戻り値のみ `false` にする(本番の `$wpdb->query()` がSQLエラー時に
	 * 返す値を模す。v0.4.0コードレビューCR-03是正).
	 *
	 * v0.5 §Step2: `WPCV_File_State_Repository::upsert_many()` が発行する
	 * `INSERT ... VALUES (...), (...) ON DUPLICATE KEY UPDATE ...` のみ、
	 * `apply_bulk_upsert()` で `$rows` へ反映する(このテーブルは全件取得方式が
	 * 使えない規模のため、`upsert_many()` はテストダブルでもSQL経由でデータを
	 * 反映する必要がある。`WPCV_File_State_Repository` のクラスdocblock参照)。
	 * それ以外のクエリ(`START TRANSACTION`等)は従来どおり記録のみ.
	 *
	 * @param string $query クエリ文字列(記録のみ).
	 * @return bool `$query_should_fail` が真なら `false`。それ以外は常に `true`.
	 */
	public function query( $query ) {
		$this->query_calls[] = $query;

		if ( $this->query_should_fail ) {
			$this->last_error = 'WPCV_Test_Fake_WPDB: query_should_fail が true のため query() を失敗させました.';

			return false;
		}

		if ( 1 === preg_match( '/^INSERT INTO\s+(\S+)\s*\(([^)]+)\)\s*VALUES\s*(.+?)\s*ON DUPLICATE KEY UPDATE/is', $query, $matches ) ) {
			$this->apply_bulk_upsert( $matches[1], $matches[2], $matches[3] );
		} elseif ( 1 === preg_match( '/^UPDATE\s+(\S+)\s+SET\s+(.+?)\s+WHERE\s+(.+)$/is', $query, $matches ) ) {
			$this->apply_bulk_update( $matches[1], $matches[2], $matches[3] );
		}

		return true;
	}

	/**
	 * `UPDATE {table} SET col = val, ... WHERE cond AND cond ...` を解釈し、
	 * `$this->rows` へ反映する(`query()` 専用のヘルパー. v0.5後半 §Step12:
	 * `WPCV_Finding_Repository` の一括終了処理・一括 diff_state 設定が、
	 * `$wpdb->update()` では組み立てられない `IN (...)`/`IS NULL` 条件のWHEREを
	 * 生SQLで発行するようになったため追加した。`apply_bulk_upsert()` と同じ
	 * 「本プラグインが実際に発行する形だけを解釈する簡易パーサー」であり、
	 * 汎用SQLパーサーではない ―― WHERE は `AND` で結んだ
	 * `column = literal` / `column IN (literal, ...)` / `column IS NULL` の
	 * 3種のみ(`OR`・括弧のネストは非対応).
	 *
	 * @param string $table      テーブル名.
	 * @param string $set_str    `SET` 直後、`WHERE` 直前までのカラム=値のカンマ区切り文字列.
	 * @param string $where_str  `WHERE` 直後の条件文字列(`AND` 区切り).
	 * @return void
	 */
	private function apply_bulk_update( $table, $set_str, $where_str ) {
		if ( ! isset( $this->rows[ $table ] ) ) {
			return;
		}

		$assignments = $this->parse_set_assignments( $set_str );
		$conditions  = $this->parse_where_conditions( $where_str );

		foreach ( $this->rows[ $table ] as $id => $row ) {
			if ( $this->row_matches_conditions( $row, $conditions ) ) {
				$this->rows[ $table ][ $id ] = array_merge( $row, $assignments );
			}
		}
	}

	/**
	 * `apply_bulk_update()` 専用: `SET` 句(`col1 = 'a', col2 = 2` のような
	 * カンマ区切り)をカラム => 値の配列に変換する。値がカンマを含まない
	 * (このプラグインが実際にSETへ渡す値は整数・NULL・短い列挙文字列のみ)
	 * 前提のため、単純な `explode( ',', ... )` で十分.
	 *
	 * @param string $set_str `SET` 句.
	 * @return array<string, mixed>
	 */
	private function parse_set_assignments( $set_str ) {
		$assignments = array();

		foreach ( explode( ',', $set_str ) as $piece ) {
			if ( 1 === preg_match( '/^\s*(\w+)\s*=\s*(.+?)\s*$/s', $piece, $matches ) ) {
				$assignments[ $matches[1] ] = $this->parse_sql_value_literal( $matches[2] );
			}
		}

		return $assignments;
	}

	/**
	 * `apply_bulk_update()` 専用: `WHERE` 句(`AND` 区切り)を条件の配列に変換する.
	 * 各条件は `array( 'eq'|'in'|'is_null', column, value )` の形(`is_null` は
	 * 3要素目を持たない).
	 *
	 * @param string $where_str `WHERE` 句.
	 * @return array<int, array>
	 */
	private function parse_where_conditions( $where_str ) {
		$conditions = array();

		foreach ( preg_split( '/\s+AND\s+/i', trim( $where_str ) ) as $piece ) {
			$piece = trim( $piece );

			if ( 1 === preg_match( '/^(\w+)\s+IS\s+NULL$/i', $piece, $matches ) ) {
				$conditions[] = array( 'is_null', $matches[1] );
				continue;
			}

			if ( 1 === preg_match( '/^(\w+)\s+IN\s*\((.+)\)$/is', $piece, $matches ) ) {
				$conditions[] = array(
					'in',
					$matches[1],
					array_map( array( $this, 'parse_sql_value_literal' ), $this->split_sql_value_literals( $matches[2] ) ),
				);
				continue;
			}

			if ( 1 === preg_match( '/^(\w+)\s*=\s*(.+)$/s', $piece, $matches ) ) {
				$conditions[] = array( 'eq', $matches[1], $this->parse_sql_value_literal( trim( $matches[2] ) ) );
			}
		}

		return $conditions;
	}

	/**
	 * `apply_bulk_update()` 専用: 1行が `parse_where_conditions()` の全条件を
	 * 満たすかどうかを判定する(すべて `AND`).
	 *
	 * @param array $row        行.
	 * @param array $conditions `parse_where_conditions()` の戻り値.
	 * @return bool
	 */
	private function row_matches_conditions( array $row, array $conditions ) {
		foreach ( $conditions as $condition ) {
			$type   = $condition[0];
			$column = $condition[1];
			$value  = array_key_exists( $column, $row ) ? $row[ $column ] : null;

			if ( 'is_null' === $type && null !== $value ) {
				return false;
			}

			// `parse_where_conditions_strict()` のみが作る.
			if ( 'is_not_null' === $type && null === $value ) {
				return false;
			}

			if ( 'eq' === $type && $value !== $condition[2] ) {
				return false;
			}

			if ( 'in' === $type && ! in_array( $value, $condition[2], true ) ) {
				return false;
			}

			// 比較演算子(`parse_where_conditions_strict()` のみが作る).
			// SQLと同じく、NULLとの比較は常に偽とする.
			if ( 'cmp' === $type ) {
				if ( null === $value ) {
					return false;
				}

				$cmp = $value <=> $condition[2];
				$ok  = array(
					'<'  => $cmp < 0,
					'<=' => $cmp <= 0,
					'>'  => $cmp > 0,
					'>=' => $cmp >= 0,
				);

				if ( ! $ok[ $condition[3] ] ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * `INSERT ... VALUES (...), (...) ON DUPLICATE KEY UPDATE ...` を解釈し、
	 * `$this->rows` へ反映する(`query()` 専用のヘルパー. v0.5 §Step2).
	 *
	 * 本プラグインが実際に発行する形(1行1タプル、値はクォート済み文字列/整数/
	 * `NULL`リテラルのいずれか)だけを解釈する簡易パーサーであり、汎用SQL
	 * パーサーではない。一意キー(`state_key`)が既存行と一致すれば
	 * `first_seen_run_id`以外の列をマージ更新し(本番の`ON DUPLICATE KEY UPDATE`
	 * 句が`first_seen_run_id`を含まないのと同じ意味)、一致しなければ新規行として
	 * 追加する.
	 *
	 * @param string $table       テーブル名.
	 * @param string $columns_str カラム名のカンマ区切り文字列(括弧の中身).
	 * @param string $values_str  `VALUES`直後のタプル列全体(先頭・末尾の丸括弧込み).
	 * @return void
	 */
	private function apply_bulk_upsert( $table, $columns_str, $values_str ) {
		$columns = array_map( 'trim', explode( ',', $columns_str ) );

		$values_str = trim( $values_str );
		$values_str = substr( $values_str, 1, -1 ); // 先頭 "(" と末尾 ")" を除去する.
		$tuples     = preg_split( '/\)\s*,\s*\(/', $values_str );

		if ( ! isset( $this->next_id[ $table ] ) ) {
			$this->next_id[ $table ] = 1;
		}

		if ( ! isset( $this->rows[ $table ] ) ) {
			$this->rows[ $table ] = array();
		}

		foreach ( $tuples as $tuple ) {
			$literals = $this->split_sql_value_literals( $tuple );
			$row      = array();

			foreach ( $columns as $index => $column ) {
				$row[ $column ] = $this->parse_sql_value_literal( $literals[ $index ] );
			}

			$existing_id = null;

			foreach ( $this->rows[ $table ] as $id => $existing_row ) {
				if ( isset( $existing_row['state_key'] ) && $existing_row['state_key'] === $row['state_key'] ) {
					$existing_id = $id;
					break;
				}
			}

			if ( null !== $existing_id ) {
				// 本番の ON DUPLICATE KEY UPDATE 句が first_seen_run_id を
				// 含まない(`WPCV_File_State_Repository::upsert_many()` 参照)のと
				// 同じ意味で、既存行の first_seen_run_id は上書きしない.
				unset( $row['first_seen_run_id'] );
				$this->rows[ $table ][ $existing_id ] = array_merge( $this->rows[ $table ][ $existing_id ], $row );
			} else {
				$id                         = $this->next_id[ $table ]++;
				$row['id']                  = $id;
				$this->rows[ $table ][ $id ] = $row;
			}
		}
	}

	/**
	 * SQLの値リテラル列("'a', 123, NULL, 'b'" のような文字列)を、各要素の
	 * 生文字列表現の配列に分割する(`apply_bulk_upsert()` 専用のヘルパー).
	 *
	 * `'...'`(シングルクォート文字列。`prepare()`が行う`\'`/`\\`エスケープを
	 * 許容する正規表現にしてある。`state_key`のような生バイト値はシングル
	 * クォート・バックスラッシュを含みうるため、これが無いと値の途中で
	 * クォートが閉じたと誤認しタプルの区切りを見失う)・整数・`NULL`の
	 * 3種のみを解釈する.
	 *
	 * @param string $literal_list カンマ区切りのSQLリテラル列(1タプル分).
	 * @return string[]
	 */
	private function split_sql_value_literals( $literal_list ) {
		preg_match_all( "/'(?:[^'\\\\]|\\\\.)*'|-?[0-9]+|NULL/i", $literal_list, $matches );

		return $matches[0];
	}

	/**
	 * SQLの値リテラル1つを、対応するPHPの値(文字列/整数/`null`)へ変換する
	 * (`apply_bulk_upsert()` 専用のヘルパー)。文字列値は `prepare()` が施した
	 * `\'`/`\\` エスケープを解除してから返す(`prepare()` のdocblock参照).
	 *
	 * @param string $literal `split_sql_value_literals()` が返す1要素.
	 * @return string|int|null
	 */
	private function parse_sql_value_literal( $literal ) {
		if ( 0 === strcasecmp( $literal, 'NULL' ) ) {
			return null;
		}

		if ( 1 === preg_match( "/^'(.*)'$/s", $literal, $matches ) ) {
			return $this->unescape_sql_string( $matches[1] );
		}

		return (int) $literal;
	}

	/**
	 * `prepare()` が `%s` の値に施したエスケープ(`\'` → `'`、`\\` → `\`)を
	 * 解除する(`parse_sql_value_literal()` 専用のヘルパー).
	 *
	 * `str_replace()` を2回連続で適用する素朴な実装は、変換順序によって
	 * 二重エスケープを誤って壊す(例: 元の値が `\\` 1個だった場合、
	 * `\'`→`'` の変換を先に行うと安全だが、`\\`→`\` を先に行うと `\\'` を
	 * `\'`→`'` に変換し損ねる)。そのため先頭から1文字ずつ走査し、`\` が
	 * 出たら次の1文字をエスケープ対象として無条件に採用する一般的な
	 * デコード方式にしてある.
	 *
	 * @param string $escaped `prepare()` がエスケープ済みの文字列(引用符の中身).
	 * @return string
	 */
	private function unescape_sql_string( $escaped ) {
		$result = '';
		$length = strlen( $escaped );

		for ( $i = 0; $i < $length; $i++ ) {
			if ( '\\' === $escaped[ $i ] && $i + 1 < $length ) {
				++$i;
			}

			$result .= $escaped[ $i ];
		}

		return $result;
	}

	/**
	 * 文字セット・照合順序の句を返す(`WPCV_Migrator::table_definitions()` 専用の
	 * 簡易フェイク). 実 `$wpdb->get_charset_collate()` と異なり固定文字列を返すだけ
	 * (テストは列・indexの有無のみを見るため、文字セットの値自体は検証対象外).
	 *
	 * @return string
	 */
	public function get_charset_collate() {
		return '';
	}

	/**
	 * プレースホルダーを実引数へ置換する(実 `$wpdb->prepare()` の簡易フェイク)。
	 *
	 * `WPCV_File_State_Repository`(v0.5 §Step2)が `state_key`(sha256の生バイト)を
	 * `%s` で渡すようになったため、単純な `vsprintf()` 置換では成立しなくなった
	 * (生バイトにシングルクォート `'` やバックスラッシュ `\` が含まれる確率は
	 * 32バイトあれば無視できない大きさになり、実際にテストで踏んだ)。
	 * `%s` の値はシングルクォート・バックスラッシュを最小限エスケープしてから
	 * 引用符で囲む(本番の `$wpdb->prepare()` の簡易近似). `apply_bulk_upsert()`
	 * 側の `split_sql_value_literals()`/`unescape_sql_string()` がこのエスケープに
	 * 対応する形でVALUES句を読み戻す(対称性が必要).
	 *
	 * @param string $query    クエリ(`%s`/`%d` プレースホルダーを含む).
	 * @param mixed  ...$args  プレースホルダーに対応する値.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$index = 0;

		return preg_replace_callback(
			'/%[sd]/',
			function ( $matches ) use ( &$index, $args ) {
				$value = $args[ $index ] ?? '';
				++$index;

				if ( '%d' === $matches[0] ) {
					return (string) (int) $value;
				}

				$escaped = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value );

				return "'{$escaped}'";
			},
			$query
		);
	}
}

/**
 * コアのみ(常に成功するマニフェスト)を持つ、手書きスタブ組み立ての
 * `WPCV_Run_Coordinator`・`WPCV_Chunk_Dispatcher` と、それが使うのと同一インスタンスの
 * 各 Repository / `WPCV_Test_Fake_WPDB` を組で作る.
 *
 * `CliCommandTest` と `RunnerAsyncTest` がどちらも「composition root
 * (`WPCV_Plugin::run_coordinator()` / `WPCV_Plugin::run_repository()`)を丸ごと
 * 差し替えて呼び出し結果を検証する」ことを必要とするため、重複を避けてここに
 * 集約する(doubles.php の集約方針参照)。v0.3.1 §Step1で `WPCV_Run_Coordinator::run()`
 * が予約済み run id を要求するようになったため、呼び出し元は本番の
 * `WPCV_Plugin::run_coordinator()` と `WPCV_Plugin::run_repository()` が同じ
 * `WPCV_Run_Repository` インスタンスを共有するのと同様に、`run_repository` を
 * `wpcv_test_inject_run_repository()` で必ず一緒に差し替えること(でなければ
 * `reserve_run()` が本番の `global $wpdb` を必要とする composition root へ
 * フォールバックしてしまう)。v0.4.0 §Step1で `WPCV_Repository` を3責務に分割した
 * のに合わせ、この関数が返す配列も `run_repository`/`target_run_repository`/
 * `finding_repository` に分割した.
 *
 * v0.4.0 §Step5で `WPCV_Run_Coordinator` がchunk dispatcherベースへ書き換わった
 * ことに合わせ、`dispatcher`/`chunk_result_repository` も返すようにした
 * (`WPCV_Runner_Async::run_async_action()` が `WPCV_Plugin::chunk_dispatcher()` を
 * 直接呼ぶため、それをテストする場合は `wpcv_test_inject_chunk_dispatcher()`/
 * `wpcv_test_inject_chunk_result_repository()`/`wpcv_test_inject_target_run_repository()`/
 * `wpcv_test_inject_finding_repository()` も一緒に差し替えること)。dispatcherの
 * continuation schedulerは既定でno-op(テストが明示的に検証する場合のみ
 * `$continuation_scheduler` 引数で差し替える)。
 *
 * v0.4.0 §Step8で `WPCV_Suppression_Repository` を組み立てに加え、
 * `WPCV_Run_Planner`/`WPCV_Chunk_Result_Repository` に注入するようにした
 * (`suppression_repository` も返す。`wpcv_test_inject_suppression_repository()`
 * で `WPCV_Plugin::suppression_repository()` も一緒に差し替えること)。
 *
 * @param WPCV_Manifest_Source|null      $core_source            省略時は常に成功する空マニフェストのfake.
 * @param WPCV_Manifest_Source|null      $plugin_source          省略時は `manifest_not_found` を返すfake.
 * @param callable|null                  $continuation_scheduler 省略時はno-op(`WPCV_Chunk_Dispatcher`
 *                                                          のクラス docblock 参照).
 * @param WPCV_Update_Event_Matcher|null $update_event_matcher   D5・D6の突き合わせ(v0.6 §Step4).
 *                                                          省略時は`null`(既存v0.5の挙動のまま).
 * @return array{
 *     coordinator: WPCV_Run_Coordinator,
 *     dispatcher: WPCV_Chunk_Dispatcher,
 *     run_repository: WPCV_Run_Repository,
 *     target_run_repository: WPCV_Target_Run_Repository,
 *     finding_repository: WPCV_Finding_Repository,
 *     chunk_result_repository: WPCV_Chunk_Result_Repository,
 *     suppression_repository: WPCV_Suppression_Repository,
 *     file_state_repository: WPCV_File_State_Repository,
 *     update_event_repository: WPCV_Update_Event_Repository,
 *     update_event_matcher: WPCV_Update_Event_Matcher,
 *     wpdb: WPCV_Test_Fake_WPDB,
 * }
 */
function wpcv_test_make_fake_environment( $core_source = null, $plugin_source = null, $continuation_scheduler = null, ?WPCV_Update_Event_Matcher $update_event_matcher = null ) {
	$core_source   = $core_source ?? new WPCV_Test_Fake_Manifest_Source(
		array(
			'manifest_status' => 'ok',
			'error_code'      => null,
			'files'           => array(),
		)
	);
	$plugin_source = $plugin_source ?? new WPCV_Test_Fake_Manifest_Source(
		array(
			'manifest_status' => 'missing',
			'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
			'files'           => array(),
		)
	);

	$wpdb                    = new WPCV_Test_Fake_WPDB();
	$now                     = static function () {
		return '2026-09-08 12:00:00';
	};
	$run_repository          = new WPCV_Run_Repository( $wpdb, $now );
	$target_run_repository   = new WPCV_Target_Run_Repository( $wpdb, $now );
	$finding_repository      = new WPCV_Finding_Repository( $wpdb );
	$suppression_repository  = new WPCV_Suppression_Repository( $wpdb, $now );
	$file_state_repository   = new WPCV_File_State_Repository( $wpdb, $now );
	$chunk_result_repository = new WPCV_Chunk_Result_Repository( $wpdb, $target_run_repository, $finding_repository, $suppression_repository, $file_state_repository );
	$update_event_repository = new WPCV_Update_Event_Repository( $wpdb, $now );

	// v0.6 §Step4: 常に有効な `WPCV_Update_Event_Matcher` を使う(呼び出し元が
	// 明示的に渡さない場合、この環境の `$wpdb`/`$run_repository` を使ったものを
	// 自動的に組み立てる). `wpcv_update_events` に何も記録されていなければ
	// 「記録なし」の判定結果になり、既存(v0.5)のテストの挙動は変わらないため、
	// 既存呼び出し元に影響しない.
	$update_event_matcher = $update_event_matcher ?? new WPCV_Update_Event_Matcher( $update_event_repository, $run_repository );

	$dispatcher = new WPCV_Chunk_Dispatcher(
		$run_repository,
		$target_run_repository,
		$chunk_result_repository,
		new WPCV_Chunk_Verifier(),
		$core_source,
		$plugin_source,
		new WPCV_Unknown_File_Scanner(),
		null,
		$continuation_scheduler ?? static function () {},
		// Repository群に注入する `$now`(固定の過去日時)と時刻源を揃える
		// (`WPCV_Chunk_Dispatcher` の `$now` プロパティのdocblock参照。ずれると
		// `deadline_at` が常に「過去」と誤判定され、すべてのrunが即座に
		// `aborted` になる).
		static function () use ( $now ) {
			return strtotime( call_user_func( $now ) );
		},
		$file_state_repository,
		null,
		$update_event_matcher
	);

	$coordinator = new WPCV_Run_Coordinator( new WPCV_Run_Planner( $suppression_repository ), $run_repository, $target_run_repository, $dispatcher );

	return array(
		'coordinator'              => $coordinator,
		'dispatcher'               => $dispatcher,
		'run_repository'           => $run_repository,
		'target_run_repository'    => $target_run_repository,
		'finding_repository'       => $finding_repository,
		'chunk_result_repository'  => $chunk_result_repository,
		'suppression_repository'   => $suppression_repository,
		'file_state_repository'    => $file_state_repository,
		'update_event_repository'  => $update_event_repository,
		'update_event_matcher'     => $update_event_matcher,
		'wpdb'                     => $wpdb,
	);
}

/**
 * `WPCV_Plugin::run_coordinator()` が返すインスタンスを差し替える
 * (private static プロパティへのリフレクション).
 *
 * @param WPCV_Run_Coordinator|null $coordinator 差し替え先. 省略時はキャッシュを空に戻す.
 * @return void
 */
function wpcv_test_inject_run_coordinator( $coordinator = null ) {
	$property = new ReflectionProperty( WPCV_Plugin::class, 'run_coordinator' );
	$property->setAccessible( true );
	$property->setValue( null, $coordinator );
}

/**
 * `WPCV_Plugin::run_repository()` が返すインスタンスを差し替える
 * (private static プロパティへのリフレクション。`wpcv_test_inject_run_coordinator()`
 * と同じ手法. v0.3 §Step6の `WPCV_Scheduler::handle_event()` が
 * `WPCV_Plugin::run_repository()` 経由でDBへアクセスするため、実 `global $wpdb`
 * 無しでテストするのに必要).
 *
 * @param WPCV_Run_Repository|null $repository 差し替え先. 省略時はキャッシュを空に戻す.
 * @return void
 */
function wpcv_test_inject_run_repository( $repository = null ) {
	$property = new ReflectionProperty( WPCV_Plugin::class, 'run_repository' );
	$property->setAccessible( true );
	$property->setValue( null, $repository );
}

/**
 * `WPCV_Plugin::target_run_repository()` が返すインスタンスを差し替える
 * (`wpcv_test_inject_run_repository()` と同じ手法. v0.4.0 §Step1).
 *
 * @param WPCV_Target_Run_Repository|null $repository 差し替え先. 省略時はキャッシュを空に戻す.
 * @return void
 */
function wpcv_test_inject_target_run_repository( $repository = null ) {
	$property = new ReflectionProperty( WPCV_Plugin::class, 'target_run_repository' );
	$property->setAccessible( true );
	$property->setValue( null, $repository );
}

/**
 * `WPCV_Plugin::finding_repository()` が返すインスタンスを差し替える
 * (`wpcv_test_inject_run_repository()` と同じ手法. v0.4.0 §Step1).
 *
 * @param WPCV_Finding_Repository|null $repository 差し替え先. 省略時はキャッシュを空に戻す.
 * @return void
 */
function wpcv_test_inject_finding_repository( $repository = null ) {
	$property = new ReflectionProperty( WPCV_Plugin::class, 'finding_repository' );
	$property->setAccessible( true );
	$property->setValue( null, $repository );
}

/**
 * `WPCV_Plugin::chunk_result_repository()` が返すインスタンスを差し替える
 * (`wpcv_test_inject_run_repository()` と同じ手法. v0.4.0 §Step5).
 *
 * @param WPCV_Chunk_Result_Repository|null $repository 差し替え先. 省略時はキャッシュを空に戻す.
 * @return void
 */
function wpcv_test_inject_chunk_result_repository( $repository = null ) {
	$property = new ReflectionProperty( WPCV_Plugin::class, 'chunk_result_repository' );
	$property->setAccessible( true );
	$property->setValue( null, $repository );
}

/**
 * `WPCV_Plugin::chunk_dispatcher()` が返すインスタンスを差し替える
 * (`wpcv_test_inject_run_repository()` と同じ手法. v0.4.0 §Step5:
 * `WPCV_Runner_Async::run_async_action()` が `WPCV_Plugin::chunk_dispatcher()` を
 * 直接呼ぶようになったため、実 `global $wpdb` 無しでテストするのに必要).
 *
 * @param WPCV_Chunk_Dispatcher|null $dispatcher 差し替え先. 省略時はキャッシュを空に戻す.
 * @return void
 */
function wpcv_test_inject_chunk_dispatcher( $dispatcher = null ) {
	$property = new ReflectionProperty( WPCV_Plugin::class, 'chunk_dispatcher' );
	$property->setAccessible( true );
	$property->setValue( null, $dispatcher );
}

/**
 * `WPCV_Plugin::sync_dispatcher()` が返すインスタンスを差し替える
 * (`wpcv_test_inject_run_repository()` と同じ手法. v0.4.0 §Step6:
 * `WPCV_Rest_Run_Controller::handle_run()` が `WPCV_Plugin::sync_dispatcher()` を
 * 直接呼ぶようになったため、実 `global $wpdb` 無しでテストするのに必要).
 *
 * @param WPCV_Chunk_Dispatcher|null $dispatcher 差し替え先. 省略時はキャッシュを空に戻す.
 * @return void
 */
function wpcv_test_inject_sync_dispatcher( $dispatcher = null ) {
	$property = new ReflectionProperty( WPCV_Plugin::class, 'sync_dispatcher' );
	$property->setAccessible( true );
	$property->setValue( null, $dispatcher );
}

/**
 * `WPCV_Plugin::suppression_repository()` が返すインスタンスを差し替える
 * (`wpcv_test_inject_run_repository()` と同じ手法. v0.4.0 §Step8).
 *
 * @param WPCV_Suppression_Repository|null $repository 差し替え先. 省略時はキャッシュを空に戻す.
 * @return void
 */
function wpcv_test_inject_suppression_repository( $repository = null ) {
	$property = new ReflectionProperty( WPCV_Plugin::class, 'suppression_repository' );
	$property->setAccessible( true );
	$property->setValue( null, $repository );
}

/**
 * `WPCV_Verifier` の各 `verify_*()` が返す target_run の最小形を作る.
 *
 * @param array $overrides 上書きするフィールド.
 * @return array
 */
function wpcv_test_make_target_run( array $overrides = array() ) {
	return array_merge(
		array(
			'target_id'       => 'core',
			'dimension'       => 'core',
			'slug'            => 'wordpress',
			'version'         => '6.8',
			'source'          => 'wporg',
			'source_ref'      => null,
			'manifest_status' => 'ok',
			'status'          => 'success',
			'error_code'      => null,
			'error_message'   => null,
			'files_total'     => 10,
			'files_verified'  => 10,
			'findings_total'  => 0,
		),
		$overrides
	);
}

/**
 * `WPCV_Verifier` の各 `verify_*()` が返す finding の最小形を作る.
 *
 * @param array $overrides 上書きするフィールド.
 * @return array
 */
function wpcv_test_make_finding( array $overrides = array() ) {
	return array_merge(
		array(
			'target_id'      => 'core',
			'dimension'      => 'core',
			'slug'           => 'wordpress',
			'version'        => '6.8',
			'source'         => 'wporg',
			'path'           => 'wp-admin/index.php',
			'status'         => 'modified',
			'severity'       => 'high',
			'hash_algorithm' => 'sha256',
			'expected_hash'  => str_repeat( 'a', 64 ),
			'actual_hash'    => str_repeat( 'b', 64 ),
			'file_size'      => 123,
		),
		$overrides
	);
}

/**
 * `wpcv_findings` の1行分(`run_id`・`suppressed_by`・`suppression_id`・
 * `closed_at`・`closed_reason`込み)を作る(v0.4.0 §Step7:
 * `WPCV_Finding_Repository::query()` のテスト用。`wpcv_test_make_finding()` は
 * `save_findings()` が挿入する列のみを持つため、`run_id` 等はここで別途持つ).
 *
 * @param array $overrides 上書きするフィールド.
 * @return array
 */
function wpcv_test_make_finding_row( array $overrides = array() ) {
	return array_merge(
		wpcv_test_make_finding(),
		array(
			'run_id'          => 1,
			'target_run_id'   => 1,
			'suppressed_by'   => null,
			'suppression_id'  => null,
			'closed_at'       => null,
			'closed_reason'   => null,
			// v0.5後半 §Step10で追加した列(WPCV_Finding_Repositoryの差分処理系
			// メソッドが読み書きする。既定は「差分処理がまだ触れていない finding」).
			'detail'          => null,
			'finding_key'     => null,
			'diff_state'      => null,
			'notified_at'     => null,
			'ended_in_run_id' => null,
			'end_reason'      => null,
		),
		$overrides
	);
}

/**
 * テスト用の最小 `WP_REST_Request` ダブル.
 *
 * `get_header()` はヘッダー名を渡すと値を返すだけの実装。`get_param()` は
 * 呼ばれた時点で失敗させる — `WPCV_Rest_Token::extract_from_request()` が
 * クエリパラメータを一切読まない(§12.3の要件)ことを、レスポンスの中身では
 * なく「そもそも呼ばれない」という形で保証するため.
 */
class WPCV_Test_Fake_Rest_Request {

	/**
	 * ヘッダー名(小文字) => 値.
	 *
	 * @var array<string,string>
	 */
	private $headers;

	/**
	 * コンストラクタ.
	 *
	 * @param array<string,string> $headers ヘッダー名(任意の大文字小文字) => 値.
	 */
	public function __construct( array $headers = array() ) {
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	/**
	 * ヘッダーを返す.
	 *
	 * @param string $name ヘッダー名(大文字小文字を問わない).
	 * @return string|null
	 */
	public function get_header( $name ) {
		$name = strtolower( $name );

		return isset( $this->headers[ $name ] ) ? $this->headers[ $name ] : null;
	}

	/**
	 * 呼ばれたら失敗させる. クエリパラメータを読んでいないことの検証用.
	 *
	 * @param string $name パラメータ名.
	 * @return never
	 * @throws RuntimeException 呼ばれた時点で必ず投げる.
	 */
	public function get_param( $name ) {
		throw new RuntimeException( 'get_param() should never be called: ' . esc_html( $name ) );
	}
}
