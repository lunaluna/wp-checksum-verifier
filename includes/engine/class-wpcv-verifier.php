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
 * §3 各節の検証・findings 組み立てで共有する static ユーティリティ集
 * (target_run/finding の形の組み立て・summary計算・未知ファイル走査対象の定義)。
 *
 * Step5(v0.4.0)で、一括実行だった `verify_core()`/`verify_plugin()`/
 * `verify_muplugin_area()`(§3.2/§3.4/§3.6 のマニフェスト取得 + ファイル比較を
 * 1メソッド内でまとめて行っていた)を削除した。chunk分割実行(v0.4.0 §Step3〜4の
 * `WPCV_Chunk_Verifier`/`WPCV_Chunk_Dispatcher`)と、それを1リクエスト内で
 * ループし切る一括実行(`WPCV_Run_Coordinator`。§Step5でchunk dispatcherの
 * ループへ書き換えた)が同じ検証ロジック(`compare_one_file()`/
 * `make_finding_for_unknown_file()`)を共有するようになったため、二重の検証
 * 実装(このクラスの旧メソッドが個別ファイル比較を独自に持っていた)を残さない
 * という判断による(旧メソッドの呼び出し元は`WPCV_Run_Coordinator`のみだった).
 *
 * このクラスはもはや $core_source/$plugin_source/$scanner を持たない
 * (旧メソッドが唯一の利用箇所だったため)。すべて static メソッドのみで構成される.
 *
 * 戻り値の target_run / finding 配列は DB スキーマ(§5.3/§5.5)の列名にほぼ対応するが、
 * `id`・`run_id`・`target_run_id` は DB への INSERT 時に採番される値であり、
 * このクラスは持たない。findings はどの target_run に属するかを `target_id`
 * (target_runs・findings の両方が持つ列)で相関させる設計とし、実際の INSERT を
 * 行う側(Repository層)が同一バッチ内の target_id 一致から target_run_id を解決する.
 */
class WPCV_Verifier {

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
	 * @param array $target_runs target_run の配列(§5.3準拠。`status`/`findings_total`を持つもの).
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
	 * マニフェスト1件(1ファイル分)とローカルファイルを比較する(§3.2/§3.4 共通).
	 *
	 * `WPCV_Chunk_Verifier`(v0.4.0 §Step3)が1ファイルずつ処理する際にこの
	 * 比較ロジックを再利用するため public 化した。呼び出し元が
	 * `WPCV_Path_Normalizer::is_safe_relative_path()` によるパス検証と
	 * `files_total` の加算を行う前提(このメソッド自身は行わない).
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
	 * MU プラグインの loader(`WPMU_PLUGIN_DIR` 直下の相対ファイル名)を、
	 * `WPCV_Unknown_File_Scanner::scan()` が受け取る形式(ABSPATH相対パスをキーにした
	 * 既知パス集合)へ変換する(§3.6).
	 *
	 * `WPCV_Chunk_Dispatcher`(v0.4.0 §Step4)が `muplugin:_scan` target を
	 * chunk 処理する際にこの変換を使う(`core_unknown_file_areas()` と同じく
	 * public static で共有する).
	 *
	 * @param string $mu_plugin_dir `WPMU_PLUGIN_DIR` の絶対パス.
	 * @param array  $loaders       `get_mu_plugins()` が返すキー(WPMU_PLUGIN_DIR
	 *                              直下の相対ファイル名)の一覧.
	 * @return array ABSPATH相対パスをキーにした連想配列(値は常に `true`。
	 *               `WPCV_Unknown_File_Scanner::scan()` の `$known_files` にそのまま渡せる).
	 */
	public static function known_muplugin_loader_files( $mu_plugin_dir, array $loaders ) {
		$mu_plugin_dir      = rtrim( WPCV_Path_Normalizer::to_forward_slashes( (string) $mu_plugin_dir ), '/' );
		$mu_plugin_relative = WPCV_Path_Normalizer::to_relative( $mu_plugin_dir );
		$known_files        = array();

		foreach ( $loaders as $basename ) {
			$basename = (string) $basename;

			$known_files[ '' === $mu_plugin_relative ? $basename : $mu_plugin_relative . '/' . $basename ] = true;
		}

		return $known_files;
	}

	/**
	 * コア領域(wp-admin/wp-includes/ABSPATH 直下)の未知ファイル走査対象(§3.3)を返す.
	 *
	 * `WPCV_Chunk_Dispatcher`(v0.4.0 §Step4)が、3領域を1つの合成target
	 * (`core:_scan`)のchunk処理としてまとめて走査する際に、領域定義
	 * (ディレクトリ+走査オプション)を参照する.
	 *
	 * @return array `array( array( 'dir' => string, 'args' => array ), ... )`.
	 */
	public static function core_unknown_file_areas() {
		return array(
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
