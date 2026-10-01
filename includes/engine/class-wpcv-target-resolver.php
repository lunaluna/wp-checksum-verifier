<?php
/**
 * WPCV_Target_Resolver クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 検証対象(target)のモデル: dimension の定数と target_id の生成・分解.
 *
 * 現時点(v0.1)では target_id の生成・分解のみを扱う。実際にインストール済みの
 * プラグイン・テーマ・MU プラグインを列挙して target のリストを作る処理
 * (get_plugins() 等を使った実列挙)は、各照合ソースの実装と合わせて後続の
 * ステップで本クラスに追加する.
 */
class WPCV_Target_Resolver {

	/** WordPress コア. */
	const DIMENSION_CORE = 'core';

	/** 通常のプラグイン. */
	const DIMENSION_PLUGIN = 'plugin';

	/** テーマ. */
	const DIMENSION_THEME = 'theme';

	/** MU プラグイン. */
	const DIMENSION_MUPLUGIN = 'muplugin';

	/**
	 * ドロップイン(v0.6 §Step9. §5.3 L1・L3)。`dropin:_stat` 1つの合成target
	 * 専用で、本体targetはこのdimensionには存在しない.
	 */
	const DIMENSION_DROPIN = 'dropin';

	/**
	 * 妥当な dimension 値の一覧.
	 *
	 * @var string[]
	 */
	const DIMENSIONS = array(
		self::DIMENSION_CORE,
		self::DIMENSION_PLUGIN,
		self::DIMENSION_THEME,
		self::DIMENSION_MUPLUGIN,
		self::DIMENSION_DROPIN,
	);

	/**
	 * 検証対象の target_id を生成する(§5.3: `core` / `plugin:{slug}` / `theme:{slug}` / `muplugin:{file}`).
	 *
	 * Core は identifier省略時のみ素の `core` を返す。v0.4.0 §Step4で、core次元の
	 * 中に「manifest比較(素の `core`)」と「未知ファイル走査(合成target)」という
	 * 性質の異なる2つのtargetを持つ必要が生じた(1 target_run = 1直列cursorという
	 * chunk分割実行の前提上、同じtarget_runに同居できないため)。muplugin次元に
	 * 既にある合成target `muplugin:_scan` と対称的に `core:_scan` を作れるよう、
	 * core でも identifier を指定できるようにする(identifier省略時の挙動は
	 * 変更していないため、既存の呼び出し元 (`WPCV_Verifier`/`WPCV_Run_Planner` が
	 * `WPCV_Target_Resolver::DIMENSION_CORE` 定数を直接使う箇所) には影響しない).
	 *
	 * @param string $dimension  self::DIMENSIONS のいずれか.
	 * @param string $identifier core は省略可(省略時は素の `core`). core 以外は必須.
	 *                            plugin/theme は slug、muplugin は WPMU_PLUGIN_DIR
	 *                            からの相対ファイルパス.
	 * @return string target_id.
	 *
	 * @throws InvalidArgumentException 指定した dimension が不正、または
	 *                                   identifier が core 以外で空の場合.
	 */
	public static function build_id( $dimension, $identifier = '' ) {
		if ( ! in_array( $dimension, self::DIMENSIONS, true ) ) {
			throw new InvalidArgumentException( esc_html( "Unknown dimension: {$dimension}" ) );
		}

		if ( self::DIMENSION_CORE === $dimension ) {
			return '' === $identifier ? self::DIMENSION_CORE : self::DIMENSION_CORE . ":{$identifier}";
		}

		if ( '' === $identifier ) {
			throw new InvalidArgumentException( esc_html( "identifier is required for dimension: {$dimension}" ) );
		}

		return "{$dimension}:{$identifier}";
	}

	/**
	 * Stat差分検知 target の target_id に付ける接尾辞(v0.5 §Step6. rev.3 §3.4).
	 *
	 * @var string
	 */
	const STAT_SUFFIX = ':_stat';

	/**
	 * 本体 target の target_id から、対応する stat 差分検知 target の target_id
	 * (`{dimension}:{slug}:_stat`)を作る(v0.5 §Step6).
	 *
	 * Stat target は本体と同じ dimension/slug を持つ(target_runs・findings の
	 * dimension/slug 列も本体と同じ値にする)。そうすることで、本体に対する
	 * `exclude_target`/`exclude_path` 抑制ルールがそのまま stat target にも効く.
	 * 本体と stat target は target_id の接尾辞だけで区別する.
	 *
	 * @param string $body_target_id 本体 target の target_id(`plugin:{slug}` 等).
	 * @return string
	 */
	public static function build_stat_id( $body_target_id ) {
		return $body_target_id . self::STAT_SUFFIX;
	}

	/**
	 * Stat差分検知 target の target_id かどうかを判定する(v0.5 §Step6).
	 *
	 * @param string $target_id 判定対象.
	 * @return bool
	 */
	public static function is_stat_id( $target_id ) {
		$suffix_length = strlen( self::STAT_SUFFIX );

		return strlen( $target_id ) > $suffix_length && self::STAT_SUFFIX === substr( $target_id, -$suffix_length );
	}

