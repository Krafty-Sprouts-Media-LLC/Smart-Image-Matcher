<?php
/**
 * Persistence layer for wp_smart_image_matcher_matches rows.
 *
 * All writes use heading_hash (stable) — never heading_position (removed).
 *
 * @package SmartImageMatcher\Domain
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MatchRepository
 *
 * @since 3.0.0
 */
class MatchRepository {

	/**
	 * Save match results for a post, replacing any existing pending rows.
	 *
	 * Deletes previous pending rows for this post first so repeated modal
	 * opens don't accumulate stale data (audit H4).
	 *
	 * @since 3.0.0
	 * @param int                              $postId  Post ID.
	 * @param array<int, array<string, mixed>> $groups  Array of { heading, matches[] } from Matcher.
	 * @return void
	 */
	public function saveMatchGroups( int $postId, array $groups ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'smart_image_matcher_matches';

		// Remove stale pending rows for this post (audit H4 — don't grow forever).
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'post_id' => $postId, 'status' => 'pending' ),
			array( '%d', '%s' )
		);

		$now = current_time( 'mysql' );

		foreach ( $groups as $group ) {
			$heading = $group['heading'];
			$matches = $group['matches'] ?? array();

			foreach ( $matches as $match ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'post_id'          => $postId,
						'heading_hash'     => $heading['heading_hash'] ?? '',
						'heading_text'     => $heading['text'] ?? '',
						'heading_tag'      => $heading['tag'] ?? 'h2',
						'image_id'         => (int) ( $match['image_id'] ?? 0 ),
						'confidence_score' => (int) ( $match['confidence_score'] ?? 0 ),
						'match_method'     => sanitize_key( $match['match_method'] ?? 'keyword' ),
						'ai_reasoning'     => isset( $match['ai_reasoning'] ) ? (string) $match['ai_reasoning'] : null,
						'status'           => 'pending',
						'created_at'       => $now,
					),
					array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
				);
			}
		}
	}

	/**
	 * Mark a match row as approved (image was inserted).
	 *
	 * @since 3.0.0
	 * @param int    $postId      Post ID.
	 * @param int    $imageId     Image ID.
	 * @param string $headingHash Heading hash.
	 * @return void
	 */
	public function markApproved( int $postId, int $imageId, string $headingHash ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_matches',
			array( 'status' => 'approved' ),
			array(
				'post_id'      => $postId,
				'image_id'     => $imageId,
				'heading_hash' => $headingHash,
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);
	}

	/**
	 * Mark a match row as rejected.
	 *
	 * @since 3.0.0
	 * @param int $matchId Match row ID.
	 * @return void
	 */
	public function markRejected( int $matchId ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_matches',
			array( 'status' => 'rejected' ),
			array( 'id' => $matchId ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Get pending matches for a post.
	 *
	 * @since 3.0.0
	 * @param int $postId Post ID.
	 * @param int $limit  Maximum rows. Default 100.
	 * @return array<int, array<string, mixed>>
	 */
	public function getPendingForPost( int $postId, int $limit = 100 ): array {
		return $this->getForPostByStatus( $postId, 'pending', $limit );
	}

	/**
	 * Get approved matches for a post.
	 *
	 * @since 3.0.0
	 * @param int $postId Post ID.
	 * @param int $limit  Maximum rows. Default 100.
	 * @return array<int, array<string, mixed>>
	 */
	public function getApprovedForPost( int $postId, int $limit = 100 ): array {
		return $this->getForPostByStatus( $postId, 'approved', $limit );
	}

	/**
	 * Get matches for a post by status.
	 *
	 * @since 3.0.0
	 * @param int    $postId Post ID.
	 * @param string $status Match status.
	 * @param int    $limit  Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function getForPostByStatus( int $postId, string $status, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smart_image_matcher_matches
				 WHERE post_id = %d AND status = %s
				 ORDER BY confidence_score DESC
				 LIMIT %d",
				$postId,
				$status,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
