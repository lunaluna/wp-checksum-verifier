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
	 * 妥当な dimension 値の一覧.
	 *
	 * @var string[]
	 */
	const DIMENSIONS = array(
		self::DIMENSION_CORE,
		self::DIMENSION_PLUGIN,
		self::DIMENSION_THEME,
		self::DIMENSION_MUPLUGIN,
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
