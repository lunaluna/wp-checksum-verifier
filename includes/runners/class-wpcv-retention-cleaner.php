<?php
/**
 * WPCV_Retention_Cleaner クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run の終端で、保持期間を過ぎた履歴(runs・target_runs・findings・suppressions)を消す
 * (v0.9プラン §3.1・§3.1.1. 設定 `retention_months` が 0 〔無期限. 既定〕のときは何もしない).
 *
 * **何を残すか**(プラン §3.1.1 の I1〜I5. 実 DB の測定で、「未終端の finding」は「今の検出」を
 * 意味しないと分かったため、差分検出が実際に読む行を基準にしている):
 *
 * - I1: 各 target の直近の `status = success` の target_run と、そこに載る findings
 *   (差分検出の基準. 消すと次の run が `first` になり、全 finding が `new` で再通知される).
 * - I2: I1 の target_run が属する runs の行(更新イベントの突き合わせが `started_at` を引く).
 * - I3: 実行中・差分処理が終わっていない run と、その run が基準にする target_run
 *   (その run より前の、各 target の直近の success).`wpcv_run_terminated` は差分処理の
 *   *前* に発火するので、今終わった run 自身も「差分処理前」として扱う.
 * - I4: `notified_at` を持つ finding のうち、同じ `finding_key` が I1 の世代にまだある行
 *   (再送抑制が `finding_key` 単位で全期間の `MAX(notified_at)` を見るため. 消すと、続いている
 *   検出が期間の経過後に1回だけ再通知される).
 * - I5: 有効な suppression(失効してから期限を過ぎたものだけ消す).
 *
 * 期限の判定: 期限切れの run は `started_at` が「今 - N か月」より前のもの. 日時の列を持たない
 * target_runs にも同じ境界を使うため、境界を「その run の id 以下」に置き換える
 * (`WPCV_Run_Repository::find_last_started_before()`).
 *
 * 削除の順序は findings → target_runs → runs(参照される側を最後に).途中で止まっても、
 * 親だけが残る状態になり、読み手は壊れない.
 *
 * 1回の呼び出しで消す target_run の数には上限を置く(`MAX_TARGET_RUNS_PER_CALL`)。上限に達したら
 * 続きは次の run の終端で行う.
 *
 * `WPCV_Manifest_Cache_Cleaner` と同じく、掃除の失敗で他のリスナーや run の確定を妨げないよう
 * try/catch で包む.消せなかった行は次の run の終端でまた消そうとするだけで、照合の結果には
 * 影響しない.
 */
class WPCV_Retention_Cleaner {

	/**
	 * 1回の呼び出しで消す(または findings だけを減らす)target_run の最大数.
	 *
	 * 1回の run 終端で長時間 DB を占有しないための上限で、既存データが多いサイトで保持期間を
	 * 有限にした直後は、何回かの run に分けて消える.
	 *
	 * 実測(2026-10-03. MySQL 8.4・ローカル・スクラッチ DB. 1年分 = 365 run・26,280 target_run・
	 * 36,500 findings〔実データの約10倍の密度〕を作り、保持3か月で繰り返し実行): 削除ありの1回が
	 * 190〜404 ms(中央値 256 ms. 約 1,700 クエリ. target_run 約 500・findings 約 700・run 約 5 を削除).
	 * 溜まった 19,800 件は 40 回で消え切った(合計 10.4 秒). 消すものが無い定常状態は 7 ms(53 クエリ).
	 * **共有ホスティング・低速なストレージでは未測定**(上の値は同じ上限を別環境で測り直す根拠にする).
	 *
	 * @var int
	 */
	const MAX_TARGET_RUNS_PER_CALL = 500;

	/**
	 * Target_runs・runs の候補を1回のクエリで読む件数.
	 *
	 * 未実測: 暫定値.走査の1クエリを短く保つことが目的で、厳密な値は求めない.
	 *
	 * @var int
	 */
	const SCAN_BATCH_SIZE = 500;

	/**
	 * `wpcv_runs` の永続化層.
	 *
	 * @var WPCV_Run_Repository
	 */
	private $run_repository;

	/**
	 * `wpcv_target_runs` の永続化層.
	 *
	 * @var WPCV_Target_Run_Repository
	 */
	private $target_run_repository;

	/**
	 * `wpcv_findings` の永続化層.
	 *
	 * @var WPCV_Finding_Repository
	 */
	private $finding_repository;

	/**
	 * `wpcv_suppressions` の永続化層.
	 *
	 * @var WPCV_Suppression_Repository
	 */
	private $suppression_repository;

