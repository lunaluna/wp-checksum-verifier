<?php
/**
 * WPCV_Prune_Job クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理画面の「古い履歴を今すぐ削除」(v0.10.0 P3. プラン §8.4)の実体.
 *
 * 大きなサイトでは削除に時間がかかるため、リクエストの中で最後までやらない. Action Scheduler の
 * アクション(`wpcv_prune_history`)を1つ予約し、1回のアクションで `WPCV_Retention_Cleaner::prune()`
 * の上限(500)分を消して、`remaining` が真なら次のアクションを予約し直す. 削除の判定は
 * `wp wpcv prune` と同じ `prune()` で、ここには持たない.
 *
 * グループは `wpcv`(`WPCV_Chunk_Dispatcher::GROUP` と同じ)にしてある. プラグインの無効化
 * (`WPCV_Chunk_Dispatcher::deactivate()` がグループごと取り消す)でこのアクションも消え、
 * アンインストール(`uninstall.php`)は hook が `wpcv_` で始まる行を消すので、後片付けは既存の
 * 仕組みに含まれる.
 *
 * 結果(進行中か・消した件数の合計・日時)は option `wpcv_prune_status` に保存する. 日時は UTC で保存し、
 * 表示のときだけサイトのタイムゾーンにする(`WPCV_Settings::format_datetime()`).
 * マルチサイトでは設定と同じくネットワークで1つ(site option).
 */
class WPCV_Prune_Job {

	/**
	 * Action Scheduler のフック名.
	 *
	 * @var string
	 */
	const HOOK = 'wpcv_prune_history';

	/**
	 * Action Scheduler のグループ(`WPCV_Chunk_Dispatcher::GROUP` と同じ).
	 *
	 * @var string
	 */
	const GROUP = 'wpcv';

	/**
	 * 結果を保存する option 名.
	 *
	 * @var string
	 */
	const STATUS_OPTION = 'wpcv_prune_status';

	/**
	 * `request()` の戻り値の `result`: 削除アクションを予約した.
	 *
	 * @var string
	 */
	const RESULT_SCHEDULED = 'scheduled';

	/**
	 * `request()` の戻り値の `result`: すでに予約済み・実行中なので、新しく予約しなかった.
	 *
	 * @var string
	 */
	const RESULT_ALREADY_RUNNING = 'already_running';

	/**
	 * `request()` の戻り値の `result`: 保持期間が無期限のため何もしなかった.
	 *
	 * @var string
	 */
	const RESULT_UNLIMITED = 'unlimited';

	/**
	 * `request()` の戻り値の `result`: Action Scheduler が使えないので、この場で1回分(上限 500)だけ消した.
	 *
	 * @var string
	 */
	const RESULT_INLINE = 'inline';

	/**
	 * 保存する状態: 実行中(予約済みを含む).
	 *
	 * @var string
	 */
	const STATE_RUNNING = 'running';

	/**
	 * 保存する状態: 最後まで終わった.
	 *
	 * @var string
	 */
	const STATE_DONE = 'done';

	/**
	 * 保存する状態: 1回分だけ消して止まった(Action Scheduler が使えず、続きは run の終わりの自動削除に任せる).
	 *
	 * @var string
	 */
	const STATE_PARTIAL = 'partial';

	/**
	 * 保存する状態: 保持期間が無期限に変わったため、途中で止めた.
	 *
	 * @var string
	 */
	const STATE_CANCELLED = 'cancelled';

	/**
	 * 保存する状態: 削除に失敗した.
	 *
	 * @var string
	 */
	const STATE_FAILED = 'failed';

	/**
	 * Action Scheduler が使えるか.
	 *
	 * `WPCV_Chunk_Dispatcher::schedule_via_action_scheduler()` と同じ条件で毎回確認する
	 * (テストでのスタブ漏れ対策も同じ).
	 *
	 * @return bool
	 */
	public static function action_scheduler_available() {
		return function_exists( 'as_enqueue_async_action' ) && class_exists( 'ActionScheduler' ) && ActionScheduler::is_initialized();
	}

