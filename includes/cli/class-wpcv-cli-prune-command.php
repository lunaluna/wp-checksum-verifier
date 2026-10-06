<?php
/**
 * WPCV_CLI_Prune_Command クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp wpcv prune` コマンド(v0.10.0. 保持期間を過ぎた履歴を今すぐ削除する).
 *
 * 削除の判定は run の終端の自動削除と同じ `WPCV_Retention_Cleaner::prune()` に任せる
 * (I1〜I5 の「残すもの」を守る. 判定を2か所に持たない). 保持期間は設定画面の値に従う
 * (`--months` のような別の期間を渡すオプションは付けない: 設定を変えずに消せると、画面の設定と
 * 実際が食い違って分かりにくいため. 0.10.0 プラン §8.3).
 *
 * WP-CLI のメッセージは翻訳しない(既存の `wp wpcv run` と同じ方針).
 */
class WPCV_CLI_Prune_Command {

	/**
	 * 繰り返しの回数の上限. 1回の `prune()` が上限(`MAX_TARGET_RUNS_PER_CALL`)で止まるたびに続けるが、
	 * 想定外の状態で無限に回らないための安全装置(通常は 1 回ごとに必ず 1 件以上消えるので届かない).
	 *
	 * @var int
	 */
	const MAX_ITERATIONS = 100000;

	/**
	 * 削除処理(テストで差し替える. 省略時は `WPCV_Plugin::retention_cleaner()`).
	 *
	 * @var WPCV_Retention_Cleaner|null
	 */
	private $cleaner;

	/**
	 * コンストラクタ. WP-CLI は引数なしで生成する.
	 *
	 * @param WPCV_Retention_Cleaner|null $cleaner 削除処理. テストで差し替える.
	 */
	public function __construct( ?WPCV_Retention_Cleaner $cleaner = null ) {
		$this->cleaner = $cleaner;
	}

	/**
	 * 保持期間を過ぎた履歴を今すぐ削除する.
	 *
	 * 設定画面の「履歴の保持期間」に従い、期限を過ぎた実行履歴・対象ごとの結果・検出結果・
	 * 失効した抑制を削除する. 各対象の最新の照合結果、処理中の結果、アラートの重複を避けるために
	 * 必要な記録は残す. 保持期間が「無期限に保持」のときは何も削除しない.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : 何も削除せず、削除することになる件数だけを表示する(件数が多いと時間がかかる).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcv prune --dry-run
	 *     wp wpcv prune
	 *
	 * @param array $args       位置引数(未使用).
	 * @param array $assoc_args 連想引数(`--dry-run`).
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$months = WPCV_Settings::get_retention_months();

		if ( $months < 1 ) {
			WP_CLI::success( '履歴の保持期間が「無期限に保持」のため、何も削除しません.' );
			return;
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );
		$cleaner = $this->cleaner ?? WPCV_Plugin::retention_cleaner();
		$total   = array(
			'suppressions' => 0,
			'target_runs'  => 0,
			'findings'     => 0,
			'runs'         => 0,
		);

		try {
			for ( $i = 0; $i < self::MAX_ITERATIONS; $i++ ) {
				$result = $cleaner->prune( $months, 0, $dry_run );

				// 別の削除(run の終わりの自動削除・管理画面のジョブ)が実行中で、lock が取れなかった
				// (v0.10.0. コードレビュー指摘2). 同時に消すと DB の負荷が倍になるので、ここで止める.
				if ( ! empty( $result['locked'] ) ) {
					WP_CLI::error( '別の削除(実行の終わりの自動削除、または管理画面の「古い履歴を今すぐ削除」)が実行中です. 終わってからもう一度実行してください. ここまでの合計: ' . self::format_counts( $total ) );
					return;
				}

				foreach ( array_keys( $total ) as $key ) {
					$total[ $key ] += $result[ $key ];
				}

				// dry-run は1回で最後まで数える. 実削除は上限で止まる間だけ続ける.
				if ( $dry_run || ! $result['remaining'] ) {
					break;
				}

				WP_CLI::line( '続きがあるため、もう一度実行します: ' . self::format_counts( $total ) );
			}
		} catch ( Throwable $e ) {
			WP_CLI::error( '削除に失敗しました: ' . $e->getMessage() );
			return;
		}

		if ( ! $dry_run && ! empty( $result['remaining'] ) ) {
			WP_CLI::error( '繰り返しの上限に達したため中断しました. もう一度実行してください. ここまでの合計: ' . self::format_counts( $total ) );
			return;
		}

		WP_CLI::success(
			sprintf(
				$dry_run ? '[dry-run] 保持期間 %d か月を過ぎた履歴(削除する予定): %s' : '保持期間 %d か月を過ぎた履歴を削除しました: %s',
				$months,
				self::format_counts( $total )
			)
		);
	}

	/**
	 * 件数を1行の文字列に整形する(`__invoke()` から分離してテスト可能にする).
	 *
	 * @param array $counts `suppressions`・`target_runs`・`findings`・`runs` の件数.
	 * @return string
	 */
	public static function format_counts( array $counts ) {
		return sprintf(
			'runs: %d, target_runs: %d, findings: %d, suppressions: %d',
			$counts['runs'],
			$counts['target_runs'],
			$counts['findings'],
			$counts['suppressions']
		);
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'wpcv prune', 'WPCV_CLI_Prune_Command' );
}
