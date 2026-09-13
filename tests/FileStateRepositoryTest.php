<?php
/**
 * WPCV_File_State_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-file-state-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wpcv_file_states` の永続化層のテスト(v0.5 §4.2 Step2)。
 *
 * `WPCV_File_State_Repository` のクラスdocblockに書いたとおり、
 * `find_by_state_keys()`/`find_stale()`/`has_baseline_before_run()` はSQLの
 * `WHERE`句とPHP側の再フィルタを両方持つ設計だが、テストダブル
 * `WPCV_Test_Fake_WPDB::get_results()` はWHERE句を解釈せずテーブル全体を
 * 返すため、ここではPHP側の再フィルタの正しさを検証していることになる
 * (`WPCV_Finding_Repository::query()` のテストと同じ考え方).
 */
class FileStateRepositoryTest extends TestCase {

	/**
	 * テスト用の行データを1件分作る.
	 *
	 * @param array $overrides 上書きするフィールド.
	 * @return array
	 */
	private function make_row( array $overrides = array() ) {
		$target_id = $overrides['target_id'] ?? 'plugin:acme-widgets';
		$path      = $overrides['path'] ?? 'plugin/acme-widgets/acme-widgets.php';

		return array_merge(
			array(
				'state_key'         => WPCV_File_State_Repository::compute_state_key( $target_id, $path ),
				'target_id'         => $target_id,
				'dimension'         => 'plugin',
				'slug'              => 'acme-widgets',
				'path'              => $path,
				'file_size'         => 4021,
				'ctime'             => 1757000000,
				'mtime'             => 1740000000,
				'content_hash'      => null,
				'hash_algorithm'    => null,
				'baseline_version'  => '1.2.0',
				'first_seen_run_id' => 10,
				'last_seen_run_id'  => 10,
			),
			$overrides
		);
	}

	/**
	 * `compute_state_key()` が同じ入力から常に同じ32バイトの値を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_compute_state_key_is_deterministic_and_32_bytes() {
		$a = WPCV_File_State_Repository::compute_state_key( 'plugin:acme-widgets', 'acme-widgets.php' );
		$b = WPCV_File_State_Repository::compute_state_key( 'plugin:acme-widgets', 'acme-widgets.php' );

		$this->assertSame( $a, $b );
		$this->assertSame( 32, strlen( $a ) );
	}

	/**
	 * `compute_state_key()` が `target_id`/`path` の連結ではなく `\0` 区切りで
	 * 計算するため、`target_id`と`path`の境界が異なる組み合わせでも衝突しない
	 * ことを確認する(クラスdocblock参照).
	 *
	 * @return void
	 */
	public function test_compute_state_key_differs_for_different_target_path_boundaries() {
		$a = WPCV_File_State_Repository::compute_state_key( 'a', 'bc' );
		$b = WPCV_File_State_Repository::compute_state_key( 'ab', 'c' );

		$this->assertNotSame( $a, $b );
	}

	/**
	 * `find_by_state_keys()` に空配列を渡すと空配列を返す(クエリを発行しない
	 * ショートサーキット)ことを確認する.
	 *
	 * @return void
	 */
	public function test_find_by_state_keys_returns_empty_array_for_empty_input() {
		$repository = new WPCV_File_State_Repository( new WPCV_Test_Fake_WPDB() );

		$this->assertSame( array(), $repository->find_by_state_keys( array() ) );
	}

	/**
	 * `find_by_state_keys()` が指定した state_key に一致する行だけを返し、
	 * 一致しない行を含まないことを確認する.
	 *
	 * @return void
	 */
	public function test_find_by_state_keys_returns_only_matching_rows() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_File_State_Repository( $wpdb );

		$row_a = $this->make_row( array( 'path' => 'a.php' ) );
		$row_b = $this->make_row( array( 'path' => 'b.php' ) );
		$row_c = $this->make_row( array( 'path' => 'c.php' ) );

		$repository->upsert_many( array( $row_a, $row_b, $row_c ) );

		$found = $repository->find_by_state_keys( array( $row_a['state_key'], $row_c['state_key'] ) );

		$this->assertCount( 2, $found );

		$found_paths = array_column( $found, 'path' );
		sort( $found_paths );

