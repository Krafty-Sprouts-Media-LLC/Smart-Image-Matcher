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
	 * Status of a row whose image is in the post (or set as its featured image).
	 * "approved" means approved and still waiting for Insert approved.
	 *
	 * @since 3.7.4
	 */
	const STATUS_INSERTED = 'inserted';

	/**
	 * Set the status of specific rows.
	 *
	 * @since 3.7.4
	 * @param int[]  $ids    Row IDs.
	 * @param string $status New status.
	 * @return int Rows changed.
	 */
	public function setStatusByIds( array $ids, string $status ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$table = $wpdb->prefix . 'smart_image_matcher_matches';
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id IN ({$in})", array_merge( array( $status ), $ids ) ) );

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Delete specific rows.
	 *
	 * @since 3.7.4
	 * @param int[] $ids Row IDs.
	 * @return void
	 */
	public function deleteByIds( array $ids ): void {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$table = $wpdb->prefix . 'smart_image_matcher_matches';
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$in})", $ids ) );
	}

	/**
	 * Approved rows after an ID cursor, oldest first (for the one-time reconcile).
	 *
	 * @since 3.7.4
	 * @param int $after_id Last row ID already handled.
	 * @param int $limit    Batch size.
	 * @return array<int, array<string, mixed>> Rows with id, post_id, image_id, heading_hash.
	 */
	public function approvedBatchAfter( int $after_id, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, post_id, image_id, heading_hash FROM {$wpdb->prefix}smart_image_matcher_matches WHERE status = %s AND id > %d ORDER BY id ASC LIMIT %d",
				'approved',
				$after_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Body images this plugin put into a post (inserted, or approved before 3.7.4).
	 *
	 * @since 3.7.4
	 * @param int $post_id Post ID.
	 * @return array<int, array<int, int>> image_id => row IDs.
	 */
	public function insertedBodyRows( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, image_id FROM {$wpdb->prefix}smart_image_matcher_matches WHERE post_id = %d AND heading_hash <> %s AND status IN (%s, %s)",
				$post_id,
				'featured',
				self::STATUS_INSERTED,
				'approved'
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['image_id'] ][] = (int) $row['id'];
		}
		return $out;
	}

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
	 * Replace the pending row for one post + heading hash.
	 *
	 * Does not touch approved/rejected rows or other headings.
	 *
	 * @since 3.3.0
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash, or "featured".
	 * @param string $heading_text Heading or post title.
	 * @param string $heading_tag  Heading tag, or "featured".
	 * @param int    $image_id     Candidate attachment ID.
	 * @param int    $score        Confidence 0–100.
	 * @param string $method       Match method key.
	 * @return void
	 */
	public function upsertPending(
		int $post_id,
		string $heading_hash,
		string $heading_text,
		string $heading_tag,
		int $image_id,
		int $score,
		string $method
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'smart_image_matcher_matches';

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE post_id = %d AND heading_hash = %s AND status = %s",
				$post_id,
				$heading_hash,
				'pending'
			)
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'post_id'          => $post_id,
				'heading_hash'     => $heading_hash,
				'heading_text'     => $heading_text,
				'heading_tag'      => $heading_tag,
				'image_id'         => $image_id,
				'confidence_score' => $score,
				'match_method'     => sanitize_key( $method ),
				'ai_reasoning'     => null,
				'status'           => 'pending',
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
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
	 * Record a successful insert: approve the chosen image and drop other
	 * pending candidates for the same heading (editor carousel leftovers).
	 *
	 * @since 3.3.3
	 * @param int    $postId      Post ID.
	 * @param int    $imageId     Inserted attachment ID.
	 * @param string $headingHash Heading hash, or "featured".
	 * @return void
	 */
	public function markInserted( int $postId, int $imageId, string $headingHash ): void {
		global $wpdb;

		// "inserted", not "approved": approved rows are the ones still waiting for Insert approved.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_matches',
			array( 'status' => self::STATUS_INSERTED ),
			array(
				'post_id'      => $postId,
				'image_id'     => $imageId,
				'heading_hash' => $headingHash,
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);
		$this->clearPendingForHeading( $postId, $headingHash );
	}

	/**
	 * Delete pending rows for one post + heading (including featured).
	 *
	 * @since 3.3.3
	 * @param int    $postId      Post ID.
	 * @param string $headingHash Heading hash, or "featured".
	 * @return void
	 */
	public function clearPendingForHeading( int $postId, string $headingHash ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'smart_image_matcher_matches';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE post_id = %d AND heading_hash = %s AND status = %s",
				$postId,
				$headingHash,
				'pending'
			)
		);
	}

	/**
	 * Images the user rejected for one heading (or "featured") of one post.
	 *
	 * Runs never suggest these again for that slot ("remembered Reject").
	 *
	 * @since 3.7.0
	 * @param int    $postId      Post ID.
	 * @param string $headingHash Heading hash, or "featured".
	 * @return int[]
	 */
	public function rejectedImageIds( int $postId, string $headingHash ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT image_id FROM {$wpdb->prefix}smart_image_matcher_matches WHERE post_id = %d AND heading_hash = %s AND status = %s",
				$postId,
				$headingHash,
				'rejected'
			)
		);

		return array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
	}

	/**
	 * Reject every pending suggestion for one post.
	 *
	 * @since 3.7.0
	 * @param int $postId Post ID.
	 * @return int Rows rejected.
	 */
	public function rejectPendingForPost( int $postId ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smart_image_matcher_matches SET status = %s WHERE post_id = %d AND status = %s",
				'rejected',
				$postId,
				'pending'
			)
		);

		return is_int( $n ) ? $n : 0;
	}

	/**
	 * Delete every pending suggestion for one post (article ignored).
	 *
	 * @since 3.7.0
	 * @param int $postId Post ID.
	 * @return void
	 */
	public function clearPendingForPost( int $postId ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_matches',
			array(
				'post_id' => $postId,
				'status'  => 'pending',
			),
			array( '%d', '%s' )
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
	 * Pending rows after an ID cursor, oldest first (for batched re-checks).
	 *
	 * @since 3.4.9
	 * @param int $afterId Last row ID already processed.
	 * @param int $limit   Batch size.
	 * @return array<int, array<string, mixed>> Rows with id, post_id, heading_text, image_id.
	 */
	public function pendingBatchAfter( int $afterId, int $limit ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, post_id, heading_hash, heading_text, image_id FROM {$wpdb->prefix}smart_image_matcher_matches
				 WHERE status = %s AND id > %d
				 ORDER BY id ASC
				 LIMIT %d",
				'pending',
				$afterId,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
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
