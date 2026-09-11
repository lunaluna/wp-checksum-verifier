<?php
/**
 * WPCV_Chunk_Dispatcher クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chunk分割実行の中核。DBからclaim可能な target_run を1件取得して1chunk分だけ
 * 処理し、続きが必要なら自分自身を再度呼び出す「wake-up」を予約する
 * (v0.4.0 §Step4)。
 *
 * プラン§Step4の設計方針どおり、Action Scheduler の queue 空判定・claim状態を
 * 進捗の正本にはしない。正本は常に `wpcv_target_runs` テーブル(status・cursor・
 * lease・attempt_count)であり、AS action はこのクラスを「起こす」ための手段に
 * 過ぎない(同じ理由でREST/WP-Cron/CLI asyncも将来同じ `dispatch()` を呼ぶ設計だが、
 * それらの繋ぎ替えはStep5・6で行う。Step4時点では本クラスはAction Scheduler
 * 経由でのみ到達可能で、既存のCLI同期・REST・WP-Cron・asyncフォールバックが
 * 使っている一括 `WPCV_Run_Coordinator` には一切手を入れていない).
 *
 * `dispatch()` 1回の責務は次のいずれか1つだけ:
 *
 * 1. Lease切れ(stale worker)の掃除(`sweep_expired_leases()`)
 * 2. Run自体のdeadline超過を検知して `aborted` へ倒す
 * 3. Claim可能な target_run を1件claimし、1chunk分処理する
 * 4. Claim対象が無ければ、全target_runが終端状態かどうかを見て run を確定する
 *    (終端でなければ、他workerの処理待ちとして遅延re-checkを予約するだけ)
 *
 * `$context`(version/plugins/plugin_dir/mu_plugin_dir/mu_plugins)は
 * `WPCV_Runner_Async` と同じ理由(AS の args 8,000文字制限。
 * `WPCV_Runner_Async` のクラス docblock 参照)で呼び出し元が毎回
 * `WPCV_Context_Builder::build()` 等で組み立て直したものを渡す設計とし、
 * このクラス自身は保持しない。これには副作用として、plugin対象の
 * `plugin_root_dir`・現在のversionを毎回「実行時点の最新状態」から再解決する
 * ことになり、実行中にプラグインが更新された場合の検知(§8.5)にも寄与する
 * (`resolve_current_plugin_context()` 参照)。
 */
class WPCV_Chunk_Dispatcher {

	/**
	 * Chunk処理後に「続きがある」ことを知らせる Action Scheduler フック名.
	 *
	 * @var string
	 */
	const HOOK = 'wpcv_dispatch_chunk';

	/**
	 * Action Scheduler へ enqueue するときの group(`WPCV_Runner_Async::GROUP` と同じ値).
	 *
	 * @var string
	 */
	const GROUP = 'wpcv';

	/**
	 * `verify_manifest_chunk()`/`verify_unknown_files_chunk()` に渡す既定の予算.
	 *
	 * 未実測: 暫定値。実測の上で見直すこと(§数値を決める前に実測するルール)。
	 * `max_seconds` は典型的な共有ホスティングの `max_execution_time`(30〜60秒。
	 * プラン§2.2)よりかなり短く取り、manifest取得のHTTP往復・DB書き込みの
	 * オーバーヘッド分の余裕を残す.
	 *
	 * @var int
	 */
	const DEFAULT_BUDGET_MAX_SECONDS = 20;

	/**
	 * `verify_manifest_chunk()`/`verify_unknown_files_chunk()` に渡す既定のファイル件数上限.
	 *
	 * 未実測: 暫定値.
	 *
	 * @var int
	 */
	const DEFAULT_BUDGET_MAX_FILES = 500;

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
	 * Chunk結果をtransactionで確定する調整役.
	 *
	 * @var WPCV_Chunk_Result_Repository
	 */
	private $chunk_result_repository;

	/**
	 * Chunk単位のmanifest比較・未知ファイル走査エンジン.
	 *
	 * @var WPCV_Chunk_Verifier
	 */
	private $chunk_verifier;

	/**
	 * コアの checksum マニフェスト取得ソース.
	 *
	 * @var WPCV_Manifest_Source
	 */
	private $core_source;

	/**
	 * 公式プラグインの checksum マニフェスト取得ソース.
	 *
	 * @var WPCV_Manifest_Source
	 */
	private $plugin_source;

	/**
	 * 未知ファイル走査エンジン.
	 *
	 * @var WPCV_Unknown_File_Scanner
	 */
	private $scanner;

