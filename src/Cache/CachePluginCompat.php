<?php
/**
 * Third-party cache-plugin compatibility.
 *
 * Calls per-post flush APIs for every major WordPress caching plugin.
 * Lifted directly from .legacy/includes/class-sim-cache.php::clear_plugin_caches().
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
 * Class CachePluginCompat
 *
 * @since 3.0.0
 */
class CachePluginCompat {

	/**
	 * Fire per-post flush on every detected caching plugin.
	 *
	 * @since 3.0.0
	 * @param int $postId Post ID.
	 * @return void
	 */
	public function clearPost( int $postId ): void {
		// WP Rocket.
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $postId );
		}
		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $postId );
		}
		// WP Super Cache.
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $postId );
		}
		// WP Fastest Cache.
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			wpfc_clear_post_cache_by_id( $postId );
		}
		// LiteSpeed Cache.
		$liteSpeed = 'LiteSpeed_Cache_API';
		if ( class_exists( $liteSpeed ) && method_exists( $liteSpeed, 'purge_post' ) ) {
			call_user_func( array( $liteSpeed, 'purge_post' ), $postId );
		}
		// Autoptimize (global flush — no per-post API available).
		if ( function_exists( 'autoptimize_flush_pagecache' ) ) {
			autoptimize_flush_pagecache();
		}
		// Comet Cache.
		$cometCache = 'Comet_Cache';
		if ( class_exists( $cometCache ) && method_exists( $cometCache, 'clear' ) ) {
			call_user_func( array( $cometCache, 'clear' ) );
		}
		// WP-Optimize.
		if ( function_exists( 'wpo_cache_flush' ) ) {
			wpo_cache_flush();
		}
	}
}
