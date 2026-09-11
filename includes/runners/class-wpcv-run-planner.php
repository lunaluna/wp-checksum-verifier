<?php
/**
 * WPCV_Run_Planner クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 実行開始時点でコア・公式プラグイン・MU プラグイン領域の target 一覧を列挙する
 * (v0.4.0 §Step2)。
 *
 * `WPCV_Run_Coordinator::run()`(v0.3.1までの一括実行)は「列挙」と「検証(manifest
 * 取得・ファイル比較)」が1メソッド内で交錯しており、chunk単位の分割実行
 * (v0.4.0 §Step3以降)の土台にできない。本クラスはそのうち「列挙」だけを
 * 切り出したもので、HTTP・ファイルシステムアクセスを一切行わない
 * (`$context['version']`・`$context['plugins']`・`$context['mu_plugins']` という
 * 既に取得済みの情報だけから target_id/dimension/slug/version/source を確定する).
 *
 * 返す target_run はすべて `WPCV_Target_Status::QUEUED` 状態のプレースホルダ
 * (`manifest_status`・`error_code`・`files_total` 等はまだ未確定の既定値)であり、
 * 実際の検証(manifest取得・ファイル比較)は行わない。Step3で実装する chunk
 * verifier が、この queued な target_run を1件ずつ claim して検証結果へ更新する.
 *
 * v0.3.1までの一括実行(`WPCV_Run_Coordinator`)は本クラス導入後もそのまま残る
 * (v0.4.0 §Step5で dispatcher 経由の実行方式へ接続するまでの間、互換動作として
 * 維持する)。列挙ロジックの重複を避けるため、`CORE_BUNDLED_PLUGIN_FILES` 定数と
 * `resolve_plugin_slug_and_root()` は `WPCV_Run_Coordinator` から本クラスへ移設し、
 * `WPCV_Run_Coordinator` 側はこのクラスの public メソッドを呼ぶ形にリファクタした
 * (挙動は変えていない).
 *
 * 日次 `scheduled_for` の重複防止(外部cron連打対策)と `.maintenance`/updater lock
 * 検出によるrun延期は、実際に使う呼び出し元(それぞれ§Step6の外部HTTP due判定、
 * §Step4以降のdispatcher)が無い状態で先取り実装すると設計の手戻りリスクが
 * 大きいため、Step2では実装しない(ユーザー確認済み。該当Stepで実装する).
 */
class WPCV_Run_Planner {

	/**
	 * コアの checksums に同梱され、プラグイン次元では二重に検証しないファイル(§3.2).
	 *
	 * `hello.php` は WordPress コアの配布物に含まれ、コアの checksums API が
	 * そのまま返す(`wp-content/plugins/hello.php` として)。プラグイン次元でも
	 * 列挙すると、同じファイルに対して2つの target が競合して存在することになる.
	 *
	 * @var string[]
	 */
	const CORE_BUNDLED_PLUGIN_FILES = array( 'hello.php' );

