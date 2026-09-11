<?php
/**
 * Transient-based cache with third-party cache-plugin compatibility.
 *
 * @package SmartImageMatcher\Cache
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cache
 *
 * @since 3.0.0
 */
class Cache {

	/**
	 * Known transient keys managed by this plugin.
	 *
	 * @var string[]
	 */
	private static array $knownTransients = array(
		'smart_image_matcher_fiaa_attachment_slug_map',
		'smart_image_matcher_api_calls_hour',
		'smart_image_matcher_api_calls_day',
	);

	/**
	 * Delete all known plugin transients.
	 *
	 * Explicit deletes, never raw SQL LIKE queries.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function clearAll(): void {
		foreach ( self::$knownTransients as $key ) {
			delete_transient( $key );
		}
	}

	/**
	 * Clear all caches for a specific post (including third-party plugins).
	 *
	 * @since 3.0.0
	 * @param int $postId Post ID.
	 * @return void
	 */
	public static function clearPost( int $postId ): void {
		clean_post_cache( $postId );
		delete_transient( "smart_image_matcher_matches_{$postId}" );

		// Third-party cache-plugin compatibility.
		// See .legacy/includes/class-sim-cache.php::clear_plugin_caches for origin.
		( new CachePluginCompat() )->clearPost( $postId );

		/**
		 * Fires after SIM has cleared the cache for a post.
		 *
		 * @since 3.0.0
		 * @param int $postId Post ID.
		 */
		do_action( 'smart_image_matcher_clear_post_cache', $postId ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- smart_image_matcher_ is the project hook prefix.
	}

	/**
	 * Delete expired smart_image_matcher_* transients from the options table.
	 *
	 * Called by the daily cleanup cron.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function clearExpiredTransients(): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND option_value < %d",
				'_transient_timeout_smart_image_matcher_%',
				time()
			)
		);
	}
}
