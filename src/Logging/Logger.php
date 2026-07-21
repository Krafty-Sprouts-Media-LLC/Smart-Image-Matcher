<?php
/**
 * Structured logger.
 *
 * Respects both WP_DEBUG_LOG and the user's smart_image_matcher_debug_mode setting.
 * Produces structured key=value log lines prefixed with [SIM].
 *
 * @package SmartImageMatcher\Logging
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Logging;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 *
 * @since 3.0.0
 */
class Logger {

	/**
	 * Whether debug logging is active for this request.
	 *
	 * @var bool|null
	 */
	private static ?bool $active = null;

	/**
	 * Log an informational message.
	 *
	 * @since 3.0.0
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Optional key-value context.
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {
		self::write( 'INFO', $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @since 3.0.0
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Optional key-value context.
	 * @return void
	 */
	public static function warn( string $message, array $context = array() ): void {
		self::write( 'WARN', $message, $context );
	}

	/**
	 * Log an error.
	 *
	 * @since 3.0.0
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Optional key-value context.
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::write( 'ERROR', $message, $context );
	}

	/**
	 * Whether debug logging is currently active.
	 *
	 * @since 3.0.0
	 * @return bool
	 */
	public static function isDebugMode(): bool {
		if ( null === self::$active ) {
			self::$active = defined( 'WP_DEBUG' ) && WP_DEBUG
				&& defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG
				&& (bool) \SmartImageMatcher\Settings\Settings::get( 'debug_mode' );
		}
		return self::$active;
	}

	/**
	 * Write a log entry.
	 *
	 * @since 3.0.0
	 * @param string               $level   Log level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context pairs.
	 * @return void
	 */
	private static function write( string $level, string $message, array $context ): void {
		if ( ! self::isDebugMode() ) {
			return;
		}

		$line = "[SIM {$level}] {$message}";

		if ( ! empty( $context ) ) {
			$pairs = array();
			foreach ( $context as $k => $v ) {
				$pairs[] = "{$k}=" . ( is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) );
			}
			$line .= ' ' . implode( ' ', $pairs );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}
}
