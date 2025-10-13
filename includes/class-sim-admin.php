<?php
/**
 * Filename: class-sim-admin.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.6
 * Last Modified: 12/10/2025
 * Description: Admin interface for post editor button and bulk processing page
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
                <span class="dashicons dashicons-format-image" style="vertical-align: middle;"></span>
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
        
        $inline_script = "
        (function() {
            // Try multiple selectors for Gutenberg toolbar
            var selectors = [
                '.edit-post-header__toolbar',
                '.editor-header__toolbar',
                '.edit-post-header-toolbar',
                '.editor-document-tools__left',
                '.edit-site-header-edit-mode__start'
            ];
            
            function addButton() {
                if (document.getElementById('sim-gutenberg-button')) {
                    return; // Already added
                }
                
                var toolbar = null;
                for (var i = 0; i < selectors.length; i++) {
                    toolbar = document.querySelector(selectors[i]);
                    if (toolbar) break;
                }
                
                if (!toolbar) {
                    console.log('SIM: Toolbar not found, will retry...');
                    return false;
                }
                
                var buttonContainer = document.createElement('div');
                buttonContainer.id = 'sim-button-container';
                buttonContainer.style.cssText = 'margin-left: 12px; display: inline-flex; align-items: center;';
                
                var button = document.createElement('button');
                button.id = 'sim-gutenberg-button';
                button.className = 'components-button is-secondary';
                button.type = 'button';
                button.style.cssText = 'margin: 0 8px; height: 36px;';
                button.innerHTML = '<span class=\"dashicons dashicons-format-image\" style=\"margin-right: 5px; vertical-align: middle;\"></span>Smart Image Matcher';
                button.onclick = function(e) {
                    e.preventDefault();
                    if (typeof jQuery !== 'undefined') {
                        jQuery('#sim-modal').show();
                        if (typeof window.simFindMatches === 'function') {
                            window.simFindMatches();
                        }
                    }
                };
                
                buttonContainer.appendChild(button);
                toolbar.appendChild(buttonContainer);
                console.log('SIM: Button added successfully');
                return true;
            }
            
            // Try immediately
            if (!addButton()) {
                // Retry with delays
                var attempts = 0;
                var maxAttempts = 10;
                var interval = setInterval(function() {
                    attempts++;
                    if (addButton() || attempts >= maxAttempts) {
                        clearInterval(interval);
                        if (attempts >= maxAttempts) {
                            console.log('SIM: Could not find Gutenberg toolbar after ' + maxAttempts + ' attempts');
                        }
                    }
                }, 500);
            }
        })();
        ";
        
        wp_add_inline_script('wp-blocks', $inline_script);
    }
    
    public static function add_admin_bar_button($wp_admin_bar) {
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
            'title' => '<span class="ab-icon dashicons dashicons-format-image"></span><span class="ab-label">Smart Image Matcher</span>',
            'href' => '#',
            'meta' => array(
                'class' => 'sim-admin-bar-button',
                'onclick' => 'jQuery("#sim-modal").show(); if (typeof window.simFindMatches === "function") { window.simFindMatches(); } return false;',
            ),
        ));
    }
    
    public static function add_modal_to_footer() {
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
                                <span class="dashicons dashicons-lightbulb" style="vertical-align: middle;"></span>
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
    
    public static function render_bulk_processor_page() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'smart-image-matcher'));
        }
        
        include SIM_PLUGIN_DIR . 'admin/views/bulk-processor.php';
    }
}

