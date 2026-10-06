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
 * - I4: `notified_at` を持つ finding のうち、同じ `finding_key` が I1 の世代にまだあり、かつ
 *   他の run に同じか新しい通知が無い行(= そのキーの最新の通知1行).再送抑制が `finding_key`
 *   単位で全期間の `MAX(notified_at)` を見るため、これを消すと、続いている検出が期間の経過後に
 *   1回だけ再通知される.最新より古い通知は判定に効かないので残さない(v0.9.1).
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
 * 続きは次の run の終端で行う.上限に数えるのは、target_run を消したときと、finding を1件以上
 * 消したときだけ(何も消さずに残した target_run は数えない. 数えると、残す行が上限の件数以上
 * 先頭に並んだとき、毎回同じ先頭で止まり、後ろの期限切れに届かなくなる).
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
	 * 削除を単一所有にする advisory lock(v0.10.0. コードレビュー指摘2). `null` なら lock を取らない
	 * (テスト用. 本番の `WPCV_Plugin::retention_cleaner()` は必ず渡す).
	 *
	 * @var WPCV_Advisory_Lock|null
	 */
	private $lock;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Run_Repository         $run_repository         `wpcv_runs` の永続化層.
	 * @param WPCV_Target_Run_Repository  $target_run_repository  `wpcv_target_runs` の永続化層.
	 * @param WPCV_Finding_Repository     $finding_repository     `wpcv_findings` の永続化層.
	 * @param WPCV_Suppression_Repository $suppression_repository `wpcv_suppressions` の永続化層.
	 * @param callable|null               $now                    現在時刻を返す. 省略時は `gmdate( 'Y-m-d H:i:s' )`.
	 * @param WPCV_Advisory_Lock|null     $lock                   削除を単一所有にする lock. 省略時は lock を取らない.
	 */
	public function __construct(
		WPCV_Run_Repository $run_repository,
		WPCV_Target_Run_Repository $target_run_repository,
		WPCV_Finding_Repository $finding_repository,
		WPCV_Suppression_Repository $suppression_repository,
		?callable $now = null,
		?WPCV_Advisory_Lock $lock = null
	) {
		$this->run_repository         = $run_repository;
		$this->target_run_repository  = $target_run_repository;
		$this->finding_repository     = $finding_repository;
		$this->suppression_repository = $suppression_repository;
		$this->now                    = $now ?? static function () {
			return gmdate( 'Y-m-d H:i:s' );
		};
		$this->lock                   = $lock;
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
			// 別の削除(`wp wpcv prune`・管理画面のジョブ)が実行中で lock が取れなければ、何もせずに終わる
			// (戻り値の `locked`). 消えなかった分は、次の run の終わりか、実行中の削除が消す.
			$this->prune( $months, (int) $run_id );
		} catch ( Throwable $e ) {
			// クラス docblock 参照: 掃除の失敗で run の確定を妨げない.
			unset( $e );
		}
	}

	/**
	 * 保持期間を過ぎた履歴を消す(または `$dry_run` で消す件数だけ数える).
	 *
	 * Run の終端(`handle_run_terminated()`)に加えて、`wp wpcv prune` と管理画面の「古い履歴を今すぐ
	 * 削除」(v0.10.0)からも呼ぶ. 削除の判定を呼び出し元ごとに持たないよう、この1か所に集約する.
	 * 保持期間は呼び出し元が `WPCV_Settings::get_retention_months()` で読んで渡す(無期限 = 0 の
	 * 扱いは呼び出し元の責務. ここでは 1 未満なら何もしない).
	 *
	 * 通常(`$dry_run` が偽)は、1回の呼び出しで消す target_run の数に上限がある
	 * (`MAX_TARGET_RUNS_PER_CALL`). 上限に達して続きがありうるときは戻り値の `remaining` が真になる
	 * (厳密な「残りの有無」ではなく、上限で止めたかどうか. 次の呼び出しで 0 件になることがある).
	 * 呼び出しごとに少なくとも1件消えるので、`remaining` が偽になるまで繰り返せば必ず終わる.
	 * `$dry_run` が真のときは、何も消さず、上限も置かずに最後まで数える(件数が多いと時間がかかる).
	 *
	 * 単一所有(v0.10.0. コードレビュー指摘2): 実際に消すときは advisory lock(`prune`)を待たずに取り、
	 * 取れなければ何も消さずに `locked` を真にして返す. run の終わりの自動削除・`wp wpcv prune`・
	 * 管理画面のジョブが同時に同じ候補を走査して DB の負荷を倍にしたり、進行状況を食い違わせたり
	 * しないため. 呼び出し元ごとの扱いは各呼び出し元を参照. dry-run は何も書かないので lock を取らない.
	 *
	 * @param int  $months            保持期間(月. 1 未満なら何もしない).
	 * @param int  $terminated_run_id 今終わった run の id(差分処理の前なので、消してはいけない側に入れる).
	 *                                CLI・管理画面からは 0(無し)で呼ぶ.
	 * @param bool $dry_run           真なら何も消さず、消す件数だけを返す.
	 * @return array{suppressions:int, target_runs:int, findings:int, runs:int, remaining:bool, locked:bool} 消した(`$dry_run` では消すことになる)件数.
	 *         `locked` は、別の削除が実行中で lock が取れず、何もしなかったこと(このとき `remaining` も真).
	 *
	 * @throws RuntimeException 削除に失敗した場合(各 Repository が投げる).
	 */
	public function prune( $months, $terminated_run_id = 0, $dry_run = false ) {
		$result = array(
			'suppressions' => 0,
			'target_runs'  => 0,
			'findings'     => 0,
			'runs'         => 0,
			'remaining'    => false,
			'locked'       => false,
		);

		if ( (int) $months < 1 ) {
			return $result;
		}

		if ( $dry_run || null === $this->lock ) {
			return $this->prune_unlocked( (int) $months, (int) $terminated_run_id, (bool) $dry_run, $result );
		}

		if ( ! $this->lock->acquire( 0 ) ) {
			$result['locked']    = true;
			$result['remaining'] = true; // 消せていないので、続きがある扱いにする.

			return $result;
		}

		try {
			return $this->prune_unlocked( (int) $months, (int) $terminated_run_id, false, $result );
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * `prune()` の本体(lock の外側の判定は `prune()` が行う).
	 *
	 * @param int   $months            保持期間(月. 1 以上).
	 * @param int   $terminated_run_id 今終わった run の id(無ければ 0).
	 * @param bool  $dry_run           真なら何も消さずに数える.
	 * @param array $result            件数を足し込む結果(`prune()` が初期化したもの).
	 * @return array{suppressions:int, target_runs:int, findings:int, runs:int, remaining:bool, locked:bool}
	 */
	private function prune_unlocked( $months, $terminated_run_id, $dry_run, array $result ) {
		$cutoff = $this->cutoff( (int) $months );

		// 期限切れの suppression は、run の有無に関わらず消す.
		$result['suppressions'] = $dry_run
			? $this->suppression_repository->count_expired_before( $cutoff )
			: $this->suppression_repository->delete_expired_before( $cutoff );

		$boundary_run_id = $this->run_repository->find_last_started_before( $cutoff );

		if ( null === $boundary_run_id ) {
			return $result; // 期限切れの run が無い.
		}

		// I3: 実行中・差分処理前の run.
		$unfinished = array_flip( $this->run_repository->find_unfinished_run_ids() );

		$unfinished[ (int) $terminated_run_id ] = true;

		// I1: 各 target の直近の success(I4 の判定にも使うので、1回だけ読む).
		$latest_success = $this->target_run_repository->find_latest_success_map();

		// I1 と、I3: 進行中の run が基準にする、その run より前の直近の success.
		$protected_target_runs = $this->protected_target_run_map( $latest_success, array_keys( $unfinished ) );

		// dry-run で「消すことになる target_run」を run ごとに数え、run の判定(残る target_run が
		// 無いか)に使う. 実際には消さないので、DB の件数からこの数を引いて判定する.
		$simulated_deleted_by_run = array();

		$this->clean_target_runs( $boundary_run_id, $latest_success, $protected_target_runs, $unfinished, $dry_run, $result, $simulated_deleted_by_run );
		$this->clean_runs( $cutoff, $protected_target_runs, $unfinished, $dry_run, $result, $simulated_deleted_by_run );

		return $result;
	}

	/**
	 * 保持期間の境界(UTC の MySQL DATETIME 文字列)を返す.
	 *
	 * 「N か月前の同じ日の同じ時刻」. その月に同じ日が無ければ、その月の末日にする
	 * (例: 2027-05-31 の 3 か月前は 2027-02-28、うるう年の 2028-05-31 なら 2028-02-29).
	 *
	 * `DateTimeImmutable::modify( '-N months' )` は使わない. 存在しない日付を翌月へ繰り越すため
	 * (2027-05-31 の 3 か月前が 2027-03-03 になる. PHP 8.4.4 で確認)、境界が数日新しくなり、
	 * 残すべき履歴まで消してしまう(0.10.0 のコードレビュー指摘1).
	 *
	 * @param int $months 保持期間(月).
	 * @return string
	 */
	private function cutoff( $months ) {
		$now = new DateTimeImmutable( (string) call_user_func( $this->now ), new DateTimeZone( 'UTC' ) );

		// 年と月だけを先に戻す(月の計算を 0 始まりにして、年をまたぐ繰り下がりを整数で扱う).
		$month_index = (int) $now->format( 'Y' ) * 12 + (int) $now->format( 'n' ) - 1 - (int) $months;
		$year        = intdiv( $month_index, 12 );
		$month       = $month_index % 12 + 1;

		// その月の日数(1日の DateTime の `t`). calendar 拡張の cal_days_in_month() には頼らない.
		$days_in_month = (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), new DateTimeZone( 'UTC' ) ) )->format( 't' );
		$day           = min( (int) $now->format( 'j' ), $days_in_month );

		return $now->setDate( $year, $month, $day )->format( 'Y-m-d H:i:s' );
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
	 * @param bool                                                       $dry_run               真なら消さずに数える(上限も置かない).
	 * @param array                                                      $result                `prune()` の戻り値(件数を足し込む).
	 * @param array<int, int>                                            $simulated_deleted     dry-run で消すことになる target_run の run ごとの数.
	 * @return void
	 */
	private function clean_target_runs( $boundary_run_id, array $latest_success, array $protected_target_runs, array $unfinished, $dry_run, array &$result, array &$simulated_deleted ) {
		// I4 の判定に使う「target ごとの直近の success の id」.
		$latest_id_by_target = array();

		foreach ( $latest_success as $target_id => $row ) {
			$latest_id_by_target[ (string) $target_id ] = $row['id'];
		}

		$handled  = 0;
		$after_id = 0;

		while ( $dry_run || $handled < self::MAX_TARGET_RUNS_PER_CALL ) {
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

				$deleted_findings = $dry_run
					? $this->finding_repository->count_by_target_run_id_except( $target_run['id'], $keep_finding_ids )
					: $this->finding_repository->delete_by_target_run_id_except( $target_run['id'], $keep_finding_ids );

				$result['findings'] += $deleted_findings;

				// 通知の記録として残す finding がある間は、その target_run も残す(finding が親を指すため).
				if ( empty( $keep_finding_ids ) ) {
					if ( ! $dry_run ) {
						$this->target_run_repository->delete_by_id( $target_run['id'] );
					} else {
						$simulated_deleted[ $target_run['run_id'] ] = ( $simulated_deleted[ $target_run['run_id'] ] ?? 0 ) + 1;
					}

					++$result['target_runs'];
				} elseif ( $deleted_findings < 1 ) {
					// 何も消さずに残しただけの target_run は、上限に数えない. 数えると、残す行が
					// 上限の件数以上 id の小さい側に並んだとき、毎回その先頭から数え直して
					// 後ろの消せる行へ永久に届かなくなる(v0.9.1).
					continue;
				}

				++$handled;

				if ( ! $dry_run && $handled >= self::MAX_TARGET_RUNS_PER_CALL ) {
					$result['remaining'] = true; // 上限で止めた. 続きがありうる.
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
	 * さらに、同じ `finding_key` で、他の run に同じか新しい `notified_at` の行があるなら残さない
	 * (v0.9.1). 再送抑制が読むのは `find_last_notified_at_by_keys()` の `MAX(notified_at)` だけで、
	 * 古い通知は新しい通知が残る限り判定に効かない. 同じ日時が2行ある場合は、走査が id 順なので
	 * 先に見た方が消え、後に見た方は比べる相手が無くなって残る(どちらも消えることはない).
	 * stat 監視で毎回変わるファイルは同じキーで毎回通知されるため、これが無いと残す行が run の数だけ増える.
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

		$live_rows = array();

		foreach ( $notified as $row ) {
			if ( isset( $live_keys[ $row['finding_key'] ] ) ) {
				$live_rows[] = $row;
			}
		}

		if ( empty( $live_rows ) ) {
			return array();
		}

		// 他の run での、同じキーの直近の通知日時(この target_run が属する run は除く).
		$others = $this->finding_repository->find_last_notified_at_by_keys(
			array_values( array_unique( array_column( $live_rows, 'finding_key' ) ) ),
			$target_run['run_id']
		);

		$keep = array();

		foreach ( $live_rows as $row ) {
			// 他の run に、同じか新しい通知がある行は、残さなくても MAX(notified_at) が変わらない.
			if ( isset( $others[ $row['finding_key'] ] ) && $others[ $row['finding_key'] ] >= $row['notified_at'] ) {
				continue;
			}

			$keep[] = $row['id'];
		}

		return $keep;
	}

	/**
	 * 期限切れで、target_run が1件も残っていない run を消す(I2・I3 は残す).
	 *
	 * @param string                                                     $cutoff                期限の境界(UTC の MySQL DATETIME 文字列).
	 * @param array<int, array{id: int, run_id: int, target_id: string}> $protected_target_runs 消さない target_run.
	 * @param array<int, bool>                                           $unfinished            実行中・差分処理前の run の id => true.
	 * @param bool                                                       $dry_run               真なら消さずに数える(上限も置かない).
	 * @param array                                                      $result                `prune()` の戻り値(件数を足し込む).
	 * @param array<int, int>                                            $simulated_deleted     dry-run で消すことになる target_run の run ごとの数.
	 * @return void
	 */
	private function clean_runs( $cutoff, array $protected_target_runs, array $unfinished, $dry_run, array &$result, array $simulated_deleted ) {
		// I2: 基準の target_run が属する run. 件数を数えるクエリを省くための近道で、
		// 正しさは「target_run が残っていれば消さない」の判定(下の count)が担う.
		$protected_run_ids = array();

		foreach ( $protected_target_runs as $row ) {
			$protected_run_ids[ $row['run_id'] ] = true;
		}

		$deleted  = 0;
		$after_id = 0;

		while ( $dry_run || $deleted < self::MAX_TARGET_RUNS_PER_CALL ) {
			$ids = $this->run_repository->find_ids_started_before( $cutoff, $after_id, self::SCAN_BATCH_SIZE );

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $run_id ) {
				$after_id = $run_id;

				if ( isset( $unfinished[ $run_id ] ) || isset( $protected_run_ids[ $run_id ] ) ) {
					continue;
				}

				// 通知の記録として残した target_run がある(dry-run では、消すことになる分を引いて数える).
				if ( $this->target_run_repository->count_by_run( $run_id ) - ( $simulated_deleted[ $run_id ] ?? 0 ) > 0 ) {
					continue;
				}

				if ( ! $dry_run ) {
					$this->run_repository->delete_by_id( $run_id );
				}

				++$deleted;
				++$result['runs'];

				if ( ! $dry_run && $deleted >= self::MAX_TARGET_RUNS_PER_CALL ) {
					$result['remaining'] = true; // 上限で止めた. 続きがありうる.
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
