<?php
/**
 * WPCV_Manifest_Cache_Repository クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wpcv_manifest_cache` テーブルの永続化を担当する(v0.7プラン §3.1・U3・D3・D4参照).
 *
 * WordPress.org のテーマには checksum API が無いため(§1.1)、テーマのマニフェストは zip を
 * 取得して作る(D1・D2). zip の取得は1テーマあたり 1.5〜2.4 秒かかる(§2.2 の実測)ので、
 * 作ったマニフェストをこのテーブルに保存し、2回目以降の run と、同じ run の2つ目
 * 以降の chunk(`WPCV_Chunk_Dispatcher` は chunk ごとにマニフェストを取り直す. §3.4)
 * ではここから返す. v0.7 Step4 からはコアのマニフェスト(`source = core`)も入れる.
 *
 * キーは `(source, slug, version)`. 期限は持たない(D4: 同じ slug・version の zip は
 * 差し替えられない前提. この前提は未確証で、プラン §9-2 に申し送りがある). 不要に
 * なった行は run の終端で `delete_except()` で消す(呼び出し側は v0.7 Step7).
 *
 * `files` 列は `{ path: { sha256, md5 } }` の JSON で保存する(D3). このクラスは
 * 中身の形を検査せず、渡された配列をそのまま保存・復元する(形を決めるのは
 * マニフェストのソース側).
 */
class WPCV_Manifest_Cache_Repository {

	/**
	 * WordPress.org のテーマの zip から作ったマニフェスト(v0.7 Step2).
	 *
	 * @var string
	 */
	const SOURCE_WPORG_THEME = 'wporg_theme';

	/**
	 * コアのマニフェスト(v0.7 Step4. slug には locale を入れる想定. §3.5).
	 *
	 * @var string
	 */
	const SOURCE_CORE = 'core';

	/**
	 * `slug` 列の長さ(`WPCV_Migrator::table_definitions()` の varchar(100) と同じ).
	 *
	 * @var int
	 */
	const MAX_SLUG_LENGTH = 100;

	/**
	 * `version` 列の長さ(`WPCV_Migrator::table_definitions()` の varchar(64) と同じ).
	 *
	 * @var int
	 */
	const MAX_VERSION_LENGTH = 64;

	/**
	 * `$wpdb` 相当のオブジェクト(`query()` / `get_results()` / `delete()` /
	 * `prepare()` / `base_prefix` を持つもの).
	 *
	 * @var object
	 */
	private $wpdb;

