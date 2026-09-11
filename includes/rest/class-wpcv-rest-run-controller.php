<?php
/**
 * WPCV_Rest_Run_Controller クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `POST /wp-json/wpcv/v1/run`(外部HTTP経由の「モードC」。v0.3 §Step8、
 * v0.3.1 §Step4で同期専用に契約を見直し、v0.4.0 §Step6でchunk分割実行に
 * 対応する形へ契約を再度見直した).
 *
 * V0.3.1では「active runが無ければ常に新規runを1件、同一リクエスト内で完走する
 * まで実行する」契約だった。この契約は、1リクエストで完走できない規模の
 * サイト(chunk分割実行〔`WPCV_Chunk_Dispatcher`〕が前提とする規模)には
 * そもそも使えず、かつ「5分ごとに叩かれるたびに新しいrunを作ってしまわないか」
 * という日次実行の重複防止も考慮されていなかった。v0.4.0 §Step6で次の契約に
 * 置き換える:
 *
 * 1. Stale lease・run deadline超過のsweepは、後段で実際に対象runへ
 *    `WPCV_Chunk_Dispatcher::dispatch()` を呼んだ時点で自動的に行われる
 *    (`dispatch()` 自身の冒頭処理。`WPCV_Chunk_Dispatcher` のクラス docblock 参照)。
 *    このハンドラが個別に呼び出す必要はない。旧来の `WPCV_Run_Repository::
 *    sweep_stale_running()`(`started_at` 基準。v0.3.1由来)もあえて呼ばない ――
 *    このメソッドは「1 action = 1 run全体」を前提にした閾値(既定180分)であり、
 *    複数リクエストにまたがって前進する chunk 分割実行(本メソッドが正にそれ)を
 *    誤って stale 扱いしてしまう。継続中の run の生死判定は `deadline_at`
 *    (既定6時間。`WPCV_Run_Repository::DEFAULT_DEADLINE_HOURS`)に一本化した
 *    ―― 進行中と判定された run へは必ず一度 `dispatch()` を呼ぶため
 *    (このクラスの2.以降参照)、deadline超過はそこで検知される.
 * 2. Active run(`queued`/`running`)が既にあれば、新規runは作らずそれを選ぶ
 *    (5分間隔の外部cronが連打しても、進行中のrunがそのまま前進し続ける).
 * 3. Active runが無ければ、`WPCV_Run_Repository::reserve_due_run()` が
 *    「本日の設定実行時刻(Settings画面のWP-Cron実行時刻と同じ値)を過ぎているか」
 *    「本日分のrunが既に存在するか」を判定し、両方満たす場合だけ新規runを作る。
 *    満たさない場合は何も作らず、直近runの状態を報告する(§5「外部HTTPの
 *    『日次開始』と『前進』の条件」).
 * 4. 選んだ(または新規作成した)runに対し、`WPCV_Plugin::sync_dispatcher()`
 *    (continuation schedulerがno-opのインスタンス)の `dispatch()` を、
 *    `WPCV_Settings::get_external_http_time_budget_seconds()` の時間予算内で
 *    繰り返す。**global `ActionScheduler::runner()->run()` は呼ばない**
 *    (v0.3.1 §Step4で除去した「サイト全体のASキューを実行してしまう」副作用を
 *    再導入しないため)。1回の `dispatch()` 呼び出し自体の時間はStep3の
 *    chunk verifier内部の時間予算(`WPCV_Chunk_Dispatcher::DEFAULT_BUDGET_MAX_SECONDS`)
 *    が守るため、このハンドラは「何回繰り返すか」だけを制御する.
 * 5. レスポンスは `run_id`/`status`/`pending_targets`/`retry_targets`/
 *    `next_retry_at` を返す。呼び出し元(外部cron)はこれを見て、runが終端に
 *    達していなければ次回のPOSTでそのまま続きから前進できる.
 *
 * 認証は`WPCV_Rest_Token`によるトークン専用(v0.3 §Step9、v0.4.0 §Step7で
 * `SCOPE_RUN`として分離)。cookie認証との併用はしない(WordPressログイン
 * セッションを持たない外部システムcronから呼べるようにするための設計。
 * `WPCV_Rest_Token::check_permission()` の docblock参照). パーミッション
 * コールバック・`Cache-Control: no-store` 付きレスポンス組み立ては
 * `WPCV_Rest_Status_Controller`・`WPCV_Rest_Findings_Controller` と共有する
 * (`WPCV_Rest_Token::check_permission()`/`WPCV_Rest_Support::response()` 参照).
 *
 * `GET /status`/`GET /findings`はv0.4.0 §Step7で追加した(`WPCV_Rest_Status_Controller`/
 * `WPCV_Rest_Findings_Controller`。本ファイルの対象外).
 */
