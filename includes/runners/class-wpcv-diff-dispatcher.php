<?php
/**
 * WPCV_Diff_Dispatcher クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 差分処理(v0.5後半 §Step12)を1chunk分進めるクラス。`WPCV_Chunk_Dispatcher`と
 * 同格のオーケストレーターだが、対象は「run単位の`wpcv_runs.diff_status`」であり、
 * 独自のcontinuation scheduler・lease延長を持たない ―― `WPCV_Chunk_Dispatcher::dispatch()`
 * から委譲される形でのみ呼ばれ、継続予約(Action Scheduler)は呼び出し元が
 * `WPCV_Chunk_Dispatcher`自身の仕組みを使い回す設計(§配線参照。同じフックを共有する
 * ため、差分処理専用のフック・グループは持たない).
 *
 * `dispatch_diff( $run_id )` 1回の責務は次のいずれか1つだけ(v0.5後半プラン §1.2
 * 「手放し型」の設計判断. 2026-09-26 ユーザー承認済み ―― §1.2参照):
 *
 * 1. `claim_diff()`が失敗(試行上限超過)→ `diff_failed`
 * 2. `claim_diff()`が不成立(他プロセスが処理中・claim対象が無い)→ `diff_not_claimable`
 * 3. `diff_cursor`があれば、その続き(chunkedモードの1 pass分)を1バッチだけ処理
 * 4. `diff_cursor`が無ければ、`diff_mode`が未設定の最初のtargetを1件選び、
 *    bulk modeなら1回で完了、chunkedモードなら最初のバッチだけ処理
 * 5. すべてのtargetの`diff_mode`が確定していれば、target_removed(アンインストール)
 *    掃除・stat targetの`wpcv_file_states`掃除・件数集計を行い`alerting`へ進める
 * 6. `claim_diff()`が`alerting`をclaimした(=5が既に済んでいる)場合は、
 *    `WPCV_Alert_Sender::send_for_run()`を呼んでから`done`へ進める(v0.5後半
 *    §Step14c.`dispatch_diff()`のdocblock参照)
 *
 * 3・4の結果は必ず`finalize_diff_chunk(..., completed:false, $cursor_json)`で
 * `pending`へ手放す(1 targetの処理が完全に終わっても、他のtargetがまだ残って
 * いる可能性があるため`completed:true`にはしない。全target完了の判定は5でのみ行う).
 *
 * 個別target・batchの処理中に例外が起きた場合はここで捕捉しない(呼び出し元の
 * Action Schedulerが吸収する。`WPCV_Chunk_Dispatcher::schedule_via_action_scheduler()`
 * のdocblock参照)。`finalize_diff_chunk()`が呼ばれないまま終わるため、runは
 * `processing`のまま残り、lease切れ後に`claim_diff()`が同じ`diff_cursor`から
 * 再試行させる(§1.1「lease切れの再claim」。試行回数はそちらで管理される).
 */
class WPCV_Diff_Dispatcher {

	/**
	 * Pass 1/Pass 2の1バッチで読む finding の最大件数.
	 *
	 * 未実測: 暫定値(`WPCV_Chunk_Dispatcher::DEFAULT_BUDGET_MAX_FILES`と同じ値を
	 * 流用).実測の上で見直すこと(§数値を決める前に実測するルール).
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 500;

	/**
	 * `wpcv_runs`の永続化層.
	 *
	 * @var WPCV_Run_Repository
	 */
	private $run_repository;

	/**
	 * `wpcv_target_runs`の永続化層.
	 *
	 * @var WPCV_Target_Run_Repository
	 */
	private $target_run_repository;

	/**
	 * `wpcv_findings`の永続化層.
	 *
	 * @var WPCV_Finding_Repository
	 */
	private $finding_repository;

