<?php
/**
 * WPCV_Update_Event_Matcher クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * D5(記録の有無)・D6(期間外の判定)を、`WPCV_Diff_Dispatcher`(チェックサムtarget.
 * v0.6プラン §3.1)と `WPCV_Chunk_Dispatcher`(stat target. §3.2)の両方から
 * 共通して使えるようにまとめたヘルパー(v0.6 §Step4で2箇所目の利用が出たため
 * 共通化した.「2箇所目の利用が確定してから共通化する」方針どおり).
 *
 * 「基準target_run」は、いずれの呼び出し元でも
 * `WPCV_Target_Run_Repository::find_baseline_target_run()` が返す
 * `array{id, version, run_id}|null` を渡す想定(チェックサムtargetは基準の
 * target_id・statは`{dimension}:{slug}:_stat`のstat target_idで求めたもの).
 *
 * U3(設定`alert_unrecorded_version_change`)は呼び出し元の責務のまま(§3.1の
 * D7は設定を見るが、§3.2のD8は「記録があれば期間・設定を問わず作り直す」ため、
 * このクラス自身では設定を読まない).
 */
class WPCV_Update_Event_Matcher {

	/**
	 * `wpcv_update_events`の永続化層.
	 *
	 * @var WPCV_Update_Event_Repository
	 */
	private $update_event_repository;

	/**
	 * `wpcv_runs`の永続化層(基準target_runが属するrunの`started_at`を引くため).
	 *
	 * @var WPCV_Run_Repository
	 */
	private $run_repository;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Update_Event_Repository $update_event_repository `wpcv_update_events`の永続化層.
	 * @param WPCV_Run_Repository          $run_repository          `wpcv_runs`の永続化層.
	 */
	public function __construct( WPCV_Update_Event_Repository $update_event_repository, WPCV_Run_Repository $run_repository ) {
		$this->update_event_repository = $update_event_repository;
		$this->run_repository          = $run_repository;
	}

	/**
	 * D5: 指定target・versionに一致し、基準target_runのrun開始より後に記録された
	 * 更新イベントがあるかを判定する(期間外・設定は考慮しない).
	 *
	 * @param string      $target_id             対象のtarget_id.
	 * @param string|null $version              今回のversion.
	 * @param array|null  $baseline_target_run   `find_baseline_target_run()`が返した
	 *                                           `array{id, version, run_id}`。基準が
	 *                                           無ければ`null`(その場合は記録なし扱い).
	 * @return bool
	 */
	public function has_matching_event( $target_id, $version, ?array $baseline_target_run ) {
		$baseline_run = $this->find_baseline_run( $baseline_target_run );

		if ( null === $baseline_run ) {
			return false;
		}

		$found = $this->update_event_repository->find_matching( $target_id, $version, (string) $baseline_run['started_at'] );

		return ! empty( $found );
	}

	/**
	 * 指定target に、指定時刻以降の更新イベントがあるかを判定する(v0.8 §Step1. §3.3-3).
	 *
	 * D5 の `has_matching_event()` と違い、version・基準 run は見ない. chunk を始めた
	 * 時刻より後に記録があれば、chunk の途中で更新(同じ version の入れ直しを含む)が
	 * 入った可能性がある.
	 *
	 * @param string $target_id 本体の target_id.
	 * @param string $since     `Y-m-d H:i:s` 形式の UTC 日時(chunk を始めた時刻).
	 * @return bool
	 */
	public function has_event_since( $target_id, $since ) {
		return $this->update_event_repository->exists_since( $target_id, $since );
	}

	/**
	 * D6: 基準target_runのrun開始が`wpcv_update_events_since`より前(=期間外)かを
	 * 判定する。基準が引けない・`wpcv_update_events_since`が未設定の場合も安全側で
	 * `true`(判定不能=期間外と同じ扱い)を返す(`WPCV_Migrator::get_update_events_since()`
	 * のdocblock参照).
	 *
	 * @param array|null $baseline_target_run `find_baseline_target_run()`が返した値.
	 * @return bool
	 */
	public function is_outside_tracked_period( ?array $baseline_target_run ) {
		$baseline_run = $this->find_baseline_run( $baseline_target_run );

		if ( null === $baseline_run ) {
			return true;
		}

		$since = WPCV_Migrator::get_update_events_since();

		return null === $since || (string) $baseline_run['started_at'] < $since;
	}

	/**
	 * `$baseline_target_run['run_id']`から、そのrunの行(`started_at`を含む)を引く
	 * (`has_matching_event()`/`is_outside_tracked_period()`の共通処理).
	 *
	 * @param array|null $baseline_target_run `find_baseline_target_run()`が返した値.
	 * @return array|null `started_at`が空でない run 行、無ければ `null`.
	 */
	private function find_baseline_run( ?array $baseline_target_run ) {
		if ( null === $baseline_target_run ) {
			return null;
		}

		$run = $this->run_repository->find_by_id( (int) ( $baseline_target_run['run_id'] ?? 0 ) );

		if ( null === $run || empty( $run['started_at'] ) ) {
			return null;
		}

		return $run;
	}
}
