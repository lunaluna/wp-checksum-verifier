<?php
/**
 * WPCV_GitHub_Mappings クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * プラグイン・テーマと GitHub リポジトリの対応付け(v0.8プラン §4.1・U2).
 *
 * GitHub と照合する対象は**手動の対応付けだけ**で決める(ヘッダーの `Update URI` /
 * `Plugin URI` からの自動検出はしない. U2). 対応付けの無い target は今までと同じ
 * (wp.org → 見つからなければ stat)になる.
 *
 * 1件 = `target`(`plugin:{slug}` / `theme:{stylesheet}`)・`repo`(`{owner}/{repo}`)・
 * `asset`(アセット名の前方一致. 省略可).
 *
 * 対応付けは次の2つから集める(`resolve()`):
 *
 * 1. 設定 `github_mappings`(`WPCV_Settings`. 設定画面のテキストエリア. Step7)
 * 2. フィルター `wpcv_github_mappings`(設定のあとに通す. コードで対応付けたい運用者向け)
 *
 * テキストエリアの1行は `plugin:wp-checksum-verifier lunaluna/wp-checksum-verifier [asset]`
 * (空白区切り. 空行と `#` で始まる行は無視). 検査で落ちた行・件は捨てて、理由を
 * `errors`(`line`・`reason`)で返す. 画面側が理由のコードを翻訳した文言にする.
 *
 * このクラスは HTTP・DB に触れない(`resolve()` が設定を読むときだけ options を読む).
 */
class WPCV_GitHub_Mappings {

	/**
	 * 理由のコード: 行の形が不正(項目が足りない・多い).
	 */
	const REASON_INVALID_FORMAT = 'invalid_format';

	/**
	 * 理由のコード: target の形が不正(`plugin:`・`theme:` 以外、または slug の文字が不正).
	 */
	const REASON_INVALID_TARGET = 'invalid_target';

	/**
	 * 理由のコード: repo の形が不正(`WPCV_GitHub_Client::is_valid_repo()`).
	 */
	const REASON_INVALID_REPO = 'invalid_repo';

	/**
	 * 理由のコード: 同じ target が既にある(後のものを捨てる).
	 */
	const REASON_DUPLICATE_TARGET = 'duplicate_target';

	/**
	 * Target の形. slug・stylesheet に使える文字は暫定(入れ子のテーマ `dir/sub` は対象外).
	 */
	const TARGET_PATTERN = '#^(plugin|theme):([A-Za-z0-9._-]+)$#';

	/**
	 * アセット名の前方一致に使える文字(空白を含まない. 暫定).
	 */
	const ASSET_PATTERN = '#^[A-Za-z0-9._+~-]+$#';

	/**
	 * テキストエリアの内容を対応付けの配列にする.
	 *
	 * @param string $raw 1行1件の文字列.
	 * @return array{entries: array<int, array{target: string, repo: string, asset: string}>, errors: array<int, array{line: int, reason: string}>}
	 */
	public static function parse_text( $raw ) {
		$entries = array();
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $raw );

		foreach ( false === $lines ? array() : $lines as $index => $line ) {
			$line = trim( $line );

			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}

			$fields = preg_split( '/\s+/', $line );
			$fields = false === $fields ? array() : $fields;

