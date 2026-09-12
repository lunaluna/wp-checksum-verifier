<?php
/**
 * WPCV_Chunk_Verifier クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 時間・件数・メモリ予算内でmanifest比較/未知ファイル走査を1chunk分だけ進め、
 * `cursor_path`・`manifest_fingerprint`を確定させる(v0.4.0 §Step3)。
 *
 * `WPCV_Verifier`(v0.2〜。1回の呼び出しで対象の全ファイルを一括比較する設計)を
 * そのまま使い続けると、大規模なプラグイン・コアで1リクエストの時間・メモリ
 * 制限に収まらない。本クラスは `WPCV_Verifier::compare_one_file()` /
 * `WPCV_Verifier::make_finding_for_unknown_file()`(いずれもStep3でpublic化した
 * 1ファイル単位の比較・変換ロジック)を再利用しつつ、`WPCV_Chunk_Cursor` で
 * 決定的にソートした対象集合を先頭から順に処理し、予算に達した時点で
 * 呼び出し元(v0.4.0 §Step4のdispatcher)へ「どこまで進んだか」を返す.
 *
 * fingerprint(`WPCV_Chunk_Cursor::compute_fingerprint()`)・versionが前回保存時
 * (`$context['previous_fingerprint']`/`$context['previous_version']`)と異なる
 * 場合は、対象集合そのものが変わった(プラグイン更新等)とみなし、実際の比較を
 * 行わずに `needs_retry: true` を返す(§Step3「resume時にversion/fingerprintが
 * 変わっていたらchunk結果を確定せずretryへ戻す」)。この判定より後の呼び出し元
 * (§Step4)が、返り値を見てtarget_runを`WPCV_Target_Status::RETRY`へ戻し、
 * cursor・集計値をリセットする責務を持つ(本クラス自身はDB更新を行わない).
 *
 * 予算(`$context['budget']`)は `max_files`/`max_seconds`/`memory_limit_bytes`
 * (+`memory_threshold_ratio`. 既定0.9)のいずれも省略可能で、未指定の項目は
 * 制限として扱わない。具体的な既定値の決定(実測含む)はStep4以降で行う
 * (Step3時点では呼び出し元が明示的に指定する設計にとどめ、未実測の数値を
 * 決め打ちしない)。
 */
class WPCV_Chunk_Verifier {

	/**
	 * 現在時刻を秒(float。`microtime( true )` 相当)で返す callable.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * 現在のメモリ使用量をバイト数(`memory_get_usage( true )` 相当)で返す callable.
	 *
	 * @var callable
	 */
	private $memory_usage;

	/**
	 * コンストラクタ.
	 *
	 * @param callable|null $now          省略時は `microtime( true )`.
	 * @param callable|null $memory_usage 省略時は `memory_get_usage( true )`.
	 */
	public function __construct( ?callable $now = null, ?callable $memory_usage = null ) {
		$this->now          = $now ?? static function () {
			return microtime( true );
		};
		$this->memory_usage = $memory_usage ?? static function () {
			return memory_get_usage( true );
		};
	}