	/**
	 * `wpcv_file_states`の永続化層(stat targetのベースライン掃除に使う.
	 * `WPCV_Chunk_Dispatcher`と異なりnullableにしていない ―― 差分処理は
	 * 全target完了のたびに必ずこの掃除を行うため、省略可能にする意味が無い).
	 *
	 * @var WPCV_File_State_Repository
	 */
	private $file_state_repository;

	/**
	 * アラート送信本体(v0.5後半 §Step14c. `alerting`をclaimしたときに呼ぶ).
	 *
	 * @var WPCV_Alert_Sender
	 */
	private $alert_sender;

	/**
	 * D5・D6の突き合わせ(v0.6 §Step3・§Step4で`WPCV_Chunk_Dispatcher`と共通化)。
	 * `DIFF_MODE_VERSION_CHANGED`の分岐で使う.
	 *
	 * @var WPCV_Update_Event_Matcher
	 */
	private $update_event_matcher;

	/**
	 * `wpcv_suppressions`の永続化層(v0.6 §Step5. D9「承認の失効」に使う).
	 *
	 * @var WPCV_Suppression_Repository
	 */
	private $suppression_repository;

	/**
	 * `claim_diff()`に渡す一意なowner文字列を生成するcallable.
	 *
	 * @var callable
	 */
	private $lease_owner_factory;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Run_Repository         $run_repository         `wpcv_runs`の永続化層.
	 * @param WPCV_Target_Run_Repository  $target_run_repository  `wpcv_target_runs`の永続化層.
	 * @param WPCV_Finding_Repository     $finding_repository     `wpcv_findings`の永続化層.
	 * @param WPCV_File_State_Repository  $file_state_repository  `wpcv_file_states`の永続化層.
	 * @param WPCV_Alert_Sender           $alert_sender           アラート送信本体(v0.5後半 §Step14c).
	 * @param WPCV_Update_Event_Matcher   $update_event_matcher   D5・D6の突き合わせ(v0.6 §Step3・§Step4).
	 * @param WPCV_Suppression_Repository $suppression_repository `wpcv_suppressions`の永続化層(v0.6 §Step5).
	 * @param callable|null               $lease_owner_factory    省略時は `uniqid( 'wpcv_diff_', true )`.
	 */
	public function __construct(
		WPCV_Run_Repository $run_repository,
		WPCV_Target_Run_Repository $target_run_repository,
		WPCV_Finding_Repository $finding_repository,
		WPCV_File_State_Repository $file_state_repository,
		WPCV_Alert_Sender $alert_sender,
		WPCV_Update_Event_Matcher $update_event_matcher,
		WPCV_Suppression_Repository $suppression_repository,
		?callable $lease_owner_factory = null
	) {
		$this->run_repository         = $run_repository;
		$this->target_run_repository  = $target_run_repository;
		$this->finding_repository     = $finding_repository;
		$this->file_state_repository  = $file_state_repository;
		$this->alert_sender           = $alert_sender;
		$this->update_event_matcher   = $update_event_matcher;
		$this->suppression_repository = $suppression_repository;

		$this->lease_owner_factory = $lease_owner_factory ?? static function () {
			return uniqid( 'wpcv_diff_', true );
		};
	}

