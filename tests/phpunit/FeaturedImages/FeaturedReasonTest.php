<?php
/**
 * Why a featured suggestion went to Review instead of being assigned.
 *
 * @package SmartImageMatcher\Tests\FeaturedImages
 * @since   3.6.2
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\FeaturedImages;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\FeaturedImages\SlugMapBuilder;
use SmartImageMatcher\REST\BulkController;

/**
 * Class FeaturedReasonTest
 *
 * @since 3.6.2
 */
class FeaturedReasonTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['sim_test_get_post'] );
	}

	/** @test */
	public function similar_filename_lists_shared_and_differing_words(): void {
		$explain = ( new FeaturedImageService( new SlugMapBuilder() ) )->explainSlugMatch(
			'can-you-hunt-on-your-own-property-in-massachusetts',
			'can-you-butcher-your-own-animals-in-massachusetts'
		);

		$this->assertFalse( $explain['auto'] );
		$this->assertSame( array( 'own', 'massachusetts' ), $explain['shared'] );
		$this->assertSame( array( 'hunt', 'property' ), $explain['post_only'] );
		$this->assertSame( array( 'butcher', 'animals' ), $explain['image_only'] );
	}

	/** @test */
	public function prefix_filename_counts_as_automatic(): void {
		$explain = ( new FeaturedImageService( new SlugMapBuilder() ) )->explainSlugMatch( 'american-goldfinch-winter-diet', 'american-goldfinch' );
		$this->assertTrue( $explain['auto'] );
	}

	/** @test */
	public function review_row_reason_reads_as_a_sentence(): void {
		$post            = new \WP_Post();
		$post->ID        = 41;
		$post->post_name = 'can-you-hunt-on-your-own-property-in-massachusetts';
		$GLOBALS['sim_test_get_post'] = static fn() => $post;

		$reason = BulkController::featuredReason(
			array(
				'post_id'    => 41,
				'image_file' => 'Can-You-Butcher-Your-Own-Animals-in-Massachusetts.jpg',
			)
		);

		$this->assertStringContainsString( 'Similar filename, not exact', $reason );
		$this->assertStringContainsString( 'own, massachusetts', $reason );
		$this->assertStringContainsString( 'hunt, property', $reason );
		$this->assertStringContainsString( 'butcher, animals', $reason );
	}
}
