<?php
/**
 * WPCV_Unknown_File_Scanner のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-path-normalizer.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-chunk-budget.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-unknown-file-scanner.php';

use PHPUnit\Framework\TestCase;

/**
 * §3.3: マニフェストに無い実在ファイルの検出(未知ファイル走査)のテスト.
 *
 * ABSPATH(tests/bootstrap.php で tests/fixtures/fake-root/ に固定)配下に
 * 実ファイル・実ディレクトリを作って走査する。各テストの前後で ABSPATH 直下を
 * `.gitkeep`(このリポジトリが元々持つ常設フィクスチャ)以外すべて掃除する
 * (個別のファイル名を列挙する方式だと、新しいテストを足すたびに掃除対象への
 * 追加を忘れて前のテストの残骸と衝突する事故が起きるため).
 */
class UnknownFileScannerTest extends TestCase {

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
	 * $items から 'path' の一覧だけを取り出す(assertSame の比較を読みやすくする).
	 *
	 * @param array $items scan() の戻り値の `items`.
	 * @return string[]
	 */
	private function paths_of( array $items ) {
		return array_column( $items, 'path' );
	}

	/**
	 * マニフェストに無い .php ファイルが high severity で検出されることを確認する.
	 *
	 * @return void
	 */
	public function test_flags_unknown_php_file_in_recursive_scan() {
		$this->put_fixture_file( 'wp-admin/index.php' );
		$this->put_fixture_file( 'wp-admin/evil.php' );

		$scanner = new WPCV_Unknown_File_Scanner();
		$result  = $scanner->scan(
			ABSPATH . 'wp-admin',
			array( 'wp-admin/index.php' => array() )
		);

		$this->assertFalse( $result['truncated'] );
		$this->assertSame( array( 'wp-admin/evil.php' ), $this->paths_of( $result['items'] ) );
		$this->assertSame( 'high', $result['items'][0]['severity'] );
	}

	/**
	 * $known_files に含まれるファイルは検出されないことを確認する.
	 *
	 * @return void
	 */
	public function test_ignores_known_files() {
		$this->put_fixture_file( 'wp-admin/index.php' );

		$scanner = new WPCV_Unknown_File_Scanner();
		$result  = $scanner->scan(
			ABSPATH . 'wp-admin',
			array( 'wp-admin/index.php' => array() )
		);

		$this->assertSame( array(), $result['items'] );
	}

	/**
	 * recursive = true(既定)ではサブディレクトリの奥まで検出することを確認する.
	 *
	 * @return void
	 */
	public function test_recursive_descends_into_subdirectories() {
		$this->put_fixture_file( 'wp-admin/includes/backdoor.php' );

		$scanner = new WPCV_Unknown_File_Scanner();
		$result  = $scanner->scan( ABSPATH . 'wp-admin', array() );

		$this->assertSame( array( 'wp-admin/includes/backdoor.php' ), $this->paths_of( $result['items'] ) );
	}

	/**
	 * recursive = false ではサブディレクトリに降りず、直下のファイルのみを
	 * 対象にすることを確認する(§3.3: ABSPATH 直下・非再帰).
	 *
	 * @return void
	 */
	public function test_non_recursive_does_not_descend() {
		$this->put_fixture_file( 'index.php' );
		$this->put_fixture_file( 'wp-content/should-not-be-seen.php' );

		$scanner = new WPCV_Unknown_File_Scanner();
		// fake-root/.gitkeep はこのテストクラスとは無関係に常設されているフィクス
		// チャファイルのため、既知として除外する.
		$result = $scanner->scan(
			rtrim( ABSPATH, '/' ),
			array( '.gitkeep' => array() ),
			array( 'recursive' => false )
		);

		$this->assertSame( array( 'index.php' ), $this->paths_of( $result['items'] ) );
	}

	/**
	 * php_severity / non_php_severity を指定すると拡張子で severity が
	 * 使い分けられることを確認する(§3.3: ABSPATH 直下は high/medium を使い分ける).
	 *
	 * @return void
	 */
	public function test_distinguishes_php_and_non_php_severity() {
		$this->put_fixture_file( 'shell.php' );
		$this->put_fixture_file( 'note.txt' );

		$scanner = new WPCV_Unknown_File_Scanner();
		$result  = $scanner->scan(
			rtrim( ABSPATH, '/' ),
			array(),
			array(
				'recursive'        => false,
				'php_severity'     => 'high',
				'non_php_severity' => 'medium',
			)
		);

		$by_path = array();
		foreach ( $result['items'] as $item ) {
			$by_path[ $item['path'] ] = $item['severity'];
		}

		$this->assertSame( 'high', $by_path['shell.php'] );
		$this->assertSame( 'medium', $by_path['note.txt'] );
	}

	/**
	 * `.htaccess` / `.user.ini` は拡張子の単純一致では拾えないため、basename での
	 * 個別判定で high 扱いになることを確認する.
	 *
	 * @return void
	 */
	public function test_flags_htaccess_and_user_ini_as_php_like_by_basename() {
		$this->put_fixture_file( '.htaccess' );
		$this->put_fixture_file( '.user.ini' );

		$scanner = new WPCV_Unknown_File_Scanner();
		$result  = $scanner->scan(
			rtrim( ABSPATH, '/' ),
			array(),
			array(
				'recursive'        => false,
				'php_severity'     => 'high',
				'non_php_severity' => 'medium',
			)
		);

		$by_path = array();
		foreach ( $result['items'] as $item ) {
			$by_path[ $item['path'] ] = $item['severity'];
		}

		$this->assertSame( 'high', $by_path['.htaccess'] );
		$this->assertSame( 'high', $by_path['.user.ini'] );
	}

