<?php
/**
 * WPCV_Previous_Core_Marker クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `core:_scan` の added に「前の version の配布物と同じ内容」の印を付ける(v0.11.0 項目3-1).
 *
 * 本体を更新すると、無くなったファイルが削除されずに残ることがある(開発版への更新で
 * 実際に起きた. WordPress の更新処理が消すのは `$_old_files` に載ったファイルだけ).
 * そうしたファイルは `core:_scan` で added として見つかる. 前の version の配布物の一覧
 * (md5)と中身が一致するファイルに印を付け、画面とメールで「消し残しの可能性が高い」と
 * 分かるようにする. 印は表示だけに使い、重大度や通知の有無は変えない(D2).
 *
 * 判定は、finding を保存する直前(`WPCV_Chunk_Dispatcher::process_core_scan()`)に行う.
 * 差分処理の段階では行わない: run の確定時(`wpcv_run_terminated`)に
 * `WPCV_Manifest_Cache_Cleaner` が前の version のマニフェストをキャッシュから消すため、
 * 差分処理で判定すると本体の更新のたびに WordPress.org への問い合わせが増える
 * (走査の段階なら、前の run が残したキャッシュがまだある).
 *
 * 判定の組み合わせ(2026-10-24 にユーザーが承認):
 *
 * | 基準に同じキー | 前の本体 version | 前のマニフェスト | 読み直した sha256 | md5        | 結果                   |
 * |----------------|------------------|------------------|-------------------|------------|------------------------|
 * | ある(継続)     | -                | -                | -                 | -          | 基準の印を引き継ぐ     |
 * | 無い(新規)     | 分からない       | -                | -                 | -          | 印なし                 |
 * | 無い           | 今と同じ         | -                | -                 | -          | 印なし                 |
 * | 無い           | 今と違う         | 取れた           | finding と一致    | 一覧と一致 | 印を付ける             |
 * | 無い           | 今と違う         | 取れた           | 一致              | 不一致     | 印なし                 |
 * | 無い           | 今と違う         | 取れた           | 不一致・読めない  | -          | 印なし                 |
 * | 無い           | 今と違う         | 取れない         | -                 | -          | 印なし(照合は続ける)   |
 *
 * - 継続の印を引き継ぐので、beta1 → beta2 と続けて更新しても印が残る.
 * - md5 は、判定の対象になったファイルだけを読み直して計算する(走査の時点の全ファイルの
 *   読み込みには手を入れない). 読み直す間にファイルが変わっていれば sha256 が finding の値と
 *   合わないので、印を付けない.
 * - 前のマニフェストはキャッシュに無ければ WordPress.org から取る(`WPCV_Source_Core` が
 *   その version のキャッシュとして保存し、run の終わりに掃除される). 同じインスタンスの中では
 *   version ごとに1回だけ取る(取れなかった結果も覚える).
 */
class WPCV_Previous_Core_Marker {

	/**
	 * `detail` 列の JSON に入れる、印のキー. 値は前の本体の version.
	 *
	 * @var string
	 */
	const DETAIL_KEY = 'same_as_core';

	/**
	 * `wpcv_findings` の永続化層(基準の印を読む).
	 *
	 * @var WPCV_Finding_Repository
	 */
	private $finding_repository;

	/**
	 * `wpcv_target_runs` の永続化層(基準の target_run を探す).
	 *
	 * @var WPCV_Target_Run_Repository
	 */
	private $target_run_repository;

	/**
	 * コアのマニフェストの取得元(キャッシュ付きの `WPCV_Source_Core`).
	 *
	 * @var WPCV_Manifest_Source
	 */
	private $core_source;

	/**
	 * Version => マニフェストの `files`(取れなかったら null). 同じ version を何度も取らないため.
	 *
	 * @var array<string, array|null>
	 */
	private $manifests = array();

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Finding_Repository    $finding_repository    `wpcv_findings` の永続化層.
	 * @param WPCV_Target_Run_Repository $target_run_repository `wpcv_target_runs` の永続化層.
	 * @param WPCV_Manifest_Source       $core_source           コアのマニフェストの取得元.
	 */
	public function __construct( WPCV_Finding_Repository $finding_repository, WPCV_Target_Run_Repository $target_run_repository, WPCV_Manifest_Source $core_source ) {
		$this->finding_repository    = $finding_repository;
		$this->target_run_repository = $target_run_repository;
		$this->core_source           = $core_source;
	}

