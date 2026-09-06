<?php
/**
 * Optional heading match override (AI when a text provider is connected).
 *
 * The free ArticleProcessor never calls Premium::has(). When this adapter is
 * missing or unavailable, headings use keyword scores.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.4.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Interface HeadingMatchGate
 *
 * @since 3.4.0
 */
interface HeadingMatchGate {

	/**
	 * Whether this override should replace keyword heading scores.
	 *
	 * @since 3.4.0
	 * @return bool
	 */
	public function isAvailable(): bool;

	/**
	 * Best library match for one heading.
	 *
	 * @since 3.4.0
	 * @param array<string, mixed> $heading      Heading descriptor.
	 * @param int[]                $exclude_ids  Attachment IDs already used in this article.
	 * @return array{score:int,image_id:int}
	 */
	public function bestMatch( array $heading, array $exclude_ids = array() ): array;
}
