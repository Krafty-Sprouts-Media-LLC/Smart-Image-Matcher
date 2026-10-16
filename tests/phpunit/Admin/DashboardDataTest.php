<?php
/**
 * Dashboard error grouping.
 *
 * @package SmartImageMatcher\Tests\Admin
 * @since   3.7.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Admin\DashboardData;

/**
 * Class DashboardDataTest
 *
 * @since 3.7.0
 */
class DashboardDataTest extends TestCase {

	/** @test */
	public function errors_are_grouped_by_message_with_counts_posts_and_age(): void {
		$now    = (int) strtotime( '2026-10-16 12:00:00' );
		$errors = array(
			array( 'at' => '2026-10-16 11:00:00', 'message' => 'Queue: action failed', 'context' => array( 'post_id' => '7', 'reason' => 'Timed out' ) ),
			array( 'at' => '2026-10-16 10:00:00', 'message' => 'Queue: action failed', 'context' => array( 'post_id' => '8', 'reason' => 'Timed out' ) ),
			array( 'at' => '2026-10-01 09:00:00', 'message' => 'Insert crashed: Class "SmartImageMatcher\\Cache\\Cache" not found', 'context' => array() ),
		);

		$groups = DashboardData::groupErrors( $errors, $now );

		$this->assertCount( 2, $groups );
		$this->assertSame( 'Queue: action failed', $groups[0]['message'] );
		$this->assertSame( 2, $groups[0]['count'] );
		$this->assertSame( array( 7, 8 ), $groups[0]['posts'] );
		$this->assertFalse( $groups[0]['old'] );
		$this->assertSame( (int) strtotime( '2026-10-16 10:00:00' ), $groups[0]['first'] );
		$this->assertTrue( $groups[1]['old'], 'Older than three days.' );
	}
}
