<?php
/**
 * Structured logger.
 *
 * Info/warn respect WP_DEBUG_LOG and the smart_image_matcher_debug_mode setting.
 * Errors always persist to the runtime option (Dashboard) so production
 * sites without debug.log still have a trail. error_log() is also called
 * when PHP logging is already on; it is not required.
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
		self::write( 'ERROR', $message, $context, true );
		self::persistError( $message, $context );
	}

	/**
	 * Recent errors stored without debug.log (newest first).
	 *
	 * @since 3.4.5
	 * @return array<int, array{at:string,message:string,context:array<string,string>}>
	 */
	public static function getRecentErrors(): array {
		$runtime = get_option( \SmartImageMatcher\Settings\Settings::RUNTIME_OPTION, array() );
		if ( ! is_array( $runtime ) || empty( $runtime['recent_errors'] ) || ! is_array( $runtime['recent_errors'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $runtime['recent_errors'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['message'] ) ) {
				continue;
			}
			$ctx = array();
			if ( isset( $row['context'] ) && is_array( $row['context'] ) ) {
				foreach ( $row['context'] as $key => $value ) {
					$ctx[ (string) $key ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
				}
			}
			$out[] = array(
				'at'      => isset( $row['at'] ) ? (string) $row['at'] : '',
				'message' => (string) $row['message'],
				'context' => $ctx,
			);
		}

		return $out;
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
	 * @param bool                 $force   When true, always write (errors).
	 * @return void
	 */
	private static function write( string $level, string $message, array $context, bool $force = false ): void {
		if ( ! $force && ! self::isDebugMode() ) {
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

		if ( $force ) {
			self::writePhpLog( $line );
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}

	/**
	 * Keep the last 20 errors in the runtime option (autoload=no).
	 *
	 * @since 3.4.5
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context pairs.
	 * @return void
	 */
	private static function persistError( string $message, array $context ): void {
		$runtime = get_option( \SmartImageMatcher\Settings\Settings::RUNTIME_OPTION, array() );
		$runtime = is_array( $runtime ) ? $runtime : array();
		$errors  = isset( $runtime['recent_errors'] ) && is_array( $runtime['recent_errors'] )
			? $runtime['recent_errors']
			: array();

		$ctx = array();
		foreach ( $context as $key => $value ) {
			$ctx[ (string) $key ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		}

		array_unshift(
			$errors,
			array(
				'at'      => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
				'message' => $message,
				'context' => $ctx,
			)
		);

		$runtime['recent_errors'] = array_slice( $errors, 0, 20 );
		update_option( \SmartImageMatcher\Settings\Settings::RUNTIME_OPTION, $runtime, false );
	}

	/**
	 * Write to PHP's error log only when the host already logs.
	 *
	 * @since 3.4.5
	 * @param string $line Formatted line.
	 * @return void
	 */
	private static function writePhpLog( string $line ): void {
		if ( ! self::isDebugMode() && ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}
}
