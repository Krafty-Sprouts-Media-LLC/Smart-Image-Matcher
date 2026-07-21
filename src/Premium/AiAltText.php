<?php
/**
 * Premium: AI alt-text generator.
 *
 * Automatically generates alt text for images with empty alt on upload,
 * and provides a bulk "fill missing alt text" admin tool.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;

/**
 * Class AiAltText
 *
 * @since 3.0.0
 */
class AiAltText {

	/**
	 * Action Scheduler hook for single-image alt generation.
	 */
	const HOOK = 'smart_image_matcher_generate_alt_text';

	/**
	 * Register hooks.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_attachment', array( $this, 'maybeQueueOnUpload' ) );
		add_action( self::HOOK,       array( $this, 'generateAndSave' ) );
		add_action( 'rest_api_init',  array( $this, 'registerBulkRoute' ) );
	}

	/**
	 * Queue alt-text generation on image upload when alt is empty.
	 *
	 * @since 3.0.0
	 * @param int $attachmentId Attachment ID.
	 * @return void
	 */
	public function maybeQueueOnUpload( int $attachmentId ): void {
		// Respect the user's setting — off by default.
		if ( ! \SmartImageMatcher\Settings\Settings::get( 'ai_alt_text_on_upload' ) ) {
			return;
		}

		if ( ! wp_attachment_is_image( $attachmentId ) ) {
			return;
		}

		$existing = (string) get_post_meta( $attachmentId, '_wp_attachment_image_alt', true );
		if ( '' !== trim( $existing ) ) {
			return; // Already has alt text.
		}

		if ( ! Queue::isAvailable() ) {
			// Fallback: generate synchronously (acceptable on upload for a single image).
			$this->generateAndSave( $attachmentId );
			return;
		}

		as_enqueue_async_action( self::HOOK, array( 'image_id' => $attachmentId ), Queue::GROUP );
	}

	/**
	 * Generate alt text for a single image and save it.
	 *
	 * @since 3.0.0
	 * @param int $imageId Attachment ID.
	 * @return void
	 */
	public function generateAndSave( int $imageId ): void {
		if ( ! ProviderBridge::isAvailable() ) {
			return;
		}

		$url = wp_get_attachment_url( $imageId );
		if ( ! $url ) {
			return;
		}

		// Cache by image_id + modified time so regeneration is cheap.
		$post     = get_post( $imageId );
		$cacheKey = 'smart_image_matcher_alt_' . $imageId . '_' . ( $post ? strtotime( $post->post_modified_gmt ) : 0 );
		$cached   = get_transient( $cacheKey );

		if ( false !== $cached ) {
			update_post_meta( $imageId, '_wp_attachment_image_alt', sanitize_text_field( (string) $cached ) );
			return;
		}

		$altText = ProviderBridge::scoreImageWithVision(
			(string) $url,
			'Provide concise alt text (maximum 12 words) for this image suitable for accessibility and SEO. Return only the alt text string, no JSON.'
		);

		if ( is_wp_error( $altText ) ) {
			Logger::warn( 'AiAltText: generation failed', array(
				'image_id' => $imageId,
				'error'    => $altText->get_error_message(),
			) );
			return;
		}

		$clean = sanitize_text_field( trim( $altText ) );

		if ( '' === $clean ) {
			return;
		}

		update_post_meta( $imageId, '_wp_attachment_image_alt', $clean );
		set_transient( $cacheKey, $clean, DAY_IN_SECONDS * 30 );

		Logger::info( 'AiAltText: saved', array( 'image_id' => $imageId, 'alt' => $clean ) );
	}

	/**
	 * Register REST route for bulk alt-text fill.
	 *
	 * POST /smart-image-matcher/v1/ai/fill-alt-text
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerBulkRoute(): void {
		register_rest_route(
			'smart-image-matcher/v1',
			'/ai/fill-alt-text',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulkFill' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Bulk-fill missing alt text across the media library.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulkFill( \WP_REST_Request $request ): \WP_REST_Response {
		// Find all images with empty alt text.
		$ids = get_posts( array(
			'post_type'              => 'attachment',
			'post_mime_type'         => 'image',
			'post_status'            => 'inherit',
			'posts_per_page'         => 200, // Cap per request.
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
			),
		) );

		$queued = 0;

		foreach ( $ids as $id ) {
			if ( Queue::isAvailable() ) {
				as_enqueue_async_action( self::HOOK, array( 'image_id' => (int) $id ), Queue::GROUP );
			} else {
				$this->generateAndSave( (int) $id );
			}
			$queued++;
		}

		return rest_ensure_response( array( 'queued' => $queued ) );
	}
}
