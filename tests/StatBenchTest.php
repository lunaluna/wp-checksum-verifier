<?php
/**
 * WPCV_Stat_Bench のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-file-hasher.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-stat-bench.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Stat_Bench`(v0.5 §4.2 Step4. rev.3 §3.9参照)のテスト.
 *
 * `UnknownFileScannerTest` と同様、ABSPATH(tests/bootstrap.php で
 * tests/fixtures/fake-root/ に固定)配下に実ファイルを作って計測する.
 */
class StatBenchTest extends TestCase {

	/**
	 * 掃除の際に ABSPATH 直下に残す既定のフィクスチャ名.
	 *
	 * @var string[]
	 */
	const PRESERVED_ENTRIES = array( '.gitkeep' );

	/**
	 * 各テストの前に前回の残骸を掃除する(前回失敗時の汚染対策).
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
	 * ABSPATH 直下を PRESERVED_ENTRIES 以外すべて削除する.
	 *
	 * @return void
	 */
	private function clean_fixtures() {
		foreach ( scandir( ABSPATH ) as $entry ) {
			if ( '.' === $entry || '..' === $entry || in_array( $entry, self::PRESERVED_ENTRIES, true ) ) {
				continue;
			}
			$this->remove_path( ABSPATH . $entry );
		}
	}

	/**
	 * ファイル・ディレクトリを再帰的に削除する(存在しなければ何もしない).
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
	 * ABSPATH 相対パスを指定してテスト用ファイルを作る(親ディレクトリも作成する).
	 *
	 * @param string $relative_path ABSPATH 相対パス.
	 * @param string $content       ファイルの中身. 既定は空文字.
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
	 * 時刻を呼び出し回数(0, 1, 2, ...)としてそのまま返す `$now` callable を作る。
	 * `measure_*()` 系メソッドは「開始直後にnow()を呼び、終了直後にもう一度呼んで
	 * 差を取る」実装のため、この `$now` を使うと経過秒は常に1になり、
	 * entries_per_sec/bytes_per_sec の計算式をそのまま検証できる.
	 *
	 * @return callable
	 */
	private function make_monotonic_now() {
		$call_count = 0;

		return static function () use ( &$call_count ) {
			return $call_count++;
		};
	}

	/**
	 * `measure()` が指定した `iterations` の数だけ `runs` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_measure_returns_one_run_per_iteration() {
		$this->put_fixture_file( 'wp-admin/a.php', 'x' );

		$bench = new WPCV_Stat_Bench( null, $this->make_monotonic_now() );
		$result = $bench->measure( ABSPATH . 'wp-admin', 3 );

		$this->assertCount( 3, $result['runs'] );
		$this->assertSame( array( 1, 2, 3 ), array_column( $result['runs'], 'iteration' ) );
	}

	/**
	 * `iterations` に1未満の値を渡すと1に切り上げられることを確認する.
	 *
	 * @return void
	 */
	public function test_measure_clamps_iterations_to_at_least_one() {
		$this->put_fixture_file( 'wp-admin/a.php', 'x' );

		$bench  = new WPCV_Stat_Bench( null, $this->make_monotonic_now() );
		$result = $bench->measure( ABSPATH . 'wp-admin', 0 );

		$this->assertCount( 1, $result['runs'] );
	}

	/**
	 * `lstat_cold`/`lstat_warm` の `entries`/`bytes` が実際のフィクスチャの
	 * サイズと一致することを確認する(`WPCV_Unknown_File_Scanner::scan()` の
	 * `collect_stat` 経路が正しく使われていることの検証).
	 *
	 * @return void
	 */
	public function test_lstat_measurements_reflect_actual_file_sizes() {
		$this->put_fixture_file( 'wp-admin/a.php', str_repeat( 'a', 100 ) );
		$this->put_fixture_file( 'wp-admin/b.php', str_repeat( 'b', 250 ) );

		$bench  = new WPCV_Stat_Bench( null, $this->make_monotonic_now() );
		$result = $bench->measure( ABSPATH . 'wp-admin', 1 );

		$cold = $result['runs'][0]['lstat_cold'];
		$warm = $result['runs'][0]['lstat_warm'];

		foreach ( array( $cold, $warm ) as $stats ) {
			$this->assertSame( 2, $stats['entries'] );
			$this->assertSame( 350, $stats['bytes'] );
		}
	}

	/**
	 * `entries_per_sec`/`bytes_per_sec` が `entries`/`bytes` を経過秒(常に1に
	 * 固定した `$now` を使う)で割った値と一致することを確認する.
	 *
	 * @return void
	 */
	public function test_per_second_rates_are_computed_from_elapsed_seconds() {
		$this->put_fixture_file( 'wp-admin/a.php', str_repeat( 'a', 100 ) );

		$bench  = new WPCV_Stat_Bench( null, $this->make_monotonic_now() );
		$result = $bench->measure( ABSPATH . 'wp-admin', 1 );

		$cold = $result['runs'][0]['lstat_cold'];

		$this->assertSame( (float) $cold['entries'], $cold['entries_per_sec'] );
		$this->assertSame( (float) $cold['bytes'], $cold['bytes_per_sec'] );
	}

