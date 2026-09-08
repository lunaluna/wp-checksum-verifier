<?php
/**
 * WPCV_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `WPCV_Verifier` が返す target_run / finding のデータ構造を DB に永続化する層.
 *
 * 検証ロジック(`WPCV_Verifier`)と DB アクセスを分離する(§4.2: `class-wpcv-verifier.php`
 * と `class-wpcv-repository.php` は別ファイル)。findings がどの target_run に
 * 属するかは `target_id` で相関させる設計(`WPCV_Verifier` の docblock参照)のため、
 * `save_target_runs()` が返す `target_id => target_run_id` の対応表を
 * `save_findings()` にそのまま渡すこと.
 *
 * 既存の `WPCV_API` / `WPCV_Migrator` は `global $wpdb;` を直接参照するが、この
 * クラスは単体テストで実 DB を使わずに検証したいため、コンストラクタで
 * `$wpdb` 相当のオブジェクトを注入できるようにする(既存クラスとの意図的な差異).
 */
class WPCV_Repository {

	/**
	 * `$wpdb` 相当のオブジェクト(`insert()` / `update()` / `base_prefix` / `insert_id` を持つもの).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * 現在時刻(UTC の MySQL DATETIME 文字列)を返す callable.
	 *
	 * テストで固定時刻を注入できるようにするため引数で差し替え可能にする.
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
	 * 実行(run)行を作成する(status = running).
	 *
	 * @param array $args {
	 *     省略可能なオプション.
	 *
	 *     @type string $run_trigger cron|manual|cli|rest. 既定 'manual'
	 *                               (実行モデル・トリガーの実装は §6 で v0.3 対象のため、
	 *                               v0.2 時点では常にこの既定値を使う想定).
	 *     @type string $runner      sync|async. 既定 'sync'.
	 * }
	 * @return int 作成した run の id.
	 */
	public function start_run( array $args = array() ) {
		$run_trigger = isset( $args['run_trigger'] ) ? (string) $args['run_trigger'] : 'manual';
		$runner      = isset( $args['runner'] ) ? (string) $args['runner'] : 'sync';

		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$this->wpdb->insert(
			$table,
			array(
				'started_at'  => call_user_func( $this->now ),
				'status'      => 'running',
				'run_trigger' => $run_trigger,
				'runner'      => $runner,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * 検証対象(target_run)群を保存する.
	 *
	 * @param int   $run_id      `start_run()` が返した run の id.
	 * @param array $target_runs `WPCV_Verifier` の各 `verify_*()` が返す target_run の配列
	 *                           (id/run_id 無し。§5.3 のスキーマに準拠).
	 * @return array `target_id => target_run_id` の対応表(`save_findings()` に渡す).
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
	 * 検出結果(finding)群を保存する.
	 *
	 * @param int   $run_id         `start_run()` が返した run の id.
	 * @param array $target_run_ids `save_target_runs()` が返した `target_id => target_run_id` の対応表.
	 * @param array $findings       `WPCV_Verifier` の各 `verify_*()` が返す findings の配列
	 *                              (id/run_id/target_run_id 無し。§5.5 のスキーマに準拠).
	 * @return void
	 *
	 * @throws InvalidArgumentException 対応する target_run_id が `$target_run_ids` に無い場合(同一バッチの
	 *                                   target_runs と findings の target_id は必ず
	 *                                   一致している前提が崩れている、呼び出し側の実装ミス).
	 */
	public function save_findings( $run_id, array $target_run_ids, array $findings ) {
		$table = $this->wpdb->base_prefix . 'wpcv_findings';

		foreach ( $findings as $finding ) {
			if ( ! isset( $target_run_ids[ $finding['target_id'] ] ) ) {
				throw new InvalidArgumentException(
					esc_html(
						sprintf(
							'WPCV_Repository::save_findings() has no matching target_run_id for target_id: %s',
							$finding['target_id']
						)
					)
				);
			}

			$this->wpdb->insert(
				$table,
				array(
					'run_id'         => $run_id,
					'target_run_id'  => $target_run_ids[ $finding['target_id'] ],
					'target_id'      => $finding['target_id'],
					'dimension'      => $finding['dimension'],
					'slug'           => $finding['slug'],
					'version'        => $finding['version'],
					'source'         => $finding['source'],
					'path'           => $finding['path'],
					'status'         => $finding['status'],
					'severity'       => $finding['severity'],
					'hash_algorithm' => $finding['hash_algorithm'],
					'expected_hash'  => $finding['expected_hash'],
					'actual_hash'    => $finding['actual_hash'],
					'file_size'      => $finding['file_size'],
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);
		}
	}

	/**
	 * 実行(run)行を完了状態にする(status・finished_at・集計値を更新する).
	 *
	 * @param int   $run_id  `start_run()` が返した run の id.
	 * @param array $summary `WPCV_Verifier::summarize()` の戻り値.
	 * @return void
	 */
	public function finish_run( $run_id, array $summary ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		$this->wpdb->update(
			$table,
			array(
				'finished_at'          => call_user_func( $this->now ),
				'status'               => $summary['status'],
				'targets_total'        => $summary['targets_total'],
				'targets_verified'     => $summary['targets_verified'],
				'targets_unverifiable' => $summary['targets_unverifiable'],
				'targets_failed'       => $summary['targets_failed'],
				'findings_total'       => $summary['findings_total'],
			),
			array( 'id' => $run_id ),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * 一定時間より古い `status = 'running'` の run を `failed` に更新する(v0.3 §Step5).
	 *
	 * 「1アクション=1run全体」の簡略化(v0.3のスコープ縮小。実装セッションへの
	 * 申し送り参照)のトレードオフとして、途中で強制終了し `running` のまま残留
	 * した run が次の run を永久にブロックし続ける事態を避けるための、WPMAR流の
	 * 軽量なハートビート途絶検知(WPMARの `sweep_stale_running()` と同じ
	 * 「アクセスのたびに掃除する」方式。専用の Cron は立てない).
	 *
	 * 判定基準は `started_at` のみを使う(`updated_at` 相当の列は追加しない):
	 * このプラグインの run は実行途中で行を更新しない(target_runs・findings は
	 * すべて `finish_run()` の直前にまとめて保存する設計。`WPCV_Run_Coordinator`
	 * の docblock 参照)ため、`started_at` より新しい「途中経過」の時刻は
	 * そもそも存在しない。WPMAR の `updated_at`(セグメント単位で進捗を刻む
	 * 設計だからこそ意味を持つハートビート)とは前提が異なる.
	 *
	 * v0.3 では専用の `aborted` 状態は導入せず、既存の `failed` を流用する
	 * (§14 のv0.4以降のスコープとした本格的なタイムアウト状態機械とは区別する).
	 *
	 * @param int $minutes この分数より古い `started_at` を stale とみなす.
	 * @return int stale と判定し `failed` に更新した run の件数.
	 */
	public function sweep_stale_running( $minutes ) {
		$table = $this->wpdb->base_prefix . 'wpcv_runs';

		// 動的な値を含まない固定リテラルのみのクエリ(status/日時の絞り込みは
		// 下の PHP 側で行う).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table literal, no user input.
		$running_rows = $this->wpdb->get_results( "SELECT id, started_at, status FROM {$table} WHERE status = 'running'", ARRAY_A );
		$running_rows = is_array( $running_rows ) ? $running_rows : array();

		$threshold = $this->stale_threshold( (int) $minutes );
		$swept     = 0;

		foreach ( $running_rows as $row ) {
			// status も改めて確認する(SQL の WHERE 句と重複するが、テストダブル
			// (`WPCV_Test_Fake_WPDB::get_results()`)が WHERE 句を解釈せず
			// テーブルの全行を返す簡易実装のための保険でもある).
			if ( 'running' !== $row['status'] ) {
				continue;
			}

			if ( empty( $row['started_at'] ) || (string) $row['started_at'] >= $threshold ) {
				continue;
			}

			$this->wpdb->update(
				$table,
				array(
					'status'      => 'failed',
					'finished_at' => call_user_func( $this->now ),
					'notes'       => 'sweep_stale_running() により stale な running run として検知し failed 化しました.',
				),
				array( 'id' => (int) $row['id'] ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			++$swept;
		}

		return $swept;
	}

	/**
	 * 現在時刻(`$this->now`)から `$minutes` 分前の MySQL DATETIME 文字列を求める.
	 *
	 * @param int $minutes 分数.
	 * @return string
	 */
	private function stale_threshold( $minutes ) {
		$now_timestamp = strtotime( call_user_func( $this->now ) );

		return gmdate( 'Y-m-d H:i:s', $now_timestamp - ( $minutes * 60 ) );
	}
}
