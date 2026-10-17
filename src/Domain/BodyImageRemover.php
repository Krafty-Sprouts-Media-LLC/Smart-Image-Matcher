<?php
/**
 * Remove images from article bodies (posts list bulk action → Tools).
 *
 * Scope "all" removes every standalone image in the body; scope "plugin" only
 * the images this plugin inserted. The featured image is never touched, and
 * images inside a gallery are left alone. An image the plugin had inserted is
 * remembered as rejected for its heading, so runs do not put it back.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.7.4
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Insertion\InsertionService;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\JobRunner;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Class BodyImageRemover
 *
 * @since 3.7.4
 */
class BodyImageRemover {

	/** Every standalone image in the body. */
	const SCOPE_ALL = 'all';

	/** Only images this plugin inserted. */
	const SCOPE_PLUGIN = 'plugin';

	/** Post meta: attachment IDs removed from this article's body; runs never put them back. */
	const META_REMOVED = '_smart_image_matcher_removed_images';

	/**
	 * Images the user removed from this article's body.
	 *
	 * @since 3.7.5
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	public static function removedImageIds( int $post_id ): array {
		$ids = get_post_meta( $post_id, self::META_REMOVED, true );
		return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
	}

	/**
	 * Remember removed images for an article (kept to the newest 200).
	 *
	 * @param int   $post_id Post ID.
	 * @param int[] $ids     Attachment IDs.
	 * @return void
	 */
	private static function rememberRemoved( int $post_id, array $ids ): void {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		$all = array_values( array_unique( array_merge( $ids, self::removedImageIds( $post_id ) ) ) );
		update_post_meta( $post_id, self::META_REMOVED, array_slice( $all, 0, 200 ) );
	}

	/**
	 * Insertion service.
	 *
	 * @var InsertionService
	 */
	private InsertionService $insertion;

	/**
	 * Match rows.
	 *
	 * @var MatchRepository
	 */
	private MatchRepository $matches;

	/**
	 * Constructor.
	 *
	 * @param InsertionService $insertion Insertion service.
	 * @param MatchRepository  $matches   Match rows.
	 */
	public function __construct( InsertionService $insertion, MatchRepository $matches ) {
		$this->insertion = $insertion;
		$this->matches   = $matches;
	}

	/**
	 * What removing would affect on one post.
	 *
	 * @since 3.7.4
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null Null when the post does not exist.
	 */
	public function preview( int $post_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$images = $this->insertion->standaloneImageIds( (string) $post->post_content );
		$ours   = $this->matches->insertedBodyRows( $post_id );
		$plugin = 0;
		foreach ( $images as $image_id ) {
			if ( $image_id > 0 && isset( $ours[ $image_id ] ) ) {
				++$plugin;
			}
		}

		$status = get_post_status_object( (string) $post->post_status );

		return array(
			'id'       => $post_id,
			'title'    => html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
			'status'   => $status ? (string) $status->label : (string) $post->post_status,
			'total'    => count( $images ),
			'plugin'   => $plugin,
		);
	}

	/**
	 * Remove images from one post's body (one save; WordPress keeps a revision).
	 *
	 * @since 3.7.4
	 * @param int    $post_id Post ID.
	 * @param string $scope   self::SCOPE_ALL or self::SCOPE_PLUGIN.
	 * @param bool   $ignore  Also ignore the article so no run touches it again.
	 * @return array{ok:bool, removed:int, message:string}
	 */
	public function remove( int $post_id, string $scope, bool $ignore ): array {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return array(
				'ok'      => false,
				'removed' => 0,
				'message' => __( 'Permission denied.', 'smart-image-matcher' ),
			);
		}

		$ours = $this->matches->insertedBodyRows( $post_id );
		$ids  = self::SCOPE_PLUGIN === $scope ? array_keys( $ours ) : null;

		if ( array() === $ids ) {
			$removed = array();
		} else {
			$removed = $this->insertion->removeImages( $post_id, $ids );
		}

		if ( is_wp_error( $removed ) ) {
			$removed = array();
		}

		// Remembered Reject: an image the plugin inserted and the user removed stays out of that heading.
		$rejected = array();
		foreach ( array_unique( $removed ) as $image_id ) {
			foreach ( $ours[ $image_id ] ?? array() as $row_id ) {
				$rejected[] = $row_id;
			}
		}
		$this->matches->setStatusByIds( $rejected, 'rejected' );

		// Every removed library image stays out of this article, whoever inserted it
		// and whatever runs next (hourly, on publish, Bulk Processor).
		self::rememberRemoved( $post_id, $removed );

		if ( $ignore ) {
			IgnoredArticles::set( $post_id, true );
		} elseif ( $removed ) {
			JobRunner::makeMissingImagesIndex()->indexPost( $post_id );
		}

		if ( $removed ) {
			Logger::info(
				'BodyImageRemover: images removed',
				array(
					'post_id' => $post_id,
					'scope'   => $scope,
					'removed' => count( $removed ),
				)
			);
		}

		return array(
			'ok'      => (bool) $removed || $ignore,
			'removed' => count( $removed ),
			'message' => $removed ? '' : (
				self::SCOPE_PLUGIN === $scope
					? __( 'No images added by Smart Image Matcher are in this article.', 'smart-image-matcher' )
					: __( 'This article has no images in its body.', 'smart-image-matcher' )
			),
		);
	}
}
