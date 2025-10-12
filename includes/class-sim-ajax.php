<?php
/**
 * Filename: class-sim-ajax.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.0
 * Last Modified: 12/10/2025
 * Description: AJAX handlers for editor modal and bulk processing
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_AJAX {
    
    public static function init() {
        add_action('wp_ajax_sim_find_matches', array(__CLASS__, 'find_matches'));
        add_action('wp_ajax_sim_insert_image', array(__CLASS__, 'insert_image'));
        add_action('wp_ajax_sim_insert_all_images', array(__CLASS__, 'insert_all_images'));
        add_action('wp_ajax_sim_undo_insertions', array(__CLASS__, 'undo_insertions'));
    }
    
    public static function find_matches() {
        check_ajax_referer('sim_editor_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Permission denied', 'smart-image-matcher')));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $mode = isset($_POST['mode']) ? sanitize_text_field($_POST['mode']) : 'keyword';
        
        if (!$post_id) {
            wp_send_json_error(array('message' => __('Invalid post ID', 'smart-image-matcher')));
        }
        
        $matches = SIM_Matcher::find_matches_for_post($post_id, $mode);
        
        if (is_wp_error($matches)) {
            wp_send_json_error(array('message' => $matches->get_error_message()));
        }
        
        global $wpdb;
        $matches_table = $wpdb->prefix . 'sim_matches';
        
        foreach ($matches as $match_group) {
            $heading = $match_group['heading'];
            
            foreach ($match_group['matches'] as $match) {
                $wpdb->insert(
                    $matches_table,
                    array(
                        'post_id' => $post_id,
                        'heading_text' => $heading['text'],
                        'heading_tag' => $heading['tag'],
                        'heading_position' => $heading['position'],
                        'image_id' => $match['image_id'],
                        'confidence_score' => $match['confidence_score'],
                        'match_method' => $match['match_method'],
                        'ai_reasoning' => isset($match['ai_reasoning']) ? $match['ai_reasoning'] : null,
                        'status' => 'pending',
                        'created_at' => current_time('mysql'),
                    ),
                    array('%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s')
                );
            }
        }
        
        wp_send_json_success(array('matches' => $matches));
    }
    
    public static function insert_image() {
        check_ajax_referer('sim_editor_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Permission denied', 'smart-image-matcher')));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $image_id = isset($_POST['image_id']) ? intval($_POST['image_id']) : 0;
        $heading_position = isset($_POST['heading_position']) ? intval($_POST['heading_position']) : 0;
        
        if (!$post_id || !$image_id) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'smart-image-matcher')));
        }
        
        $result = self::insert_image_after_heading($post_id, $image_id, $heading_position);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        global $wpdb;
        $matches_table = $wpdb->prefix . 'sim_matches';
        
        $wpdb->update(
            $matches_table,
            array('status' => 'approved'),
            array(
                'post_id' => $post_id,
                'image_id' => $image_id,
                'heading_position' => $heading_position,
            ),
            array('%s'),
            array('%d', '%d', '%d')
        );
        
        SIM_Cache::clear_post_cache($post_id);
        
        wp_send_json_success(array(
            'message' => __('Image inserted successfully', 'smart-image-matcher'),
            'post_id' => $post_id,
        ));
    }
    
    public static function insert_all_images() {
        check_ajax_referer('sim_editor_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Permission denied', 'smart-image-matcher')));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $insertions = isset($_POST['insertions']) ? json_decode(stripslashes($_POST['insertions']), true) : array();
        
        if (!$post_id || empty($insertions)) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'smart-image-matcher')));
        }
        
        $backup_content = get_post_field('post_content', $post_id);
        set_transient('sim_undo_' . $post_id, $backup_content, 300);
        
        usort($insertions, function($a, $b) {
            return $b['heading_position'] - $a['heading_position'];
        });
        
        $success_count = 0;
        $errors = array();
        
        foreach ($insertions as $insertion) {
            $result = self::insert_image_after_heading(
                $post_id,
                $insertion['image_id'],
                $insertion['heading_position']
            );
            
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $success_count++;
            }
        }
        
        SIM_Cache::clear_post_cache($post_id);
        
        wp_send_json_success(array(
            'message' => sprintf(__('Inserted %d images', 'smart-image-matcher'), $success_count),
            'success_count' => $success_count,
            'errors' => $errors,
        ));
    }
    
    public static function undo_insertions() {
        check_ajax_referer('sim_editor_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array('message' => __('Permission denied', 'smart-image-matcher')));
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        if (!$post_id) {
            wp_send_json_error(array('message' => __('Invalid post ID', 'smart-image-matcher')));
        }
        
        $backup_content = get_transient('sim_undo_' . $post_id);
        
        if ($backup_content === false) {
            wp_send_json_error(array('message' => __('Undo timeout expired', 'smart-image-matcher')));
        }
        
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $backup_content,
        ));
        
        delete_transient('sim_undo_' . $post_id);
        
        SIM_Cache::clear_post_cache($post_id);
        
        wp_send_json_success(array('message' => __('Insertions undone', 'smart-image-matcher')));
    }
    
    private static function insert_image_after_heading($post_id, $image_id, $heading_position) {
        $post = get_post($post_id);
        
        if (!$post) {
            return new WP_Error('invalid_post', __('Invalid post ID', 'smart-image-matcher'));
        }
        
        $content = $post->post_content;
        
        preg_match_all('/<h[2-6][^>]*>.*?<\/h[2-6]>/is', $content, $matches, PREG_OFFSET_CAPTURE);
        
        $heading_end = null;
        foreach ($matches[0] as $match) {
            if ($match[1] == $heading_position) {
                $heading_end = $match[1] + strlen($match[0]);
                break;
            }
        }
        
        if ($heading_end === null) {
            return new WP_Error('heading_not_found', __('Heading not found', 'smart-image-matcher'));
        }
        
        $image_block = self::create_image_block($image_id);
        
        $new_content = substr($content, 0, $heading_end) . "\n\n" . $image_block . "\n\n" . substr($content, $heading_end);
        
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content,
        ));
        
        return true;
    }
    
    private static function create_image_block($image_id) {
        $image = get_post($image_id);
        
        if (!$image) {
            return '';
        }
        
        $image_url = wp_get_attachment_url($image_id);
        $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
        $caption = wp_get_attachment_caption($image_id);
        
        $metadata = wp_get_attachment_metadata($image_id);
        $width = isset($metadata['width']) ? $metadata['width'] : '';
        $height = isset($metadata['height']) ? $metadata['height'] : '';
        
        $block = sprintf(
            '<!-- wp:image {"id":%d,"sizeSlug":"large","linkDestination":"none"} -->',
            $image_id
        );
        $block .= "\n";
        $block .= '<figure class="wp-block-image size-large">';
        $block .= sprintf(
            '<img src="%s" alt="%s" class="wp-image-%d"%s%s/>',
            esc_url($image_url),
            esc_attr($alt_text),
            $image_id,
            $width ? ' width="' . esc_attr($width) . '"' : '',
            $height ? ' height="' . esc_attr($height) . '"' : ''
        );
        
        if (!empty($caption)) {
            $block .= sprintf('<figcaption class="wp-element-caption">%s</figcaption>', wp_kses_post($caption));
        }
        
        $block .= '</figure>';
        $block .= "\n" . '<!-- /wp:image -->';
        
        return $block;
    }
}