class WPCV_Rest_Run_Controller {

	/**
	 * REST名前空間.
	 *
	 * @var string
	 */
	const NAMESPACE_NAME = 'wpcv/v1';

	/**
	 * ルートのパス(名前空間からの相対パス).
	 *
	 * @var string
	 */
	const ROUTE = '/run';

	/**
	 * ルートを登録する. `rest_api_init` フックから呼ぶ.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_NAME,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_run' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * パーミッションコールバック.
	 *
	 * `WPCV_Rest_Token::check_permission()`(v0.4.0 §Step7で3コントローラー分
	 * 共通化)に `SCOPE_RUN` を渡すだけの薄いラッパー(このエンドポイントのみが
	 * runをトリガーできる scope. `WPCV_Rest_Token` のクラス docblock参照).
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return true|WP_Error
	 */
	public static function check_permission( $request ) {
		return WPCV_Rest_Token::check_permission( $request, WPCV_Rest_Token::SCOPE_RUN );
	}

	/**
	 * `POST /run` のハンドラ(v0.4.0 §Step6で契約を見直し。クラス docblock 参照).
	 *
	 * @param WP_REST_Request $request リクエスト(未使用. パラメータを持たないため).
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_run( $request ) {
		unset( $request );

		$run_repository        = WPCV_Plugin::run_repository();
		$target_run_repository = WPCV_Plugin::target_run_repository();
		$run_time              = WPCV_Settings::get_run_time();

		$reservation = $run_repository->reserve_due_run(
			$run_time['hour'],
			$run_time['minute'],
			array(
				'run_trigger' => 'rest',
				'runner'      => 'sync',
			)
		);

		if ( $reservation['lock_failed'] ) {
			return new WP_Error(
				'wpcv_rest_busy',
				__( 'The verifier is busy. Try again shortly.', 'wp-checksum-verifier' ),
				array( 'status' => 503 )
			);
		}

		if ( null === $reservation['run_id'] ) {
			// Active runも無く、本日分の新規runも作らなかった(未due、または
			// 本日分は作成済み)。直近runの状態をそのまま報告する(クラス
			// docblock「外部HTTPの『日次開始』と『前進』の条件」参照).
			return WPCV_Rest_Support::response( self::describe_run( $run_repository->find_most_recent_run(), $target_run_repository ) );
		}

		$run_id  = (int) $reservation['run_id'];
		$context = WPCV_Context_Builder::build( 'rest' );

		if ( $reservation['created'] ) {
			try {
				WPCV_Run_Starter::plan_and_save( $run_repository, new WPCV_Run_Planner( WPCV_Plugin::suppression_repository() ), $target_run_repository, $run_id, $context );
			} catch ( Throwable $e ) {
				// plan_and_save() 自身が run を failed 化した上で再送出する
				// (`WPCV_Run_Starter` のクラス docblock 参照).
				return self::run_failed_error();
			}
		}

		try {
			self::drain_within_time_budget( $run_id, $context );
		} catch ( Throwable $e ) {
			$run_repository->mark_run_failed( $run_id, get_class( $e ) . ': ' . $e->getMessage() );

			return self::run_failed_error();
		}

		return WPCV_Rest_Support::response( self::describe_run( $run_repository->find_by_id( $run_id ), $target_run_repository ) );
	}

	/**
	 * `WPCV_Plugin::sync_dispatcher()` の `dispatch()` を、設定された時間予算内で
	 * 繰り返す(クラス docblock 「4.」参照).
	 *
	 * 実際に処理が進んだ(`action: 'processed'`)場合だけループを続ける。
	 * `run_finalized`/`aborted`/`run_not_found`/`run_already_terminal` は
	 * それ以上呼んでも意味が無いため即座に止まる。`waiting`(他workerのlease待ち)も
	 * 同様に即座に止める ―― lease有効期限は実時間の経過でしか切れないため、
	 * 間を置かずに `dispatch()` を呼び直しても状態は変わらず、時間予算を
	 * 無為に消費するだけになる(次回のPOSTに委ねる).
	 *
	 * @param int   $run_id  対象の run の id.
	 * @param array $context `WPCV_Context_Builder::build()` と同じ形.
	 * @return void
	 */
	private static function drain_within_time_budget( $run_id, array $context ) {
		$dispatcher = WPCV_Plugin::sync_dispatcher();
		$deadline   = time() + WPCV_Settings::get_external_http_time_budget_seconds();

		do {
			$result = $dispatcher->dispatch( $run_id, $context );
		} while ( 'processed' === $result['action'] && time() < $deadline );
	}

