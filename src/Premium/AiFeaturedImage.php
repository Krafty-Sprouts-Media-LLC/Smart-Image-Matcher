<?php
/**
 * Premium: AI-generated featured image fallback.
 *
 * When FIAA finds no slug-matched attachment for a post,
 * this class generates a featured image via ProviderBridge::generateImage()
 * and registers it in the media library.
 *
 * Gate: only runs when smart_image_matcher_fiaa_cron_enabled and the post has no featured image.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Logging\Logger;

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
	 * Runs only once per post — if the post already has a real stored thumbnail,
	 * this method returns false immediately.
	 *
	 * @since 3.0.0
	 * @param int $postId Post ID.
	 * @return bool True on success, false on failure / skip.
	 */
	public function generateForPost( int $postId ): bool {
		// Only run when the user explicitly enabled this feature.
		if ( ! \SmartImageMatcher\Settings\Settings::get( 'ai_featured_image_enabled' ) ) {
			return false;
		}

		if ( metadata_exists( 'post', $postId, '_thumbnail_id' ) ) {
			$thumbId = (int) get_metadata_raw( 'post', $postId, '_thumbnail_id', true );
			if ( $thumbId > 0 ) {
				return false; // Post already has a real thumbnail.
			}
		}

		if ( ! ProviderBridge::isAvailable() ) {
			return false;
		}

		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$prompt = sprintf(
			'Generate a horizontal 1200×630 photographic-style image that illustrates: %s. %s',
			$post->post_title,
			wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 30 )
		);

		$result = ProviderBridge::generateImage( $prompt );

		if ( is_wp_error( $result ) ) {
			Logger::warn( 'AiFeaturedImage: generation failed', array(
				'post_id' => $postId,
				'error'   => $result->get_error_message(),
			) );
			return false;
		}

		// Sideload the generated image into the media library.
		$imageUrl = method_exists( $result, 'getUrl' ) ? $result->getUrl() : (string) $result;

		if ( '' === $imageUrl ) {
			return false;
		}

		$attachmentId = $this->sideloadImage( $imageUrl, $postId, $post->post_title );

		if ( ! $attachmentId ) {
			return false;
		}

		set_post_thumbnail( $postId, $attachmentId );

		Logger::info( 'AiFeaturedImage: generated and assigned', array(
			'post_id'       => $postId,
			'attachment_id' => $attachmentId,
		) );

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Sideload an image URL into the WordPress media library.
	 *
	 * @since 3.0.0
	 * @param string $url    Remote image URL.
	 * @param int    $postId Parent post ID.
	 * @param string $title  Attachment title.
	 * @return int|null Attachment ID on success, null on failure.
	 */
	private function sideloadImage( string $url, int $postId, string $title ): ?int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$id = media_sideload_image( $url, $postId, $title, 'id' );

		if ( is_wp_error( $id ) ) {
			Logger::error( 'AiFeaturedImage: sideload failed', array( 'error' => $id->get_error_message() ) );
			return null;
		}

		return (int) $id;
	}
}
