<?php
/**
 * WPCV_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `WPCV_Verifier` が返す target_run / finding のデータ構造を DB に永続化する層.
 *
 * 検証ロジック(`WPCV_Verifier`)と DB アクセスを分離する(§4.2: `class-wpcv-verifier.php`
 * と `class-wpcv-repository.php` は別ファイル)。findings がどの target_run に
 * 属するかは `target_id` で相関させる設計(`WPCV_Verifier` の docblock参照)のため、
 * `save_target_runs()` が返す `target_id => target_run_id` の対応表を
 * `save_findings()` にそのまま渡すこと.
 *
 * 既存の `WPCV_API` / `WPCV_Migrator` は `global $wpdb;` を直接参照するが、この
 * クラスは単体テストで実 DB を使わずに検証したいため、コンストラクタで
 * `$wpdb` 相当のオブジェクトを注入できるようにする(既存クラスとの意図的な差異).
 */
class WPCV_Repository {

	/**
	 * 「キュー投入済み・実行待ち」を表す run 行の状態(v0.3.1 §Step1: `queued`/`running` を
	 * 「実行権(active lease)」として扱う。`reserve_run()` の docblock 参照).
	 *
	 * @var string
	 */
	const STATUS_QUEUED = 'queued';

	/**
	 * 「検証処理中」を表す run 行の状態.
	 *
	 * @var string
	 */
	const STATUS_RUNNING = 'running';

	/**
	 * 実行権(active lease)を保持しているとみなす状態の一覧.
	 *
	 * @var string[]
	 */
	const ACTIVE_STATUSES = array( self::STATUS_QUEUED, self::STATUS_RUNNING );

