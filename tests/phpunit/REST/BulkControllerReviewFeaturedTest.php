<?php
/**
 * Review must decide "already has a featured image" the same way article runs do.
 *
 * Regression: a fallback featured-image plugin makes has_post_thumbnail() true for
 * every post, so every featured Review suggestion was hidden ("Nothing to review"
 * after a run reported 256 sent to Review).
 *
 * @package SmartImageMatcher\Tests\REST
 * @since   3.6.2
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\REST;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\REST\BulkController;

/**
 * Class BulkControllerReviewFeaturedTest
 *
 * @since 3.6.2
 */
class BulkControllerReviewFeaturedTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['sim_test_has_post_thumbnail'], $GLOBALS['sim_test_post_meta'][31], $GLOBALS['sim_test_post_meta'][32], $GLOBALS['sim_test_get_post'] );
	}

	/**
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function featuredRow( int $post_id ): array {
		return array(
			'post_id'      => $post_id,
			'heading_hash' => 'featured',
			'heading_tag'  => 'featured',
			'image_id'     => 90,
		);
	}

	/** @test */
	public function featured_suggestion_stays_visible_when_only_a_fallback_image_shows(): void {
		// A fallback plugin answers "yes" for every post; nothing is actually saved.
		$GLOBALS['sim_test_has_post_thumbnail'] = static fn() => true;

		$kept = BulkController::omitInsertedHeadings( array( $this->featuredRow( 31 ) ) );

		$this->assertCount( 1, $kept );
	}

	/** @test */
	public function featured_suggestion_is_hidden_once_a_real_featured_image_is_saved(): void {
		$GLOBALS['sim_test_post_meta'][32]['_thumbnail_id'] = 77;
		$attachment            = new \WP_Post();
		$attachment->ID        = 77;
		$attachment->post_type = 'attachment';
		$GLOBALS['sim_test_get_post'] = static fn( $id ) => 77 === (int) $id ? $attachment : null;

		$kept = BulkController::omitInsertedHeadings( array( $this->featuredRow( 32 ) ) );

		$this->assertCount( 0, $kept );
	}
}
