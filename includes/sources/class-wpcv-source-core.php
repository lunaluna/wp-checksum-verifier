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
 */
class WPCV_Source_Core implements WPCV_Manifest_Source {

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

		// Core_Upgrader::upgrade() と同じ locale 決定ロジック(class-core-upgrader.php).
		global $wp_local_package;
		$locale = $wp_local_package ?? 'en_US';

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

		return array(
			'manifest_status' => $manifest_status,
			'error_code'      => null,
			'files'           => self::to_files( $checksums ),
		);
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
