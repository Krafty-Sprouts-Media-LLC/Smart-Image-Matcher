<?php
/**
 * Filename: class-sim-settings.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.0
 * Last Modified: 12/10/2025
 * Description: Settings page and options management
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_Settings {
    
    public static function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'smart-image-matcher'));
        }
        
        if (isset($_POST['sim_save_settings'])) {
            check_admin_referer('sim_settings_nonce');
            self::save_settings();
        }
        
        include SIM_PLUGIN_DIR . 'admin/views/settings-page.php';
    }
    
    private static function save_settings() {
        $settings = array(
            'sim_match_mode' => isset($_POST['sim_match_mode']) ? sanitize_text_field($_POST['sim_match_mode']) : 'keyword',
            'sim_confidence_threshold' => isset($_POST['sim_confidence_threshold']) ? intval($_POST['sim_confidence_threshold']) : 70,
            'sim_hierarchy_mode' => isset($_POST['sim_hierarchy_mode']) ? sanitize_text_field($_POST['sim_hierarchy_mode']) : 'smart',
            'sim_heading_overlap_threshold' => isset($_POST['sim_heading_overlap_threshold']) ? intval($_POST['sim_heading_overlap_threshold']) : 70,
            'sim_minimum_image_spacing' => isset($_POST['sim_minimum_image_spacing']) ? intval($_POST['sim_minimum_image_spacing']) : 300,
            'sim_claude_api_key' => isset($_POST['sim_claude_api_key']) ? sanitize_text_field($_POST['sim_claude_api_key']) : '',
            'sim_claude_model' => isset($_POST['sim_claude_model']) ? sanitize_text_field($_POST['sim_claude_model']) : 'claude-sonnet-4-20250514',
            'sim_daily_spending_limit' => isset($_POST['sim_daily_spending_limit']) ? floatval($_POST['sim_daily_spending_limit']) : 10.00,
            'sim_batch_size_limit' => isset($_POST['sim_batch_size_limit']) ? intval($_POST['sim_batch_size_limit']) : 50,
            'sim_cost_warnings' => isset($_POST['sim_cost_warnings']) ? 1 : 0,
            'sim_email_notifications' => isset($_POST['sim_email_notifications']) ? 1 : 0,
            'sim_auto_fallback_keyword' => isset($_POST['sim_auto_fallback_keyword']) ? 1 : 0,
            'sim_delete_on_uninstall' => isset($_POST['sim_delete_on_uninstall']) ? 1 : 0,
        );
        
        foreach ($settings as $option => $value) {
            update_option($option, $value);
        }
        
        add_settings_error(
            'sim_settings',
            'sim_settings_updated',
            __('Settings saved successfully.', 'smart-image-matcher'),
            'updated'
        );
    }
}

