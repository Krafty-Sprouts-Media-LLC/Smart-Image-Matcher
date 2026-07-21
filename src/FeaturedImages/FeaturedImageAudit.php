<?php
/**
 * Audit and cleanup helpers for featured image assignments.
 *
 * @package SmartImageMatcher\FeaturedImages
 * @since   3.0.5
 */

declare( strict_types=1 );

namespace SmartImageMatcher\FeaturedImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\PostStatuses;

/**
 * Class FeaturedImageAudit
 *
 * @since 3.0.5
 */
class FeaturedImageAudit {

	/**
	 * @var FeaturedImageService
	 */
	private FeaturedImageService $matcher;

	/**
	 * Constructor.
	 *
	 * @param FeaturedImageService $matcher Slug scoring service.
	 */
	public function __construct( FeaturedImageService $matcher ) {
		$this->matcher = $matcher;
	}

	/**
	 * Find posts whose featured image would not pass current auto-assign rules.
	 *
	 * @since 3.0.5
	 * @param string   $postType Post type slug.
	 * @param string[] $statuses Post statuses.
	 * @param int      $preview  Maximum preview rows to return.
	 * @return array<string, mixed>
	 */
	public function scanUnsafeAssignments( string $postType, array $statuses, int $preview = 25 ): array {
		global $wpdb;

		$statuses = PostStatuses::sanitizeList( $statuses );
		$rows     = $this->fetchAssignedRows( $postType, $statuses );
		$unsafe   = array();
		$safe     = 0;

		foreach ( $rows as $row ) {
			$postSlug  = (string) ( $row['post_name'] ?? '' );
			$imageSlug = (string) ( $row['image_slug'] ?? '' );
			$postId    = (int) ( $row['ID'] ?? 0 );

			if ( $postId <= 0 || '' === $postSlug || '' === $imageSlug ) {
				continue;
			}

			if ( $this->matcher->isAutoAssignSafePair( $postSlug, $imageSlug ) ) {
				++$safe;
				continue;
			}

			$score = $this->matcher->scoreSlugMatch( $postSlug, $imageSlug );

			$unsafe[] = array(
				'id'          => $postId,
				'title'       => (string) ( $row['post_title'] ?? '' ),
				'post_slug'   => $postSlug,
				'image_slug'  => $imageSlug,
				'score'       => (int) ( $score['score'] ?? 0 ),
				'method'      => (string) ( $score['method'] ?? '' ),
				'attachment_id' => (int) ( $row['attachment_id'] ?? 0 ),
			);
		}

		return array(
			'total_assigned' => count( $rows ),
			'safe'           => $safe,
			'unsafe'         => count( $unsafe ),
			'preview'        => array_slice( $unsafe, 0, max( 1, min( 50, $preview ) ) ),
			'post_ids'       => array_map(
				static fn( array $item ): int => (int) ( $item['id'] ?? 0 ),
				$unsafe
			),
		);
	}

	/**
	 * Remove the featured image from a post without deleting the attachment.
	 *
	 * @since 3.0.5
	 * @param int $postId Post ID.
	 * @return bool
	 */
	public function clearFeaturedImage( int $postId ): bool {
		if ( $postId <= 0 ) {
			return false;
		}

		return delete_post_thumbnail( $postId );
	}

	/**
	 * Clear a featured image when the current assignment is not auto-assign safe.
	 *
	 * @since 3.0.5
	 * @param int $postId Post ID.
	 * @return array<string, mixed>
	 */
	public function clearIfUnsafe( int $postId ): array {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'cleared' => false,
				'status'  => __( 'Post not found.', 'smart-image-matcher' ),
			);
		}

		$thumbId = (int) get_post_thumbnail_id( $postId );
		if ( $thumbId <= 0 ) {
			return array(
				'cleared' => false,
				'status'  => __( 'No featured image.', 'smart-image-matcher' ),
			);
		}

		$attachment = get_post( $thumbId );
		if ( ! $attachment instanceof \WP_Post ) {
			return array(
				'cleared' => false,
				'status'  => __( 'Featured image attachment not found.', 'smart-image-matcher' ),
			);
		}

		$postSlug  = (string) $post->post_name;
		$imageSlug = (string) $attachment->post_name;

		if ( $this->matcher->isAutoAssignSafePair( $postSlug, $imageSlug ) ) {
			return array(
				'cleared'     => false,
				'status'      => __( 'Already safe.', 'smart-image-matcher' ),
				'post_slug'   => $postSlug,
				'image_slug'  => $imageSlug,
				'attachment_id' => $thumbId,
			);
		}

		$score = $this->matcher->scoreSlugMatch( $postSlug, $imageSlug );

		if ( ! $this->clearFeaturedImage( $postId ) ) {
			return array(
				'cleared'     => false,
				'status'      => __( 'Could not clear featured image.', 'smart-image-matcher' ),
				'post_slug'   => $postSlug,
				'image_slug'  => $imageSlug,
				'attachment_id' => $thumbId,
				'score'       => (int) ( $score['score'] ?? 0 ),
				'method'      => (string) ( $score['method'] ?? '' ),
			);
		}

		return array(
			'cleared'       => true,
			'status'        => __( 'Cleared', 'smart-image-matcher' ),
			'post_slug'     => $postSlug,
			'image_slug'    => $imageSlug,
			'attachment_id' => $thumbId,
			'score'         => (int) ( $score['score'] ?? 0 ),
			'method'        => (string) ( $score['method'] ?? '' ),
		);
	}

	/**
	 * Fetch posts that currently have a featured image assigned.
	 *
	 * @since 3.0.5
	 * @param string   $postType Post type slug.
	 * @param string[] $statuses Post statuses.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchAssignedRows( string $postType, array $statuses ): array {
		global $wpdb;

		if ( empty( $statuses ) ) {
			return array();
		}

		$statusPlaceholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$args               = array_merge( array( $postType ), $statuses );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = "SELECT p.ID, p.post_title, p.post_name, att.ID AS attachment_id, att.post_name AS image_slug
				  FROM {$wpdb->posts} p
				  INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
				  INNER JOIN {$wpdb->posts} att ON att.ID = CAST(pm.meta_value AS UNSIGNED)
				  WHERE p.post_type = %s
				    AND p.post_status IN ({$statusPlaceholders})
				    AND CAST(pm.meta_value AS UNSIGNED) > 0
				  ORDER BY p.ID ASC";

		$rows = $wpdb->get_results( $wpdb->prepare( $query, ...$args ), ARRAY_A );
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}
}
