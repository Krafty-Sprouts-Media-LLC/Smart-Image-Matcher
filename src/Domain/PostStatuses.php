<?php
/**
 * WordPress post status helpers for queries and admin UI.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.0.3
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PostStatuses
 *
 * @since 3.0.3
 */
class PostStatuses {

	/**
	 * Status slugs that must never be used in content matching queries.
	 *
	 * @since 3.0.3
	 * @var string[]
	 */
	private const EXCLUDED_SLUGS = array(
		'inherit',
		'trash',
		'auto-draft',
	);

	/**
	 * Return queryable post status objects (non-internal, content statuses).
	 *
	 * @since 3.0.3
	 * @return array<string, \stdClass>
	 */
	public static function queryable(): array {
		$stati = get_post_stati( array( 'internal' => false ), 'objects' );
		if ( ! is_array( $stati ) ) {
			return array();
		}

		foreach ( self::EXCLUDED_SLUGS as $slug ) {
			unset( $stati[ $slug ] );
		}

		return $stati;
	}

	/**
	 * Return queryable post status slugs.
	 *
	 * @since 3.0.3
	 * @return string[]
	 */
	public static function slugs(): array {
		return array_keys( self::queryable() );
	}

	/**
	 * Sanitize a list of post status slugs against registered queryable statuses.
	 *
	 * @since 3.0.3
	 * @param mixed $value Raw list (array or comma-separated string).
	 * @return string[]
	 */
	public static function sanitizeList( $value ): array {
		if ( is_string( $value ) ) {
			$rawStatuses = explode( ',', wp_unslash( $value ) );
		} elseif ( is_array( $value ) ) {
			$rawStatuses = $value;
		} else {
			$rawStatuses = array();
		}

		$allowed  = self::slugs();
		$statuses = array_values(
			array_intersect(
				array_map( 'sanitize_key', array_map( 'trim', $rawStatuses ) ),
				$allowed
			)
		);

		return ! empty( $statuses ) ? $statuses : array( 'publish' );
	}

	/**
	 * Convert a sanitized status list to a comma-separated string.
	 *
	 * @since 3.0.3
	 * @param string[] $statuses Status slugs.
	 * @return string
	 */
	public static function toCsv( array $statuses ): string {
		$statuses = self::sanitizeList( $statuses );
		return implode( ',', $statuses );
	}

	/**
	 * Human-readable label for a post status slug.
	 *
	 * @since 3.0.3
	 * @param string $slug Status slug.
	 * @return string
	 */
	public static function label( string $slug ): string {
		$stati = self::queryable();
		if ( isset( $stati[ $slug ] ) && isset( $stati[ $slug ]->label ) ) {
			return (string) $stati[ $slug ]->label;
		}

		return $slug;
	}
}
