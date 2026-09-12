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
	 * 行を読み取る(`WPCV_Repository::sweep_stale_running()` 専用の簡易フェイク).
	 *
	 * 実 `$wpdb` と異なり SQL を解釈しない。クエリ文字列から `FROM {table}` の
	 * テーブル名だけを正規表現で拾い、そのテーブルの全行をそのまま返す
	 * (WHERE 句によるフィルタリングは呼び出し側の PHP コードが行う設計になって
	 * いるため、フェイク側で再現する必要が無い。`WPCV_Repository::sweep_stale_running()`
	 * の docblock 参照).
	 *
	 * @param string $query  SQL文字列(`FROM {table}` を含む前提).
	 * @param string $output 無視する(本プラグインは常に `ARRAY_A` で呼ぶ).
	 * @return array<int, array>
	 */
	public function get_results( $query, $output = 'ARRAY_A' ) {
		unset( $output );

		if ( 1 !== preg_match( '/FROM\s+(\S+)/i', $query, $matches ) ) {
			return array();
		}

		$table = $matches[1];

		return isset( $this->rows[ $table ] ) ? array_values( $this->rows[ $table ] ) : array();
	}

	/**
	 * 単一の値を返す(`WPCV_Repository::reserve_run()` の `GET_LOCK()` 専用の
	 * 簡易フェイク)。実 SQL は実行せず、`$this->get_var_return` をそのまま返す.
	 *
	 * @param string $query クエリ文字列(記録のみ。実行はしない).
	 * @return string|null
	 */
	public function get_var( $query ) {
		$this->get_var_calls[] = $query;

		return $this->get_var_return;
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
	 * @param string $query クエリ文字列(記録のみ).
	 * @return bool `$query_should_fail` が真なら `false`。それ以外は常に `true`.
	 */
	public function query( $query ) {
		$this->query_calls[] = $query;

		if ( $this->query_should_fail ) {
			$this->last_error = 'WPCV_Test_Fake_WPDB: query_should_fail が true のため query() を失敗させました.';

			return false;
		}

		return true;
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
	 * このダブルは実 SQL を実行しないため、エスケープ処理は行わず `%s`/`%d` を
	 * `vsprintf()` で単純に置換するだけで十分(呼び出し引数の確認は
	 * `get_var_calls`/`query_calls` に記録された最終文字列で行う).
	 *
	 * @param string $query    クエリ(`%s`/`%d` プレースホルダーを含む).
	 * @param mixed  ...$args  プレースホルダーに対応する値.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
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
 * @param WPCV_Manifest_Source|null $core_source            省略時は常に成功する空マニフェストのfake.
 * @param WPCV_Manifest_Source|null $plugin_source          省略時は `manifest_not_found` を返すfake.
 * @param callable|null             $continuation_scheduler 省略時はno-op(`WPCV_Chunk_Dispatcher`
 *                                                          のクラス docblock 参照).
 * @return array{
 *     coordinator: WPCV_Run_Coordinator,
 *     dispatcher: WPCV_Chunk_Dispatcher,
 *     run_repository: WPCV_Run_Repository,
 *     target_run_repository: WPCV_Target_Run_Repository,
 *     finding_repository: WPCV_Finding_Repository,
 *     chunk_result_repository: WPCV_Chunk_Result_Repository,
 *     suppression_repository: WPCV_Suppression_Repository,
 *     wpdb: WPCV_Test_Fake_WPDB,
 * }
 */
function wpcv_test_make_fake_environment( $core_source = null, $plugin_source = null, $continuation_scheduler = null ) {
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
	$chunk_result_repository = new WPCV_Chunk_Result_Repository( $wpdb, $target_run_repository, $finding_repository, $suppression_repository );

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
		}
	);

	$coordinator = new WPCV_Run_Coordinator( new WPCV_Run_Planner( $suppression_repository ), $run_repository, $target_run_repository, $dispatcher );

	return array(
		'coordinator'             => $coordinator,
		'dispatcher'              => $dispatcher,
		'run_repository'          => $run_repository,
		'target_run_repository'   => $target_run_repository,
		'finding_repository'      => $finding_repository,
		'chunk_result_repository' => $chunk_result_repository,
		'suppression_repository'  => $suppression_repository,
		'wpdb'                    => $wpdb,
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
 * `WPCV_Plugin::run_repository()->sweep_stale_running()` を呼ぶため、実 `global $wpdb`
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
