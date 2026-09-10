<?php
/**
 * WPCV_CLI_Command クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wpcv run` コマンド(§6.2: CLI は実行時間制限が無く同期が最も確実なため、
 * 同期実行を既定にする)。
 *
 * `WP_CLI_Command` は継承しない(単一のリーフコマンドのみで、サブコマンドを
 * 束ねるための umbrella クラスが不要なため。`__invoke()` を持つプレーンな
 * クラスとして `WP_CLI::add_command()` に渡す WP-CLI の一般的な最小構成).
 */
class WPCV_CLI_Command {

	/**
	 * コア・公式プラグイン・MU プラグイン領域を検証し、run を実行する.
	 *
	 * ## OPTIONS
	 *
	 * [--async]
	 * : `WPCV_Runner_Async::enqueue_run()` 経由で Action Scheduler のキューに
	 *   enqueue する(既定の同期実行はこの関数を経由しない。§6の「CLIは同期が
	 *   最も確実」という方針をそのまま尊重するため)。Action Scheduler が利用
	 *   できない環境では自動的に同期実行へフォールバックする.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcv run
	 *     wp wpcv run --async
	 *
	 * @param array $args        位置引数(未使用).
	 * @param array $assoc_args  連想引数(`--async`).
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		if ( ! empty( $assoc_args['async'] ) ) {
			$this->run_async();
			return;
		}

		// 検証開始前に実行権(run 行)を予約する(v0.3.1 §Step1: `WPCV_Run_Coordinator::run()`
		// はもう run 行を作らないため、同期呼び出し元が必ず先に予約すること。
		// `WPCV_Run_Coordinator` の docblock 参照).
		$reservation = WPCV_Plugin::repository()->reserve_run(
			array(
				'run_trigger' => 'cli',
				'runner'      => 'sync',
			)
		);

		if ( $reservation['lock_failed'] ) {
			WP_CLI::error( '実行権の予約に失敗しました(lock取得失敗)。しばらくしてから再実行してください.' );
			return;
		}

		if ( $reservation['active'] ) {
			WP_CLI::error(
				sprintf(
					'既に実行中の run があります(run #%d)。完了を待ってから再実行してください.',
					$reservation['run_id']
				)
			);
			return;
		}

		$context = WPCV_Context_Builder::build( 'cli' );

		try {
			$result = WPCV_Plugin::run_coordinator()->run( $reservation['run_id'], $context );
		} catch ( InvalidArgumentException $e ) {
			// 例外メッセージは固定文言のみで動的値を含まない.
			// esc_html() での保護は DB 出力等の呼び出し元向けであり、CLI 標準出力への
			// 表示自体は対象外(WordPress.Security.EscapeOutput はここに反応しない).
			WP_CLI::error( $e->getMessage() );
			return;
		}

		self::report_result( $result );
	}

	/**
	 * `--async` 指定時の処理. `WPCV_Runner_Async::enqueue_run()` を呼び、
	 * enqueue できたか同期フォールバックしたかに応じて出力を切り替える.
	 *
	 * @return void
	 */
	private function run_async() {
		$enqueue_result = WPCV_Runner_Async::enqueue_run( 'cli' );

		if ( $enqueue_result['enqueued'] ) {
			WP_CLI::success(
				sprintf(
					'run をキューに追加しました(action_id: %d)。`wp action-scheduler run` または次回の cron 実行で処理されます.',
					$enqueue_result['action_id']
				)
			);
			return;
		}

		WP_CLI::line( 'Action Scheduler が利用できないため、同期実行にフォールバックしました.' );
		self::report_result( $enqueue_result['result'] );
	}

	/**
	 * `run()` の結果を出力する(`__invoke()` から分離してテスト可能にする).
	 *
	 * @param array $result `WPCV_Run_Coordinator::run()` の戻り値.
	 * @return void
	 */
	private static function report_result( array $result ) {
		WP_CLI::line( self::format_result( $result ) );
		WP_CLI::success(
			sprintf(
				'run #%d が完了しました(status: %s).',
				$result['run_id'],
				$result['summary']['status']
			)
		);
	}

	/**
	 * `run()` の戻り値を1行の要約文字列に整形する(`report_result()` から分離してテスト可能にする).
	 *
	 * @param array $result `WPCV_Run_Coordinator::run()` の戻り値.
	 * @return string
	 */
	public static function format_result( array $result ) {
		$summary = $result['summary'];

		return sprintf(
			'targets: %d total / %d verified / %d unverifiable / %d failed, findings: %d',
			$summary['targets_total'],
			$summary['targets_verified'],
			$summary['targets_unverifiable'],
			$summary['targets_failed'],
			$summary['findings_total']
		);
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'wpcv run', 'WPCV_CLI_Command' );
}