	/**
	 * レスポンスに含める `run_id`/`status`/`pending_targets`/`retry_targets`/
	 * `next_retry_at` を組み立てる.
	 *
	 * @param array|null                 $run                    `WPCV_Run_Repository::find_by_id()`/
	 *                                                            `find_most_recent_run()` の戻り値.
	 * @param WPCV_Target_Run_Repository $target_run_repository `wpcv_target_runs` の永続化層.
	 * @return array{run_id:int|null,status:string|null,pending_targets:int,retry_targets:int,next_retry_at:string|null}
	 */
	private static function describe_run( $run, WPCV_Target_Run_Repository $target_run_repository ) {
		if ( null === $run ) {
			return array(
				'run_id'          => null,
				'status'          => null,
				'pending_targets' => 0,
				'retry_targets'   => 0,
				'next_retry_at'   => null,
			);
		}

		$counts = self::count_targets( $target_run_repository->find_all_by_run( (int) $run['id'] ) );

		return array(
			'run_id'          => (int) $run['id'],
			'status'          => (string) $run['status'],
			'pending_targets' => $counts['pending'],
			'retry_targets'   => $counts['retry'],
			'next_retry_at'   => $counts['next_retry_at'],
		);
	}

	/**
	 * Target_run群から `pending`(未着手)・`retry`(再試行待ち)件数と、
	 * 最も早い `retry_after` を集計する.
	 *
	 * @param array $target_runs `WPCV_Target_Run_Repository::find_all_by_run()` の戻り値.
	 * @return array{pending:int,retry:int,next_retry_at:string|null}
	 */
	private static function count_targets( array $target_runs ) {
		$pending       = 0;
		$retry         = 0;
		$next_retry_at = null;

		foreach ( $target_runs as $target_run ) {
			if ( WPCV_Target_Status::QUEUED === $target_run['status'] ) {
				++$pending;
				continue;
			}

			if ( WPCV_Target_Status::RETRY !== $target_run['status'] ) {
				continue;
			}

			++$retry;

			if ( empty( $target_run['retry_after'] ) ) {
				continue;
			}

			if ( null === $next_retry_at || $target_run['retry_after'] < $next_retry_at ) {
				$next_retry_at = $target_run['retry_after'];
			}
		}

		return array(
			'pending'       => $pending,
			'retry'         => $retry,
			'next_retry_at' => $next_retry_at,
		);
	}

	/**
	 * 検証中に例外が発生した場合の `WP_Error`(500)を組み立てる.
	 *
	 * @return WP_Error
	 */
	private static function run_failed_error() {
		return new WP_Error(
			'wpcv_rest_run_failed',
			__( 'The verification run failed. Check the run history for details.', 'wp-checksum-verifier' ),
			array( 'status' => 500 )
		);
	}
}
