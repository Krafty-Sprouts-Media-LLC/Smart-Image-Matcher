<?php
/**
 * Optional skip-band image generation adapter.
 *
 * The free ArticleProcessor never calls Premium::has(). When this adapter is
 * missing or unavailable, skip-band outcomes become skip.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.3.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface GenerationFallback
 *
 * @since 3.3.0
 */
interface GenerationFallback {

	/**
	 * Whether skip-band generation may be offered.
	 *
	 * @since 3.3.0
	 * @return bool
	 */
	public function isAvailable(): bool;

	/**
	 * Enqueue generation for one featured slot or heading.
	 *
	 * @since 3.3.0
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash, or "featured".
	 * @param string $heading_text Heading or post title.
	 * @param string $section_text Surrounding section excerpt.
	 * @return bool True if a job was queued.
	 */
	public function enqueue( int $post_id, string $heading_hash, string $heading_text, string $section_text ): bool;
}