	/**
	 * 現在時刻(UTC の MySQL DATETIME 文字列)を返す callable(テストで固定時刻を注入する).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Run_Repository         $run_repository         `wpcv_runs` の永続化層.
	 * @param WPCV_Target_Run_Repository  $target_run_repository  `wpcv_target_runs` の永続化層.
	 * @param WPCV_Finding_Repository     $finding_repository     `wpcv_findings` の永続化層.
	 * @param WPCV_Suppression_Repository $suppression_repository `wpcv_suppressions` の永続化層.
	 * @param callable|null               $now                    現在時刻を返す. 省略時は `gmdate( 'Y-m-d H:i:s' )`.
	 */
	public function __construct(
		WPCV_Run_Repository $run_repository,
		WPCV_Target_Run_Repository $target_run_repository,
		WPCV_Finding_Repository $finding_repository,
		WPCV_Suppression_Repository $suppression_repository,
		?callable $now = null
	) {
		$this->run_repository         = $run_repository;
		$this->target_run_repository  = $target_run_repository;
		$this->finding_repository     = $finding_repository;
		$this->suppression_repository = $suppression_repository;
		$this->now                    = $now ?? static function () {
			return gmdate( 'Y-m-d H:i:s' );
		};
	}

	/**
	 * `wpcv_run_terminated` フックのハンドラ本体(`WPCV_Plugin::handle_retention_run_terminated()`
	 * から呼ばれる).
	 *
	 * Run の終了状態は問わない(failed / aborted でも掃除してよい.履歴を消すだけで、
	 * その run の列挙の完全性には依存しない).
	 *
	 * @param int    $run_id 終端に達した run の id.
	 * @param string $status 遷移後の `wpcv_runs.status`(このハンドラでは使わない).
	 * @return void
	 */
	public function handle_run_terminated( $run_id, $status ) {
		unset( $status );

		$months = WPCV_Settings::get_retention_months();

		if ( $months < 1 ) {
			return; // 無期限(既定): 何も消さない.
		}

		try {
			$this->clean( (int) $run_id, $months );
		} catch ( Throwable $e ) {
			// クラス docblock 参照: 掃除の失敗で run の確定を妨げない.
			unset( $e );
		}
	}

	/**
	 * 保持期間を過ぎた履歴を消す.
	 *
	 * @param int $terminated_run_id 今終わった run の id(差分処理の前なので、消してはいけない側に入れる).
	 * @param int $months            保持期間(月. 1 以上).
	 * @return void
	 */
	private function clean( $terminated_run_id, $months ) {
		$cutoff = $this->cutoff( $months );

		// 期限切れの suppression は、run の有無に関わらず消す.
		$this->suppression_repository->delete_expired_before( $cutoff );

		$boundary_run_id = $this->run_repository->find_last_started_before( $cutoff );

		if ( null === $boundary_run_id ) {
			return; // 期限切れの run が無い.
		}

		// I3: 実行中・差分処理前の run.
		$unfinished = array_flip( $this->run_repository->find_unfinished_run_ids() );

		$unfinished[ (int) $terminated_run_id ] = true;

		// I1: 各 target の直近の success(I4 の判定にも使うので、1回だけ読む).
		$latest_success = $this->target_run_repository->find_latest_success_map();

		// I1 と、I3: 進行中の run が基準にする、その run より前の直近の success.
		$protected_target_runs = $this->protected_target_run_map( $latest_success, array_keys( $unfinished ) );

		$this->clean_target_runs( $boundary_run_id, $latest_success, $protected_target_runs, $unfinished );
		$this->clean_runs( $cutoff, $protected_target_runs, $unfinished );
	}

