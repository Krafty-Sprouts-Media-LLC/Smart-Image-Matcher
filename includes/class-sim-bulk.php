<?php
/**
 * Filename: class-sim-bulk.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.1
 * Last Modified: 12/10/2025
 * Description: Bulk processing functionality for multiple posts with render method
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_Bulk {
    
    public static function add_posts_to_queue($post_ids, $priority = 0) {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'sim_queue';
        
        foreach ($post_ids as $post_id) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$queue_table} WHERE post_id = %d AND status IN ('queued', 'processing')",
                $post_id
            ));
            
            if ($existing) {
                continue;
            }
            
            $wpdb->insert(
                $queue_table,
                array(
                    'post_id' => $post_id,
                    'status' => 'queued',
                    'priority' => $priority,
                    'attempts' => 0,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%d', '%d', '%s')
            );
        }
        
        return true;
    }
    
    public static function process_queue_item() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'sim_queue';
        
        $item = $wpdb->get_row(
            "SELECT * FROM {$queue_table} 
             WHERE status = 'queued' 
             ORDER BY priority DESC, created_at ASC 
             LIMIT 1"
        );
        
        if (!$item) {
            return false;
        }
        
        $wpdb->update(
            $queue_table,
            array('status' => 'processing'),
            array('id' => $item->id),
            array('%s'),
            array('%d')
        );
        
        $mode = get_option('sim_match_mode', 'keyword');
        $matches = SIM_Matcher::find_matches_for_post($item->post_id, $mode);
        
        if (is_wp_error($matches)) {
            $wpdb->update(
                $queue_table,
                array(
                    'status' => 'failed',
                    'attempts' => $item->attempts + 1,
                    'error_message' => $matches->get_error_message(),
                    'processed_at' => current_time('mysql'),
                ),
                array('id' => $item->id),
                array('%s', '%d', '%s', '%s'),
                array('%d')
            );
            
            return new WP_Error('processing_failed', $matches->get_error_message());
        }
        
        $wpdb->update(
            $queue_table,
            array(
                'status' => 'completed',
                'processed_at' => current_time('mysql'),
            ),
            array('id' => $item->id),
            array('%s', '%s'),
            array('%d')
        );
        
        return array(
            'post_id' => $item->post_id,
            'matches' => $matches,
        );
    }
    
    public static function get_queue_status() {
        global $wpdb;
        
        $queue_table = $wpdb->prefix . 'sim_queue';
        
        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count 
             FROM {$queue_table} 
             GROUP BY status"
        );
        
        $status = array(
            'queued' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
        );
        
        foreach ($stats as $stat) {
            $status[$stat->status] = intval($stat->count);
        }
        
        return $status;
    }
    
    public static function get_pending_matches($limit = 50) {
        global $wpdb;
        
        $matches_table = $wpdb->prefix . 'sim_matches';
        
        $matches = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$matches_table} 
             WHERE status = 'pending' 
             ORDER BY created_at DESC 
             LIMIT %d",
            $limit
        ));
        
        return $matches;
    }
    
    public static function render_bulk_processor_page() {
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'smart-image-matcher'));
        }
        
        require_once SIM_PLUGIN_DIR . 'admin/views/bulk-processor.php';
    }
}

