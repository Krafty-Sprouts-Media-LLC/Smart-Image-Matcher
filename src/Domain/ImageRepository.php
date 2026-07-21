<?php
/**
 * Image repository backed by the wp_smart_image_matcher_image_terms inverted index.
 *
 * Scoring weights per source field:
 *   filename — 10 (primary)
 *   title    —  9 (+1 if intentional)
 *   alt      —  8
 *   caption  —  5
 *
 * Phase 3 replaces the in-memory full-library array that MatchController
 * was using.  The matcher now gets candidate image IDs via a single SQL JOIN
 * rather than iterating all images in PHP.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Logging\Logger;

/**
 * Class ImageRepository
 *
 * @since 3.0.0
 */
class ImageRepository {

	/**
	 * Per-source weight constants.
	 */
	const WEIGHT_FILENAME = 10;
	const WEIGHT_TITLE    = 9;
	const WEIGHT_ALT      = 8;
	const WEIGHT_CAPTION  = 5;

	// -------------------------------------------------------------------------
	// Query
	// -------------------------------------------------------------------------

	/**
	 * Find candidate images for a set of normalised terms via SQL.
	 *
	 * Returns image metadata rows sorted by total matched weight descending.
	 * Each row: { id, filename, title, alt, caption, url, match_score }
	 *
	 * @since 3.0.0
	 * @param string[] $terms  Normalised keywords from the heading.
	 * @param int      $limit  Maximum candidates. Default 20.
	 * @return array<int, array<string, mixed>>
	 */
	public function findCandidates( array $terms, int $limit = 20 ): array {
		if ( empty( $terms ) ) {
			return array();
		}

		global $wpdb;

		$table        = esc_sql( $wpdb->prefix . 'smart_image_matcher_image_terms' );
		$placeholders = implode( ', ', array_fill( 0, count( $terms ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT image_id, SUM(weight) AS match_score
				 FROM {$table}
				 WHERE term IN ({$placeholders})
				 GROUP BY image_id
				 ORDER BY match_score DESC
				 LIMIT %d",
				array_merge( $terms, array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $rows ) ) {
			return array();
		}

		$imageIds = array_column( $rows, 'image_id' );
		$scoreMap = array_column( $rows, 'match_score', 'image_id' );

		// Fetch metadata for the candidate image IDs.
		$metadata = $this->fetchMetadata( array_map( 'intval', $imageIds ) );

		// Merge match_score into metadata rows.
		foreach ( $metadata as &$row ) {
			$row['match_score'] = (int) ( $scoreMap[ $row['id'] ] ?? 0 );
		}
		unset( $row );

		// Re-sort by score descending (fetchMetadata preserves original order).
		usort( $metadata, static fn( $a, $b ) => $b['match_score'] - $a['match_score'] );

		return $metadata;
	}

	// -------------------------------------------------------------------------
	// Indexing
	// -------------------------------------------------------------------------

	/**
	 * Index (or re-index) a single attachment.
	 *
	 * Called on add_attachment, edit_attachment.
	 *
	 * @since 3.0.0
	 * @param int $imageId Attachment ID.
	 * @return void
	 */
	public function indexImage( int $imageId ): void {
		if ( ! wp_attachment_is_image( $imageId ) ) {
			return;
		}

		global $wpdb;

		$table    = esc_sql( $wpdb->prefix . 'smart_image_matcher_image_terms' );
		$terms    = $this->extractTermsForImage( $imageId );

		// Delete stale entries for this image.
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array( 'image_id' => $imageId ),
			array( '%d' )
		);

		if ( empty( $terms ) ) {
			return;
		}

		// Bulk insert.
		$rows   = array();
		$formats = array();

		foreach ( $terms as $term ) {
			$rows[]   = $imageId;
			$rows[]   = $term['term'];
			$rows[]   = $term['weight'];
			$rows[]   = $term['source'];
			$formats[] = '(%d, %s, %d, %s)';
		}

		$sql = "INSERT INTO {$table} (image_id, term, weight, source) VALUES "
			. implode( ', ', $formats );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $wpdb->prepare( $sql, $rows ) );

		Logger::info( 'ImageRepository: indexed image', array( 'image_id' => $imageId, 'terms' => count( $terms ) ) );
	}