	/**
	 * `wpcv_file_states`(stat差分検知の層1/層2ベースライン)を使うtargetかどうかを
	 * 判定する(v0.6 §Step12是正).
	 *
	 * 通常の本体target(`is_stat_id()`が真の `{dimension}:{slug}:_stat`)に加えて、
	 * `core:_config`/`dropin:_stat`(v0.6 §Step9。本体targetを持たない合成target)も
	 * `wpcv_file_states`を使う。この2つは`:_stat`接尾辞の規則に従わない
	 * (`core:_config`はslugが`_config`で終わる)ため、`is_stat_id()`だけでは
	 * 判定できない.
	 *
	 * `WPCV_Diff_Dispatcher::cleanup_after_all_targets_processed()`(今回列挙された
	 * targetの一覧を`is_stat_id()`だけで組み立て、それ以外の`wpcv_file_states`行を
	 * 削除する処理)がv0.6 §Step9で`core:_config`を考慮し忘れていたため、
	 * `core:_config`が実際には毎run列挙されているのに「列挙されなかったtarget」
	 * として扱われ、ベースラインが毎runで削除される不具合があった
	 * (v0.6 §Step12の実地検証〔test-armfu.local〕で発見。`dropin:_stat`は
	 * `:_stat`接尾辞を持つため偶然この不具合を免れていた).
	 * 判定ロジック自体は`WPCV_Target_Resolver`(target_id/dimension/slugの
	 * 意味を扱う本クラス)に置くのが自然なため、ここに実装する.
	 *
	 * @param string $target_id target_id.
	 * @param string $dimension dimension.
	 * @param string $slug      slug.
	 * @return bool
	 */
	public static function uses_file_state_storage( $target_id, $dimension, $slug ) {
		if ( self::is_stat_id( $target_id ) ) {
			return true;
		}

		if ( self::DIMENSION_CORE === $dimension && '_config' === $slug ) {
			return true;
		}

		return self::DIMENSION_DROPIN === $dimension;
	}

	/**
	 * Stat差分検知 target の target_id から、本体 target の target_id を取り出す(v0.5 §Step6).
	 *
	 * @param string $stat_target_id `build_stat_id()` が作った target_id.
	 * @return string
	 *
	 * @throws InvalidArgumentException Stat target の target_id でない場合.
	 */
	public static function body_id_of_stat( $stat_target_id ) {
		if ( ! self::is_stat_id( $stat_target_id ) ) {
			throw new InvalidArgumentException( esc_html( "Not a stat target_id: {$stat_target_id}" ) );
		}

		return substr( $stat_target_id, 0, -strlen( self::STAT_SUFFIX ) );
	}

	/**
	 * 本体ごとの未知ファイル走査 target の target_id に付ける接尾辞(v0.7 §Step5. D8).
	 *
	 * @var string
	 */
	const SCAN_SUFFIX = ':_scan';

	/**
	 * 本体 target の target_id から、その本体の未知ファイル走査 target の target_id
	 * (`{dimension}:{slug}:_scan`)を作る(v0.7 §Step5. テーマ専用).
	 *
	 * `core:_scan`・`muplugin:_scan` は領域全体の走査で、slug が `_scan` の合成 target
	 * (本体を持たない)。こちらは本体と同じ dimension/slug を持ち(抑制ルールを共有する
	 * ため. `build_stat_id()` と同じ考え方)、target_id の接尾辞だけで区別する.
	 *
	 * @param string $body_target_id 本体 target の target_id(`theme:{stylesheet}`).
	 * @return string
	 */
	public static function build_scan_id( $body_target_id ) {
		return $body_target_id . self::SCAN_SUFFIX;
	}

	/**
	 * 本体ごとの未知ファイル走査 target の target_id から、本体の target_id を取り出す
	 * (v0.7 §Step5).
	 *
	 * `core:_scan`・`muplugin:_scan`(領域全体の走査. 本体を持たない)は対象外で、
	 * null を返す.
	 *
	 * @param string $target_id 判定対象.
	 * @return string|null 本体の target_id. 本体ごとの走査 target でなければ null.
	 */
	public static function body_id_of_scan( $target_id ) {
		$suffix_length = strlen( self::SCAN_SUFFIX );

		if ( strlen( $target_id ) <= $suffix_length || self::SCAN_SUFFIX !== substr( $target_id, -$suffix_length ) ) {
			return null;
		}

		$body_id = substr( $target_id, 0, -$suffix_length );

		// `core:_scan` → `core`、`muplugin:_scan` → `muplugin` は本体の target_id ではない
		// (`muplugin` には区切りの `:` が無く、`core` は素のコアの照合 target).
		if ( false === strpos( $body_id, ':' ) ) {
			return null;
		}

		return $body_id;
	}

	/**
	 * 検証対象の target_id を dimension と identifier に分解する.
	 *
	 * @param string $target_id build_id() が生成した形式の文字列.
	 * @return array{dimension: string, identifier: string}
	 *
	 * @throws InvalidArgumentException 既知の dimension で始まらない場合.
	 */
	public static function parse_id( $target_id ) {
		if ( self::DIMENSION_CORE === $target_id ) {
			return array(
				'dimension'  => self::DIMENSION_CORE,
				'identifier' => '',
			);
		}

		// slug・ファイルパス自体にコロンが含まれ得るため、先頭の1つだけで区切る.
		$parts = explode( ':', $target_id, 2 );

		if ( 2 !== count( $parts ) || ! in_array( $parts[0], self::DIMENSIONS, true ) ) {
			throw new InvalidArgumentException( esc_html( "Malformed target_id: {$target_id}" ) );
		}

		return array(
			'dimension'  => $parts[0],
			'identifier' => $parts[1],
		);
	}
}
