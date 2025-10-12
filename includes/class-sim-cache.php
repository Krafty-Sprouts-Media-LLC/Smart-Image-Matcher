<?php
/**
 * Filename: class-sim-cache.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.0
 * Last Modified: 12/10/2025
 * Description: Cache management and compatibility for major WordPress cache plugins
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_Cache {
    
    public static function clear_post_cache($post_id) {
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'posts');
        
        delete_transient('sim_matches_' . $post_id);
        
        self::clear_plugin_caches($post_id);
    }
    
    public static function clear_plugin_caches($post_id) {
        if (function_exists('rocket_clean_post')) {
            rocket_clean_post($post_id);
        }
        
        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($post_id);
        }
        
        if (function_exists('wp_cache_post_change')) {
            wp_cache_post_change($post_id);
        }
        
        if (function_exists('wpfc_clear_post_cache_by_id')) {
            wpfc_clear_post_cache_by_id($post_id);
        }
        
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_post')) {
            LiteSpeed_Cache_API::purge_post($post_id);
        }
        
        if (function_exists('autoptimize_flush_pagecache')) {
            autoptimize_flush_pagecache();
        }
        
        if (class_exists('Comet_Cache') && method_exists('Comet_Cache', 'clear')) {
            Comet_Cache::clear();
        }
        
        if (function_exists('wpo_cache_flush')) {
            wpo_cache_flush();
        }
        
        do_action('sim_clear_post_cache', $post_id);
    }
    
    public static function clear_all_transients() {
        global $wpdb;
        
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_sim_%' 
             OR option_name LIKE '_transient_timeout_sim_%'"
        );
        
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
    
    public static function clear_expired_transients() {
        global $wpdb;
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE %s 
                 AND option_value < %d",
                '_transient_timeout_sim_%',
                time()
            )
        );
    }
    
    public static function get_cached_media_library() {
        $cache_key = 'sim_media_library_cache';
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }
        
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
        );
        
        $images = get_posts($args);
        
        $media_library = array();
        
        foreach ($images as $image_id) {
            $media_library[$image_id] = array(
                'id' => $image_id,
                'filename' => basename(get_attached_file($image_id)),
                'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                'title' => get_the_title($image_id),
                'caption' => wp_get_attachment_caption($image_id),
                'url' => wp_get_attachment_url($image_id),
            );
        }
        
        $duration = get_option('sim_cache_media_library_duration', 86400);
        set_transient($cache_key, $media_library, $duration);
        
        return $media_library;
    }
    
    public static function invalidate_media_library_cache() {
        delete_transient('sim_media_library_cache');
    }
}