	/**
	 * extra_excluded_paths に指定したパスは検出対象から除外されることを確認する
	 * (§3.3: ABSPATH 直下の `.htaccess` / `wp-config.php` はハッシュ承認対象で
	 * 未知ファイル検出の対象外).
	 *
	 * @return void
	 */
	public function test_extra_excluded_paths_are_skipped() {
		$this->put_fixture_file( '.htaccess' );
		$this->put_fixture_file( 'wp-config.php' );
		$this->put_fixture_file( 'index.php' );

		$scanner = new WPCV_Unknown_File_Scanner();
		// fake-root/.gitkeep はこのテストクラスとは無関係に常設されているフィクス
		// チャファイルのため、既知として除外する.
		$result = $scanner->scan(
			rtrim( ABSPATH, '/' ),
			array( '.gitkeep' => array() ),
			array(
				'recursive'            => false,
				'extra_excluded_paths' => array( '.htaccess', 'wp-config.php' ),
			)
		);

		$this->assertSame( array( 'index.php' ), $this->paths_of( $result['items'] ) );
	}

	/**
	 * `.git` / `node_modules` / `.well-known` の配下には降りないことを確認する
	 * (§3.3: 既定の除外).
	 *
	 * @return void
	 */
	public function test_default_excluded_directories_are_never_descended() {
		$this->put_fixture_file( 'wp-content/mu-plugins/.git/config' );
		$this->put_fixture_file( 'wp-content/mu-plugins/node_modules/pkg/index.js' );
		$this->put_fixture_file( 'wp-content/mu-plugins/.well-known/acme-challenge/token' );
		$this->put_fixture_file( 'wp-content/mu-plugins/real-backdoor.php' );

		$scanner = new WPCV_Unknown_File_Scanner();
		$result  = $scanner->scan( ABSPATH . 'wp-content/mu-plugins', array() );

		$this->assertSame( array( 'wp-content/mu-plugins/real-backdoor.php' ), $this->paths_of( $result['items'] ) );
	}

	/**
	 * 存在しないディレクトリを指定した場合は空配列を返すことを確認する
	 * (権限エラー等と同様、例外にせず検出結果0件として扱う).
	 *
	 * @return void
	 */
	public function test_returns_empty_array_for_nonexistent_base_dir() {
		$scanner = new WPCV_Unknown_File_Scanner();
		$result  = $scanner->scan( ABSPATH . 'no-such-directory', array() );

		$this->assertSame( array(), $result['items'] );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * `budget.max_seconds` を超えると walk 自体が打ち切られ、`truncated: true` を
	 * 返すことを確認する(v0.4.0コードレビューCR-08是正。`$now` callable を注入して
	 * 経過時間を制御する).
	 *
	 * @return void
	 */
	public function test_scan_truncates_when_walk_exceeds_max_seconds_budget() {
		$this->put_fixture_file( 'wp-admin/a.php' );
		$this->put_fixture_file( 'wp-admin/b.php' );

		// 1回目(開始時刻)は 0.0、以降は常に 100.0(経過100秒)を返す.
		$call_count = 0;
		$now        = function () use ( &$call_count ) {
			return 0 === $call_count++ ? 0.0 : 100.0;
		};

		$scanner = new WPCV_Unknown_File_Scanner( $now );
		$result  = $scanner->scan(
			ABSPATH . 'wp-admin',
			array(),
			array( 'budget' => array( 'max_seconds' => 10 ) )
		);

		$this->assertTrue( $result['truncated'] );
	}

	/**
	 * `budget.memory_limit_bytes` を超えても walk 自体が打ち切られることを確認する
	 * (`$memory_usage` callable を注入).
	 *
	 * @return void
	 */
	public function test_scan_truncates_when_walk_exceeds_memory_budget() {
		$this->put_fixture_file( 'wp-admin/a.php' );
		$this->put_fixture_file( 'wp-admin/b.php' );

		$scanner = new WPCV_Unknown_File_Scanner(
			null,
			static function () {
				return 950;
			}
		);

		$result = $scanner->scan(
			ABSPATH . 'wp-admin',
			array(),
			array(
				'budget' => array(
					'memory_limit_bytes'     => 1000,
					'memory_threshold_ratio' => 0.9,
				),
			)
		);

		$this->assertTrue( $result['truncated'] );
	}

	/**
	 * budget を指定しない(既定)場合は打ち切られず、ディレクトリの規模に関わらず
	 * 常に完走することを確認する(既存の呼び出し元との後方互換).
	 *
	 * @return void
	 */
	public function test_scan_does_not_truncate_without_budget() {
		$this->put_fixture_file( 'wp-admin/a.php' );
		$this->put_fixture_file( 'wp-admin/b.php' );
		$this->put_fixture_file( 'wp-admin/c.php' );

		$scanner = new WPCV_Unknown_File_Scanner();
		$result  = $scanner->scan( ABSPATH . 'wp-admin', array() );

		$this->assertFalse( $result['truncated'] );
		$this->assertCount( 3, $result['items'] );
	}
}
