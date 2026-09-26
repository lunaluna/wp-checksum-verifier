<?php
/**
 * WPCV_File_State_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_file_states` テーブルの永続化を担当する(v0.5 §4.2 Step2. rev.3 §3参照).
 *
 * Stat差分検知(層1)のベースライン(path単位のsize/ctime/mtime)を保持する。
 * このテーブルは1 targetあたり数百〜数万行になりうる(プラグイン30個・8万
 * ファイル規模を想定. rev.3 §3.3)ため、`WPCV_Suppression_Repository`/
 * `WPCV_Target_Run_Repository::all_rows()` のような「テーブル全体を読んで
 * PHP側で絞り込む」方式は採らない。代わりに `WPCV_Finding_Repository::query()`
 * が既に採用しているパターン(SQLの`WHERE`句で本番の効率を確保しつつ、
 * テストダブル`WPCV_Test_Fake_WPDB::get_results()`がWHERE句を解釈しないため
 * PHP側でも同じ条件を再フィルタする)を踏襲する。
 *
 * `upsert_many()` だけは「N行を1クエリのON DUPLICATE KEY UPDATEにまとめる」
 * ことがrev.3 §3.5で明示的に要求されており、全件取得方式はそもそも成立しない
 * (INSERTの)ため、テストダブル側にVALUES句の簡易パーサーを追加している
 * (`WPCV_Test_Fake_WPDB::query()` 参照)。
 *
 * `delete_stale()` は「対象行を`find_stale()`で取得し、id単位で`$wpdb->delete()`
 * を呼ぶ」設計にした(削除対象は「前回スキャン以降見えなくなったファイル」の
 * みであり、通常は総ファイル数のごく一部に留まるため、1クエリへの最適化より
 * 実装の単純さ・既存`delete()`メソッドとの一貫性を優先した。2026-09-13
 * ユーザー確認済み)。
 */
class WPCV_File_State_Repository {

	/**
	 * `upsert_many()` が読み書きする列と、SQLプレースホルダー種別のマップ.
	 *
	 * `id`(auto increment)と`updated_at`(呼び出し時点で`$now`から設定するため
	 * 個別に扱う)は含めない。連想配列の順序がそのままINSERT文の列順になる.
	 *
	 * @var array<string,string>
	 */
	private const UPSERT_COLUMNS = array(
		'state_key'         => '%s',
		'target_id'         => '%s',
		'dimension'         => '%s',
		'slug'              => '%s',
		'path'              => '%s',
		'file_size'         => '%d',
		'ctime'             => '%d',
		'mtime'             => '%d',
		'content_hash'      => '%s',
		'hash_algorithm'    => '%s',
		'baseline_version'  => '%s',
		'first_seen_run_id' => '%d',
		'last_seen_run_id'  => '%d',
	);

	/**
	 * `$wpdb` 相当のオブジェクト(`get_results()` / `delete()` / `query()` /
	 * `prepare()` / `base_prefix` を持つもの).
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
	 * `state_key`(`sha256( target_id . "\0" . path )` の生バイト)を計算する
	 * (rev.3 §3.3のデータモデル定義どおり).
	 *
	 * `\0`区切りにする理由: target_id・path はいずれも可変長の文字列であり、
	 * 単純な連結(`$target_id . $path`)では `target_id="a"` + `path="bc"` と
	 * `target_id="ab"` + `path="c"` が同じキーになる衝突を防げないため.
	 *
	 * @param string $target_id 対象の target_id.
	 * @param string $path      ABSPATH相対パス.
	 * @return string sha256の生バイト(32バイト固定長の binary string).
	 */
	public static function compute_state_key( $target_id, $path ) {
		return hash( 'sha256', $target_id . "\0" . $path, true );
	}

