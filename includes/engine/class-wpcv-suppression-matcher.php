<?php
/**
 * WPCV_Suppression_Matcher クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finding単位の抑制判定を行う(v0.4.0 §Step8。プラン§7の抑制3層)。
 *
 * DBアクセスを持たない静的ユーティリティにし、`WPCV_Suppression_Repository` が
 * 取得したルール配列を受け取って判定するだけにする(単体テストで実DB・
 * テストダブルを介さずロジックだけを検証できるようにするため).
 *
 * 優先順位は `exclude_target -> exclude_path -> allowlist_hash -> finding` の順
 * (プラン§7)。`exclude_target` は `WPCV_Run_Planner::plan()` が target 列挙
 * 時点で適用する(検証自体を行わない)ため、本クラスが扱うのは `exclude_path` ->
 * `allowlist_hash` -> soft change(readme.txt/readme.md、strict mode次第) の
 * 順に絞り込む `apply()` が実質のエントリポイントになる。soft change判定は
 * プラン上の優先順位表には明記されていないが、ユーザーが明示的に設定した
 * ルール(exclude_path/allowlist_hash)を無条件のsystem判定より優先するのが
 * 自然なため、パイプラインの最後(通常findingになる直前)に置く設計にした
 * (ユーザー確認済み).
 */
class WPCV_Suppression_Matcher {

	/** `findings.suppressed_by` に記録するsoft change判定の理由コード. */
	const SOFT_CHANGE_REASON = 'soft_change';

	/**
	 * `allowlist_hash` 判定の対象になり得る finding status.
	 *
	 * `missing`/`unreadable` は `actual_hash` を持たない(比較対象が無い)ため
	 * 対象外にする(§Step8着手前の設計確認で決定).
	 *
	 * @var string[]
	 */
	const ALLOWLIST_ELIGIBLE_STATUSES = array( 'added', 'modified' );

	/**
	 * `readme.txt`/`readme.md` 用のsoft change対象ファイル名(小文字比較).
	 *
	 * @var string[]
	 */
	const SOFT_CHANGE_BASENAMES = array( 'readme.txt', 'readme.md' );

	/**
	 * 1件のfindingに対し、`exclude_path` -> `allowlist_hash` -> soft change の順で
	 * 抑制判定を行う.
	 *
	 * @param array $finding              `WPCV_Verifier::compare_one_file()`/
	 *                                    `make_finding_for_unknown_file()` が返す finding.
	 * @param array $exclude_path_rules   `WPCV_Suppression_Repository::find_active_rules_for_target()`
	 *                                    が返す `exclude_path` ルールの配列.
	 * @param array $allowlist_hash_rules 同 `allowlist_hash` ルールの配列.
	 * @param bool  $strict_mode          `WPCV_Settings::get_strict_mode()` の値.
	 * @return array{suppressed_by: string|null, suppression_id: int|null}
	 */
	public static function apply( array $finding, array $exclude_path_rules, array $allowlist_hash_rules, $strict_mode ) {
		$exclude_path_rule = self::find_matching_exclude_path_rule( $finding, $exclude_path_rules );

		if ( null !== $exclude_path_rule ) {
			return array(
				'suppressed_by'  => null,
				'suppression_id' => (int) $exclude_path_rule['id'],
			);
		}

		$allowlist_hash_rule = self::find_matching_allowlist_hash_rule( $finding, $allowlist_hash_rules );

		if ( null !== $allowlist_hash_rule ) {
			return array(
				'suppressed_by'  => null,
				'suppression_id' => (int) $allowlist_hash_rule['id'],
			);
		}

		if ( ! $strict_mode && self::is_soft_change_path( $finding['path'] ) ) {
			return array(
				'suppressed_by'  => self::SOFT_CHANGE_REASON,
				'suppression_id' => null,
			);
		}

		return array(
			'suppressed_by'  => null,
			'suppression_id' => null,
		);
	}