	/**
	 * 1回分の差分処理dispatchを行う(クラス docblock参照).
	 *
	 * 6. `claim_diff()`が`alerting`をclaimした場合(手順5で全target完了済み)は、
	 * `WPCV_Alert_Sender::send_for_run()`を呼んでから`finalize_diff_alerting()`で
	 * `done`へ進める(v0.5後半 §Step14c).送信中に例外が起きてもここでは捕捉
	 * しない(クラスdocblock「個別target・batchの処理中に例外が起きた場合は
	 * ここで捕捉しない」と同じ方針. Action Schedulerが吸収し、lease切れ後に
	 * 別ownerが再claimして送り直す=D10「少なくとも1回」).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return array{action: string} 少なくとも `action` キーを持つ結果
	 *         (`diff_failed`|`diff_not_claimable`|`diff_claimed`|`diff_finalized`|
	 *         `diff_alerted`。テスト・観測用).
	 */
	public function dispatch_diff( $run_id ) {
		$run_id = (int) $run_id;
		$owner  = call_user_func( $this->lease_owner_factory );
		$claim  = $this->run_repository->claim_diff( $run_id, $owner );

		if ( $claim['failed'] ) {
			return array( 'action' => 'diff_failed' );
		}

		if ( ! $claim['claimed'] ) {
			return array( 'action' => 'diff_not_claimable' );
		}

		$run = $claim['run'];

		if ( WPCV_Diff_Status::ALERTING === ( $run['diff_status'] ?? null ) ) {
			$this->alert_sender->send_for_run( $run_id, $owner );
			$this->run_repository->finalize_diff_alerting( $run_id, $owner );

			return array( 'action' => 'diff_alerted' );
		}

		$cursor = self::decode_cursor( $run['diff_cursor'] ?? null );

		if ( null !== $cursor ) {
			$next_cursor = $this->continue_target( $run_id, $cursor );
			$this->run_repository->finalize_diff_chunk( $run_id, $owner, self::encode_cursor( $next_cursor ), false );

			return array( 'action' => 'diff_claimed' );
		}

		$target_run = $this->find_next_target_needing_mode( $run_id );

		if ( null !== $target_run ) {
			$next_cursor = $this->start_target( $run_id, $target_run );
			$this->run_repository->finalize_diff_chunk( $run_id, $owner, self::encode_cursor( $next_cursor ), false );

			return array( 'action' => 'diff_claimed' );
		}

		// §3.3「取りこぼしの回収」ではなく「今回のrunで全target処理が完了した」
		// タイミング(手順5. `WPCV_Diff_Dispatcher`クラスdocblock参照).
		$this->cleanup_after_all_targets_processed( $run_id );
		$counts = $this->finding_repository->aggregate_diff_counts( $run_id );
		$this->run_repository->finalize_diff_chunk( $run_id, $owner, null, true, $counts );

		return array( 'action' => 'diff_finalized' );
	}

