<?php
/**
 * WPCV_Chunk_Verifier::verify_stat_chunk() / WPCV_Verifier::make_finding_for_stat_change() のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-error-code.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-verifier.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-cursor.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-verifier.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-file-state-repository.php';

use PHPUnit\Framework\TestCase;

/**
 * Stat差分検知(層1)の chunk 処理のテスト(v0.5 §Step5).
 *
 * `verify_stat_chunk()` はファイルシステムにも DB にも触らない純ロジックのため、
 * `collect_stat => true` の走査結果(items)と前回ベースラインを手で組み立てて渡す。
 * ただし`content_hash_enabled`(v0.6 §Step10)を使うテストだけは、実際に
 * `hash_file()` が読む対象として `tests/fixtures/fake-root/`(ABSPATH)配下に
 * 実ファイルを作る(`VerifierTest`/`ChunkVerifierTest` と同じ方式).
 */
class StatChunkVerifierTest extends TestCase {

	/**
	 * テスト対象の target_id.
	 *
	 * @var string
	 */
	const TARGET_ID = 'plugin:custom-plugin:_stat';

	/**
	 * 各テストの前に前回の残骸を掃除する(content_hash_enabledのテストが
	 * 実ファイルを作るため).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->clean_fixtures();
	}

	/**
	 * 各テストの後に作成したフィクスチャを掃除する.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->clean_fixtures();
		parent::tearDown();
	}

	/**
	 * ABSPATH 直下を `.gitkeep` 以外すべて削除する.
	 *
	 * @return void
	 */
	private function clean_fixtures() {
		foreach ( scandir( ABSPATH ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || '.gitkeep' === $entry ) {
				continue;
			}
			$this->remove_path( ABSPATH . $entry );
		}
	}

