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
	 * v0.5 §Step6: `skipped` かつ `error_code = checksum_covered` の target_run
	 * (本体がチェックサム照合できたので省略した stat target)は、件数にも status 判定にも
	 * 含めない。本体の結果の複製でしかなく、数えると公式プラグインだけのサイトでも
	 * run が常に partial になってしまうため. それ以外の skipped(exclude_target 等)の
	 * 扱いは従来どおり.
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
		$target_runs = array_values(
			array_filter(
				$target_runs,
				static function ( $target_run ) {
					return ! ( 'skipped' === $target_run['status'] && isset( $target_run['error_code'] ) && WPCV_Error_Code::CHECKSUM_COVERED === $target_run['error_code'] );
				}
			)
		);

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
			if ( self::is_core_wp_content_missing_exempt( $dimension, $finding_path ) ) {
				return array(
					'finding'  => null,
					'verified' => false,
				);
			}

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
	 * Stat差分検知(層1)の1ファイル分の前回値と今回値を比べ、finding に変換する
	 * (v0.5 §Step5. rev.3 §3.2/§3.5参照).
	 *
	 * 状態の組み合わせ(`$previous` は前回ベースライン、`$current` は今回の `lstat()` 値):
	 *
	 * | 前回値 | size | ctime | mtime | 結果                                    |
	 * |--------|------|-------|-------|-----------------------------------------|
	 * | 無し   | -    | -     | -     | `added`(新規ファイル)                   |
	 * | あり   | 同じ | 同じ  | 同じ  | null(変更なし)                          |
	 * | あり   | 同じ | 変化  | 変化  | `stat_changed`                          |
	 * | あり   | 同じ | 変化  | 同じ  | `stat_changed`(chmod等. timestomp扱いしない) |
	 * | あり   | 同じ | 同じ  | 変化  | `stat_changed`(通常は起きない組み合わせ)|
	 * | あり   | 変化 | *     | 変化  | `stat_changed`                          |
	 * | あり   | 変化 | *     | 同じ  | `stat_changed` + timestomp(severity=high) |
	 *
	 * timestomp は rev.3 §3.5 の定義どおり「size(層2では content_hash)が変化したのに
	 * mtime が不変」の場合だけ立てる。size が同じで ctime だけ変わった場合は、
	 * chmod/chown と「同サイズ書き換え+mtime巻き戻し」を stat だけでは区別できない
	 * ため、timestomp にはせず通常の `stat_changed` として拾うに留める(層1の限界。§3.2-c).
	 *
	 * **v0.6 §Step10で導入した層2(`core:_config`/`dropin:_stat`専用)は、上記の
	 * timestomp判定を拡張するのではなく、内容ハッシュが変化していれば`stat_changed`
	 * を経由せず直接`modified`を返す形にした(`make_finding_for_stat_content_change()`
	 * 参照。2026-09-30ユーザー承認済み). 本メソッド自体は層1専用のまま変更していない.
	 *
	 * 層1はファイル内容を読まない設計(§3.2-c)のため、`added` でも hash を計算しない
	 * (`make_finding_for_unknown_file()` は hash を計算するので使わない)。そのため
	 * `expected_hash`/`actual_hash` は常に null、`hash_algorithm` は空文字にする
	 * (列が NOT NULL のため)。actual_hash を持たないので、ハッシュ承認
	 * (`allowlist_hash`)の対象にもならない. 抑制は `exclude_path` で行う(§3.5).
	 *
	 * @param string     $target_id     target_id(`{dimension}:{slug}:_stat` 形式).
	 * @param string     $dimension     dimension.
	 * @param string     $slug          slug.
	 * @param string     $version       version.
	 * @param string     $source        source.
	 * @param string     $relative_path ABSPATH 相対パス.
	 * @param string     $severity      timestomp でない場合の severity(走査時に拡張子から決めた値).
	 * @param array|null $previous      前回値 `array( 'size' => int, 'ctime' => int, 'mtime' => int )`.
	 *                                  ベースラインに無い(新規)場合は null.
	 * @param array      $current       今回値(`$previous` と同じ形).
	 * @return array|null 変更が無ければ null. それ以外は finding(`stat_changed` のみ `detail` を持つ).
	 */
	public static function make_finding_for_stat_change( $target_id, $dimension, $slug, $version, $source, $relative_path, $severity, ?array $previous, array $current ) {
		$current_size = (int) $current['size'];

		if ( null === $previous ) {
			return self::make_finding( $target_id, $dimension, $slug, $version, $source, $relative_path, 'added', $severity, '', null, null, $current_size );
		}

		$size_changed  = (int) $previous['size'] !== $current_size;
		$ctime_changed = (int) $previous['ctime'] !== (int) $current['ctime'];
		$mtime_changed = (int) $previous['mtime'] !== (int) $current['mtime'];

		if ( ! $size_changed && ! $ctime_changed && ! $mtime_changed ) {
			return null;
		}

		$timestomp = $size_changed && ! $mtime_changed;

		$finding = self::make_finding(
			$target_id,
			$dimension,
			$slug,
			$version,
			$source,
			$relative_path,
			'stat_changed',
			$timestomp ? 'high' : $severity,
			'',
			null,
			null,
			$current_size
		);

		// 管理画面で「何が変わったのか」を読めるよう、前回値と今回値を JSON で持たせる
		// (expected_hash/actual_hash が空になるため. rev.3 §3.5の detail 形式).
		$finding['detail'] = wp_json_encode(
			array(
				'size'      => array(
					'old' => (int) $previous['size'],
					'new' => $current_size,
				),
				'ctime'     => array(
					'old' => (int) $previous['ctime'],
					'new' => (int) $current['ctime'],
				),
				'mtime'     => array(
					'old' => (int) $previous['mtime'],
					'new' => (int) $current['mtime'],
				),
				'timestomp' => $timestomp,
			)
		);

		return $finding;
	}

	/**
	 * `make_finding_for_stat_change()`に内容ハッシュ比較を重ねる(v0.6 §Step10.
	 * §5.3 L4「常に内容ハッシュを取る」対象専用. `core:_config`/`dropin:_stat`から呼ぶ).
	 *
	 * 組み合わせ表(2026-09-30ユーザー承認済み):
	 *
	 * | 前回content_hash | 今回content_hash | 結果                                    |
	 * |------------------|-------------------|------------------------------------------|
	 * | null(層2導入前等) | *                 | 比較不能。`make_finding_for_stat_change()`にフォールバック(stat比較のみ) |
	 * | あり             | null(読み取り失敗) | 同上(フォールバック)                     |
	 * | あり             | 前回と同じ         | 同上(フォールバック。chmod等のstat変化はそのまま拾う) |
	 * | あり             | 前回と違う         | `modified`(expected_hash=前回値・actual_hash=今回値). この回は`stat_changed`を別途出さない |
	 *
	 * 「同じサイズ・同じmtimeでの書き換え」(timestompの典型例)を層1のstat比較だけでは
	 * 検知できないため、内容ハッシュが変わっていればstatの状態に関わらず`modified`を
	 * 優先する。フォールバック時は`make_finding_for_stat_change()`のtimestomp判定
	 * (size変化+mtime不変)がそのまま働く.
	 *
	 * @param string      $target_id             target_id(`{dimension}:{slug}:_stat`形式).
	 * @param string      $dimension             dimension.
	 * @param string      $slug                  slug.
	 * @param string      $version               version.
	 * @param string      $source                source.
	 * @param string      $relative_path         ABSPATH相対パス.
	 * @param string      $severity              timestompでない場合のseverity(フォールバック時のみ使う).
	 * @param array|null  $previous              前回のstat値(`make_finding_for_stat_change()`と同じ形).
	 * @param array       $current               今回のstat値(同上).
	 * @param string|null $previous_content_hash 前回保存済みのcontent_hash(sha256).未確立ならnull.
	 * @param string|null $current_content_hash  今回計算したcontent_hash(sha256).読み取り失敗ならnull.
	 * @return array|null 変更が無ければnull.
	 */
	public static function make_finding_for_stat_content_change( $target_id, $dimension, $slug, $version, $source, $relative_path, $severity, ?array $previous, array $current, ?string $previous_content_hash, ?string $current_content_hash ) {
		if ( null !== $previous_content_hash && null !== $current_content_hash && $previous_content_hash !== $current_content_hash ) {
			return self::make_finding(
				$target_id,
				$dimension,
				$slug,
				$version,
				$source,
				$relative_path,
				'modified',
				self::severity_for_modified_path( $relative_path ),
				WPCV_File_Hasher::ALGO_SHA256,
				$previous_content_hash,
				$current_content_hash,
				(int) $current['size']
			);
		}

		return self::make_finding_for_stat_change( $target_id, $dimension, $slug, $version, $source, $relative_path, $severity, $previous, $current );
	}

	/**
	 * Stat差分検知で、前回のベースラインにあったのに今回見つからなかったファイルを
	 * `missing` finding にする(v0.5 §Step7. rev.3 §3.3「削除検出は last_seen_run_id 方式」).
	 *
	 * Severity はチェックサム照合の `missing`(`compare_one_file()`)と同じ medium にする.
	 * 層1は内容を読まないため hash は持たない。消えたファイルなので file_size も null.
	 *
	 * @param string $target_id     target_id(`{dimension}:{slug}:_stat` 形式).
	 * @param string $dimension     dimension.
	 * @param string $slug          slug.
	 * @param string $version       version.
	 * @param string $source        source.
	 * @param string $relative_path ABSPATH 相対パス.
	 * @return array finding.
	 */
	public static function make_finding_for_stat_missing( $target_id, $dimension, $slug, $version, $source, $relative_path ) {
		return self::make_finding( $target_id, $dimension, $slug, $version, $source, $relative_path, 'missing', 'medium', '', null, null, null );
	}

	/**
	 * Stat差分検知の変更 finding をまとめて1件の集約 finding にする
	 * (v0.5 §Step7. rev.3 §3.7-c 大量変更のロールアップ).
	 *
	 * Version を上げない更新(mu-plugin・単一ファイルプラグイン・一部のベンダー)では
	 * version 変化によるベースライン作り直しが効かず、更新のたびに数百件の
	 * `stat_changed` が出てしまう。そうした場合に1件へまとめる.
	 *
	 * 集約 finding の `path` は target のルート(ディレクトリ、または単一ファイル)、
	 * severity は元の finding のうち最も高いもの。`detail` に件数と代表パスを入れる.
	 *
	 * @param string $target_id        target_id.
	 * @param string $dimension        dimension.
	 * @param string $slug             slug.
	 * @param string $version          version.
	 * @param string $source           source.
	 * @param string $target_root_path target のルートの ABSPATH 相対パス.
	 * @param array  $findings         まとめる finding(1件以上).
	 * @param int    $files_scanned    このまとまりで走査したファイル数.
	 * @return array finding(status は `stat_changed`).
	 */
	public static function make_rollup_finding_for_stat_changes( $target_id, $dimension, $slug, $version, $source, $target_root_path, array $findings, $files_scanned ) {
		$rank     = array(
			'low'    => 0,
			'medium' => 1,
			'high'   => 2,
		);
		$severity = 'low';
		$paths    = array();
		$added    = 0;

		foreach ( $findings as $finding ) {
			if ( $rank[ $finding['severity'] ] > $rank[ $severity ] ) {
				$severity = $finding['severity'];
			}

			if ( 'added' === $finding['status'] ) {
				++$added;
			}

			$paths[] = $finding['path'];
		}

		sort( $paths, SORT_STRING );

		$rollup = self::make_finding( $target_id, $dimension, $slug, $version, $source, $target_root_path, 'stat_changed', $severity, '', null, null, null );

		$rollup['detail'] = wp_json_encode(
			array(
				'rollup'        => true,
				'count'         => count( $findings ),
				'added'         => $added,
				'files_scanned' => (int) $files_scanned,
				// 代表パスの件数は表示用の目安(性能に関わる値ではない)。
				// detail 列(TEXT)に収まり、一覧で読める程度に絞っている.
				'sample_paths'  => array_slice( $paths, 0, self::ROLLUP_SAMPLE_PATHS ),
			),
			// DB を直接見たときにパスが読めるよう、`/` と日本語をエスケープしない.
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return $rollup;
	}

	/**
	 * 集約 finding の `detail.sample_paths` に入れる代表パスの最大件数(表示用).
	 *
	 * @var int
	 */
	const ROLLUP_SAMPLE_PATHS = 10;

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
	 * コア照合の `missing` を、`wp-content/` 配下では finding にしない対象かどうかを
	 * 判定する(v0.5後半プラン §0.1 U2・§0.4).
	 *
	 * WP-CLI の checksum-command(`Checksum_Core_Command.php`)は `wp-content/`
	 * 配下のファイルを欠落・改変どちらの照合からも外すが、本プラグインは
	 * **欠落だけ**を外す. hello.php は plugin 次元の照合から既に除外している
	 * (`WPCV_Run_Planner::CORE_BUNDLED_PLUGIN_FILES`)ため、改変を拾えるのは
	 * core 照合だけになる. `modified` を core 照合からも外すと、同梱ファイルの
	 * 改ざんを一切検出できなくなってしまう.
	 *
	 * @param string $dimension     dimension.
	 * @param string $finding_path  ABSPATH 相対パス(`WPCV_Path_Normalizer::to_relative()` 済み).
	 * @return bool
	 */
	private static function is_core_wp_content_missing_exempt( $dimension, $finding_path ) {
		return WPCV_Target_Resolver::DIMENSION_CORE === $dimension && 0 === strpos( $finding_path, 'wp-content/' );
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
