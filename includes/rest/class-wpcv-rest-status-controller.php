<?php
/**
 * WPCV_Rest_Status_Controller クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /wp-json/wpcv/v1/status`(v0.4.0 §Step7)。
 *
 * 現在進行中のrun(`current_run`。無ければ `null`)と、直近に完了したrun
 * (`last_run`。terminal状態のもののみ。§Step7プラン「current/last run」)を、
 * それぞれtarget集計・最終活動時刻・deadlineとあわせて返す。加えて、設定画面の
 * 日次実行時刻から計算した次回予定時刻(`next_scheduled_at`)も返す.
 *
 * `wpcv_runs.heartbeat_at` は書き込まれない列のため(v0.4.0 §Step1で追加したが
 * 用途が無いまま。`WPCV_Chunk_Dispatcher` のクラス docblock 参照)使わず、
 * 代わりに対象runのtarget_runsが持つ `heartbeat_at`/`finished_at` の最大値を
 * 「最終活動時刻」として計算する(`last_activity_at()` 参照。target単位の
 * claim・finalizeのたびに更新される列のため、run自体より実態に即した
 * 「stale run」判定材料になる).
 *
 * 認証は `WPCV_Rest_Token::check_permission()` に `SCOPE_READ` を渡す
 * (`POST /run` の `SCOPE_RUN` とは別トークン。`WPCV_Rest_Token` のクラス
 * docblock参照)。`Cache-Control: no-store` は `WPCV_Rest_Support` 経由で
 * 付与する.
 */
class WPCV_Rest_Status_Controller {

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
	const ROUTE = '/status';

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
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle_status' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * パーミッションコールバック(`SCOPE_READ`).
	 *
	 * @param WP_REST_Request $request リクエスト.
	 * @return true|WP_Error
	 */
	public static function check_permission( $request ) {
		return WPCV_Rest_Token::check_permission( $request, WPCV_Rest_Token::SCOPE_READ );
	}

	/**
	 * `GET /status` のハンドラ.
	 *
	 * @param WP_REST_Request $request リクエスト(未使用. パラメータを持たないため).
	 * @return WP_REST_Response
	 */
	public static function handle_status( $request ) {
		unset( $request );

		$run_repository        = WPCV_Plugin::run_repository();
		$target_run_repository = WPCV_Plugin::target_run_repository();

		$current_run_id = $run_repository->find_active_run_id();
		$current_run    = null !== $current_run_id ? $run_repository->find_by_id( $current_run_id ) : null;
		$last_run       = $run_repository->find_most_recent_terminal_run();

		$run_time = WPCV_Settings::get_run_time();

		return WPCV_Rest_Support::response(
			array(
				'current_run'       => self::describe_run( $current_run, $target_run_repository ),
				'last_run'          => self::describe_run( $last_run, $target_run_repository ),
				'next_scheduled_at' => gmdate( 'c', WPCV_Scheduler::next_timestamp_after( time(), $run_time['hour'], $run_time['minute'] ) ),
			)
		);
	}

	/**
	 * 1件のrunを、target集計・最終活動時刻込みの詳細形へ変換する.
	 *
	 * @param array|null                 $run                    `WPCV_Run_Repository::find_by_id()`/
	 *                                                            `find_most_recent_terminal_run()` の戻り値.
	 * @param WPCV_Target_Run_Repository $target_run_repository `wpcv_target_runs` の永続化層.
	 * @return array|null `$run` が `null` ならそのまま `null`.
	 */
	private static function describe_run( $run, WPCV_Target_Run_Repository $target_run_repository ) {
		if ( null === $run ) {
			return null;
		}

		$target_runs = $target_run_repository->find_all_by_run( (int) $run['id'] );

		return array(
			'run_id'           => (int) $run['id'],
			'status'           => (string) $run['status'],
			'run_trigger'      => isset( $run['run_trigger'] ) ? (string) $run['run_trigger'] : null,
			'started_at'       => self::nullable_string( $run, 'started_at' ),
			'finished_at'      => self::nullable_string( $run, 'finished_at' ),
			'scheduled_for'    => self::nullable_string( $run, 'scheduled_for' ),
			'deadline_at'      => self::nullable_string( $run, 'deadline_at' ),
			'last_activity_at' => self::last_activity_at( $target_runs ),
			'findings_total'   => (int) ( $run['findings_total'] ?? 0 ),
			'targets'          => self::tally_target_statuses( $target_runs ),
		);
	}

	/**
	 * Target_run群のステータス別件数を集計する(`WPCV_Target_Status` の全状態を
	 * 網羅. 0件の状態も明示的に0を返す).
	 *
	 * @param array $target_runs `WPCV_Target_Run_Repository::find_all_by_run()` の戻り値.
	 * @return array<string,int>
	 */
	private static function tally_target_statuses( array $target_runs ) {
		$counts          = array_fill_keys(
			array(
				WPCV_Target_Status::QUEUED,
				WPCV_Target_Status::RETRY,
				WPCV_Target_Status::RUNNING,
				WPCV_Target_Status::SUCCESS,
				WPCV_Target_Status::UNVERIFIABLE,
				WPCV_Target_Status::FAILED,
				WPCV_Target_Status::SKIPPED,
				WPCV_Target_Status::ABORTED,
			),
			0
		);
		$counts['total'] = count( $target_runs );

		foreach ( $target_runs as $target_run ) {
			if ( isset( $counts[ $target_run['status'] ] ) ) {
				++$counts[ $target_run['status'] ];
			}
		}

		return $counts;
	}

	/**
	 * 対象runの target_runs のうち、最も新しい `heartbeat_at`/`finished_at` を返す
	 * (クラス docblock 参照。値は `Y-m-d H:i:s` のUTC文字列を辞書式比較する。
	 * 既存コードベース全体〔`WPCV_Run_Repository::sweep_stale_running()` 等〕と
	 * 同じ手法).
	 *
	 * @param array $target_runs `WPCV_Target_Run_Repository::find_all_by_run()` の戻り値.
	 * @return string|null 一度もclaim・finalizeされたtarget_runが無ければ `null`.
	 */
	private static function last_activity_at( array $target_runs ) {
		$latest = null;

		foreach ( $target_runs as $target_run ) {
			foreach ( array( 'heartbeat_at', 'finished_at' ) as $field ) {
				if ( empty( $target_run[ $field ] ) ) {
					continue;
				}

				if ( null === $latest || $target_run[ $field ] > $latest ) {
					$latest = $target_run[ $field ];
				}
			}
		}

		return $latest;
	}

	/**
	 * Run行から文字列カラムを読み取る(未設定・空文字は `null` として扱う).
	 *
	 * @param array  $run   Run行.
	 * @param string $field カラム名.
	 * @return string|null
	 */
	private static function nullable_string( array $run, $field ) {
		return empty( $run[ $field ] ) ? null : (string) $run[ $field ];
	}
}
