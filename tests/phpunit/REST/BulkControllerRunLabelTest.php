<?php
/**
 * Unit tests for operator-facing run labels (never include job hashes).
 *
 * @package SmartImageMatcher\Tests\REST
 * @since   3.3.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\REST;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\REST\BulkController;

/**
 * Class BulkControllerRunLabelTest
 *
 * @since 3.3.0
 */
class BulkControllerRunLabelTest extends TestCase {

	/**
	 * Same-day runs use “Today HH:MM”.
	 *
	 * @return void
	 */
	public function test_same_day_label_uses_today(): void {
		$label = BulkController::formatRunWhen( '2000-01-01 09:14:00' );
		$this->assertSame( 'Today 09:14', $label );
	}

	/**
	 * Combined label includes article counts, not a job id.
	 *
	 * @return void
	 */
	public function test_run_label_has_counts_not_hash(): void {
		$label = BulkController::formatRunLabel(
			array(
				'created_at' => '2000-01-01 09:14:00',
				'total'      => 40,
				'inserted'   => 28,
				'review'     => 7,
				'job_id'     => 'smart_image_matcher_a1b2c3d4e5f6',
			)
		);

		$this->assertStringContainsString( 'Today 09:14', $label );
		$this->assertStringContainsString( '40 articles', $label );
		$this->assertStringContainsString( 'Inserted 28', $label );
		$this->assertStringContainsString( 'Review 7', $label );
		$this->assertStringNotContainsString( 'smart_image_matcher_', $label );
		$this->assertStringNotContainsString( 'a1b2c3d4e5f6', $label );
	}
}
