<?php
/**
 * WPCV_Run_Coordinator クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 1回分の検証(run)を、chunk分割実行の上で「完走するまでループする」薄いadapter
 * (v0.4.0 §Step5)。
 *
 * V0.3.1までは検証ロジック(旧`WPCV_Verifier::verify_core()` 等)を直接呼ぶ一括実行
 * エンジンだった。v0.4.0 §Step4でchunk単位の分割実行(`WPCV_Chunk_Dispatcher`)を
 * 導入したことで、検証ロジックの実体は `WPCV_Chunk_Verifier`/`WPCV_Chunk_Dispatcher`
 * 側に一本化された。このクラスはもはや自前の検証ロジックを持たず、
 *
 * 1. `WPCV_Run_Starter::plan_and_save()` で対象を列挙・保存し、
 * 2. `WPCV_Chunk_Dispatcher::dispatch()` を終端状態に達するまで同一プロセス内で
 *    ループし、
 * 3. 最終的な target_runs から summary を再計算して返す
 *
 * という「plan + dispatchループ」の薄いadapterになった(プラン§Step5「v0.3.1の
 * 一括Coordinatorは互換adapterに縮小し、二重の検証実装を残さない」)。
 *
 * 公開契約(`run( $run_id, $context )` が `{run_id, summary}` を返す)は
 * v0.3.1までと変えていない。そのため CLI 同期・REST・Action Scheduler不在時の
 * 同期フォールバックはこのクラスの呼び出しコード自体を変更する必要が無い
 * (`WPCV_Plugin::build_run_coordinator()` が内部の配線を差し替えるのみ).
 *
 * **WP-Cron自動実行・CLI `--async`・「今すぐ実行」はこのクラスを経由しない**
 * (`WPCV_Runner_Async::run_async_action()` が `WPCV_Run_Starter::plan_and_save()` +
 * `WPCV_Chunk_Dispatcher::dispatch()` を1回だけ呼び、続きはAS actionの自己連鎖に
 * 任せる設計。§6.2「CLIは同期が最も確実」という方針とは別に、自動実行系は
 * 1リクエスト・1AS actionをブロックし続けない設計にするため)。このクラスが
 * 使う `WPCV_Chunk_Dispatcher` インスタンスは、AS action向けのシングルトン
 * (`WPCV_Plugin::chunk_dispatcher()`)とは別に、continuation schedulerをno-opに
 * したものを使う(`WPCV_Plugin::build_run_coordinator()` 参照。同期ループ自身が
 * 「次のdispatch呼び出し」を供給するため、AS への enqueue は不要かつ有害
 * 〔1回の同期runで数十件の不要なAS actionが積み上がる〕).
 */
class WPCV_Run_Coordinator {

	/**
	 * Target列挙.
	 *
	 * @var WPCV_Run_Planner
	 */
	private $planner;

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
	 * Chunk分割実行のdispatcher(continuation schedulerがno-opのインスタンスを想定.
	 * クラス docblock 参照).
	 *
	 * @var WPCV_Chunk_Dispatcher
	 */
	private $dispatcher;

	/**
	 * `dispatch()` の戻り値のうち、ループを終了させる `action` の一覧.
	 *
	 * @var string[]
	 */
	const TERMINAL_ACTIONS = array( 'run_finalized', 'aborted', 'run_not_found', 'run_already_terminal' );

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Run_Planner           $planner               target列挙.
	 * @param WPCV_Run_Repository        $run_repository        `wpcv_runs` の永続化層.
	 * @param WPCV_Target_Run_Repository $target_run_repository `wpcv_target_runs` の永続化層.
	 * @param WPCV_Chunk_Dispatcher      $dispatcher            chunk分割実行のdispatcher.
	 */
	public function __construct(
		WPCV_Run_Planner $planner,
		WPCV_Run_Repository $run_repository,
		WPCV_Target_Run_Repository $target_run_repository,
		WPCV_Chunk_Dispatcher $dispatcher
	) {
		$this->planner               = $planner;
		$this->run_repository        = $run_repository;
		$this->target_run_repository = $target_run_repository;
		$this->dispatcher            = $dispatcher;
	}

	/**
	 * コア・公式プラグイン・MU プラグイン領域を検証し、1回の run として保存する.
	 *
	 * `$run_id` は呼び出し元が `WPCV_Run_Repository::reserve_run()`(必要なら
	 * `mark_queued_running()` で `queued` から引き継いで)で事前に予約した、
	 * 既に `running` である run の id を渡すこと(v0.3.1 §Step1由来の契約。
	 * このクラス自身は run 行を作成しない).
	 *
	 * @param int   $run_id  呼び出し元が予約済みの(`running` 状態の)run の id.
	 * @param array $context `WPCV_Context_Builder::build()` と同じ形
	 *                        (version/plugins/plugin_dir/mu_plugin_dir/mu_plugins).
	 * @return array {
	 *     @type int   $run_id  引数の `$run_id` をそのまま返す(呼び出し元の利便性のため).
	 *     @type array $summary `WPCV_Verifier::summarize()` の戻り値.
	 * }
	 *
	 * @throws Throwable Plan・保存に失敗した場合の例外(`WPCV_Run_Starter::plan_and_save()`が
	 *                    run failed 化した上で再送出する)。個々の target の処理失敗は
	 *                    `WPCV_Chunk_Dispatcher::dispatch()` がtarget単位で吸収するため、
	 *                    ここへは伝播しない.
	 */
	public function run( $run_id, array $context ) {
		WPCV_Run_Starter::plan_and_save( $this->run_repository, $this->planner, $this->target_run_repository, $run_id, $context );

		do {
			$result = $this->dispatcher->dispatch( $run_id, $context );
		} while ( ! in_array( $result['action'], self::TERMINAL_ACTIONS, true ) );

		$summary = WPCV_Verifier::summarize( $this->target_run_repository->find_all_by_run( $run_id ) );

		return array(
			'run_id'  => $run_id,
			'summary' => $summary,
		);
	}
}
