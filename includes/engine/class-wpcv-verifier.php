<?php
/**
 * WPCV_Verifier クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 各照合ソース(§3 各節)と未知ファイル走査(§3.3)を統合する検証エンジン本体.
 *
 * このクラスは検証ロジックのみを担い、$wpdb への書き込みは行わない(§4.2 の
 * Repository層(`class-wpcv-run-repository.php` 等。v0.4.0 §Step1で3責務に分割)の
 * 責務として別途分離する。DB 書き込みまで含めると
 * dbDelta 相当のテスト用 $wpdb スタブが新たに必要になり、この段階の目的
 * ―― 検証ロジックそのものの正しさを PHPUnit で確認する ―― から外れるため)。
 *
 * 戻り値の target_run / finding 配列は DB スキーマ(§5.3/§5.5)の列名にほぼ対応するが、
 * `id`・`run_id`・`target_run_id` は DB への INSERT 時に採番される値であり、
 * このクラスは持たない。findings はどの target_run に属するかを `target_id`
 * (target_runs・findings の両方が持つ列)で相関させる設計とし、実際の INSERT を
 * 行う側(未実装の Repository)が同一バッチ内の target_id 一致から
 * target_run_id を解決する.
 */
class WPCV_Verifier {

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
	 * コンストラクタ.
	 *
	 * @param WPCV_Manifest_Source      $core_source   §3.2 コア照合ソース.
	 * @param WPCV_Manifest_Source      $plugin_source §3.4 公式プラグイン照合ソース.
	 * @param WPCV_Unknown_File_Scanner $scanner       §3.3 未知ファイル走査エンジン.
	 */
	public function __construct( WPCV_Manifest_Source $core_source, WPCV_Manifest_Source $plugin_source, WPCV_Unknown_File_Scanner $scanner ) {
		$this->core_source   = $core_source;
		$this->plugin_source = $plugin_source;
		$this->scanner       = $scanner;
	}

