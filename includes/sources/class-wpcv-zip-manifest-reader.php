<?php
/**
 * WPCV_Zip_Manifest_Reader クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 配布物の zip を展開せずに検査し、ファイルごとの sha256 と md5 を計算する
 * (v0.8 §Step3. D4).
 *
 * `WPCV_Source_Wporg_Theme`(v0.7)が持っていた処理を、そのままの挙動で切り出した
 * 共有クラス. WordPress.org のテーマの zip と、GitHub Releases のアセットの zip
 * (v0.8 の `WPCV_Source_GitHub`)の両方が使う. 検査のロジックを二重に持たないための切り出しで、
 * 上限の値とフィルター名は呼び出し側が決めて `$limits` で渡す.
 *
 * zip はディスクに展開しない. `ZipArchive::getStream()` 等で読みながらハッシュを取るので、
 * zip slip(展開先の外への書き込み)は起こりえない. ただしコアの `unzip_file()` が持つ保護
 * (`validate_file()`・`__MACOSX` の除外など)も効かないため、エントリの検査は自前で行う
 * (WPMAR の `WPMAR_PDF_Installer::validate_entry_name()` / `entry_is_symlink()` の移植に、
 * ルートの検査・制御文字・エントリ数・サイズ・圧縮率の上限を足したもの).
 * 検査で1つでも落ちたエントリがあれば、何も返さない(`archive_rejected`).
 *
 * zip のルートの扱い:
 *
 * - `$root` が文字列: 最上位のディレクトリがその名前だけであること(WordPress.org のテーマは
 *   `{slug}/`).
 * - `$root` が null: 最上位のディレクトリが1つだけであれば、その名前を問わず取り除く
 *   (GitHub の配布 zip. l2d-updater は展開後にディレクトリ名を slug に直すので、zip の
 *   ルート名がインストール先と同じとは限らない).
 */
class WPCV_Zip_Manifest_Reader {

	/**
	 * ハッシュを取るときに1回で読むバイト数.
	 */
	const READ_CHUNK_BYTES = 65536;