	/**
	 * `core:_scan` の finding に印を付けて返す(印を付けない finding はそのまま返す).
	 *
	 * 失敗しても例外は外に出さない. 印は表示のための情報なので、判定できないことを理由に
	 * 照合(finding の保存)を止めない.
	 *
	 * @param int    $run_id       今回の run の id.
	 * @param array  $findings     `WPCV_Chunk_Verifier::verify_unknown_files_chunk()` が返した finding.
	 * @param string $core_version 今の本体の version.
	 * @return array 同じ順・同じ件数の finding(印を付けたものは `detail` に JSON が入る).
	 */
	public function mark( $run_id, array $findings, $core_version ) {
		if ( empty( $findings ) ) {
			return $findings;
		}

		try {
			return $this->mark_findings( (int) $run_id, $findings, (string) $core_version );
		} catch ( Throwable $e ) {
			unset( $e );

			return $findings;
		}
	}

	/**
	 * `detail` の JSON から印(前の本体の version)を読む. 印が無ければ null.
	 *
	 * 画面・メール(Step 4・5)からも使う.
	 *
	 * @param string|null $detail `detail` 列の値.
	 * @return string|null
	 */
	public static function marked_version( $detail ) {
		if ( null === $detail || '' === $detail ) {
			return null;
		}

		$decoded = json_decode( (string) $detail, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded[ self::DETAIL_KEY ] ) || ! is_string( $decoded[ self::DETAIL_KEY ] ) || '' === $decoded[ self::DETAIL_KEY ] ) {
			return null;
		}

