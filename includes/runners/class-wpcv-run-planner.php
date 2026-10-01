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
 *
 * Step4(v0.4.0)で `core:_scan`(コアの未知ファイル走査専用の合成target。
 * `WPCV_Target_Resolver::build_id()` のdocblock参照)を追加した。これにより
 * `plan()` が返す target_run の件数が、Step2時点(coreは1件)から1件増えている
 * (core本体 + core:_scan の2件)。既存の一括実行(`WPCV_Run_Coordinator`)は
 * この合成targetを消費しないため無害だが、`plan()` の戻り値件数に依存する
 * テスト・呼び出し元は影響を受ける.
 *
 * Step8(v0.4.0)で `exclude_target` 抑制ルール(`WPCV_Suppression_Type::EXCLUDE_TARGET`)
 * の適用を追加した。列挙した各targetについて有効な `exclude_target` ルールが
 * あれば、`WPCV_Target_Status::QUEUED` ではなく `SKIPPED`(`error_code` は
 * `WPCV_Error_Code::EXCLUDED`)として作る。plan時点でスキップを確定させ、
 * chunk verifierへは一切回さない設計(ユーザー確認済み)。これにより、除外した
 * targetのmanifest取得・ファイルI/Oが一切発生しない.
 *
 * v0.5 §Step6で、plugin target と muplugin の loader target それぞれの直後に
 * stat 差分検知 target(`{dimension}:{slug}:_stat`)を無条件に列挙するようにした
 * (`queued_stat_target_run()` 参照)。本体の直後に置くのは、claim が id 昇順で
 * 行われるため、本体の処理が先に進み stat target が待たされにくくするため.
 * `plan()` の戻り値件数はそのぶん増える.
 * v0.5 §Step8 で、設定で stat 差分検知を無効にしていれば列挙しないようにした
 * (`$stat_detection_enabled`).
 * v0.6 §Step9 で、本体 target を持たない合成 target を2つ(`core:_config`・
 * `dropin:_stat`)、`core:_scan` の直後に常に列挙するようにした(プラン§5.3
 * L1・L8)。`$stat_detection_enabled` とは無関係の別の仕組みのため、その設定を
 * 見ずに常に列挙する。実在するファイルの絞り込みはここでは行わない(クラス
 * docblock「ファイルシステムアクセスを一切行わない」不変条件のまま。
 * `WPCV_Chunk_Dispatcher` が dispatch 時点で絞り込む).
 * v0.7 §Step3 で、プラグインの後にテーマ(`theme:{stylesheet}` と `:_stat`)を
 * 列挙するようにした(`$context['themes']`. テーマの数 × 2 件増える).
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
	 * `exclude_target` 抑制ルールの取得元.
	 *
	 * @var WPCV_Suppression_Repository
	 */
	private $suppression_repository;

	/**
	 * Stat 差分検知 target を列挙するか(v0.5 §Step8).
	 *
	 * 設定(`WPCV_Settings::get_stat_detection_enabled()`)を呼び出し元が渡す.
	 * このクラスがオプションを直接読まないのは、列挙を入力だけで決まる処理に
	 * 保ち、テストで設定を切り替えやすくするため.
	 *
	 * @var bool
	 */
	private $stat_detection_enabled;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Suppression_Repository $suppression_repository `exclude_target` 抑制ルールの取得元.
	 * @param bool                        $stat_detection_enabled stat 差分検知 target を列挙するか
	 *                                                            (v0.5 §Step8. 既定 true).
	 */
	public function __construct( WPCV_Suppression_Repository $suppression_repository, $stat_detection_enabled = true ) {
		$this->suppression_repository = $suppression_repository;
		$this->stat_detection_enabled = (bool) $stat_detection_enabled;
	}

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
	 *     @type array  $themes        stylesheet => `version` 等(v0.7 §Step3.
	 *                                 `WPCV_Context_Builder::describe_themes()` の形).
	 *                                 既定は空配列.
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

		$target_runs[] = $this->maybe_apply_exclude_target(
			self::queued_target_run(
				WPCV_Target_Resolver::DIMENSION_CORE,
				WPCV_Target_Resolver::DIMENSION_CORE,
				'wordpress', // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- WPCV_Verifier::verify_core() と同じ理由.
				(string) $context['version'],
				'wporg'
			)
		);

		// Step4(v0.4.0)のchunk分割dispatcher向け合成target。コアの未知ファイル走査
		// (wp-admin/wp-includes/ABSPATH直下の3領域。`WPCV_Verifier::core_unknown_file_areas()`
		// 参照)は「1 target_run = 1直列cursor」というchunk実行の前提上、manifest比較
		// (上記の素の `core`)とは別のtarget_runにする必要がある。`muplugin:_scan`と
		// 対称的な設計(`WPCV_Target_Resolver::build_id()` のdocblock参照)。
		// 既存の一括実行(`WPCV_Verifier::verify_core()`)はこの合成targetを使わず、
		// 従来どおり `core` target_run 1件の中で未知ファイル走査まで行う(この
		// target_run自体はStep5でdispatcher経由に繋ぎ替えるまでの間、一括実行側からは
		// 参照されない).
		$target_runs[] = $this->maybe_apply_exclude_target(
			self::queued_target_run(
				WPCV_Target_Resolver::build_id( WPCV_Target_Resolver::DIMENSION_CORE, '_scan' ),
				WPCV_Target_Resolver::DIMENSION_CORE,
				'_scan',
				null,
				null
			)
		);

		// v0.6 §Step9(§5.3 L1・L8): 本体targetを持たない合成targetを2つ、本体の
		// 照合とは無関係に常に列挙する。ファイルシステムアクセスはここでは行わない
		// (クラスdocblock「HTTP・ファイルシステムアクセスを一切行わない」という
		// 不変条件どおり. 実在するファイルの絞り込みは`WPCV_Chunk_Dispatcher`が
		// dispatch時点で`WPCV_Static_Target_Resolver`を使って行う)。`stat_detection_enabled`
		// (既存stat targetの列挙可否)とは無関係の別の仕組みのため、その設定を見ずに
		// 常に列挙する.
		$target_runs[] = $this->maybe_apply_exclude_target(
			self::queued_target_run(
				WPCV_Target_Resolver::build_id( WPCV_Target_Resolver::DIMENSION_CORE, '_config' ),
				WPCV_Target_Resolver::DIMENSION_CORE,
				'_config',
				null,
				null
			)
		);
		$target_runs[] = $this->maybe_apply_exclude_target(
			self::queued_target_run(
				WPCV_Target_Resolver::build_id( WPCV_Target_Resolver::DIMENSION_DROPIN, '_stat' ),
				WPCV_Target_Resolver::DIMENSION_DROPIN,
				'_stat',
				null,
				null
			)
		);

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			if ( in_array( (string) $plugin_file, self::CORE_BUNDLED_PLUGIN_FILES, true ) ) {
				continue;
			}

			$resolved       = self::resolve_plugin_slug_and_root( (string) $plugin_file, $plugin_dir );
			$plugin_version = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
			$body_target_id = WPCV_Target_Resolver::build_id( WPCV_Target_Resolver::DIMENSION_PLUGIN, $resolved['slug'] );

			$target_runs[] = $this->maybe_apply_exclude_target(
				self::queued_target_run(
					$body_target_id,
					WPCV_Target_Resolver::DIMENSION_PLUGIN,
					$resolved['slug'],
					'' === $plugin_version ? null : $plugin_version,
					'wporg'
				)
			);

			if ( $this->stat_detection_enabled ) {
				$target_runs[] = $this->maybe_apply_exclude_target( self::queued_stat_target_run( $body_target_id, WPCV_Target_Resolver::DIMENSION_PLUGIN, $resolved['slug'], $plugin_version ) );
			}
		}

		// v0.7 §3.3(D5): テーマごとに本体(`theme:{stylesheet}`. WordPress.org の zip と
		// 照合)と、stat 差分検知が有効なら `:_stat` を列挙する(プラグインと同じ並び).
		// WordPress.org と照合しない条件(D6)の判定は、ここではなく照合ソースが行う
		// (version の空・入れ子・Update URI. どれも HTTP を出さずに unverifiable になり、
		// `:_stat` が走査する). 未知ファイル走査の `:_scan` は v0.7 Step5 で足す.
		$themes = isset( $context['themes'] ) ? (array) $context['themes'] : array();

		foreach ( $themes as $stylesheet => $theme ) {
			$theme_version  = isset( $theme['version'] ) ? (string) $theme['version'] : '';
			$body_target_id = WPCV_Target_Resolver::build_id( WPCV_Target_Resolver::DIMENSION_THEME, (string) $stylesheet );

			$target_runs[] = $this->maybe_apply_exclude_target(
				self::queued_target_run(
					$body_target_id,
					WPCV_Target_Resolver::DIMENSION_THEME,
					(string) $stylesheet,
					'' === $theme_version ? null : $theme_version,
					'wporg'
				)
			);

			if ( $this->stat_detection_enabled ) {
				$target_runs[] = $this->maybe_apply_exclude_target( self::queued_stat_target_run( $body_target_id, WPCV_Target_Resolver::DIMENSION_THEME, (string) $stylesheet, $theme_version ) );
			}
		}

		if ( ! empty( $context['mu_plugin_dir'] ) ) {
			$mu_plugins = isset( $context['mu_plugins'] ) ? (array) $context['mu_plugins'] : array();
			$dimension  = WPCV_Target_Resolver::DIMENSION_MUPLUGIN;

			// §3.6: loader ごとの target(wp.org/GitHub マッピング未実装のため、
			// 現時点では検証の結果は必ず unverifiable になるが、その判定自体は
			// Step3のchunk verifierが行う。ここではqueuedとして列挙するのみ).
			foreach ( array_keys( $mu_plugins ) as $basename ) {
				$body_target_id = WPCV_Target_Resolver::build_id( $dimension, (string) $basename );

				$target_runs[] = $this->maybe_apply_exclude_target(
					self::queued_target_run(
						$body_target_id,
						$dimension,
						(string) $basename,
						null,
						null
					)
				);

				if ( $this->stat_detection_enabled ) {
					$target_runs[] = $this->maybe_apply_exclude_target( self::queued_stat_target_run( $body_target_id, $dimension, (string) $basename, '' ) );
				}
			}

			// サブディレクトリ配下の未知ファイル走査用の合成target
			// (`WPCV_Verifier::verify_muplugin_area()` の docblock 参照).
			$target_runs[] = $this->maybe_apply_exclude_target(
				self::queued_target_run(
					WPCV_Target_Resolver::build_id( $dimension, '_scan' ),
					$dimension,
					'_scan',
					null,
					null
				)
			);
		}

		return $target_runs;
	}

	/**
	 * 列挙済みの target_run に対し、有効な `exclude_target` 抑制ルールがあれば
	 * `WPCV_Target_Status::SKIPPED` へ書き換える(v0.4.0 §Step8).
	 *
	 * @param array $target_run `queued_target_run()` が返す target_run.
	 * @return array 抑制ルールが無ければそのまま。あれば `status`/`error_code` を
	 *               書き換えたもの.
	 */
	private function maybe_apply_exclude_target( array $target_run ) {
		$rule = $this->suppression_repository->find_active_exclude_target_rule( $target_run['dimension'], $target_run['slug'] );

		if ( null === $rule ) {
			return $target_run;
		}

		$target_run['status']     = WPCV_Target_Status::SKIPPED;
		$target_run['error_code'] = WPCV_Error_Code::EXCLUDED;

		return $target_run;
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
	 * 本体 target に対応する stat 差分検知 target(`{dimension}:{slug}:_stat`)を
	 * `WPCV_Target_Status::QUEUED` で組み立てる(v0.5 §Step6. rev.3 §3.4).
	 *
	 * 「チェックサム照合できなかった target だけ stat 走査する」判定は、manifest を
	 * 取得するまで確定しない。このクラスは HTTP・ファイルシステムアクセスを行わない
	 * (クラス docblock の不変条件)ため、ここでは判定せず無条件に列挙し、実際の
	 * 振り分けは `WPCV_Chunk_Dispatcher` が本体 target_run の結果を見て行う.
	 *
	 * version には本体と同じ値を入れる。dispatcher が実行時の version と比べ、
	 * 変わっていれば `needs_retry`(ベースライン再構築の起点. rev.3 §3.7-a)にする.
	 * source は `stat`(チェックサムの取得元ではなく stat 比較であることを示す).
	 *
	 * @param string $body_target_id 本体 target の target_id.
	 * @param string $dimension      dimension(本体と同じ).
	 * @param string $slug           slug(本体と同じ. 抑制ルールを共有するため).
	 * @param string $version        本体の version(不明なら空文字).
	 * @return array
	 */
	private static function queued_stat_target_run( $body_target_id, $dimension, $slug, $version ) {
		return self::queued_target_run(
			WPCV_Target_Resolver::build_stat_id( $body_target_id ),
			$dimension,
			$slug,
			'' === $version ? null : $version,
			'stat'
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
