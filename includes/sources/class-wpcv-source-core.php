<?php
/**
 * WPCV_Source_Core クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress コアの checksum マニフェスト取得(§3.2).
 *
 * `get_core_checksums()`(`wp-admin/includes/update.php`)を使う. WP-CLI 不要、
 * PHP のみで完結する.
 *
 * v0.7 §Step4(§3.5): `get_core_checksums()` は呼ぶたびに HTTP を出す(自前の
 * キャッシュを持たない. 7.1.2 の `update.php` で確認). コア同梱テーマの照合
 * (D7)で、テーマの target もコアのマニフェストを見るようになったため、
 * `WPCV_Manifest_Cache_Repository`(`source = core`、slug = locale)に保存して
 * 2回目からはそこから返す(`manifest_status = cached`). en_US に切り替えて取得した
 * マニフェスト(`locale_fallback`)はキャッシュしない. キャッシュには期限が無いため、
 * 保存すると、後でその locale のマニフェストが公開されても en_US との照合が
 * 続いてしまうから.
 */
class WPCV_Source_Core implements WPCV_Manifest_Source {

	/**
	 * マニフェストのキャッシュ. `null` ならキャッシュしない(v0.6 までと同じ).
	 *
	 * @var WPCV_Manifest_Cache_Repository|null
	 */
	private $cache;

	/**
	 * コンストラクタ.
	 *
	 * @param WPCV_Manifest_Cache_Repository|null $cache マニフェストのキャッシュ(v0.7 §Step4).
	 */
	public function __construct( ?WPCV_Manifest_Cache_Repository $cache = null ) {
		$this->cache = $cache;
	}

	/**
	 * コアの checksum マニフェストを取得する.
	 *
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string $version 照合対象の WordPress バージョン. 必須(checksum
	 *                           verifier は推測しない。§3.4 と同じ原則).
	 * }
	 * @return array インターフェースの docblock を参照.
	 *
	 * @throws InvalidArgumentException バージョンが指定されていない場合.
	 */
	public function get_manifest( array $context ) {
		if ( empty( $context['version'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Source_Core::get_manifest() requires $context[\'version\'].' ) );
		}

		if ( ! function_exists( 'get_core_checksums' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$version = (string) $context['version'];

		$locale = self::current_locale();

		if ( null !== $this->cache ) {
			$cached = $this->cache->find( WPCV_Manifest_Cache_Repository::SOURCE_CORE, $locale, $version );

			if ( null !== $cached ) {
				return array(
					'manifest_status' => 'cached',
					'error_code'      => null,
					'files'           => self::cached_to_files( $cached['files'] ),
				);
			}
		}

		$checksums       = get_core_checksums( $version, $locale );
		$manifest_status = 'ok';

		// locale マニフェストが未発行の場合、en_US にフォールバックする(§3.2).
		// ja コアの locale マニフェストは特に遅れることがある(§8.6).
		if ( false === $checksums && 'en_US' !== $locale ) {
			$checksums       = get_core_checksums( $version, 'en_US' );
			$manifest_status = 'locale_fallback';
		}

		if ( false === $checksums ) {
			return array(
				'manifest_status' => 'missing',
				'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
				'files'           => array(),
			);
		}

		if ( null !== $this->cache && 'ok' === $manifest_status ) {
			$to_cache = array();

			foreach ( $checksums as $path => $hash ) {
				$to_cache[ $path ] = array( 'md5' => (string) $hash );
			}

			$this->cache->save( WPCV_Manifest_Cache_Repository::SOURCE_CORE, $locale, $version, $to_cache );
		}

		return array(
			'manifest_status' => $manifest_status,
			'error_code'      => null,
			'files'           => self::to_files( $checksums ),
		);
	}

	/**
	 * コアのマニフェストの locale を返す(`Core_Upgrader::upgrade()` と同じ決め方.
	 * class-core-upgrader.php). キャッシュのキー(slug)にも使う. v0.7 §Step7 で、
	 * キャッシュの掃除(`WPCV_Manifest_Cache_Cleaner`)と共有するため切り出した.
	 *
	 * @return string
	 */
	public static function current_locale() {
		global $wp_local_package;

		return isset( $wp_local_package ) && '' !== (string) $wp_local_package ? (string) $wp_local_package : 'en_US';
	}

	/**
	 * キャッシュの形(`{ path: { md5 } }`)を、共通のマニフェスト形式に変換する
	 * (`to_files()` と同じ結果になる).
	 *
	 * @param array $cached_files キャッシュの `files`.
	 * @return array インターフェースの docblock にある `files` の形式.
	 */
	private static function cached_to_files( array $cached_files ) {
		$checksums = array();

		foreach ( $cached_files as $path => $hashes ) {
			if ( is_array( $hashes ) && isset( $hashes['md5'] ) && '' !== $hashes['md5'] ) {
				$checksums[ (string) $path ] = (string) $hashes['md5'];
			}
		}

		return self::to_files( $checksums );
	}

	/**
	 * `get_core_checksums()` の戻り値(相対パス => md5文字列)を、共通のマニフェスト形式に変換する.
	 *
	 * @param array $checksums `get_core_checksums()` の戻り値.
	 * @return array インターフェースの docblock にある `files` の形式.
	 */
	private static function to_files( array $checksums ) {
		$files = array();

		foreach ( $checksums as $path => $hash ) {
			$files[ $path ] = array(
				'algorithm' => WPCV_File_Hasher::ALGO_MD5,
				'hashes'    => (array) $hash,
			);
		}

		return $files;
	}
}
