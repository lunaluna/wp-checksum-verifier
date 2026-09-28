<?php
/**
 * WPCV_Run_Failure_Alerter クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run の連続失敗アラート(v0.5後半 §Step15a設計)を1回分処理する.
 *
 * `wpcv_run_terminated`フック(`WPCV_Run_Repository::update_active_run()`/
 * `finish_run()`が、実際に状態遷移できた場合のみ発火する.クラスdocblock参照)を
 * 購読し、runがfailed/abortedへ遷移した直後に呼ばれる.
 *
 * 差分アラート(`WPCV_Alert_Sender::send_for_run()`)が`alerting`段階
 * (差分処理の完了後)で評価されるのに対し、このクラスはrun自体が失敗した
 * 時点で即座に評価する(§3.4「run の失敗時」).差分処理を経由しないため、
 * failed/abortedになったrunには差分処理そのものが走らない
 * (`diff_status = skipped`.`mark_run_failed()`/`mark_run_aborted()`参照).
 *
 * `handle()`全体を`try/catch ( Throwable )`で囲む(§3.4・§6「失敗アラートの
 * 送信」): このメソッドは`wpcv_run_terminated`のフックハンドラとして、
 * `WPCV_Run_Repository::update_active_run()`/`finish_run()`の呼び出しスタックの
 * 中で同期的に実行される.ここで例外を外に漏らすと、`mark_run_failed()`等の
 * 呼び出し元(dispatcherやRunnerのcatch節)まで巻き込んで run の終端処理自体を
 * 壊しかねない.
 */
class WPCV_Run_Failure_Alerter {

	/**
	 * `wpcv_runs`の永続化層.
	 *
	 * @var WPCV_Run_Repository
	 */
	private $run_repository;

	/**
	 * アラートの送信(`send_run_failure()`)を担う.
	 *
	 * @var WPCV_Alert_Sender
	 */
	private $alert_sender;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Run_Repository $run_repository `wpcv_runs`の永続化層.
	 * @param WPCV_Alert_Sender   $alert_sender   アラートの送信を担うクラス.
	 */
	public function __construct( WPCV_Run_Repository $run_repository, WPCV_Alert_Sender $alert_sender ) {
		$this->run_repository = $run_repository;
		$this->alert_sender   = $alert_sender;
	}

	/**
	 * `wpcv_run_terminated`フックのハンドラ本体(`WPCV_Plugin::handle_run_terminated()`
	 * から呼ばれる).
	 *
	 * @param int    $run_id 終端に達したrunのid.
	 * @param string $status 遷移後の`wpcv_runs.status`.
	 * @return void
	 */
	public function handle( $run_id, $status ) {
		if ( ! in_array( $status, array( WPCV_Run_Status::FAILED, WPCV_Run_Status::ABORTED ), true ) ) {
			// success/partialでは何もしない(アラートは差分処理のalerting段階が送る.§2.3).
			return;
		}

		try {
			$run_id    = (int) $run_id;
			$threshold = max( 1, (int) apply_filters( 'wpcv_alert_run_failure_streak', 3 ) );
			$streak    = $this->run_repository->find_failure_streak( $run_id, $threshold );

			if ( $streak['length'] < $threshold || $streak['notified'] ) {
				return;
			}

			$this->alert_sender->send_run_failure( $run_id, $streak['runs'] );
		} catch ( Throwable $e ) {
			// クラスdocblock参照: 送信の失敗でrunの終端処理を壊さない.
			// 通知済みにはならないため、次の失敗runでもう一度評価される.
			unset( $e );
		}
	}
}

add_action( 'wpcv_run_terminated', array( 'WPCV_Plugin', 'handle_run_terminated' ), 10, 2 );
