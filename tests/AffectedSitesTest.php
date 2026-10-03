<?php
/**
 * WPCV_Affected_Sites のテスト.
 *
 * @package WPChecksumVerifier
 */

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/engine/class-wpcv-target-resolver.php';
require_once dirname( __DIR__ ) . '/includes/class-wpcv-affected-sites.php';

use PHPUnit\Framework\TestCase;

/**
 * マルチサイトの影響サイト表示(v0.9 §Step4. プラン §3.2)のテスト.
 *
 * ネットワークの走査(`collect_snapshot()`)は実環境(alpine-dealer.local)で確認する.
 * ここでは、走査結果(スナップショット)からの判定と、状態ごとの扱いをテストする.
 * 有効化の3通り(ネットワーク有効・main site のみ・サブサイトのみ)× plugin / theme を網羅する.
 */
class AffectedSitesTest extends TestCase {

	/**
	 * 各テストの前にマルチサイトにする.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wpcv_test_is_multisite'] = true;
	}

	/**
	 * 各テストの後にマルチサイトの指定を戻す.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['_wpcv_test_is_multisite'] );
		parent::tearDown();
	}

	/**
	 * 3サイト(main=1・サブサイト 3・4)のスナップショットを作る.
	 *
	 * @param array $overrides 上書きするキー.
	 * @return array
	 */
	private static function snapshot( array $overrides = array() ) {
		return array_merge(
			array(
				'total_sites'      => 3,
				'too_large'        => false,
				'sitewide_plugins' => array(),
				'allowed_themes'   => array(),
				'sites'            => array(
					1 => array(
						'name'       => 'Main',
						'url'        => 'https://example.test/',
						'plugins'    => array(),
						'stylesheet' => 'parent-theme',
						'template'   => 'parent-theme',
					),
					3 => array(
						'name'       => 'Site Three',
						'url'        => 'https://example.test/three/',
						'plugins'    => array(),
						'stylesheet' => 'parent-theme',
						'template'   => 'parent-theme',
					),
					4 => array(
						'name'       => 'Site Four',
						'url'        => 'https://example.test/four/',
						'plugins'    => array(),
						'stylesheet' => 'child-theme',
						'template'   => 'parent-theme',
					),
				),
			),
			$overrides
		);
	}

	/**
	 * Plugin がネットワーク有効なら「ネットワーク全体」で、サイトの一覧は持たない.
	 *
	 * @return void
	 */
	public function test_network_activated_plugin_is_network_wide() {
		$info = WPCV_Affected_Sites::describe_from_snapshot( self::snapshot( array( 'sitewide_plugins' => array( 'acme' => true ) ) ), 'plugin', 'acme' );

		$this->assertSame( WPCV_Affected_Sites::STATE_NETWORK, $info['state'] );
		$this->assertSame( array(), $info['sites'] );
	}

	/**
	 * Main site だけで有効な plugin は、main site だけを返す.
	 *
	 * @return void
	 */
	public function test_plugin_active_on_main_site_only() {
		$snapshot                        = self::snapshot();
		$snapshot['sites'][1]['plugins'] = array( 'acme' => true );

		$info = WPCV_Affected_Sites::describe_from_snapshot( $snapshot, 'plugin', 'acme' );

		$this->assertSame( WPCV_Affected_Sites::STATE_SITES, $info['state'] );
		$this->assertSame( array( 1 ), array_column( $info['sites'], 'blog_id' ) );
		$this->assertSame( 'Main', $info['sites'][0]['name'] );
		$this->assertSame( WPCV_Affected_Sites::RELATION_ACTIVE, $info['sites'][0]['relation'] );
	}

	/**
	 * サブサイトだけで有効な plugin は、そのサブサイトだけを返す(複数なら id 順).
	 *
	 * @return void
	 */
	public function test_plugin_active_on_sub_sites_only() {
		$snapshot                        = self::snapshot();
		$snapshot['sites'][4]['plugins'] = array( 'acme' => true );
		$snapshot['sites'][3]['plugins'] = array( 'acme' => true );

		$info = WPCV_Affected_Sites::describe_from_snapshot( $snapshot, 'plugin', 'acme' );

		$this->assertSame( WPCV_Affected_Sites::STATE_SITES, $info['state'] );
		$this->assertSame( array( 3, 4 ), array_column( $info['sites'], 'blog_id' ) );
	}

	/**
	 * どのサイトでも有効でない plugin は「どこでも無効」.
	 *
	 * @return void
	 */
	public function test_plugin_not_active_anywhere() {
		$info = WPCV_Affected_Sites::describe_from_snapshot( self::snapshot(), 'plugin', 'acme' );

		$this->assertSame( WPCV_Affected_Sites::STATE_NONE, $info['state'] );
		$this->assertSame( array(), $info['sites'] );
		$this->assertFalse( $info['network_enabled'] );
	}

