<?php
/**
 * WPCV_Update_Lock_Detector のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-update-lock-detector.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Update_Lock_Detector`(v0.6 §Step6. D10)のテスト。
 *
 * `.maintenance` の読み方は `$maintenance_upgrading_reader` を注入して
 * ファイルシステムに依存せず検証する(実ファイルでの読み方自体は
 * `test_is_deferred_reads_real_maintenance_file()` の1件だけ確かめる)。
 * `core_updater.lock`/`auto_updater.lock` は `get_option()` スタブ
 * (`$GLOBALS['_wpcv_test_options']`)で模擬する.
 *
 * D10の組み合わせ(§3.4): `.maintenance`(無し/10分未満/10分以上)×
 * `core_updater.lock`(無し/15分未満/15分以上)×`auto_updater.lock`
 * (無し/1時間未満/1時間以上)の全てを1件ずつ確認する.
 */
class UpdateLockDetectorTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['_wpcv_test_options'] = array();
	}

	/**
	 * `$now` を固定した Detector を組み立てる.
	 *
	 * @param int|null      $upgrading `.maintenance` の `$upgrading`(`null`ならファイル無し).
	 * @param callable|null $now       省略時は固定時刻 `1000000`.
	 * @return WPCV_Update_Lock_Detector
	 */
	private function make_detector( $upgrading, ?callable $now = null ) {
		return new WPCV_Update_Lock_Detector(
			'/tmp/wpcv-test-abspath-not-used',
			$now ?? static function () {
				return 1000000;
			},
			static function () use ( $upgrading ) {
				return $upgrading;
			}
		);
	}

	/**
	 * 何も無ければ延期しない.
	 *
	 * @return void
	 */
	public function test_is_deferred_false_when_nothing_present() {
		$detector = $this->make_detector( null );

		$this->assertFalse( $detector->is_deferred() );
	}

	/**
	 * `.maintenance` が10分未満なら延期する.
	 *
	 * @return void
	 */
	public function test_is_deferred_true_when_maintenance_is_fresh() {
		$detector = $this->make_detector( 1000000 - ( 9 * MINUTE_IN_SECONDS ) );

		$this->assertTrue( $detector->is_deferred() );
	}

	/**
	 * `.maintenance` がちょうど10分以上前(コアの `>=` 条件と同じ境界)なら延期しない
	 * (更新が途中で失敗して残ったものとして扱う. D10の表参照).
	 *
	 * @return void
	 */
	public function test_is_deferred_false_when_maintenance_is_stale() {
		$detector = $this->make_detector( 1000000 - ( 10 * MINUTE_IN_SECONDS ) );

		$this->assertFalse( $detector->is_deferred() );
	}

	/**
	 * `$upgrading` が整数でない(壊れた`.maintenance`)場合は延期しない.
	 *
	 * @return void
	 */
	public function test_is_deferred_false_when_maintenance_upgrading_is_not_int() {
		$detector = $this->make_detector( 'not-an-int' );

		$this->assertFalse( $detector->is_deferred() );
	}

	/**
	 * `core_updater.lock` が15分未満なら延期する.
	 *
	 * @return void
	 */
	public function test_is_deferred_true_when_core_updater_lock_is_fresh() {
		$GLOBALS['_wpcv_test_options']['core_updater.lock'] = 1000000 - ( 14 * MINUTE_IN_SECONDS );

		$detector = $this->make_detector( null );

		$this->assertTrue( $detector->is_deferred() );
	}

	/**
	 * `core_updater.lock` が15分以上前なら延期しない.
	 *
	 * @return void
	 */
	public function test_is_deferred_false_when_core_updater_lock_is_stale() {
		$GLOBALS['_wpcv_test_options']['core_updater.lock'] = 1000000 - ( 15 * MINUTE_IN_SECONDS );

		$detector = $this->make_detector( null );

		$this->assertFalse( $detector->is_deferred() );
	}

	/**
	 * `auto_updater.lock` が1時間未満なら延期する.
	 *
	 * @return void
	 */
	public function test_is_deferred_true_when_auto_updater_lock_is_fresh() {
		$GLOBALS['_wpcv_test_options']['auto_updater.lock'] = 1000000 - ( HOUR_IN_SECONDS - 1 );

		$detector = $this->make_detector( null );

		$this->assertTrue( $detector->is_deferred() );
	}

	/**
	 * `auto_updater.lock` が1時間以上前なら延期しない.
	 *
	 * @return void
	 */
	public function test_is_deferred_false_when_auto_updater_lock_is_stale() {
		$GLOBALS['_wpcv_test_options']['auto_updater.lock'] = 1000000 - HOUR_IN_SECONDS;

		$detector = $this->make_detector( null );

		$this->assertFalse( $detector->is_deferred() );
	}

	/**
	 * `.maintenance`が古くても`auto_updater.lock`が新しければ延期する(OR条件.
	 * D10の表は複数行を独立に判定するため、組み合わさっても正しく`true`になる
	 * ことを確認する).
	 *
	 * @return void
	 */
	public function test_is_deferred_true_when_only_one_of_multiple_signals_is_fresh() {
		$GLOBALS['_wpcv_test_options']['auto_updater.lock'] = 1000000 - ( HOUR_IN_SECONDS - 1 );

		$detector = $this->make_detector( 1000000 - ( 20 * MINUTE_IN_SECONDS ) );

		$this->assertTrue( $detector->is_deferred() );
	}

	/**
	 * 実際に `.maintenance` ファイルを読む既定実装(`file_get_contents()` +
	 * 正規表現で `$upgrading` を取り出す)を1件だけ確認する(他のケースは
	 * 注入したreaderで確認済み)。フィクスチャの中身は
	 * `WP_Upgrader::create_lock()` 呼び出し元(`class-wp-upgrader.php`)が実際に
	 * 書く固定フォーマット(`<?php $upgrading = <timestamp>; ?>`。整数リテラル1つ
	 * のみで式は含まない)と一致させる.
	 *
	 * @return void
	 */
	public function test_is_deferred_reads_real_maintenance_file() {
		$dir = sys_get_temp_dir() . '/wpcv-update-lock-detector-test-' . uniqid( '', true );
		mkdir( $dir );
		file_put_contents( $dir . '/.maintenance', '<?php $upgrading = ' . ( 1000000 - 60 ) . '; ?>' );

		try {
			$detector = new WPCV_Update_Lock_Detector(
				$dir,
				static function () {
					return 1000000;
				}
			);

			$this->assertTrue( $detector->is_deferred() );
		} finally {
			unlink( $dir . '/.maintenance' );
			rmdir( $dir );
		}
	}
}