	/**
	 * 今回の run のtarget_run一覧から、まだ`diff_mode`が設定されていない
	 * 最初のもの(id昇順)を探す.
	 *
	 * @param int $run_id 対象の run の id.
	 * @return array|null 見つからなければ `null`(全target処理済み).
	 */
	private function find_next_target_needing_mode( $run_id ) {
		$candidates = $this->target_run_repository->find_all_by_run( $run_id );

		usort(
			$candidates,
			static function ( $a, $b ) {
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);

		foreach ( $candidates as $target_run ) {
			if ( empty( $target_run['diff_mode'] ) ) {
				return $target_run;
			}
		}

		return null;
	}

	/**
	 * 今回の run のtarget_run一覧から、指定idの行を1件探す
	 * (`WPCV_Target_Run_Repository::find_by_id()`がprivateのため、既存クラス
	 * 〔`process_stat_target()`〕と同じ「全件取得してPHPで絞り込む」方式を使う).
	 *
	 * @param int $run_id         対象の run の id.
	 * @param int $target_run_id  探す target_run の id.
	 * @return array|null 見つからなければ `null`(通常は起こらない ―― `diff_cursor`が
	 *                     指す target_run_id は同じ run に属することを呼び出し元が
	 *                     保証する).
	 */
	private function find_target_run( $run_id, $target_run_id ) {
		foreach ( $this->target_run_repository->find_all_by_run( $run_id ) as $candidate ) {
			if ( (int) $candidate['id'] === (int) $target_run_id ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * まだ`diff_mode`が未設定のtarget 1件を処理する(手順4).
	 *
	 * Stat target(`WPCV_Target_Resolver::is_stat_id()`)が`success`(実際に走査した)
	 * 場合は§2.3の通り無条件で`event`にする(世代比較の概念を持たないため、
	 * `determine_diff_mode()`を経由しない。それ以外のstatus〔skipped等〕は
	 * 通常のtargetと同じ`determine_diff_mode()`のロジックに委ねる ――
	 * `unverifiable`/`skipped`〔`checksum_covered`・依存待ち等〕の分岐は
	 * dimensionを問わず共通のため).
	 *
	 * @param int   $run_id     対象の run の id.
	 * @param array $target_run `find_next_target_needing_mode()`が返したtarget_run行.
	 * @return array|null 次に処理すべきcursor(chunkedモードの最初のバッチへ進む).
	 *                     bulk modeで1回で完了したら `null`.
	 */
	private function start_target( $run_id, array $target_run ) {
		$target_run_id = (int) $target_run['id'];
		$is_stat       = WPCV_Target_Resolver::is_stat_id( (string) $target_run['target_id'] );

		if ( $is_stat && WPCV_Target_Status::SUCCESS === $target_run['status'] ) {
			$this->target_run_repository->update_diff_mode( $target_run_id, WPCV_Generation_Differ::DIFF_MODE_EVENT, null );
			$this->finding_repository->mark_diff_state_for_target_run( $target_run_id, WPCV_Generation_Differ::DIFF_STATE_EVENT, true );

			return null;
		}

		$baseline          = $this->target_run_repository->find_baseline_target_run( (string) $target_run['target_id'], $run_id );
		$baseline_for_mode = null;

		if ( null !== $baseline ) {
			$baseline_for_mode = array(
				'version' => $baseline['version'],
				'usable'  => $this->finding_repository->is_baseline_usable( (int) $baseline['id'] ),
			);
		}

		$mode = WPCV_Generation_Differ::determine_diff_mode(
			(string) $target_run['status'],
			$target_run['error_code'] ?? null,
			$target_run['version'],
			$baseline_for_mode
		);

		$this->target_run_repository->update_diff_mode(
			$target_run_id,
			$mode,
			null === $baseline ? null : (int) $baseline['id']
		);

		switch ( $mode ) {
			case WPCV_Generation_Differ::DIFF_MODE_FIRST:
				// 基準が無い、または使えない(v4より前の行しか無い)。基準側は
				// 触らない(§1.4「usable=falseの基準は将来的にも解決されない
				// 古い行が残り続けるが、Step12のスコープ外」).
				$this->finding_repository->mark_diff_state_for_target_run( $target_run_id, WPCV_Generation_Differ::DIFF_STATE_NEW, true );
				return null;

			case WPCV_Generation_Differ::DIFF_MODE_VERSION_CHANGED:
				$this->finding_repository->mark_diff_state_for_target_run( $target_run_id, WPCV_Generation_Differ::DIFF_STATE_NEW, true );
				// determine_diff_mode()がこのモードを返すのは基準が使える場合のみ
				// なので、$baselineは必ず非nullである(クラスGenerationDifferの
				// 分岐参照).
				$this->finding_repository->end_all_for_target_run( $run_id, (int) $baseline['id'], WPCV_Generation_Differ::END_REASON_VERSION_CHANGED );
				$this->maybe_flag_unrecorded_version_change( $target_run_id, (string) $target_run['target_id'], $target_run['version'], $baseline );
				// D9(v0.6 §Step5・§3.3): versionが変わったので、古いversionのまま
				// 残っているallowlist_hash承認を失効させる(手動デプロイ等、記録が
				// 無い経路でversionが変わった場合も含め、version_changedと判定された
				// 時点で経路を問わず失効させてよい.差分処理〔このメソッド〕が
				// version一致を毎回確認しているため、失効しても照合の挙動自体は
				// 変わらず一覧の表示だけが正しくなる〔D9〕).
				$this->suppression_repository->expire_allowlist_hash_rules_with_different_version(
					(string) $target_run['dimension'],
					(string) $target_run['slug'],
					$target_run['version']
				);
				return null;

			case WPCV_Generation_Differ::DIFF_MODE_EXCLUDED:
				if ( null !== $baseline ) {
					$this->finding_repository->end_all_for_target_run( $run_id, (int) $baseline['id'], WPCV_Generation_Differ::END_REASON_EXCLUDED );
				}
				return null;

			case WPCV_Generation_Differ::DIFF_MODE_SKIPPED:
				// §1.3「exclude_target以外の理由でskipped: 何もしない」.
				return null;

			case WPCV_Generation_Differ::DIFF_MODE_COMPARED:
			case WPCV_Generation_Differ::DIFF_MODE_NOT_VERIFIED:
				// §1.4 Pass 1から開始する(基準が無くても、Pass 1は
				// baseline_target_run_id=nullを許容する設計. run_pass1_batch()参照).
				return array(
					'target_run_id' => $target_run_id,
					'pass'          => 1,
					'after_id'      => 0,
				);

			default:
				// determine_diff_mode()は現時点でこれ以外の値を返さないが、
				// 将来の値追加漏れで無言のまま何もしないよりは、ここに来た
				// 事実だけでも明示しておく(安全側のフォールバック).
				return null;
		}
	}

	/**
	 * `DIFF_MODE_VERSION_CHANGED`になったtarget_runについて、WordPressの更新機構を
	 * 通った記録(`wpcv_update_events`)があるかを調べ、無ければ
	 * `error_code = version_changed_unrecorded`を書く(v0.6プラン §3.1・D5・D6・U3.
	 * §3.1の組み合わせ表そのものの実装).
	 *
	 * 判定順序(表の行の順序と対応): (1) 設定`alert_unrecorded_version_change`が
	 * OFFなら判定不要 (2) 基準target_runが属するrunの開始時刻が引けなければ
	 * 安全側で何もしない (3) その時刻が`wpcv_update_events_since`(D6)より前
	 * (または`wpcv_update_events_since`自体が未設定)なら「期間外」として何もしない
	 * (4) `find_matching()`で記録があれば何もしない (5) ここまで残ったものだけ
	 * `error_code`を書く.
	 *
	 * @param int         $target_run_id 対象のtarget_runのid.
	 * @param string      $target_id     対象のtarget_id.
	 * @param string|null $version       今回のversion(`target_runs.version`列の値).
	 * @param array       $baseline      `find_baseline_target_run()`が返した基準target_run
	 *                                   (`id`/`version`/`run_id`).
	 * @return void
	 */
	private function maybe_flag_unrecorded_version_change( $target_run_id, $target_id, $version, array $baseline ) {
		if ( ! WPCV_Settings::get_alert_unrecorded_version_change_enabled() ) {
			return;
		}

		if ( $this->update_event_matcher->is_outside_tracked_period( $baseline ) ) {
			// D6: 期間外(基準runの開始時刻が引けない場合も判定不能=期間外と同じ
			// 扱いになる.`WPCV_Update_Event_Matcher::is_outside_tracked_period()`
			// のdocblock参照).
			return;
		}

		// v0.7 §Step6 是正: テーマの `:_scan`(本体と同じ version を持つ派生の target)には
		// 印を付けない. 更新イベントは本体の target_id(`theme:{stylesheet}`)で記録されるので、
		// `:_scan` の target_id のままでは記録のある更新でも見つからず、印が付いてしまう
		// (2026-10-01 test-armfu.local で、WP-CLI の更新のあと `theme:twentytwentyone:_scan`
		// に付いた). `:_scan` は本体が照合できたときしか走らないので、version の変化は
		// 本体の target_run に印が付く(記録が無ければ). 二重に通知しないためにも付けない.
		// stat の target(`:_stat`)は `start_target()` で常に `event` モードになり、ここへは来ない.
		if ( null !== WPCV_Target_Resolver::body_id_of_scan( $target_id ) ) {
			return;
		}

		if ( $this->update_event_matcher->has_matching_event( $target_id, $version, $baseline ) ) {
			return;
		}

		$this->target_run_repository->mark_version_changed_unrecorded( $target_run_id );
	}

	/**
	 * `diff_cursor`が指す、chunkedモード(compared/not_verified)のtargetの
	 * 続きを1バッチだけ処理する(手順3).
	 *
	 * @param int   $run_id 対象の run の id.
	 * @param array $cursor `decode_cursor()`が返した連想配列
	 *                       (`target_run_id`/`pass`/`after_id`).
	 * @return array|null 次に処理すべきcursor. 両passが完了していれば `null`.
	 */
	private function continue_target( $run_id, array $cursor ) {
		$target_run_id = (int) $cursor['target_run_id'];
		$target_run    = $this->find_target_run( $run_id, $target_run_id );

		if ( null === $target_run ) {
			// 通常は起こらない(cursorが指すtarget_run_idは同じrunに属する
			// はず). 安全側としてこのtargetは諦め、次のtargetへ進む.
			return null;
		}

		if ( 1 === (int) $cursor['pass'] ) {
			$baseline_target_run_id = isset( $target_run['baseline_target_run_id'] ) ? $target_run['baseline_target_run_id'] : null;
			$result                 = $this->run_pass1_batch( $run_id, $target_run, null === $baseline_target_run_id ? null : (int) $baseline_target_run_id, (int) $cursor['after_id'] );

			if ( null === $result || ! $result['more'] ) {
				if ( WPCV_Generation_Differ::DIFF_MODE_COMPARED === $target_run['diff_mode'] ) {
					// §1.4「pass 1は必ずpass 2より先に完了させる」. comparedのみpass 2へ進む.
					return array(
						'target_run_id' => $target_run_id,
						'pass'          => 2,
						'after_id'      => 0,
					);
				}

				// not_verifiedはpass 2が無いのでここでこのtargetは完了(§1.4).
				return null;
			}

			return array(
				'target_run_id' => $target_run_id,
				'pass'          => 1,
				'after_id'      => $result['last_id'],
			);
		}

		$result = $this->run_pass2_batch( $run_id, $target_run, (int) $cursor['after_id'] );

		if ( null === $result || ! $result['more'] ) {
			return null;
		}

		return array(
			'target_run_id' => $target_run_id,
			'pass'          => 2,
			'after_id'      => $result['last_id'],
		);
	}

	/**
	 * Pass 1(今回側)を1バッチ処理する(§1.4).
	 *
	 * @param int      $run_id                 対象の run の id.
	 * @param array    $target_run             今回側のtarget_run行.
	 * @param int|null $baseline_target_run_id 基準のtarget_run id(無ければ `null`.
	 *                                          `not_verified`で基準が無い場合に起こる).
	 * @param int      $after_id               この id より大きい行から続ける.
	 * @return array{last_id: int, more: bool}|null バッチが空(=このtargetに
	 *         findingが1件も無い)なら `null`.
	 */
	private function run_pass1_batch( $run_id, array $target_run, $baseline_target_run_id, $after_id ) {
		$target_run_id = (int) $target_run['id'];
		$batch         = $this->finding_repository->find_batch_by_target_run( $target_run_id, $after_id, self::DEFAULT_BATCH_SIZE );

		if ( empty( $batch ) ) {
			return null;
		}

		$keys_in_batch = array();

		foreach ( $batch as $row ) {
			if ( null !== $row['finding_key'] ) {
				$keys_in_batch[] = $row['finding_key'];
			}
		}

		$matched_keys = array();

		if ( null !== $baseline_target_run_id && ! empty( $keys_in_batch ) ) {
			$matched_keys = array_flip( $this->finding_repository->find_matching_keys( $baseline_target_run_id, array_values( array_unique( $keys_in_batch ) ), true ) );
		}

		$new_ids             = array();
		$continuing_ids      = array();
		$suppressed_end_keys = array();

		foreach ( $batch as $row ) {
			$is_suppressed = ! empty( $row['suppressed_by'] ) || ! empty( $row['suppression_id'] );
			$key           = $row['finding_key'];
			$is_match      = null !== $key && isset( $matched_keys[ $key ] );

			if ( $is_suppressed ) {
				// §2.2「抑制されていれば diff_state は NULL のまま」
				// (save_findings()時点の既定値のまま.ここでは書かない).
				// 基準に同じキーがあれば「抑制終了」として基準側を終わらせる.
				if ( $is_match ) {
					$suppressed_end_keys[] = $key;
				}
				continue;
			}

			if ( $is_match ) {
				$continuing_ids[] = (int) $row['id'];
			} else {
				$new_ids[] = (int) $row['id'];
			}
		}

		if ( ! empty( $new_ids ) ) {
			$this->finding_repository->mark_diff_state_by_ids( $new_ids, WPCV_Generation_Differ::DIFF_STATE_NEW );
		}

		if ( ! empty( $continuing_ids ) ) {
			$this->finding_repository->mark_diff_state_by_ids( $continuing_ids, WPCV_Generation_Differ::DIFF_STATE_CONTINUING );
		}

		if ( ! empty( $suppressed_end_keys ) && null !== $baseline_target_run_id ) {
			$this->finding_repository->mark_ended_by_keys( $run_id, $baseline_target_run_id, array_values( array_unique( $suppressed_end_keys ) ), WPCV_Generation_Differ::END_REASON_SUPPRESSED );
		}

		$last_row = $batch[ count( $batch ) - 1 ];

		return array(
			'last_id' => (int) $last_row['id'],
			'more'    => count( $batch ) === self::DEFAULT_BATCH_SIZE,
		);
	}

	/**
	 * Pass 2(基準側)を1バッチ処理する(§1.4).
	 *
	 * `compared`モードのみ到達する(`not_verified`はpass 1完了時点でこのtargetの
	 * 処理を終える. `continue_target()`参照).
	 *
	 * @param int   $run_id     対象の run の id.
	 * @param array $target_run 今回側のtarget_run行(`baseline_target_run_id`を含む).
	 * @param int   $after_id   この id より大きい行から続ける.
	 * @return array{last_id: int, more: bool}|null バッチが空なら `null`.
	 */
	private function run_pass2_batch( $run_id, array $target_run, $after_id ) {
		$baseline_target_run_id = (int) $target_run['baseline_target_run_id'];
		$current_target_run_id  = (int) $target_run['id'];

		$batch = $this->finding_repository->find_baseline_batch( $baseline_target_run_id, $after_id, self::DEFAULT_BATCH_SIZE );

		if ( empty( $batch ) ) {
			return null;
		}

		$keys_in_batch = array_values( array_unique( array_column( $batch, 'finding_key' ) ) );
		$matched_keys  = empty( $keys_in_batch )
			? array()
			: array_flip( $this->finding_repository->find_matching_keys( $current_target_run_id, $keys_in_batch, false ) );

		$unmatched_keys = array();

		foreach ( $batch as $row ) {
			if ( ! isset( $matched_keys[ $row['finding_key'] ] ) ) {
				$unmatched_keys[] = $row['finding_key'];
			}
		}

		if ( ! empty( $unmatched_keys ) ) {
			$this->finding_repository->mark_ended_by_keys( $run_id, $baseline_target_run_id, array_values( array_unique( $unmatched_keys ) ), WPCV_Generation_Differ::END_REASON_RESOLVED );
		}

		$last_row = $batch[ count( $batch ) - 1 ];

		return array(
			'last_id' => (int) $last_row['id'],
			'more'    => count( $batch ) === self::DEFAULT_BATCH_SIZE,
		);
	}

	/**
	 * 全targetの`diff_mode`が確定したタイミングで1回だけ行う掃除(手順5).
	 *
	 * `WPCV_File_State_Repository`の掃除(Step9で実装済み)は「今回列挙された
	 * target一覧」が確定する、この一択のタイミングでのみ呼べる(Step12の
	 * 進捗メモ§3参照)。target_removed(アンインストール)検出も同じタイミングで
	 * 行う(§target_removed検出.2026-09-26ユーザー承認済み).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return void
	 */
	private function cleanup_after_all_targets_processed( $run_id ) {
		$target_runs = $this->target_run_repository->find_all_by_run( $run_id );

		$enumerated_stat_target_ids = array();
		$excluded_stat_target_ids   = array();

		foreach ( $target_runs as $target_run ) {
			// v0.6 §Step12是正: `is_stat_id()`だけで判定すると、`:_stat`接尾辞を
			// 持たない`core:_config`が「列挙されなかったtarget」として扱われ、
			// 毎runベースラインが削除されてしまう不具合があった
			// (`WPCV_Target_Resolver::uses_file_state_storage()`のdocblock参照).
			if ( ! WPCV_Target_Resolver::uses_file_state_storage( (string) $target_run['target_id'], (string) $target_run['dimension'], (string) $target_run['slug'] ) ) {
				continue;
			}

			$enumerated_stat_target_ids[] = (string) $target_run['target_id'];

			if ( WPCV_Generation_Differ::DIFF_MODE_EXCLUDED === ( $target_run['diff_mode'] ?? null ) ) {
				$excluded_stat_target_ids[] = (string) $target_run['target_id'];
			}
		}

		$this->file_state_repository->delete_targets_not_enumerated( $enumerated_stat_target_ids );

		if ( ! empty( $excluded_stat_target_ids ) ) {
			$this->file_state_repository->delete_excluded_targets( $excluded_stat_target_ids );
		}

		$this->handle_target_removed( $run_id, $target_runs );
	}

	/**
	 * 今回のrunのtarget_run一覧に現れなかったtarget_id(アンインストール等)を探し、
	 * その基準findingを`target_removed`で終わらせる(§target_removed検出.
	 * `WPCV_Generation_Differ::determine_diff_mode()`はこのケースを扱わない
	 * ―― 今回runのtarget_run自体が存在しないため.クラスdocblock参照).
	 *
	 * @param int   $run_id               対象の run の id.
	 * @param array $current_target_runs 今回のrunのtarget_run一覧.
	 * @return void
	 */
	private function handle_target_removed( $run_id, array $current_target_runs ) {
		$current_target_ids = array();

		foreach ( $current_target_runs as $target_run ) {
			$current_target_ids[ (string) $target_run['target_id'] ] = true;
		}

		foreach ( $this->target_run_repository->find_all_known_target_ids() as $target_id ) {
			if ( isset( $current_target_ids[ $target_id ] ) ) {
				continue;
			}

			$baseline = $this->target_run_repository->find_baseline_target_run( $target_id, $run_id );

			if ( null === $baseline ) {
				continue;
			}

			$this->finding_repository->end_all_for_target_run( $run_id, (int) $baseline['id'], WPCV_Generation_Differ::END_REASON_TARGET_REMOVED );
		}
	}

	/**
	 * `diff_cursor`(連想配列)をDB保存用のJSON文字列へ変換する.
	 *
	 * @param array|null $cursor `continue_target()`/`start_target()`が返した値.
	 * @return string|null `null`ならそのまま `null`(完了).
	 */
	private static function encode_cursor( $cursor ) {
		return null === $cursor ? null : wp_json_encode( $cursor );
	}

	/**
	 * `wpcv_runs.diff_cursor`(JSON文字列)を連想配列へ変換する.
	 *
	 * @param string|null $cursor_json `claim_diff()`が返したrunの`diff_cursor`.
	 * @return array|null 空・不正なJSONなら `null`(cursor無しとして扱う).
	 */
	private static function decode_cursor( $cursor_json ) {
		if ( empty( $cursor_json ) ) {
			return null;
		}

		$decoded = json_decode( (string) $cursor_json, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