	/**
	 * 指定した `state_key` の一覧に該当する行をまとめて取得する
	 * (`verify_stat_chunk()` が「今回のchunkで走査したpath群の前回値」を
	 * 一括で引くために使う想定. v0.5 §Step5以降).
	 *
	 * @param string[] $state_keys `compute_state_key()` が返す生バイト文字列の配列.
	 * @return array<int, array> state_key => 行、ではなく単純な配列(呼び出し側で
	 *                            `state_key`をキーに引き直す).
	 */
	public function find_by_state_keys( array $state_keys ) {
		if ( empty( $state_keys ) ) {
			return array();
		}

		$table        = $this->wpdb->base_prefix . 'wpcv_file_states';
		$placeholders = implode( ',', array_fill( 0, count( $state_keys ), '%s' ) );
		$sql          = "SELECT * FROM {$table} WHERE state_key IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name + placeholder count only) built above; values are bound via prepare() here.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $state_keys ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		// クラスdocblock参照: テストダブルはWHERE句を解釈しないため、本番・
		// テスト両方で正しく動くようPHP側でも同じ条件を再フィルタする.
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $state_keys ) {
					return in_array( $row['state_key'], $state_keys, true );
				}
			)
		);
	}

	/**
	 * N行を1クエリの `INSERT ... ON DUPLICATE KEY UPDATE` でまとめて反映する
	 * (rev.3 §3.5「実装上の落とし穴」: `upsert_many()`をN回の`insert()`に
	 * 分解すると、8万ファイル規模のtargetでchunkごとに数百回のクエリが
	 * 発生し本番の負荷が跳ね上がるため、必ず1クエリにまとめる).
	 *
	 * `first_seen_run_id`は`ON DUPLICATE KEY UPDATE`句に含めない(既存行が
	 * 見つかった場合、最初に検出されたrunのidを保持し続けるため。上書きすると
	 * 「いつからこのファイルが存在するか」の情報が失われる).
	 *
	 * @param array<int, array> $rows `UPSERT_COLUMNS`のキーを持つ連想配列の配列
	 *                                (`state_key`は`compute_state_key()`で計算済みのものを渡す).
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->query()` がSQLエラーで `false` を返した場合.
	 */
	public function upsert_many( array $rows ) {
		if ( empty( $rows ) ) {
			return;
		}

		$table = $this->wpdb->base_prefix . 'wpcv_file_states';
		$now   = call_user_func( $this->now );

		$value_tuples = array();
		$args         = array();

		foreach ( $rows as $row ) {
			$row['updated_at'] = $now;
			$value_tuples[]    = $this->build_value_tuple( $row, $args );
		}

		$columns = implode( ', ', array_merge( array_keys( self::UPSERT_COLUMNS ), array( 'updated_at' ) ) );
		$update  = implode(
			', ',
			array_map(
				static function ( $column ) {
					return "{$column} = VALUES({$column})";
				},
				// first_seen_run_id は更新しない(docblock参照). state_keyは
				// UNIQUE KEYそのものであり、UPDATE対象に含めても意味が無い
				// (一致条件そのものなので値は変わらない)ため除外する.
				array_diff( array_merge( array_keys( self::UPSERT_COLUMNS ), array( 'updated_at' ) ), array( 'state_key', 'first_seen_run_id' ) )
			)
		);

		$sql = "INSERT INTO {$table} ({$columns}) VALUES " . implode( ', ', $value_tuples ) . " ON DUPLICATE KEY UPDATE {$update}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table/column names are fixed literals built above; row values are bound via prepare() here.
		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, $args ) );

		// v0.5 §Step6: `commit_chunk()` のトランザクション内で呼ばれるようになったため、
		// 他の Repository と同じく SQL エラーを例外にする(v0.4.0 CR-03 と同じ理由.
		// 失敗を見逃すと findings だけ確定しベースラインが古いまま残る).
		if ( false === $result ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_File_State_Repository::upsert_many() の query に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}
	}

	/**
	 * 指定した target で、今回の run より前に最後に見られた行(=今回走査で
	 * 見つからなかった=削除された可能性がある行)を取得する
	 * (rev.3 §3.3「削除検出は`last_seen_run_id`方式」参照).
	 *
	 * @param string $target_id      対象の target_id.
	 * @param int    $current_run_id 今回の run の id(この値未満の`last_seen_run_id`
	 *                               を持つ行が対象).
	 * @return array<int, array>
	 */
	public function find_stale( $target_id, $current_run_id ) {
		return $this->stale_rows( $target_id, $current_run_id );
	}

	/**
	 * `find_stale()` が返す行を1件ずつ削除する(`missing` finding化した後に
	 * 呼ぶ想定. v0.5 §Step7).
	 *
	 * クラスdocblock参照: 1クエリのIN句にまとめず、id単位で既存の`$wpdb->delete()`
	 * (厳密等価WHEREのみ対応)をそのまま使う設計にした.
	 *
	 * @param string $target_id      対象の target_id.
	 * @param int    $current_run_id 今回の run の id.
	 * @return array<int, array> 削除した行(`missing` finding組み立て用に呼び出し元へ返す).
	 */
	public function delete_stale( $target_id, $current_run_id ) {
		$stale = $this->stale_rows( $target_id, $current_run_id );
		$table = $this->wpdb->base_prefix . 'wpcv_file_states';

		foreach ( $stale as $row ) {
			$this->wpdb->delete(
				$table,
				array( 'id' => (int) $row['id'] ),
				array( '%d' )
			);
		}

		return $stale;
	}

	/**
	 * 指定した target のベースラインを丸ごと削除する(version変更時の
	 * ベースライン破棄. rev.3 §3.7-a参照. v0.5 §Step7).
	 *
	 * @param string $target_id 対象の target_id.
	 * @return int 削除した行数.
	 *
	 * @throws RuntimeException `$wpdb->delete()` がSQLエラーで `false` を返した場合.
	 */
	public function delete_by_target( $target_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_file_states';

		$deleted = $this->wpdb->delete(
			$table,
			array( 'target_id' => (string) $target_id ),
			array( '%s' )
		);

		// v0.5 §Step7: commit_chunk() のトランザクション内で呼ぶため、SQL エラーは
		// 例外にして ROLLBACK させる(黙って0件扱いにすると古いベースラインが残る).
		if ( false === $deleted ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_File_State_Repository::delete_by_target() の delete に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}

		return (int) $deleted;
	}

	/**
	 * 今回の run で列挙された stat target 以外の行をすべて削除する
	 * (v0.5 §Step9. D8「アンインストールされたプラグインの `wpcv_file_states` 行は、
	 * 差分処理のときに消す」).
	 *
	 * アンインストールされたプラグインは、次回以降の run で `WPCV_Run_Planner` が
	 * そもそも target_run を作らなくなる(=「列挙されない」). stale行検出
	 * (`delete_stale()`)は同じ target 内での path 単位の削除であり、target 自体が
	 * 二度と現れないケースは対象にできないため、別のメソッドとして用意する.
	 *
	 * このテーブルは stat 差分検知(層1)専用であり、全行の target_id は必ず
	 * stat target(クラスdocblock参照). `SELECT DISTINCT target_id` は対象が
	 * target数(数十件程度)に留まるため、1 target あたり数万行になりうる本体の
	 * 行数には影響されない.
	 *
	 * @param string[] $enumerated_target_ids 今回の run で target_run が作られた
	 *                                        stat target_id の一覧(status を問わない.
	 *                                        `http_error` 等で `unverifiable`/`failed` に
	 *                                        なった target も、`checksum_covered` で
	 *                                        `skipped` になった target も「列挙された」
	 *                                        ことに変わりはないため含める).
	 * @return string[] 削除した target_id の一覧.
	 *
	 * @throws RuntimeException `delete_by_target()` が例外を投げた場合、そのまま伝播する.
	 */
	public function delete_targets_not_enumerated( array $enumerated_target_ids ) {
		$table = $this->wpdb->base_prefix . 'wpcv_file_states';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only, no variables) built above.
		$rows = $this->wpdb->get_results( "SELECT DISTINCT target_id FROM {$table}", ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$existing_target_ids = array_unique(
			array_map(
				static function ( $row ) {
					return (string) $row['target_id'];
				},
				$rows
			)
		);

		$enumerated_target_ids = array_map( 'strval', $enumerated_target_ids );
		$stale_target_ids      = array_values( array_diff( $existing_target_ids, $enumerated_target_ids ) );

		foreach ( $stale_target_ids as $target_id ) {
			$this->delete_by_target( $target_id );
		}

		return $stale_target_ids;
	}

	/**
	 * 指定した複数の target のベースラインをまとめて削除する(v0.5 §Step9.
	 * `exclude_target` ルールが有効化された stat target の行を消す).
	 *
	 * `exclude_target` ルールが有効な stat target は、以後の run でも
	 * `status = skipped`・`error_code = excluded` の target_run が作られ続ける
	 * (`WPCV_Run_Planner::maybe_apply_exclude_target()`)ため、
	 * `delete_targets_not_enumerated()` の「列挙されない」には該当しない.
	 * どの target_id が今回 excluded になったかは plan 時点の情報を持つ
	 * 呼び出し側(v0.5 §Step12 の差分処理)が判定し、その一覧をそのまま渡す想定
	 * (このメソッド自身は status/error_code を判定しない).
	 *
	 * @param string[] $target_ids 削除する target_id の一覧.
	 * @return int 削除した行数の合計.
	 *
	 * @throws RuntimeException `delete_by_target()` が例外を投げた場合、そのまま伝播する.
	 */
	public function delete_excluded_targets( array $target_ids ) {
		$deleted = 0;

		foreach ( array_unique( array_map( 'strval', $target_ids ) ) as $target_id ) {
			$deleted += $this->delete_by_target( $target_id );
		}

		return $deleted;
	}

	/**
	 * 指定した id の行を削除する(v0.5 §Step7. 削除検出で `missing` にした行を消す).
	 *
	 * `delete_stale()` と違い、削除対象を呼び出し元が決める。dispatcher は
	 * このchunkで upsert する前に stale 行を読むため、このchunkで今まさに見つかった
	 * path を除いた id だけを渡す必要がある(upsert 後に `find_stale()` し直すと
	 * トランザクションの外で読んだ内容とずれるため).
	 *
	 * @param int[] $ids 削除する行の id.
	 * @return void
	 *
	 * @throws RuntimeException `$wpdb->delete()` がSQLエラーで `false` を返した場合.
	 */
	public function delete_by_ids( array $ids ) {
		$table = $this->wpdb->base_prefix . 'wpcv_file_states';

		foreach ( $ids as $id ) {
			$deleted = $this->wpdb->delete(
				$table,
				array( 'id' => (int) $id ),
				array( '%d' )
			);

			if ( false === $deleted ) {
				throw new RuntimeException(
					esc_html(
						sprintf(
							'WPCV_File_State_Repository::delete_by_ids() の delete に失敗しました: %s',
							(string) $this->wpdb->last_error
						)
					)
				);
			}
		}
	}

	/**
	 * 指定した target に、`baseline_version` が `$version` と異なる行が1件でも
	 * あるかを判定する(v0.5 §Step7. rev.3 §3.7-a の version 変化によるベースライン破棄).
	 *
	 * Target_run の version だけを比べる方式では、run と run の間にプラグインが
	 * 更新された場合(通常の自動更新)を検知できない。新しい run の target_run は
	 * plan 時点で最初から新しい version を持つためである。そこでベースライン側に
	 * 保存した version と比べる.
	 *
	 * `$version` が空文字(Version ヘッダが無い・mu-plugin)の場合は、保存時に NULL
	 * として書いている(`WPCV_Chunk_Verifier::verify_stat_chunk()`)ので NULL と比べる.
	 *
	 * @param string $target_id 対象の target_id.
	 * @param string $version   本体の現在の version(不明なら空文字).
	 * @return bool
	 */
	public function has_rows_with_other_baseline_version( $target_id, $version ) {
		$table   = $this->wpdb->base_prefix . 'wpcv_file_states';
		$version = (string) $version;

		if ( '' === $version ) {
			$sql  = "SELECT * FROM {$table} WHERE target_id = %s AND baseline_version IS NOT NULL LIMIT 1";
			$args = array( (string) $target_id );
		} else {
			$sql  = "SELECT * FROM {$table} WHERE target_id = %s AND ( baseline_version IS NULL OR baseline_version <> %s ) LIMIT 1";
			$args = array( (string) $target_id, $version );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; values are bound via prepare() here.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		// クラスdocblock参照: テストダブルはWHERE句を解釈しないため PHP 側でも同じ条件で絞る.
		$expected = '' === $version ? null : $version;

		foreach ( $rows as $row ) {
			if ( (string) $row['target_id'] === (string) $target_id && $row['baseline_version'] !== $expected ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 指定した target に、今回の run より前に書かれたベースライン行が1件でも
	 * 存在するかを判定する(rev.3 §3.6「初回実行(ベースライン構築)の扱い」参照).
	 *
	 * 「その target の行数が0か」で判定しない理由はrev.3 §3.6に詳しい
	 * (今回のrunの最初のchunkが書いた行と混同するバグを避けるため、
	 * `last_seen_run_id < 現在のrun_id` の行の有無で判定する).
	 *
	 * @param string $target_id      対象の target_id.
	 * @param int    $current_run_id 今回の run の id.
	 * @return bool
	 */
	public function has_baseline_before_run( $target_id, $current_run_id ) {
		return ! empty( $this->stale_rows( $target_id, $current_run_id, 1 ) );
	}

	/**
	 * 指定した target で `last_seen_run_id < $current_run_id` の行を取得する
	 * (`find_stale()`/`has_baseline_before_run()` の共通ロジック).
	 *
	 * `$limit` は v0.5 §Step7 で追加した。`has_baseline_before_run()` は存在確認だけで
	 * よいのに全件を読んでおり、2回目以降の run では chunk ごとに数千行を読み込んでいた
	 * (test-armfu.local の run #67 で 5,033 行の target を確認)ため、1件で打ち切る.
	 *
	 * @param string $target_id      対象の target_id.
	 * @param int    $current_run_id 比較基準の run id.
	 * @param int    $limit          取得件数の上限(0 なら無制限).
	 * @return array<int, array>
	 */
	private function stale_rows( $target_id, $current_run_id, $limit = 0 ) {
		$table = $this->wpdb->base_prefix . 'wpcv_file_states';
		$sql   = "SELECT * FROM {$table} WHERE target_id = %s AND last_seen_run_id < %d" . ( $limit > 0 ? ' LIMIT ' . (int) $limit : '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; values are bound via prepare() here.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, (string) $target_id, (int) $current_run_id ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		// クラスdocblock参照: テストダブルはWHERE句を解釈しないため、本番・
		// テスト両方で正しく動くようPHP側でも同じ条件を再フィルタする.
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $target_id, $current_run_id ) {
					return (string) $row['target_id'] === (string) $target_id && (int) $row['last_seen_run_id'] < (int) $current_run_id;
				}
			)
		);
	}

	/**
	 * `upsert_many()` の1行分を `(val, val, ...)` のプレースホルダー文字列に
	 * 組み立て、値を `$args` へ積む.
	 *
	 * NULL値の列は `%s`/`%d` プレースホルダーではなくリテラル `NULL` を直接
	 * 埋め込む(`content_hash`/`hash_algorithm`/`baseline_version`は層1では
	 * 常にNULL. rev.3 §3.8参照)。理由: `$wpdb->prepare()`の`%s`にPHPの`null`を
	 * 渡すと空文字列`''`にキャストされてしまい、実DBのNULLとは意味が異なる
	 * (`content_hash IS NULL`のような判定が壊れる)ため.
	 *
	 * @param array            $row  `UPSERT_COLUMNS`のキー(+`updated_at`)を持つ連想配列.
	 * @param array<int,mixed> &$args 呼び出し元が積み上げる、prepare()に渡す値の配列(参照渡しで追記).
	 * @return string
	 */
	private function build_value_tuple( array $row, array &$args ) {
		$placeholders = array();

		$columns = array_merge( self::UPSERT_COLUMNS, array( 'updated_at' => '%s' ) );

		foreach ( $columns as $column => $format ) {
			$value = $row[ $column ];

			if ( null === $value ) {
				$placeholders[] = 'NULL';
				continue;
			}

			$placeholders[] = $format;
			$args[]         = '%d' === $format ? (int) $value : (string) $value;
		}

		return '(' . implode( ', ', $placeholders ) . ')';
	}
}