			$entries[] = array(
				'line'   => $index + 1,
				'fields' => $fields,
			);
		}

		$valid  = array();
		$errors = array();

		foreach ( $entries as $item ) {
			$count = count( $item['fields'] );

			if ( $count < 2 || $count > 3 ) {
				$errors[] = array(
					'line'   => $item['line'],
					'reason' => self::REASON_INVALID_FORMAT,
				);
				continue;
			}

			$valid[] = array(
				'line'  => $item['line'],
				'entry' => array(
					'target' => $item['fields'][0],
					'repo'   => $item['fields'][1],
					'asset'  => 3 === $count ? $item['fields'][2] : '',
				),
			);
		}

		$normalized = self::validate( $valid );

		return array(
			'entries' => $normalized['entries'],
			'errors'  => array_merge( $errors, $normalized['errors'] ),
		);
	}

	/**
	 * 対応付けの配列を、テキストエリアに出す1行1件の文字列にする.
	 *
	 * @param array $entries `target`・`repo`・`asset` を持つ配列の配列.
	 * @return string
	 */
	public static function format_text( array $entries ) {
		$lines = array();

		foreach ( $entries as $entry ) {
			$line = $entry['target'] . ' ' . $entry['repo'];

			if ( '' !== (string) ( $entry['asset'] ?? '' ) ) {
				$line .= ' ' . $entry['asset'];
			}

			$lines[] = $line;
		}

		return implode( "\n", $lines );
	}

	/**
	 * 対応付けの配列(設定・フィルターの値)を検査し、target_id をキーにした表にする.
	 *
	 * 形が不正な件・同じ target の2件目以降は捨てる(1件目が残る).
	 *
	 * @param array $entries `target`・`repo`・`asset` を持つ配列の配列(形は保証しない).
	 * @return array{map: array<string, array{repo: string, asset: string}>, errors: array<int, array{line: int, reason: string}>}
	 *         `map` のキーは `WPCV_Target_Resolver::build_id()` と同じ target_id. `errors` の `line` は
	 *         配列の何件目か(1始まり).
	 */
	public static function normalize( array $entries ) {
		$items = array();

		foreach ( array_values( $entries ) as $index => $entry ) {
			$items[] = array(
				'line'  => $index + 1,
				'entry' => array(
					'target' => is_array( $entry ) && isset( $entry['target'] ) ? (string) $entry['target'] : '',
					'repo'   => is_array( $entry ) && isset( $entry['repo'] ) ? (string) $entry['repo'] : '',
					'asset'  => is_array( $entry ) && isset( $entry['asset'] ) ? (string) $entry['asset'] : '',
				),
			);
		}

		$validated = self::validate( $items );
		$map       = array();

		foreach ( $validated['entries'] as $entry ) {
			$map[ $entry['target'] ] = array(
				'repo'  => $entry['repo'],
				'asset' => $entry['asset'],
			);
		}

		return array(
			'map'    => $map,
			'errors' => $validated['errors'],
		);
	}

	/**
	 * 実行時に使う対応付け(設定 → フィルターの順)を、target_id をキーにした表で返す.
	 *
	 * @return array<string, array{repo: string, asset: string}>
	 */
	public static function resolve() {
		$entries = WPCV_Settings::get_github_mappings();

		/**
		 * GitHub リポジトリとの対応付けを、設定画面の値のあとに足す・変える(v0.8 §Step6. U2).
		 *
		 * 形が不正な件・同じ target の2件目以降は捨てられる.
		 *
		 * @param array<int, array{target: string, repo: string, asset?: string}> $entries 設定画面の対応付け.
		 */
		$entries = apply_filters( 'wpcv_github_mappings', $entries );

		// フィルターが配列以外を返しても、空として扱う.
		return self::normalize( (array) $entries )['map'];
	}

	/**
	 * 検査して、通った件と落ちた理由に分ける(`parse_text()`・`normalize()` の共通処理).
	 *
	 * @param array<int, array{line: int, entry: array{target: string, repo: string, asset: string}}> $items 行番号付きの件.
	 * @return array{entries: array<int, array{target: string, repo: string, asset: string}>, errors: array<int, array{line: int, reason: string}>}
	 */
	private static function validate( array $items ) {
		$entries = array();
		$errors  = array();
		$seen    = array();

		foreach ( $items as $item ) {
			$entry = $item['entry'];
			$line  = $item['line'];

			if ( 1 !== preg_match( self::TARGET_PATTERN, $entry['target'], $matches ) || '' === trim( $matches[2], '.' ) ) {
				$errors[] = array(
					'line'   => $line,
					'reason' => self::REASON_INVALID_TARGET,
				);
				continue;
			}

			if ( ! WPCV_GitHub_Client::is_valid_repo( $entry['repo'] ) ) {
				$errors[] = array(
					'line'   => $line,
					'reason' => self::REASON_INVALID_REPO,
				);
				continue;
			}

			if ( '' !== $entry['asset'] && 1 !== preg_match( self::ASSET_PATTERN, $entry['asset'] ) ) {
				$errors[] = array(
					'line'   => $line,
					'reason' => self::REASON_INVALID_FORMAT,
				);
				continue;
			}

			if ( isset( $seen[ $entry['target'] ] ) ) {
				$errors[] = array(
					'line'   => $line,
					'reason' => self::REASON_DUPLICATE_TARGET,
				);
				continue;
			}

			$seen[ $entry['target'] ] = true;
			$entries[]                = $entry;
		}

		return array(
			'entries' => $entries,
			'errors'  => $errors,
		);
	}
}