	/**
	 * 保持期間の境界(UTC の MySQL DATETIME 文字列)を返す.
	 *
	 * @param int $months 保持期間(月).
	 * @return string
	 */
	private function cutoff( $months ) {
		$now = new DateTimeImmutable( (string) call_user_func( $this->now ), new DateTimeZone( 'UTC' ) );

		return $now->modify( '-' . (int) $months . ' months' )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * 消してはいけない target_run(I1 と、進行中の run の基準)の map を返す.
	 *
	 * @param array<string, array{id: int, run_id: int}> $latest_success      各 target の直近の success(I1).
	 * @param int[]                                      $unfinished_run_ids 実行中・差分処理前の run の id.
	 * @return array<int, array{id: int, run_id: int, target_id: string}> target_run の id => 行.
	 */
	private function protected_target_run_map( array $latest_success, array $unfinished_run_ids ) {
		$maps = array( $latest_success );

		if ( ! empty( $unfinished_run_ids ) ) {
			// 進行中の run の差分検出は「その run より前の直近の success」を基準にする
			// (`find_baseline_target_run()` の `run_id < 今回`). 一番古い進行中の run を境にとれば、
			// どの進行中の run の基準も含まれる.
			$maps[] = $this->target_run_repository->find_latest_success_map( min( $unfinished_run_ids ) );
		}

		$protected = array();

		foreach ( $maps as $map ) {
			foreach ( $map as $target_id => $row ) {
				$protected[ $row['id'] ] = array(
					'id'        => $row['id'],
					'run_id'    => $row['run_id'],
					'target_id' => (string) $target_id,
				);
			}
		}

		return $protected;
	}

	/**
	 * 期限切れの target_run を、載っている findings とともに消す.
	 *
	 * @param int                                                        $boundary_run_id       この run_id 以下が期限切れ.
	 * @param array<string, array{id: int, run_id: int}>                 $latest_success        各 target の直近の success.
	 * @param array<int, array{id: int, run_id: int, target_id: string}> $protected_target_runs 消さない target_run(id => 行).
	 * @param array<int, bool>                                           $unfinished            実行中・差分処理前の run の id => true.
	 * @return void
	 */
	private function clean_target_runs( $boundary_run_id, array $latest_success, array $protected_target_runs, array $unfinished ) {
		// I4 の判定に使う「target ごとの直近の success の id」.
		$latest_id_by_target = array();

		foreach ( $latest_success as $target_id => $row ) {
			$latest_id_by_target[ (string) $target_id ] = $row['id'];
		}

		$handled  = 0;
		$after_id = 0;

		while ( $handled < self::MAX_TARGET_RUNS_PER_CALL ) {
			$batch = $this->target_run_repository->find_ids_up_to_run( $boundary_run_id, $after_id, self::SCAN_BATCH_SIZE );

			if ( empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $target_run ) {
				$after_id = $target_run['id'];

				if ( isset( $protected_target_runs[ $target_run['id'] ] ) || isset( $unfinished[ $target_run['run_id'] ] ) ) {
					continue; // I1・I3.
				}

				$keep_finding_ids = $this->notified_finding_ids_to_keep( $target_run, $latest_id_by_target );

				$this->finding_repository->delete_by_target_run_id_except( $target_run['id'], $keep_finding_ids );

				// 通知の記録として残す finding がある間は、その target_run も残す(finding が親を指すため).
				if ( empty( $keep_finding_ids ) ) {
					$this->target_run_repository->delete_by_id( $target_run['id'] );
				}

				++$handled;

				if ( $handled >= self::MAX_TARGET_RUNS_PER_CALL ) {
					return;
				}
			}

			if ( count( $batch ) < self::SCAN_BATCH_SIZE ) {
				break;
			}
		}
	}

	/**
	 * 消す target_run のうち、通知の記録として残す finding の id を返す(I4).
	 *
	 * `notified_at` があり、同じ `finding_key` が、その target の直近の success の target_run に
	 * まだ存在する行だけ残す.その target に success の target_run が無ければ(`latest_id_by_target`
	 * に無い)、続いている検出が無いので残さない.
	 *
	 * @param array{id: int, run_id: int, target_id: string} $target_run          消す候補の target_run.
	 * @param array<string, int>                             $latest_id_by_target target_id => 直近の success の target_run の id.
	 * @return int[]
	 */
	private function notified_finding_ids_to_keep( array $target_run, array $latest_id_by_target ) {
		if ( ! isset( $latest_id_by_target[ $target_run['target_id'] ] ) ) {
			return array();
		}

		$notified = $this->finding_repository->find_notified_rows_for_target_run( $target_run['id'] );

		if ( empty( $notified ) ) {
			return array();
		}

		$live_keys = array_flip(
			$this->finding_repository->find_matching_keys(
				$latest_id_by_target[ $target_run['target_id'] ],
				array_values( array_unique( array_column( $notified, 'finding_key' ) ) ),
				false
			)
		);

		$keep = array();

		foreach ( $notified as $row ) {
			if ( isset( $live_keys[ $row['finding_key'] ] ) ) {
				$keep[] = $row['id'];
			}
		}

		return $keep;
	}

	/**
	 * 期限切れで、target_run が1件も残っていない run を消す(I2・I3 は残す).
	 *
	 * @param string                                                     $cutoff                期限の境界(UTC の MySQL DATETIME 文字列).
	 * @param array<int, array{id: int, run_id: int, target_id: string}> $protected_target_runs 消さない target_run.
	 * @param array<int, bool>                                           $unfinished            実行中・差分処理前の run の id => true.
	 * @return void
	 */
	private function clean_runs( $cutoff, array $protected_target_runs, array $unfinished ) {
		// I2: 基準の target_run が属する run. 件数を数えるクエリを省くための近道で、
		// 正しさは「target_run が残っていれば消さない」の判定(下の count)が担う.
		$protected_run_ids = array();

		foreach ( $protected_target_runs as $row ) {
			$protected_run_ids[ $row['run_id'] ] = true;
		}

		$deleted  = 0;
		$after_id = 0;

		while ( $deleted < self::MAX_TARGET_RUNS_PER_CALL ) {
			$ids = $this->run_repository->find_ids_started_before( $cutoff, $after_id, self::SCAN_BATCH_SIZE );

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $run_id ) {
				$after_id = $run_id;

				if ( isset( $unfinished[ $run_id ] ) || isset( $protected_run_ids[ $run_id ] ) ) {
					continue;
				}

				if ( $this->target_run_repository->count_by_run( $run_id ) > 0 ) {
					continue; // 通知の記録として残した target_run がある.
				}

				$this->run_repository->delete_by_id( $run_id );
				++$deleted;

				if ( $deleted >= self::MAX_TARGET_RUNS_PER_CALL ) {
					return;
				}
			}

			if ( count( $ids ) < self::SCAN_BATCH_SIZE ) {
				break;
			}
		}
	}
}

add_action( 'wpcv_run_terminated', array( 'WPCV_Plugin', 'handle_retention_run_terminated' ), 10, 2 );
