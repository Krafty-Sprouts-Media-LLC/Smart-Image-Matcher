<?php
/**
 * Optional featured-image match override (AI when a text provider is connected).
 *
 * The free ArticleProcessor never calls Premium::has(). When this adapter is
 * missing or unavailable, featured images use slug scores.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.4.1
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Interface FeaturedMatchGate
 *
 * @since 3.4.1
 */
interface FeaturedMatchGate {

	/**
	 * Whether this override should replace slug featured scores.
	 *
	 * @since 3.4.1
	 * @return bool
	 */
	public function isAvailable(): bool;

	/**
	 * Best library match for a post's featured slot.
	 *
	 * @since 3.4.1
	 * @param \WP_Post $post Post.
	 * @return array{score:int,image_id:int}
	 */
	public function bestMatch( \WP_Post $post ): array;
}
