<?php
/**
 * Unit tests for HeadingLocator.
 *
 * @package SmartImageMatcher\Tests\Insertion
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Insertion;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Insertion\HeadingLocator;

/**
 * Class HeadingLocatorTest
 *
 * @since 3.0.0
 */
class HeadingLocatorTest extends TestCase {

	/** @test */
	public function hash_is_deterministic(): void {
		$hash1 = HeadingLocator::computeHash( 2, 'black swallowtail', 0 );
		$hash2 = HeadingLocator::computeHash( 2, 'black swallowtail', 0 );

		$this->assertEquals( $hash1, $hash2, 'Same inputs must produce the same hash' );
	}

	/** @test */
	public function repeated_headings_get_distinct_hashes(): void {
		$hash0 = HeadingLocator::computeHash( 2, 'introduction', 0 );
		$hash1 = HeadingLocator::computeHash( 2, 'introduction', 1 );

		$this->assertNotEquals( $hash0, $hash1, 'Repeated headings must have distinct hashes' );
	}

	/** @test */
	public function different_levels_get_distinct_hashes(): void {
		$hashH2 = HeadingLocator::computeHash( 2, 'overview', 0 );
		$hashH3 = HeadingLocator::computeHash( 3, 'overview', 0 );

		$this->assertNotEquals( $hashH2, $hashH3 );
	}

	/** @test */
	public function hash_is_40_chars(): void {
		$hash = HeadingLocator::computeHash( 2, 'test heading', 0 );

		$this->assertEquals( 40, strlen( $hash ), 'sha1 must produce a 40-char hex string' );
	}
}
