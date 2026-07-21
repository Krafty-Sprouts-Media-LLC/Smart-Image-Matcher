<?php
/**
 * PSR-4 autoloader for SmartImageMatcher.
 *
 * Maps SmartImageMatcher\ to the src/ directory.
 * No Composer required in production.
 *
 * @package SmartImageMatcher
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix   = 'SmartImageMatcher\\';
		$base_dir = __DIR__ . '/';
		$len      = strlen( $prefix );

		if ( strncmp( $class, $prefix, $len ) !== 0 ) {
			return;
		}

		$file = $base_dir . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);
