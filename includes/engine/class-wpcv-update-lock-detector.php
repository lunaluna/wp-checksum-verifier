<?php
/**
 * WPCV_Update_Lock_Detector クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `.maintenance` ファイル・updater lock option の有無を判定する(v0.6 §3.4・D10).
 *
 * `wp_is_maintenance_mode()` はそのまま使わない ―― `enable_maintenance_mode`
 * フィルターの影響を受け、`wp_installing()` 中は無条件に偽を返す
 * (`wp-includes/load.php` のソース参照。プラン §3.4)。ここでは同じ判定条件
 * (`.maintenance` の存在 + `$upgrading` からの経過時間)だけを自前で再実装する.
 *
 * `core_updater.lock`/`auto_updater.lock` は `WP_Upgrader::create_lock()`
 * (`wp-admin/includes/class-wp-upgrader.php`)が書く単なる option
 * (`{lock_name}.lock` にUnixタイムスタンプ)なので `get_option()` で直接読む.
 * `create_lock()` の `$release_timeout` 実値はコアのソースで確認済み
 * (`core_updater`=15分、`auto_updater`=既定の1時間). マルチサイトでも
 * `wp_version_check`/`wp_maybe_auto_update` はサイトごとにスケジュールされる
 * cronイベントであり、このクラスが動く実行コンテキストと同じサイトの
 * `$wpdb->options` を読み書きするため、サイトをまたいだ不一致は起きない.
 */
class WPCV_Update_Lock_Detector {

	/**
	 * `.maintenance` が「更新中」とみなされる上限(コアの `load.php` と同じ10分).
	 *
	 * @var int
	 */
	const MAINTENANCE_MAX_AGE_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * `core_updater.lock` の上限(`class-core-upgrader.php` の
	 * `create_lock( 'core_updater', 15 * MINUTE_IN_SECONDS )` と同じ値).
	 *
	 * @var int
	 */
	const CORE_UPDATER_LOCK_MAX_AGE_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * `auto_updater.lock` の上限(`class-wp-automatic-updater.php` の
	 * `create_lock( 'auto_updater' )` は第2引数省略 = `create_lock()` 既定の
	 * `HOUR_IN_SECONDS`).
	 *
	 * @var int
	 */
	const AUTO_UPDATER_LOCK_MAX_AGE_SECONDS = HOUR_IN_SECONDS;

	/**
	 * ABSPATH(末尾スラッシュ付き).
	 *
	 * @var string
	 */
	private $abspath;

	/**
	 * 現在時刻(Unix timestamp)を返す callable.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * `.maintenance` の `$upgrading` 値(int)を読む callable。ファイルが無ければ `null`.
	 *
	 * @var callable
	 */
	private $maintenance_upgrading_reader;

	/**
	 * コンストラクタ.
	 *
	 * @param string|null   $abspath                      省略時は `ABSPATH`.
	 * @param callable|null $now                           省略時は `time()`.
	 * @param callable|null $maintenance_upgrading_reader  省略時は実際に
	 *                                                      `.maintenance` を読む実装
	 *                                                      (テストでファイルシステムに
	 *                                                      依存せず`$upgrading`値を注入
	 *                                                      できるようにするため).
	 */
	public function __construct( $abspath = null, ?callable $now = null, ?callable $maintenance_upgrading_reader = null ) {
		$this->abspath = null !== $abspath ? rtrim( $abspath, '/' ) . '/' : ABSPATH;

		$this->now = $now ?? static function () {
			return time();
		};

		$this->maintenance_upgrading_reader = $maintenance_upgrading_reader ?? array( $this, 'read_maintenance_upgrading' );
	}

	/**
	 * `.maintenance` または updater lock により延期すべきかを判定する(D10).
	 *
	 * @return bool
	 */
	public function is_deferred() {
		return $this->is_maintenance_active() || $this->is_updater_lock_active();
	}

	/**
	 * `.maintenance` が存在し、かつ `$upgrading` から
	 * `self::MAINTENANCE_MAX_AGE_SECONDS` 未満かを判定する.
	 *
	 * 10分以上前の `.maintenance`(更新が途中で失敗して残ったもの)は延期しない
	 * (D10の表。`core:_scan` が未知ファイルとして検出することを妨げないため).
	 *
	 * @return bool
	 */
	private function is_maintenance_active() {
		$upgrading = call_user_func( $this->maintenance_upgrading_reader );

		if ( ! is_int( $upgrading ) ) {
			return false;
		}

		return ( call_user_func( $this->now ) - $upgrading ) < self::MAINTENANCE_MAX_AGE_SECONDS;
	}

	/**
	 * `self::$maintenance_upgrading_reader` の既定実装。`.maintenance` の
	 * `$upgrading` 値を取り出す.
	 *
	 * コアの `wp_is_maintenance_mode()`(`wp-includes/load.php`)は
	 * `include ABSPATH . '.maintenance';` で読むが、ここでは `include` せず
	 * `file_get_contents()` + 正規表現で読む。理由は2つ: (1) `.maintenance` の
	 * 中身は `WP_Upgrader::create_lock()` 呼び出し元(`class-wp-upgrader.php`)が
	 * 書く固定フォーマット `<?php $upgrading = <timestamp>; ?>` のみで、
	 * PHPを実行しなくても値を取り出せる. (2) `include` で任意PHPを実行する
	 * 経路を増やさずに済む(このファイルはABSPATH直下にあり通常書き込み権限が
	 * 及ぶ範囲だが、実行経路を増やさないに越したことはない).
	 *
	 * @return int|null ファイルが無い、または `$upgrading` を取り出せなければ `null`.
	 */
	private function read_maintenance_upgrading() {
		$file = $this->abspath . '.maintenance';

		if ( ! file_exists( $file ) ) {
			return null;
		}

		// ABSPATH直下のローカルファイル読み取りであり、`WordPress.WP.AlternativeFunctions`
		// が想定するリモートURL(`wp_remote_get()`推奨)には当たらない.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = file_get_contents( $file );

		if ( false === $contents || ! preg_match( '/\$upgrading\s*=\s*(\d+)/', $contents, $matches ) ) {
			return null;
		}

		return (int) $matches[1];
	}

	/**
	 * `core_updater.lock`/`auto_updater.lock` のいずれかが有効期限内かを判定する.
	 *
	 * @return bool
	 */
	private function is_updater_lock_active() {
		return $this->is_lock_option_active( 'core_updater.lock', self::CORE_UPDATER_LOCK_MAX_AGE_SECONDS )
			|| $this->is_lock_option_active( 'auto_updater.lock', self::AUTO_UPDATER_LOCK_MAX_AGE_SECONDS );
	}

	/**
	 * `{$lock_name}.lock` option(値はUnixタイムスタンプ)が `$max_age_seconds`
	 * 未満かを判定する.
	 *
	 * @param string $option_name     option名(`core_updater.lock`/`auto_updater.lock`).
	 * @param int    $max_age_seconds 上限(秒).
	 * @return bool
	 */
	private function is_lock_option_active( $option_name, $max_age_seconds ) {
		$locked_at = get_option( $option_name, false );

		if ( false === $locked_at ) {
			return false;
		}

		return ( call_user_func( $this->now ) - (int) $locked_at ) < $max_age_seconds;
	}
}
