<?php
/**
 * WPCV_Run_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_runs` テーブルの永続化を担当する(v0.4.0 §Step1でWPCV_Repositoryから分割).
 *
 * これまでは`WPCV_Repository`がrun/target_run/findingの3責務をまとめて
 * 持っていたが、v0.4.0のchunk分割実行(dispatcher・lease・retry)でrun単位の
 * 操作がさらに増えることを見越し、責務ごとに`WPCV_Run_Repository`/
 * `WPCV_Target_Run_Repository`/`WPCV_Finding_Repository`へ分割した(プラン
 * §v0.4.0確定スコープ「Repositoryをrun、target、findingの責務に分割する」)。
 * `WPCV_Run_Coordinator`は3つすべてを注入で受け取る.
 *
 * 状態文字列は`WPCV_Run_Status`に集約する(このクラス自身は定数を持たない).
 *
 * 既存の`WPCV_API`/`WPCV_Migrator`は`global $wpdb;`を直接参照するが、この
 * クラスは単体テストで実DBを使わずに検証したいため、コンストラクタで
 * `$wpdb`相当のオブジェクトを注入できるようにする(既存クラスとの意図的な差異).
 *
 * v0.4.0 §Step6で `reserve_due_run()`(外部HTTPモードの日次due判定込み予約)・
 * `find_most_recent_run()` を追加した。`reserve_run()` は「呼ばれた時点で
 * 常に即座に予約する」同期系エントリポイント(CLI・手動・cron)向けのままとし、
 * 外部HTTP専用の日次due判定ロジックを混在させないよう別メソッドとして分離した.
 *
 * v0.4.0 §Step9で `find_all()`(実行履歴一覧画面向けのpagination付き全件取得)を
 * 追加した。既存の `find_most_recent_run()`/`find_most_recent_terminal_run()` は
 * 「1件だけ」を返す前提のため、一覧表示には使えない.
 */
class WPCV_Run_Repository {

	/**
	 * `find_all()` の `per_page` 既定値(`WPCV_Finding_Repository::DEFAULT_PER_PAGE` と
	 * 同じ理由・同じ値. クラス docblock参照).
	 *
	 * @var int
	 */
	const DEFAULT_PER_PAGE = 20;

	/**
	 * `find_all()` の `per_page` 上限値.
	 *
	 * @var int
	 */
	const MAX_PER_PAGE = 100;

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
	 * `reserve_run()` が新規作成時に設定する `deadline_at`(§6.3「run のタイムアウト」)
	 * までの時間(時間単位)。プラン本体§6.3で既定6時間と明記済みの値であり、
	 * 他の定数(`LOCK_TIMEOUT_SECONDS`等)と異なり「未実測の仮値」ではない
	 * (v0.4.0 §Step4で実装。Step1〜3時点では列を用意するのみだった).
	 *
	 * @var int
	 */
	const DEFAULT_DEADLINE_HOURS = 6;

	/**
	 * 差分処理(v0.5後半 §Step12)の claim が設定する lease 有効期間の既定値(秒).
	 *
	 * 未実測: 暫定値.`WPCV_Target_Run_Repository::DEFAULT_LEASE_SECONDS`(検証本体の
	 * chunk leaseと同じ120秒)をそのまま流用する(プラン§4.5「差分処理の試行上限・
	 * lease の長さは target_run と同じ定数を流用」).
	 *
	 * @var int
	 */
	const DIFF_LEASE_SECONDS = 120;