	/**
	 * `claim_next()` に渡す一意な lease owner 文字列を生成する callable.
	 *
	 * @var callable
	 */
	private $lease_owner_factory;

	/**
	 * 「続きがある」ことを知らせる(継続をenqueueする)callable.
	 *
	 * `function( int $run_id, int $delay_seconds ): void`。`$delay_seconds` が
	 * 0 なら即時enqueue、正の値なら `time() + $delay_seconds` に単発予約する.
	 *
	 * 単体テストで実際の Action Scheduler 関数を必要とせずに済むよう、
	 * `WPCV_Runner_Async::enqueue_run()` の `$availability_checker` と同じ理由で
	 * 注入可能にしている.
	 *
	 * @var callable
	 */
	private $continuation_scheduler;

	/**
	 * 現在時刻(Unix timestamp)を返す callable(deadline超過判定に使う).
	 *
	 * `WPCV_Run_Repository`/`WPCV_Target_Run_Repository` と同じ理由(テストで
	 * 固定時刻を注入できるようにするため)で引数で差し替え可能にする。これを
	 * 固定 `time()` にすると、テストが固定の過去日時を `$wpdb` の `now` callable
	 * に注入していてもdeadline判定だけは実際の壁時計時刻を見てしまい、
	 * `deadline_at`(固定の過去日時 + 6時間)が常に「過去」と誤判定されて
	 * すべてのrunが即座に `aborted` になる、という実際に踏んだ不具合がある
	 * (Repository群の `now` callableとdeadline判定の時刻源がずれていたため).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Run_Repository          $run_repository           `wpcv_runs` の永続化層.
	 * @param WPCV_Target_Run_Repository   $target_run_repository    `wpcv_target_runs` の永続化層.
	 * @param WPCV_Chunk_Result_Repository $chunk_result_repository  Chunk結果の確定役.
	 * @param WPCV_Chunk_Verifier          $chunk_verifier           Chunk単位の検証エンジン.
	 * @param WPCV_Manifest_Source         $core_source              §3.2 コア照合ソース.
	 * @param WPCV_Manifest_Source         $plugin_source            §3.4 公式プラグイン照合ソース.
	 * @param WPCV_Unknown_File_Scanner    $scanner                  §3.3 未知ファイル走査エンジン.
	 * @param callable|null                $lease_owner_factory      省略時は `uniqid( 'wpcv_', true )`.
	 * @param callable|null                $continuation_scheduler   省略時は Action Scheduler の
	 *                                                                `as_enqueue_async_action()`/
	 *                                                                `as_schedule_single_action()`
	 *                                                                (利用不可なら何もしない).
	 * @param callable|null                $now                      現在時刻(Unix timestamp)を
	 *                                                                返す callable. 省略時は `time()`.
	 */
	public function __construct(
		WPCV_Run_Repository $run_repository,
		WPCV_Target_Run_Repository $target_run_repository,
		WPCV_Chunk_Result_Repository $chunk_result_repository,
		WPCV_Chunk_Verifier $chunk_verifier,
		WPCV_Manifest_Source $core_source,
		WPCV_Manifest_Source $plugin_source,
		WPCV_Unknown_File_Scanner $scanner,
		?callable $lease_owner_factory = null,
		?callable $continuation_scheduler = null,
		?callable $now = null
	) {
		$this->run_repository          = $run_repository;
		$this->target_run_repository   = $target_run_repository;
		$this->chunk_result_repository = $chunk_result_repository;
		$this->chunk_verifier          = $chunk_verifier;
		$this->core_source             = $core_source;
		$this->plugin_source           = $plugin_source;
		$this->scanner                 = $scanner;

		$this->lease_owner_factory = $lease_owner_factory ?? static function () {
			return uniqid( 'wpcv_', true );
		};

		$this->continuation_scheduler = $continuation_scheduler ?? array( __CLASS__, 'schedule_via_action_scheduler' );

		$this->now = $now ?? static function () {
			return time();
		};
	}

