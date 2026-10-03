<?php
/**
 * 検出結果の絞り込みの allowlist が、管理画面と REST で一致することのテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-generation-differ.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-capability.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-run-history.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-wpcv-page-findings.php';
require_once dirname( __DIR__ ) . '/includes/rest/class-wpcv-rest-findings-controller.php';

use PHPUnit\Framework\TestCase;

/**
 * `WPCV_Page_Findings` と `WPCV_Rest_Findings_Controller` は、読み込み順序への依存を避けるために
 * `VALID_STATUSES`・`VALID_SEVERITIES`・`VALID_DIFF_STATES` をあえて複製している(v0.9.1 §Step3).
 * 値を足すときに片方だけ更新すると、管理画面と REST で絞り込める値がずれる. このテストが、
 * 片方だけの更新を検知する.
 */
class FindingsAllowlistParityTest extends TestCase {

	/**
	 * Status の allowlist が一致する.
	 *
	 * @return void
	 */
	public function test_statuses_match() {
		$this->assertEqualsCanonicalizing( WPCV_Rest_Findings_Controller::VALID_STATUSES, WPCV_Page_Findings::VALID_STATUSES );
	}

	/**
	 * Severity の allowlist が一致する.
	 *
	 * @return void
	 */
	public function test_severities_match() {
		$this->assertEqualsCanonicalizing( WPCV_Rest_Findings_Controller::VALID_SEVERITIES, WPCV_Page_Findings::VALID_SEVERITIES );
	}

	/**
	 * Diff state の allowlist が一致し、差分検出が実際に使う3つの値と同じである.
	 *
	 * @return void
	 */
	public function test_diff_states_match_each_other_and_the_differ() {
		$differ = array(
			WPCV_Generation_Differ::DIFF_STATE_NEW,
			WPCV_Generation_Differ::DIFF_STATE_CONTINUING,
			WPCV_Generation_Differ::DIFF_STATE_EVENT,
		);

		$this->assertEqualsCanonicalizing( WPCV_Rest_Findings_Controller::VALID_DIFF_STATES, WPCV_Page_Findings::VALID_DIFF_STATES );
		$this->assertEqualsCanonicalizing( $differ, WPCV_Page_Findings::VALID_DIFF_STATES );
	}
}
