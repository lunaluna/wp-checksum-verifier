<?php
/**
 * WPCV_CLI_Bench_Stat_Command クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wpcv bench-stat` コマンド(v0.5 §4.2 Step4. rev.3 §3.9参照).
 *
 * Stat差分検知(層1)が共有ホスティングで成立するかを判断するための、
 * **読み取り専用**の計測コマンド。DBには一切書き込まない。実際の計測ロジックは
 * `WPCV_Stat_Bench` に分離してあり(単体テスト可能にするため)、このクラスは
 * WP-CLIとの接続(オプションのパース・出力整形)のみを担当する.
 *
 * `WPCV_CLI_Command`(`wp wpcv run`)と同様、単一のリーフコマンドのみのため
 * `WP_CLI_Command` は継承しない(そのクラスdocblock参照).
 */
class WPCV_CLI_Bench_Stat_Command {

	/**
	 * ディレクトリ配下のstatスループットを計測する.
	 *
	 * ## OPTIONS
	 *
	 * --dir=<path>
	 * : 計測対象ディレクトリの絶対パス(例: `WP_PLUGIN_DIR`、大きめのプラグイン1つ、
	 *   `ABSPATH . 'wp-includes'`)。
	 *
	 * [--iterations=<n>]
	 * : `lstat`経路(本番と同じ経路)の計測回数. 既定3。1未満は1に切り上げる.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcv bench-stat --dir=/var/www/html/wp-content/plugins
	 *     wp wpcv bench-stat --dir=/var/www/html/wp-includes --iterations=5
	 *
	 * @param array $args       位置引数(未使用).
	 * @param array $assoc_args 連想引数(`--dir`必須・`--iterations`任意).
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		if ( empty( $assoc_args['dir'] ) ) {
			WP_CLI::error( '--dir を指定してください.' );
			return;
		}

		$dir = (string) $assoc_args['dir'];

		if ( ! is_dir( $dir ) ) {
			WP_CLI::error(
				sprintf(
					'指定されたディレクトリが存在しません: %s',
					esc_html( $dir )
				)
			);
			return;
		}

		$iterations = isset( $assoc_args['iterations'] ) ? (int) $assoc_args['iterations'] : 3;

		$result = ( new WPCV_Stat_Bench() )->measure( $dir, $iterations );

		self::report( $result );
	}

	/**
	 * 計測結果を出力する(`__invoke()` から分離してテスト可能にする).
	 *
	 * @param array $result `WPCV_Stat_Bench::measure()` の戻り値.
	 * @return void
	 */
	public static function report( array $result ) {
		WP_CLI::line( '--- lstat経路(本番と同じ経路。ディレクトリ走査込み) ---' );
		foreach ( $result['runs'] as $run ) {
			WP_CLI::line( sprintf( '--- iteration %d ---', $run['iteration'] ) );
			self::report_stats( 'lstat (cold)', $run['lstat_cold'] );
			self::report_stats( 'lstat (warm)', $run['lstat_warm'] );
		}

		WP_CLI::line( '--- lstat() 1回 vs filesize()+filectime()+filemtime() 3回呼びの比較(ファイル一覧確保後の関数呼び出しのみ) ---' );
		self::report_stats( 'lstat-only (cold)', $result['lstat_only_cold'] );
		self::report_stats( 'lstat-only (warm)', $result['lstat_only_warm'] );
		self::report_stats( 'triple-call (cold)', $result['triple_call_cold'] );
		self::report_stats( 'triple-call (warm)', $result['triple_call_warm'] );
	}

	/**
	 * 1件分の計測結果を1行に整形して出力する.
	 *
	 * @param string $label ラベル(`lstat (cold)` 等).
	 * @param array  $stats `WPCV_Stat_Bench` の `build_stats()` 形式.
	 * @return void
	 */
	private static function report_stats( $label, array $stats ) {
		WP_CLI::line(
			sprintf(
				'%s: entries=%d, seconds=%.4f, bytes=%d, entries/sec=%.1f, bytes/sec=%.0f',
				$label,
				$stats['entries'],
				$stats['seconds'],
				$stats['bytes'],
				$stats['entries_per_sec'],
				$stats['bytes_per_sec']
			)
		);
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'wpcv bench-stat', 'WPCV_CLI_Bench_Stat_Command' );
}
