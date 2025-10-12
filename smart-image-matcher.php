<?php
/**
 * Plugin Name: Smart Image Matcher
 * Plugin URI: https://kraftysprouts.com
 * Description: Automatically scans the media library and intelligently attaches relevant images to headings within posts and pages. Offers keyword-based and AI-powered matching.
 * Version: 1.0.6
 * Author: Krafty Sprouts Media, LLC
 * Author URI: https://kraftysprouts.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-image-matcher
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Filename: smart-image-matcher.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.6
 * Last Modified: 12/10/2025
 * Description: Main plugin file for Smart Image Matcher
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SIM_VERSION', '1.0.6');
define('SIM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SIM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SIM_PLUGIN_BASENAME', plugin_basename(__FILE__));

define('SIM_MAX_API_CALLS_PER_HOUR', 50);
define('SIM_MAX_API_CALLS_PER_DAY', 500);

require_once SIM_PLUGIN_DIR . 'includes/class-sim-core.php';
require_once SIM_PLUGIN_DIR . 'includes/class-sim-matcher.php';
require_once SIM_PLUGIN_DIR . 'includes/class-sim-ai.php';
require_once SIM_PLUGIN_DIR . 'includes/class-sim-admin.php';
require_once SIM_PLUGIN_DIR . 'includes/class-sim-ajax.php';
require_once SIM_PLUGIN_DIR . 'includes/class-sim-bulk.php';
require_once SIM_PLUGIN_DIR . 'includes/class-sim-settings.php';
require_once SIM_PLUGIN_DIR . 'includes/class-sim-cache.php';

register_activation_hook(__FILE__, 'sim_activate_plugin');
register_deactivation_hook(__FILE__, 'sim_deactivate_plugin');

function sim_activate_plugin() {
    if (version_compare(get_bloginfo('version'), '6.0', '<')) {
        wp_die(esc_html__('Smart Image Matcher requires WordPress 6.0 or higher.', 'smart-image-matcher'));
    }
    
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        wp_die(esc_html__('Smart Image Matcher requires PHP 7.4 or higher.', 'smart-image-matcher'));
    }
    
    sim_create_database_tables();
    sim_set_default_options();
    
    if (!wp_next_scheduled('sim_daily_cleanup')) {
        wp_schedule_event(time(), 'daily', 'sim_daily_cleanup');
    }
    
    flush_rewrite_rules();
}

function sim_deactivate_plugin() {
    wp_clear_scheduled_hook('sim_daily_cleanup');
    
    SIM_Cache::clear_all_transients();
}

function sim_create_database_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $matches_table = $wpdb->prefix . 'sim_matches';
    $queue_table = $wpdb->prefix . 'sim_queue';
    
    $matches_sql = "CREATE TABLE IF NOT EXISTS {$matches_table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        heading_text VARCHAR(255) NOT NULL,
        heading_tag VARCHAR(10) NOT NULL,
        heading_position INT NOT NULL,
        image_id BIGINT UNSIGNED NOT NULL,
        confidence_score INT NOT NULL,
        match_method VARCHAR(20) NOT NULL,
        ai_reasoning TEXT,
        status VARCHAR(20) DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        INDEX post_id_idx (post_id),
        INDEX status_idx (status)
    ) {$charset_collate};";
    
    $queue_sql = "CREATE TABLE IF NOT EXISTS {$queue_table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) DEFAULT 'queued',
        priority INT DEFAULT 0,
        attempts INT DEFAULT 0,
        error_message TEXT,
        processed_at DATETIME,
        created_at DATETIME NOT NULL,
        INDEX status_idx (status),
        INDEX post_id_idx (post_id)
    ) {$charset_collate};";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($matches_sql);
    dbDelta($queue_sql);
}

function sim_set_default_options() {
    $defaults = array(
        'sim_match_mode' => 'keyword',
        'sim_confidence_threshold' => 70,
        'sim_hierarchy_mode' => 'smart',
        'sim_heading_overlap_threshold' => 70,
        'sim_minimum_image_spacing' => 300,
        'sim_claude_api_key' => '',
        'sim_claude_model' => 'claude-sonnet-4-20250514',
        'sim_daily_spending_limit' => 10.00,
        'sim_batch_size_limit' => 50,
        'sim_cost_warnings' => true,
        'sim_email_notifications' => true,
        'sim_auto_fallback_keyword' => true,
        'sim_delete_on_uninstall' => true,
        'sim_cache_media_library_duration' => 86400,
        'sim_cache_match_results_duration' => 3600,
    );
    
    foreach ($defaults as $option => $value) {
        if (get_option($option) === false) {
            add_option($option, $value);
        }
    }
}

function sim_init() {
    SIM_Core::get_instance();
}
add_action('plugins_loaded', 'sim_init');

add_action('sim_daily_cleanup', 'sim_daily_cleanup_task');
function sim_daily_cleanup_task() {
    global $wpdb;
    
    $matches_table = $wpdb->prefix . 'sim_matches';
    $queue_table = $wpdb->prefix . 'sim_queue';
    
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$matches_table} WHERE status = %s AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
        'rejected'
    ));
    
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$queue_table} WHERE status = %s AND processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
        'completed'
    ));
    
    SIM_Cache::clear_expired_transients();
}

