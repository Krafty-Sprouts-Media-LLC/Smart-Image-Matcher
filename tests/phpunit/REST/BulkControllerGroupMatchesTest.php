<?php
/**
 * Unit tests for BulkController::groupMatchesByPost().
 *
 * @package SmartImageMatcher\Tests\REST
 * @since   3.3.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\REST;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\REST\BulkController;

/**
 * Class BulkControllerGroupMatchesTest
 *
 * @since 3.3.0
 */
class BulkControllerGroupMatchesTest extends TestCase {

	/**
	 * Two pending rows on one post become one article with two headings.
	 *
	 * @return void
	 */
	public function test_two_headings_on_one_post_group_as_one_article(): void {
		$articles = BulkController::groupMatchesByPost(
			array(
				array(
					'id'               => 1,
					'post_id'          => 10,
					'post_title'       => 'Birds',
					'heading_tag'      => 'h2',
					'heading_text'     => 'American Goldfinch',
					'heading_hash'     => 'hash-a',
					'image_id'         => 42,
					'image_url'        => 'https://example.com/a.jpg',
					'confidence_score' => 80,
				),
				array(
					'id'               => 2,
					'post_id'          => 10,
					'post_title'       => 'Birds',
					'heading_tag'      => 'featured',
					'heading_text'     => 'Birds',
					'heading_hash'     => 'featured',
					'image_id'         => 7,
					'image_url'        => 'https://example.com/b.jpg',
					'confidence_score' => 75,
				),
			)
		);

		$this->assertCount( 1, $articles );
		$this->assertSame( 10, $articles[0]['post_id'] );
		$this->assertSame( 'Birds', $articles[0]['post_title'] );
		$this->assertCount( 2, $articles[0]['headings'] );
		$this->assertSame( 'American Goldfinch', $articles[0]['headings'][0]['heading_text'] );
		$this->assertSame( 'featured', $articles[0]['headings'][1]['heading_hash'] );
	}

	/**
	 * Distinct post IDs stay as separate articles.
	 *
	 * @return void
	 */
	public function test_different_posts_stay_separate(): void {
		$articles = BulkController::groupMatchesByPost(
			array(
				array(
					'id'               => 1,
					'post_id'          => 10,
					'post_title'       => 'A',
					'heading_tag'      => 'h2',
					'heading_text'     => 'One',
					'heading_hash'     => 'h1',
					'image_id'         => 1,
					'image_url'        => '',
					'confidence_score' => 80,
				),
				array(
					'id'               => 2,
					'post_id'          => 11,
					'post_title'       => 'B',
					'heading_tag'      => 'h2',
					'heading_text'     => 'Two',
					'heading_hash'     => 'h2',
					'image_id'         => 2,
					'image_url'        => '',
					'confidence_score' => 81,
				),
			)
		);

		$this->assertCount( 2, $articles );
		$this->assertSame( 10, $articles[0]['post_id'] );
		$this->assertSame( 11, $articles[1]['post_id'] );
	}
}
