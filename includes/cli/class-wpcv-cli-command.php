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
 * `--async` フラグは v0.3 Step 4 で追加する.
 */
class WPCV_CLI_Command {

	/**
	 * コア・公式プラグイン・MU プラグイン領域を検証し、同期的に1回の run を実行する.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcv run
	 *
	 * @param array $args        位置引数(未使用).
	 * @param array $assoc_args  連想引数(未使用. `--async` は Step 4 で追加).
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$context = WPCV_Context_Builder::build( 'cli' );

		try {
			$result = WPCV_Plugin::run_coordinator()->run( $context );
		} catch ( InvalidArgumentException $e ) {
			// 例外メッセージは固定文言のみで動的値を含まない.
			// esc_html() での保護は DB 出力等の呼び出し元向けであり、CLI 標準出力への
			// 表示自体は対象外(WordPress.Security.EscapeOutput はここに反応しない).
			WP_CLI::error( $e->getMessage() );
			return;
		}

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
	 * `run()` の戻り値を1行の要約文字列に整形する(`__invoke()` から分離してテスト可能にする).
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
