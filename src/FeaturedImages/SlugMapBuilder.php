<?php
/**
 * Builds and caches a slug → attachment_id map.
 *
 * Ported from .legacy/includes/class-sim-featured-image-auto-assigner.php
 * with the GUID LIKE fallback removed (audit PERF6).
 *
 * @package SmartImageMatcher\FeaturedImages
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\FeaturedImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SlugMapBuilder
 *
 * @since 3.0.0
 */
class SlugMapBuilder {

	const CACHE_KEY = 'smart_image_matcher_fiaa_attachment_slug_map';
	const CACHE_TTL = 1800; // 30 minutes.

	/**
	 * Get the slug map from cache, or build and cache it.
	 *
	 * @since 3.0.0
	 * @return array<string, int>  slug => attachment_id
	 */
	public function get(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		return $this->build();
	}

	/**
	 * Build the slug map with a direct SQL query and cache it.
	 *
	 * Intentional direct query: WP_Query cannot return (ID, post_name) for
	 * all attachments without loading post objects into memory.
	 *
	 * GUID LIKE fallback intentionally omitted — it caused full-table scans
	 * against an unindexed column (audit PERF6).
	 *
	 * @since 3.0.0
	 * @return array<string, int>
	 */
	public function build(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT p.ID, p.post_name, m.meta_value AS attached_file
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m
			   ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			 WHERE p.post_type   = 'attachment'
			   AND p.post_status = 'inherit'
			   AND p.post_name  <> ''",
			ARRAY_A
		);

		$map = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$id = (int) ( $row['ID'] ?? 0 );
				if ( $id <= 0 ) {
					continue;
				}
				$keys = array( (string) ( $row['post_name'] ?? '' ) );
				$file = (string) ( $row['attached_file'] ?? '' );
				if ( '' !== $file ) {
					$base = function_exists( 'wp_basename' ) ? wp_basename( $file ) : basename( $file );
					if ( is_string( $base ) && '' !== $base ) {
						$keys[] = $base;
					}
				}
				foreach ( $keys as $key ) {
					if ( '' !== $key && ! isset( $map[ $key ] ) ) {
						$map[ $key ] = $id;
					}
				}
			}
		}

		set_transient( self::CACHE_KEY, $map, self::CACHE_TTL );

		return $map;
	}

	/**
	 * Invalidate the cache (called on add/edit/delete attachment).
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function clearCache(): void {
		delete_transient( self::CACHE_KEY );
	}
}