		return $decoded[ self::DETAIL_KEY ];
	}

	/**
	 * `mark()` の本体.
	 *
	 * @param int    $run_id       今回の run の id.
	 * @param array  $findings     finding の配列.
	 * @param string $core_version 今の本体の version.
	 * @return array
	 */
	private function mark_findings( $run_id, array $findings, $core_version ) {
		$keys = array();

		foreach ( $findings as $index => $finding ) {
			if ( ! self::is_candidate( $finding ) ) {
				continue;
			}

			$keys[ $index ] = WPCV_Finding_Key::compute(
				$finding['target_id'],
				$finding['version'],
				$finding['path'],
				$finding['status'],
				$finding['hash_algorithm'],
				$finding['expected_hash'],
				$finding['actual_hash']
			);
		}

		if ( empty( $keys ) ) {
			return $findings;
		}

		// 継続か新規か: `core:_scan` の基準(今回の run より前の直近の success)に同じキーがあるか.
		// 差分処理(`WPCV_Diff_Dispatcher`)と同じ関数で基準を選ぶ.
		$baseline        = $this->target_run_repository->find_baseline_target_run( WPCV_Finding_Key::VERSIONLESS_TARGET_ID, $run_id );
		$baseline_detail = null === $baseline ? array() : $this->finding_repository->find_details_by_keys( (int) $baseline['id'], array_values( $keys ) );

		// 前の本体の version: `core` の基準の target_run の version.
		$previous_core    = $this->target_run_repository->find_baseline_target_run( WPCV_Target_Resolver::DIMENSION_CORE, $run_id );
		$previous_version = null === $previous_core || null === $previous_core['version'] ? '' : (string) $previous_core['version'];

		foreach ( $keys as $index => $key ) {
			if ( array_key_exists( $key, $baseline_detail ) ) {
				// 継続: 基準に印があれば引き継ぐ. 判定はしない.
				$inherited = self::marked_version( $baseline_detail[ $key ] );

				if ( null !== $inherited ) {
					$findings[ $index ]['detail'] = self::encode_detail( $inherited );
				}
				continue;
			}

			// 新規: 前の本体の version が分からない・今と同じなら判定しない.
			if ( '' === $previous_version || $previous_version === $core_version ) {
				continue;
			}

			if ( $this->matches_previous_release( $findings[ $index ], $previous_version ) ) {
				$findings[ $index ]['detail'] = self::encode_detail( $previous_version );
			}
		}

		return $findings;
	}

	/**
	 * 印の判定の対象になる finding かどうか(`core:_scan` の added で、中身の sha256 があるもの).
	 *
	 * @param array $finding finding.
	 * @return bool
	 */
	private static function is_candidate( array $finding ) {
		return WPCV_Finding_Key::VERSIONLESS_TARGET_ID === ( $finding['target_id'] ?? '' )
			&& 'added' === ( $finding['status'] ?? '' )
			&& WPCV_File_Hasher::ALGO_SHA256 === ( $finding['hash_algorithm'] ?? '' )
			&& ! empty( $finding['actual_hash'] );
	}

	/**
	 * ファイルが、前の version の配布物の同じパスのファイルと同じ内容かを判定する.
	 *
	 * ファイルを1回だけ読み、sha256 と md5 を同時に計算する. sha256 が finding の値と違えば、
	 * 走査のあとで中身が変わったので、判定しない(false).
	 *
	 * @param array  $finding          finding.
	 * @param string $previous_version 前の本体の version.
	 * @return bool
	 */
	private function matches_previous_release( array $finding, $previous_version ) {
		$files = $this->previous_manifest_files( $previous_version );
		$path  = (string) $finding['path'];

		if ( null === $files || ! isset( $files[ $path ]['hashes'] ) ) {
			return false;
		}

		$hashes = self::hash_file_sha256_and_md5( ABSPATH . $path );

		if ( null === $hashes || $hashes['sha256'] !== (string) $finding['actual_hash'] ) {
			return false;
		}

		return in_array( $hashes['md5'], array_map( 'strtolower', (array) $files[ $path ]['hashes'] ), true );
	}

	/**
	 * 前の version のコアのマニフェストの `files` を返す(取れなければ null).
	 *
	 * @param string $version 前の本体の version.
	 * @return array|null
	 */
	private function previous_manifest_files( $version ) {
		if ( ! array_key_exists( $version, $this->manifests ) ) {
			$manifest = $this->core_source->get_manifest( array( 'version' => $version ) );

			$this->manifests[ $version ] = null === $manifest['error_code'] ? (array) $manifest['files'] : null;
		}

		return $this->manifests[ $version ];
	}

	/**
	 * ファイルを1回だけ読んで、sha256 と md5 を返す(読めなければ null).
	 *
	 * `hash_init()` を2つ使い、同じ読み込みから両方を計算する(v0.11 プラン 0.2 の実測で、
	 * md5 を足しても計算時間は小さい).
	 *
	 * @param string $absolute_path 絶対パス.
	 * @return array{sha256: string, md5: string}|null
	 */
	private static function hash_file_sha256_and_md5( $absolute_path ) {
		if ( ! is_readable( $absolute_path ) || is_dir( $absolute_path ) ) {
			return null;
		}

		// is_readable() の確認後も、削除・権限の変更で開けないことがある(WPCV_File_Hasher と同じ理由).
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $absolute_path, 'rb' );

		if ( false === $handle ) {
			return null;
		}

		$sha256 = hash_init( 'sha256' );
		$md5    = hash_init( 'md5' );

		while ( ! feof( $handle ) ) {
			// 64KB ずつ読む. 1回の読み込みの大きさは結果に影響せず、メモリの使用量だけが変わる
			// (対象は本体の消し残しのファイルで、大きくても数百KB).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$chunk = fread( $handle, 65536 );

			if ( false === $chunk ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );

				return null;
			}

			hash_update( $sha256, $chunk );
			hash_update( $md5, $chunk );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return array(
			'sha256' => hash_final( $sha256 ),
			'md5'    => hash_final( $md5 ),
		);
	}

	/**
	 * 印を `detail` 列の JSON にする.
	 *
	 * @param string $previous_version 前の本体の version.
	 * @return string
	 */
	private static function encode_detail( $previous_version ) {
		return (string) wp_json_encode( array( self::DETAIL_KEY => (string) $previous_version ) );
	}
}