	/**
	 * 1回分のdispatchを行う(クラス docblock 参照).
	 *
	 * @param int   $run_id  対象の run の id.
	 * @param array $context `WPCV_Context_Builder::build()` と同じ形
	 *                        (version/plugins/plugin_dir/mu_plugin_dir/mu_plugins).
	 * @return array{action: string} 少なくとも `action` キーを持つ結果
	 *               (`run_not_found`|`run_already_terminal`|`aborted`|
	 *               `run_finalized`|`waiting`|`processed`。テスト・観測用).
	 */
	public function dispatch( $run_id, array $context ) {
		$run_id = (int) $run_id;

		$this->target_run_repository->sweep_expired_leases( $run_id );

		$run = $this->run_repository->find_by_id( $run_id );

		if ( null === $run ) {
			return array( 'action' => 'run_not_found' );
		}

		if ( ! WPCV_Run_Status::is_active( $run['status'] ) ) {
			// 既に終端に達している(他workerが先に確定させた、stale
			// sweepでfailed化された等)。継続をenqueueしても意味が無いため、
			// ここで静かに終わる(重複配送されたAS actionのno-op).
			return array(
				'action' => 'run_already_terminal',
				'status' => $run['status'],
			);
		}

		if ( ! empty( $run['deadline_at'] ) && $this->is_past( $run['deadline_at'] ) ) {
			$this->run_repository->mark_run_aborted( $run_id, 'run deadline を超過したため aborted にしました.' );
			$this->target_run_repository->abort_non_terminal_for_run( $run_id );

			return array( 'action' => 'aborted' );
		}

		$lease_owner = call_user_func( $this->lease_owner_factory );
		$claimed     = $this->target_run_repository->claim_next( $run_id, $lease_owner );

		if ( null === $claimed ) {
			return $this->handle_no_claimable_target( $run_id );
		}

		try {
			$this->process_claimed_target( $run_id, $claimed, $context );
		} catch ( Throwable $e ) {
			// 個別targetの処理失敗でrun全体を止めない(他のtargetは処理を
			// 継続できるため)。`WPCV_Run_Coordinator::run()` がrun全体を
			// failedにするのとは異なるレイヤーの判断(こちらはtarget単位).
			$this->target_run_repository->finalize_immediate(
				$claimed['id'],
				array(
					'status'        => WPCV_Target_Status::FAILED,
					'error_message' => get_class( $e ) . ': ' . $e->getMessage(),
				)
			);
		}

		$this->schedule_continuation( $run_id, 0 );

		return array(
			'action'    => 'processed',
			'target_id' => $claimed['target_id'],
		);
	}

	/**
	 * Claim対象が無かった場合の分岐(run確定判定、または待機).
	 *
	 * @param int $run_id 対象の run の id.
	 * @return array{action: string}
	 */
	private function handle_no_claimable_target( $run_id ) {
		$target_runs = $this->target_run_repository->find_all_by_run( $run_id );

		foreach ( $target_runs as $target_run ) {
			if ( ! WPCV_Target_Status::is_terminal( $target_run['status'] ) ) {
				// 他workerがまだ処理中(leaseがまだ有効)。次回のsweepで
				// stale判定できるよう、lease有効期間相当の遅延で自分自身を
				// 再度起こしておく(誰も呼ばなくなって永久に停止することを防ぐ).
				$this->schedule_continuation( $run_id, WPCV_Target_Run_Repository::DEFAULT_LEASE_SECONDS );

				return array( 'action' => 'waiting' );
			}
		}

		$summary = WPCV_Verifier::summarize( $target_runs );
		$this->run_repository->finish_run( $run_id, $summary );

		return array(
			'action'  => 'run_finalized',
			'summary' => $summary,
		);
	}