	/**
	 * ZIP を開き、全エントリを検査してからハッシュを取る.
	 *
	 * @param string      $path   zip のパス.
	 * @param string|null $root   zip のルートディレクトリ名. null なら「1つだけのディレクトリ」を
	 *                            名前を問わず受け入れる.
	 * @param array       $limits 上限. `entries`(エントリ数)・`entry_bytes`(1エントリの展開後)・
	 *                            `total_bytes`(展開後の合計)・`ratio`(1エントリの圧縮率).
	 * @return array<string, array{sha256: string, md5: string}>|string 成功したらルートを除いた
	 *         相対パスをキーにしたハッシュの配列. 失敗したら `WPCV_Error_Code` の値
	 *         (`archive_invalid` / `archive_rejected`).
	 */
	public static function read( $path, $root, array $limits ) {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $path, ZipArchive::CHECKCONS ) ) {
			return WPCV_Error_Code::ARCHIVE_INVALID;
		}

		try {
			if ( null === $root ) {
				if ( $zip->count() > $limits['entries'] ) {
					return WPCV_Error_Code::ARCHIVE_REJECTED;
				}

				$root = self::detect_single_root( $zip );

				if ( null === $root ) {
					return WPCV_Error_Code::ARCHIVE_REJECTED;
				}
			}

			$entries = self::inspect_entries( $zip, $root, $limits );

			if ( null === $entries ) {
				return WPCV_Error_Code::ARCHIVE_REJECTED;
			}

			return self::hash_entries( $zip, $entries, $limits );
		} finally {
			$zip->close();
		}
	}

	/**
	 * 最上位のディレクトリが1つだけのとき、その名前を返す(`$root` が null のとき用).
	 *
	 * `__MACOSX/` は数えない. 最上位にファイルが直接ある場合は、その名前が「ルート」として
	 * 返り、あとの `validate_entry_name()` が拒否する(ルートにファイルを置かせない).
	 * ここでは名前の安全性は見ず、最上位の名前が何種類あるかだけを数える.
	 *
	 * @param ZipArchive $zip 開いた zip.
	 * @return string|null 最上位の名前が1種類だけならその名前. 0種類または複数なら null.
	 */
	private static function detect_single_root( ZipArchive $zip ) {
		$roots = array();
		$count = $zip->count();

		for ( $index = 0; $index < $count; $index++ ) {
			$stat = $zip->statIndex( $index );

			if ( false === $stat ) {
				return null;
			}

			$normalized = str_replace( '\\', '/', (string) $stat['name'] );
			$first      = explode( '/', ltrim( $normalized, '/' ), 2 )[0];

			if ( '' === $first || '__MACOSX' === $first ) {
				continue;
			}

			$roots[ $first ] = true;
		}

		return 1 === count( $roots ) ? (string) array_key_first( $roots ) : null;
	}

	/**
	 * 全エントリを `statIndex()` で検査し、ハッシュを取るファイルの一覧を返す
	 * (§3.2 の 7. 何も読まないうちに全部を検査する).
	 *
	 * @param ZipArchive $zip    開いた zip.
	 * @param string     $root   zip のルートディレクトリ名.
	 * @param array      $limits 上限(`read()` 参照).
	 * @return array<string, int>|null ルートを除いた相対パス => エントリの index. 1つでも
	 *                                 検査に落ちたら null.
	 */
	private static function inspect_entries( ZipArchive $zip, $root, array $limits ) {
		if ( $zip->count() > $limits['entries'] ) {
			return null;
		}

		$entries     = array();
		$total_bytes = 0;

		$count = $zip->count();

		for ( $index = 0; $index < $count; $index++ ) {
			// 名前の検査は元のバイト列で行う. 既定の読み方(エンコードの推測)では、
			// UTF-8 の印が無い名前の制御文字が CP437 の別の文字(`\x01` → `☺`)に
			// 置き換わり、検査をすり抜けるため(2026-10-01 に実際の zip で確認).
			$raw  = $zip->statIndex( $index, ZipArchive::FL_ENC_RAW );
			$stat = $zip->statIndex( $index );

			if ( false === $raw || false === $stat ) {
				return null;
			}

			$relative = self::validate_entry_name( (string) $raw['name'], (string) $stat['name'], $root );

			if ( false === $relative ) {
				return null;
			}

			// `__MACOSX/`(macOS の Finder が作る付随ファイル)はテーマの一部ではない.
			if ( null === $relative ) {
				continue;
			}

			if ( self::entry_is_symlink( $zip, $index ) ) {
				return null;
			}

			// ディレクトリのエントリは数えるだけで読まない(エントリ数の上限には入る).
			if ( '/' === substr( (string) $stat['name'], -1 ) ) {
				continue;
			}

			$size      = (int) $stat['size'];
			$comp_size = (int) $stat['comp_size'];

			if ( $size > $limits['entry_bytes'] ) {
				return null;
			}

			$total_bytes += $size;

			if ( $total_bytes > $limits['total_bytes'] ) {
				return null;
			}

			// 圧縮後が0バイトで展開後が1バイト以上なら、圧縮率は無限大とみなす.
			if ( $size > 0 && ( 0 === $comp_size || $size / $comp_size > $limits['ratio'] ) ) {
				return null;
			}

			// 同じパスのエントリが2つあると、どちらがローカルのファイルの正解か決まらない.
			if ( isset( $entries[ $relative ] ) ) {
				return null;
			}

			$entries[ $relative ] = $index;
		}

		return $entries;
	}

	/**
	 * エントリの名前を検査し、ルートを除いた相対パスを返す(WPMAR の
	 * `WPMAR_PDF_Installer::validate_entry_name()` を移植. トップレベルの許可リストは
	 * 「最初のセグメントが `{root}`」に置き換えた).
	 *
	 * @param string $raw_name     元のバイト列の名前(`ZipArchive::FL_ENC_RAW`).
	 * @param string $decoded_name 既定の読み方の名前(パスとして使う).
	 * @param string $root         zip のルートディレクトリ名.
	 * @return string|null|false ルートを除いた相対パス(ディレクトリのエントリは末尾の `/` を
	 *                           除いたもの. ルートのディレクトリは空文字列). `__MACOSX/`
	 *                           配下なら null. 拒否するなら false.
	 */
	private static function validate_entry_name( $raw_name, $decoded_name, $root ) {
		if ( '' === $raw_name || '' === $decoded_name ) {
			return false;
		}

		// NUL・制御文字. NUL は libzip が読み出す時点で空白に置き換えるため、実際には
		// ここまで届かない(2026-10-01 に実際の zip で確認). 展開しないので害は無いが、
		// 別の libzip で素通りした場合に備えて検査は残す.
		if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $raw_name . $decoded_name ) ) {
			return false;
		}

		$normalized = str_replace( '\\', '/', $decoded_name );

		// 先頭が「/」の絶対パスと、Windows のドライブレターを拒否する.
		if ( '/' === $normalized[0] || 1 === preg_match( '#^[A-Za-z]:#', $normalized ) ) {
			return false;
		}

		// ディレクトリのエントリの末尾の `/` だけは許す.
		$segments = explode( '/', rtrim( $normalized, '/' ) );

		foreach ( $segments as $segment ) {
			// `..` に加え、`.` と空のセグメント(`a//b`)も拒否する. ローカルのパスと
			// 突き合わせるので、正規化されていない名前は受け入れない.
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		if ( '__MACOSX' === $segments[0] ) {
			return null;
		}

		// zip のルートは `{root}/` の1つだけのはず(15テーマの実測ですべてこの形. §2.2).
		if ( $root !== $segments[0] ) {
			return false;
		}

		// ルートのディレクトリ自体(`{slug}/`)は相対パスが空になる. 呼び出し側は
		// ディレクトリのエントリとして読み飛ばす. 末尾に `/` が無い `{root}` は、
		// ルートに置かれたファイルなので拒否する.
		if ( 1 === count( $segments ) ) {
			return '/' === substr( $normalized, -1 ) ? '' : false;
		}

		return implode( '/', array_slice( $segments, 1 ) );
	}

	/**
	 * エントリが Unix のシンボリックリンクかどうか(WPMAR の
	 * `WPMAR_PDF_Installer::entry_is_symlink()` を移植).
	 *
	 * 外部属性の上位16ビットが Unix のファイルモードで、種別のビット(0xF000)が
	 * `S_IFLNK`(0xA000)ならシンボリックリンク.
	 *
	 * @param ZipArchive $zip   開いた zip.
	 * @param int        $index エントリの index.
	 * @return bool
	 */
	private static function entry_is_symlink( ZipArchive $zip, $index ) {
		$opsys = 0;
		$attr  = 0;

		if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
			return false;
		}

		if ( ZipArchive::OPSYS_UNIX !== $opsys ) {
			return false;
		}

		return 0xA000 === ( ( ( $attr >> 16 ) & 0xFFFF ) & 0xF000 );
	}

	/**
	 * 検査を通ったエントリを読みながら sha256 と md5 を取る(§3.2 の 8).
	 *
	 * `statIndex()` のサイズは zip の中に書かれた申告値で、実際の中身と違うことが
	 * ありうる. そのため読んだバイト数も数え、上限を超えたら打ち切る.
	 *
	 * @param ZipArchive         $zip     開いた zip.
	 * @param array<string, int> $entries `inspect_entries()` の戻り値.
	 * @param array              $limits  上限(`read()` 参照).
	 * @return array<string, array{sha256: string, md5: string}>|string 失敗したら `WPCV_Error_Code` の値.
	 */
	private static function hash_entries( ZipArchive $zip, array $entries, array $limits ) {
		$files       = array();
		$total_bytes = 0;

		foreach ( $entries as $relative => $index ) {
			// PHP 8.2 以上は index で読む(名前の読み方の違いで別のエントリを開かないため).
			// それより前は名前で読む(重複する名前は `inspect_entries()` で拒否済み).
			$stream = PHP_VERSION_ID >= 80200
				? $zip->getStreamIndex( $index )
				: $zip->getStream( (string) $zip->getNameIndex( $index ) );

			if ( false === $stream ) {
				return WPCV_Error_Code::ARCHIVE_INVALID;
			}

			$sha256      = hash_init( 'sha256' );
			$md5         = hash_init( 'md5' );
			$entry_bytes = 0;

			while ( ! feof( $stream ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading a ZipArchive stream (not a file on disk); WP_Filesystem does not apply.
				$chunk = fread( $stream, self::READ_CHUNK_BYTES );

				if ( false === $chunk ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fread above.
					fclose( $stream );

					return WPCV_Error_Code::ARCHIVE_INVALID;
				}

				$entry_bytes += strlen( $chunk );
				$total_bytes += strlen( $chunk );

				if ( $entry_bytes > $limits['entry_bytes'] || $total_bytes > $limits['total_bytes'] ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fread above.
					fclose( $stream );

					return WPCV_Error_Code::ARCHIVE_REJECTED;
				}

				hash_update( $sha256, $chunk );
				hash_update( $md5, $chunk );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see fread above.
			fclose( $stream );

			$files[ $relative ] = array(
				'sha256' => hash_final( $sha256 ),
				'md5'    => hash_final( $md5 ),
			);
		}

		return $files;
	}
}