	/**
	 * Manifestベース(コア・公式プラグイン)のchunk比較を1回分行う.
	 *
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string      $target_id            target_id. 必須.
	 *     @type string      $dimension            dimension. 必須.
	 *     @type string      $slug                 slug. 必須.
	 *     @type string      $version              version. 必須.
	 *     @type string      $source               source. 必須.
	 *     @type string      $base_dir             マニフェストの相対パスを解決する基準
	 *                                             ディレクトリの絶対パス. 必須.
	 *     @type array       $manifest_files       照合ソースが返した `files`. 必須.
	 *     @type string|null $cursor_path          前回確定した位置. 最初から処理する
	 *                                             場合は null(既定).
	 *     @type string|null $previous_fingerprint 前回保存した `manifest_fingerprint`.
	 *                                             初回は null(既定. fingerprint比較を
	 *                                             スキップする).
	 *     @type string|null $previous_version     前回保存した `version`. 初回は null
	 *                                             (既定. version比較をスキップする).
	 *     @type array       $budget               `max_files`/`max_seconds`/
	 *                                             `memory_limit_bytes`/
	 *                                             `memory_threshold_ratio`(いずれも省略可).
	 * }
	 * @return array {
	 *     @type array       $findings             確定した findings.
	 *     @type string|null $cursor_path          次回に渡すべき位置(完走時は null).
	 *     @type int         $files_verified_delta 今回のchunkで一致確認できた件数.
	 *     @type int         $files_total          対象集合(安全なpathのみ)の総数.
	 *     @type bool        $completed            対象集合を最後まで処理できたか.
	 *     @type string      $manifest_fingerprint 今回計算した fingerprint.
	 *     @type bool        $fingerprint_changed  前回と fingerprint が異なるか.
	 *     @type bool        $version_changed      前回と version が異なるか.
	 *     @type bool        $needs_retry          true の場合、呼び出し元は比較結果を
	 *                                             確定させず target_run を retry へ戻すこと
	 *                                             (findings は空、completed は false).
	 * }
	 */
	public function verify_manifest_chunk( array $context ) {
		$manifest_files = (array) $context['manifest_files'];
		$sorted_paths   = WPCV_Chunk_Cursor::sorted_safe_paths( $manifest_files );
		$identifiers    = WPCV_Chunk_Cursor::manifest_identifiers( $manifest_files, $sorted_paths );
		$fingerprint    = WPCV_Chunk_Cursor::compute_fingerprint( $identifiers );

		$previous_fingerprint = $context['previous_fingerprint'] ?? null;
		$previous_version     = $context['previous_version'] ?? null;

		$fingerprint_changed = null !== $previous_fingerprint && $previous_fingerprint !== $fingerprint;
		$version_changed     = null !== $previous_version && $previous_version !== $context['version'];

		if ( $fingerprint_changed || $version_changed ) {
			return self::retry_result( $fingerprint, count( $sorted_paths ), $fingerprint_changed, $version_changed );
		}

		$base_dir = rtrim( WPCV_Path_Normalizer::to_forward_slashes( (string) $context['base_dir'] ), '/' );
		$paths    = WPCV_Chunk_Cursor::paths_after( $sorted_paths, $context['cursor_path'] ?? null );

		$findings             = array();
		$files_verified_delta = 0;
		$new_cursor_path      = $context['cursor_path'] ?? null;
		$completed            = true;
		$processed            = 0;
		$start                = call_user_func( $this->now );
		$budget               = isset( $context['budget'] ) ? (array) $context['budget'] : array();

		foreach ( $paths as $path ) {
			$result = WPCV_Verifier::compare_one_file(
				(string) $context['target_id'],
				(string) $context['dimension'],
				(string) $context['slug'],
				(string) $context['version'],
				(string) $context['source'],
				$base_dir,
				$path,
				$manifest_files[ $path ]
			);

			if ( $result['verified'] ) {
				++$files_verified_delta;
			}

			if ( null !== $result['finding'] ) {
				$findings[] = $result['finding'];
			}

			$new_cursor_path = $path;
			++$processed;

			if ( $this->budget_exceeded( $start, $processed, $budget ) ) {
				$completed = false;
				break;
			}
		}

		return array(
			'findings'             => $findings,
			'cursor_path'          => $completed ? null : $new_cursor_path,
			'files_verified_delta' => $files_verified_delta,
			'files_total'          => count( $sorted_paths ),
			'completed'            => $completed,
			'manifest_fingerprint' => $fingerprint,
			'fingerprint_changed'  => false,
			'version_changed'      => false,
			'needs_retry'          => false,
		);
	}

