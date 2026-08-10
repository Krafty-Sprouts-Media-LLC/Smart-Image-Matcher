<?php
/**
 * Unit tests for Matcher scoring.
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\Matcher;

/**
 * Class MatcherTest
 *
 * @since 3.0.0
 */
class MatcherTest extends TestCase {

	private Matcher $matcher;

	protected function setUp(): void {
		$this->matcher = new Matcher();
	}

	/**
	 * Helper — build a minimal image row.
	 *
	 * @param string $filename
	 * @param string $title
	 * @param string $alt
	 * @return array<string, mixed>
	 */
	private function image( string $filename, string $title = '', string $alt = '' ): array {
		return array(
			'id'       => 1,
			'filename' => $filename,
			'title'    => $title,
			'alt'      => $alt,
			'url'      => 'https://example.com/' . $filename,
		);
	}

	/** @test */
	public function exact_filename_match_scores_100(): void {
		$score = $this->matcher->calculateScore(
			array( 'black', 'swallowtail' ),
			$this->image( 'black-swallowtail.jpg' )
		);
		$this->assertEquals( 100, $score );
	}

	/** @test */
	public function unrelated_image_scores_zero(): void {
		$score = $this->matcher->calculateScore(
			array( 'monarch', 'butterfly' ),
			$this->image( 'sunset-beach.jpg' )
		);
		$this->assertEquals( 0, $score );
	}

	/** @test */
	public function high_score_with_intentional_title(): void {
		// Title is different from filename → intentional bonus.
		$score = $this->matcher->calculateScore(
			array( 'kentucky', 'warbler' ),
			$this->image( 'kentucky-warbler.jpg', 'Kentucky Warbler' )
		);
		$this->assertGreaterThanOrEqual( 90, $score );
	}

	/** @test */
	public function alt_text_only_match_still_scores(): void {
		$score = $this->matcher->calculateScore(
			array( 'sunset', 'beach' ),
			$this->image( 'img-001.jpg', '', 'sunset at the beach' )
		);
		$this->assertGreaterThan( 0, $score );
	}

	/** @test */
	public function keyword_overlap_calculation(): void {
		$overlap = $this->matcher->calculateKeywordOverlap(
			array( 'black', 'spider' ),
			array( 'black', 'widow' )
		);
		// intersection={black} / union={black,spider,widow} = 1/3 ≈ 33.33
		$this->assertEqualsWithDelta( 33.33, $overlap, 0.5 );
	}

	/** @test */
	public function empty_keywords_returns_zero(): void {
		$score = $this->matcher->calculateScore( array(), $this->image( 'photo.jpg' ) );
		$this->assertEquals( 0, $score );
	}
}
