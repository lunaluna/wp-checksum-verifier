<?php
/**
 * `WPCV_Retention_Cleaner` の手書きのテストダブル.
 *
 * @package WPChecksumVerifier
 */

/**
 * `prune()` を差し替えた `WPCV_Retention_Cleaner`(`wp wpcv prune` と「今すぐ削除」のテスト用).
 *
 * PHPUnit の `createMock()` は使わない. 開発用の依存(`vendor`)は PHP 8.1 以上向けで入っていて、
 * モックの生成が `enum_exists()`(PHP 8.1 以降)を呼ぶため、PHP 7.4・8.0 でテストを走らせられなく
 * なる(0.10.0 で確認). ほかのテストと同じく手書きのダブルにする(`tests/doubles.php` の方針).
 *
 * `doubles.php` に置かないのは、`WPCV_Retention_Cleaner` を読み込まないテストファイルも
 * `doubles.php` を読むため(親クラスが無いと定義できない). 使うテストは、本体を読み込んでから
 * このファイルを読む.
 */
class WPCV_Test_Stub_Retention_Cleaner extends WPCV_Retention_Cleaner {

	/**
	 * `prune()` の呼び出しの記録(`array( $months, $terminated_run_id, $dry_run )` の並び).
	 *
	 * @var array<int, array{0: int, 1: int, 2: bool}>
	 */
	public $calls = array();

	/**
	 * `prune()` が順に返す結果. `Throwable` なら投げる.
	 *
	 * @var array<int, array|Throwable>
	 */
	private $responses;

	/**
	 * コンストラクタ. 親のコンストラクタ(Repository が要る)は呼ばない.
	 *
	 * @param array<int, array|Throwable> $responses `prune()` が順に返す結果(または投げる例外).
	 */
	public function __construct( array $responses = array() ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
		$this->responses = $responses;
	}

	/**
	 * 呼び出しを記録し、用意した結果を順に返す. 用意が尽きたら、想定外の呼び出しとして例外を投げる.
	 *
	 * @param int  $months            保持期間(月).
	 * @param int  $terminated_run_id 今終わった run の id.
	 * @param bool $dry_run           dry-run か.
	 * @return array
	 *
	 * @throws LogicException 用意した結果が尽きた場合.
	 * @throws Throwable      用意した結果が例外の場合.
	 */
	public function prune( $months, $terminated_run_id = 0, $dry_run = false ) {
		$this->calls[] = array( (int) $months, (int) $terminated_run_id, (bool) $dry_run );

		if ( empty( $this->responses ) ) {
			throw new LogicException( 'WPCV_Test_Stub_Retention_Cleaner: 想定外の prune() の呼び出し.' );
		}

		$response = array_shift( $this->responses );

		if ( $response instanceof Throwable ) {
			throw $response;
		}

		return $response;
	}
}
