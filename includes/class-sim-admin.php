<?php
/**
 * Filename: class-sim-admin.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.0
 * Last Modified: 12/10/2025
 * Description: Admin interface for post editor button and bulk processing page
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_Admin {
    
    public static function init() {
        add_action('edit_form_after_title', array(__CLASS__, 'add_editor_button'));
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

