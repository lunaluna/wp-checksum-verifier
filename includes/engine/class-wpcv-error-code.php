<?php
/**
 * WPCV_Error_Code クラスファイル.
 *
 * @package WPChecksumVerifier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * §5.4 で列挙した error_code の定数と一覧.
 *
 * `target_runs.status`(unverifiable / failed / skipped / retried)の理由は
 * 運用上まったく意味が異なるため、必ずこの一覧のいずれかでコード化する
 * (「異常なし」と「確認できていない」を区別できない状態を避けるため。§8.6)。
 */
class WPCV_Error_Code {

	/** マニフェストが存在しない(未発行含む). */
	const MANIFEST_NOT_FOUND = 'manifest_not_found';

	/** 取得時の HTTP エラー. */
	const HTTP_ERROR = 'http_error';

	/** GitHub レート制限. */
	const RATE_LIMITED = 'rate_limited';

	/** ZipArchive 拡張が無い. */
	const ZIPARCHIVE_MISSING = 'ziparchive_missing';

	/** 配布パッケージが存在しない(子テーマ等). */
	const PACKAGE_NOT_FOUND = 'package_not_found';

	/** GitHub リリースにアセットが無い. */
	const NO_RELEASE_ASSET = 'no_release_asset';

	/** アセットを一意に決定できない. */
	const ASSET_AMBIGUOUS = 'asset_ambiguous';

	/** 照合ソースが特定できない. */
	const UNKNOWN_SOURCE = 'unknown_source';

	/** ローカルのバージョンが取得できない. */
	const VERSION_UNKNOWN = 'version_unknown';

	/** アーカイブが壊れている / 検証に失敗. */
	const ARCHIVE_INVALID = 'archive_invalid';

	/** 不正なアーカイブ(zip slip / zip bomb)と判定して拒否. */
	const ARCHIVE_REJECTED = 'archive_rejected';

	/** 一時展開先の容量不足. */
	const DISK_FULL = 'disk_full';

	/** 検証中に更新が走った(retry 対象). */
	const VERSION_CHANGED = 'version_changed';

	/** 更新処理中でスキップ. */
	const LOCKED = 'locked';

	/** 時間予算切れ(resume 対象). */
	const TIMEOUT = 'timeout';

	/**
	 * Plan時点では存在した対象(プラグイン等)が、後続chunkの実行時点で見つからない
	 * (v0.4.0 §Step4)。
	 *
	 * 分割実行では `WPCV_Run_Planner::plan()` が列挙してから実際にdispatcherが
	 * その target を claim するまでに時間差があり得るため、一括実行(§16-D以前)には
	 * 存在しなかった状態。「削除された」「slugが変わった」のいずれも区別せず
	 * この1つに倒す(claim時点でローカルに見つからない、という事実のみを記録する).
	 */
	const TARGET_MISSING = 'target_missing';

	/**
	 * Lease有効期限切れ(worker のクラッシュ・強制終了・タイムアウトの疑い)の
	 * 検知が最大試行回数を超えた(v0.4.0 §Step4。
	 * `WPCV_Target_Run_Repository::sweep_expired_leases()` 参照).
	 *
	 * `TIMEOUT`(chunkが時間予算に達して正常にyieldした場合)とは意味が異なる
	 * ―― こちらは「yieldすら記録されないまま lease が切れた」= 途中経過が
	 * 一切確定していない異常系であり、`WPCV_Target_Status::FAILED`(終端)へ倒す.
	 */
	const LEASE_EXPIRED = 'lease_expired';

	/**
	 * 全 error_code とその説明の一覧を返す(管理画面表示・バリデーション用).
	 *
	 * @return array<string, string> error_code => 説明.
	 */
	public static function all() {
		return array(
			self::MANIFEST_NOT_FOUND => __( 'Manifest not found (including not yet published)', 'wp-checksum-verifier' ),
			self::HTTP_ERROR         => __( 'HTTP error while fetching', 'wp-checksum-verifier' ),
			self::RATE_LIMITED       => __( 'GitHub rate limit reached', 'wp-checksum-verifier' ),
			self::ZIPARCHIVE_MISSING => __( 'ZipArchive extension is not available', 'wp-checksum-verifier' ),
			self::PACKAGE_NOT_FOUND  => __( 'Distribution package not found (e.g. child theme)', 'wp-checksum-verifier' ),
			self::NO_RELEASE_ASSET   => __( 'GitHub release has no asset', 'wp-checksum-verifier' ),
			self::ASSET_AMBIGUOUS    => __( 'Could not uniquely determine the asset', 'wp-checksum-verifier' ),
			self::UNKNOWN_SOURCE     => __( 'Could not identify a verification source', 'wp-checksum-verifier' ),
			self::VERSION_UNKNOWN    => __( 'Could not determine the local version', 'wp-checksum-verifier' ),
			self::ARCHIVE_INVALID    => __( 'Archive is corrupt or failed verification', 'wp-checksum-verifier' ),
			self::ARCHIVE_REJECTED   => __( 'Archive rejected (zip slip / zip bomb check)', 'wp-checksum-verifier' ),
			self::DISK_FULL          => __( 'Insufficient disk space for extraction', 'wp-checksum-verifier' ),
			self::VERSION_CHANGED    => __( 'Version changed during verification (will retry)', 'wp-checksum-verifier' ),
			self::LOCKED             => __( 'Skipped: an update is in progress', 'wp-checksum-verifier' ),
			self::TIMEOUT            => __( 'Time budget exhausted (will resume)', 'wp-checksum-verifier' ),
			self::TARGET_MISSING     => __( 'Target no longer found locally', 'wp-checksum-verifier' ),
			self::LEASE_EXPIRED      => __( 'Worker lease expired too many times', 'wp-checksum-verifier' ),
		);
	}

	/**
	 * 指定した文字列が既知の error_code かどうかを判定する.
	 *
	 * @param string $error_code 判定対象.
	 * @return bool
	 */
	public static function is_valid( $error_code ) {
		return array_key_exists( $error_code, self::all() );
	}
}
