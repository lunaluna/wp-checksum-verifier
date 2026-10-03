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

	/**
	 * 照合元(GitHub)の認証・権限の失敗: トークンが無効・失効(401)、または権限が足りない・
	 * 失敗が続いて一時的に拒否された(レート制限の印の無い 403)(v0.9 §Step7. プラン §3.5).
	 *
	 * トークンの失効は恒常的な状態で、一時的な通信障害(`http_error`)とは意味が違う. このコードの
	 * target は、連続 unverifiable の数えない側にし、代わりに stat 差分検知で監視する.
	 * 非公開のリポジトリにトークンが無い・権限が無い場合は、GitHub が存在しないリポジトリと区別しない
	 * 404 を返す(公式ドキュメント)ので、このコードにはならず `manifest_not_found` になる.
	 */
	const SOURCE_ACCESS_DENIED = 'source_access_denied';

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
	 * `exclude_target` 抑制ルールに一致し、検証自体を行わずスキップした(v0.4.0 §Step8).
	 *
	 * ユーザーが明示的にこの target を検証対象外にした恒久的なスキップであることを示す.
	 */
	const EXCLUDED = 'excluded';

	/**
	 * 本体 target がチェックサム照合できたため、stat 差分検知 target
	 * (`{dimension}:{slug}:_stat`)の走査を省略した(v0.5 §Step6. rev.3 §3.4).
	 *
	 * 照合できたファイルは内容の正しさまで確認済みであり、stat 走査を重ねても
	 * 得るものが無い。stat の I/O を一切発生させないためのスキップ.
	 */
	const CHECKSUM_COVERED = 'checksum_covered';

	/**
	 * 本体の version が変わったため、stat 差分検知のベースラインを捨てて作り直した
	 * (v0.5 §Step7. rev.3 §3.7-b).
	 *
	 * 作り直した run では前回との比較をしていない(=その日の変更は検証していない)。
	 * 攻撃者が version 文字列を書き換えるだけで検知を無効化できてしまうため、
	 * この事実を target_run に残して管理画面から見えるようにする.
	 */
	const BASELINE_REBUILT = 'baseline_rebuilt';

	/**
	 * `diff_mode = version_changed` になったが、WordPress の更新機構(`wpcv_update_events`.
	 * v0.6プラン §2.1)を通った記録が見つからなかった(v0.6プラン §3.1・D5).
	 *
	 * `git` / FTP / composer 等の手動デプロイでは、正当な更新でも記録が残らない
	 * (プラン U3参照。既定では通知するが、手動デプロイのサイトは設定でOFFにできる)。
	 * `VERSION_CHANGED`(検証中に version が変わった. retry対象)とは意味が異なる
	 * ―― こちらは差分処理の段階(2つの run の間)で version が変わっていたことを表す.
	 */
	const VERSION_CHANGED_UNRECORDED = 'version_changed_unrecorded';

	/**
	 * 全 error_code とその説明の一覧を返す(管理画面表示・バリデーション用).
	 *
	 * @return array<string, string> error_code => 説明.
	 */
	public static function all() {
		return array(
			self::MANIFEST_NOT_FOUND         => __( 'Manifest not found (including not yet published)', 'wp-checksum-verifier' ),
			self::HTTP_ERROR                 => __( 'HTTP error while fetching', 'wp-checksum-verifier' ),
			self::RATE_LIMITED               => __( 'GitHub rate limit reached', 'wp-checksum-verifier' ),
			self::SOURCE_ACCESS_DENIED       => __( 'Cannot access the source (the GitHub token is invalid, expired or lacks permission)', 'wp-checksum-verifier' ),
			self::ZIPARCHIVE_MISSING         => __( 'ZipArchive extension is not available', 'wp-checksum-verifier' ),
			self::PACKAGE_NOT_FOUND          => __( 'Distribution package not found (e.g. child theme)', 'wp-checksum-verifier' ),
			self::NO_RELEASE_ASSET           => __( 'GitHub release has no asset', 'wp-checksum-verifier' ),
			self::ASSET_AMBIGUOUS            => __( 'Could not uniquely determine the asset', 'wp-checksum-verifier' ),
			self::UNKNOWN_SOURCE             => __( 'Could not identify a verification source', 'wp-checksum-verifier' ),
			self::VERSION_UNKNOWN            => __( 'Could not determine the local version', 'wp-checksum-verifier' ),
			self::ARCHIVE_INVALID            => __( 'Archive is corrupt or failed verification', 'wp-checksum-verifier' ),
			self::ARCHIVE_REJECTED           => __( 'Archive rejected (zip slip / zip bomb check)', 'wp-checksum-verifier' ),
			self::DISK_FULL                  => __( 'Insufficient disk space for extraction', 'wp-checksum-verifier' ),
			self::VERSION_CHANGED            => __( 'Version changed during verification (will retry)', 'wp-checksum-verifier' ),
			self::TIMEOUT                    => __( 'Time budget exhausted (will resume)', 'wp-checksum-verifier' ),
			self::TARGET_MISSING             => __( 'Target no longer found locally', 'wp-checksum-verifier' ),
			self::LEASE_EXPIRED              => __( 'Worker lease expired too many times', 'wp-checksum-verifier' ),
			self::EXCLUDED                   => __( 'Excluded by an exclude_target suppression rule', 'wp-checksum-verifier' ),
			self::CHECKSUM_COVERED           => __( 'Skipped: already verified by checksums', 'wp-checksum-verifier' ),
			self::BASELINE_REBUILT           => __( 'Stat baseline rebuilt after a version change (changes in this run were not compared)', 'wp-checksum-verifier' ),
			self::VERSION_CHANGED_UNRECORDED => __( 'Version changed without a record of a WordPress update', 'wp-checksum-verifier' ),
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
