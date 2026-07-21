<?php
/**
 * Uninstall handler for Smart Image Matcher.
 *
 * Cron events are ALWAYS cleared.
 * Data (tables, options, transients) is only deleted when the user opted in.
 *
 * @package SmartImageMatcher
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Uninstall script variables are file-local.

// Always clear scheduled hooks so they don't fire after deletion.
wp_clear_scheduled_hook( 'smart_image_matcher_daily_cleanup' );
wp_clear_scheduled_hook( 'smart_image_matcher_fiaa_cron_run' );
wp_clear_scheduled_hook( 'smart_image_matcher_fiaa_scheduled_run' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	$smart_image_matcher_action_hooks = array(
		'smart_image_matcher_queue_ai_match',
		'smart_image_matcher_queue_index_backfill',
		'smart_image_matcher_queue_bulk_match',
		'smart_image_matcher_queue_bulk_insert',
		'smart_image_matcher_queue_fiaa_run',
		'smart_image_matcher_queue_fiaa_audit_clear',
		'smart_image_matcher_fiaa_scheduled_run',
	);

	foreach ( $smart_image_matcher_action_hooks as $smart_image_matcher_action_hook ) {
		as_unschedule_all_actions( $smart_image_matcher_action_hook, array(), 'smart-image-matcher' );
	}
}

// Respect the user's data-retention preference.
$settings = get_option( 'smart_image_matcher_settings', array() );
$delete   = is_array( $settings ) ? (bool) ( $settings['delete_on_uninstall'] ?? true ) : true;

if ( ! $delete ) {
	return;
}

// TODO: Phase 1 — replace inline SQL with Migrator::uninstall() call.
global $wpdb;

$tables = array(
	esc_sql( $wpdb->prefix . 'smart_image_matcher_matches' ),
	esc_sql( $wpdb->prefix . 'smart_image_matcher_queue' ),
	esc_sql( $wpdb->prefix . 'smart_image_matcher_image_terms' ),
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Delete all smart_image_matcher_* options and transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'smart_image_matcher_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_smart_image_matcher_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_smart_image_matcher_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared

wp_cache_flush();

// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
