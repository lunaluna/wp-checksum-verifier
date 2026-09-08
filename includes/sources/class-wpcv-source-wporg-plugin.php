<?php
/**
 * WPCV_Source_Wporg_Plugin クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 公式プラグイン(wp.org)の checksum マニフェスト取得(§3.4).
 *
 * 対象 URL は `https://downloads.wordpress.org/plugin-checksums/{slug}/{version}.json`。
 * sha256 を優先し、無い場合のみ md5 にフォールバックする(使用したアルゴリズムは
 * ファイルごとに `files[$path]['algorithm']` に記録し、呼び出し側が
 * `findings.hash_algorithm` に転記する).
 *
 * ローカルのバージョンは `get_plugins()` のヘッダー値のみを使う運用とし、wp.org
 * 側から stable version を推測することはしない(rev.2 での方針転換。ローカルが
 * 1.4.7 なのに 1.6.0 のマニフェストと比較して誤った警告を出す事故を避けるため)。
 * version が空の場合は HTTP リクエストを行わず `error_code = version_unknown` を返す.
 */
class WPCV_Source_Wporg_Plugin implements WPCV_Manifest_Source {

	/**
	 * マニフェスト取得先の URL テンプレート. `{slug}/{version}.json` を埋め込む.
	 */
	const MANIFEST_URL_TEMPLATE = 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json';

	/**
	 * HTTP リクエストのタイムアウト秒数.
	 *
	 * 未実測: 暫定値。実測の上で見直すこと(§数値を決める前に実測するルール).
	 */
	const REQUEST_TIMEOUT = 10;

	/**
	 * 公式プラグインの checksum マニフェストを取得する.
	 *
	 * @param array $context {
	 *     コンテキスト.
	 *
	 *     @type string $slug    プラグインの slug(`WP_PLUGIN_DIR` 直下のディレクトリ名). 必須.
	 *     @type string $version ローカルにインストールされているプラグインのバージョン
	 *                           (`get_plugins()` のヘッダー値)。取得できない場合は空文字列
	 *                           または未指定でよく、その場合は HTTP リクエストを行わず
	 *                           `error_code = version_unknown` を返す(§3.4: checksum
	 *                           verifier はバージョンを推測しない).
	 * }
	 * @return array インターフェースの docblock を参照.
	 *
	 * @throws InvalidArgumentException 必須の slug が指定されていない場合(呼び出し側の
	 *                                   実装ミス。version と異なり slug はディレクトリ名
	 *                                   から常に決定できるため実行時状態ではない).
	 */
	public function get_manifest( array $context ) {
		if ( empty( $context['slug'] ) ) {
			throw new InvalidArgumentException( esc_html( 'WPCV_Source_Wporg_Plugin::get_manifest() requires $context[\'slug\'].' ) );
		}

		// バージョンはローカル環境の実行時状態(get_plugins() が読めるかどうか)に
		// 依存する正当な運用上の状態であり、slug と異なり例外にはしない(§3.4).
		if ( empty( $context['version'] ) ) {
			return array(
				'manifest_status' => 'missing',
				'error_code'      => WPCV_Error_Code::VERSION_UNKNOWN,
				'files'           => array(),
			);
		}

		$slug    = (string) $context['slug'];
		$version = (string) $context['version'];
		$url     = sprintf( self::MANIFEST_URL_TEMPLATE, rawurlencode( $slug ), rawurlencode( $version ) );

		$response = wp_remote_get( $url, array( 'timeout' => self::REQUEST_TIMEOUT ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'manifest_status' => 'missing',
				'error_code'      => WPCV_Error_Code::HTTP_ERROR,
				'files'           => array(),
			);
		}

		$response_code = (int) wp_remote_retrieve_response_code( $response );

		// 404 は「未発行(または存在しない slug/version)」であり、これは異常系ではなく
		// checksum 検証における通常の運用状態(§8.6: 取得失敗は unverifiable/
		// manifest_not_found に落とし success にしない)。404 以外の非 200 は
		// 接続・サーバー側の問題として http_error にする.
		if ( 404 === $response_code ) {
			return array(
				'manifest_status' => 'missing',
				'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
				'files'           => array(),
			);
		}

		if ( 200 !== $response_code ) {
			return array(
				'manifest_status' => 'missing',
				'error_code'      => WPCV_Error_Code::HTTP_ERROR,
				'files'           => array(),
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// 不正な JSON・想定外の構造(files が無い等)は、壊れたレスポンスを success
		// として通してしまわないよう「実質マニフェストが存在しない」扱いにする.
		if ( ! is_array( $data ) || empty( $data['files'] ) || ! is_array( $data['files'] ) ) {
			return array(
				'manifest_status' => 'missing',
				'error_code'      => WPCV_Error_Code::MANIFEST_NOT_FOUND,
				'files'           => array(),
			);
		}

		return array(
			'manifest_status' => 'ok',
			'error_code'      => null,
			'files'           => self::to_files( $data['files'] ),
		);
	}

	/**
	 * JSON の `files` 部分を共通のマニフェスト形式に変換する.
	 *
	 * §3.4 の方針どおり sha256 を優先し、無い場合のみ md5 にフォールバックする。値は
	 * 文字列と配列(複数候補)の両方があり得るため `(array)` キャストで揃える.
	 *
	 * @param array $raw_files JSON デコード後の `files` 部分(`{path}.{md5,sha256}`).
	 * @return array インターフェースの docblock にある `files` の形式.
	 */
	private static function to_files( array $raw_files ) {
		$files = array();

		foreach ( $raw_files as $path => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			if ( isset( $entry['sha256'] ) && '' !== $entry['sha256'] ) {
				$files[ $path ] = array(
					'algorithm' => WPCV_File_Hasher::ALGO_SHA256,
					'hashes'    => (array) $entry['sha256'],
				);
				continue;
			}

			if ( isset( $entry['md5'] ) && '' !== $entry['md5'] ) {
				$files[ $path ] = array(
					'algorithm' => WPCV_File_Hasher::ALGO_MD5,
					'hashes'    => (array) $entry['md5'],
				);
			}
		}

		return $files;
	}
}