	/**
	 * 未知ファイル走査結果のchunk処理を1回分行う.
	 *
	 * `WPCV_Unknown_File_Scanner::scan()` は指定ディレクトリを一括で走査して
	 * 結果を返す設計のまま変更しない(v0.4.0 §Step3のスコープは「walkの結果を
	 * 決定的な順序でchunk処理する」ことであり、walk自体の分割は対象外)。
	 * 走査結果には実ファイルのhashを計算していない(`path`/`severity` のみ)ため、
	 * fingerprintは実ファイルの内容ではなく path一覧のみから計算する(walkのみで
	 * 確定できる値にすることで、fingerprint計算自体は軽量に保つ).
	 *
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string      $target_id            target_id. 必須.
	 *     @type string      $dimension            dimension. 必須.
	 *     @type string      $slug                 slug. 必須.
	 *     @type string|null $version              version.
	 *     @type string|null $source               source.
	 *     @type array       $scan_items           `WPCV_Unknown_File_Scanner::scan()` の
	 *                                             戻り値. 必須.
	 *     @type string|null $cursor_path          前回確定した位置. 既定 null.
	 *     @type string|null $previous_fingerprint 前回保存した fingerprint. 既定 null.
	 *     @type array       $budget               `verify_manifest_chunk()` と同じ形.
	 * }
	 * @return array `verify_manifest_chunk()` と同じ形(`files_verified_delta`/
	 *               `version_changed` は常に 0/false. 未知ファイル走査には
	 *               「一致確認できた件数」やversion比較の概念が無いため).
	 */
	public function verify_unknown_files_chunk( array $context ) {
		$scan_items   = (array) $context['scan_items'];
		$sorted_paths = WPCV_Chunk_Cursor::sorted_scan_paths( $scan_items );
		$fingerprint  = WPCV_Chunk_Cursor::compute_fingerprint( $sorted_paths );

		$previous_fingerprint = $context['previous_fingerprint'] ?? null;
		$fingerprint_changed  = null !== $previous_fingerprint && $previous_fingerprint !== $fingerprint;

		if ( $fingerprint_changed ) {
			return self::retry_result( $fingerprint, count( $sorted_paths ), true, false );
		}

		$severity_by_path = array();
		foreach ( $scan_items as $item ) {
			$severity_by_path[ $item['path'] ] = $item['severity'];
		}

		$paths           = WPCV_Chunk_Cursor::paths_after( $sorted_paths, $context['cursor_path'] ?? null );
		$version         = isset( $context['version'] ) ? (string) $context['version'] : '';
		$source          = isset( $context['source'] ) ? (string) $context['source'] : '';
		$budget          = isset( $context['budget'] ) ? (array) $context['budget'] : array();
		$findings        = array();
		$new_cursor_path = $context['cursor_path'] ?? null;
		$completed       = true;
		$processed       = 0;
		$start           = call_user_func( $this->now );

		foreach ( $paths as $path ) {
			$findings[] = WPCV_Verifier::make_finding_for_unknown_file(
				(string) $context['target_id'],
				(string) $context['dimension'],
				(string) $context['slug'],
				$version,
				$source,
				$path,
				$severity_by_path[ $path ]
			);

			$new_cursor_path = $path;
			++$processed;

			if ( $this->budget_exceeded( $start, $processed, $budget ) ) {
				$completed = false;
				break;
			}
		}

		return array(
			'findings'             => $findings,
			'cursor_path'          => $completed ? null : $new_cursor_path,
			'files_verified_delta' => 0,
			'files_total'          => count( $sorted_paths ),
			'completed'            => $completed,
			'manifest_fingerprint' => $fingerprint,
			'fingerprint_changed'  => false,
			'version_changed'      => false,
			'needs_retry'          => false,
		);
	}

	/**
	 * Fingerprint/version不一致時の戻り値を組み立てる(2つの `verify_*_chunk()` で共有).
	 *
	 * @param string $fingerprint         今回計算した fingerprint.
	 * @param int    $files_total         対象集合の総数.
	 * @param bool   $fingerprint_changed fingerprintが変わったか.
	 * @param bool   $version_changed     versionが変わったか.
	 * @return array `verify_manifest_chunk()` と同じ形.
	 */
	private static function retry_result( $fingerprint, $files_total, $fingerprint_changed, $version_changed ) {
		return array(
			'findings'             => array(),
			'cursor_path'          => null,
			'files_verified_delta' => 0,
			'files_total'          => $files_total,
			'completed'            => false,
			'manifest_fingerprint' => $fingerprint,
			'fingerprint_changed'  => $fingerprint_changed,
			'version_changed'      => $version_changed,
			'needs_retry'          => true,
		);
	}

	/**
	 * 予算(件数・経過時間・メモリ)のいずれかを超えたかを判定する.
	 *
	 * @param float $start_time 処理開始時刻(`$this->now` の戻り値).
	 * @param int   $processed  このchunkで既に処理した件数.
	 * @param array $budget     `max_files`/`max_seconds`/`memory_limit_bytes`/
	 *                          `memory_threshold_ratio`(いずれも省略可).
	 * @return bool
	 */
	private function budget_exceeded( $start_time, $processed, array $budget ) {
		return WPCV_Chunk_Budget::exceeded( $start_time, $processed, $budget, $this->now, $this->memory_usage );
	}
}
