<?php
/**
 * Filename: class-sim-ajax.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.4.0
 * Last Modified: 12/10/2025
 * Description: AJAX handlers for editor modal and bulk processing
 * Includes comprehensive error logging for debugging insertion issues
 * Optimized bulk insert to create ONE revision instead of multiple
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_AJAX {
    
    public static function init() {
        add_action('wp_ajax_sim_find_matches', array(__CLASS__, 'find_matches'));
        add_action('wp_ajax_sim_insert_image', array(__CLASS__, 'insert_image'));
        add_action('wp_ajax_sim_insert_all_images', array(__CLASS__, 'insert_all_images'));
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
        
        error_log('SIM: Insert image request - Post ID: ' . $post_id . ', Image ID: ' . $image_id . ', Position: ' . $heading_position);
        
        if (!$post_id || !$image_id) {
            error_log('SIM: Invalid parameters - Post ID or Image ID missing');
            wp_send_json_error(array('message' => __('Invalid parameters', 'smart-image-matcher')));
        }
        
        // Verify post exists
        $post = get_post($post_id);
        if (!$post) {
            error_log('SIM: Post not found - ID: ' . $post_id);
            wp_send_json_error(array('message' => __('Post not found', 'smart-image-matcher')));
        }
        
        // Verify image exists
        $image = get_post($image_id);
        if (!$image || $image->post_type !== 'attachment') {
            error_log('SIM: Image not found - ID: ' . $image_id);
            wp_send_json_error(array('message' => __('Image not found', 'smart-image-matcher')));
        }
        
        $result = self::insert_image_after_heading($post_id, $image_id, $heading_position);
        
        if (is_wp_error($result)) {
            error_log('SIM: Insert failed - ' . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        error_log('SIM: Image inserted successfully');
        
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
        
        // Final verification - get fresh copy from database
        wp_cache_flush();
        $final_post = get_post($post_id);
        $final_content = $final_post->post_content;
        
        // Check if image block is actually in the content
        $image_block_exists = (strpos($final_content, 'wp:image') !== false && strpos($final_content, 'wp-image-' . $image_id) !== false);
        
        error_log('SIM: Final verification - Image block exists in DB: ' . ($image_block_exists ? 'YES' : 'NO'));
        error_log('SIM: Final content length: ' . strlen($final_content));
        
        wp_send_json_success(array(
            'message' => __('Image inserted successfully', 'smart-image-matcher'),
            'post_id' => $post_id,
            'inserted' => true,
            'debug' => array(
                'original_length' => strlen($content),
                'new_length' => strlen($final_content),
                'image_exists' => $image_block_exists,
                'image_id' => $image_id,
            ),
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
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(array('message' => __('Post not found', 'smart-image-matcher')));
        }
        
        // Sort bottom to top to preserve positions
        usort($insertions, function($a, $b) {
            return $b['heading_position'] - $a['heading_position'];
        });
        
        $content = $post->post_content;
        $original_content = $content;
        $has_blocks = has_blocks($content);
        
        error_log('SIM: Bulk insert - Processing ' . count($insertions) . ' images in ONE update');
        
        // Filter out duplicates before processing
        $valid_insertions = array();
        foreach ($insertions as $insertion) {
            if (!self::image_exists_in_content($content, $insertion['image_id'], $insertion['heading_position'])) {
                $valid_insertions[] = $insertion;
            } else {
                error_log('SIM: Skipping duplicate image ID ' . $insertion['image_id'] . ' at position ' . $insertion['heading_position']);
            }
        }
        
        if (empty($valid_insertions)) {
            wp_send_json_error(array('message' => __('All images already exist in content', 'smart-image-matcher')));
        }
        
        // Process all insertions into content (Gutenberg or Classic)
        if ($has_blocks) {
            $content = self::bulk_insert_gutenberg($content, $valid_insertions);
        } else {
            $content = self::bulk_insert_html($content, $valid_insertions);
        }
        
        if (empty($content) || $content === $original_content) {
            error_log('SIM: Bulk insert failed - content unchanged');
            wp_send_json_error(array('message' => __('Failed to insert images', 'smart-image-matcher')));
        }
        
        error_log('SIM: Content updated, calling wp_update_post ONCE for ' . count($valid_insertions) . ' images');
        
        // ONE update for all images - creates ONE revision
        $update_result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $content,
        ), true);
        
        if (is_wp_error($update_result)) {
            error_log('SIM: wp_update_post failed: ' . $update_result->get_error_message());
            wp_send_json_error(array('message' => $update_result->get_error_message()));
        }
        
        // Update match statuses
        global $wpdb;
        $matches_table = $wpdb->prefix . 'sim_matches';
        foreach ($valid_insertions as $insertion) {
            $wpdb->update(
                $matches_table,
                array('status' => 'approved'),
                array(
                    'post_id' => $post_id,
                    'image_id' => $insertion['image_id'],
                    'heading_position' => $insertion['heading_position'],
                ),
                array('%s'),
                array('%d', '%d', '%d')
            );
        }
        
        SIM_Cache::clear_post_cache($post_id);
        
        error_log('SIM: Bulk insert succeeded - ONE revision created for ' . count($valid_insertions) . ' images');
        
        wp_send_json_success(array(
            'message' => sprintf(__('Inserted %d images', 'smart-image-matcher'), count($valid_insertions)),
            'success_count' => count($valid_insertions),
            'errors' => array(),
        ));
    }
    
    
    private static function insert_image_after_heading($post_id, $image_id, $heading_position) {
        $post = get_post($post_id);
        
        if (!$post) {
            error_log('SIM: insert_image_after_heading - Post not found: ' . $post_id);
            return new WP_Error('invalid_post', __('Invalid post ID', 'smart-image-matcher'));
        }
        
        $content = $post->post_content;
        error_log('SIM: Original content length: ' . strlen($content));
        
        // Check for duplicate - if image already exists in content, skip insertion
        if (self::image_exists_in_content($content, $image_id, $heading_position)) {
            error_log('SIM: Image ID ' . $image_id . ' already exists in content near position ' . $heading_position . ' - skipping duplicate');
            return new WP_Error('duplicate_image', __('Image already exists in this location', 'smart-image-matcher'));
        }
        
        // Parse blocks if this is Gutenberg content
        $has_blocks = has_blocks($content);
        error_log('SIM: Content has Gutenberg blocks: ' . ($has_blocks ? 'YES' : 'NO'));
        
        if ($has_blocks) {
            // Use WordPress block parser for Gutenberg
            $blocks = parse_blocks($content);
            error_log('SIM: Parsed ' . count($blocks) . ' blocks');
            
            // Find the heading block and insert image after it
            $inserted = false;
            $new_blocks = array();
            
            foreach ($blocks as $block) {
                $new_blocks[] = $block;
                
                // Check if this is a heading block at the target position
                if (!$inserted && isset($block['blockName']) && $block['blockName'] === 'core/heading') {
                    $block_html = render_block($block);
                    $block_position = strpos($content, $block_html);
                    
                    if ($block_position !== false && abs($block_position - $heading_position) < 50) {
                        // Insert image block after this heading
                        $image_block = self::create_gutenberg_image_block($image_id);
                        $new_blocks[] = $image_block;
                        $inserted = true;
                        error_log('SIM: Inserted image block after Gutenberg heading');
                    }
                }
            }
            
            if ($inserted) {
                // Serialize blocks back to content
                $new_content = serialize_blocks($new_blocks);
            } else {
                error_log('SIM: Could not find Gutenberg heading, falling back to HTML insertion');
                $new_content = self::insert_via_html($content, $image_id, $heading_position);
            }
        } else {
            // Classic editor or HTML content
            error_log('SIM: Using HTML insertion for Classic Editor');
            $new_content = self::insert_via_html($content, $image_id, $heading_position);
        }
        
        if (empty($new_content) || $new_content === $content) {
            error_log('SIM: Content unchanged after insertion attempt');
            return new WP_Error('insertion_failed', __('Failed to insert image', 'smart-image-matcher'));
        }
        
        error_log('SIM: New content length: ' . strlen($new_content));
        
        // Update post with WordPress function
        $update_result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $new_content,
        ), true);
        
        if (is_wp_error($update_result)) {
            error_log('SIM: wp_update_post failed: ' . $update_result->get_error_message());
            return $update_result;
        }
        
        error_log('SIM: wp_update_post succeeded');
        
        // Clear caches using WordPress functions
        clean_post_cache($post_id);
        
        return true;
    }
    
    private static function insert_via_html($content, $image_id, $heading_position) {
        // HTML-based insertion for Classic Editor
        preg_match_all('/<h[2-6][^>]*>.*?<\/h[2-6]>/is', $content, $matches, PREG_OFFSET_CAPTURE);
        
        $heading_end = null;
        foreach ($matches[0] as $match) {
            if ($match[1] == $heading_position) {
                $heading_end = $match[1] + strlen($match[0]);
                break;
            }
        }
        
        if ($heading_end === null) {
            return $content;
        }
        
        $image_block = self::create_image_block($image_id);
        return substr($content, 0, $heading_end) . "\n\n" . $image_block . "\n\n" . substr($content, $heading_end);
    }
    
    private static function create_gutenberg_image_block($image_id) {
        $image_url = wp_get_attachment_url($image_id);
        $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
        $caption = wp_get_attachment_caption($image_id);
        
        // Build block attributes - ONLY id, sizeSlug, linkDestination (no width/height)
        $attrs = array(
            'id' => $image_id,
            'sizeSlug' => 'large',
            'linkDestination' => 'none',
        );
        
        // Create img tag WITHOUT width/height - Gutenberg handles via sizeSlug
        $img_html = sprintf(
            '<img src="%s" alt="%s" class="wp-image-%d"/>',
            esc_url($image_url),
            esc_attr($alt_text),
            $image_id
        );
        
        // Wrap in figure with optional caption
        $innerHTML = '<figure class="wp-block-image size-large">' . $img_html;
        if (!empty($caption)) {
            $innerHTML .= '<figcaption class="wp-element-caption">' . wp_kses_post($caption) . '</figcaption>';
        }
        $innerHTML .= '</figure>';
        
        // Create proper Gutenberg block array for parse_blocks/serialize_blocks
        $block = array(
            'blockName' => 'core/image',
            'attrs' => $attrs,
            'innerBlocks' => array(),
            'innerHTML' => $innerHTML,
            'innerContent' => array($innerHTML),
        );
        
        error_log('SIM: Created Gutenberg block array without width/height in attrs or img');
        
        return $block;
    }
    
    private static function create_image_block($image_id) {
        $image = get_post($image_id);
        
        if (!$image) {
            error_log('SIM: Image not found for ID: ' . $image_id);
            return '';
        }
        
        $image_url = wp_get_attachment_url($image_id);
        $alt_text = get_post_meta($image_id, '_wp_attachment_image_alt', true);
        $caption = wp_get_attachment_caption($image_id);
        
        // Build Gutenberg block comment with ONLY required attributes
        $block = sprintf(
            '<!-- wp:image {"id":%d,"sizeSlug":"large","linkDestination":"none"} -->',
            $image_id
        );
        $block .= "\n";
        
        // Start figure tag
        $block .= '<figure class="wp-block-image size-large">';
        
        // Create img tag WITHOUT width/height attributes
        // Gutenberg handles dimensions automatically via sizeSlug
        $block .= sprintf(
            '<img src="%s" alt="%s" class="wp-image-%d"/>',
            esc_url($image_url),
            esc_attr($alt_text),
            $image_id
        );
        
        // Add caption if exists
        if (!empty($caption)) {
            $block .= sprintf(
                '<figcaption class="wp-element-caption">%s</figcaption>',
                wp_kses_post($caption)
            );
        }
        
        // Close figure tag
        $block .= '</figure>';
        $block .= "\n" . '<!-- /wp:image -->';
        
        error_log('SIM: Created Gutenberg block without width/height');
        
        return $block;
    }
    
    private static function image_exists_in_content($content, $image_id, $heading_position) {
        // Check if image ID already exists in content
        $image_class = 'wp-image-' . $image_id;
        
        // Quick check: if image class doesn't exist anywhere, it's not a duplicate
        if (strpos($content, $image_class) === false) {
            return false;
        }
        
        // Image exists somewhere - now check if it's near this heading position
        // Define "near" as within 1000 characters (before or after the heading)
        $search_start = max(0, $heading_position - 500);
        $search_end = min(strlen($content), $heading_position + 1500);
        $search_length = $search_end - $search_start;
        
        $content_section = substr($content, $search_start, $search_length);
        
        // Check if image exists in this section
        if (strpos($content_section, $image_class) !== false) {
            error_log('SIM: Found image ' . $image_id . ' near heading at position ' . $heading_position);
            return true;
        }
        
        return false;
    }
    
    private static function bulk_insert_gutenberg($content, $insertions) {
        $blocks = parse_blocks($content);
        error_log('SIM: Bulk Gutenberg - Parsed ' . count($blocks) . ' blocks');
        
        // Create a map of positions to images for quick lookup
        $insertion_map = array();
        foreach ($insertions as $insertion) {
            $insertion_map[$insertion['heading_position']] = $insertion['image_id'];
        }
        
        $new_blocks = array();
        
        foreach ($blocks as $block) {
            $new_blocks[] = $block;
            
            // Check if this is a heading block
            if (isset($block['blockName']) && $block['blockName'] === 'core/heading') {
                $block_html = render_block($block);
                $block_position = strpos($content, $block_html);
                
                // Check if we have an image to insert after this heading
                foreach ($insertion_map as $heading_pos => $image_id) {
                    if ($block_position !== false && abs($block_position - $heading_pos) < 50) {
                        $image_block = self::create_gutenberg_image_block($image_id);
                        $new_blocks[] = $image_block;
                        error_log('SIM: Bulk Gutenberg - Inserted image ' . $image_id . ' after heading at position ' . $block_position);
                        unset($insertion_map[$heading_pos]); // Remove so we don't insert twice
                        break;
                    }
                }
            }
        }
        
        return serialize_blocks($new_blocks);
    }
    
    private static function bulk_insert_html($content, $insertions) {
        // Find all headings
        preg_match_all('/<h[2-6][^>]*>.*?<\/h[2-6]>/is', $content, $matches, PREG_OFFSET_CAPTURE);
        
        // Build array of insertion points (position => image_id)
        $insertion_points = array();
        
        foreach ($insertions as $insertion) {
            foreach ($matches[0] as $match) {
                if ($match[1] == $insertion['heading_position']) {
                    $heading_end = $match[1] + strlen($match[0]);
                    $insertion_points[$heading_end] = $insertion['image_id'];
                    break;
                }
            }
        }
        
        // Sort by position (descending) to insert from bottom to top
        krsort($insertion_points);
        
        // Insert images
        foreach ($insertion_points as $position => $image_id) {
            $image_block = self::create_image_block($image_id);
            $content = substr($content, 0, $position) . "\n\n" . $image_block . "\n\n" . substr($content, $position);
            error_log('SIM: Bulk HTML - Inserted image ' . $image_id . ' at position ' . $position);
        }
        
        return $content;
    }
}

