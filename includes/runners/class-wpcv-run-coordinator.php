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
 * 1回分の検証(run)のライフサイクルを統括する(§4.2: `runners/class-wpcv-run-coordinator.php`).
 *
 * `WPCV_Verifier`(検証ロジック)と `WPCV_Repository`(DB永続化)をつなぎ、コア・
 * 公式プラグイン・MU プラグイン領域すべてを検証して1回の run として保存する。
 * これが動くことで v0.2 がエンドツーエンド(検証サイトで同期実行が通る)になる
 * (プラン§14 の v0.2 完了条件)。
 *
 * `get_plugins()` / `get_mu_plugins()` / ローカルの WordPress バージョンといった
 * 実際の WordPress 環境からの読み取りはこのクラス自身では行わない。呼び出し側
 * (WP-Cron / WP-CLI / REST といった実行モデルのエントリポイント。§6、v0.3 対象)が
 * それらを解決して `$context` として渡す設計とし、このクラス自体は単体テストで
 * 実 WordPress 環境やテストダブルの大きな面積を必要としないようにしている.
 */
class WPCV_Run_Coordinator {

	/**
	 * コアの checksums に同梱され、プラグイン次元では二重に検証しないファイル(§3.2).
	 *
	 * `hello.php` は WordPress コアの配布物に含まれ、コアの checksums API が
	 * そのまま返す(`wp-content/plugins/hello.php` として)。プラグイン次元でも
	 * 検証すると、同じファイルに対して2つの target が競合して存在することになる.
	 *
	 * @var string[]
	 */
	const CORE_BUNDLED_PLUGIN_FILES = array( 'hello.php' );

	/**
	 * 検証エンジン.
	 *
	 * @var WPCV_Verifier
	 */
	private $verifier;

	/**
	 * DB 永続化層.
	 *
	 * @var WPCV_Repository
	 */
	private $repository;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Verifier   $verifier   検証エンジン.
	 * @param WPCV_Repository $repository DB 永続化層.
	 */
	public function __construct( WPCV_Verifier $verifier, WPCV_Repository $repository ) {
		$this->verifier   = $verifier;
		$this->repository = $repository;
	}