	/**
	 * 差分処理がlease切れとみなして再試行させる最大回数の既定値.
	 *
	 * `WPCV_Target_Run_Repository::DEFAULT_MAX_ATTEMPTS` と同じ値を流用する
	 * (プラン§4.5. 上記 `DIFF_LEASE_SECONDS` と同じ理由).
	 *
	 * @var int
	 */
	const DIFF_MAX_ATTEMPTS = 5;

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `update()` / `get_results()` / `get_var()` /
	 * `query()` / `prepare()` / `base_prefix` / `insert_id` / `last_error` を持つもの).
	 *
	 * `last_error` は v0.4.0コードレビューCR-03是正で追加した要件(`insert_run_row()`
	 * が `insert()` 失敗時の例外メッセージに使う).
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
	 * 分割 work item を持たない。プラン§設計判断参照)のもとで、
	 * `WPCV_Run_Status::ACTIVE`(`queued`/`planning`/`running`)の run 行そのものを
	 * 実行権(active lease)として使う。MySQL の名前付き advisory lock
	 * (`GET_LOCK()`/`RELEASE_LOCK()`)で「active run の検索」と「行の insert」を
	 * 1つの直列区間にまとめ、同時受付(REST連打・cron と手動実行の競合など)で
	 * 2件以上の run が同時に作られることを防ぐ。lock はこの受付処理だけを保護し、
	 * 取得後の長い検証処理そのものは保護しない(その間は作成した run 行の
	 * active な状態自体が排他状態を表す。`planning`状態自体の意味は
	 * `WPCV_Run_Status`のクラスdocblock参照).
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
	 *                                  初期状態. `WPCV_Run_Status::PLANNING`
	 *                                  (既定。即座にtargetの列挙・保存を始める
	 *                                  同期系の呼び出し元向け。v0.4.0コード
	 *                                  レビューCR-01是正で`RUNNING`から変更 ――
	 *                                  target_runsの保存が終わるまで他プロセスに
	 *                                  「target 0件で完了」と誤認させないため、
	 *                                  呼び出し元は`WPCV_Run_Starter::plan_and_save()`
	 *                                  経由で保存完了後に`running`へ遷移させる
	 *                                  こと)または `WPCV_Run_Status::QUEUED`
	 *                                  (Action Scheduler へ enqueue してから実際の
	 *                                  実行までに間が空く呼び出し元向け).
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
	 *
	 * @throws RuntimeException `insert_run_row()` の insert が失敗した場合
	 *                          (v0.4.0コードレビューCR-03是正。advisory lock は
	 *                          `finally` で確実に解放してから再送出する).
	 */
	public function reserve_run( array $args = array() ) {
		$run_trigger    = isset( $args['run_trigger'] ) ? (string) $args['run_trigger'] : 'manual';
		$runner         = isset( $args['runner'] ) ? (string) $args['runner'] : 'sync';
		$initial_status = isset( $args['initial_status'] ) ? (string) $args['initial_status'] : WPCV_Run_Status::PLANNING;

		// ローカル変数名を `$wpdb` にするのは WPCS の PreparedSQL sniff 対策
		// (`$this->wpdb->prepare()` のように `$wpdb` 直書きでない形だと
		// `prepare()` 呼び出しとして認識されず誤検知するため).
		$wpdb      = $this->wpdb;
		$lock_name = $this->lock_name();

		// advisory lock はキャッシュ不可能な性質の呼び出しであるため直接クエリで問題ない
		// (`find_active_run_id()` と同じ理由での ignore).
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
	 * `queued` の run 行を `planning` へ条件付きで更新する(v0.3.1 §Step1で新設、
	 * v0.4.0コードレビューCR-01是正で遷移先を`running`から`planning`へ変更し
	 * 改名した。旧名`mark_queued_running()`).
	 *
	 * Action Scheduler ワーカーが、enqueue 時に予約しておいた `queued` run を、
	 * target を列挙・保存し始める時点で引き継ぐために使う。`status = 'queued'`
	 * を WHERE に含めるため、stale sweep に先を越されて既に `failed` にされて
	 * いた場合は更新されず、戻り値で呼び出し元に伝わる(プラン§P1「stale化後に
	 * 旧ワーカーが成功で上書きできる」への対策)。ここでは`running`ではなく
	 * `planning`にするのは、target_runsの保存が完了するまで他プロセスに
	 * 「進行中」と誤認させないため(`WPCV_Run_Starter::plan_and_save()`の
	 * クラス docblock 参照).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return bool 更新できたら true(呼び出し元は列挙・保存を続行してよい)。
	 *              false は対象行が既に `queued` ではない(stale 化・多重発火等)
	 *              ことを意味し、呼び出し元は検証を開始せず no-op とすること.
	 */
	public function mark_queued_planning( $run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$updated = $this->wpdb->update(
			$table,
			array( 'status' => WPCV_Run_Status::PLANNING ),
			array(
				'id'     => (int) $run_id,
				'status' => WPCV_Run_Status::QUEUED,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		return $updated > 0;
	}

	/**
	 * `planning` の run 行を `running` へ条件付きで更新する(v0.4.0コード
	 * レビューCR-01是正で新設).
	 *
	 * `WPCV_Run_Starter::plan_and_save()` が `wpcv_target_runs` への保存を
	 * 完了した直後に呼ぶ。この遷移が成功して初めて、他プロセス(REST の
	 * ポーリング等)がこの run を「target が確定済みの進行中run」として
	 * 安全に `WPCV_Chunk_Dispatcher::dispatch()` へ渡せるようになる
	 * (target_runsが1件も無いままclaim対象0件→即completeと誤認する競合
	 * 〔v0.4.0コードレビューCR-01〕への対策)。
	 *
	 * @param int $run_id 対象の run の id.
	 * @return bool 更新できたら true。false は対象行が既に `planning` では
	 *              ない(stale sweep・deadline超過に先を越された等)ことを
	 *              意味し、呼び出し元は検証を継続すべきでない.
	 */
	public function mark_planning_running( $run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$updated = $this->wpdb->update(
			$table,
			array( 'status' => WPCV_Run_Status::RUNNING ),
			array(
				'id'     => (int) $run_id,
				'status' => WPCV_Run_Status::PLANNING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		return $updated > 0;
	}

	/**
	 * 実行(run)行を失敗状態にする(v0.3.1 §Step1、§Step2で `queued` にも対応、
	 * v0.4.0コードレビューCR-01是正で `planning` にも対応).
	 *
	 * 呼び出し時点で run が取り得る状態:
	 *
	 * 1. `running`(`WPCV_Chunk_Dispatcher::dispatch()` 呼び出し中の例外等).
	 * 2. `queued`(`queued` で予約した直後に Action Scheduler への enqueue
	 *    自体が失敗した場合。ワーカーは一度も起動していないため他プロセスとの
	 *    競合は起こらない).
	 * 3. `planning`(`WPCV_Run_Starter::plan_and_save()` が列挙・保存・
	 *    `planning→running`遷移のいずれかで例外を投げた場合).
	 *
	 * 3状態を順に試す(`update_active_run()` 参照。実 `$wpdb` の `update()` は
	 * WHERE 句に IN() を組み立てられないため)。`finish_run()` と異なり
	 * active な状態すべてを受け付ける必要があるのはこのメソッドと
	 * `mark_run_aborted()` だけ.
	 *
	 * @param int    $run_id 対象の run の id.
	 * @param string $notes  失敗理由(例外クラス名・メッセージ等). 省略可.
	 * @return bool 更新できたら true。false は対象行が active な状態
	 *              (`WPCV_Run_Status::ACTIVE`)のいずれでもない(stale sweep に
	 *              先を越された等)ことを意味する.
	 */
	public function mark_run_failed( $run_id, $notes = '' ) {
		return $this->update_active_run(
			$run_id,
			array(
				'finished_at' => call_user_func( $this->now ),
				'status'      => WPCV_Run_Status::FAILED,
				'notes'       => (string) $notes,
				'diff_status' => WPCV_Diff_Status::SKIPPED,
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * 実行(run)行を `WPCV_Run_Status::ABORTED` にする(v0.4.0 §Step4: run
	 * deadline超過sweep。`WPCV_Chunk_Dispatcher` から呼ぶ。CR-01是正で
	 * `planning` にも対応).
	 *
	 * `mark_run_failed()` と同じ3段階CAS(`update_active_run()`)を使う理由も
	 * 同じ(`mark_run_failed()` の docblock 参照。ここでは「deadline超過」という
	 * 別の終端理由のため専用メソッドとして分離した).
	 *
	 * @param int    $run_id 対象の run の id.
	 * @param string $notes  終了理由. 省略可.
	 * @return bool 更新できたら true。false は対象行が active な状態
	 *              (`WPCV_Run_Status::ACTIVE`)のいずれでもない(既に終端状態
	 *              だった等)ことを意味する.
	 */
	public function mark_run_aborted( $run_id, $notes = '' ) {
		return $this->update_active_run(
			$run_id,
			array(
				'finished_at' => call_user_func( $this->now ),
				'status'      => WPCV_Run_Status::ABORTED,
				'notes'       => (string) $notes,
				'diff_status' => WPCV_Diff_Status::SKIPPED,
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Active な run 行(`WPCV_Run_Status::ACTIVE` のいずれか)を、状態を順に
	 * 試して更新する(v0.4.0コードレビューCR-01是正: `mark_run_failed()`/
	 * `mark_run_aborted()` が個別に持っていた2段階CAS〔`running`→`queued`〕を
	 * 共通化し、新設した `planning` にも対応する3段階にした).
	 *
	 * 実 `$wpdb::update()` の WHERE 句には IN() を組み立てられないため、
	 * `WPCV_Run_Status::ACTIVE` の順に1状態ずつ試す(いずれか1つで更新できた
	 * 時点で終了。他の状態への再試行は対象0件のno-opになるだけで安全)。
	 * 「どのactive状態からでも終端へ倒せる」という操作の意味が変わらない
	 * この2メソッドだけがこのヘルパーを使う想定(`finish_run()`のように
	 * 特定の1状態〔`running`〕のみを条件にすべき箇所には使わない).
	 *
	 * v0.5後半 §Step12(§3.4): 実際に更新できた場合のみ `wpcv_run_terminated`
	 * フックを発火する.呼び出し元(`mark_run_failed()`/`mark_run_aborted()`の
	 * さらに外側)ではなく、状態遷移が成功したこの1か所に集約することで、
	 * 呼び出し箇所ごとにフック発火の有無がドリフトすることを防ぐ.
	 *
	 * @param int   $run_id 対象の run の id.
	 * @param array $data   更新するデータ(`status` キーを含むこと.フック引数に使う).
	 * @param array $format `$data` の `%s`/`%d` 書式(1件目の要素は必ず
	 *                       `array( 'id' => ..., 'status' => ... )` のWHERE用
	 *                       書式〔`%d`, `%s`〕の前に来る点は呼び出し元が揃える).
	 * @return bool `WPCV_Run_Status::ACTIVE` のいずれかで更新できたら true.
	 */
	private function update_active_run( $run_id, array $data, array $format ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		foreach ( WPCV_Run_Status::ACTIVE as $status ) {
			$updated = $this->wpdb->update(
				$table,
				$data,
				array(
					'id'     => (int) $run_id,
					'status' => $status,
				),
				$format,
				array( '%d', '%s' )
			);

			if ( $updated > 0 ) {
				/**
				 * Runが終端状態(failed/aborted/success/partial)に達したことを知らせる
				 * (v0.5後半 §Step15の失敗アラートが購読する.§3.4参照).
				 *
				 * @param int    $run_id 終端に達した run の id.
				 * @param string $status 遷移後の `wpcv_runs.status`.
				 */
				do_action( 'wpcv_run_terminated', (int) $run_id, (string) $data['status'] );

				return true;
			}
		}

		return false;
	}

	/**
	 * 実行(run)行を新規 insert する(`reserve_run()`/`reserve_due_run()` からのみ
	 * 呼ぶ内部ヘルパー).
	 *
	 * @param string      $status        insert する `status`(`WPCV_Run_Status::PLANNING` または
	 *                                   `WPCV_Run_Status::QUEUED`).
	 * @param string      $run_trigger   cron|manual|cli|rest.
	 * @param string      $runner        sync|async.
	 * @param string|null $scheduled_for `reserve_due_run()`(v0.4.0 §Step6)が渡す、
	 *                                   本日の設定実行時刻のUTC DATETIME文字列。
	 *                                   `reserve_run()` からは渡されず(常に `null`)、
	 *                                   その場合は列を書き込まない(既存の
	 *                                   同期実行系の挙動に影響しない).
	 * @return int 作成した run の id.
	 *
	 * @throws RuntimeException `$wpdb->insert()` が失敗した場合(v0.4.0コード
	 *                          レビューCR-03是正)。ここでチェックせずに
	 *                          `$this->wpdb->insert_id` をそのまま返すと、insert
	 *                          失敗時にPHPの `$wpdb` 実装が保持し続ける「直前の
	 *                          成功したinsertのid」を誤って新規runのidとして
	 *                          返してしまい、以降の処理が別のrunの行を上書きする
	 *                          事故につながる(根拠: WordPress の `$wpdb->insert()`
	 *                          はSQLエラー時に例外を投げず `false` を返すだけで、
	 *                          `insert_id` を更新しない).
	 */
	private function insert_run_row( $status, $run_trigger, $runner, $scheduled_for = null ) {
		$table      = $this->wpdb->base_prefix . 'wpcv_runs';
		$now_string = call_user_func( $this->now );

		$data   = array(
			'started_at'  => $now_string,
			'status'      => $status,
			'run_trigger' => $run_trigger,
			'runner'      => $runner,
			// v0.4.0 §Step4: `WPCV_Chunk_Dispatcher` が deadline超過を検知して
			// `aborted` へ倒すためのしきい値(`DEFAULT_DEADLINE_HOURS` のdocblock参照)。
			// 一括実行(`WPCV_Run_Coordinator`)はこの列を読まないため、
			// 書き込むだけで既存の同期実行系の挙動には影響しない.
			'deadline_at' => gmdate( 'Y-m-d H:i:s', strtotime( $now_string ) + self::DEFAULT_DEADLINE_HOURS * HOUR_IN_SECONDS ),
		);
		$format = array( '%s', '%s', '%s', '%s', '%s' );

		if ( null !== $scheduled_for ) {
			$data['scheduled_for'] = $scheduled_for;
			$format[]              = '%s';
		}

		if ( false === $this->wpdb->insert( $table, $data, $format ) ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Run_Repository::insert_run_row() の insert に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}

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
	 * 実行(run)行を完了状態にする(status・finished_at・集計値を更新する).
	 *
	 * `status = 'running'` を WHERE に含める(v0.3.1 §Step1: `mark_run_failed()` と
	 * 同じ理由。stale sweep に先を越されて既に `failed` にされていた run へ、
	 * 後から戻ってきた旧ワーカーが success/partial を書き戻せてしまう事故
	 * 〔プラン§P1「stale化後に旧ワーカーが成功で上書きできる」〕を防ぐ).
	 *
	 * `diff_status` に `pending` を同じ UPDATE で書き込む(v0.5後半プラン §3.1:
	 * success/partial になった run は差分処理の対象になるため. 差分処理
	 * (Step12以降)が claim できるようにする起点であり、ここで書き漏らすと
	 * どの run も差分処理へ進めなくなる).
	 *
	 * v0.5後半 §Step12(§3.4): 実際に更新できた場合のみ `wpcv_run_terminated`
	 * フックを発火する(`update_active_run()` の同じフックと合わせて、
	 * `mark_run_failed()`/`mark_run_aborted()`/`finish_run()` の3メソッドすべてが
	 * 「状態遷移が成功した1か所」でのみ発火する).
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
				'diff_status'          => WPCV_Diff_Status::PENDING,
			),
			array(
				'id'     => (int) $run_id,
				'status' => WPCV_Run_Status::RUNNING,
			),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' ),
			array( '%d', '%s' )
		);

		if ( $updated > 0 ) {
			/** この記述は `update_active_run()` の同名フックのdocblock参照. */
			do_action( 'wpcv_run_terminated', (int) $run_id, (string) $summary['status'] );
		}

		return $updated > 0;
	}

	/**
	 * 差分処理(v0.5後半 §Step12)の1chunk分を進める権利をclaimする(§3.1の状態遷移).
	 *
	 * `wpcv_target_runs.claim_next()`(CAS方式)と異なり、runは1行しか無いため
	 * 候補選定は不要(既知の `$run_id` に対する単純なCAS).target_runのlease方式
	 * (`sweep_expired_leases()`→`claim_next()`の2段階)とも異なり、次の3状態遷移を
	 * このメソッド1つにまとめている:
	 *
	 * - `pending` → `processing`(通常のclaim.`diff_attempt_count` は変えない.
	 *   §3.1「chunkが正常にyieldして継続する分はこのカウントを消費しない」と
	 *   同じ考え方)
	 * - `processing`/`alerting`(lease有効) → 変わらず `claimed: false`(他プロセスが
	 *   実際に処理中. §3.1「他のプロセスがclaimしようとする→何もせず戻る」)
	 * - `processing`/`alerting`(lease切れ) → 試行回数が上限以内なら `pending` へ
	 *   戻してから即座に同じCASで再claim(worker crash からの再開.§3.1「途中の
	 *   プロセスが死んだケース」).上限を超えていれば `failed` へ倒す
	 *
	 * fencingに `diff_owner` の値そのものではなく `diff_status`(読み取り時点の値)を
	 * 使うのは、`$wpdb->update()` の WHERE に `NULL` を渡すと `%s` プレースホルダーが
	 * 空文字列にキャストされ `diff_owner = ''` という誤ったSQLになる(NULLの等価
	 * 比較にならない)ため、未claim状態を`diff_owner IS NULL`では表現しない設計に
	 * したことによる(`WPCV_Target_Run_Repository` の fencing とは異なる理由で、
	 * こちらは値そのものが `pending`/`processing` という非NULLの列挙値であるため
	 * 安全に等価比較できる).claim後の実際のchunk処理結果の書き込み
	 * (`finalize_diff_chunk()` 等)は、あらためて `diff_owner = $owner` も
	 * WHEREに含めてfencingする(この時点では非NULLの具体的な値のため安全).
	 *
	 * @param int    $run_id        対象の run の id.
	 * @param string $owner         この呼び出しを識別する一意な文字列
	 *                              (`WPCV_Diff_Dispatcher` が呼び出しごとに生成する).
	 * @param int    $lease_seconds lease有効期間(秒). 省略時は `DIFF_LEASE_SECONDS`.
	 * @param int    $max_attempts  lease切れの最大許容回数. 省略時は `DIFF_MAX_ATTEMPTS`.
	 * @return array{claimed: bool, run: array|null, failed: bool} `claimed` が
	 *         true なら `run` に claim 直後の行(`diff_owner`/`diff_status` 更新済み)を
	 *         持つ.`failed` が true は今回の呼び出しで試行上限超過により
	 *         `failed` へ倒したことを意味する(この場合 `claimed` は常に false).
	 */
	public function claim_diff( $run_id, $owner, $lease_seconds = self::DIFF_LEASE_SECONDS, $max_attempts = self::DIFF_MAX_ATTEMPTS ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';
		$run   = $this->find_by_id( (int) $run_id );

		if ( null === $run ) {
			return array(
				'claimed' => false,
				'run'     => null,
				'failed'  => false,
			);
		}

		$current_status = (string) ( $run['diff_status'] ?? '' );

		if ( WPCV_Diff_Status::PENDING === $current_status ) {
			return $this->cas_claim_diff( $table, $run_id, $current_status, $owner, $lease_seconds );
		}

		if ( ! in_array( $current_status, array( WPCV_Diff_Status::PROCESSING, WPCV_Diff_Status::ALERTING ), true ) ) {
			return array(
				'claimed' => false,
				'run'     => $run,
				'failed'  => false,
			);
		}

		if ( ! $this->is_past( (string) $run['diff_lease_expires_at'] ) ) {
			// Lease有効中. 他プロセスが実際に処理中(§3.1「変わらず戻る」).
			return array(
				'claimed' => false,
				'run'     => $run,
				'failed'  => false,
			);
		}

		// `diff_attempt_count` は列定義上 `NOT NULL default 0` だが、テストダブルは
		// カラムのdefault値を模さない(実際に insert() へ渡した値しか持たない)ため、
		// `insert_run_row()` が明示的に書かない値でも安全に読めるよう `??` で補う.
		$next_attempt_count = (int) ( $run['diff_attempt_count'] ?? 0 ) + 1;

		if ( $next_attempt_count > (int) $max_attempts ) {
			$updated = $this->wpdb->update(
				$table,
				array(
					'diff_status'           => WPCV_Diff_Status::FAILED,
					'diff_owner'            => null,
					'diff_lease_expires_at' => null,
					'diff_attempt_count'    => $next_attempt_count,
				),
				array(
					'id'          => (int) $run_id,
					'diff_status' => $current_status,
				),
				array( '%s', '%s', '%s', '%d' ),
				array( '%d', '%s' )
			);

			return array(
				'claimed' => false,
				'run'     => null,
				'failed'  => $updated > 0,
			);
		}

		// Lease切れ・試行上限内: いったん pending へ戻してから、同じ呼び出しの
		// 中で即座に再claimする(呼び出し元に2回に分けて再試行させない).
		$reverted = $this->wpdb->update(
			$table,
			array(
				'diff_status'           => WPCV_Diff_Status::PENDING,
				'diff_owner'            => null,
				'diff_lease_expires_at' => null,
				'diff_attempt_count'    => $next_attempt_count,
			),
			array(
				'id'          => (int) $run_id,
				'diff_status' => $current_status,
			),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d', '%s' )
		);

		if ( $reverted <= 0 ) {
			// 他プロセスが同時にこの行へ触れた(稀). 今回は諦め、次回の
			// dispatchに委ねる.
			return array(
				'claimed' => false,
				'run'     => null,
				'failed'  => false,
			);
		}

		return $this->cas_claim_diff( $table, $run_id, WPCV_Diff_Status::PENDING, $owner, $lease_seconds );
	}

	/**
	 * `claim_diff()` の `pending` → `processing` のCAS本体(通常claim・lease切れ後の
	 * 再claim共通).
	 *
	 * @param string $table         `wpcv_runs` テーブル名.
	 * @param int    $run_id        対象の run の id.
	 * @param string $expected_from CASの `WHERE diff_status = ?` に使う、読み取り時点の値
	 *                              (常に `WPCV_Diff_Status::PENDING`).
	 * @param string $owner         claim した worker を識別する一意な文字列.
	 * @param int    $lease_seconds lease有効期間(秒).
	 * @return array{claimed: bool, run: array|null, failed: bool}
	 */
	private function cas_claim_diff( $table, $run_id, $expected_from, $owner, $lease_seconds ) {
		$lease_expires_at = gmdate( 'Y-m-d H:i:s', strtotime( call_user_func( $this->now ) ) + (int) $lease_seconds );

		$updated = $this->wpdb->update(
			$table,
			array(
				'diff_status'           => WPCV_Diff_Status::PROCESSING,
				'diff_owner'            => (string) $owner,
				'diff_lease_expires_at' => $lease_expires_at,
			),
			array(
				'id'          => (int) $run_id,
				'diff_status' => $expected_from,
			),
			array( '%s', '%s', '%s' ),
			array( '%d', '%s' )
		);

		if ( $updated <= 0 ) {
			return array(
				'claimed' => false,
				'run'     => null,
				'failed'  => false,
			);
		}

		return array(
			'claimed' => true,
			'run'     => $this->find_by_id( (int) $run_id ),
			'failed'  => false,
		);
	}

	/**
	 * 差分処理の1chunkを確定する(§3.1「chunkを1つ処理...diff_cursorを進め、
	 * leaseを延長」に対応.ただしStep12の設計〔本ファイルのクラス docblock
	 * 相当の判断.`WPCV_Diff_Dispatcher` クラス docblock参照〕では、
	 * leaseを延長して同じ`processing`のまま居座るのではなく、target_run の
	 * `update_chunk_progress()`(completed:false時にleaseを手放しRETRYへ戻す)と
	 * 同じ考え方を採用した: 完了していなければ `pending` へ戻し(次回のclaimへ
	 * 委ねる)、完了していれば集計値を書いて `alerting` へ進める.
	 *
	 * `diff_owner = $owner` をWHEREに含めてfencingする(claim_diff()が割り当てた
	 * ものと一致する場合のみ書き込みを反映させる.§3.1「書き込みはすべて
	 * diff_ownerをWHEREに含める」).
	 *
	 * @param int         $run_id      対象の run の id.
	 * @param string      $owner       `claim_diff()` がこの呼び出しに割り当てた owner.
	 * @param string|null $cursor_json 次回に渡す `diff_cursor`(完了時は `null`).
	 * @param bool        $completed   全targetの差分処理が終わったか.
	 * @param array|null  $counts      `$completed` が true のときの集計値
	 *                                 (`new`/`resolved`/`continuing`).
	 * @return bool 更新できたら true(false はfencing失敗. 呼び出し元は諦めてよい
	 *              ―― 既に別プロセスが再claimしている).
	 */
	public function finalize_diff_chunk( $run_id, $owner, $cursor_json, $completed, ?array $counts = null ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		if ( $completed ) {
			$data   = array(
				'diff_status'           => WPCV_Diff_Status::ALERTING,
				'diff_owner'            => null,
				'diff_lease_expires_at' => null,
				'diff_cursor'           => null,
				'findings_new'          => (int) $counts['new'],
				'findings_resolved'     => (int) $counts['resolved'],
				'findings_continuing'   => (int) $counts['continuing'],
			);
			$format = array( '%s', '%s', '%s', '%s', '%d', '%d', '%d' );
		} else {
			$data   = array(
				'diff_status'           => WPCV_Diff_Status::PENDING,
				'diff_owner'            => null,
				'diff_lease_expires_at' => null,
				'diff_cursor'           => $cursor_json,
			);
			$format = array( '%s', '%s', '%s', '%s' );
		}

		$updated = $this->wpdb->update(
			$table,
			$data,
			array(
				'id'          => (int) $run_id,
				'diff_status' => WPCV_Diff_Status::PROCESSING,
				'diff_owner'  => (string) $owner,
			),
			$format,
			array( '%d', '%s', '%s' )
		);

		return $updated > 0;
	}

	/**
	 * アラート送信(v0.5後半 §Step14. `WPCV_Alert_Sender`)の結果をrun行に記録する.
	 *
	 * Fencingは持たない(`finalize_diff_alerting()`と同じ理由. §3.3「alertingは
	 * この1遷移のみ」に相当する終端記録であり、`WPCV_Alert_Sender::send_for_run()`が
	 * 1回のdispatch呼び出しの中で完結して呼ぶ設計のため。Step14cで`alerting`の
	 * claim・leaseを導入する際、fencingが必要かどうかはそちらで再検討する).
	 *
	 * @param int         $run_id                 対象の run の id.
	 * @param string      $alert_status           `no_recipient`/`sent`/`failed`/`not_needed`
	 *                                            のいずれか(§2.5).
	 * @param string|null $alert_error            `WP_Error::get_error_message()`のみ
	 *                                            (§6: `get_error_data()`は保存しない).
	 *                                            500文字を超える分は切り捨てる.
	 * @param string|null $alert_channel_failures 失敗した追加チャネルの`name`をカンマ区切りで
	 *                                            (§4.3). 500文字を超える分は切り捨てる.
	 * @param bool        $mark_attempted         `true`なら`alert_attempted_at`も書く
	 *                                            (`not_needed`のときは呼び出し元が`false`を渡す
	 *                                            ―― 送信を試みていないため).
	 * @return bool `$wpdb->update()`の戻り値(0件一致でも`false`にはならない.
	 *              呼び出し元は対象runが存在する前提で呼ぶ).
	 */
	public function record_alert_result( $run_id, $alert_status, $alert_error = null, $alert_channel_failures = null, $mark_attempted = true ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$data   = array( 'alert_status' => (string) $alert_status );
		$format = array( '%s' );

		if ( $mark_attempted ) {
			$data['alert_attempted_at'] = call_user_func( $this->now );
			$format[]                   = '%s';
		}

		$data['alert_error'] = null === $alert_error ? null : substr( (string) $alert_error, 0, 500 );
		$format[]            = '%s';

		$data['alert_channel_failures'] = null === $alert_channel_failures ? null : substr( (string) $alert_channel_failures, 0, 500 );
		$format[]                       = '%s';

		return false !== $this->wpdb->update( $table, $data, array( 'id' => (int) $run_id ), $format, array( '%d' ) );
	}

	/**
	 * `alerting` を `done` へ進める(v0.5後半 §Step12時点では実際の送信を行わず
	 * 即座に `done` にする.§3.3「アラートの段階は何もせず done にする」).
	 *
	 * このCASは `diff_owner` を条件に含めない.`alerting` はこの1遷移のみ
	 * (以後 `done` から動かない終端)であり、複数プロセスが同時に呼んでも
	 * 最初の1件だけが実際に更新され残りは0行(=「既に他プロセスが `done` に
	 * した」の意味しか持たない)ため、専用の owner による排他は不要と判断した.
	 *
	 * @param int $run_id 対象の run の id.
	 * @return bool 更新できたら true(false は既に他プロセスが `done` にしていた
	 *              こと〔正常系〕を意味する).
	 */
	public function finalize_diff_alerting( $run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$updated = $this->wpdb->update(
			$table,
			array( 'diff_status' => WPCV_Diff_Status::DONE ),
			array(
				'id'          => (int) $run_id,
				'diff_status' => WPCV_Diff_Status::ALERTING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);

		return $updated > 0;
	}

	/**
	 * 差分処理が `pending`、またはlease切れの `processing`/`alerting` の run を
	 * 古いもの(id最小)から最大1件だけ探す(v0.5後半 §Step12・§3.3「取りこぼしの
	 * 回収」).新しい run の開始時(`WPCV_Run_Starter::plan_and_save()`)に呼ぶ想定.
	 *
	 * その場で処理はしない(呼び出し元が継続の action を1つ予約するだけに
	 * とどめる. 新しい run の開始をタイムアウトさせないため).
	 *
	 * @return int|null 見つからなければ `null`.
	 */
	public function find_stale_diff_run() {
		$now_string = call_user_func( $this->now );
		$candidates = array();

		foreach ( $this->all_rows() as $row ) {
			$status = (string) ( $row['diff_status'] ?? '' );

			if ( WPCV_Diff_Status::PENDING === $status ) {
				$candidates[] = $row;
				continue;
			}

			if ( in_array( $status, array( WPCV_Diff_Status::PROCESSING, WPCV_Diff_Status::ALERTING ), true )
				&& ! empty( $row['diff_lease_expires_at'] )
				&& (string) $row['diff_lease_expires_at'] < $now_string
			) {
				$candidates[] = $row;
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);

		return (int) $candidates[0]['id'];
	}

	/**
	 * 与えられたMySQL DATETIME文字列(UTC)が現在時刻より過去かどうかを判定する
	 * (`WPCV_Chunk_Dispatcher::is_past()` と同じロジック. `claim_diff()` の
	 * lease切れ判定用).
	 *
	 * @param string $datetime `Y-m-d H:i:s` 形式のUTC日時文字列.
	 * @return bool
	 */
	private function is_past( $datetime ) {
		if ( '' === $datetime ) {
			return false;
		}

		$timestamp = strtotime( $datetime );

		return false !== $timestamp && $timestamp <= strtotime( call_user_func( $this->now ) );
	}

	/**
	 * 現在 active(`WPCV_Run_Status::ACTIVE`。実行権を保持している)の run が
	 * 無いかを調べる(v0.3 §Step8: RESTハンドラの冪等性判定に使う。v0.3.1 §Step1で
	 * `queued` も対象に拡張、v0.4.0コードレビューCR-01是正で `planning` も対象に拡張).
	 *
	 * 呼び出し側は先に `WPCV_Chunk_Dispatcher::sweep_deadline_and_expired_leases()`
	 * を呼んでおくこと(このメソッド自身は stale 判定を行わない。ここで見つかる
	 * 行は「stale ではない = 現在進行中とみなせる」run である前提を呼び出し元が
	 * 保証する設計。v0.3〜v0.3.1で使っていた `started_at` 基準の
	 * `sweep_stale_running()` は、v0.4.0コードレビューCR-07是正で
	 * `deadline_at`+target lease 基準の判定に置き換えたため削除した
	 * 〔`WPCV_Chunk_Dispatcher::sweep_deadline_and_expired_leases()` の
	 * docblock参照〕)。`reserve_run()` は
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
	 * Run行を1件、全カラム込みで読み取る(v0.4.0 §Step4: `WPCV_Chunk_Dispatcher` が
	 * `deadline_at`・`status` を確認するために使う。`find_active_run()` は
	 * `id`/`status` のみを読む軽量版のため、`deadline_at` 等が必要なここでは
	 * 別メソッドにした).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return array|null 見つからなければ `null`.
	 */
	public function find_by_id( $run_id ) {
		foreach ( $this->all_rows() as $row ) {
			if ( (int) $row['id'] === (int) $run_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * 全run行のうち、最も新しい(id最大の)ものを1件返す(v0.4.0 §Step6:
	 * `WPCV_Rest_Run_Controller` が「作業対象のrunが無い」場合に直近の状態を
	 * 報告するために使う).
	 *
	 * @return array|null run行が1件も無ければ `null`.
	 */
	public function find_most_recent_run() {
		return self::most_recent_of( $this->all_rows() );
	}

	/**
	 * 全run行を、新しい(id最大の)ものから順にpagination付きで返す(v0.4.0 §Step9:
	 * `WPCV_Page_Run_History` の実行履歴一覧画面から使う).
	 *
	 * `WPCV_Finding_Repository::query()` と同じ理由(テストダブルがWHERE句を
	 * 解釈しないため)で、sort・paginationはPHP側で行う。v1では絞り込み条件を
	 * 設けない(運用開始直後はrun件数が少なく、必要になった時点でstatus等の
	 * 絞り込みを追加する).
	 *
	 * @param array $args {
	 *     省略可能なpagination条件.
	 *
	 *     @type int $page     既定1(1未満は1にclampする).
	 *     @type int $per_page 既定`DEFAULT_PER_PAGE`(1-`MAX_PER_PAGE`にclampする).
	 * }
	 * @return array{rows: array, total: int} `total` はpagination前の全件数.
	 */
	public function find_all( array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'page'     => 1,
				'per_page' => self::DEFAULT_PER_PAGE,
			)
		);

		$rows = $this->all_rows();

		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $b['id'] <=> (int) $a['id'];
			}
		);

		$total    = count( $rows );
		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$offset   = ( $page - 1 ) * $per_page;

		return array(
			'rows'  => array_slice( $rows, $offset, $per_page ),
			'total' => $total,
		);
	}

	/**
	 * 指定した `run_trigger`(`cron`/`manual`/`cli`/`rest`)の run 行のうち、最も新しい
	 * (id最大の)ものを1件返す(v0.4.0 §Step10: 状態パネルの「最後にCLIで実行した
	 * 時刻」表示向け。Web PHPのプロセスからは「WP-CLIが今インストールされているか」
	 * 自体を確実に判定できないため、代わりに観測可能な事実 ―― 過去にCLI経由の run が
	 * 記録されているか・いつか ―― を表示する設計〔プラン§Step10〕).
	 *
	 * @param string $run_trigger `cron`|`manual`|`cli`|`rest`.
	 * @return array|null 該当する run が1件も無ければ `null`.
	 */
	public function find_most_recent_by_trigger( $run_trigger ) {
		$matching_rows = array_values(
			array_filter(
				$this->all_rows(),
				static function ( $row ) use ( $run_trigger ) {
					return isset( $row['run_trigger'] ) && (string) $row['run_trigger'] === (string) $run_trigger;
				}
			)
		);

		return self::most_recent_of( $matching_rows );
	}

	/**
	 * Terminal状態(`WPCV_Run_Status::TERMINAL`)の run 行のうち、最も新しい
	 * (id最大の)ものを1件返す(v0.4.0 §Step7: `WPCV_Rest_Status_Controller` が
	 * 「直近に完了したrun」を報告するために使う。`find_most_recent_run()` は
	 * ステータスを問わないため、activeなrunが最新の場合は区別できない).
	 *
	 * @return array|null 該当する run が1件も無ければ `null`.
	 */
	public function find_most_recent_terminal_run() {
		$terminal_rows = array_values(
			array_filter(
				$this->all_rows(),
				static function ( $row ) {
					return WPCV_Run_Status::is_terminal( $row['status'] );
				}
			)
		);

		return self::most_recent_of( $terminal_rows );
	}

	/**
	 * `find_most_recent_run()`/`find_most_recent_terminal_run()` で共有する
	 * 「最も id が大きい行を返す」処理.
	 *
	 * @param array<int, array> $rows 対象の行群.
	 * @return array|null `$rows` が空なら `null`.
	 */
	private static function most_recent_of( array $rows ) {
		if ( empty( $rows ) ) {
			return null;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $b['id'] <=> (int) $a['id'];
			}
		);

		return $rows[0];
	}

	/**
	 * 外部HTTP(v0.4.0 §Step6)向けに、日次due判定込みで実行権を予約する.
	 *
	 * `reserve_run()` と同じ advisory lock の中で次の順に判定する:
	 *
	 * 1. active run(`queued`/`planning`/`running`)があれば、`reserve_run()` と
	 *    同じくそれを返す(新規作成は行わない。5分間隔の外部cronが連打しても、
	 *    進行中の run が1件そのまま前進し続けることを保証する).
	 * 2. 無ければ、現在時刻(`$this->now`)が本日の設定実行時刻(`$hour:$minute`
	 *    UTC)をまだ過ぎていない場合、または本日分の run(`scheduled_for` の暦日が
	 *    今日と一致する run。ステータスは問わない)が既に存在する場合は、
	 *    何も作成せず `run_id: null` を返す(「作業対象の run が無い」ことを表す。
	 *    呼び出し元は `find_most_recent_run()` 等で直近の状態を報告すること).
	 * 3. どちらでもなければ、`scheduled_for` に本日の設定実行時刻を記録した新規
	 *    run を作成する.
	 *
	 * `$hour`/`$minute` を(`WPCV_Settings` 経由の値そのものではなく)プリミティブな
	 * 値で受け取るのは、他の Repository メソッドと同じく「現在時刻」の唯一の情報源を
	 * `$this->now` に一本化し、テストで固定時刻を注入するだけで due/not-due の
	 * どちらの分岐も決定的に検証できるようにするため(呼び出し元が別途 `time()` を
	 * 読んで判定を分散させると、テストが実際の壁時計時刻に依存してしまう).
	 *
	 * @param int   $hour   設定実行時刻の時(UTC. 0-23).
	 * @param int   $minute 設定実行時刻の分(UTC. 0-59).
	 * @param array $args   `reserve_run()` と同じ(`run_trigger`/`runner`/`initial_status`).
	 * @return array{
	 *     run_id: int|null,
	 *     status: string|null,
	 *     active: bool,
	 *     created: bool,
	 *     lock_failed: bool,
	 * } `created` が真の場合のみ、呼び出し元は `WPCV_Run_Starter::plan_and_save()` で
	 *   target_runs を保存する必要がある(`active` な既存 run は既に保存済みのため不要).
	 *
	 * @throws RuntimeException `insert_run_row()` の insert が失敗した場合
	 *                          (`reserve_run()` の同じ `@throws` 参照).
	 */
	public function reserve_due_run( $hour, $minute, array $args = array() ) {
		$run_trigger    = isset( $args['run_trigger'] ) ? (string) $args['run_trigger'] : 'manual';
		$runner         = isset( $args['runner'] ) ? (string) $args['runner'] : 'sync';
		$initial_status = isset( $args['initial_status'] ) ? (string) $args['initial_status'] : WPCV_Run_Status::PLANNING;

		$wpdb      = $this->wpdb;
		$lock_name = $this->lock_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK() はキャッシュ不可.
		$lock_acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, self::LOCK_TIMEOUT_SECONDS )
		);

		if ( '1' !== (string) $lock_acquired ) {
			return array(
				'run_id'      => null,
				'status'      => null,
				'active'      => false,
				'created'     => false,
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
					'created'     => false,
					'lock_failed' => false,
				);
			}

			$now_string    = call_user_func( $this->now );
			$scheduled_for = gmdate( 'Y-m-d H:i:s', $this->today_due_at( (int) $hour, (int) $minute ) );

			if ( $now_string < $scheduled_for || $this->has_run_scheduled_for_date( substr( $scheduled_for, 0, 10 ) ) ) {
				return array(
					'run_id'      => null,
					'status'      => null,
					'active'      => false,
					'created'     => false,
					'lock_failed' => false,
				);
			}

			$run_id = $this->insert_run_row( $initial_status, $run_trigger, $runner, $scheduled_for );

			return array(
				'run_id'      => $run_id,
				'status'      => $initial_status,
				'active'      => false,
				'created'     => true,
				'lock_failed' => false,
			);
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- RELEASE_LOCK() はキャッシュ不可.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * `$this->now` が属するUTC暦日における `$hour:$minute` の Unix timestamp を返す
	 * (「今日の設定実行時刻」。その時刻を過ぎているかどうかは問わない.
	 * `reserve_due_run()` の due 判定専用の内部ヘルパー).
	 *
	 * @param int $hour   時(UTC).
	 * @param int $minute 分(UTC).
	 * @return int
	 */
	private function today_due_at( $hour, $minute ) {
		$now_timestamp = strtotime( call_user_func( $this->now ) );

		return gmmktime(
			$hour,
			$minute,
			0,
			(int) gmdate( 'n', $now_timestamp ),
			(int) gmdate( 'j', $now_timestamp ),
			(int) gmdate( 'Y', $now_timestamp )
		);
	}

	/**
	 * 指定した暦日(`Y-m-d`)を `scheduled_for` に持つ run が(ステータスを問わず)
	 * 既に存在するかどうかを調べる(`reserve_due_run()` の「当日分は作成済みか」判定).
	 *
	 * @param string $date `Y-m-d` 形式(UTC).
	 * @return bool
	 */
	private function has_run_scheduled_for_date( $date ) {
		foreach ( $this->all_rows() as $row ) {
			if ( empty( $row['scheduled_for'] ) ) {
				continue;
			}

			if ( substr( (string) $row['scheduled_for'], 0, 10 ) === $date ) {
				return true;
			}
		}

		return false;
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

		// 動的な値を含まない固定リテラルのみのクエリ(IN リストの組み立て理由は
		// `active_status_sql_list()` 参照).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static literal (built from a hardcoded enum, no user input), table name only.
		$active_rows = $this->wpdb->get_results( "SELECT id, status FROM {$table} WHERE status IN ( {$this->active_status_sql_list()} )", ARRAY_A );
		$active_rows = is_array( $active_rows ) ? $active_rows : array();

		foreach ( $active_rows as $row ) {
			// テストダブル(`WPCV_Test_Fake_WPDB::get_results()`)が WHERE 句を
			// 解釈せずテーブルの全行を返す簡易実装のための保険として、
			// status を改めて確認する.
			if ( WPCV_Run_Status::is_active( $row['status'] ) ) {
				return array(
					'id'     => (int) $row['id'],
					'status' => (string) $row['status'],
				);
			}
		}

		return null;
	}

	/**
	 * `find_by_id()`/`find_most_recent_run()`/`has_run_scheduled_for_date()`で
	 * 共有する「テーブルの全行を読み取る」処理(v0.4.0 §Step6で `find_by_id()` から
	 * 抽出。`WPCV_Target_Run_Repository::all_rows()` と同じ理由〔テストダブルが
	 * WHERE 句を解釈しないための設計〕).
	 *
	 * @return array<int, array>
	 */
	private function all_rows() {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$rows = $this->wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * `WPCV_Run_Status::ACTIVE` から `IN ( 'a', 'b', ... )` に埋め込む値部分を
	 * 組み立てる(v0.4.0コードレビューCR-01是正)。
	 *
	 * かつては `find_active_run()` と(削除済みの)`sweep_stale_running()` が
	 * それぞれ独自に `'queued', 'running'` を直書きしており、`planning` 状態を
	 * 追加した際に片方だけ更新して同期が崩れる事故が実際に起きかけた
	 * (このメソッド追加の経緯そのもの)。値はすべて `WPCV_Run_Status` の定数
	 * (ユーザー入力を含まない固定enum)であるため、`prepare()` を介さない
	 * 文字列連結でも安全.
	 *
	 * @return string
	 */
	private function active_status_sql_list() {
		return "'" . implode( "', '", WPCV_Run_Status::ACTIVE ) . "'";
	}
}