	/**
	 * 削除アクションが予約済み、または実行中かを返す.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return self::action_scheduler_available()
			&& function_exists( 'as_has_scheduled_action' )
			&& (bool) as_has_scheduled_action( self::HOOK, null, self::GROUP );
	}

	/**
	 * 「今すぐ削除」を受け付ける(管理画面のボタンから呼ぶ. nonce・権限の確認は呼び出し元).
	 *
	 * 組み合わせ(プラン §8.5):
	 *
	 * - 保持期間が無期限 → 何もしない(`RESULT_UNLIMITED`).
	 * - 予約済み・実行中のアクションがある → 新しく予約しない(`RESULT_ALREADY_RUNNING`. 二重に押された場合).
	 * - Action Scheduler が使える → 状態を `running` にして予約する(`RESULT_SCHEDULED`).
	 * - Action Scheduler が使えない → この場で1回分(上限 500)だけ消し、続きは run の終わりの
	 *   自動削除に任せる(`RESULT_INLINE`).
	 *
	 * @return array{result: string, error: string|null}
	 */
	public static function request() {
		$months = WPCV_Settings::get_retention_months();

		if ( $months < 1 ) {
			return array(
				'result' => self::RESULT_UNLIMITED,
				'error'  => null,
			);
		}

		if ( self::is_active() ) {
			return array(
				'result' => self::RESULT_ALREADY_RUNNING,
				'error'  => null,
			);
		}

		if ( ! self::action_scheduler_available() ) {
			return self::run_inline( $months );
		}

		self::write_status( self::new_status( self::STATE_RUNNING, $months ) );

		$action_id = as_enqueue_async_action( self::HOOK, array(), self::GROUP );

		if ( (int) $action_id <= 0 ) {
			// 予約に失敗した(AS の一時的な DB 書き込み失敗など). 状態を失敗にして、画面に出せるようにする.
			self::write_status( array_merge( self::new_status( self::STATE_FAILED, $months ), array( 'finished_at' => gmdate( 'Y-m-d H:i:s' ) ) ) );

			return array(
				'result' => self::RESULT_SCHEDULED,
				'error'  => 'enqueue_failed',
			);
		}

		return array(
			'result' => self::RESULT_SCHEDULED,
			'error'  => null,
		);
	}

	/**
	 * Action Scheduler のアクション本体. 1回で上限分を消し、残っていれば次のアクションを予約する.
	 *
	 * 保持期間は毎回読み直す(途中で「無期限」に変えられたら何もせず止め〔§8.5 #7〕、短くされたら次の
	 * アクションから新しい期間で消す〔#8〕). 失敗したときは状態を `failed` にして例外を投げ直し、
	 * Action Scheduler にも失敗として記録させる.
	 *
	 * @return void
	 *
	 * @throws Throwable 削除に失敗した場合(状態を記録したうえで投げ直す).
	 */
	public static function run_action() {
		$months = WPCV_Settings::get_retention_months();
		$status = self::read_status();

		if ( $months < 1 ) {
			$status['state']       = self::STATE_CANCELLED;
			$status['finished_at'] = gmdate( 'Y-m-d H:i:s' );
			self::write_status( $status );
			return;
		}

		$status['months'] = $months;

		try {
			$result = WPCV_Plugin::retention_cleaner()->prune( $months );
		} catch ( Throwable $e ) {
			$status['state']       = self::STATE_FAILED;
			$status['finished_at'] = gmdate( 'Y-m-d H:i:s' );
			self::write_status( $status );
			throw $e;
		}

		$status['totals'] = self::add_counts( $status['totals'], $result );

		if ( $result['remaining'] ) {
			$status['state'] = self::STATE_RUNNING;
			self::write_status( $status );

			// 次のアクションを予約する. 失敗したら、止まったまま「実行中」に見えないよう失敗にする.
			if ( (int) as_enqueue_async_action( self::HOOK, array(), self::GROUP ) <= 0 ) {
				$status['state']       = self::STATE_FAILED;
				$status['finished_at'] = gmdate( 'Y-m-d H:i:s' );
				self::write_status( $status );
			}
			return;
		}

		$status['state']       = self::STATE_DONE;
		$status['finished_at'] = gmdate( 'Y-m-d H:i:s' );
		self::write_status( $status );
	}

