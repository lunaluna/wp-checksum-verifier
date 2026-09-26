<?php
/**
 * WPCV_Migrator のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-migrator.php';
require_once __DIR__ . '/doubles.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Migrator::get_stored_version()` のテスト(v0.3.1 §Step5).
 *
 * `maybe_upgrade()`/`create_or_update_tables()` は `global $wpdb` と実際の
 * `dbDelta()` に依存するため単体テスト対象外(実地検証側の責務。プロジェクト内
 * 既存の慣習を踏襲)。`get_stored_version()` はオプションの読み取りのみで
 * 完結するため、ここで検証する.
 */
class MigratorTest extends TestCase {

	/**
	 * 各テストの前に前回の残骸を掃除する.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		unset(
			$GLOBALS['_wpcv_test_is_multisite'],
			$GLOBALS['_wpcv_test_options'],
			$GLOBALS['_wpcv_test_site_options'],
			$GLOBALS['_wpcv_test_update_site_option_calls'],
			$GLOBALS['_wpcv_test_main_site_id'],
			$GLOBALS['wpdb']
		);
	}

	/**
	 * 単一サイトでは `wp_options`(`get_option()`)の値をそのまま返すことを確認する.
	 *
	 * @return void
	 */
	public function test_get_stored_version_reads_option_on_single_site() {
		$GLOBALS['_wpcv_test_options'][ WPCV_Migrator::DB_VERSION_OPTION ] = 3;

		$this->assertSame( 3, WPCV_Migrator::get_stored_version() );
	}

	/**
	 * 単一サイトで未保存なら 0 を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_get_stored_version_returns_zero_when_unset_on_single_site() {
		$this->assertSame( 0, WPCV_Migrator::get_stored_version() );
	}

	/**
	 * マルチサイトで site option が既に設定されていれば、それをそのまま返すことを確認する
	 * (`wp_options` 側に別の値があっても site option を優先する).
	 *
	 * @return void
	 */
	public function test_get_stored_version_prefers_site_option_on_multisite() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;
		$GLOBALS['_wpcv_test_site_options'][ WPCV_Migrator::DB_VERSION_OPTION ] = 2;
		$GLOBALS['_wpcv_test_options'][ WPCV_Migrator::DB_VERSION_OPTION ]      = 99;

