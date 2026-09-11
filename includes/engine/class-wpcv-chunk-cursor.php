<?php
/**
 * WPCV_Chunk_Cursor クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chunk分割実行のための決定的な順序付け・fingerprint計算・cursor位置計算
 * (v0.4.0 §Step3)。
 *
 * Manifestの反復順序(`get_manifest()` が返す `files` 連想配列の挿入順)は
 * 取得元(wp.org API のレスポンス順)に依存し決定的ではない
 * (`WPCV_Source_Core`/`WPCV_Source_Wporg_Plugin` はいずれもソートしていない)。
 * `WPCV_Unknown_File_Scanner::scan()` も `scandir()` 依存でOS/ロケールにより
 * 揺らぎ得る。resume時に「前回のcursor_pathより後」を正しく再開するには、
 * 呼び出し側が明示的にソートした同一の並び順を毎回使う必要があるため、
 * その並び替えとcursor計算をここに集約する.
 *
 * fingerprintは「対象集合(manifestのpath+hashの組、または未知ファイル走査の
 * path一覧)が前回のchunk実行時から変わっていないか」を検知するための値
 * (§Step3「resume時にversion/fingerprintが変わっていたらchunk結果を確定せず
 * retryへ戻す」)。sort済み識別子の配列から `sha256` を計算するだけの汎用実装とし、
 * manifestの場合は `path|algorithm|hashes` を、未知ファイル走査の場合は
 * pathそのものを識別子として渡す(呼び出し元の責務. `WPCV_Chunk_Verifier` 参照).
 */
class WPCV_Chunk_Cursor {

	/**
	 * Manifestの `files`(path => spec)から、安全なパスのみをソート済みで返す.
	 *
	 * `WPCV_Path_Normalizer::is_safe_relative_path()` を満たさないpath(`..` を
	 * 含む等)は、`WPCV_Verifier::compare_files()` と同じ方針で除外する.
	 *
	 * @param array $manifest_files 照合ソースが返した `files`.
	 * @return string[] ソート済み(バイト列順. `sort()` の既定 `SORT_STRING` と同じ)のpath一覧.
	 */
	public static function sorted_safe_paths( array $manifest_files ) {
		$paths = array();

		foreach ( array_keys( $manifest_files ) as $path ) {
			if ( WPCV_Path_Normalizer::is_safe_relative_path( (string) $path ) ) {
				$paths[] = (string) $path;
			}
		}

		sort( $paths, SORT_STRING );

		return $paths;
	}

	/**
	 * 未知ファイル走査の結果(`WPCV_Unknown_File_Scanner::scan()` の戻り値)から
	 * ソート済みのpath一覧を返す.
	 *
	 * @param array $scan_items `array( array( 'path' => string, 'severity' => string ), ... )`.
	 * @return string[] ソート済みのpath一覧.
	 */
	public static function sorted_scan_paths( array $scan_items ) {
		$paths = array();

		foreach ( $scan_items as $item ) {
			$paths[] = (string) $item['path'];
		}

		sort( $paths, SORT_STRING );

		return $paths;
	}

	/**
	 * ソート済み識別子の配列から fingerprint(sha256 hex, 64文字)を計算する.
	 *
	 * @param string[] $sorted_identifiers ソート済みの識別子一覧
	 *                                     (manifestなら `path|algorithm|hashes` 形式、
	 *                                     未知ファイル走査なら path そのもの).
	 * @return string sha256 hex文字列.
	 */
	public static function compute_fingerprint( array $sorted_identifiers ) {
		return hash( 'sha256', implode( "\n", $sorted_identifiers ) );
	}

	/**
	 * Manifestの `files` から `compute_fingerprint()` 用の識別子一覧を組み立てる
	 * (`sorted_safe_paths()` の結果に対応する `algorithm`/`hashes` を連結する).
	 *
	 * @param array    $manifest_files 照合ソースが返した `files`.
	 * @param string[] $sorted_paths   `sorted_safe_paths( $manifest_files )` の戻り値.
	 * @return string[] `path|algorithm|hash1,hash2` 形式の識別子一覧(`$sorted_paths` と同じ順).
	 */
	public static function manifest_identifiers( array $manifest_files, array $sorted_paths ) {
		$identifiers = array();

		foreach ( $sorted_paths as $path ) {
			$spec          = $manifest_files[ $path ];
			$hashes        = implode( ',', (array) $spec['hashes'] );
			$identifiers[] = $path . '|' . $spec['algorithm'] . '|' . $hashes;
		}

		return $identifiers;
	}

	/**
	 * ソート済みpath一覧のうち、`$cursor_path` より後(sort順で厳密に大きい)の
	 * ものだけを返す.
	 *
	 * `$cursor_path` が `$sorted_paths` に見つからない場合(前回の対象集合と
	 * 現在の対象集合が異なる。fingerprintの不一致で検知され呼び出し元が
	 * retryへ倒す前提のため、ここでは安全側に倒して)は、先頭から全件を返す.
	 *
	 * @param string[]    $sorted_paths ソート済みpath一覧.
	 * @param string|null $cursor_path  前回確定した位置. 最初から処理する場合は null.
	 * @return string[] 処理すべきpathの一覧(元の順序を維持した部分配列).
	 */
	public static function paths_after( array $sorted_paths, $cursor_path ) {
		if ( null === $cursor_path || '' === $cursor_path ) {
			return $sorted_paths;
		}

		$index = array_search( $cursor_path, $sorted_paths, true );

		if ( false === $index ) {
			return $sorted_paths;
		}

		return array_slice( $sorted_paths, $index + 1 );
	}
}
