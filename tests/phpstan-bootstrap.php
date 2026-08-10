<?php
/**
 * PHPStan-only bootstrap for project and integration symbols.
 *
 * @package SmartImageMatcher\Tests
 */

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/' );
	}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'DB_NAME' ) ) {
	define( 'DB_NAME', 'wordpress' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'SIM_VERSION' ) ) {
	define( 'SIM_VERSION', '3.0.0' );
}

if ( ! defined( 'SIM_PLUGIN_DIR' ) ) {
	define( 'SIM_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'SIM_PLUGIN_URL' ) ) {
	define( 'SIM_PLUGIN_URL', 'https://example.test/wp-content/plugins/smart-image-matcher/' );
}

if ( ! defined( 'SIM_PLUGIN_BASENAME' ) ) {
	define( 'SIM_PLUGIN_BASENAME', 'smart-image-matcher/smart-image-matcher.php' );
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	/**
	 * Stub for the WordPress AI client integration.
	 *
	 * @param mixed $messages Prompt messages.
	 * @param mixed $args     Request args.
	 * @return mixed
	 */
	function wp_ai_client_prompt( $messages = null, $args = array() ) {
		return '';
	}
}

if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Stub for Action Scheduler enqueue.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Action args.
	 * @param string $group Action group.
	 * @return int
	 */
	function as_enqueue_async_action( $hook, $args = array(), $group = '' ): int {
		return 1;
	}
}

if ( ! function_exists( 'as_has_scheduled_action' ) ) {
	/**
	 * Stub for Action Scheduler lookup.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Action args.
	 * @param string $group Action group.
	 * @return bool
	 */
	function as_has_scheduled_action( $hook, $args = array(), $group = '' ): bool {
		return false;
	}
}

if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
	/**
	 * Stub for Action Scheduler recurring actions.
	 *
	 * @param int    $timestamp Start timestamp.
	 * @param int    $interval  Interval in seconds.
	 * @param string $hook      Hook name.
	 * @param array  $args      Action args.
	 * @param string $group     Action group.
	 * @return int
	 */
	function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '' ): int {
		return 1;
	}
}

	if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	/**
	 * Stub for Action Scheduler unscheduling.
	 *
	 * @param string $hook  Hook name.
	 * @param array  $args  Action args.
	 * @param string $group Action group.
	 * @return void
	 */
		function as_unschedule_all_actions( $hook, $args = array(), $group = '' ): void {}
	}

	if ( ! class_exists( 'LiteSpeed_Cache_API' ) ) {
		class LiteSpeed_Cache_API {
			public static function purge_post( int $postId ): void {}
		}
	}

	if ( ! class_exists( 'Comet_Cache' ) ) {
		class Comet_Cache {
			public static function clear(): void {}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\\format_items' ) ) {
		/**
		 * Stub for WP-CLI table/json formatting.
		 *
		 * @param string $format Output format.
		 * @param array  $items  Rows.
		 * @param array  $fields Field names.
		 * @return void
		 */
		function format_items( string $format, array $items, array $fields ): void {}
	}

	if ( ! function_exists( __NAMESPACE__ . '\\make_progress_bar' ) ) {
		/**
		 * Stub for WP-CLI progress bars.
		 *
		 * @param string $message Progress label.
		 * @param int    $count   Total ticks.
		 * @return object
		 */
		function make_progress_bar( string $message, int $count ): object {
			return new class() {
				public function tick(): void {}
				public function finish(): void {}
			};
		}
	}
}
