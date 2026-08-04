<?php
/**
 * Premium: AI-generated featured image fallback.
 *
 * When FIAA finds no slug-matched attachment for a post,
 * this class generates a featured image via AiImageGenerator
 * and sets it as the post thumbnail.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Settings\Settings;

/**
 * Class AiFeaturedImage
 *
 * @since 3.0.0
 */
class AiFeaturedImage {

	/**
	 * Register hooks.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		// Invoked directly from FiaaCron when no slug match is found.
	}

	/**
	 * Generate a featured image for a post and set it as the thumbnail.
	 *
	 * @since 3.0.0
	 * @param int $postId Post ID.
	 * @return bool True on success, false on failure / skip.
	 */
	public function generateForPost( int $postId ): bool {
		if ( ! Settings::get( 'ai_featured_image_enabled' ) ) {
			return false;
		}

		if ( metadata_exists( 'post', $postId, '_thumbnail_id' ) ) {
			$thumbId = (int) get_metadata_raw( 'post', $postId, '_thumbnail_id', true );
			if ( $thumbId > 0 ) {
				return false;
			}
		}

		if ( ! ProviderBridge::isImageGenerationAvailable() ) {
			return false;
		}

		$result = ( new AiImageGenerator() )->generateFeaturedForPost( $postId );

		if ( is_wp_error( $result ) ) {
			Logger::warn(
				'AiFeaturedImage: generation failed',
				array(
					'post_id' => $postId,
					'error'   => $result->get_error_message(),
				)
			);
			return false;
		}

		Logger::info(
			'AiFeaturedImage: generated and assigned',
			array(
				'post_id'       => $postId,
				'attachment_id' => (int) $result,
			)
		);

		return true;
	}
}