		$this->assertSame( 2, WPCV_Migrator::get_stored_version() );
	}

	/**
	 * マルチサイトで site option が未設定の場合、main site の `wp_options` に
	 * 残る旧バージョンをフォールバックとして返し、かつ site option 側へ
	 * 書き込む(キャッシュする)ことを確認する(v0.3.1 §Step5. プラン§P2
	 * 「DB schema versionがマルチサイトでblog単位」の移行パスの確認).
	 *
	 * @return void
	 */
	public function test_get_stored_version_falls_back_to_legacy_option_and_caches_it() {
		$GLOBALS['_wpcv_test_is_multisite']                                = true;
		$GLOBALS['_wpcv_test_options'][ WPCV_Migrator::DB_VERSION_OPTION ] = 1;

		$this->assertSame( 1, WPCV_Migrator::get_stored_version() );
		$this->assertSame( 1, $GLOBALS['_wpcv_test_site_options'][ WPCV_Migrator::DB_VERSION_OPTION ] );
		$this->assertCount( 1, $GLOBALS['_wpcv_test_update_site_option_calls'] );
	}

	/**
	 * マルチサイトで site option・legacy option ともに未設定なら 0 を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_get_stored_version_returns_zero_when_nothing_stored_on_multisite() {
		$GLOBALS['_wpcv_test_is_multisite'] = true;

		$this->assertSame( 0, WPCV_Migrator::get_stored_version() );
	}

	/**
	 * `table_definitions()`(`create_or_update_tables()` から分離した SQL 組み立て
	 * 専用メソッド。v0.4.0 §Step1)が返す runs/target_runs/findings の CREATE TABLE
	 * 文に、v0.4.0 §Step1 で追加した列・index がすべて含まれることを確認する。
	 *
	 * `create_or_update_tables()` 自体(実際の dbDelta 呼び出し)は実 DB 依存のため
	 * 引き続きテスト対象外(クラス docblock 参照)だが、スキーマ定義の組み立てだけを
	 * 分離したことで「列の追加漏れ」を実 DB 無しで検出できるようにしてある.
	 *
	 * @return void
	 */
	public function test_table_definitions_include_step1_columns_and_indexes() {
		$GLOBALS['wpdb'] = new WPCV_Test_Fake_WPDB();

		$method = new ReflectionMethod( WPCV_Migrator::class, 'table_definitions' );
		$method->setAccessible( true );

		list( $sql_runs, $sql_target_runs, $sql_findings, $sql_suppressions ) = $method->invoke( null );

		foreach ( array( 'scheduled_for', 'heartbeat_at', 'deadline_at' ) as $column ) {
			$this->assertStringContainsString( $column, $sql_runs, "wpcv_runs is missing column: {$column}" );
		}

		foreach ( array( 'cursor_path', 'manifest_fingerprint', 'attempt_count', 'heartbeat_at', 'lease_owner', 'lease_expires_at', 'retry_after', 'idx_run_status' ) as $needle ) {
			$this->assertStringContainsString( $needle, $sql_target_runs, "wpcv_target_runs is missing column/index: {$needle}" );
		}

		$this->assertStringContainsString( 'suppression_id', $sql_findings );
		$this->assertStringContainsString( 'idx_suppression_id', $sql_findings );

		// 抑制テーブル自体は v0.4.0 §Step1 で変更しないため、既存の抑制3層の列が
		// 変わらず残っていることだけ確認する(回帰防止).
		$this->assertStringContainsString( 'type varchar(20) NOT NULL', $sql_suppressions );
	}

	/**
	 * `wpcv_findings` に v0.5(rev.3 §3.5)で追加した `detail` 列が含まれることを
	 * 確認する(stat差分検知の finding で size/ctime/mtime の差分を JSON で保持するため).
	 *
	 * @return void
	 */
	public function test_table_definitions_findings_includes_detail_column() {
		$GLOBALS['wpdb'] = new WPCV_Test_Fake_WPDB();

		$method = new ReflectionMethod( WPCV_Migrator::class, 'table_definitions' );
		$method->setAccessible( true );

		list( , , $sql_findings ) = $method->invoke( null );

		$this->assertMatchesRegularExpression( '/detail\s+text\s+NULL/', $sql_findings );
	}

	/**
	 * `table_definitions()` が5番目の要素として `wpcv_file_states`(v0.5・
	 * rev.3 §3.3 で新設)の CREATE TABLE 文を返し、必要な列・indexを含むことを
	 * 確認する.
	 *
	 * @return void
	 */
	public function test_table_definitions_include_file_states_table_columns_and_indexes() {
		$GLOBALS['wpdb'] = new WPCV_Test_Fake_WPDB();

		$method = new ReflectionMethod( WPCV_Migrator::class, 'table_definitions' );
		$method->setAccessible( true );

		$sqls = $method->invoke( null );

		$this->assertCount( 5, $sqls, 'table_definitions() must return 5 CREATE TABLE statements from v0.5 onward' );

		$sql_file_states = $sqls[4];

		foreach ( array(
			'state_key',
			'target_id',
			'dimension',
			'slug',
			'path',
			'file_size',
			'ctime',
			'mtime',
			'content_hash',
			'hash_algorithm',
			'baseline_version',
			'first_seen_run_id',
			'last_seen_run_id',
			'updated_at',
			'idx_state_key',
			'idx_target_last_seen',
			'idx_path',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $sql_file_states, "wpcv_file_states is missing column/index: {$needle}" );
		}

		$this->assertMatchesRegularExpression( '/state_key\s+binary\(32\)\s+NOT NULL/', $sql_file_states );
		$this->assertStringContainsString( 'UNIQUE KEY idx_state_key (state_key)', $sql_file_states );
		$this->assertStringContainsString( 'KEY idx_target_last_seen (target_id, last_seen_run_id)', $sql_file_states );

		// 層2(内容ハッシュ)専用の列は v0.5 では常に NULL 許容でなければならない
		// (層1のみのインストールでは書き込まれないため. §3.8参照).
		foreach ( array( 'content_hash', 'hash_algorithm', 'baseline_version' ) as $column ) {
			$this->assertMatchesRegularExpression(
				'/' . preg_quote( $column, '/' ) . '\s+[a-z0-9()]+\s+NULL/',
				$sql_file_states,
				"{$column} must be nullable"
			);
		}
	}

	/**
	 * §Step1 で追加した列がすべて NULL 許容(または default 付き)である
	 * ことを確認する。v0.3.1 以前に作成された既存行は新しい列の値を持たないため、
	 * NOT NULL かつ default 無しの列を追加すると、既存行の読み取り互換
	 * (新列を NULL として読める)が壊れる(プラン§v0.4.0「migrationの再実行性と、
	 * v0.3.1既存runの読み取り互換をテストする」への対応).
	 *
	 * `attempt_count`(`NOT NULL default 0`)は default 付きのため対象外.
	 *
	 * @return void
	 */
	public function test_table_definitions_new_columns_are_nullable_for_v0_3_1_read_compat() {
		$GLOBALS['wpdb'] = new WPCV_Test_Fake_WPDB();

		$method = new ReflectionMethod( WPCV_Migrator::class, 'table_definitions' );
		$method->setAccessible( true );

		list( $sql_runs, $sql_target_runs, $sql_findings ) = $method->invoke( null );

		$nullable_columns_by_sql = array(
			$sql_runs        => array( 'scheduled_for', 'heartbeat_at', 'deadline_at' ),
			$sql_target_runs => array( 'cursor_path', 'manifest_fingerprint', 'heartbeat_at', 'lease_owner', 'lease_expires_at', 'retry_after' ),
			$sql_findings    => array( 'suppression_id' ),
		);

		foreach ( $nullable_columns_by_sql as $sql => $columns ) {
			foreach ( $columns as $column ) {
				$this->assertMatchesRegularExpression(
					'/' . preg_quote( $column, '/' ) . '\s+[a-z]+(?:\s+unsigned)?(?:\(\d+\))?\s+NULL/',
					$sql,
					"{$column} must be nullable for backward compatibility with pre-v0.4.0 rows"
				);
			}
		}
	}

	/**
	 * `parse_column_names()`(`schema_is_current()` 専用のヘルパー。v0.4.0コード
	 * レビューCR-05是正)が、1列1行のCREATE TABLE文から列名だけを正しく抽出し、
	 * `PRIMARY KEY`/`KEY`/`UNIQUE KEY` 等のインデックス行を除外することを確認する。
	 * 手書きの独立したSQL片で検証することで、`table_definitions()` 自身の出力を
	 * 使った他のテストとは独立に抽出ロジックの正しさを確認できるようにしている.
	 *
	 * `UNIQUE KEY` 行は v0.5 で `wpcv_file_states` に追加した際に `UNIQUE` を
	 * 列名と誤認し、有効化が必ず失敗する不具合の原因になった(回帰防止).
	 *
	 * @return void
	 */
	public function test_parse_column_names_extracts_columns_and_excludes_keys() {
		$method = new ReflectionMethod( WPCV_Migrator::class, 'parse_column_names' );
		$method->setAccessible( true );

		$sql = "CREATE TABLE wp_example (
	id bigint unsigned NOT NULL auto_increment,
	name varchar(191) NOT NULL,
	created_at datetime NULL,
	PRIMARY KEY (id),
	UNIQUE KEY idx_unique_name (name),
	unique index idx_lower (created_at),
	KEY idx_name (name),
	INDEX idx_created (created_at),
	FULLTEXT KEY idx_ft (name)
) utf8mb4_general_ci;";

		$this->assertSame( array( 'id', 'name', 'created_at' ), $method->invoke( null, $sql ) );
	}

	/**
	 * `wpcv_file_states` の定義から抽出される列名が、実DBの `DESCRIBE` が返す
	 * はずの列名と完全に一致することを、`parse_column_names()` に依存しない
	 * 手書きの期待値で確認する.
	 *
	 * `test_schema_is_current_*` はフェイクwpdbの「正しいスキーマ」自体を
	 * `parse_column_names()` で組み立てるため、パーサーが余計な列名
	 * (`UNIQUE` 等)を返しても期待値側にも同じ誤りが入って打ち消し合い、
	 * 検出できなかった. その穴を塞ぐための独立した検証.
	 *
	 * @return void
	 */
	public function test_parse_column_names_matches_explicit_file_states_columns() {
		$GLOBALS['wpdb'] = new WPCV_Test_Fake_WPDB();

		$definitions = new ReflectionMethod( WPCV_Migrator::class, 'table_definitions' );
		$definitions->setAccessible( true );
		$parse = new ReflectionMethod( WPCV_Migrator::class, 'parse_column_names' );
		$parse->setAccessible( true );

		$sqls = $definitions->invoke( null );

		$this->assertSame(
			array(
				'id',
				'state_key',
				'target_id',
				'dimension',
				'slug',
				'path',
				'file_size',
				'ctime',
				'mtime',
				'content_hash',
				'hash_algorithm',
				'baseline_version',
				'first_seen_run_id',
				'last_seen_run_id',
				'updated_at',
			),
			$parse->invoke( null, $sqls[4] )
		);
	}

	/**
	 * 5テーブルすべてについて、抽出した列名にインデックス・制約のキーワードが
	 * 紛れ込んでいないことを確認する(今後 `table_definitions()` に新しい種類の
	 * インデックス行を追加した場合の回帰防止).
	 *
	 * @return void
	 */
	public function test_parse_column_names_never_returns_sql_keywords_for_table_definitions() {
		$GLOBALS['wpdb'] = new WPCV_Test_Fake_WPDB();

		$definitions = new ReflectionMethod( WPCV_Migrator::class, 'table_definitions' );
		$definitions->setAccessible( true );
		$parse = new ReflectionMethod( WPCV_Migrator::class, 'parse_column_names' );
		$parse->setAccessible( true );

		foreach ( $definitions->invoke( null ) as $sql ) {
			foreach ( $parse->invoke( null, $sql ) as $column ) {
				$this->assertNotContains( strtoupper( $column ), WPCV_Migrator::NON_COLUMN_LINE_KEYWORDS, "parsed SQL keyword as column: {$column}" );
			}
		}
	}

	/**
	 * `schema_is_current()`(v0.4.0コードレビューCR-05是正)が、4テーブルすべてに
	 * 期待する列がそろっている場合に `true` を返すことを確認する.
	 *
	 * @return void
	 */
	public function test_schema_is_current_returns_true_when_all_expected_columns_present() {
		$wpdb            = new WPCV_Test_Fake_WPDB();
		$GLOBALS['wpdb'] = $wpdb;

		$this->populate_fake_schema_from_table_definitions( $wpdb );

		$method = new ReflectionMethod( WPCV_Migrator::class, 'schema_is_current' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( null ) );
	}

	/**
	 * `schema_is_current()` が、1テーブルでも期待する列が1つ欠けていれば
	 * `false` を返すことを確認する(v0.4.0コードレビューCR-05是正: `dbDelta()` が
	 * ALTER権限不足等で一部の列を追加できなかった状態を模す).
	 *
	 * @return void
	 */
	public function test_schema_is_current_returns_false_when_a_column_is_missing() {
		$wpdb            = new WPCV_Test_Fake_WPDB();
		$GLOBALS['wpdb'] = $wpdb;

		$this->populate_fake_schema_from_table_definitions( $wpdb );

		// `wpcv_target_runs` の `lease_owner` 列だけがALTERに失敗した状態を模す.
		$table = $wpdb->base_prefix . 'wpcv_target_runs';
		$wpdb->columns_by_table[ $table ] = array_values(
			array_diff( $wpdb->columns_by_table[ $table ], array( 'lease_owner' ) )
		);

		$method = new ReflectionMethod( WPCV_Migrator::class, 'schema_is_current' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( null ) );
	}

	/**
	 * `table_definitions()` の出力を `parse_column_names()` に通し、フェイクwpdbの
	 * `columns_by_table`(=実DBの `DESCRIBE` 相当)を「dbDeltaが完全に成功した」
	 * 状態として組み立てる(`test_schema_is_current_*` の共通セットアップ).
	 *
	 * @param WPCV_Test_Fake_WPDB $wpdb フェイク wpdb.
	 * @return void
	 */
	private function populate_fake_schema_from_table_definitions( WPCV_Test_Fake_WPDB $wpdb ) {
		$table_definitions_method = new ReflectionMethod( WPCV_Migrator::class, 'table_definitions' );
		$table_definitions_method->setAccessible( true );
		$parse_method = new ReflectionMethod( WPCV_Migrator::class, 'parse_column_names' );
		$parse_method->setAccessible( true );

		$tables = array(
			$wpdb->base_prefix . 'wpcv_runs',
			$wpdb->base_prefix . 'wpcv_target_runs',
			$wpdb->base_prefix . 'wpcv_findings',
			$wpdb->base_prefix . 'wpcv_suppressions',
			$wpdb->base_prefix . 'wpcv_file_states',
		);
		$sqls = $table_definitions_method->invoke( null );

		foreach ( $tables as $index => $table ) {
			$wpdb->columns_by_table[ $table ] = $parse_method->invoke( null, $sqls[ $index ] );
		}
	}
}