	/**
	 * `triple_call_cold`/`triple_call_warm` も実際のフィクスチャの合計サイズを
	 * 反映することを確認する(rev.3 §3.9の比較対象。`filesize()`+`filectime()`+
	 * `filemtime()` の3回呼び経路).
	 *
	 * @return void
	 */
	public function test_triple_call_measurements_reflect_actual_file_sizes() {
		$this->put_fixture_file( 'wp-admin/a.php', str_repeat( 'a', 100 ) );
		$this->put_fixture_file( 'wp-admin/b.php', str_repeat( 'b', 250 ) );

		$bench  = new WPCV_Stat_Bench( null, $this->make_monotonic_now() );
		$result = $bench->measure( ABSPATH . 'wp-admin', 1 );

		foreach ( array( $result['triple_call_cold'], $result['triple_call_warm'] ) as $stats ) {
			$this->assertSame( 2, $stats['entries'] );
			$this->assertSame( 350, $stats['bytes'] );
		}
	}

	/**
	 * `lstat_only_cold`/`lstat_only_warm`(`triple_call_*`と条件を揃えた比較用の
	 * lstat計測)も実際のフィクスチャの合計サイズを反映し、`triple_call_*`と
	 * 同じ`entries`/`bytes`になることを確認する(公平な比較になっていることの
	 * 検証。test-armfu.localでの実測時に両者の条件が揃っていない不具合を
	 * 発見して修正した経緯に対応するテスト).
	 *
	 * @return void
	 */
	public function test_lstat_only_measurements_match_triple_call_entries_and_bytes() {
		$this->put_fixture_file( 'wp-admin/a.php', str_repeat( 'a', 100 ) );
		$this->put_fixture_file( 'wp-admin/b.php', str_repeat( 'b', 250 ) );

		$bench  = new WPCV_Stat_Bench( null, $this->make_monotonic_now() );
		$result = $bench->measure( ABSPATH . 'wp-admin', 1 );

		foreach ( array( $result['lstat_only_cold'], $result['lstat_only_warm'] ) as $stats ) {
			$this->assertSame( 2, $stats['entries'] );
			$this->assertSame( 350, $stats['bytes'] );
		}

		$this->assertSame( $result['lstat_only_cold']['entries'], $result['triple_call_cold']['entries'] );
		$this->assertSame( $result['lstat_only_cold']['bytes'], $result['triple_call_cold']['bytes'] );
	}

	/**
	 * 対象ディレクトリが空でも例外にならず、0件の結果を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_measure_handles_empty_directory_without_error() {
		mkdir( ABSPATH . 'wp-admin', 0777, true );

		$bench  = new WPCV_Stat_Bench( null, $this->make_monotonic_now() );
		$result = $bench->measure( ABSPATH . 'wp-admin', 1 );

		$this->assertSame( 0, $result['runs'][0]['lstat_cold']['entries'] );
		$this->assertSame( 0, $result['triple_call_cold']['entries'] );
	}

	/**
	 * `$include_content_hash` を省略(既定false)すると `content_hash_cold`/
	 * `content_hash_warm` が結果に含まれないことを確認する(v0.6 §Step8. 既定では
	 * ハッシュ計算を行わない、というクラスdocblockの方針の検証).
	 *
	 * @return void
	 */
	public function test_measure_omits_content_hash_by_default() {
		$this->put_fixture_file( 'wp-admin/a.php', 'x' );

		$bench  = new WPCV_Stat_Bench( null, $this->make_monotonic_now() );
		$result = $bench->measure( ABSPATH . 'wp-admin', 1 );

		$this->assertArrayNotHasKey( 'content_hash_cold', $result['runs'][0] );
		$this->assertArrayNotHasKey( 'content_hash_warm', $result['runs'][0] );
	}

	/**
	 * `$include_content_hash = true` のとき、`content_hash_cold`/
	 * `content_hash_warm` が実際のフィクスチャの合計サイズを反映することを
	 * 確認する(v0.6 §Step8. 層2の実測).
	 *
	 * @return void
	 */
	public function test_measure_includes_content_hash_when_requested() {
		$this->put_fixture_file( 'wp-admin/a.php', str_repeat( 'a', 100 ) );
		$this->put_fixture_file( 'wp-admin/b.php', str_repeat( 'b', 250 ) );

		$bench  = new WPCV_Stat_Bench( null, $this->make_monotonic_now() );
		$result = $bench->measure( ABSPATH . 'wp-admin', 1, true );

		foreach ( array( $result['runs'][0]['content_hash_cold'], $result['runs'][0]['content_hash_warm'] ) as $stats ) {
			$this->assertSame( 2, $stats['entries'] );
			$this->assertSame( 350, $stats['bytes'] );
		}
	}

	/**
	 * `$include_content_hash = true` を `iterations` 複数回で呼んでも、
	 * `runs` の各要素にそれぞれ `content_hash_cold`/`content_hash_warm` が
	 * 付くことを確認する(v0.6 §Step8).
	 *
	 * @return void
	 */
	public function test_measure_includes_content_hash_for_every_iteration() {
		$this->put_fixture_file( 'wp-admin/a.php', 'x' );

		$bench  = new WPCV_Stat_Bench( null, $this->make_monotonic_now() );
		$result = $bench->measure( ABSPATH . 'wp-admin', 2, true );

		$this->assertCount( 2, $result['runs'] );

		foreach ( $result['runs'] as $run ) {
			$this->assertArrayHasKey( 'content_hash_cold', $run );
			$this->assertArrayHasKey( 'content_hash_warm', $run );
		}
	}
}
