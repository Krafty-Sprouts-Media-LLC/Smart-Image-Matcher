<?php
/**
 * Filename: class-sim-admin.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.1.1
 * Last Modified: 27/10/2025
 * Description: Admin interface for post editor button and modal
 * Supports both Classic Editor and Gutenberg Block Editor
 * Includes image naming tips in modal and settings page
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_Admin {
    
    public static function init() {
        add_action('edit_form_after_title', array(__CLASS__, 'add_editor_button'));
        add_action('enqueue_block_editor_assets', array(__CLASS__, 'enqueue_gutenberg_button'));
        add_action('admin_footer', array(__CLASS__, 'add_modal_to_footer'));
        add_action('admin_bar_menu', array(__CLASS__, 'add_admin_bar_button'), 100);
    }
    
    public static function add_editor_button($post) {
        if (!in_array($post->post_type, array('post', 'page'))) {
            return;
        }
        
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }
        
        ?>
        <div id="sim-editor-button-container" style="margin: 10px 0;">
            <button type="button" id="sim-open-modal" class="button button-secondary">
                <span class="sim-svg-icon sim-icon-image" style="vertical-align: middle;"></span>
                <?php esc_html_e('Smart Image Matcher', 'smart-image-matcher'); ?>
            </button>
        </div>
        <?php
    }
    
    public static function enqueue_gutenberg_button() {
        global $post;
        
        if (!$post || !in_array($post->post_type, array('post', 'page'))) {
            return;
        }
        
        // No longer needed - using proper Gutenberg ToolbarButton API
    }
    
    public static function add_admin_bar_button($wp_admin_bar) {
        // Only show in admin area - get_current_screen() is not available on frontend
        if (!is_admin()) {
            return;
        }
        
        // Check if get_current_screen() function exists (extra safety check)
        if (!function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        
        if (!$screen || !in_array($screen->id, array('post', 'page'))) {
            return;
        }
        
        global $post;
        if (!$post || !in_array($post->post_type, array('post', 'page'))) {
            return;
        }
        
        $wp_admin_bar->add_node(array(
            'id' => 'smart-image-matcher',
            'title' => '<span class="ab-icon sim-svg-icon sim-icon-image"></span><span class="ab-label">Smart Image Matcher</span>',
            'href' => '#',
            'meta' => array(
                'class' => 'sim-admin-bar-button',
                'onclick' => 'jQuery("#sim-modal").show(); if (typeof window.simFindMatches === "function") { window.simFindMatches(); } return false;',
            ),
        ));
    }
    
    public static function add_modal_to_footer() {
        // Check if get_current_screen() function exists (should be in admin context)
        if (!function_exists('get_current_screen')) {
            return;
        }
        
        $screen = get_current_screen();
        
        if (!$screen || !in_array($screen->id, array('post', 'page'))) {
            return;
        }
        
        self::render_modal();
    }
    
    public static function render_modal() {
        ?>
        <div id="sim-modal" class="sim-modal" style="display: none;">
            <div class="sim-modal-overlay"></div>
            <div class="sim-modal-content">
                <div class="sim-modal-header">
                    <h2><?php esc_html_e('Smart Image Matcher', 'smart-image-matcher'); ?></h2>
                    <button type="button" class="sim-modal-close">&times;</button>
                </div>
                <div class="sim-modal-body">
                    <div class="sim-loading-state">
                        <p><?php esc_html_e('Analyzing content...', 'smart-image-matcher'); ?></p>
                        <div class="sim-progress-bar">
                            <div class="sim-progress-fill"></div>
                        </div>
                        <p class="sim-loading-info"></p>
                    </div>
                    <div class="sim-results-state" style="display: none;">
                        <div class="sim-results-summary"></div>
                        
                        <!-- Image Naming Tips (Collapsible) -->
                        <details class="sim-tips-section" style="margin: 15px 0; padding: 12px; background: #f0f6fc; border: 1px solid #c3dafe; border-radius: 4px;">
                            <summary style="cursor: pointer; font-weight: 600; color: #0366d6; user-select: none;">
                                <span class="sim-svg-icon sim-icon-lightbulb" style="vertical-align: middle;"></span>
                                <?php esc_html_e('Tips for Better Matching', 'smart-image-matcher'); ?>
                            </summary>
                            <div style="margin-top: 10px; font-size: 13px; line-height: 1.6;">
                                <p style="margin: 8px 0 8px 0;"><strong><?php esc_html_e('How to improve your matches:', 'smart-image-matcher'); ?></strong></p>
                                <ul style="margin: 8px 0 8px 20px; list-style: disc;">
                                    <li><?php esc_html_e('Use descriptive filenames with word separators (dashes, underscores, or spaces)', 'smart-image-matcher'); ?></li>
                                    <li><?php esc_html_e('Set image titles using natural language (e.g., "Kentucky Warbler" not dashes)', 'smart-image-matcher'); ?></li>
                                    <li><?php esc_html_e('Add relevant alt text for SEO and accessibility', 'smart-image-matcher'); ?></li>
                                    <li><?php esc_html_e('Match keywords from your headings in image metadata', 'smart-image-matcher'); ?></li>
                                    <li><?php esc_html_e('Avoid generic names like "IMG_001.jpg" or "screenshot.png"', 'smart-image-matcher'); ?></li>
                                </ul>
                                <p style="margin: 8px 0 0 20px; font-size: 11px; color: #666; font-style: italic;">
                                    <?php esc_html_e('Tip: Dashes are SEO-recommended but any separator works!', 'smart-image-matcher'); ?>
                                </p>
                                <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
                                    <strong><?php esc_html_e('Priority:', 'smart-image-matcher'); ?></strong> 
                                    <?php esc_html_e('Filename (100 pts) → Title (90 pts) → Alt Text (85 pts)', 'smart-image-matcher'); ?>
                                </p>
                            </div>
                        </details>
                        
                        <div class="sim-matches-container"></div>
                    </div>
                    <div class="sim-error-state" style="display: none;">
                        <p class="sim-error-message"></p>
                    </div>
                </div>
                <div class="sim-modal-footer">
                    <button type="button" class="button sim-cancel-button"><?php esc_html_e('Cancel', 'smart-image-matcher'); ?></button>
                    <button type="button" class="button button-primary sim-insert-all-button" style="display: none;"><?php esc_html_e('Insert All Selected', 'smart-image-matcher'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}