	/**
	 * コア・公式プラグイン・MU プラグイン領域を検証し、1回の run として保存する.
	 *
	 * `$run_id` は呼び出し元が `WPCV_Repository::reserve_run()`(必要なら
	 * `mark_queued_running()` で `queued` から引き継いで)で事前に予約した、
	 * 既に `running` である run の id を渡すこと(v0.3.1 §Step1: run 行の作成を
	 * 検証完了後ではなく受付時点に移した。プランP0「run行が検証完了後まで
	 * 作られない」への対策。このクラス自身はもう run 行を作成しない).
	 *
	 * バリデーション・検証・保存のいずれかで例外が発生した場合、`$run_id` の
	 * run を `mark_run_failed()` で failed 化してから例外を再送出する(検証途中の
	 * 例外・タイムアウトでも失敗記録が残らない、というプランP0の不具合への対策).
	 *
	 * @param int   $run_id  呼び出し元が予約済みの(`running` 状態の)run の id.
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string $version       ローカルの WordPress バージョン. 必須.
	 *     @type array  $plugins       `get_plugins()` と同じ形式(プラグインファイル
	 *                                 => ヘッダー配列。`Version` キーを読む). 既定は空配列.
	 *     @type string $plugin_dir    `WP_PLUGIN_DIR` の絶対パス。`$plugins` が空
	 *                                 でない場合は必須.
	 *     @type string $mu_plugin_dir `WPMU_PLUGIN_DIR` の絶対パス。省略時は
	 *                                 MU プラグイン領域の検証をスキップする.
	 *     @type array  $mu_plugins    `get_mu_plugins()` と同じ形式(ファイル名 =>
	 *                                 ヘッダー配列。キーのみ使う). 既定は空配列.
	 * }
	 * @return array {
	 *     @type int   $run_id  引数の `$run_id` をそのまま返す(呼び出し元の利便性のため).
	 *     @type array $summary `WPCV_Verifier::summarize()` の戻り値.
	 * }
	 *
	 * @throws InvalidArgumentException 必須の version が指定されていない場合、または
	 *                                   plugins が空でないのに plugin_dir が指定されていない場合.
	 * @throws Throwable 検証・保存処理中に発生した例外(failed 記録後に再送出).
	 */
	public function run( $run_id, array $context ) {
		try {
			if ( empty( $context['version'] ) ) {
				throw new InvalidArgumentException( esc_html( 'WPCV_Run_Coordinator::run() requires $context[\'version\'].' ) );
			}

			$plugins    = isset( $context['plugins'] ) ? (array) $context['plugins'] : array();
			$plugin_dir = isset( $context['plugin_dir'] ) ? (string) $context['plugin_dir'] : '';

			if ( ! empty( $plugins ) && '' === $plugin_dir ) {
				throw new InvalidArgumentException( esc_html( 'WPCV_Run_Coordinator::run() requires $context[\'plugin_dir\'] when $context[\'plugins\'] is not empty.' ) );
			}

			$target_runs = array();
			$findings    = array();

			$core_result   = $this->verifier->verify_core( array( 'version' => (string) $context['version'] ) );
			$target_runs[] = $core_result['target_run'];
			$findings      = array_merge( $findings, $core_result['findings'] );

			foreach ( $plugins as $plugin_file => $plugin_data ) {
				if ( in_array( (string) $plugin_file, self::CORE_BUNDLED_PLUGIN_FILES, true ) ) {
					continue;
				}

				$resolved       = self::resolve_plugin_slug_and_root( (string) $plugin_file, $plugin_dir );
				$plugin_version = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';

				$plugin_result = $this->verifier->verify_plugin(
					array(
						'slug'            => $resolved['slug'],
						'version'         => $plugin_version,
						'plugin_root_dir' => $resolved['plugin_root_dir'],
					)
				);

				$target_runs[] = $plugin_result['target_run'];
				$findings      = array_merge( $findings, $plugin_result['findings'] );
			}

			if ( ! empty( $context['mu_plugin_dir'] ) ) {
				$mu_plugins       = isset( $context['mu_plugins'] ) ? (array) $context['mu_plugins'] : array();
				$mu_plugin_result = $this->verifier->verify_muplugin_area(
					array(
						'mu_plugin_dir' => (string) $context['mu_plugin_dir'],
						'loaders'       => array_keys( $mu_plugins ),
					)
				);

				$target_runs = array_merge( $target_runs, $mu_plugin_result['target_runs'] );
				$findings    = array_merge( $findings, $mu_plugin_result['findings'] );
			}

			$summary = WPCV_Verifier::summarize( $target_runs );

			$target_run_ids = $this->repository->save_target_runs( $run_id, $target_runs );

			$this->repository->save_findings( $run_id, $target_run_ids, $findings );
			$this->repository->finish_run( $run_id, $summary );

			return array(
				'run_id'  => $run_id,
				'summary' => $summary,
			);
		} catch ( Throwable $e ) {
			$this->repository->mark_run_failed( $run_id, get_class( $e ) . ': ' . $e->getMessage() );

			throw $e;
		}
	}

	/**
	 * `get_plugins()` のキー(プラグインファイル)から slug と検証の基準ディレクトリを求める.
	 *
	 * ディレクトリ型プラグイン(`{slug}/{file}.php`)は `{slug}` をそのまま使う。
	 * 単一ファイルプラグイン(`{file}.php`。スラッシュを含まない)は wp.org 上の
	 * slug がファイル名と一致するとは限らないため、ファイル名から拡張子を除いた
	 * ものをベストエフォートで slug として使う(§3.4 のメモに記載済みの既知の限界.
	 * 一致しない場合は `WPCV_Source_Wporg_Plugin` が `manifest_not_found` を返し、
	 * unverifiable として記録されるだけなので、誤った `modified` 警告にはならない).
	 *
	 * @param string $plugin_file `get_plugins()` のキー.
	 * @param string $plugin_dir  `WP_PLUGIN_DIR` の絶対パス.
	 * @return array{slug: string, plugin_root_dir: string}
	 */
	private static function resolve_plugin_slug_and_root( $plugin_file, $plugin_dir ) {
		$plugin_dir = rtrim( $plugin_dir, '/' );

		if ( false !== strpos( $plugin_file, '/' ) ) {
			$slug = strstr( $plugin_file, '/', true );

			return array(
				'slug'            => $slug,
				'plugin_root_dir' => $plugin_dir . '/' . $slug,
			);
		}

		return array(
			'slug'            => pathinfo( $plugin_file, PATHINFO_FILENAME ),
			'plugin_root_dir' => $plugin_dir,
		);
	}
}
