<?php
/**
 * PHPUnit bootstrap for Smart Image Matcher.
 *
 * Two modes:
 *   1. Unit-only (WP_TESTS_DIR not set or not present) — loads just the
 *      plugin autoloader so pure-PHP unit tests can run without WordPress.
 *   2. Full integration (WP_TESTS_DIR present) — loads the WordPress test
 *      environment so integration tests can use WP factories and DB.
 *
 * @package SmartImageMatcher\Tests
 */

// Always load Composer autoloader (gives us plugin classes + dev deps).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// Load WordPress test environment only when available.
$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( file_exists( $tests_dir . '/includes/functions.php' ) ) {
	// Full WP integration mode.
	require_once $tests_dir . '/includes/functions.php';

	function sim_manually_load_plugin(): void {
		require dirname( __DIR__ ) . '/smart-image-matcher.php';
	}
	tests_add_filter( 'muplugins_loaded', 'sim_manually_load_plugin' );

	require $tests_dir . '/includes/bootstrap.php';
} else {
	// Unit-only mode — define the WP stubs PHPUnit needs so pure classes load.
	// WP functions are stubbed by szepeviktor/phpstan-wordpress stubs at
	// runtime via the phpstan.neon.dist; for PHPUnit we define the minimal
	// surface that our unit-tested classes call at construction time.
	if ( ! function_exists( 'add_action' ) ) {
		function add_action() {}
		function apply_filters( $tag, $value ) { return $value; }
		// Stateful in-memory option store so tests can round-trip
		// get_option()/update_option()/delete_option() calls.
		function get_option( $option, $default = false ) {
			return $GLOBALS['sim_test_options'][ $option ] ?? $default;
		}
		function update_option( $option, $value, $autoload = null ) {
			$GLOBALS['sim_test_options'][ $option ] = $value;
			return true;
		}
		function delete_option( $option ) {
			unset( $GLOBALS['sim_test_options'][ $option ] );
			return true;
		}
		function wp_json_encode( $data ) { return json_encode( $data ); }
		function current_user_can() { return true; }
		function wp_attachment_is_image() { return true; }
		function __( $text ) { return $text; }
		function esc_html__( $text ) { return $text; }
		function sanitize_text_field( $str ) { return $str; }
		function sanitize_textarea_field( $str ) { return $str; }
		function sanitize_mime_type( $mime ) { return preg_replace( '/[^a-z0-9.+-\/]/i', '', (string) $mime ); }
		function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
		function absint( $value ) { return abs( (int) $value ); }
		function get_post_stati( $args = array(), $output = 'names' ) {
			$stati = array( 'publish', 'draft', 'pending', 'future', 'private' );
			if ( 'names' === $output ) {
				return array_combine( $stati, $stati );
			}
			$objects = array();
			foreach ( $stati as $slug ) {
				$obj        = new stdClass();
				$obj->label = ucfirst( $slug );
				$objects[ $slug ] = $obj;
			}
			return $objects;
		}
		function wp_strip_all_tags( $string ) { return strip_tags( $string ); }
		function sim_html_entity_decode( $string, $flags = ENT_QUOTES, $enc = 'UTF-8' ) {
			return html_entity_decode( $string, $flags, $enc );
		}
		function esc_url( $url ) { return htmlspecialchars( $url, ENT_QUOTES ); }
		function esc_url_raw( $url ) { return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : ''; }
		function esc_attr( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
		function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES ); }
		function wp_kses_post( $data ) { return $data; }
		function wp_parse_args( $args, $defaults = array() ) {
			if ( is_array( $args ) ) {
				return array_merge( $defaults, $args );
			}
			return $defaults;
		}
		function serialize_blocks( $blocks ) { return ''; }
		function wp_unslash( $value ) { return stripslashes_deep( $value ); }
		function stripslashes_deep( $value ) {
			return is_array( $value ) ? array_map( 'stripslashes_deep', $value ) : stripslashes( $value );
		}
		function parse_blocks( $content ) { return []; }
		function has_blocks( $content ) { return strpos( $content, '<!-- wp:' ) !== false; }
		function wp_get_attachment_url() { return ''; }
		function get_post_meta( $post_id, $key = '', $single = false ) {
			$value = $GLOBALS['sim_test_post_meta'][ (int) $post_id ][ (string) $key ] ?? null;
			return $single ? ( $value ?? '' ) : ( null === $value ? array() : array( $value ) );
		}
		function update_post_meta( $post_id, $key, $value ) {
			$GLOBALS['sim_test_post_meta'][ (int) $post_id ][ (string) $key ] = $value;
			return true;
		}
		function delete_post_meta( $post_id, $key ) {
			unset( $GLOBALS['sim_test_post_meta'][ (int) $post_id ][ (string) $key ] );
			return true;
		}
		function set_transient( $key, $value, $expiration = 0 ) {
			unset( $expiration );
			$GLOBALS['sim_test_transients'][ (string) $key ] = $value;
			return true;
		}
		function get_transient( $key ) {
			return $GLOBALS['sim_test_transients'][ (string) $key ] ?? false;
		}
		function delete_transient( $key ) {
			unset( $GLOBALS['sim_test_transients'][ (string) $key ] );
			return true;
		}
		function get_the_title() { return ''; }
		function wp_get_attachment_caption() { return ''; }
		function get_attached_file() { return ''; }
		function wp_update_post() { return 1; }
		function get_post() { return null; }
		function clean_post_cache() {}
		function rest_ensure_response( $data ) { return $data; }
		function set_post_thumbnail() {}
		function metadata_exists() { return false; }
		function get_metadata_raw() { return null; }
		function register_rest_route() {}
		// Action Scheduler stubs record their calls into globals so tests
		// can assert on scheduling/cancellation behaviour without a real
		// Action Scheduler data store.
		function as_enqueue_async_action( $hook, $args = array(), $group = '' ) {
			$GLOBALS['sim_test_as_enqueued'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group );
			return 1;
		}
		function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
			if ( isset( $GLOBALS['sim_test_as_has_scheduled'] ) && is_callable( $GLOBALS['sim_test_as_has_scheduled'] ) ) {
				return $GLOBALS['sim_test_as_has_scheduled']( $hook, $args, $group );
			}
			return false;
		}
		function function_exists_as( $name ) { return false; }
		function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '' ) {
			$GLOBALS['sim_test_as_scheduled'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group );
			return 1;
		}
		function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
			$GLOBALS['sim_test_as_unscheduled'][] = array( 'hook' => $hook, 'args' => $args, 'group' => $group );
		}
		function wp_clear_scheduled_hook( $hook ) {
			$GLOBALS['sim_test_wp_cron_cleared'][] = $hook;
		}
		function current_time( $type = 'mysql' ) { return '2000-01-01 00:00:00'; }
		// get_posts is deliberately overridable per-test via this global
		// callable hook — unit tests set $GLOBALS['sim_test_get_posts'] to
		// a closure that inspects the query args and returns attachment IDs.
		function get_posts( $args = array() ) {
			if ( isset( $GLOBALS['sim_test_get_posts'] ) && is_callable( $GLOBALS['sim_test_get_posts'] ) ) {
				return $GLOBALS['sim_test_get_posts']( $args );
			}
			return array();
		}

		if ( ! class_exists( 'WP_Error' ) ) {
			class WP_Error {
				private $code, $message;
				public function __construct( $code = '', $message = '' ) {
					$this->code    = $code;
					$this->message = $message;
				}
				public function get_error_message() { return $this->message; }
				public function get_error_code()    { return $this->code; }
			}
		}
		if ( ! function_exists( 'is_wp_error' ) ) {
			function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
		}
		if ( ! function_exists( 'esc_sql' ) ) {
			function esc_sql( $data ) { return addslashes( $data ); }
		}

		if ( ! class_exists( 'SimTestWpdb' ) ) {
			/**
			 * Minimal in-memory $wpdb stand-in for unit tests that touch
			 * ImageRepository::indexImage()/removeImage(). Records calls
			 * instead of hitting a real database.
			 */
			class SimTestWpdb {
				public string $prefix = 'wp_';
				/** @var array<int, array<string, mixed>> */
				public array $deletes = array();
				/** @var array<int, string> */
				public array $queries = array();

				public function delete( $table, $where, $format = null ) {
					$this->deletes[] = array( 'table' => $table, 'where' => $where );
					return 1;
				}

				public function prepare( $query, ...$args ) {
					return $query;
				}

				public function query( $query ) {
					$this->queries[] = (string) $query;
					return 1;
				}
			}
		}

		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			$GLOBALS['wpdb'] = new SimTestWpdb();
		}

		if ( ! class_exists( 'WP_Post' ) ) {
			class WP_Post {
				public $ID = 0;
				public $post_content = '';
				public $post_title   = '';
				public $post_modified_gmt = '2000-01-01 00:00:00';
				public $post_name   = '';
				public $post_type   = 'post';
				public $post_status = 'publish';
				public $post_parent = 0;
				public $post_excerpt = '';
			}
		}
	}
}