	/**
	 * コアを検証する(§3.2 のマニフェスト取得 + §3.3 の未知ファイル走査).
	 *
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string $version ローカルの WordPress バージョン. 必須.
	 * }
	 * @return array {
	 *     @type array $target_run §5.3 のスキーマに準拠した1件の連想配列(id/run_id 無し).
	 *     @type array $findings   §5.5 のスキーマに準拠した配列(id/run_id/target_run_id 無し).
	 * }
	 *
	 * @throws InvalidArgumentException 必須の version が指定されていない場合.
	 */
	public function verify_core( array $context ) {
		if ( empty( $context['version'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Verifier::verify_core() requires $context[\'version\'].' ) );
		}

		$version   = (string) $context['version'];
		$target_id = WPCV_Target_Resolver::DIMENSION_CORE;
		$dimension = WPCV_Target_Resolver::DIMENSION_CORE;
		// §5.5: コアの slug は識別子として常に全て小文字で保存する。次の行の代入値を
		// phpcbf がプロダクト名の誤記と誤認して大文字化してしまうため抑止する.
		$slug       = 'wordpress'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
		$manifest   = $this->core_source->get_manifest( array( 'version' => $version ) );
		$target_run = self::base_target_run( $target_id, $dimension, $slug, $version, 'wporg', $manifest );

		if ( null !== $manifest['error_code'] ) {
			return array(
				'target_run' => $target_run,
				'findings'   => array(),
			);
		}

		$findings = self::compare_files( $target_id, $dimension, $slug, $version, 'wporg', ABSPATH, $manifest['files'], $target_run );
		$findings = array_merge( $findings, $this->scan_core_unknown_areas( $manifest['files'], $target_id, $dimension, $slug, $version ) );

		$target_run['findings_total'] = count( $findings );

		return array(
			'target_run' => $target_run,
			'findings'   => $findings,
		);
	}

	/**
	 * 公式プラグイン1件を検証する(§3.4).
	 *
	 * `wp-content/` 全体の未知ファイル走査は行わない(§3.3: 意図的な範囲外)ため、
	 * ここではマニフェストとの hash 比較のみを行う.
	 *
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string $slug            プラグインの slug. 必須.
	 *     @type string $version         ローカルのプラグインバージョン(`get_plugins()`
	 *                                   のヘッダー値)。取得できない場合は空文字列で
	 *                                   よい(§3.4: version_unknown として扱う).
	 *     @type string $plugin_root_dir マニフェストの相対パスを解決する基準ディレクトリ
	 *                                   の絶対パス. 必須。ディレクトリ型プラグインなら
	 *                                   `WP_PLUGIN_DIR/{slug}`、単一ファイルプラグインなら
	 *                                   `WP_PLUGIN_DIR` そのもの(§3.4 の単一ファイル
	 *                                   プラグインの扱いは呼び出し側がこの値の選択で表現する).
	 * }
	 * @return array `verify_core()` と同じ形.
	 *
	 * @throws InvalidArgumentException 必須の slug または plugin_root_dir が指定されていない場合.
	 */
	public function verify_plugin( array $context ) {
		if ( empty( $context['slug'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Verifier::verify_plugin() requires $context[\'slug\'].' ) );
		}

		if ( empty( $context['plugin_root_dir'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Verifier::verify_plugin() requires $context[\'plugin_root_dir\'].' ) );
		}

		$slug            = (string) $context['slug'];
		$version         = isset( $context['version'] ) ? (string) $context['version'] : '';
		$plugin_root_dir = (string) $context['plugin_root_dir'];
		$dimension       = WPCV_Target_Resolver::DIMENSION_PLUGIN;
		$target_id       = WPCV_Target_Resolver::build_id( $dimension, $slug );

		$manifest   = $this->plugin_source->get_manifest(
			array(
				'slug'    => $slug,
				'version' => $version,
			)
		);
		$target_run = self::base_target_run( $target_id, $dimension, $slug, '' === $version ? null : $version, 'wporg', $manifest );

		if ( null !== $manifest['error_code'] ) {
			return array(
				'target_run' => $target_run,
				'findings'   => array(),
			);
		}

		$findings                     = self::compare_files( $target_id, $dimension, $slug, $version, 'wporg', $plugin_root_dir, $manifest['files'], $target_run );
		$target_run['findings_total'] = count( $findings );

		return array(
			'target_run' => $target_run,
			'findings'   => $findings,
		);
	}

	/**
	 * MU プラグイン領域を検証する(§3.6).
	 *
	 * `WPMU_PLUGIN_DIR` 直下の loader(`get_mu_plugins()` が認識するファイル)は
	 * 個別の target_run を持つが、slug + version が両方確定できる loader を
	 * wp.org/GitHub のマニフェストと突き合わせる仕組み(§3.6 の2番目・3番目の行)は
	 * 未実装のため、すべて `unverifiable`/`unknown_source` になる(GitHub 手動
	 * マッピングが無い既定状態としては仕様どおりの挙動。§3.6 の最終行).
	 *
	 * サブディレクトリ配下の未知ファイル走査(§3.3・§3.6 最終行)は、個々の
	 * loader とは別の合成 target(`muplugin:_scan`)にまとめて紐付ける。
	 * WordPress 自体が loader とサブディレクトリの所有関係を管理しておらず、
	 * 特定の loader に帰属させる根拠が無いため.
	 *
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string   $mu_plugin_dir `WPMU_PLUGIN_DIR` の絶対パス. 必須.
	 *     @type string[] $loaders       `get_mu_plugins()` が返すキー(WPMU_PLUGIN_DIR
	 *                                   直下の相対ファイル名)の一覧. 既定は空配列.
	 * }
	 * @return array {
	 *     @type array $target_runs 個々の loader の target_run に加え、末尾に
	 *                              `muplugin:_scan` の target_run を含む配列.
	 *     @type array $findings    `muplugin:_scan` に属する `added` findings の配列.
	 * }
	 *
	 * @throws InvalidArgumentException 必須の mu_plugin_dir が指定されていない場合.
	 */
	public function verify_muplugin_area( array $context ) {
		if ( empty( $context['mu_plugin_dir'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Verifier::verify_muplugin_area() requires $context[\'mu_plugin_dir\'].' ) );
		}

		$mu_plugin_dir = rtrim( WPCV_Path_Normalizer::to_forward_slashes( (string) $context['mu_plugin_dir'] ), '/' );
		$loaders       = isset( $context['loaders'] ) ? (array) $context['loaders'] : array();
		$dimension     = WPCV_Target_Resolver::DIMENSION_MUPLUGIN;

		$mu_plugin_relative = WPCV_Path_Normalizer::to_relative( $mu_plugin_dir );

		$loader_target_runs = array();
		$known_files        = array();

		foreach ( $loaders as $basename ) {
			$basename = (string) $basename;
			$known_files[ '' === $mu_plugin_relative ? $basename : $mu_plugin_relative . '/' . $basename ] = true;

			$loader_target_runs[] = array(
				'target_id'       => WPCV_Target_Resolver::build_id( $dimension, $basename ),
				'dimension'       => $dimension,
				'slug'            => $basename,
				'version'         => null,
				'source'          => null,
				'source_ref'      => null,
				'manifest_status' => 'missing',
				'status'          => 'unverifiable',
				'error_code'      => WPCV_Error_Code::UNKNOWN_SOURCE,
				'error_message'   => null,
				'files_total'     => 0,
				'files_verified'  => 0,
				'findings_total'  => 0,
			);
		}

		$items = $this->scanner->scan(
			$mu_plugin_dir,
			$known_files,
			array(
				'recursive'        => true,
				'php_severity'     => 'high',
				'non_php_severity' => 'medium',
			)
		);

		$scan_target_id = WPCV_Target_Resolver::build_id( $dimension, '_scan' );
		$findings       = array();

		foreach ( $items as $item ) {
			// 合成 target には実体となる照合ソースが無いため、findings.source は
			// (§5.3 の値のうち)何もソースを使っていないことを表す 'none' にする.
			// dimension・slug 相当の情報が無い(ファイル横断の走査結果である)ため
			// version は空文字列にする(findings.version は NOT NULL のため null 不可).
			$findings[] = self::make_finding_for_unknown_file( $scan_target_id, $dimension, '_scan', '', 'none', $item['path'], $item['severity'] );
		}

		$scan_target_run = array(
			'target_id'       => $scan_target_id,
			'dimension'       => $dimension,
			'slug'            => '_scan',
			'version'         => null,
			'source'          => null,
			'source_ref'      => null,
			'manifest_status' => 'ok',
			'status'          => 'success',
			'error_code'      => null,
			'error_message'   => null,
			'files_total'     => 0,
			'files_verified'  => 0,
			'findings_total'  => count( $findings ),
		);

		return array(
			'target_runs' => array_merge( $loader_target_runs, array( $scan_target_run ) ),
			'findings'    => $findings,
		);
	}

	/**
	 * 個々の target_run から run 全体の集計値を求める(§5.2).
	 *
	 * 状態の組み合わせ:
	 *
	 * | target_runs の内訳                                   | run.status |
	 * |-------------------------------------------------------|------------|
	 * | 1件以上あり、すべて success                            | success    |
	 * | 1件以上の success と、1件以上の unverifiable/failed 等 | partial    |
	 * | success が0件(全滅、または target_runs 自体が空)       | success(空の場合)/ partial(1件以上あるが全滅の場合) |
	 *
	 * @param array $target_runs `verify_core()`等が返した target_run の配列.
	 * @return array {
	 *     @type string $status                success|partial.
	 *     @type int    $targets_total
	 *     @type int    $targets_verified
	 *     @type int    $targets_unverifiable
	 *     @type int    $targets_failed
	 *     @type int    $findings_total
	 * }
	 */
	public static function summarize( array $target_runs ) {
		$targets_total        = count( $target_runs );
		$targets_verified     = 0;
		$targets_unverifiable = 0;
		$targets_failed       = 0;
		$findings_total       = 0;

		foreach ( $target_runs as $target_run ) {
			if ( 'success' === $target_run['status'] ) {
				++$targets_verified;
			} elseif ( 'unverifiable' === $target_run['status'] ) {
				++$targets_unverifiable;
			} elseif ( 'failed' === $target_run['status'] ) {
				++$targets_failed;
			}

			$findings_total += isset( $target_run['findings_total'] ) ? (int) $target_run['findings_total'] : 0;
		}

		return array(
			'status'               => ( $targets_verified === $targets_total ) ? 'success' : 'partial',
			'targets_total'        => $targets_total,
			'targets_verified'     => $targets_verified,
			'targets_unverifiable' => $targets_unverifiable,
			'targets_failed'       => $targets_failed,
			'findings_total'       => $findings_total,
		);
	}

	/**
	 * マニフェスト取得結果から target_run の基本形を組み立てる.
	 *
	 * @param string      $target_id target_id.
	 * @param string      $dimension dimension.
	 * @param string      $slug      slug.
	 * @param string|null $version   version(不明なら null).
	 * @param string      $source    source(常に 'wporg'. コア・公式プラグインのみ扱うため).
	 * @param array       $manifest  照合ソースの `get_manifest()` の戻り値.
	 * @return array §5.3 のスキーマに準拠した target_run(id/run_id 無し).
	 */
	private static function base_target_run( $target_id, $dimension, $slug, $version, $source, array $manifest ) {
		return array(
			'target_id'       => $target_id,
			'dimension'       => $dimension,
			'slug'            => $slug,
			'version'         => $version,
			'source'          => $source,
			'source_ref'      => null,
			'manifest_status' => $manifest['manifest_status'],
			'status'          => null === $manifest['error_code'] ? 'success' : 'unverifiable',
			'error_code'      => $manifest['error_code'],
			'error_message'   => null,
			'files_total'     => 0,
			'files_verified'  => 0,
			'findings_total'  => 0,
		);
	}

	/**
	 * マニフェストとローカルファイルを比較し、findings を作る(§3.2/§3.4 共通).
	 *
	 * 呼び出しの副作用として `$target_run['files_total']` /
	 * `$target_run['files_verified']` を加算する.
	 *
	 * @param string $target_id      target_id.
	 * @param string $dimension      dimension.
	 * @param string $slug           slug.
	 * @param string $version        version(この時点では既に確定している非空文字列).
	 * @param string $source         source.
	 * @param string $base_dir       マニフェストの相対パスを解決する基準ディレクトリの絶対パス.
	 * @param array  $manifest_files 照合ソースが返した `files`(§interface 参照).
	 * @param array  $target_run     files_total/files_verified を加算する対象(参照渡し).
	 * @return array findings の配列.
	 */
	private static function compare_files( $target_id, $dimension, $slug, $version, $source, $base_dir, array $manifest_files, array &$target_run ) {
		$findings = array();
		$base_dir = rtrim( WPCV_Path_Normalizer::to_forward_slashes( $base_dir ), '/' );

		foreach ( $manifest_files as $relative_path => $spec ) {
			// wp.org 由来のマニフェストで通常起こらないが、`..` 等を含む不正な
			// パスは保存・照合のどちらでも拒否する方針(§12.5)をここでも適用する.
			if ( ! WPCV_Path_Normalizer::is_safe_relative_path( $relative_path ) ) {
				continue;
			}

			++$target_run['files_total'];

			$result = self::compare_one_file( $target_id, $dimension, $slug, $version, $source, $base_dir, $relative_path, $spec );

			if ( $result['verified'] ) {
				++$target_run['files_verified'];
			}

			if ( null !== $result['finding'] ) {
				$findings[] = $result['finding'];
			}
		}

		return $findings;
	}

	/**
	 * マニフェスト1件(1ファイル分)とローカルファイルを比較する(§3.2/§3.4 共通).
	 *
	 * `compare_files()` の1ループ分を抽出したもの(v0.4.0 §Step3: chunk分割実行の
	 * `WPCV_Chunk_Verifier` が1ファイルずつ処理する際にも同じ比較ロジックを
	 * 再利用するため public 化した。呼び出し元が `WPCV_Path_Normalizer::is_safe_relative_path()`
	 * によるパス検証と `files_total` の加算を行う前提(このメソッド自身は行わない).
	 *
	 * @param string $target_id     target_id.
	 * @param string $dimension     dimension.
	 * @param string $slug          slug.
	 * @param string $version       version(この時点では既に確定している非空文字列).
	 * @param string $source        source.
	 * @param string $base_dir      マニフェストの相対パスを解決する基準ディレクトリの絶対パス
	 *                              (末尾スラッシュ無し・スラッシュ区切り済み).
	 * @param string $relative_path マニフェストのキー(相対パス。安全性は呼び出し元で検証済みの前提).
	 * @param array  $spec          マニフェストの値(`algorithm`/`hashes`).
	 * @return array{finding: array|null, verified: bool} `finding` は一致した場合 null.
	 */
	public static function compare_one_file( $target_id, $dimension, $slug, $version, $source, $base_dir, $relative_path, array $spec ) {
		$absolute_path   = $base_dir . '/' . $relative_path;
		$finding_path    = WPCV_Path_Normalizer::to_relative( $absolute_path );
		$algorithm       = $spec['algorithm'];
		$expected_hashes = (array) $spec['hashes'];
		$expected_hash   = isset( $expected_hashes[0] ) ? $expected_hashes[0] : null;

		if ( ! file_exists( $absolute_path ) ) {
			return array(
				'finding'  => self::make_finding( $target_id, $dimension, $slug, $version, $source, $finding_path, 'missing', 'medium', $algorithm, $expected_hash, null, null ),
				'verified' => false,
			);
		}

		$actual_hash = WPCV_File_Hasher::hash( $absolute_path, $algorithm );

		if ( null === $actual_hash ) {
			return array(
				'finding'  => self::make_finding( $target_id, $dimension, $slug, $version, $source, $finding_path, 'unreadable', 'medium', $algorithm, $expected_hash, null, null ),
				'verified' => false,
			);
		}

		if ( in_array( $actual_hash, $expected_hashes, true ) ) {
			return array(
				'finding'  => null,
				'verified' => true,
			);
		}

		return array(
			'finding'  => self::make_finding(
				$target_id,
				$dimension,
				$slug,
				$version,
				$source,
				$finding_path,
				'modified',
				self::severity_for_modified_path( $finding_path ),
				$algorithm,
				$expected_hash,
				$actual_hash,
				WPCV_File_Hasher::size( $absolute_path )
			),
			'verified' => false,
		);
	}

	/**
	 * コア領域(wp-admin/wp-includes/ABSPATH 直下)の未知ファイルを走査する(§3.3).
	 *
	 * @param array  $known_files コアマニフェストの `files`(3領域すべてで同じものを渡してよい。
	 *                            走査範囲外のキーが混ざっていても実害は無い).
	 * @param string $target_id   findings に持たせる target_id(常に `core`).
	 * @param string $dimension   dimension(常に `core`).
	 * @param string $slug        slug(常に `wordpress`).
	 * @param string $version     version.
	 * @return array findings の配列(`added`).
	 */
	private function scan_core_unknown_areas( array $known_files, $target_id, $dimension, $slug, $version ) {
		$areas = array(
			array(
				'dir'  => ABSPATH . 'wp-admin',
				'args' => array(
					'recursive'        => true,
					'php_severity'     => 'high',
					'non_php_severity' => 'high',
				),
			),
			array(
				'dir'  => ABSPATH . 'wp-includes',
				'args' => array(
					'recursive'        => true,
					'php_severity'     => 'high',
					'non_php_severity' => 'high',
				),
			),
			array(
				'dir'  => rtrim( ABSPATH, '/' ),
				'args' => array(
					'recursive'            => false,
					'php_severity'         => 'high',
					'non_php_severity'     => 'medium',
					'extra_excluded_paths' => array( '.htaccess', 'wp-config.php' ),
				),
			),
		);

		$findings = array();

		foreach ( $areas as $area ) {
			foreach ( $this->scanner->scan( $area['dir'], $known_files, $area['args'] ) as $item ) {
				$findings[] = self::make_finding_for_unknown_file( $target_id, $dimension, $slug, $version, 'wporg', $item['path'], $item['severity'] );
			}
		}

		return $findings;
	}

	/**
	 * 未知ファイル走査(`added`)の1件を finding に変換する(実ファイルの hash を計算する).
	 *
	 * `WPCV_Chunk_Verifier`(v0.4.0 §Step3)が未知ファイル走査の chunk 処理でも
	 * 同じ変換ロジックを使うため public 化した.
	 *
	 * @param string $target_id     target_id.
	 * @param string $dimension     dimension.
	 * @param string $slug          slug.
	 * @param string $version       version.
	 * @param string $source        source.
	 * @param string $relative_path ABSPATH 相対パス.
	 * @param string $severity      severity.
	 * @return array finding.
	 */
	public static function make_finding_for_unknown_file( $target_id, $dimension, $slug, $version, $source, $relative_path, $severity ) {
		$absolute_path = ABSPATH . $relative_path;

		return self::make_finding(
			$target_id,
			$dimension,
			$slug,
			$version,
			$source,
			$relative_path,
			'added',
			$severity,
			WPCV_File_Hasher::ALGO_SHA256,
			null,
			WPCV_File_Hasher::hash( $absolute_path, WPCV_File_Hasher::ALGO_SHA256 ),
			WPCV_File_Hasher::size( $absolute_path )
		);
	}

	/**
	 * 検出結果(finding)の1行を組み立てる(§5.5 のスキーマに準拠。id/run_id/target_run_id 無し).
	 *
	 * @param string      $target_id      target_id.
	 * @param string      $dimension      dimension.
	 * @param string      $slug           slug.
	 * @param string      $version        version.
	 * @param string      $source         source.
	 * @param string      $path           ABSPATH 相対パス.
	 * @param string      $status         modified|added|missing|unreadable.
	 * @param string      $severity       high|medium|low.
	 * @param string      $hash_algorithm sha256|md5.
	 * @param string|null $expected_hash  expected_hash(added では null).
	 * @param string|null $actual_hash    actual_hash(missing/unreadable では null).
	 * @param int|null    $file_size      file_size.
	 * @return array
	 */
	private static function make_finding( $target_id, $dimension, $slug, $version, $source, $path, $status, $severity, $hash_algorithm, $expected_hash, $actual_hash, $file_size ) {
		return array(
			'target_id'      => $target_id,
			'dimension'      => $dimension,
			'slug'           => $slug,
			'version'        => $version,
			'source'         => $source,
			'path'           => $path,
			'status'         => $status,
			'severity'       => $severity,
			'hash_algorithm' => $hash_algorithm,
			'expected_hash'  => $expected_hash,
			'actual_hash'    => $actual_hash,
			'file_size'      => $file_size,
		);
	}

	/**
	 * `modified` finding の severity を拡張子から求める(§5.5 の severity 表と同じ分類).
	 *
	 * @param string $relative_path ABSPATH 相対パス.
	 * @return string high|medium.
	 */
	private static function severity_for_modified_path( $relative_path ) {
		$basename = strtolower( basename( $relative_path ) );

		if ( '.htaccess' === $basename || '.user.ini' === $basename ) {
			return 'high';
		}

		$extension = strtolower( pathinfo( $relative_path, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, array( 'php', 'phtml', 'phar', 'php5', 'php7', 'inc' ), true ) ) {
			return 'high';
		}

		if ( 'js' === $extension ) {
			/**
			 * `.js` ファイルの severity を調整する(§5.5).
			 *
			 * 管理画面 JS と圧縮済みフロント JS とで意味が大きく異なるため、
			 * 既定は high としつつ対象ごとに変更できるようにする.
			 *
			 * @param string $severity      既定の severity('high').
			 * @param string $relative_path ABSPATH 相対パス.
			 */
			return apply_filters( 'wpcv_severity', 'high', $relative_path );
		}

		return 'medium';
	}
}