		$this->assertSame( array( 'a.php', 'c.php' ), $found_paths );
	}

	/**
	 * `upsert_many()` に空配列を渡すと `query()` を一切呼ばないことを確認する.
	 *
	 * @return void
	 */
	public function test_upsert_many_does_nothing_for_empty_input() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_File_State_Repository( $wpdb );

		$repository->upsert_many( array() );

		$this->assertCount( 0, $wpdb->query_calls );
	}

	/**
	 * `upsert_many()` がN行を1回の `query()` 呼び出し(1クエリ)にまとめることを
	 * 確認する(rev.3 §3.5「実装上の落とし穴」で明示的に要求されている性質).
	 *
	 * @return void
	 */
	public function test_upsert_many_issues_a_single_query_for_multiple_rows() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_File_State_Repository( $wpdb );

		$repository->upsert_many(
			array(
				$this->make_row( array( 'path' => 'a.php' ) ),
				$this->make_row( array( 'path' => 'b.php' ) ),
				$this->make_row( array( 'path' => 'c.php' ) ),
			)
		);

		$this->assertCount( 1, $wpdb->query_calls );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $wpdb->query_calls[0] );
		// VALUES句が3タプル分含まれること(大まかな検証。厳密な構文検証は
		// find_by_state_keys() 経由の反映結果で行う).
		$this->assertSame( 2, substr_count( $wpdb->query_calls[0], '),(' ) + substr_count( $wpdb->query_calls[0], '), (' ) );
	}

	/**
	 * `upsert_many()` で新規挿入した行が `find_by_state_keys()` で正しく読み取れる
	 * (NULL値の列も含めて正しく保存される)ことを確認する.
	 *
	 * @return void
	 */
	public function test_upsert_many_persists_new_row_with_null_columns() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_File_State_Repository( $wpdb );

		$row = $this->make_row();

		$repository->upsert_many( array( $row ) );

		$found = $repository->find_by_state_keys( array( $row['state_key'] ) );

		$this->assertCount( 1, $found );
		$this->assertSame( $row['target_id'], $found[0]['target_id'] );
		$this->assertSame( $row['path'], $found[0]['path'] );
		$this->assertSame( (string) $row['file_size'], (string) $found[0]['file_size'] );
		$this->assertNull( $found[0]['content_hash'] );
		$this->assertNull( $found[0]['hash_algorithm'] );
	}

	/**
	 * `upsert_many()` を同じ `state_key` で2回呼ぶと、1回目の行が更新される
	 * (新規行として重複挿入されない)ことを確認する.
	 *
	 * @return void
	 */
	public function test_upsert_many_updates_existing_row_with_same_state_key() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_File_State_Repository( $wpdb );

		$row = $this->make_row( array( 'file_size' => 4021 ) );
		$repository->upsert_many( array( $row ) );

		$updated_row               = $row;
		$updated_row['file_size']  = 4160;
		$updated_row['ctime']      = 1757600000;
		$updated_row['last_seen_run_id'] = 11;
		$repository->upsert_many( array( $updated_row ) );

		$found = $repository->find_by_state_keys( array( $row['state_key'] ) );

		$this->assertCount( 1, $found, 'state_key が同じ行は重複挿入されず更新されるべき' );
		$this->assertSame( '4160', (string) $found[0]['file_size'] );
		$this->assertSame( '1757600000', (string) $found[0]['ctime'] );
		$this->assertSame( '11', (string) $found[0]['last_seen_run_id'] );
	}

	/**
	 * `upsert_many()` で既存行を更新しても `first_seen_run_id` は最初の値を
	 * 保持し続ける(上書きされない)ことを確認する(クラスdocblock参照:
	 * 「いつからこのファイルが存在するか」の情報を保つため).
	 *
	 * @return void
	 */
	public function test_upsert_many_does_not_overwrite_first_seen_run_id_on_update() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_File_State_Repository( $wpdb );

		$row = $this->make_row( array( 'first_seen_run_id' => 5, 'last_seen_run_id' => 5 ) );
		$repository->upsert_many( array( $row ) );

		$later_row                      = $row;
		$later_row['first_seen_run_id'] = 99; // 呼び出し側が誤って別の値を渡しても無視されるべき.
		$later_row['last_seen_run_id']  = 20;
		$repository->upsert_many( array( $later_row ) );

		$found = $repository->find_by_state_keys( array( $row['state_key'] ) );

		$this->assertSame( '5', (string) $found[0]['first_seen_run_id'] );
		$this->assertSame( '20', (string) $found[0]['last_seen_run_id'] );
	}

	/**
	 * `find_stale()` が指定した `target_id` かつ `last_seen_run_id` が現在の
	 * run より前の行のみを返し、他targetの行・最新の行を含まないことを確認する.
	 *
	 * @return void
	 */
	public function test_find_stale_returns_only_rows_for_target_before_current_run() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_File_State_Repository( $wpdb );

		$stale_row       = $this->make_row( array( 'path' => 'stale.php', 'last_seen_run_id' => 10 ) );
		$fresh_row       = $this->make_row( array( 'path' => 'fresh.php', 'last_seen_run_id' => 12 ) );
		$other_target    = $this->make_row(
			array(
				'target_id'        => 'plugin:other',
				'path'             => 'stale.php',
				'last_seen_run_id' => 10,
			)
		);

		$repository->upsert_many( array( $stale_row, $fresh_row, $other_target ) );

		$found = $repository->find_stale( 'plugin:acme-widgets', 12 );

		$this->assertCount( 1, $found );
		$this->assertSame( 'stale.php', $found[0]['path'] );
	}

	/**
	 * `delete_stale()` が該当行を削除し、削除した行を返すことを確認する。
	 * 2回目の呼び出しでは既に削除済みのため空配列になることも確認する.
	 *
	 * @return void
	 */
	public function test_delete_stale_deletes_matching_rows_and_returns_them() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_File_State_Repository( $wpdb );

		$stale_row = $this->make_row( array( 'path' => 'stale.php', 'last_seen_run_id' => 10 ) );
		$fresh_row = $this->make_row( array( 'path' => 'fresh.php', 'last_seen_run_id' => 12 ) );

		$repository->upsert_many( array( $stale_row, $fresh_row ) );

		$deleted = $repository->delete_stale( 'plugin:acme-widgets', 12 );

		$this->assertCount( 1, $deleted );
		$this->assertSame( 'stale.php', $deleted[0]['path'] );

		$remaining = $repository->find_by_state_keys( array( $stale_row['state_key'], $fresh_row['state_key'] ) );
		$this->assertCount( 1, $remaining );
		$this->assertSame( 'fresh.php', $remaining[0]['path'] );

		$this->assertSame( array(), $repository->delete_stale( 'plugin:acme-widgets', 12 ) );
	}

	/**
	 * `delete_by_target()` が指定した target の行だけを削除し、他targetの行に
	 * 影響しないことを確認する.
	 *
	 * @return void
	 */
	public function test_delete_by_target_deletes_only_rows_for_that_target() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_File_State_Repository( $wpdb );

		$row_a = $this->make_row( array( 'path' => 'a.php' ) );
		$row_b = $this->make_row( array( 'target_id' => 'plugin:other', 'path' => 'b.php' ) );

		$repository->upsert_many( array( $row_a, $row_b ) );

		$deleted_count = $repository->delete_by_target( 'plugin:acme-widgets' );

		$this->assertSame( 1, $deleted_count );

		$remaining = $repository->find_by_state_keys( array( $row_a['state_key'], $row_b['state_key'] ) );
		$this->assertCount( 1, $remaining );
		$this->assertSame( 'plugin:other', $remaining[0]['target_id'] );
	}

	/**
	 * `has_baseline_before_run()` が、対象targetに過去runの行があれば true を
	 * 返すことを確認する.
	 *
	 * @return void
	 */
	public function test_has_baseline_before_run_returns_true_when_prior_run_row_exists() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_File_State_Repository( $wpdb );

		$repository->upsert_many( array( $this->make_row( array( 'last_seen_run_id' => 10 ) ) ) );

		$this->assertTrue( $repository->has_baseline_before_run( 'plugin:acme-widgets', 11 ) );
	}

	/**
	 * `has_baseline_before_run()` が、その target に一度も行が無ければ false を
	 * 返すことを確認する(初回実行=ベースライン構築モード).
	 *
	 * @return void
	 */
	public function test_has_baseline_before_run_returns_false_when_no_rows_exist() {
		$repository = new WPCV_File_State_Repository( new WPCV_Test_Fake_WPDB() );

		$this->assertFalse( $repository->has_baseline_before_run( 'plugin:acme-widgets', 1 ) );
	}

	/**
	 * `has_baseline_before_run()` が「今回のrunで書いた行」を除外することを
	 * 確認する(rev.3 §3.6: 「その target の行数が0か」で判定すると、最初の
	 * chunkが upsert した瞬間に破綻するバグを防ぐための核心的な性質。
	 * v0.5 §4.2 Step2の完了条件に明記されているテスト).
	 *
	 * @return void
	 */
	public function test_has_baseline_before_run_excludes_rows_written_by_current_run() {
		$repository = new WPCV_File_State_Repository( new WPCV_Test_Fake_WPDB() );

		// 今回のrun(run_id=20)の最初のchunkが、ちょうどこのtargetの最初のpathを
		// upsertした直後の状態を模す(last_seen_run_id=20はまだ「前回」ではない).
		$repository->upsert_many(
			array(
				$this->make_row(
					array(
						'first_seen_run_id' => 20,
						'last_seen_run_id'  => 20,
					)
				),
			)
		);

		$this->assertFalse(
			$repository->has_baseline_before_run( 'plugin:acme-widgets', 20 ),
			'今回のrunが書いた行だけでは「既存ベースラインあり」と誤判定してはならない'
		);
	}
}
