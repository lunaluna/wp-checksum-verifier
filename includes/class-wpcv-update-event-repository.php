<?php
/**
 * WPCV_Update_Event_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_update_events` テーブルの永続化を担当する(v0.6プラン §2.1・D1参照).
 *
 * WordPress の更新機構(手動更新・自動更新・WP-CLI)を通って target の version が
 * 変わったことを、追記のみで記録する. 記録先を option ではなくテーブルにしたのは、
 * 同時に2つの更新が走ったとき(管理画面の手動更新と自動更新の cron が重なる等)に
 * option の read-modify-write では片方の記録が失われるため(D1参照. `wpcv_alert_streaks`
 * option を v0.5 Step15で捨てたのと同じ理由).
 *
 * 記録(`insert()`)はフック(`upgrader_process_complete`・`_core_updated_successfully`.
 * v0.6 Step2)からのみ呼ばれる想定。突き合わせ(`find_matching()`)は差分処理
 * (v0.6 Step3)が「その target の version が今回と一致し、基準target_runのrun開始
 * より後に記録された更新イベントがあるか」を判定するために使う(D5参照).
 */
class WPCV_Update_Event_Repository {

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `get_results()` / `query()` /
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
	 * 更新イベントを1件記録する(v0.6 Step2から呼ばれる想定. D2〜D4参照).
	 *
	 * `event_at` は呼び出し時点(コンストラクタの `$now`)を使う ―― フック発火時点で
	 * ディスクから読み直した `$version` と対にして記録することで、「更新処理の
	 * あと、ディスク上の version はこれだった」という事実だけを残す(D3参照。
	 * 更新が成功したかどうかは判定しない).
	 *
	 * @param string      $target_id  `plugin:{slug}` / `core`(WPCV_Target_Resolver の形式).
	 * @param string|null $version    フックの時点でディスクから読んだ version. 読めなければ null.
	 * @param string      $source     `plugin_update` / `plugin_bulk_update` / `plugin_install` / `core_update`.
	 * @param int         $created_by `get_current_user_id()`. cron・CLI では 0.
	 * @return int 作成した行の id.
	 *
	 * @throws RuntimeException `$wpdb->insert()` が失敗した場合(他のRepositoryと
	 *                          同じくSQLエラーを見逃さないため. v0.4.0コードレビューCR-03と同じ理由).
	 */
	public function insert( $target_id, $version, $source, $created_by = 0 ) {
		$table = $this->wpdb->base_prefix . 'wpcv_update_events';

		$data = array(
			'target_id'  => (string) $target_id,
			'version'    => null === $version ? null : (string) $version,
			'event_at'   => call_user_func( $this->now ),
			'source'     => (string) $source,
			'created_by' => (int) $created_by,
		);

		$format = array( '%s', '%s', '%s', '%s', '%d' );

		if ( false === $this->wpdb->insert( $table, $data, $format ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Update_Event_Repository::insert() の insert に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * 指定した target・version に一致し、`$after` より後に記録された更新イベントが
	 * あるかを調べる(D5の突き合わせ条件そのもの. v0.6 Step3から呼ばれる想定).
	 *
	 * `event_at` は `$after` と同時刻の行を一致とはみなさない(厳密に後のみ. `$after`
	 * には基準target_runの run 開始時刻を渡す想定で、「基準run開始の時点で既に
	 * 記録されていた更新」は今回のversion変化の理由にならないため).
	 *
	 * @param string      $target_id 対象の target_id.
	 * @param string|null $version   今回の run で読み取った version(不明なら null).
	 * @param string      $after     この時刻より後の `event_at` を持つ行だけを対象にする(UTC DATETIME文字列).
	 * @return array<int, array> 一致する行(最大1件. 存在確認のみで内容は使わない想定だが、
	 *                            呼び出し側が必要なら参照できるようそのまま返す).
	 */
	public function find_matching( $target_id, $version, $after ) {
		$table       = $this->wpdb->base_prefix . 'wpcv_update_events';
		$version_val = null === $version ? null : (string) $version;

		if ( null === $version_val ) {
			$sql  = "SELECT * FROM {$table} WHERE target_id = %s AND version IS NULL AND event_at > %s LIMIT 1";
			$args = array( (string) $target_id, (string) $after );
		} else {
			$sql  = "SELECT * FROM {$table} WHERE target_id = %s AND version = %s AND event_at > %s LIMIT 1";
			$args = array( (string) $target_id, $version_val, (string) $after );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; values are bound via prepare() here.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * `event_at` が `$days` 日より古い行を削除する(v0.6プラン §2.1「掃除」参照.
	 * 呼び出し側〔run終端 `wpcv_run_terminated` での接続. 保持日数の定数〕は
	 * v0.6 Step2以降で実装する. このメソッド自体は日数を引数に取るだけの
	 * 汎用的な削除処理として用意する).
	 *
	 * `WPCV_File_State_Repository::delete_stale()` と同じく、1クエリのDELETEに
	 * まとめず「対象行をSELECTし、id単位で `$wpdb->delete()` を呼ぶ」設計にした
	 * (対象は「保持期間を超えた更新イベント」のみで、プラン§2.1の見積もりでは
	 * 100プラグイン・毎週更新でも90日で約1,300行に留まるため、1クエリへの
	 * 最適化より既存Repositoryとの一貫性を優先した).
	 *
	 * @param int $days この日数より古い `event_at` を持つ行を削除する.
	 * @return int 削除した行数.
	 *
	 * @throws RuntimeException `$wpdb->delete()` がSQLエラーで `false` を返した場合.
	 */
	public function delete_older_than( $days ) {
		$table     = $this->wpdb->base_prefix . 'wpcv_update_events';
		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( call_user_func( $this->now ) ) - (int) $days * DAY_IN_SECONDS );

		$sql = "SELECT * FROM {$table} WHERE event_at < %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; the value is bound via prepare() here.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $threshold ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as $row ) {
			$deleted = $this->wpdb->delete(
				$table,
				array( 'id' => (int) $row['id'] ),
				array( '%d' )
			);

			if ( false === $deleted ) {
				throw new RuntimeException(
					esc_html(
						sprintf(
							'WPCV_Update_Event_Repository::delete_older_than() の delete に失敗しました: %s',
							(string) $this->wpdb->last_error
						)
					)
				);
			}
		}

		return count( $rows );
	}
}