	/**
	 * ネットワーク有効の判定は plugin だけに効く(同じ slug の theme には効かない).
	 *
	 * @return void
	 */
	public function test_sitewide_plugin_does_not_affect_a_theme_with_the_same_slug() {
		$info = WPCV_Affected_Sites::describe_from_snapshot( self::snapshot( array( 'sitewide_plugins' => array( 'parent-theme' => true ) ) ), 'theme', 'parent-theme' );

		$this->assertSame( WPCV_Affected_Sites::STATE_SITES, $info['state'] );
	}

	/**
	 * Theme: stylesheet が一致するサイトは「有効」、子テーマの親(template)として使うサイトは「親」.
	 *
	 * @return void
	 */
	public function test_theme_active_and_parent_relations() {
		$parent = WPCV_Affected_Sites::describe_from_snapshot( self::snapshot(), 'theme', 'parent-theme' );

		$this->assertSame( WPCV_Affected_Sites::STATE_SITES, $parent['state'] );
		$this->assertSame( array( 1, 3, 4 ), array_column( $parent['sites'], 'blog_id' ) );
		$this->assertSame(
			array( 'active', 'active', 'parent' ),
			array_column( $parent['sites'], 'relation' )
		);

		$child = WPCV_Affected_Sites::describe_from_snapshot( self::snapshot(), 'theme', 'child-theme' );

		$this->assertSame( array( 4 ), array_column( $child['sites'], 'blog_id' ) );
		$this->assertSame( WPCV_Affected_Sites::RELATION_ACTIVE, $child['sites'][0]['relation'] );
	}

	/**
	 * Theme: どのサイトでも使っていなくても、ネットワークで許可されていればその旨を返す.
	 *
	 * @return void
	 */
	public function test_unused_theme_reports_network_enabled() {
		$info = WPCV_Affected_Sites::describe_from_snapshot( self::snapshot( array( 'allowed_themes' => array( 'spare-theme' => true ) ) ), 'theme', 'spare-theme' );

		$this->assertSame( WPCV_Affected_Sites::STATE_NONE, $info['state'] );
		$this->assertTrue( $info['network_enabled'] );

		$plain = WPCV_Affected_Sites::describe_from_snapshot( self::snapshot(), 'theme', 'spare-theme' );

		$this->assertFalse( $plain['network_enabled'] );
	}

	/**
	 * サイト数が上限を超えて走査していないスナップショットは、plugin / theme とも「表示しない」.
	 *
	 * @return void
	 */
	public function test_too_large_network_is_unavailable() {
		$snapshot = array(
			'total_sites' => 12000,
			'too_large'   => true,
		);

		foreach ( array( 'plugin', 'theme' ) as $dimension ) {
			$info = WPCV_Affected_Sites::describe_from_snapshot( $snapshot, $dimension, 'acme' );

			$this->assertSame( WPCV_Affected_Sites::STATE_UNAVAILABLE, $info['state'] );
			$this->assertSame( 12000, $info['total_sites'] );
		}
	}

	/**
	 * 単一サイト・plugin / theme 以外の dimension は対象外で、スナップショットを読まない.
	 *
	 * @return void
	 */
	public function test_not_applicable_does_not_read_the_snapshot() {
		$calls  = 0;
		$reader = static function () use ( &$calls ) {
			++$calls;

			return array();
		};

		$GLOBALS['_wpcv_test_is_multisite'] = false;
		$single                             = new WPCV_Affected_Sites( $reader );

		$this->assertSame( WPCV_Affected_Sites::STATE_NOT_APPLICABLE, $single->describe( 'plugin', 'acme' )['state'] );

		$GLOBALS['_wpcv_test_is_multisite'] = true;
		$multi                              = new WPCV_Affected_Sites( $reader );

		foreach ( array( 'core', 'muplugin', 'dropin' ) as $dimension ) {
			$this->assertSame( WPCV_Affected_Sites::STATE_NOT_APPLICABLE, $multi->describe( $dimension, 'x' )['state'] );
		}

		$this->assertSame( 0, $calls );
	}

	/**
	 * スナップショットは1つのインスタンスの中で1回だけ読む(1リクエスト1回の走査).
	 *
	 * @return void
	 */
	public function test_snapshot_is_read_once_per_instance() {
		$calls  = 0;
		$reader = static function () use ( &$calls ) {
			++$calls;

			return self::snapshot( array( 'sitewide_plugins' => array( 'acme' => true ) ) );
		};

		$sites = new WPCV_Affected_Sites( $reader );

		$this->assertSame( WPCV_Affected_Sites::STATE_NETWORK, $sites->describe( 'plugin', 'acme' )['state'] );
		$this->assertSame( WPCV_Affected_Sites::STATE_NONE, $sites->describe( 'plugin', 'other' )['state'] );
		$sites->describe( 'theme', 'parent-theme' );

		$this->assertSame( 1, $calls );
	}
}