	/**
	 * `reserve_run()` が `GET_LOCK()` に渡すタイムアウト秒数.
	 *
	 * この秒数は受付処理(active run の検索 + 行の insert)だけをブロックする
	 * ものであり、検証処理そのものの待ち時間ではない(§設計判断「advisory lock は
	 * 受付処理だけを保護し、長い検証中は run 行が排他状態を表す」参照)。
	 * 5秒は未実測の初期値。実測して問題があれば調整すること.
	 *
	 * @var int
	 */
	const LOCK_TIMEOUT_SECONDS = 5;

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `update()` / `get_results()` / `get_var()` /
	 * `query()` / `prepare()` / `base_prefix` / `insert_id` を持つもの).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * 現在時刻(UTC の MySQL DATETIME 文字列)を返す callable.
	 *
	 * テストで固定時刻を注入できるようにするため引数で差し替え可能にする.
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
	 * 実行権(run 行)を排他的に予約する(v0.3.1 §Step1).
	 *
	 * 「1 action = 1 run」の一括実行モデル(v0.3.1では新しい lock テーブルや
	 * 分割 work item を持たない。プラン§設計判断参照)のもとで、`queued`/`running`
	 * の run 行そのものを実行権(active lease)として使う。MySQL の名前付き
	 * advisory lock(`GET_LOCK()`/`RELEASE_LOCK()`)で「active run の検索」と
	 * 「行の insert」を1つの直列区間にまとめ、同時受付(REST連打・cron と手動実行の
	 * 競合など)で2件以上の run が同時に作られることを防ぐ。lock はこの受付処理
	 * だけを保護し、取得後の長い検証処理そのものは保護しない(その間は作成した
	 * run 行の `queued`/`running` 状態自体が排他状態を表す).
	 *
	 * @param array $args {
	 *     省略可能なオプション.
	 *
	 *     @type string $run_trigger    cron|manual|cli|rest. 既定 'manual'.
	 *     @type string $runner         sync|async(記録用のメタデータ。「同期実行
	 *                                  したか、Action Scheduler ワーカー経由で
	 *                                  実行したか」を表すだけで、下記
	 *                                  `initial_status` の決定には使わない). 既定 'sync'.
	 *     @type string $initial_status active run が無い場合に新規作成する run の
	 *                                  初期状態. `self::STATUS_RUNNING`(既定。
	 *                                  即座に検証を始める同期系の呼び出し元向け)
	 *                                  または `self::STATUS_QUEUED`(Action Scheduler
	 *                                  へ enqueue してから実際の実行までに間が
	 *                                  空く呼び出し元向け。v0.3.1 Step2 で
	 *                                  enqueue 側から使う想定).
	 * }
	 * @return array{
	 *     run_id: int|null,
	 *     status: string|null,
	 *     active: bool,
	 *     lock_failed: bool,
	 * } `lock_failed` が真の場合は lock 取得に失敗しており run は作成していない
	 *   (`run_id`/`status` は null)。`active` が真の場合は既存の `queued`/`running`
	 *   run が見つかったため新規作成しておらず、`run_id`/`status` はその既存 run を
	 *   指す(v0.3.1 §Step4: RESTの応答に実際の状態を含めるため、active 時も
	 *   実際の `status` を返すようにした)。両方偽の場合のみ、新規に run を
	 *   作成しており `run_id`/`status` が新規行を指す.
	 */
	public function reserve_run( array $args = array() ) {
		$run_trigger    = isset( $args['run_trigger'] ) ? (string) $args['run_trigger'] : 'manual';
		$runner         = isset( $args['runner'] ) ? (string) $args['runner'] : 'sync';
		$initial_status = isset( $args['initial_status'] ) ? (string) $args['initial_status'] : self::STATUS_RUNNING;

		// ローカル変数名を `$wpdb` にするのは WPCS の PreparedSQL sniff 対策
		// (`$this->wpdb->prepare()` のように `$wpdb` 直書きでない形だと
		// `prepare()` 呼び出しとして認識されず誤検知するため).
		$wpdb      = $this->wpdb;
		$lock_name = $this->lock_name();

		// advisory lock はキャッシュ不可能な性質の呼び出しであるため直接クエリで問題ない
		// (`sweep_stale_running()` / `find_active_run_id()` と同じ理由での ignore).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK() はキャッシュ不可.
		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, self::LOCK_TIMEOUT_SECONDS )
		);

		if ( '1' !== (string) $lock_acquired ) {
			return array(
				'run_id'      => null,
				'status'      => null,
				'active'      => false,
				'lock_failed' => true,
			);
		}

		try {
			$active_run = $this->find_active_run();

			if ( null !== $active_run ) {
				return array(
					'run_id'      => $active_run['id'],
					'status'      => $active_run['status'],
					'active'      => true,
					'lock_failed' => false,
				);
			}

			$run_id = $this->insert_run_row( $initial_status, $run_trigger, $runner );

			return array(
				'run_id'      => $run_id,
				'status'      => $initial_status,
				'active'      => false,
				'lock_failed' => false,
			);
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- RELEASE_LOCK() はキャッシュ不可.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * `queued` の run 行を `running` へ条件付きで更新する(v0.3.1 §Step1).
	 *
	 * Action Scheduler ワーカーが、enqueue 時に予約しておいた `queued` run を
	 * 実際の検証開始時点で引き継ぐために使う(v0.3.1 Step2 で配線する想定)。
	 * `status = 'queued'` を WHERE に含めるため、stale sweep に先を越されて
	 * 既に `failed` にされていた場合は更新されず、戻り値で呼び出し元に伝わる
	 * (プラン§P1「stale化後に旧ワーカーが成功で上書きできる」への対策).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return bool 更新できたら true(呼び出し元は検証を続行してよい)。false は
	 *              対象行が既に `queued` ではない(stale 化・多重発火等)ことを
	 *              意味し、呼び出し元は検証を開始せず no-op とすること.
	 */
	public function mark_queued_running( $run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$updated = $this->wpdb->update(
			$table,
			array( 'status' => self::STATUS_RUNNING ),
			array(
				'id'     => (int) $run_id,
				'status' => self::STATUS_QUEUED,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		return $updated > 0;
	}

	/**
	 * 実行(run)行を失敗状態にする(v0.3.1 §Step1、§Step2で `queued` にも対応).
	 *
	 * 2つの呼び出し状況を想定する:
	 *
	 * 1. `WPCV_Run_Coordinator::run()` が検証中に例外を投げた場合(この時点で
	 *    run は必ず `running`。`reserve_run()` が直接 `running` で作った行、または
	 *    `mark_queued_running()` で `running` へ遷移させた行のいずれか).
	 * 2. `queued` で予約した直後に Action Scheduler への enqueue 自体が失敗した
	 *    場合(v0.3.1 §Step2。この時点で run はまだ `queued` のまま。ワーカーは
	 *    一度も起動していないため、他プロセスとの競合は起こらない).
	 *
	 * `status = 'running'` を条件に更新を試み、対象行が見つからなければ
	 * `status = 'queued'` を条件に再試行する(2段階。実 `$wpdb` の `update()` は
	 * WHERE 句に IN() を組み立てられないため。`finish_run()` と異なり `queued` も
	 * 受け付ける必要があるのはこのメソッドだけ. 状況1で更新できた場合は状況2の
	 * 再試行は対象0件で no-op になるだけで安全).
	 *
	 * @param int    $run_id 対象の run の id.
	 * @param string $notes  失敗理由(例外クラス名・メッセージ等). 省略可.
	 * @return bool 更新できたら true。false は対象行が `running`/`queued` の
	 *              いずれでもない(stale sweep に先を越された等)ことを意味する.
	 */
	public function mark_run_failed( $run_id, $notes = '' ) {
		$table  = $this->wpdb->base_prefix . 'wpcv_runs';
		$data   = array(
			'finished_at' => call_user_func( $this->now ),
			'status'      => 'failed',
			'notes'       => (string) $notes,
		);
		$format = array( '%s', '%s', '%s' );

		$updated = $this->wpdb->update(
			$table,
			$data,
			array(
				'id'     => (int) $run_id,
				'status' => self::STATUS_RUNNING,
			),
			$format,
			array( '%d', '%s' )
		);

		if ( $updated > 0 ) {
			return true;
		}

		$updated = $this->wpdb->update(
			$table,
			$data,
			array(
				'id'     => (int) $run_id,
				'status' => self::STATUS_QUEUED,
			),
			$format,
			array( '%d', '%s' )
		);

		return $updated > 0;
	}

	/**
	 * 実行(run)行を新規 insert する(`reserve_run()` からのみ呼ぶ内部ヘルパー).
	 *
	 * @param string $status      insert する `status`(`self::STATUS_RUNNING` または
	 *                            `self::STATUS_QUEUED`).
	 * @param string $run_trigger cron|manual|cli|rest.
	 * @param string $runner      sync|async.
	 * @return int 作成した run の id.
	 */
	private function insert_run_row( $status, $run_trigger, $runner ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$this->wpdb->insert(
			$table,
			array(
				'started_at'  => call_user_func( $this->now ),
				'status'      => $status,
				'run_trigger' => $run_trigger,
				'runner'      => $runner,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * `reserve_run()` が使う advisory lock の名前を求める.
	 *
	 * `GET_LOCK()` の名前空間は MySQL サーバー単位(データベース単位ではない)
	 * のため、同一サーバーに同居する他のインストールと衝突しないよう
	 * `base_prefix` を含める(`class-wpcv-migrator.php` がテーブル名に
	 * `base_prefix` を使う理由と同じ、マルチサイトの installation-level の考え方).
	 *
	 * @return string
	 */
	private function lock_name() {
		return 'wpcv_reserve_run_' . $this->wpdb->base_prefix;
	}

	/**
	 * 検証対象(target_run)群を保存する.
	 *
	 * @param int   $run_id      `reserve_run()` が返した run の id.
	 * @param array $target_runs `WPCV_Verifier` の各 `verify_*()` が返す target_run の配列
	 *                           (id/run_id 無し。§5.3 のスキーマに準拠).
	 * @return array `target_id => target_run_id` の対応表(`save_findings()` に渡す).
	 */
	public function save_target_runs( $run_id, array $target_runs ) {
		$table          = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$target_run_ids = array();

		foreach ( $target_runs as $target_run ) {
			$this->wpdb->insert(
				$table,
				array(
					'run_id'          => $run_id,
					'target_id'       => $target_run['target_id'],
					'dimension'       => $target_run['dimension'],
					'slug'            => $target_run['slug'],
					'version'         => $target_run['version'],
					'source'          => $target_run['source'],
					'source_ref'      => $target_run['source_ref'],
					'manifest_status' => $target_run['manifest_status'],
					'status'          => $target_run['status'],
					'error_code'      => $target_run['error_code'],
					'error_message'   => $target_run['error_message'],
					'files_total'     => $target_run['files_total'],
					'files_verified'  => $target_run['files_verified'],
					'findings_total'  => $target_run['findings_total'],
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
			);

			$target_run_ids[ $target_run['target_id'] ] = (int) $this->wpdb->insert_id;
		}

		return $target_run_ids;
	}

	/**
	 * 検出結果(finding)群を保存する.
	 *
	 * @param int   $run_id         `reserve_run()` が返した run の id.
	 * @param array $target_run_ids `save_target_runs()` が返した `target_id => target_run_id` の対応表.
	 * @param array $findings       `WPCV_Verifier` の各 `verify_*()` が返す findings の配列
	 *                              (id/run_id/target_run_id 無し。§5.5 のスキーマに準拠).
	 * @return void
	 *
	 * @throws InvalidArgumentException 対応する target_run_id が `$target_run_ids` に無い場合(同一バッチの
	 *                                   target_runs と findings の target_id は必ず
	 *                                   一致している前提が崩れている、呼び出し側の実装ミス).
	 */
	public function save_findings( $run_id, array $target_run_ids, array $findings ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';

		foreach ( $findings as $finding ) {
			if ( ! isset( $target_run_ids[ $finding['target_id'] ] ) ) {
				throw new InvalidArgumentException(
					esc_html(
						sprintf(
							'WPCV_Repository::save_findings() has no matching target_run_id for target_id: %s',
							$finding['target_id']
						)
					)
				);
			}

			$this->wpdb->insert(
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
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	/**
	 * 実行(run)行を完了状態にする(status・finished_at・集計値を更新する).
	 *
	 * `status = 'running'` を WHERE に含める(v0.3.1 §Step1: `mark_run_failed()` と
	 * 同じ理由。stale sweep に先を越されて既に `failed` にされていた run へ、
	 * 後から戻ってきた旧ワーカーが success/partial を書き戻せてしまう事故
	 * 〔プラン§P1「stale化後に旧ワーカーが成功で上書きできる」〕を防ぐ).
	 *
	 * @param int   $run_id  `reserve_run()` が返した run の id.
	 * @param array $summary `WPCV_Verifier::summarize()` の戻り値.
	 * @return bool 更新できたら true。false は対象行が既に `running` ではない
	 *              (stale sweep に先を越された等)ことを意味する.
	 */
	public function finish_run( $run_id, array $summary ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$updated = $this->wpdb->update(
			$table,
			array(
				'finished_at'          => call_user_func( $this->now ),
				'status'               => $summary['status'],
				'targets_total'        => $summary['targets_total'],
				'targets_verified'     => $summary['targets_verified'],
				'targets_unverifiable' => $summary['targets_unverifiable'],
				'targets_failed'       => $summary['targets_failed'],
				'findings_total'       => $summary['findings_total'],
			),
			array(
				'id'     => (int) $run_id,
				'status' => self::STATUS_RUNNING,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%d' ),
			array( '%d', '%s' )
		);

		return $updated > 0;
	}

	/**
	 * 一定時間より古い `queued`/`running` の run を `failed` に更新する
	 * (v0.3 §Step5、v0.3.1 §Step1で `queued` も対象に拡張).
	 *
	 * 「1アクション=1run全体」の簡略化(v0.3のスコープ縮小。実装セッションへの
	 * 申し送り参照)のトレードオフとして、途中で強制終了し `queued`/`running` の
	 * まま残留した run が次の run を永久にブロックし続ける事態を避けるための、
	 * WPMAR流の軽量なハートビート途絶検知(WPMARの `sweep_stale_running()` と
	 * 同じ「アクセスのたびに掃除する」方式。専用の Cron は立てない)。`queued` も
	 * 対象にするのは、enqueue はできたが Action Scheduler ワーカーが何らかの理由で
	 * 拾わなかった run も同様に永久ブロック要因になり得るため.
	 *
	 * 判定基準は `started_at` のみを使う(`updated_at` 相当の列は追加しない):
	 * このプラグインの run は実行途中で行を更新しない(target_runs・findings は
	 * すべて `finish_run()` の直前にまとめて保存する設計。`WPCV_Run_Coordinator`
	 * の docblock 参照)ため、`started_at` より新しい「途中経過」の時刻は
	 * そもそも存在しない。WPMAR の `updated_at`(セグメント単位で進捗を刻む
	 * 設計だからこそ意味を持つハートビート)とは前提が異なる.
	 *
	 * v0.3 では専用の `aborted` 状態は導入せず、既存の `failed` を流用する
	 * (§14 のv0.4以降のスコープとした本格的なタイムアウト状態機械とは区別する).
	 *
	 * 更新時も `status = $row['status']`(SELECT 時点で読んだ状態そのもの)を
	 * WHERE に含める(v0.3.1 §Step1: SELECT と UPDATE の間に別プロセスが
	 * 状態を進めていた場合〔run がちょうど完了した等〕に、その更新を
	 * 巻き戻して `failed` で上書きしてしまう事故を防ぐ).
	 *
	 * @param int $minutes この分数より古い `started_at` を stale とみなす.
	 * @return int stale と判定し `failed` に更新した run の件数.
	 */
	public function sweep_stale_running( $minutes ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		// 動的な値を含まない固定リテラルのみのクエリ(status/日時の絞り込みは
		// 下の PHP 側で行う).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$active_rows = $this->wpdb->get_results( "SELECT id, started_at, status FROM {$table} WHERE status IN ( 'queued', 'running' )", ARRAY_A );
		$active_rows = is_array( $active_rows ) ? $active_rows : array();

		$threshold = $this->stale_threshold( (int) $minutes );
		$swept     = 0;

		foreach ( $active_rows as $row ) {
			// status も改めて確認する(SQL の WHERE 句と重複するが、テストダブル
			// (`WPCV_Test_Fake_WPDB::get_results()`)が WHERE 句を解釈せず
			// テーブルの全行を返す簡易実装のための保険でもある).
			if ( ! in_array( $row['status'], self::ACTIVE_STATUSES, true ) ) {
				continue;
			}

			if ( empty( $row['started_at'] ) || (string) $row['started_at'] >= $threshold ) {
				continue;
			}

			$updated = $this->wpdb->update(
				$table,
				array(
					'status'      => 'failed',
					'finished_at' => call_user_func( $this->now ),
					'notes'       => 'sweep_stale_running() により stale な run として検知し failed 化しました.',
				),
				array(
					'id'     => (int) $row['id'],
					'status' => $row['status'],
				),
				array( '%s', '%s', '%s' ),
				array( '%d', '%s' )
			);

			if ( $updated > 0 ) {
				++$swept;
			}
		}

		return $swept;
	}

	/**
	 * 現在 `queued`/`running`(実行権を保持している)の run が無いかを調べる
	 * (v0.3 §Step8: RESTハンドラの冪等性判定に使う。v0.3.1 §Step1で `queued` も対象に拡張).
	 *
	 * 呼び出し側は先に `sweep_stale_running()` を呼んでおくこと(このメソッドは
	 * stale 判定を行わない。ここで見つかる行は「stale ではない = 現在進行中と
	 * みなせる」run である前提を呼び出し元が保証する設計)。`reserve_run()` は
	 * このメソッドを advisory lock 内から呼ぶことで、同時受付でも高々1件しか
	 * active run が存在しないことを保証する.
	 *
	 * 複数件見つかった場合(`reserve_run()` の advisory lock を経由しない
	 * 直接呼び出しでの競合等、通常は起こらない)は最初に見つかった1件の id を
	 * 返す(呼び出し元は「active run があるかどうか」だけを見れば十分な設計のため).
	 *
	 * @return int|null active な run が無ければ `null`.
	 */
	public function find_active_run_id() {
		$active = $this->find_active_run();

		return null === $active ? null : $active['id'];
	}

	/**
	 * `find_active_run_id()` の id・status 両方を返す版(v0.3.1 §Step4)。
	 *
	 * `reserve_run()` の active 分岐(`WPCV_Rest_Run_Controller::handle_run()` が
	 * 「既に進行中の run」レスポンスに実際の状態〔`queued`/`running`〕を含める
	 * ために使う)向けに、`find_active_run_id()` の docblock の前提・戻り値の
	 * 意味はすべてそのまま引き継ぐ.
	 *
	 * @return array{id: int, status: string}|null active な run が無ければ `null`.
	 */
	private function find_active_run() {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		// sweep_stale_running() と同じ方針で、動的な値を含まない固定リテラルのみのクエリ.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$active_rows = $this->wpdb->get_results( "SELECT id, status FROM {$table} WHERE status IN ( 'queued', 'running' )", ARRAY_A );
		$active_rows = is_array( $active_rows ) ? $active_rows : array();

		foreach ( $active_rows as $row ) {
			// `sweep_stale_running()` と同じ理由(テストダブルの WHERE 句非対応)で
			// status を改めて確認する.
			if ( in_array( $row['status'], self::ACTIVE_STATUSES, true ) ) {
				return array(
					'id'     => (int) $row['id'],
					'status' => (string) $row['status'],
				);
			}
		}

		return null;
	}

	/**
	 * 現在時刻(`$this->now`)から `$minutes` 分前の MySQL DATETIME 文字列を求める.
	 *
	 * @param int $minutes 分数.
	 * @return string
	 */
	private function stale_threshold( $minutes ) {
		$now_timestamp = strtotime( call_user_func( $this->now ) );

		return gmdate( 'Y-m-d H:i:s', $now_timestamp - ( $minutes * 60 ) );
	}
}