	/**
	 * ファイル・ディレクトリを再帰的に削除する.
	 *
	 * @param string $path 絶対パス.
	 * @return void
	 */
	private function remove_path( $path ) {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			foreach ( scandir( $path ) as $entry ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}
				$this->remove_path( $path . '/' . $entry );
			}
			rmdir( $path );
			return;
		}

		if ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * ABSPATH 相対パスを指定してテスト用ファイルを作る.
	 *
	 * @param string $relative_path ABSPATH 相対パス.
	 * @param string $content       ファイルの中身.
	 * @return void
	 */
	private function put_fixture_file( $relative_path, $content = '' ) {
		$absolute_path = ABSPATH . $relative_path;
		$dir           = dirname( $absolute_path );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		file_put_contents( $absolute_path, $content );
	}

	/**
	 * 走査結果の item を1件組み立てる.
	 *
	 * @param string $path     ABSPATH 相対パス.
	 * @param int    $size     size.
	 * @param int    $ctime    ctime.
	 * @param int    $mtime    mtime.
	 * @param string $severity severity.
	 * @return array
	 */
	private function item( $path, $size, $ctime, $mtime, $severity = 'high' ) {
		return array(
			'path'     => $path,
			'severity' => $severity,
			'size'     => $size,
			'ctime'    => $ctime,
			'mtime'    => $mtime,
		);
	}

	/**
	 * `wpcv_file_states` の行の形をしたベースラインを1件組み立てる
	 * (DB から読んだ値は文字列で返るため、あえて文字列にしている).
	 *
	 * @param int         $size          file_size.
	 * @param int         $ctime         ctime.
	 * @param int         $mtime         mtime.
	 * @param string|null $content_hash  content_hash(v0.6 §Step10。未確立ならnull.
	 *                                  `wpdb`はNULL列をPHPのnullで返すため、この
	 *                                  引数を省略した場合もnullのまま持たせる).
	 * @return array
	 */
	private function state( $size, $ctime, $mtime, $content_hash = null ) {
		return array(
			'file_size'    => (string) $size,
			'ctime'        => (string) $ctime,
			'mtime'        => (string) $mtime,
			'content_hash' => $content_hash,
		);
	}

	/**
	 * `verify_stat_chunk()` の `$context` を組み立てる.
	 *
	 * @param array $scan_items 走査結果.
	 * @param array $states     path => ベースライン行(ローダーが返す値).
	 * @param array $overrides  上書きするキー.
	 * @return array
	 */
	private function context( array $scan_items, array $states, array $overrides = array() ) {
		return array_merge(
			array(
				'target_id'            => self::TARGET_ID,
				'dimension'            => 'plugin',
				'slug'                 => 'custom-plugin',
				'version'              => '1.0.0',
				'source'               => '',
				'run_id'               => 42,
				'scan_items'           => $scan_items,
				'baseline_mode'        => false,
				'load_previous_states' => static function ( array $paths ) use ( $states ) {
					return array_intersect_key( $states, array_flip( $paths ) );
				},
			),
			$overrides
		);
	}

	/**
	 * 前回値と size/ctime/mtime がすべて同じなら finding を出さず、
	 * 「変化なし」として数え、ベースライン行を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_unchanged_file_produces_no_finding() {
		$path   = 'wp-content/plugins/custom-plugin/main.php';
		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 100, 1000, 900 ) ),
				array( $path => $this->state( 100, 1000, 900 ) )
			)
		);

		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( 1, $result['files_verified_delta'] );
		$this->assertTrue( $result['completed'] );
		$this->assertCount( 1, $result['baseline_rows'] );

		$row = $result['baseline_rows'][0];
		$this->assertSame( WPCV_File_State_Repository::compute_state_key( self::TARGET_ID, $path ), $row['state_key'] );
		$this->assertSame( 100, $row['file_size'] );
		$this->assertSame( 1000, $row['ctime'] );
		$this->assertSame( 900, $row['mtime'] );
		$this->assertSame( '1.0.0', $row['baseline_version'] );
		$this->assertSame( 42, $row['first_seen_run_id'] );
		$this->assertSame( 42, $row['last_seen_run_id'] );
		$this->assertNull( $row['content_hash'] );
	}

	/**
	 * Size と mtime が変わった(通常の書き換え)場合、timestomp ではない
	 * `stat_changed` になり、severity は走査時の値のままであることを確認する.
	 *
	 * @return void
	 */
	public function test_size_change_produces_stat_changed() {
		$path   = 'wp-content/plugins/custom-plugin/readme.txt';
		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 150, 2000, 2000, 'medium' ) ),
				array( $path => $this->state( 100, 1000, 900 ) )
			)
		);

		$this->assertCount( 1, $result['findings'] );
		$finding = $result['findings'][0];
		$this->assertSame( 'stat_changed', $finding['status'] );
		$this->assertSame( 'medium', $finding['severity'] );
		$this->assertSame( 150, $finding['file_size'] );
		$this->assertNull( $finding['expected_hash'] );
		$this->assertNull( $finding['actual_hash'] );
		$this->assertSame( '', $finding['hash_algorithm'] );
		$this->assertSame( 0, $result['files_verified_delta'] );

		$this->assertSame(
			array(
				'size'      => array(
					'old' => 100,
					'new' => 150,
				),
				'ctime'     => array(
					'old' => 1000,
					'new' => 2000,
				),
				'mtime'     => array(
					'old' => 900,
					'new' => 2000,
				),
				'timestomp' => false,
			),
			json_decode( $finding['detail'], true )
		);
	}

	/**
	 * Size が同じで ctime だけが変わった場合(chmod 等)も `stat_changed` として拾うが、
	 * timestomp にはしないことを確認する(size が変わっていないため. rev.3 §3.5).
	 *
	 * @return void
	 */
	public function test_ctime_only_change_produces_stat_changed_without_timestomp() {
		$path   = 'wp-content/plugins/custom-plugin/main.php';
		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 100, 5000, 900, 'medium' ) ),
				array( $path => $this->state( 100, 1000, 900 ) )
			)
		);

		$this->assertCount( 1, $result['findings'] );
		$this->assertSame( 'stat_changed', $result['findings'][0]['status'] );
		$this->assertSame( 'medium', $result['findings'][0]['severity'] );

		$detail = json_decode( $result['findings'][0]['detail'], true );
		$this->assertFalse( $detail['timestomp'] );
	}

	/**
	 * Size が変わったのに mtime が前回と同じなら timestomp とみなし、
	 * severity を強制的に high にすることを確認する(rev.3 §3.5).
	 *
	 * @return void
	 */
	public function test_size_change_with_unchanged_mtime_is_timestomp() {
		$path   = 'wp-content/plugins/custom-plugin/assets/style.css';
		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 4160, 1757600000, 1740000000, 'medium' ) ),
				array( $path => $this->state( 4021, 1757000000, 1740000000 ) )
			)
		);

		$this->assertCount( 1, $result['findings'] );
		$finding = $result['findings'][0];
		$this->assertSame( 'stat_changed', $finding['status'] );
		$this->assertSame( 'high', $finding['severity'] );

		$detail = json_decode( $finding['detail'], true );
		$this->assertTrue( $detail['timestomp'] );
	}

	/**
	 * ベースラインに無いファイルは `added` になり、層1はファイル内容を読まないため
	 * hash を持たないことを確認する.
	 *
	 * @return void
	 */
	public function test_new_file_produces_added_without_hash() {
		$known  = 'wp-content/plugins/custom-plugin/main.php';
		$new    = 'wp-content/plugins/custom-plugin/shell.php';
		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array(
					$this->item( $known, 100, 1000, 900 ),
					$this->item( $new, 321, 3000, 3000, 'high' ),
				),
				array( $known => $this->state( 100, 1000, 900 ) )
			)
		);

		$this->assertCount( 1, $result['findings'] );
		$finding = $result['findings'][0];
		$this->assertSame( 'added', $finding['status'] );
		$this->assertSame( $new, $finding['path'] );
		$this->assertSame( 'high', $finding['severity'] );
		$this->assertSame( 321, $finding['file_size'] );
		$this->assertNull( $finding['actual_hash'] );
		$this->assertArrayNotHasKey( 'detail', $finding );
		$this->assertSame( 1, $result['files_verified_delta'] );
		$this->assertCount( 2, $result['baseline_rows'] );
	}

	/**
	 * ベースライン構築モードでは、変化の有無にかかわらず finding を出さず、
	 * 前回値のローダーも呼ばずに全件のベースライン行だけを返すことを確認する(rev.3 §3.6).
	 *
	 * @return void
	 */
	public function test_baseline_mode_builds_rows_without_findings() {
		$loader_called = false;

		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array(
					$this->item( 'wp-content/plugins/custom-plugin/a.php', 1, 1, 1 ),
					$this->item( 'wp-content/plugins/custom-plugin/b.php', 2, 2, 2 ),
				),
				array(),
				array(
					'baseline_mode'        => true,
					'load_previous_states' => static function () use ( &$loader_called ) {
						$loader_called = true;
						return array();
					},
				)
			)
		);

		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( 0, $result['files_verified_delta'] );
		$this->assertCount( 2, $result['baseline_rows'] );
		$this->assertTrue( $result['completed'] );
		$this->assertFalse( $loader_called );
	}

	/**
	 * Fingerprint が path 一覧だけから計算され、stat 値が変わっても変わらないことを
	 * 確認する(rev.3 §3.5「実装上の落とし穴」: 含めると永久に needs_retry になる).
	 *
	 * @return void
	 */
	public function test_fingerprint_does_not_depend_on_stat_values() {
		$path     = 'wp-content/plugins/custom-plugin/main.php';
		$verifier = new WPCV_Chunk_Verifier();

		$first = $verifier->verify_stat_chunk(
			$this->context( array( $this->item( $path, 100, 1000, 900 ) ), array(), array( 'baseline_mode' => true ) )
		);

		$second = $verifier->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 999, 8000, 7000 ) ),
				array( $path => $this->state( 100, 1000, 900 ) ),
				array( 'previous_fingerprint' => $first['manifest_fingerprint'] )
			)
		);

		$this->assertSame( $first['manifest_fingerprint'], $second['manifest_fingerprint'] );
		$this->assertFalse( $second['needs_retry'] );
		$this->assertSame( WPCV_Chunk_Cursor::compute_fingerprint( array( $path ) ), $first['manifest_fingerprint'] );
	}

	/**
	 * 本体 target の version が前回保存時と変わっていたら、比較も
	 * ベースライン行の作成も行わずに `needs_retry` を返すことを確認する
	 * (ベースライン破棄は Step7 で呼び出し元が行う. rev.3 §3.7-a).
	 *
	 * @return void
	 */
	public function test_version_change_returns_needs_retry_without_rows() {
		$path   = 'wp-content/plugins/custom-plugin/main.php';
		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 150, 2000, 2000 ) ),
				array( $path => $this->state( 100, 1000, 900 ) ),
				array(
					'version'          => '1.1.0',
					'previous_version' => '1.0.0',
				)
			)
		);

		$this->assertTrue( $result['needs_retry'] );
		$this->assertTrue( $result['version_changed'] );
		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( array(), $result['baseline_rows'] );
	}

	/**
	 * `max_files` 予算で途中で止まり、前回値のローダーにもその件数ぶんの path
	 * だけが渡されること、cursor から再開できることを確認する.
	 *
	 * @return void
	 */
	public function test_max_files_budget_limits_loader_and_resumes_from_cursor() {
		$items  = array();
		$states = array();
		foreach ( array( 'a', 'b', 'c' ) as $name ) {
			$path            = "wp-content/plugins/custom-plugin/{$name}.php";
			$items[]         = $this->item( $path, 10, 10, 10 );
			$states[ $path ] = $this->state( 10, 10, 10 );
		}

		$requested = array();
		$loader    = static function ( array $paths ) use ( $states, &$requested ) {
			$requested[] = $paths;
			return array_intersect_key( $states, array_flip( $paths ) );
		};

		$verifier = new WPCV_Chunk_Verifier();
		$first    = $verifier->verify_stat_chunk(
			$this->context(
				$items,
				$states,
				array(
					'budget'               => array( 'max_files' => 2 ),
					'load_previous_states' => $loader,
				)
			)
		);

		$this->assertFalse( $first['completed'] );
		$this->assertSame( 'wp-content/plugins/custom-plugin/b.php', $first['cursor_path'] );
		$this->assertCount( 2, $first['baseline_rows'] );
		$this->assertSame(
			array( 'wp-content/plugins/custom-plugin/a.php', 'wp-content/plugins/custom-plugin/b.php' ),
			$requested[0]
		);

		$second = $verifier->verify_stat_chunk(
			$this->context(
				$items,
				$states,
				array(
					'budget'               => array( 'max_files' => 2 ),
					'load_previous_states' => $loader,
					'cursor_path'          => $first['cursor_path'],
					'previous_fingerprint' => $first['manifest_fingerprint'],
				)
			)
		);

		$this->assertTrue( $second['completed'] );
		$this->assertNull( $second['cursor_path'] );
		$this->assertSame( array( 'wp-content/plugins/custom-plugin/c.php' ), $requested[1] );
		$this->assertSame( 1, $second['files_verified_delta'] );
	}

	/**
	 * `lstat()` が失敗した item(size/ctime/mtime がすべて0)は、finding も
	 * ベースライン行も作らずに読み飛ばすことを確認する(cursor は進める).
	 *
	 * @return void
	 */
	public function test_failed_lstat_item_is_skipped() {
		$path   = 'wp-content/plugins/custom-plugin/vanished.php';
		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 0, 0, 0 ) ),
				array( $path => $this->state( 100, 1000, 900 ) )
			)
		);

		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( array(), $result['baseline_rows'] );
		$this->assertSame( 0, $result['files_verified_delta'] );
		$this->assertTrue( $result['completed'] );
	}

	/**
	 * ベースライン構築モードでないのに前回値のローダーが無ければ、
	 * 例外で呼び出し元の設定漏れを知らせることを確認する.
	 *
	 * @return void
	 */
	public function test_missing_loader_throws_unless_baseline_mode() {
		$context = $this->context( array( $this->item( 'wp-content/plugins/custom-plugin/a.php', 1, 1, 1 ) ), array() );
		unset( $context['load_previous_states'] );

		$this->expectException( InvalidArgumentException::class );

		( new WPCV_Chunk_Verifier() )->verify_stat_chunk( $context );
	}

	/**
	 * `make_finding_for_stat_change()` が、size/ctime/mtime がすべて同じなら null を返す
	 * ことを直接確認する(組み合わせ表の「変更なし」行).
	 *
	 * @return void
	 */
	public function test_make_finding_for_stat_change_returns_null_when_unchanged() {
		$stat = array(
			'size'  => 1,
			'ctime' => 2,
			'mtime' => 3,
		);

		$this->assertNull(
			WPCV_Verifier::make_finding_for_stat_change( self::TARGET_ID, 'plugin', 'custom-plugin', '1.0.0', '', 'x.php', 'high', $stat, $stat )
		);
	}

	/**
	 * `make_finding_for_stat_content_change()` の組み合わせ表(v0.6 §Step10.
	 * 2026-09-30ユーザー承認済み)を直接呼び出しで確認する.
	 *
	 * 前回・今回のcontent_hashが両方あり値が違えば、statの状態(ここでは
	 * size/ctime/mtimeすべて同じ.「同じサイズの書き換え」を想定)に関わらず
	 * `modified` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_make_finding_for_stat_content_change_returns_modified_when_hash_differs() {
		$stat = array(
			'size'  => 10,
			'ctime' => 10,
			'mtime' => 10,
		);

		$finding = WPCV_Verifier::make_finding_for_stat_content_change(
			self::TARGET_ID,
			'core',
			'_config',
			'',
			'',
			'wp-config.php',
			'high',
			$stat,
			$stat,
			hash( 'sha256', 'old' ),
			hash( 'sha256', 'new' )
		);

		$this->assertSame( 'modified', $finding['status'] );
		$this->assertSame( 'high', $finding['severity'] );
		$this->assertSame( hash( 'sha256', 'old' ), $finding['expected_hash'] );
		$this->assertSame( hash( 'sha256', 'new' ), $finding['actual_hash'] );
		$this->assertSame( WPCV_File_Hasher::ALGO_SHA256, $finding['hash_algorithm'] );
		$this->assertArrayNotHasKey( 'detail', $finding );
	}

	/**
	 * 前回のcontent_hashがnull(層2導入前のベースライン等、比較不能)なら、
	 * statも変化していない場合は`make_finding_for_stat_change()`と同じくnullに
	 * フォールバックすることを確認する.
	 *
	 * @return void
	 */
	public function test_make_finding_for_stat_content_change_falls_back_when_previous_hash_is_null() {
		$stat = array(
			'size'  => 10,
			'ctime' => 10,
			'mtime' => 10,
		);

		$finding = WPCV_Verifier::make_finding_for_stat_content_change(
			self::TARGET_ID,
			'core',
			'_config',
			'',
			'',
			'wp-config.php',
			'high',
			$stat,
			$stat,
			null,
			hash( 'sha256', 'new' )
		);

		$this->assertNull( $finding );
	}

	/**
	 * 今回のcontent_hashがnull(読み取り失敗)なら、同様にstat比較へ
	 * フォールバックすることを確認する.
	 *
	 * @return void
	 */
	public function test_make_finding_for_stat_content_change_falls_back_when_current_hash_is_null() {
		$stat = array(
			'size'  => 10,
			'ctime' => 10,
			'mtime' => 10,
		);

		$finding = WPCV_Verifier::make_finding_for_stat_content_change(
			self::TARGET_ID,
			'core',
			'_config',
			'',
			'',
			'wp-config.php',
			'high',
			$stat,
			$stat,
			hash( 'sha256', 'old' ),
			null
		);

		$this->assertNull( $finding );
	}

	/**
	 * `verify_stat_chunk()` に `content_hash_enabled` を渡すと、size/ctime/mtimeが
	 * すべて同じ「同じサイズの書き換え」でも内容ハッシュの違いから `modified` を
	 * 検出することを確認する(v0.6 §Step10の完了条件そのもの. プラン§6 Step10参照).
	 *
	 * @return void
	 */
	public function test_content_hash_enabled_detects_same_size_rewrite_as_modified() {
		$path = 'wp-config.php';
		$this->put_fixture_file( $path, 'BBBB' );

		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 4, 1000, 900, 'high' ) ),
				array( $path => $this->state( 4, 1000, 900, hash( 'sha256', 'AAAA' ) ) ),
				array( 'content_hash_enabled' => true )
			)
		);

		$this->assertCount( 1, $result['findings'] );
		$finding = $result['findings'][0];
		$this->assertSame( 'modified', $finding['status'] );
		$this->assertSame( hash( 'sha256', 'AAAA' ), $finding['expected_hash'] );
		$this->assertSame( hash( 'sha256', 'BBBB' ), $finding['actual_hash'] );

		$row = $result['baseline_rows'][0];
		$this->assertSame( hash( 'sha256', 'BBBB' ), $row['content_hash'] );
		$this->assertSame( WPCV_File_Hasher::ALGO_SHA256, $row['hash_algorithm'] );
	}

	/**
	 * 前回のcontent_hashが未確立(null。層2導入直後の移行期)なら、statが
	 * 変化していない限りfindingを出さず、今回計算したhashをbaseline行へ
	 * 保存するだけにとどまることを確認する(2026-09-30ユーザー承認済みの方針).
	 *
	 * @return void
	 */
	public function test_content_hash_enabled_stores_hash_without_finding_when_previous_hash_missing() {
		$path = 'wp-config.php';
		$this->put_fixture_file( $path, 'BBBB' );

		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 4, 1000, 900, 'high' ) ),
				array( $path => $this->state( 4, 1000, 900 ) ),
				array( 'content_hash_enabled' => true )
			)
		);

		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( hash( 'sha256', 'BBBB' ), $result['baseline_rows'][0]['content_hash'] );
	}

	/**
	 * 内容が変わっていなければ、ctimeのみの変化(chmod等)は従来どおり
	 * `stat_changed` のまま拾われることを確認する(content_hash一致時は
	 * stat比較へフォールバックする方針).
	 *
	 * @return void
	 */
	public function test_content_hash_enabled_still_reports_stat_changed_when_content_unchanged() {
		$path = 'wp-config.php';
		$this->put_fixture_file( $path, 'AAAA' );

		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 4, 5000, 900, 'high' ) ),
				array( $path => $this->state( 4, 1000, 900, hash( 'sha256', 'AAAA' ) ) ),
				array( 'content_hash_enabled' => true )
			)
		);

		$this->assertCount( 1, $result['findings'] );
		$this->assertSame( 'stat_changed', $result['findings'][0]['status'] );
	}

	/**
	 * ベースライン構築モードでも(finding は出さずに)内容ハッシュを計算して
	 * baseline行へ保存することを確認する(§5.3 L4「常に内容ハッシュを取る」).
	 *
	 * @return void
	 */
	public function test_content_hash_enabled_computes_hash_during_baseline_mode() {
		$path = 'wp-config.php';
		$this->put_fixture_file( $path, 'AAAA' );

		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 4, 1000, 900, 'high' ) ),
				array(),
				array(
					'baseline_mode'        => true,
					'content_hash_enabled' => true,
				)
			)
		);

		$this->assertSame( array(), $result['findings'] );
		$this->assertSame( hash( 'sha256', 'AAAA' ), $result['baseline_rows'][0]['content_hash'] );
	}

	/**
	 * `content_hash_enabled` を渡さない(既定 false)通常のstat targetは、実ファイルが
	 * 存在してもハッシュを一切計算せず、従来どおり`content_hash`がnullのままである
	 * ことを確認する(層1のみのtargetの挙動が変わらないことの完了条件. プラン§6参照).
	 *
	 * @return void
	 */
	public function test_content_hash_stays_null_when_not_enabled_even_if_file_exists() {
		$path = 'wp-content/plugins/custom-plugin/main.php';
		$this->put_fixture_file( $path, 'anything' );

		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				array( $this->item( $path, 8, 1000, 900 ) ),
				array( $path => $this->state( 8, 1000, 900 ) )
			)
		);

		$this->assertSame( array(), $result['findings'] );
		$this->assertNull( $result['baseline_rows'][0]['content_hash'] );
		$this->assertNull( $result['baseline_rows'][0]['hash_algorithm'] );
	}

	/**
	 * 変更件数がまとめの閾値に届かなければ、個別の finding のまま返すことを確認する(v0.5 §Step7).
	 *
	 * @return void
	 */
	public function test_rollup_is_not_applied_below_threshold() {
		list( $items, $states ) = $this->changed_items( 5, 10 );

		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				$items,
				$states,
				array(
					'rollup'           => array(
						'min_count' => 20,
						'ratio'     => 0.5,
					),
					'target_root_path' => 'wp-content/plugins/custom-plugin',
				)
			)
		);

		$this->assertCount( 5, $result['findings'] );
	}

	/**
	 * 件数と割合の両方が閾値以上ならまとめるが、timestomp の finding は
	 * まとめずに個別に残すことを確認する(v0.5 §Step7).
	 *
	 * @return void
	 */
	public function test_rollup_keeps_timestomp_findings_individual() {
		list( $items, $states ) = $this->changed_items( 4, 4 );

		// 1件だけ timestomp(size が変わったのに mtime が前回と同じ)にする.
		$stomped                        = $items[0]['path'];
		$states[ $stomped ]['mtime']    = (string) $items[0]['mtime'];
		$states[ $stomped ]['file_size'] = '1';

		$result = ( new WPCV_Chunk_Verifier() )->verify_stat_chunk(
			$this->context(
				$items,
				$states,
				array(
					'rollup'           => array(
						'min_count' => 3,
						'ratio'     => 0.5,
					),
					'target_root_path' => 'wp-content/plugins/custom-plugin',
				)
			)
		);

		$this->assertCount( 2, $result['findings'] );

		$by_path = array_column( $result['findings'], null, 'path' );
		$this->assertSame( 'high', $by_path[ $stomped ]['severity'] );

		$rollup = json_decode( $by_path['wp-content/plugins/custom-plugin']['detail'], true );
		$this->assertTrue( $rollup['rollup'] );
		$this->assertSame( 3, $rollup['count'] );
		$this->assertSame( 4, $rollup['files_scanned'] );
	}

	/**
	 * 前回から size/ctime/mtime が変わった item とベースラインを作る.
	 *
	 * @param int $changed 変更ありにする件数.
	 * @param int $total   全体の件数.
	 * @return array{0: array, 1: array} items と path => ベースライン行.
	 */
	private function changed_items( $changed, $total ) {
		$items  = array();
		$states = array();

		for ( $i = 0; $i < $total; $i++ ) {
			$path            = sprintf( 'wp-content/plugins/custom-plugin/f%02d.php', $i );
			$items[]         = $this->item( $path, 200, 2000, 2000, 'medium' );
			$states[ $path ] = $i < $changed ? $this->state( 100, 1000, 900 ) : $this->state( 200, 2000, 2000 );
		}

		return array( $items, $states );
	}
}
