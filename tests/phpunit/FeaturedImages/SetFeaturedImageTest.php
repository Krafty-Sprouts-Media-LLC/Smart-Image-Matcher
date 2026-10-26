<?php
/**
 * Setting a featured image takes that image out of the article body.
 *
 * @package SmartImageMatcher\Tests\FeaturedImages
 * @since   3.7.7
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\FeaturedImages;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\FeaturedImages\FeaturedImageService;

/**
 * Class SetFeaturedImageTest
 *
 * @since 3.7.7
 */
class SetFeaturedImageTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['sim_test_get_post'], $GLOBALS['sim_test_post_meta'], $GLOBALS['sim_test_updated_post'] );
	}

	/**
	 * Use a classic-editor post with the given body.
	 *
	 * @param string $content Post content.
	 * @return void
	 */
	private function givenPost( string $content ): void {
		$GLOBALS['sim_test_get_post'] = static function () use ( $content ) {
			$post               = new \WP_Post();
			$post->ID           = 10;
			$post->post_content = $content;
			return $post;
		};
	}

	/** @test */
	public function featured_image_is_removed_from_the_body_and_other_images_stay(): void {
		$this->givenPost(
			"<h2>Grouse Hunting Season Dates in South Carolina</h2>\n"
			. "<p><img class=\"wp-image-42\" src=\"grouse.jpg\" /></p>\n"
			. "<p><img class=\"wp-image-7\" src=\"other.jpg\" /></p>"
		);

		$this->assertTrue( FeaturedImageService::setFeaturedImage( 10, 42 ) );
		$this->assertSame( 42, FeaturedImageService::getStoredThumbnailId( 10 ) );

		$saved = (string) ( $GLOBALS['sim_test_updated_post']['post_content'] ?? '' );
		$this->assertStringNotContainsString( 'wp-image-42', $saved );
		$this->assertStringContainsString( 'wp-image-7', $saved );
		$this->assertStringContainsString( '<h2>Grouse Hunting Season Dates in South Carolina</h2>', $saved );
	}

	/** @test */
	public function body_without_the_featured_image_is_not_saved(): void {
		$this->givenPost( '<p><img class="wp-image-7" src="other.jpg" /></p>' );

		$this->assertTrue( FeaturedImageService::setFeaturedImage( 10, 42 ) );
		$this->assertArrayNotHasKey( 'sim_test_updated_post', $GLOBALS );
	}
}