	/**
	 * Remove an attachment from the inverted index.
	 *
	 * Called on delete_attachment.
	 *
	 * @since 3.0.0
	 * @param int $imageId Attachment ID.
	 * @return void
	 */
	public function removeImage( int $imageId ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_image_terms',
			array( 'image_id' => $imageId ),
			array( '%d' )
		);
	}

	/**
	 * Backfill the entire media library.
	 *
	 * Called once from the activation backfill Action Scheduler job.
	 * Processes in batches to stay within PHP time limits.
	 *
	 * @since 3.0.0
	 * @param int $batchSize Attachments per batch. Default 200.
	 * @return int Number of images indexed.
	 */
	public function backfillAll( int $batchSize = 200 ): int {
		$page     = 1;
		$indexed  = 0;

		do {
			$ids = get_posts( array(
				'post_type'              => 'attachment',
				'post_mime_type'         => 'image',
				'post_status'            => 'inherit',
				'posts_per_page'         => $batchSize,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );

			foreach ( $ids as $id ) {
				$this->indexImage( (int) $id );
				$indexed++;
			}

			$page++;
		} while ( count( $ids ) === $batchSize );

		Logger::info( 'ImageRepository: backfill complete', array( 'count' => $indexed ) );

		return $indexed;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Extract index terms for a single image.
	 *
	 * @since 3.0.0
	 * @param int $imageId Attachment ID.
	 * @return array<int, array{term: string, weight: int, source: string}>
	 */
	private function extractTermsForImage( int $imageId ): array {
		$terms = array();

		// Filename.
		$file     = (string) get_attached_file( $imageId );
		$basename = pathinfo( $file, PATHINFO_FILENAME );
		$basename = strtolower( str_replace( array( '-', '_' ), ' ', $basename ) );
		foreach ( $this->tokenize( $basename ) as $token ) {
			$terms[] = array( 'term' => $token, 'weight' => self::WEIGHT_FILENAME, 'source' => 'filename' );
		}

		// Title.
		$title = strtolower( get_the_title( $imageId ) );
		foreach ( $this->tokenize( $title ) as $token ) {
			$terms[] = array( 'term' => $token, 'weight' => self::WEIGHT_TITLE, 'source' => 'title' );
		}

		// Alt text.
		$alt = strtolower( (string) get_post_meta( $imageId, '_wp_attachment_image_alt', true ) );
		foreach ( $this->tokenize( $alt ) as $token ) {
			$terms[] = array( 'term' => $token, 'weight' => self::WEIGHT_ALT, 'source' => 'alt' );
		}

		// Caption.
		$caption = strtolower( (string) wp_get_attachment_caption( $imageId ) );
		foreach ( $this->tokenize( $caption ) as $token ) {
			$terms[] = array( 'term' => $token, 'weight' => self::WEIGHT_CAPTION, 'source' => 'caption' );
		}

		// Deduplicate: if the same term appears in multiple fields, keep the
		// highest-weight entry only.
		$seen   = array();
		$unique = array();
		foreach ( $terms as $entry ) {
			$t = $entry['term'];
			if ( ! isset( $seen[ $t ] ) || $entry['weight'] > $seen[ $t ] ) {
				$seen[ $t ]    = $entry['weight'];
				$unique[ $t ]  = $entry;
			}
		}

		return array_values( $unique );
	}

	/**
	 * Tokenize a text string into clean individual words.
	 *
	 * @since 3.0.0
	 * @param string $text Input text (already lowercased).
	 * @return string[]
	 */
	private function tokenize( string $text ): array {
		// Remove special chars, split.
		$text   = (string) preg_replace( '/[^a-z0-9\s]/', ' ', $text );
		$words  = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return array();
		}

		// Filter very short tokens (< 2 chars) unless they are meaningful short
		// codes. The full whitelist logic lives in Normalizer; here we use a
		// simple length gate for the index.
		return array_values( array_filter( $words, static fn( $w ) => strlen( $w ) >= 2 ) );
	}

	/**
	 * Fetch full metadata rows for a list of image IDs.
	 *
	 * Uses a batch of individual get_post / get_post_meta calls so the
	 * WordPress object cache is populated correctly.
	 *
	 * @since 3.0.0
	 * @param int[] $imageIds Attachment IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetchMetadata( array $imageIds ): array {
		$rows = array();

		foreach ( $imageIds as $id ) {
			$rows[] = array(
				'id'       => $id,
				'filename' => basename( (string) get_attached_file( $id ) ),
				'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'title'    => get_the_title( $id ),
				'caption'  => (string) wp_get_attachment_caption( $id ),
				'url'      => (string) wp_get_attachment_url( $id ),
			);
		}

		return $rows;
	}
}
