<?php
/**
 * WPCV_Action_Scheduler_Loader のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/runners/class-wpcv-action-scheduler-loader.php';

use PHPUnit\Framework\TestCase;

/**
 * `lib/` 優先・`vendor/` フォールバック・どちらも無ければ何もしない、の3パターンを
 * 一時ディレクトリのフィクスチャで検証する(`RunCoordinatorTest` と同様の掃除方式).
 *
 * 実際の Action Scheduler 本体を読み込むと重く、かつ複数テストで同じクラス群を
 * 再定義することになるため、フィクスチャは「読み込まれたら $GLOBALS に印を付ける
 * だけの軽量な PHP ファイル」にする。各テストは一意な一時ディレクトリを使うため
 * (絶対パスが毎回異なる)、`require_once` の重複排除がテスト間で衝突することはない.
 */
class ActionSchedulerLoaderTest extends TestCase {

	/**
	 * 今回のテストで作成した一時ディレクトリ(掃除対象).
	 *
	 * @var string|null
	 */
	private $plugin_dir;

	/**
	 * 各テストの後に一時ディレクトリを削除する.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_wpcv_test_as_loaded_from'] );

		if ( null !== $this->plugin_dir ) {
			$this->remove_path( $this->plugin_dir );
			$this->plugin_dir = null;
		}

		parent::tearDown();
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
	 * 空(何も持たない)一意な一時ディレクトリを作る.
	 *
	 * @return string 作成したディレクトリの絶対パス.
	 */
	private function make_plugin_dir() {
		$this->plugin_dir = sys_get_temp_dir() . '/wpcv-as-loader-test-' . uniqid( '', true );
		mkdir( $this->plugin_dir, 0777, true );

		return $this->plugin_dir;
	}

	/**
	 * `$relative_path` にフィクスチャファイルを置く.
	 *
	 * 読み込まれると `$GLOBALS['_wpcv_test_as_loaded_from']` に `$marker` を書き込む
	 * だけの内容にする(実際の Action Scheduler は読み込まない).
	 *
	 * @param string $plugin_dir    プラグインルート.
	 * @param string $relative_path プラグインルートからの相対パス.
	 * @param string $marker        読み込まれたことを示す印.
	 * @return void
	 */
	private function put_fixture( $plugin_dir, $relative_path, $marker ) {
		$absolute_path = $plugin_dir . '/' . $relative_path;
		$dir           = dirname( $absolute_path );

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		file_put_contents(
			$absolute_path,
			'<?php $GLOBALS[\'_wpcv_test_as_loaded_from\'] = ' . var_export( $marker, true ) . ';'
		);
	}

	/**
	 * `lib/` にも `vendor/` にも無い場合、何も require しないことを確認する.
	 *
	 * @return void
	 */
	public function test_maybe_load_does_nothing_when_neither_path_exists() {
		$plugin_dir = $this->make_plugin_dir();

		WPCV_Action_Scheduler_Loader::maybe_load( $plugin_dir );

		$this->assertArrayNotHasKey( '_wpcv_test_as_loaded_from', $GLOBALS );
	}

	/**
	 * `vendor/` のみ存在する場合、フォールバックしてそちらを読み込むことを確認する.
	 *
	 * @return void
	 */
	public function test_maybe_load_falls_back_to_vendor_when_lib_absent() {
		$plugin_dir = $this->make_plugin_dir();
		$this->put_fixture( $plugin_dir, 'vendor/woocommerce/action-scheduler/action-scheduler.php', 'vendor' );

		WPCV_Action_Scheduler_Loader::maybe_load( $plugin_dir );

		$this->assertSame( 'vendor', $GLOBALS['_wpcv_test_as_loaded_from'] );
	}

	/**
	 * `lib/` と `vendor/` の両方が存在する場合、`lib/` を優先することを確認する.
	 *
	 * @return void
	 */
	public function test_maybe_load_prefers_lib_over_vendor() {
		$plugin_dir = $this->make_plugin_dir();
		$this->put_fixture( $plugin_dir, 'lib/action-scheduler/action-scheduler.php', 'lib' );
		$this->put_fixture( $plugin_dir, 'vendor/woocommerce/action-scheduler/action-scheduler.php', 'vendor' );

		WPCV_Action_Scheduler_Loader::maybe_load( $plugin_dir );

		$this->assertSame( 'lib', $GLOBALS['_wpcv_test_as_loaded_from'] );
	}
}