	/**
	 * `exclude_path` ルールのうち、finding の path に一致する最初の1件を返す.
	 *
	 * @param array $finding 判定対象の finding.
	 * @param array $rules   `exclude_path` ルールの配列.
	 * @return array|null 一致するルールが無ければ null.
	 */
	public static function find_matching_exclude_path_rule( array $finding, array $rules ) {
		foreach ( $rules as $rule ) {
			if ( empty( $rule['pattern'] ) ) {
				continue;
			}

			if ( self::matches_glob( (string) $rule['pattern'], (string) $finding['path'] ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * `allowlist_hash` ルールのうち、finding に一致する最初の1件を返す.
	 *
	 * 一致条件は path(glob)・target version・hash algorithm・expected hash の
	 * すべてが一致すること。version/hash_algorithmの不一致はマッチさせない
	 * (プラン§7「allowlist_hashはtarget versionとhash algorithmを必須にする」。
	 * versionが変われば再承認が必要、というv0.6のversion-change失効を見据えた
	 * 意図的な設計).
	 *
	 * @param array $finding 判定対象の finding.
	 * @param array $rules   `allowlist_hash` ルールの配列.
	 * @return array|null 一致するルールが無ければ null.
	 */
	public static function find_matching_allowlist_hash_rule( array $finding, array $rules ) {
		if ( ! in_array( $finding['status'], self::ALLOWLIST_ELIGIBLE_STATUSES, true ) ) {
			return null;
		}

		if ( empty( $finding['actual_hash'] ) ) {
			return null;
		}

		foreach ( $rules as $rule ) {
			if ( empty( $rule['pattern'] ) || empty( $rule['expected_hash'] ) || empty( $rule['hash_algorithm'] ) || empty( $rule['version'] ) ) {
				continue;
			}

			if ( (string) $rule['version'] !== (string) $finding['version'] ) {
				continue;
			}

			if ( (string) $rule['hash_algorithm'] !== (string) $finding['hash_algorithm'] ) {
				continue;
			}

			if ( (string) $rule['expected_hash'] !== (string) $finding['actual_hash'] ) {
				continue;
			}

			if ( ! self::matches_glob( (string) $rule['pattern'], (string) $finding['path'] ) ) {
				continue;
			}

			return $rule;
		}

		return null;
	}

	/**
	 * `$path` が `readme.txt`/`readme.md` のsoft change対象かどうかを判定する
	 * (ファイル名のみで判定。大文字小文字は区別しない).
	 *
	 * @param string $path ABSPATH相対パス.
	 * @return bool
	 */
	public static function is_soft_change_path( $path ) {
		$basename = strtolower( basename( (string) $path ) );

		return in_array( $basename, self::SOFT_CHANGE_BASENAMES, true );
	}

	/**
	 * `$pattern`(`*`/`**`/`?` のみをワイルドカードとして解釈するglob)が `$path` に
	 * 一致するかどうかを判定する.
	 *
	 * ABSPATH相対・スラッシュ区切りのパス(`WPCV_Path_Normalizer` の方針)を前提に、
	 * `*` はスラッシュを跨がない任意の0文字以上、`**` はスラッシュを跨ぐ任意の
	 * 0文字以上、`?` はスラッシュ以外の任意の1文字として扱う。それ以外の文字は
	 * すべて `preg_quote()` でエスケープするため、`$pattern` に正規表現の特殊文字
	 * (`.`・`(`・`)` 等)を含めても意図しないマッチを起こさない(path traversal
	 * 拒否とは別に、抑制ルール自体が任意の正規表現として振る舞わないようにする
	 * ための安全策).
	 *
	 * @param string $pattern glob パターン.
	 * @param string $path    比較対象のパス.
	 * @return bool
	 */
	public static function matches_glob( $pattern, $path ) {
		if ( '' === $pattern ) {
			return false;
		}

		return 1 === preg_match( self::glob_to_regex( $pattern ), $path );
	}

	/**
	 * Glob パターンを正規表現へ変換する(`matches_glob()` の内部ヘルパー).
	 *
	 * @param string $pattern glob パターン.
	 * @return string `preg_match()` にそのまま渡せる正規表現(デリミタ込み).
	 */
	private static function glob_to_regex( $pattern ) {
		$length = strlen( $pattern );
		$regex  = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $pattern[ $i ];

			if ( '*' === $char ) {
				if ( $i + 1 < $length && '*' === $pattern[ $i + 1 ] ) {
					$regex .= '.*';
					++$i;
				} else {
					$regex .= '[^/]*';
				}
			} elseif ( '?' === $char ) {
				$regex .= '[^/]';
			} else {
				$regex .= preg_quote( $char, '#' );
			}
		}

		return '#^' . $regex . '$#';
	}
}
