<?php
/**
 * WPCV_Target_Run_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_target_runs` テーブルの永続化を担当する(v0.4.0 §Step1でWPCV_Repositoryから分割).
 *
 * 分割の経緯は `WPCV_Run_Repository` のクラス docblock 参照。v0.4.0 §Step3で
 * `WPCV_Chunk_Verifier` の結果(cursor/manifest_fingerprint等)を書き込む
 * `update_chunk_progress()`/`reset_for_retry()` を追加した.
 */
class WPCV_Target_Run_Repository {

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `update()` / `get_results()` /
	 * `base_prefix` / `insert_id` を持つもの).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * 現在時刻(UTC の MySQL DATETIME 文字列)を返す callable.
	 *
	 * テストで固定時刻を注入できるようにするため引数で差し替え可能にする
	 * (`WPCV_Run_Repository` と同じパターン. v0.4.0 §Step3で `update_chunk_progress()`
	 * の `finished_at` に使うために追加).
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * コンストラクタ.
	 *
	 * @param object        $wpdb `$wpdb` 相当のオブジェクト.
	 * @param callable|null $now  現在時刻を返す callable. 省略時は `gmdate( 'Y-m-d H:i:s' )`.
	 */
	public function __construct( $wpdb, ?callable $now = null ) {
		$this->wpdb = $wpdb;
		$this->now  = $now ?? static function () {
			return gmdate( 'Y-m-d H:i:s' );
		};
	}

	/**
	 * 検証対象(target_run)群を保存する.
	 *
	 * @param int   $run_id      `WPCV_Run_Repository::reserve_run()` が返した run の id.
	 * @param array $target_runs `WPCV_Verifier` の各 `verify_*()` が返す target_run の配列
	 *                           (id/run_id 無し。§5.3 のスキーマに準拠).
	 * @return array `target_id => target_run_id` の対応表(`WPCV_Finding_Repository::save_findings()` に渡す).
	 */
	public function save_target_runs( $run_id, array $target_runs ) {
		$table          = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$target_run_ids = array();

		foreach ( $target_runs as $target_run ) {
			$this->wpdb->insert(
				$table,
				array(
					'run_id'          => $run_id,
					'target_id'       => $target_run['target_id'],
					'dimension'       => $target_run['dimension'],
					'slug'            => $target_run['slug'],
					'version'         => $target_run['version'],
					'source'          => $target_run['source'],
					'source_ref'      => $target_run['source_ref'],
					'manifest_status' => $target_run['manifest_status'],
					'status'          => $target_run['status'],
					'error_code'      => $target_run['error_code'],
					'error_message'   => $target_run['error_message'],
					'files_total'     => $target_run['files_total'],
					'files_verified'  => $target_run['files_verified'],
					'findings_total'  => $target_run['findings_total'],
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
			);

			$target_run_ids[ $target_run['target_id'] ] = (int) $this->wpdb->insert_id;
		}

		return $target_run_ids;
	}

	/**
	 * `WPCV_Chunk_Verifier::verify_manifest_chunk()`/`verify_unknown_files_chunk()` の
	 * 結果(`needs_retry: false` の場合)を target_run 行へ反映する(v0.4.0 §Step3).
	 *
	 * `files_verified`/`findings_total` は前回までの累積値に今回の増分
	 * (`files_verified_delta`・`count( findings )`)を加算する(chunk単位の
	 * 呼び出しを重ねるたびに積み上がっていく値のため)。加算前の現在値を
	 * 読み取ってから `update()` に渡す方式にしたのは、`$wpdb->update()` が
	 * `SET files_verified = files_verified + %d` のような式を組み立てられない
	 * ため(生SQLでの直接更新も可能だが、テストダブル〔`WPCV_Test_Fake_WPDB`〕の
	 * 複雑化を避けるため見送った).
	 *
	 * `$chunk_result['completed']` が真の場合、この chunk 呼び出しで対象集合を
	 * 最後まで処理できたとみなし、`status` を `WPCV_Target_Status::SUCCESS` へ
	 * 進め `finished_at` を記録する(manifest取得自体の成功可否は呼び出し元が
	 * chunk verifier を呼ぶ前提条件のため、ここでは判定しない).
	 *
	 * @param int   $target_run_id 対象の target_run の id.
	 * @param array $chunk_result  `WPCV_Chunk_Verifier::verify_*_chunk()` の戻り値
	 *                             (`needs_retry: false` のもの).
	 * @return bool 更新できたら true。false は対象行が見つからなかったことを意味する.
	 */
	public function update_chunk_progress( $target_run_id, array $chunk_result ) {
		$table   = $this->wpdb->base_prefix . 'wpcv_target_runs';
		$current = $this->find_by_id( (int) $target_run_id );

		if ( null === $current ) {
			return false;
		}

		$files_verified = (int) $current['files_verified'] + (int) $chunk_result['files_verified_delta'];
		$findings_total = (int) $current['findings_total'] + count( $chunk_result['findings'] );

		$data   = array(
			'cursor_path'          => $chunk_result['cursor_path'],
			'manifest_fingerprint' => $chunk_result['manifest_fingerprint'],
			'files_total'          => (int) $chunk_result['files_total'],
			'files_verified'       => $files_verified,
			'findings_total'       => $findings_total,
		);
		$format = array( '%s', '%s', '%d', '%d', '%d' );

		if ( $chunk_result['completed'] ) {
			$data['status']      = WPCV_Target_Status::SUCCESS;
			$data['finished_at'] = call_user_func( $this->now );
			$format[]            = '%s';
			$format[]            = '%s';
		}

		$updated = $this->wpdb->update(
			$table,
			$data,
			array( 'id' => (int) $target_run_id ),
			$format,
			array( '%d' )
		);

		return $updated > 0;
	}

	/**
	 * Fingerprint/versionの不一致を検知した target_run を `WPCV_Target_Status::RETRY` へ
	 * 戻し、cursor・集計値をリセットする(v0.4.0 §Step3。
	 * `WPCV_Chunk_Verifier::verify_*_chunk()` が `needs_retry: true` を返した場合に呼ぶ).
	 *
	 * 集計値をリセットするのは、対象集合が変わった(プラグイン更新等)ことで
	 * 「前回どこまで確認できていたか」の情報自体が意味を失うため。次回の
	 * chunk呼び出しは、この target_run を最初から(`cursor_path = null` として)
	 * 再検証する.
	 *
	 * @param int         $target_run_id        対象の target_run の id.
	 * @param string|null $manifest_fingerprint 今回計算し直した fingerprint
	 *                                          (次回の照合基準として保存しておく).
	 * @return bool 更新できたら true。false は対象行が見つからなかったことを意味する.
	 */
	public function reset_for_retry( $target_run_id, $manifest_fingerprint ) {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';

		$updated = $this->wpdb->update(
			$table,
			array(
				'status'               => WPCV_Target_Status::RETRY,
				'cursor_path'          => null,
				'manifest_fingerprint' => $manifest_fingerprint,
				'files_total'          => 0,
				'files_verified'       => 0,
				'findings_total'       => 0,
			),
			array( 'id' => (int) $target_run_id ),
			array( '%s', '%s', '%s', '%d', '%d', '%d' ),
			array( '%d' )
		);

		return $updated > 0;
	}

	/**
	 * Id から target_run 行を1件読み取る(`update_chunk_progress()` の内部ヘルパー).
	 *
	 * @param int $target_run_id 対象の target_run の id.
	 * @return array|null 見つからなければ null.
	 */
	private function find_by_id( $target_run_id ) {
		$table = $this->wpdb->base_prefix . 'wpcv_target_runs';

		// 動的な値を含まない固定リテラルのみのクエリ(id の絞り込みは下の PHP 側で行う。
		// `WPCV_Run_Repository::find_active_run()` と同じ理由でのignore).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$rows = $this->wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as $row ) {
			if ( (int) $row['id'] === (int) $target_run_id ) {
				return $row;
			}
		}

		return null;
	}
}
