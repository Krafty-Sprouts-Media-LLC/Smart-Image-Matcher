<?php
/**
 * Unit tests for MatchDecision.
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.2.31
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\MatchDecision;

/**
 * Class MatchDecisionTest
 *
 * @since 3.2.31
 */
class MatchDecisionTest extends TestCase {

	/**
	 * Scores at or above auto-insert insert even when generation is available.
	 *
	 * @return void
	 */
	public function test_score_at_or_above_auto_insert_inserts(): void {
		$this->assertSame( 'insert', MatchDecision::decide( 100, 90, 70, false ) );
		$this->assertSame( 'insert', MatchDecision::decide( 90, 90, 70, true ) );
	}

	/**
	 * The middle band stays in review and must not spend generation credits.
	 *
	 * @return void
	 */
	public function test_middle_band_reviews_even_if_generation_on(): void {
		$this->assertSame( 'review', MatchDecision::decide( 80, 90, 70, true ) );
	}

	/**
	 * Below the review floor with generation off is a skip.
	 *
	 * @return void
	 */
	public function test_below_review_without_generation_skips(): void {
		$this->assertSame( 'skip', MatchDecision::decide( 40, 90, 70, false ) );
		$this->assertSame( 'skip', MatchDecision::decide( 0, 90, 70, false ) );
	}

	/**
	 * Below the review floor with generation on queues generate.
	 *
	 * @return void
	 */
	public function test_below_review_with_generation_generates(): void {
		$this->assertSame( 'generate', MatchDecision::decide( 40, 90, 70, true ) );
	}
}
