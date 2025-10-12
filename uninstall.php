<?php
/**
 * Filename: uninstall.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.0
 * Last Modified: 12/10/2025
 * Description: Complete cleanup on plugin uninstall
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function sim_uninstall_plugin() {
    global $wpdb;
    
    $delete_data = get_option('sim_delete_on_uninstall', 1);
    
    if (!$delete_data) {
        return;
    }
    
    $matches_table = $wpdb->prefix . 'sim_matches';
    $queue_table = $wpdb->prefix . 'sim_queue';
    
    $wpdb->query("DROP TABLE IF EXISTS {$matches_table}");
    $wpdb->query("DROP TABLE IF EXISTS {$queue_table}");
    
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sim_%'");
    
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sim_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sim_%'");
    
    wp_clear_scheduled_hook('sim_daily_cleanup');
    
    $upload_dir = wp_upload_dir();
    $sim_dir = $upload_dir['basedir'] . '/smart-image-matcher';
    
    if (file_exists($sim_dir)) {
        sim_recursive_delete($sim_dir);
    }
    
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
}

function sim_recursive_delete($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), array('.', '..'));
    
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        
        if (is_dir($path)) {
            sim_recursive_delete($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

sim_uninstall_plugin();

