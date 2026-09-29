<?php
/**
 * WPCV_Finding_Repository のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-finding-key.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-status.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-generation-differ.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-finding-repository.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `wpcv_findings` の DB 永続化(§4.2: Repository 層。v0.4.0 §Step1で
 * `WPCV_Repository` から分割)のテスト.
 */
class FindingRepositoryTest extends TestCase {

	/**
	 * save_findings() が target_run_ids から target_run_id を解決して
	 * findings テーブルに insert することを確認する.
	 *
	 * @return void
	 */
	public function test_save_findings_resolves_target_run_id() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$repository->save_findings(
			42,
			array( 'core' => 7 ),
			array( wpcv_test_make_finding( array( 'target_id' => 'core' ) ) )
		);

		$row = $wpdb->rows['wp_wpcv_findings'][1];
		$this->assertSame( 42, $row['run_id'] );
		$this->assertSame( 7, $row['target_run_id'] );
		$this->assertSame( 'core', $row['target_id'] );
		$this->assertSame( 'wp-admin/index.php', $row['path'] );
	}

	/**
	 * 対応する target_run_id が無い finding を渡すと例外を投げることを確認する
	 * (target_runs と findings の target_id は同一バッチ内で必ず一致している前提のため).
	 *
	 * @return void
	 */
	public function test_save_findings_throws_when_target_run_id_missing() {
		$this->expectException( InvalidArgumentException::class );

		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$repository->save_findings(
			42,
			array(),
			array( wpcv_test_make_finding( array( 'target_id' => 'core' ) ) )
		);
	}

	/**
	 * `save_findings()` が `$wpdb->insert()` の失敗(`false`)を検知して
	 * `RuntimeException` を投げることを確認する(v0.4.0コードレビューCR-03是正:
	 * DB容量不足・接続断等でinsertが `false` を返しても気付かず処理を続けると、
	 * 呼び出し元 `WPCV_Chunk_Result_Repository::commit_chunk()` がfindingを
	 * 1件も保存できないまま後続のcursor更新・COMMITへ進んでしまう).
	 *
	 * @return void
	 */
	public function test_save_findings_throws_when_insert_fails() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$wpdb->insert_should_fail = true;

		$this->expectException( RuntimeException::class );

		$repository->save_findings(
			42,
			array( 'core' => 7 ),
			array( wpcv_test_make_finding( array( 'target_id' => 'core' ) ) )
		);
	}

	/**
	 * `delete_by_target_run_id()` が指定 target_run_id の finding だけを削除し、
	 * 他の target_run_id の finding は残すことを確認する(v0.4.0コードレビュー
	 * CR-04是正: fingerprint/version変更時に前世代のfindingsを削除するために追加).
	 *
	 * @return void
	 */
	public function test_delete_by_target_run_id_removes_only_matching_rows() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$repository->save_findings(
			42,
			array( 'core' => 7 ),
			array( wpcv_test_make_finding( array( 'target_id' => 'core' ) ) )
		);
		$repository->save_findings(
			42,
			array( 'plugin:akismet' => 8 ),
			array( wpcv_test_make_finding( array( 'target_id' => 'plugin:akismet' ) ) )
		);

		$repository->delete_by_target_run_id( 7 );

		$remaining = array_values( $wpdb->rows['wp_wpcv_findings'] );
		$this->assertCount( 1, $remaining );
		$this->assertSame( 8, $remaining[0]['target_run_id'] );
	}

	/**
	 * `delete_by_target_run_id()` が、`$wpdb->delete()` の失敗(`false`)を検知して
	 * `RuntimeException` を投げることを確認する(v0.4.0コードレビューCR-03是正と
	 * 同じ理由。これを確認しないと、削除したつもりで実際には旧世代findingsが
	 * 残ったままcursorだけリセットされる不整合が起こり得る).
	 *
	 * @return void
	 */
	public function test_delete_by_target_run_id_throws_when_delete_fails() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$wpdb->delete_should_fail = true;

		$this->expectException( RuntimeException::class );

		$repository->delete_by_target_run_id( 7 );
	}

	/**
	 * `query()` が指定 run_id 以外の finding を含めないことを確認する
	 * (v0.4.0 §Step7).
	 *
	 * @return void
	 */
	public function test_query_filters_by_run_id() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'a.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 2, 'path' => 'b.php' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );
		$result     = $repository->query( array( 'run_id' => 1 ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'a.php', $result['rows'][0]['path'] );
	}

	/**
	 * `query()` が `dimension`/`status`/`severity` の allowlist フィルタを適用することを確認する.
	 *
	 * @return void
	 */
	public function test_query_filters_by_dimension_status_and_severity() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'core.php', 'dimension' => 'core', 'status' => 'modified', 'severity' => 'high' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'plugin.php', 'dimension' => 'plugin', 'status' => 'added', 'severity' => 'medium' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$this->assertSame(
			array( 'core.php' ),
			array_column( $repository->query( array( 'run_id' => 1, 'dimension' => array( 'core' ) ) )['rows'], 'path' )
		);
		$this->assertSame(
			array( 'plugin.php' ),
			array_column( $repository->query( array( 'run_id' => 1, 'status' => array( 'added' ) ) )['rows'], 'path' )
		);
		$this->assertSame(
			array( 'core.php' ),
			array_column( $repository->query( array( 'run_id' => 1, 'severity' => array( 'high' ) ) )['rows'], 'path' )
		);
	}

	/**
	 * `query()` が `diff_state` の allowlist フィルタを適用することを確認する
	 * (v0.5後半 §16・Q1).
	 *
	 * @return void
	 */
	public function test_query_filters_by_diff_state() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'new.php', 'diff_state' => WPCV_Generation_Differ::DIFF_STATE_NEW ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'continuing.php', 'diff_state' => WPCV_Generation_Differ::DIFF_STATE_CONTINUING ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$this->assertSame(
			array( 'new.php' ),
			array_column( $repository->query( array( 'run_id' => 1, 'diff_state' => array( 'new' ) ) )['rows'], 'path' )
		);
		$this->assertSame(
			array( 'new.php', 'continuing.php' ),
			array_column( $repository->query( array( 'run_id' => 1 ) )['rows'], 'path' ),
			'diff_state を渡さなければ絞り込まない'
		);
	}

	/**
	 * `query()` が既定で suppressed/closed の finding を除外し、
	 * `include_suppressed`/`include_closed` で含められることを確認する.
	 *
	 * @return void
	 */
	public function test_query_excludes_suppressed_and_closed_by_default() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'open.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'suppressed.php', 'suppressed_by' => 'soft_change' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'closed.php', 'closed_at' => '2026-09-08 00:00:00', 'closed_reason' => 'fixed' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$default_result = $repository->query( array( 'run_id' => 1 ) );
		$this->assertSame( array( 'open.php' ), array_column( $default_result['rows'], 'path' ) );
		$this->assertSame( 1, $default_result['total'] );

		$with_suppressed = $repository->query( array( 'run_id' => 1, 'include_suppressed' => true ) );
		$this->assertContains( 'suppressed.php', array_column( $with_suppressed['rows'], 'path' ) );
		$this->assertNotContains( 'closed.php', array_column( $with_suppressed['rows'], 'path' ) );

		$with_closed = $repository->query( array( 'run_id' => 1, 'include_closed' => true ) );
		$this->assertContains( 'closed.php', array_column( $with_closed['rows'], 'path' ) );
	}

	/**
	 * `query()` が `sort`/`order` に従って並べ替えることを確認する
	 * (allowlist外の`sort`は`id`にフォールバックする).
	 *
	 * @return void
	 */
	public function test_query_sorts_by_allowlisted_column() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'b.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => 'a.php' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$asc = $repository->query( array( 'run_id' => 1, 'sort' => 'path', 'order' => 'asc' ) );
		$this->assertSame( array( 'a.php', 'b.php' ), array_column( $asc['rows'], 'path' ) );

		$desc = $repository->query( array( 'run_id' => 1, 'sort' => 'path', 'order' => 'desc' ) );
		$this->assertSame( array( 'b.php', 'a.php' ), array_column( $desc['rows'], 'path' ) );
	}

	/**
	 * `query()` が `page`/`per_page` に従って結果を分割し、`total` には
	 * pagination前の全件数を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_query_paginates_results() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		foreach ( range( 1, 5 ) as $i ) {
			$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => "file{$i}.php" ) ) );
		}

		$repository = new WPCV_Finding_Repository( $wpdb );

		$page1 = $repository->query( array( 'run_id' => 1, 'per_page' => 2, 'page' => 1 ) );
		$this->assertSame( array( 'file1.php', 'file2.php' ), array_column( $page1['rows'], 'path' ) );
		$this->assertSame( 5, $page1['total'] );

		$page3 = $repository->query( array( 'run_id' => 1, 'per_page' => 2, 'page' => 3 ) );
		$this->assertSame( array( 'file5.php' ), array_column( $page3['rows'], 'path' ) );
	}

	/**
	 * `detail` を持つ finding(stat_changed)は `detail` 列に保存され、持たない finding は
	 * NULL になることを確認する(v0.5 §Step6. Step1 で列を追加したが保存が漏れていた).
	 *
	 * @return void
	 */
	public function test_save_findings_persists_detail_column() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$repository->save_findings(
			1,
			array( 'core' => 7 ),
			array(
				wpcv_test_make_finding( array( 'detail' => '{"timestomp":true}' ) ),
				wpcv_test_make_finding(),
			)
		);

		$this->assertSame( '{"timestomp":true}', $wpdb->rows['wp_wpcv_findings'][1]['detail'] );
		$this->assertNull( $wpdb->rows['wp_wpcv_findings'][2]['detail'] );
	}

	/**
	 * `save_findings()` が `WPCV_Finding_Key::compute()` と同じ値を `finding_key` 列に
	 * 保存することを確認する(v0.5後半 §Step10. 差分処理〔Step12以降〕はこの列を
	 * 読むだけで計算し直さない設計のため、保存時の値が計算式と一致している
	 * ことが前提になる).
	 *
	 * @return void
	 */
	public function test_save_findings_persists_finding_key() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$finding = wpcv_test_make_finding( array( 'target_id' => 'core' ) );

		$repository->save_findings( 1, array( 'core' => 7 ), array( $finding ) );

		$expected = WPCV_Finding_Key::compute(
			$finding['target_id'],
			$finding['version'],
			$finding['path'],
			$finding['status'],
			$finding['hash_algorithm'],
			$finding['expected_hash'],
			$finding['actual_hash']
		);

		$this->assertSame( $expected, $wpdb->rows['wp_wpcv_findings'][1]['finding_key'] );
		$this->assertSame( 64, strlen( $wpdb->rows['wp_wpcv_findings'][1]['finding_key'] ) );
	}

	/**
	 * `is_baseline_usable()` が、finding が1件も無い target_run(前回クリーンだった)
	 * を使える基準として扱うことを確認する(v0.5後半 §Step12・§1.4: 「finding_key
	 * 付きの行が無い」ことと「finding自体が無い」ことを区別する設計).
	 *
	 * @return void
	 */
	public function test_is_baseline_usable_true_when_no_findings() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$this->assertTrue( $repository->is_baseline_usable( 7 ) );
	}

	/**
	 * `is_baseline_usable()` が、`finding_key` を1件でも持つ target_run を
	 * 使える基準として扱うことを確認する.
	 *
	 * @return void
	 */
	public function test_is_baseline_usable_true_when_has_finding_key() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'finding_key' => str_repeat( 'a', 64 ) ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$this->assertTrue( $repository->is_baseline_usable( 7 ) );
	}

	/**
	 * `is_baseline_usable()` が、`finding_key` を持たない行(v4より前)しか無い
	 * target_run を使えない基準として扱うことを確認する.
	 *
	 * @return void
	 */
	public function test_is_baseline_usable_false_when_only_legacy_rows() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'finding_key' => null ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$this->assertFalse( $repository->is_baseline_usable( 7 ) );
	}

	/**
	 * `find_batch_by_target_run()` が、指定 target_run 以外の行を除外し、
	 * `id` 昇順・`after_id` より大きい行だけを `limit` 件までに絞ることを確認する
	 * (v0.5後半 §Step12・§1.4 Pass 1のバッチ取得).
	 *
	 * @return void
	 */
	public function test_find_batch_by_target_run_filters_orders_and_limits() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 9, 'path' => 'other.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'path' => 'a.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'path' => 'b.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'path' => 'c.php' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$first_batch = $repository->find_batch_by_target_run( 7, 0, 2 );
		$this->assertSame( array( 'a.php', 'b.php' ), array_column( $first_batch, 'path' ) );

		$second_batch = $repository->find_batch_by_target_run( 7, $first_batch[1]['id'], 2 );
		$this->assertSame( array( 'c.php' ), array_column( $second_batch, 'path' ) );
	}

	/**
	 * `find_baseline_batch()` が、抑制済み・`finding_key`無し・既に終了済みの行を
	 * 除外することを確認する(v0.5後半 §Step12・§1.4 Pass 2の事前絞り込み).
	 *
	 * @return void
	 */
	public function test_find_baseline_batch_excludes_non_comparable_rows() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'path' => 'ok.php', 'finding_key' => str_repeat( 'a', 64 ) ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'path' => 'legacy.php', 'finding_key' => null ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'path' => 'suppressed.php', 'finding_key' => str_repeat( 'b', 64 ), 'suppressed_by' => 'soft_change' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'path' => 'ended.php', 'finding_key' => str_repeat( 'c', 64 ), 'ended_in_run_id' => 3 ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$this->assertSame( array( 'ok.php' ), array_column( $repository->find_baseline_batch( 7, 0, 10 ), 'path' ) );
	}

	/**
	 * `find_matching_keys()` が、`$only_comparable = true` のとき抑制済みの
	 * finding をマッチ対象から除外し、`false` のときは含めることを確認する
	 * (v0.5後半 §Step12・§1.4: Pass 1〔今回側の抑制状態を見る〕とPass 2〔今回側の
	 * 抑制状態を問わない〕の違い).
	 *
	 * @return void
	 */
	public function test_find_matching_keys_respects_only_comparable() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'finding_key' => 'key-a' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'finding_key' => 'key-b', 'suppressed_by' => 'soft_change' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$this->assertSame( array( 'key-a' ), $repository->find_matching_keys( 7, array( 'key-a', 'key-b', 'key-missing' ), true ) );

		$without_filter = $repository->find_matching_keys( 7, array( 'key-a', 'key-b', 'key-missing' ), false );
		sort( $without_filter );
		$this->assertSame( array( 'key-a', 'key-b' ), $without_filter );
	}

	/**
	 * `find_matching_keys()` が空の `$keys` を渡されたら空配列を返し、
	 * `IN ()` のような不正なSQLを組み立てないことを確認する.
	 *
	 * @return void
	 */
	public function test_find_matching_keys_returns_empty_for_empty_keys() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$this->assertSame( array(), $repository->find_matching_keys( 7, array() ) );
	}

	/**
	 * `mark_ended_by_ids()` が指定 id の finding だけに `ended_in_run_id`/
	 * `end_reason` を書き込み、他の finding には触れないことを確認する.
	 *
	 * @return void
	 */
	public function test_mark_ended_by_ids_updates_only_specified_ids() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'path' => 'target.php' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'path' => 'other.php' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );
		$repository->mark_ended_by_ids( 99, array( 1 ), WPCV_Generation_Differ::END_REASON_SUPPRESSED );

		$this->assertSame( 99, $wpdb->rows['wp_wpcv_findings'][1]['ended_in_run_id'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_SUPPRESSED, $wpdb->rows['wp_wpcv_findings'][1]['end_reason'] );
		$this->assertNull( $wpdb->rows['wp_wpcv_findings'][2]['ended_in_run_id'] );
	}

	/**
	 * `mark_ended_by_ids()` が空の `$ids` を渡されたら何も更新しないことを確認する.
	 *
	 * @return void
	 */
	public function test_mark_ended_by_ids_noop_for_empty_ids() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row() );

		$repository = new WPCV_Finding_Repository( $wpdb );
		$repository->mark_ended_by_ids( 99, array(), WPCV_Generation_Differ::END_REASON_SUPPRESSED );

		$this->assertNull( $wpdb->rows['wp_wpcv_findings'][1]['ended_in_run_id'] );
	}

	/**
	 * `mark_ended_by_keys()` が、指定 target_run・指定キーで、かつまだ終わって
	 * いない finding だけを終わらせることを確認する(既に終了済みの行を
	 * 上書きしない.§1.4「pass 2に到達する基準行はまだ終わっていないものだけ」
	 * という前提をこの書き込み自身でも保証する設計).
	 *
	 * @return void
	 */
	public function test_mark_ended_by_keys_skips_already_ended_rows() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'finding_key' => 'key-a' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'finding_key' => 'key-b', 'ended_in_run_id' => 1, 'end_reason' => 'excluded' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 8, 'finding_key' => 'key-a' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );
		$repository->mark_ended_by_keys( 99, 7, array( 'key-a', 'key-b' ), WPCV_Generation_Differ::END_REASON_RESOLVED );

		$this->assertSame( 99, $wpdb->rows['wp_wpcv_findings'][1]['ended_in_run_id'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_RESOLVED, $wpdb->rows['wp_wpcv_findings'][1]['end_reason'] );
		// 既に終了済み(target_run_id=7・key-b)は上書きされない.
		$this->assertSame( 1, $wpdb->rows['wp_wpcv_findings'][2]['ended_in_run_id'] );
		$this->assertSame( 'excluded', $wpdb->rows['wp_wpcv_findings'][2]['end_reason'] );
		// 別target_run(id=8)の同じキーには触れない.
		$this->assertNull( $wpdb->rows['wp_wpcv_findings'][3]['ended_in_run_id'] );
	}

	/**
	 * `end_all_for_target_run()` が、指定 target_run の未終了 finding をすべて
	 * 同じ理由で終わらせ、既に終了済みの行・他の target_run には触れないことを
	 * 確認する(v0.5後半 §Step12・§1.3 bulk mode用).
	 *
	 * @return void
	 */
	public function test_end_all_for_target_run_ends_only_unended_rows() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7 ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'ended_in_run_id' => 1, 'end_reason' => 'resolved' ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 8 ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );
		$repository->end_all_for_target_run( 99, 7, WPCV_Generation_Differ::END_REASON_TARGET_REMOVED );

		$this->assertSame( 99, $wpdb->rows['wp_wpcv_findings'][1]['ended_in_run_id'] );
		$this->assertSame( WPCV_Generation_Differ::END_REASON_TARGET_REMOVED, $wpdb->rows['wp_wpcv_findings'][1]['end_reason'] );
		$this->assertSame( 1, $wpdb->rows['wp_wpcv_findings'][2]['ended_in_run_id'] );
		$this->assertNull( $wpdb->rows['wp_wpcv_findings'][3]['ended_in_run_id'] );
	}

	/**
	 * `mark_diff_state_for_target_run()` が `$only_unsuppressed = true` のとき
	 * 抑制済みの finding には触れず(既定NULLのまま)、`false` のときはすべて
	 * 設定することを確認する(v0.5後半 §Step12・§1.4「抑制されていれば
	 * diff_stateはNULLのまま」).
	 *
	 * @return void
	 */
	public function test_mark_diff_state_for_target_run_respects_only_unsuppressed() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7 ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_run_id' => 7, 'suppressed_by' => 'soft_change' ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );
		$repository->mark_diff_state_for_target_run( 7, WPCV_Generation_Differ::DIFF_STATE_NEW, true );

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_NEW, $wpdb->rows['wp_wpcv_findings'][1]['diff_state'] );
		$this->assertNull( $wpdb->rows['wp_wpcv_findings'][2]['diff_state'] );

		$repository->mark_diff_state_for_target_run( 7, WPCV_Generation_Differ::DIFF_STATE_EVENT, false );

		$this->assertSame( WPCV_Generation_Differ::DIFF_STATE_EVENT, $wpdb->rows['wp_wpcv_findings'][2]['diff_state'] );
	}

	/**
	 * `aggregate_diff_counts()` が、今回の run の `new`/`continuing` と、
	 * この run が終わらせた(`ended_in_run_id`が一致する)`resolved` を正しく
	 * 集計することを確認する(`resolved`対象は基準〔別run〕に属する行のため、
	 * `run_id`ではなく`ended_in_run_id`で絞り込む設計. v0.5後半 §Step12).
	 *
	 * @return void
	 */
	public function test_aggregate_diff_counts() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		// 今回の run(id=99)の finding: new 2件・continuing 1件.
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 99, 'diff_state' => WPCV_Generation_Differ::DIFF_STATE_NEW ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 99, 'diff_state' => WPCV_Generation_Differ::DIFF_STATE_NEW ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 99, 'diff_state' => WPCV_Generation_Differ::DIFF_STATE_CONTINUING ) ) );
		// 別runで抑制されたため diff_state=NULL のまま(集計されない).
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 99, 'diff_state' => null ) ) );
		// 基準(別run=1)の finding。今回のrun(99)がresolvedとして終わらせた.
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'ended_in_run_id' => 99, 'end_reason' => WPCV_Generation_Differ::END_REASON_RESOLVED ) ) );
		// 別の理由(suppressed)で終わった行はresolvedに数えない.
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'ended_in_run_id' => 99, 'end_reason' => WPCV_Generation_Differ::END_REASON_SUPPRESSED ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$this->assertSame(
			array( 'new' => 2, 'resolved' => 1, 'continuing' => 1 ),
			$repository->aggregate_diff_counts( 99 )
		);
	}

	/**
	 * `mark_notified_by_ids()`が`MARK_NOTIFIED_BATCH_SIZE`件ずつ区切ってUPDATEし、
	 * すべての行に`notified_at`を書くことを確認する(コードレビュー指摘4.
	 * 1,201件 = 500 + 500 + 201 で3回のUPDATEになる).
	 *
	 * @return void
	 */
	public function test_mark_notified_by_ids_updates_in_batches() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );
		$ids        = array();

		for ( $i = 1; $i <= 1201; $i++ ) {
			$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'run_id' => 1, 'path' => "f{$i}.php" ) ) );
			$ids[] = $i;
		}

		$repository->mark_notified_by_ids( $ids, '2026-09-27 00:00:00' );

		$updates = array_values(
			array_filter(
				$wpdb->query_calls,
				static function ( $query ) {
					return 0 === strpos( $query, 'UPDATE' );
				}
			)
		);
		$this->assertCount( 3, $updates, '500件ずつ区切るため3回のUPDATEになる' );

		foreach ( $wpdb->rows['wp_wpcv_findings'] as $row ) {
			$this->assertSame( '2026-09-27 00:00:00', $row['notified_at'] );
		}
	}

	/**
	 * `query()` の並べ替えで同じ値どうしは id 昇順になり(降順を指定しても同じ)、
	 * 総件数は絞り込み後・ページ分け前の件数になることを確認する(コードレビュー
	 * 指摘5で並べ替えを `ORDER BY {列} {方向}, id ASC` のSQLにしたため).
	 *
	 * @return void
	 */
	public function test_query_orders_ties_by_id_and_counts_filtered_total() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'path' => 'b.php', 'status' => 'modified' ) ) ); // id 1.
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'path' => 'a.php', 'status' => 'modified' ) ) ); // id 2.
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'path' => 'b.php', 'status' => 'modified' ) ) ); // id 3.
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'path' => 'c.php', 'status' => 'added' ) ) );    // id 4(絞り込みで除外).

		$result = $repository->query(
			array(
				'run_id'   => 1,
				'status'   => array( 'modified' ),
				'sort'     => 'path',
				'order'    => 'desc',
				'per_page' => 2,
			)
		);

		$this->assertSame( 3, $result['total'], '総件数は絞り込み後・ページ分け前の件数' );
		$this->assertSame( array( 1, 3 ), array_map( 'intval', array_column( $result['rows'], 'id' ) ), '同じpathどうしは降順指定でもid昇順' );
	}

	/**
	 * `query()` が1 run分のfindingを全件読まず、SQLのLIMIT/OFFSETで1ページ分だけを
	 * 読むことを確認する(コードレビュー指摘5. 以前は1 run分を全件読んでPHPで
	 * ページ分けしていた).
	 *
	 * @return void
	 */
	public function test_query_reads_only_one_page_via_limit() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		for ( $i = 1; $i <= 5; $i++ ) {
			$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'path' => "f{$i}.php" ) ) );
		}

		$repository->query( array( 'run_id' => 1, 'per_page' => 2, 'page' => 2 ) );

		$this->assertCount( 1, $wpdb->get_results_calls );
		$this->assertStringContainsString( 'LIMIT 2 OFFSET 2', $wpdb->get_results_calls[0] );
	}

	/**
	 * `query_ended_by_run()` が `ended_in_run_id` で絞り込み、他のrunで終わった
	 * finding・まだ終わっていないfindingを含めないことを確認する(v0.5後半 §16・§1.3).
	 *
	 * @return void
	 */
	public function test_query_ended_by_run_filters_by_ended_in_run_id() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'path' => 'ended-in-93.php', 'ended_in_run_id' => 93, 'end_reason' => WPCV_Generation_Differ::END_REASON_RESOLVED ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'path' => 'ended-in-94.php', 'ended_in_run_id' => 94, 'end_reason' => WPCV_Generation_Differ::END_REASON_RESOLVED ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'path' => 'still-open.php', 'ended_in_run_id' => null ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );
		$result     = $repository->query_ended_by_run( 93, 1, 20 );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( array( 'ended-in-93.php' ), array_column( $result['rows'], 'path' ) );
	}

	/**
	 * `query_ended_by_run()` が理由(`ENDED_BY_RUN_REASON_ORDER`.resolved →
	 * suppressed → excluded → version_changed → target_removed)→target_id→path→id
	 * の順で並べることを確認する(v0.5後半 §16・§1.3).
	 *
	 * @return void
	 */
	public function test_query_ended_by_run_orders_by_reason_then_target_then_path() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		// 挿入順はあえて優先順位・並び順と逆にする.
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_id' => 'plugin:b', 'path' => 'b.php', 'ended_in_run_id' => 10, 'end_reason' => WPCV_Generation_Differ::END_REASON_TARGET_REMOVED ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_id' => 'plugin:a', 'path' => 'z.php', 'ended_in_run_id' => 10, 'end_reason' => WPCV_Generation_Differ::END_REASON_VERSION_CHANGED ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_id' => 'plugin:a', 'path' => 'b.php', 'ended_in_run_id' => 10, 'end_reason' => WPCV_Generation_Differ::END_REASON_RESOLVED ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_id' => 'plugin:a', 'path' => 'a.php', 'ended_in_run_id' => 10, 'end_reason' => WPCV_Generation_Differ::END_REASON_RESOLVED ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_id' => 'plugin:a', 'path' => 'c.php', 'ended_in_run_id' => 10, 'end_reason' => WPCV_Generation_Differ::END_REASON_SUPPRESSED ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );
		$result     = $repository->query_ended_by_run( 10, 1, 20 );

		$this->assertSame( 5, $result['total'] );
		$this->assertSame(
			array( 'a.php', 'b.php', 'c.php', 'z.php', 'b.php' ),
			array_column( $result['rows'], 'path' )
		);
		$this->assertSame(
			array(
				WPCV_Generation_Differ::END_REASON_RESOLVED,
				WPCV_Generation_Differ::END_REASON_RESOLVED,
				WPCV_Generation_Differ::END_REASON_SUPPRESSED,
				WPCV_Generation_Differ::END_REASON_VERSION_CHANGED,
				WPCV_Generation_Differ::END_REASON_TARGET_REMOVED,
			),
			array_column( $result['rows'], 'end_reason' )
		);
	}

	/**
	 * `query_ended_by_run()` が理由の境界をまたいでもpagination(`page`/`per_page`)を
	 * 正しく適用することを確認する(v0.5後半 §16・§1.3。resolvedが2件・
	 * suppressedが2件のときpage=1,per_page=3で「resolved 2件+suppressed 1件」、
	 * page=2,per_page=3で「suppressed 1件」になることを確かめる).
	 *
	 * @return void
	 */
	public function test_query_ended_by_run_paginates_across_reason_boundary() {
		$wpdb = new WPCV_Test_Fake_WPDB();
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_id' => 'plugin:a', 'path' => 'r1.php', 'ended_in_run_id' => 10, 'end_reason' => WPCV_Generation_Differ::END_REASON_RESOLVED ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_id' => 'plugin:a', 'path' => 'r2.php', 'ended_in_run_id' => 10, 'end_reason' => WPCV_Generation_Differ::END_REASON_RESOLVED ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_id' => 'plugin:a', 'path' => 's1.php', 'ended_in_run_id' => 10, 'end_reason' => WPCV_Generation_Differ::END_REASON_SUPPRESSED ) ) );
		$wpdb->insert( 'wp_wpcv_findings', wpcv_test_make_finding_row( array( 'target_id' => 'plugin:a', 'path' => 's2.php', 'ended_in_run_id' => 10, 'end_reason' => WPCV_Generation_Differ::END_REASON_SUPPRESSED ) ) );

		$repository = new WPCV_Finding_Repository( $wpdb );

		$page1 = $repository->query_ended_by_run( 10, 1, 3 );
		$this->assertSame( 4, $page1['total'] );
		$this->assertSame( array( 'r1.php', 'r2.php', 's1.php' ), array_column( $page1['rows'], 'path' ) );

		$page2 = $repository->query_ended_by_run( 10, 2, 3 );
		$this->assertSame( 4, $page2['total'] );
		$this->assertSame( array( 's2.php' ), array_column( $page2['rows'], 'path' ) );
	}

	/**
	 * `query_ended_by_run()` が0件のrunに対して空配列・total 0を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_query_ended_by_run_returns_empty_when_nothing_ended() {
		$wpdb       = new WPCV_Test_Fake_WPDB();
		$repository = new WPCV_Finding_Repository( $wpdb );

		$result = $repository->query_ended_by_run( 999, 1, 20 );

		$this->assertSame( array(), $result['rows'] );
		$this->assertSame( 0, $result['total'] );
	}
}
