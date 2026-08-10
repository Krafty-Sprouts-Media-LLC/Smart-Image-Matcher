<?php
/**
 * Unit tests for the Premium feature gate.
 *
 * @package SmartImageMatcher\Tests
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Premium;

/**
 * Class PremiumTest
 *
 * @since 3.0.0
 */
class PremiumTest extends TestCase {

	/** @test */
	public function registered_feature_defaults_to_true(): void {
		Premium::registerFeature( 'test_feature', array( 'default' => true ) );

		$this->assertTrue( Premium::has( 'test_feature' ) );
	}

	/** @test */
	public function wp_org_build_keeps_unregistered_features_available(): void {
		$this->assertTrue( Premium::has( 'nonexistent_feature_xyz' ) );
	}

	/** @test */
	public function enable_keeps_a_feature_available(): void {
		Premium::registerFeature( 'my_feature', array( 'default' => false ) );
		$this->assertTrue( Premium::has( 'my_feature' ) );

		Premium::enable( 'my_feature' );
		$this->assertTrue( Premium::has( 'my_feature' ) );
	}
}
