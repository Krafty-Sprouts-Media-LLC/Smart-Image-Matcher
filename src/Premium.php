<?php
/**
 * Feature gate.
 *
 * In the wp.org build all features are fully enabled — no functionality
 * is locked, gated, or limited behind a license check (Guideline 5).
 * A separate Pro add-on plugin (hosted elsewhere) may call enable() to
 * register additional features in the future.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Premium
 *
 * @since 3.0.0
 */
class Premium {

	/**
	 * Registered feature definitions.
	 *
	 * @var array<string, array{default: bool, label?: string}>
	 */
	private static array $features = array();

	/**
	 * Per-request resolved state cache.
	 *
	 * @var array<string, bool>
	 */
	private static array $cache = array();

	/**
	 * Register a feature and its default enabled state.
	 *
	 * @since 3.0.0
	 * @param string               $slug Feature slug (e.g. 'bulk_processor').
	 * @param array{default?: bool, label?: string} $args {
	 *     @type bool   $default Default enabled state.
	 *     @type string $label   Optional human-readable label.
	 * }
	 * @return void
	 */
	public static function registerFeature( string $slug, array $args = array() ): void {
		self::$features[ $slug ] = wp_parse_args( $args, array( 'default' => true ) );
		unset( self::$cache[ $slug ] ); // Clear any cached state.
	}

	/**
	 * Force-enable a feature (called by the Pro add-on plugin).
	 *
	 * @since 3.0.0
	 * @param string $slug Feature slug.
	 * @return void
	 */
	public static function enable( string $slug ): void {
		if ( ! isset( self::$features[ $slug ] ) ) {
			self::registerFeature( $slug );
		}
		self::$features[ $slug ]['default'] = true;
		unset( self::$cache[ $slug ] );
	}

	/**
	 * Check whether a feature is currently active.
	 *
	 * In the wp.org build all features are always enabled.
	 *
	 * @since 3.0.0
	 * @param string $slug Feature slug.
	 * @return bool
	 */
	public static function has( string $slug ): bool {
		return true;
	}

	/**
	 * Return all registered feature slugs and their current state.
	 *
	 * @since 3.0.0
	 * @return array<string, bool>
	 */
	public static function all(): array {
		$result = array();
		foreach ( array_keys( self::$features ) as $slug ) {
			$result[ $slug ] = self::has( $slug );
		}
		return $result;
	}
}