	/**
	 * コア・公式プラグイン・MU プラグイン領域の target 一覧を列挙する.
	 *
	 * @param array $context {
	 *     コンテキスト(`WPCV_Context_Builder::build()` の戻り値と同じ形).
	 *
	 *     @type string $version       ローカルの WordPress バージョン. 必須.
	 *     @type array  $plugins       `get_plugins()` と同じ形式(プラグインファイル
	 *                                 => ヘッダー配列。`Version` キーを読む). 既定は空配列.
	 *     @type string $plugin_dir    `WP_PLUGIN_DIR` の絶対パス。`$plugins` が空
	 *                                 でない場合は必須.
	 *     @type string $mu_plugin_dir `WPMU_PLUGIN_DIR` の絶対パス。省略時は
	 *                                 MU プラグイン領域を列挙しない.
	 *     @type array  $mu_plugins    `get_mu_plugins()` と同じ形式(ファイル名 =>
	 *                                 ヘッダー配列。キーのみ使う). 既定は空配列.
	 * }
	 * @return array `WPCV_Target_Status::QUEUED` 状態の target_run の配列
	 *               (id/run_id 無し。§5.3 のスキーマに準拠).
	 *
	 * @throws InvalidArgumentException 必須の version が指定されていない場合、または
	 *                                   plugins が空でないのに plugin_dir が指定されていない場合.
	 */
	public function plan( array $context ) {
		if ( empty( $context['version'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Run_Planner::plan() requires $context[\'version\'].' ) );
		}

		$plugins    = isset( $context['plugins'] ) ? (array) $context['plugins'] : array();
		$plugin_dir = isset( $context['plugin_dir'] ) ? (string) $context['plugin_dir'] : '';

		if ( ! empty( $plugins ) && '' === $plugin_dir ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Run_Planner::plan() requires $context[\'plugin_dir\'] when $context[\'plugins\'] is not empty.' ) );
		}

		$target_runs = array();

		$target_runs[] = self::queued_target_run(
			WPCV_Target_Resolver::DIMENSION_CORE,
			WPCV_Target_Resolver::DIMENSION_CORE,
			'wordpress', // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- WPCV_Verifier::verify_core() と同じ理由.
			(string) $context['version'],
			'wporg'
		);

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( in_array( (string) $plugin_file, self::CORE_BUNDLED_PLUGIN_FILES, true ) ) {
				continue;
			}

			$resolved       = self::resolve_plugin_slug_and_root( (string) $plugin_file, $plugin_dir );
			$plugin_version = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';

			$target_runs[] = self::queued_target_run(
				WPCV_Target_Resolver::build_id( WPCV_Target_Resolver::DIMENSION_PLUGIN, $resolved['slug'] ),
				WPCV_Target_Resolver::DIMENSION_PLUGIN,
				$resolved['slug'],
				'' === $plugin_version ? null : $plugin_version,
				'wporg'
			);
		}

		if ( ! empty( $context['mu_plugin_dir'] ) ) {
			$mu_plugins = isset( $context['mu_plugins'] ) ? (array) $context['mu_plugins'] : array();
			$dimension  = WPCV_Target_Resolver::DIMENSION_MUPLUGIN;

			// §3.6: loader ごとの target(wp.org/GitHub マッピング未実装のため、
			// 現時点では検証の結果は必ず unverifiable になるが、その判定自体は
			// Step3のchunk verifierが行う。ここではqueuedとして列挙するのみ).
			foreach ( array_keys( $mu_plugins ) as $basename ) {
				$target_runs[] = self::queued_target_run(
					WPCV_Target_Resolver::build_id( $dimension, (string) $basename ),
					$dimension,
					(string) $basename,
					null,
					null
				);
			}

			// サブディレクトリ配下の未知ファイル走査用の合成target
			// (`WPCV_Verifier::verify_muplugin_area()` の docblock 参照).
			$target_runs[] = self::queued_target_run(
				WPCV_Target_Resolver::build_id( $dimension, '_scan' ),
				$dimension,
				'_scan',
				null,
				null
			);
		}

		return $target_runs;
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
	public static function resolve_plugin_slug_and_root( $plugin_file, $plugin_dir ) {
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

	/**
	 * `WPCV_Target_Status::QUEUED` 状態の target_run を組み立てる.
	 *
	 * @param string      $target_id target_id.
	 * @param string      $dimension dimension.
	 * @param string      $slug      slug.
	 * @param string|null $version   version(不明なら null).
	 * @param string|null $source    source(照合ソースが未定のMU領域では null).
	 * @return array §5.3 のスキーマに準拠した target_run(id/run_id 無し).
	 */
	private static function queued_target_run( $target_id, $dimension, $slug, $version, $source ) {
		return array(
			'target_id'       => $target_id,
			'dimension'       => $dimension,
			'slug'            => $slug,
			'version'         => $version,
			'source'          => $source,
			'source_ref'      => null,
			'manifest_status' => 'missing',
			'status'          => WPCV_Target_Status::QUEUED,
			'error_code'      => null,
			'error_message'   => null,
			'files_total'     => 0,
			'files_verified'  => 0,
			'findings_total'  => 0,
		);
	}
}
