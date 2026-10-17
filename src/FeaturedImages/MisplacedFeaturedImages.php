<?php
/**
 * Featured images that ended up in the article body instead.
 *
 * Before 3.6.2 a fallback featured image (KSM Extensions) made posts look like
 * they already had one, so runs skipped the featured slot while the heading
 * pass put the article's own image under its first heading. A post qualifies
 * here only when it has no real featured image and its body holds an image
 * that is an exact or prefix filename match for the post — the same rule that
 * auto-assigns featured images.
 *
 * @package SmartImageMatcher\FeaturedImages
 * @since   3.7.3
 */

declare( strict_types=1 );

namespace SmartImageMatcher\FeaturedImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\IgnoredArticles;
use SmartImageMatcher\Insertion\InsertionService;
use SmartImageMatcher\Logging\Logger;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Class MisplacedFeaturedImages
 *
 * @since 3.7.3
 */
class MisplacedFeaturedImages {

	/**
	 * Featured image service.
	 *
	 * @var FeaturedImageService
	 */
	private FeaturedImageService $featured;

	/**
	 * Insertion service.
	 *
	 * @var InsertionService
	 */
	private InsertionService $insertion;

	/**
	 * Constructor.
	 *
	 * @param FeaturedImageService $featured  Featured image service.
	 * @param InsertionService     $insertion Insertion service.
	 */
	public function __construct( FeaturedImageService $featured, InsertionService $insertion ) {
		$this->featured  = $featured;
		$this->insertion = $insertion;
	}

	/**
	 * The body image that should be this post's featured image, or 0.
	 *
	 * @since 3.7.3
	 * @param int $post_id Post ID.
	 * @return int Attachment ID.
	 */
	public function candidate( int $post_id ): int {
		if ( $post_id <= 0 || IgnoredArticles::isIgnored( $post_id ) || FeaturedImageService::hasActionableFeaturedImage( $post_id ) ) {
			return 0;
		}

		$in_body = $this->insertion->attachmentIdsInContent( $post_id );
		if ( empty( $in_body ) ) {
			return 0;
		}

		$safe = array();
		foreach ( $in_body as $image_id ) {
			if ( $this->featured->isAutoAssignSafeAttachment( $post_id, (int) $image_id ) ) {
				$safe[] = (int) $image_id;
			}
		}

		if ( count( $safe ) <= 1 ) {
			return (int) ( $safe[0] ?? 0 );
		}

		// Several qualify: take the post's best filename match.
		$best = (int) ( $this->featured->scoreBestForPost( $post_id )['attachment_id'] ?? 0 );
		return in_array( $best, $safe, true ) ? $best : $safe[0];
	}

	/**
	 * Set the image as the featured image, then take it out of the body.
	 *
	 * @since 3.7.3
	 * @param int $post_id Post ID.
	 * @return array{ok:bool,image_id:int,message:string}
	 */
	public function move( int $post_id ): array {
		$image_id = $this->candidate( $post_id );
		if ( $image_id <= 0 ) {
			return array(
				'ok'       => false,
				'image_id' => 0,
				'message'  => __( 'Nothing to move: the article already has a featured image, or no exact filename match is in its body.', 'smart-image-matcher' ),
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array(
				'ok'       => false,
				'image_id' => $image_id,
				'message'  => __( 'Permission denied.', 'smart-image-matcher' ),
			);
		}

		set_post_thumbnail( $post_id, $image_id );
		if ( FeaturedImageService::getStoredThumbnailId( $post_id ) !== $image_id ) {
			return array(
				'ok'       => false,
				'image_id' => $image_id,
				'message'  => __( 'Could not set the featured image.', 'smart-image-matcher' ),
			);
		}

		$removed = $this->insertion->removeImage( $post_id, $image_id );
		if ( is_wp_error( $removed ) ) {
			Logger::warn(
				'MisplacedFeaturedImages: featured image set but not removed from the body',
				array(
					'post_id'  => $post_id,
					'image_id' => $image_id,
					'reason'   => $removed->get_error_message(),
				)
			);
			return array(
				'ok'       => true,
				'image_id' => $image_id,
				'message'  => __( 'Featured image set; it is still in the body as well.', 'smart-image-matcher' ),
			);
		}

		Logger::info(
			'MisplacedFeaturedImages: moved body image to featured',
			array(
				'post_id'  => $post_id,
				'image_id' => $image_id,
			)
		);

		return array(
			'ok'       => true,
			'image_id' => $image_id,
			'message'  => '',
		);
	}
}
