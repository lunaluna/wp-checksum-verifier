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
	 * Stat差分検知(層1)のchunk処理を1回分行う(v0.5 §Step5. rev.3 §3参照).
	 *
	 * `verify_unknown_files_chunk()` を雛形にしている。違いは次の3点:
	 *
	 * 1. 走査結果(`collect_stat => true` で得た size/ctime/mtime 付きの items)を
	 *    前回のベースラインと比べ、`WPCV_Verifier::make_finding_for_stat_change()` で
	 *    finding にする(ファイル内容は読まない).
	 * 2. 処理したファイルぶんのベースライン行(`baseline_rows`)も返す。呼び出し元
	 *    (Step6の dispatcher / `commit_chunk()`)が findings と同じトランザクションで
	 *    `WPCV_File_State_Repository::upsert_many()` に渡す想定.
	 * 3. `$context['baseline_mode']` が真なら「初回のベースライン構築」とみなし、
	 *    finding を一切出さずにベースライン行だけを返す(§3.6)。この判定
	 *    (`has_baseline_before_run()`)は DB を読むため呼び出し元の責務とする.
	 *
	 * このクラスは DB に触らない。前回値は `$context['load_previous_states']`
	 * (path の配列を受け取り、path => ベースライン行 を返す callable)経由で受け取る。
	 * 全件を先に渡す形にしないのは、1 target が数万ファイルになりうるため
	 * (このchunkで処理しうる path ぶんだけを1回で引く).
	 *
	 * fingerprint は path 一覧のみから計算し、stat 値は含めない(rev.3 §3.5
	 * 「実装上の落とし穴」: stat 値を入れるとファイルが1つ変わるたびに
	 * `needs_retry` になり、永久に確定できなくなる).
	 *
	 * `lstat()` が失敗した item(`WPCV_Unknown_File_Scanner` は size/ctime/mtime を
	 * すべて0で返す。走査と lstat の間に消えた等の競合)は、finding もベースライン行も
	 * 作らずに読み飛ばす。ベースラインの `last_seen_run_id` が更新されないため、
	 * 本当に消えていれば Step7 の削除検出で `missing` として拾われる.
	 *
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string        $target_id            target_id(`{dimension}:{slug}:_stat`). 必須.
	 *     @type string        $dimension            dimension. 必須.
	 *     @type string        $slug                 slug. 必須.
	 *     @type string|null   $version              本体 target の現在の version.
	 *     @type string|null   $source               source.
	 *     @type int           $run_id               今回の run の id(ベースライン行の
	 *                                               first/last_seen_run_id になる). 必須.
	 *     @type array         $scan_items           `collect_stat => true` で得た
	 *                                               `WPCV_Unknown_File_Scanner::scan()` の戻り値. 必須.
	 *     @type bool          $baseline_mode        真ならベースライン構築のみ(finding なし). 既定 false.
	 *     @type callable|null $load_previous_states `function( string[] $paths ): array`.
	 *                                               path => `array( 'file_size', 'ctime', 'mtime', ... )`
	 *                                               (`wpcv_file_states` の行の形)を返す.
	 *                                               `baseline_mode` が偽なら必須.
	 *     @type string|null   $cursor_path          前回確定した位置. 既定 null.
	 *     @type string|null   $previous_fingerprint 前回保存した fingerprint. 既定 null.
	 *     @type string|null   $previous_version     前回保存した version. 既定 null.
	 *     @type array         $budget               `verify_manifest_chunk()` と同じ形.
	 * }
	 * @return array `verify_manifest_chunk()` と同じ形に `baseline_rows`
	 *               (`WPCV_File_State_Repository::upsert_many()` にそのまま渡せる行の配列)を
	 *               加えたもの. `files_verified_delta` は「前回から変化なしと確認できた件数」
	 *               (ベースライン構築モードでは比較していないので0).
	 */
	public function verify_stat_chunk( array $context ) {
		$scan_items   = (array) $context['scan_items'];
		$sorted_paths = WPCV_Chunk_Cursor::sorted_scan_paths( $scan_items );
		$fingerprint  = WPCV_Chunk_Cursor::compute_fingerprint( $sorted_paths );
		$version      = isset( $context['version'] ) ? (string) $context['version'] : '';

		$previous_fingerprint = $context['previous_fingerprint'] ?? null;
		$previous_version     = $context['previous_version'] ?? null;

		$fingerprint_changed = null !== $previous_fingerprint && $previous_fingerprint !== $fingerprint;
		$version_changed     = null !== $previous_version && $previous_version !== $version;

		if ( $fingerprint_changed || $version_changed ) {
			$result                  = self::retry_result( $fingerprint, count( $sorted_paths ), $fingerprint_changed, $version_changed );
			$result['baseline_rows'] = array();
			return $result;
		}

		$item_by_path = array();
		foreach ( $scan_items as $item ) {
			$item_by_path[ $item['path'] ] = $item;
		}

		$budget = isset( $context['budget'] ) ? (array) $context['budget'] : array();
		$paths  = WPCV_Chunk_Cursor::paths_after( $sorted_paths, $context['cursor_path'] ?? null );

		$baseline_mode   = ! empty( $context['baseline_mode'] );
		$previous_states = $baseline_mode ? array() : $this->load_previous_states( $context, $paths, $budget );

		$target_id = (string) $context['target_id'];
		$dimension = (string) $context['dimension'];
		$slug      = (string) $context['slug'];
		$source    = isset( $context['source'] ) ? (string) $context['source'] : '';
		$run_id    = (int) $context['run_id'];

		$findings             = array();
		$baseline_rows        = array();
		$files_verified_delta = 0;
		$new_cursor_path      = $context['cursor_path'] ?? null;
		$completed            = true;
		$processed            = 0;
		$start                = call_user_func( $this->now );

		foreach ( $paths as $path ) {
			$item    = $item_by_path[ $path ];
			$current = array(
				'size'  => isset( $item['size'] ) ? (int) $item['size'] : 0,
				'ctime' => isset( $item['ctime'] ) ? (int) $item['ctime'] : 0,
				'mtime' => isset( $item['mtime'] ) ? (int) $item['mtime'] : 0,
			);

			// lstat が失敗した item は読み飛ばす. 理由はメソッドの docblock を参照.
			$stat_failed = 0 === $current['size'] && 0 === $current['ctime'] && 0 === $current['mtime'];

			if ( ! $stat_failed ) {
				if ( ! $baseline_mode ) {
					$previous = isset( $previous_states[ $path ] ) ? array(
						'size'  => (int) $previous_states[ $path ]['file_size'],
						'ctime' => (int) $previous_states[ $path ]['ctime'],
						'mtime' => (int) $previous_states[ $path ]['mtime'],
					) : null;

					$finding = WPCV_Verifier::make_finding_for_stat_change( $target_id, $dimension, $slug, $version, $source, $path, (string) $item['severity'], $previous, $current );

					if ( null === $finding ) {
						++$files_verified_delta;
					} else {
						$findings[] = $finding;
					}
				}

				$baseline_rows[] = array(
					'state_key'         => WPCV_File_State_Repository::compute_state_key( $target_id, $path ),
					'target_id'         => $target_id,
					'dimension'         => $dimension,
					'slug'              => $slug,
					'path'              => $path,
					'file_size'         => $current['size'],
					'ctime'             => $current['ctime'],
					'mtime'             => $current['mtime'],
					'content_hash'      => null,
					'hash_algorithm'    => null,
					'baseline_version'  => '' === $version ? null : $version,
					'first_seen_run_id' => $run_id,
					'last_seen_run_id'  => $run_id,
				);
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
			'baseline_rows'        => $baseline_rows,
		);
	}

	/**
	 * このchunkで処理しうる path ぶんの前回ベースラインを、呼び出し元の callable
	 * 経由でまとめて引く(`verify_stat_chunk()` 専用).
	 *
	 * `max_files` 予算があればその件数までに絞る(それ以上は今回のchunkで処理されない
	 * ため)。時間・メモリ予算で先に止まった場合は一部が使われずに終わるが、
	 * 正しさには影響しない.
	 *
	 * @param array    $context `verify_stat_chunk()` の `$context`.
	 * @param string[] $paths   cursor より後の path(ソート済み).
	 * @param array    $budget  予算.
	 * @return array<string, array> path => ベースライン行.
	 *
	 * @throws InvalidArgumentException `load_previous_states` が callable でない場合.
	 */
	private function load_previous_states( array $context, array $paths, array $budget ) {
		if ( ! isset( $context['load_previous_states'] ) || ! is_callable( $context['load_previous_states'] ) ) {
			throw new InvalidArgumentException( 'verify_stat_chunk() requires a callable load_previous_states unless baseline_mode is true.' );
		}

		if ( isset( $budget['max_files'] ) && (int) $budget['max_files'] > 0 ) {
			$paths = array_slice( $paths, 0, (int) $budget['max_files'] );
		}

		if ( empty( $paths ) ) {
			return array();
		}

		$states = call_user_func( $context['load_previous_states'], $paths );

		return is_array( $states ) ? $states : array();
	}

	/**
	 * Fingerprint/version不一致時の戻り値を組み立てる(3つの `verify_*_chunk()` で共有).
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