	/**
	 * 保存してある結果を返す(まだ一度も削除していなければ `null`).
	 *
	 * 状態が `running` でもアクションが無い(無効化・Action Scheduler のテーブル掃除などで消えた)ときは、
	 * 「実行中」と表示し続けないよう `interrupted` として返す.
	 *
	 * @return array{state: string, months: int, started_at: string, finished_at: string|null, totals: array{runs:int,target_runs:int,findings:int,suppressions:int}}|null
	 */
	public static function get_status() {
		$stored = is_multisite() ? get_site_option( self::STATUS_OPTION, null ) : get_option( self::STATUS_OPTION, null );

		if ( ! is_array( $stored ) || empty( $stored['state'] ) ) {
			return null;
		}

		$status = self::read_status();

		if ( self::STATE_RUNNING === $status['state'] && ! self::is_active() ) {
			$status['state'] = 'interrupted';
		}

		return $status;
	}

	/**
	 * Action Scheduler が使えないとき、この場で1回分(上限 500)だけ消す.
	 *
	 * @param int $months 保持期間(月).
	 * @return array{result: string, error: string|null}
	 */
	private static function run_inline( $months ) {
		$status = self::new_status( self::STATE_RUNNING, $months );

		try {
			$result = WPCV_Plugin::retention_cleaner()->prune( $months );
		} catch ( Throwable $e ) {
			unset( $e );
			$status['state']       = self::STATE_FAILED;
			$status['finished_at'] = gmdate( 'Y-m-d H:i:s' );
			self::write_status( $status );

			return array(
				'result' => self::RESULT_INLINE,
				'error'  => 'prune_failed',
			);
		}

		$status['totals']      = self::add_counts( $status['totals'], $result );
		$status['state']       = $result['remaining'] ? self::STATE_PARTIAL : self::STATE_DONE;
		$status['finished_at'] = gmdate( 'Y-m-d H:i:s' );
		self::write_status( $status );

		return array(
			'result' => self::RESULT_INLINE,
			'error'  => null,
		);
	}

	/**
	 * 新しい状態の配列を作る.
	 *
	 * @param string $state  `STATE_*`.
	 * @param int    $months 保持期間(月).
	 * @return array{state: string, months: int, started_at: string, finished_at: string|null, totals: array{runs:int,target_runs:int,findings:int,suppressions:int}}
	 */
	private static function new_status( $state, $months ) {
		return array(
			'state'       => $state,
			'months'      => (int) $months,
			'started_at'  => gmdate( 'Y-m-d H:i:s' ),
			'finished_at' => null,
			'totals'      => array(
				'runs'         => 0,
				'target_runs'  => 0,
				'findings'     => 0,
				'suppressions' => 0,
			),
		);
	}

	/**
	 * 件数の合計に、今回の結果を足す.
	 *
	 * @param array $totals 今までの合計.
	 * @param array $result `prune()` の戻り値.
	 * @return array{runs:int,target_runs:int,findings:int,suppressions:int}
	 */
	private static function add_counts( array $totals, array $result ) {
		$sum = array();

		foreach ( array( 'runs', 'target_runs', 'findings', 'suppressions' ) as $key ) {
			$sum[ $key ] = (int) ( $totals[ $key ] ?? 0 ) + (int) ( $result[ $key ] ?? 0 );
		}

		return $sum;
	}

	/**
	 * 保存してある状態を、欠けた項目を補って返す(未保存なら空の `running` ではなく新規の `done` 相当の枠).
	 *
	 * @return array{state: string, months: int, started_at: string, finished_at: string|null, totals: array{runs:int,target_runs:int,findings:int,suppressions:int}}
	 */
	private static function read_status() {
		$stored = is_multisite() ? get_site_option( self::STATUS_OPTION, array() ) : get_option( self::STATUS_OPTION, array() );
		$base   = self::new_status( self::STATE_DONE, WPCV_Settings::get_retention_months() );

		if ( ! is_array( $stored ) ) {
			return $base;
		}

		$status           = array_merge( $base, $stored );
		$status['totals'] = self::add_counts( array(), (array) ( $stored['totals'] ?? array() ) );

		return $status;
	}

	/**
	 * 状態を保存する(autoload しない. 画面を開いたときだけ読む).
	 *
	 * @param array $status 保存する状態.
	 * @return void
	 */
	private static function write_status( array $status ) {
		if ( is_multisite() ) {
			update_site_option( self::STATUS_OPTION, $status );
			return;
		}

		update_option( self::STATUS_OPTION, $status, false );
	}
}

add_action( WPCV_Prune_Job::HOOK, array( 'WPCV_Prune_Job', 'run_action' ) );