	/**
	 * 現在時刻(UTC の MySQL DATETIME 文字列)を返す callable.
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
	 * キャッシュから1件を読む.
	 *
	 * `files` 列の JSON が壊れていた場合(配列に戻せない場合)は、その行を消して
	 * `null`(キャッシュなし)を返す. 消さずに残すと、`save()` は `INSERT IGNORE` の
	 * ため壊れた行を上書きできず、毎回 zip を取り直し続けることになるため.
	 *
	 * @param string $source  `SOURCE_*` 定数のいずれか.
	 * @param string $slug    テーマの stylesheet など.
	 * @param string $version version.
	 * @return array{files: array, file_count: int, archive_bytes: int, fetched_at: string}|null
	 *         見つからなければ null.
	 */
	public function find( $source, $slug, $version ) {
		$table = $this->table();

		$sql = "SELECT * FROM {$table} WHERE source = %s AND slug = %s AND version = %s LIMIT 1";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; values are bound via prepare() here.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, (string) $source, (string) $slug, (string) $version ), ARRAY_A );

		if ( ! is_array( $rows ) || array() === $rows ) {
			return null;
		}

		$row   = $rows[0];
		$files = json_decode( (string) $row['files'], true );

		if ( ! is_array( $files ) ) {
			// 壊れた行は消しておく(メソッドの docblock 参照). 消せなくても読み取りは
			// キャッシュなしとして続けられるので、戻り値は確かめない.
			$this->wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );

			return null;
		}

		return array(
			'files'         => $files,
			'file_count'    => (int) $row['file_count'],
			'archive_bytes' => (int) $row['archive_bytes'],
			'fetched_at'    => (string) $row['fetched_at'],
		);
	}

	/**
	 * キャッシュに1件を保存する.
	 *
	 * 同じ `(source, slug, version)` の行が既にあれば何もしない(`INSERT IGNORE`).
	 * 2つのワーカーが同じテーマを同時に取りに行った場合でも、作られるマニフェストは
	 * 同じなので、後の書き込みを捨ててよい(§3.1).
	 *
	 * `slug`・`version` が列の長さを超える場合は保存せず `false` を返す. MySQL の
	 * 設定(strict モードでない場合)によっては値が黙って切り詰められ、別の
	 * slug・version の行と同じキーになって、違うテーマのマニフェストを返して
	 * しまうおそれがあるため. 呼び出し側はキャッシュなしで照合を続ければよい.
	 *
	 * @param string $source        `SOURCE_*` 定数のいずれか.
	 * @param string $slug          テーマの stylesheet など.
	 * @param string $version       version.
	 * @param array  $files         `{ path: { sha256, md5 } }` の形の配列(D3).
	 * @param int    $archive_bytes 取得した zip のサイズ(コアなど zip が無いものは 0).
	 * @return bool 保存した(または同じキーの行が既にあった)なら true. 長さの制限で
	 *              保存しなかったなら false.
	 *
	 * @throws RuntimeException `$wpdb->query()` が SQL エラーで `false` を返した場合
	 *                          (他の Repository と同じく SQL エラーを見逃さないため).
	 */
	public function save( $source, $slug, $version, array $files, $archive_bytes = 0 ) {
		$slug    = (string) $slug;
		$version = (string) $version;

		if ( mb_strlen( $slug, 'UTF-8' ) > self::MAX_SLUG_LENGTH || mb_strlen( $version, 'UTF-8' ) > self::MAX_VERSION_LENGTH ) {
			return false;
		}

		$table = $this->table();

		// パスの `/` をエスケープしない(`\/` にすると保存サイズが増えるだけで意味は同じ).
		$json = wp_json_encode( $files, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$sql = "INSERT IGNORE INTO {$table} (source, slug, version, files, file_count, archive_bytes, fetched_at) VALUES (%s, %s, %s, %s, %d, %d, %s)";

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; values are bound via prepare() here.
		$prepared = $this->wpdb->prepare( $sql, (string) $source, $slug, $version, (string) $json, count( $files ), max( 0, (int) $archive_bytes ), call_user_func( $this->now ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- $prepared is the output of prepare() above.
		$result = $this->wpdb->query( $prepared );

		if ( false === $result ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						'WPCV_Manifest_Cache_Repository::save() の insert に失敗しました: %s',
						(string) $this->wpdb->last_error
					)
				)
			);
		}

		return true;
	}

	/**
	 * 指定した source の行のうち、`$keep` に含まれない `(slug, version)` の行を消す
	 * (D4. run の終端で「今回の run の target に現れなかった (slug, version)」を
	 * 消すために使う. 呼び出し側は v0.7 Step7).
	 *
	 * 呼び出し側は、run が `failed` で終わったとき(列挙が途中で止まっている可能性が
	 * ある)はこのメソッドを呼ばないこと(§3.1).
	 *
	 * 他の Repository(`WPCV_Update_Event_Repository::delete_older_than()` など)と同じく、
	 * 対象の行を SELECT して id 単位で `$wpdb->delete()` を呼ぶ. 行の数はインストール
	 * されているテーマの数(と、その直前の version)程度なので、1クエリにまとめる
	 * 最適化は要らない.
	 *
	 * @param string     $source `SOURCE_*` 定数のいずれか.
	 * @param string[][] $keep   残す `array( slug, version )` の組の配列.
	 * @return int 消した行数.
	 *
	 * @throws RuntimeException `$wpdb->delete()` が SQL エラーで `false` を返した場合.
	 */
	public function delete_except( $source, array $keep ) {
		$table = $this->table();

		// 「slug + NUL + version」を集合のキーにする(slug・version は NUL を含まない).
		$keep_keys = array();

		foreach ( $keep as $pair ) {
			$keep_keys[ (string) $pair[0] . "\0" . (string) $pair[1] ] = true;
		}

		$sql = "SELECT id, slug, version FROM {$table} WHERE source = %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- $sql is a fixed literal (table name only) built above; the value is bound via prepare() here.
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, (string) $source ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$deleted = 0;

		foreach ( $rows as $row ) {
			if ( isset( $keep_keys[ (string) $row['slug'] . "\0" . (string) $row['version'] ] ) ) {
				continue;
			}

			$result = $this->wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );

			if ( false === $result ) {
				throw new RuntimeException(
					esc_html(
						sprintf(
							'WPCV_Manifest_Cache_Repository::delete_except() の delete に失敗しました: %s',
							(string) $this->wpdb->last_error
						)
					)
				);
			}

			++$deleted;
		}

		return $deleted;
	}

	/**
	 * テーブル名を返す(installation-level のため `base_prefix`. `WPCV_Migrator` 参照).
	 *
	 * @return string
	 */
	private function table() {
		return $this->wpdb->base_prefix . 'wpcv_manifest_cache';
	}
}