	/**
	 * Claimしたtarget_runを、dimension/slugに応じた処理へ振り分ける.
	 *
	 * @param int   $run_id      対象の run の id.
	 * @param array $target_run  `claim_next()` が返した target_run 行.
	 * @param array $context     `dispatch()` に渡された `$context`.
	 * @return void
	 */
	private function process_claimed_target( $run_id, array $target_run, array $context ) {
		$dimension = $target_run['dimension'];
		$slug      = $target_run['slug'];

		if ( WPCV_Target_Resolver::DIMENSION_CORE === $dimension && '_scan' === $slug ) {
			$this->process_core_scan( $run_id, $target_run, $context );
			return;
		}

		if ( WPCV_Target_Resolver::DIMENSION_CORE === $dimension ) {
			$this->process_manifest_chunk(
				$run_id,
				$target_run,
				$this->core_source,
				array( 'version' => (string) $context['version'] ),
				rtrim( ABSPATH, '/' ),
				(string) $context['version'],
				'wporg'
			);
			return;
		}

		if ( WPCV_Target_Resolver::DIMENSION_MUPLUGIN === $dimension && '_scan' === $slug ) {
			$this->process_muplugin_scan( $run_id, $target_run, $context );
			return;
		}

		if ( WPCV_Target_Resolver::DIMENSION_MUPLUGIN === $dimension ) {
			// §3.6: wp.org/GitHub マッピング未実装のため、loaderは常にunverifiable/
			// unknown_source(`WPCV_Verifier::verify_muplugin_area()` と同じ挙動).
			// chunk処理を伴わないため即時終端化する.
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'status'     => WPCV_Target_Status::UNVERIFIABLE,
					'error_code' => WPCV_Error_Code::UNKNOWN_SOURCE,
				)
			);
			return;
		}

		if ( WPCV_Target_Resolver::DIMENSION_PLUGIN === $dimension ) {
			$this->process_plugin( $run_id, $target_run, $context );
			return;
		}

		// 現状(v0.4.0)ではtheme次元のtarget_runはplannerが列挙しないため
		// 到達しない想定だが、将来次元が増えた際に無言で無視しないよう明示的に
		// unverifiable/unknown_sourceで終端化しておく.
		$this->target_run_repository->finalize_immediate(
			$target_run['id'],
			array(
				'status'     => WPCV_Target_Status::UNVERIFIABLE,
				'error_code' => WPCV_Error_Code::UNKNOWN_SOURCE,
			)
		);
	}

	/**
	 * Core(manifest比較のみ。未知ファイル走査は`core:_scan`で別途処理)を処理する.
	 *
	 * @param int   $run_id     対象の run の id.
	 * @param array $target_run claim済みのtarget_run行.
	 * @param array $context    `dispatch()` に渡された `$context`.
	 * @return void
	 */
	private function process_core_scan( $run_id, array $target_run, array $context ) {
		$manifest = $this->core_source->get_manifest( array( 'version' => (string) $context['version'] ) );

		if ( null !== $manifest['error_code'] ) {
			// §16-D: マニフェストが取得できなければ、どのファイルが「既知」かを
			// 確定できないため、未知ファイル走査自体を行わない
			// (`WPCV_Verifier::verify_core()` の早期returnと同じ判断).
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'manifest_status' => $manifest['manifest_status'],
					'status'          => WPCV_Target_Status::UNVERIFIABLE,
					'error_code'      => $manifest['error_code'],
				)
			);
			return;
		}

		$scan_items = array();

		foreach ( WPCV_Verifier::core_unknown_file_areas() as $area ) {
			$scan_items = array_merge( $scan_items, $this->scanner->scan( $area['dir'], $manifest['files'], $area['args'] ) );
		}

		$this->process_scan_chunk( $run_id, $target_run, $scan_items, (string) $context['version'], 'wporg' );
	}

	/**
	 * Muplugin の合成走査target(`muplugin:_scan`)を処理する.
	 *
	 * @param int   $run_id     対象の run の id.
	 * @param array $target_run claim済みのtarget_run行.
	 * @param array $context    `dispatch()` に渡された `$context`.
	 * @return void
	 */
	private function process_muplugin_scan( $run_id, array $target_run, array $context ) {
		$mu_plugin_dir = isset( $context['mu_plugin_dir'] ) ? (string) $context['mu_plugin_dir'] : '';

		if ( '' === $mu_plugin_dir ) {
			// WPMU_PLUGIN_DIR自体が定義されていない(実行時点で構成が変わった等)。
			// 走査対象が無いため、差分ゼロの成功として終端化する.
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array( 'status' => WPCV_Target_Status::SUCCESS )
			);
			return;
		}

		$mu_plugins  = isset( $context['mu_plugins'] ) ? (array) $context['mu_plugins'] : array();
		$known_files = WPCV_Verifier::known_muplugin_loader_files( $mu_plugin_dir, array_keys( $mu_plugins ) );

		$scan_items = $this->scanner->scan(
			$mu_plugin_dir,
			$known_files,
			array(
				'recursive'        => true,
				'php_severity'     => 'high',
				'non_php_severity' => 'medium',
			)
		);

		// §5.5: findings.version は NOT NULL のため空文字列にする
		// (`WPCV_Verifier::verify_muplugin_area()` の合成targetと同じ規約).
		$this->process_scan_chunk( $run_id, $target_run, $scan_items, '', 'none' );
	}

	/**
	 * 公式プラグイン1件を処理する。現在の `$context['plugins']` から、
	 * target_run.slug と一致する plugin_file を解決し直す
	 * (クラス docblock 「実行時点の最新状態を再解決する」参照).
	 *
	 * @param int   $run_id     対象の run の id.
	 * @param array $target_run claim済みのtarget_run行.
	 * @param array $context    `dispatch()` に渡された `$context`.
	 * @return void
	 */
	private function process_plugin( $run_id, array $target_run, array $context ) {
		$resolved = $this->resolve_current_plugin_context( $context, $target_run['slug'] );

		if ( null === $resolved ) {
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'status'     => WPCV_Target_Status::UNVERIFIABLE,
					'error_code' => WPCV_Error_Code::TARGET_MISSING,
				)
			);
			return;
		}

		$this->process_manifest_chunk(
			$run_id,
			$target_run,
			$this->plugin_source,
			array(
				'slug'    => $target_run['slug'],
				'version' => $resolved['version'],
			),
			$resolved['plugin_root_dir'],
			$resolved['version'],
			'wporg'
		);
	}

	/**
	 * `$context['plugins']` から、指定 slug に解決される plugin_file を探す.
	 *
	 * @param array  $context `dispatch()` に渡された `$context`.
	 * @param string $slug    探したい slug(target_run.slug).
	 * @return array{version: string, plugin_root_dir: string}|null 見つからなければ `null`
	 *               (plan時点では存在したが、実行時点でローカルから消えている. §Step4).
	 */
	private function resolve_current_plugin_context( array $context, $slug ) {
		$plugins    = isset( $context['plugins'] ) ? (array) $context['plugins'] : array();
		$plugin_dir = isset( $context['plugin_dir'] ) ? (string) $context['plugin_dir'] : '';

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( in_array( (string) $plugin_file, WPCV_Run_Planner::CORE_BUNDLED_PLUGIN_FILES, true ) ) {
				continue;
			}

			$candidate = WPCV_Run_Planner::resolve_plugin_slug_and_root( (string) $plugin_file, $plugin_dir );

			if ( $candidate['slug'] === $slug ) {
				return array(
					'version'         => isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '',
					'plugin_root_dir' => $candidate['plugin_root_dir'],
				);
			}
		}

		return null;
	}

	/**
	 * Manifestベース(core/plugin)のchunk処理を1回分行う.
	 *
	 * @param int                  $run_id          対象の run の id.
	 * @param array                $target_run      claim済みのtarget_run行.
	 * @param WPCV_Manifest_Source $source          manifest取得ソース.
	 * @param array                $manifest_context `$source->get_manifest()` に渡すcontext.
	 * @param string               $base_dir        manifestの相対パスを解決する基準ディレクトリ.
	 * @param string               $version         今回dispatcherが観測した「現在の」version.
	 * @param string               $source_label     findings.source に記録する値(常に `wporg`).
	 * @return void
	 */
	private function process_manifest_chunk( $run_id, array $target_run, WPCV_Manifest_Source $source, array $manifest_context, $base_dir, $version, $source_label ) {
		$manifest = $source->get_manifest( $manifest_context );

		if ( null !== $manifest['error_code'] ) {
			$this->target_run_repository->finalize_immediate(
				$target_run['id'],
				array(
					'manifest_status' => $manifest['manifest_status'],
					'status'          => WPCV_Target_Status::UNVERIFIABLE,
					'error_code'      => $manifest['error_code'],
				)
			);
			return;
		}

		$chunk_result = $this->chunk_verifier->verify_manifest_chunk(
			array(
				'target_id'            => $target_run['target_id'],
				'dimension'            => $target_run['dimension'],
				'slug'                 => $target_run['slug'],
				'version'              => $version,
				'source'               => $source_label,
				'base_dir'             => $base_dir,
				'manifest_files'       => $manifest['files'],
				'cursor_path'          => $target_run['cursor_path'] ?? null,
				'previous_fingerprint' => $target_run['manifest_fingerprint'] ?? null,
				'previous_version'     => $target_run['version'],
				'budget'               => $this->default_budget(),
			)
		);

		$this->chunk_result_repository->commit_chunk( $run_id, $target_run['id'], $target_run['target_id'], $chunk_result, $version );
	}

	/**
	 * 未知ファイル走査ベース(core:_scan/muplugin:_scan)のchunk処理を1回分行う.
	 *
	 * @param int    $run_id      対象の run の id.
	 * @param array  $target_run  claim済みのtarget_run行.
	 * @param array  $scan_items  `WPCV_Unknown_File_Scanner::scan()` の戻り値.
	 * @param string $version     findings.version に記録する値.
	 * @param string $source_label findings.source に記録する値.
	 * @return void
	 */
	private function process_scan_chunk( $run_id, array $target_run, array $scan_items, $version, $source_label ) {
		$chunk_result = $this->chunk_verifier->verify_unknown_files_chunk(
			array(
				'target_id'            => $target_run['target_id'],
				'dimension'            => $target_run['dimension'],
				'slug'                 => $target_run['slug'],
				'version'              => $version,
				'source'               => $source_label,
				'scan_items'           => $scan_items,
				'cursor_path'          => $target_run['cursor_path'] ?? null,
				'previous_fingerprint' => $target_run['manifest_fingerprint'] ?? null,
				'budget'               => $this->default_budget(),
			)
		);

		$this->chunk_result_repository->commit_chunk( $run_id, $target_run['id'], $target_run['target_id'], $chunk_result );
	}

	/**
	 * `WPCV_Chunk_Verifier::verify_*_chunk()` に渡す既定の予算を組み立てる.
	 *
	 * @return array `max_files`/`max_seconds`/`memory_limit_bytes`(取得できる場合のみ).
	 */
	private function default_budget() {
		$budget = array(
			'max_files'   => self::DEFAULT_BUDGET_MAX_FILES,
			'max_seconds' => self::DEFAULT_BUDGET_MAX_SECONDS,
		);

		$memory_limit_bytes = self::memory_limit_bytes();

		if ( null !== $memory_limit_bytes ) {
			$budget['memory_limit_bytes'] = $memory_limit_bytes;
		}

		return $budget;
	}

	/**
	 * PHP の `memory_limit` ini設定をバイト数へ変換する(`-1`＝無制限の場合は `null`).
	 *
	 * @return int|null
	 */
	private static function memory_limit_bytes() {
		// `ini_get()` は該当キーが無い場合 `false` を返す(PHP自体の挙動)。
		// `(string) false` は空文字列になるため、直後の空文字列チェックへ
		// 素通しして問題ない(PHPStanが「常にfalse」と誤検知する === false との
		// 直接比較を避けるため、あえてこの経路にしている).
		$limit = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $limit || '-1' === $limit ) {
			return null;
		}

		$unit  = strtolower( substr( $limit, -1 ) );
		$value = (int) $limit;

		switch ( $unit ) {
			case 'g':
				return $value * 1024 * 1024 * 1024;
			case 'm':
				return $value * 1024 * 1024;
			case 'k':
				return $value * 1024;
			default:
				return $value;
		}
	}

	/**
	 * 与えられたMySQL DATETIME文字列(UTC)が現在時刻より過去かどうかを判定する.
	 *
	 * @param string $datetime `Y-m-d H:i:s` 形式のUTC日時文字列.
	 * @return bool
	 */
	private function is_past( $datetime ) {
		$timestamp = strtotime( (string) $datetime );

		return false !== $timestamp && $timestamp <= call_user_func( $this->now );
	}

	/**
	 * `$this->continuation_scheduler` を呼ぶ.
	 *
	 * @param int $run_id        対象の run の id.
	 * @param int $delay_seconds 0なら即時、正の値なら遅延.
	 * @return void
	 */
	private function schedule_continuation( $run_id, $delay_seconds ) {
		call_user_func( $this->continuation_scheduler, $run_id, $delay_seconds );
	}

	/**
	 * `$continuation_scheduler` の既定実装(実際の Action Scheduler 呼び出し).
	 *
	 * 可用性チェックを固定にせず毎回 `function_exists()`/`ActionScheduler::is_initialized()`
	 * で確認するのは `WPCV_Runner_Async::enqueue_run()` と同じ理由
	 * (PHPUnitプロセス内でのスタブ漏れ対策。同クラスのdocblock参照).
	 *
	 * @param int $run_id        対象の run の id.
	 * @param int $delay_seconds 0なら即時、正の値なら遅延.
	 * @return void
	 */
	private static function schedule_via_action_scheduler( $run_id, $delay_seconds ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! class_exists( 'ActionScheduler' ) || ! ActionScheduler::is_initialized() ) {
			return;
		}

		if ( $delay_seconds > 0 ) {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + (int) $delay_seconds, self::HOOK, array( (int) $run_id ), self::GROUP );
			}
			return;
		}

		as_enqueue_async_action( self::HOOK, array( (int) $run_id ), self::GROUP );
	}
}

add_action( WPCV_Chunk_Dispatcher::HOOK, array( 'WPCV_Plugin', 'dispatch_chunk' ), 10, 1 );
