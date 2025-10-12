<?php
/**
 * Filename: settings-page.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.0.2
 * Last Modified: 12/10/2025
 * Description: Settings page view with image naming best practices
 */

if (!defined('ABSPATH')) {
    exit;
}

$match_mode = get_option('sim_match_mode', 'keyword');
$confidence_threshold = get_option('sim_confidence_threshold', 70);
$hierarchy_mode = get_option('sim_hierarchy_mode', 'smart');
$heading_overlap_threshold = get_option('sim_heading_overlap_threshold', 70);
$claude_api_key = get_option('sim_claude_api_key', '');
$claude_model = get_option('sim_claude_model', 'claude-sonnet-4-20250514');
$daily_spending_limit = get_option('sim_daily_spending_limit', 10.00);
$batch_size_limit = get_option('sim_batch_size_limit', 50);
$cost_warnings = get_option('sim_cost_warnings', true);
$email_notifications = get_option('sim_email_notifications', true);
$auto_fallback_keyword = get_option('sim_auto_fallback_keyword', true);
$delete_on_uninstall = get_option('sim_delete_on_uninstall', true);
?>

<div class="wrap">
    <h1><?php esc_html_e('Smart Image Matcher Settings', 'smart-image-matcher'); ?></h1>
    
    <?php settings_errors('sim_settings'); ?>
    
    <!-- Image Naming Best Practices -->
    <div class="notice notice-info" style="margin: 20px 0; padding: 15px;">
        <h3 style="margin-top: 0;">
            <span class="dashicons dashicons-lightbulb" style="color: #2271b1;"></span>
            <?php esc_html_e('Image Naming Best Practices', 'smart-image-matcher'); ?>
        </h3>
        <p><?php esc_html_e('For best matching results, follow these guidelines when naming and organizing your images:', 'smart-image-matcher'); ?></p>
        <ul style="margin-left: 20px;">
            <li><strong><?php esc_html_e('Descriptive Filenames:', 'smart-image-matcher'); ?></strong> <?php esc_html_e('Use descriptive names with clear word separators (e.g., "western-black-widow.jpg" or "western_black_widow.jpg" instead of "IMG_1234.jpg")', 'smart-image-matcher'); ?></li>
            <li><strong><?php esc_html_e('Meaningful Titles:', 'smart-image-matcher'); ?></strong> <?php esc_html_e('Set custom image titles in Media Library that match your content headings', 'smart-image-matcher'); ?></li>
            <li><strong><?php esc_html_e('SEO-Friendly Alt Text:', 'smart-image-matcher'); ?></strong> <?php esc_html_e('Add descriptive alt text that describes the image content', 'smart-image-matcher'); ?></li>
            <li><strong><?php esc_html_e('Match Your Headings:', 'smart-image-matcher'); ?></strong> <?php esc_html_e('Use keywords from your post headings in image metadata', 'smart-image-matcher'); ?></li>
            <li><strong><?php esc_html_e('Avoid Generic Names:', 'smart-image-matcher'); ?></strong> <?php esc_html_e('Replace auto-generated names like "IMG_001.jpg" or "screenshot.png" with specific descriptions', 'smart-image-matcher'); ?></li>
        </ul>
        <p style="margin: 10px 0 0 20px; font-size: 13px; color: #666;">
            <em><?php esc_html_e('Note: Dashes, underscores, or spaces all work. Dashes are SEO-recommended but not required.', 'smart-image-matcher'); ?></em>
        </p>
        <p style="margin-bottom: 0;">
            <strong><?php esc_html_e('Scoring Priority:', 'smart-image-matcher'); ?></strong> 
            <?php esc_html_e('Filename (100 points) → Title (90 points + bonus) → Alt Text (85 points)', 'smart-image-matcher'); ?>
        </p>
    </div>
    
    <form method="post" action="">
        <?php wp_nonce_field('sim_settings_nonce'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="sim_match_mode"><?php esc_html_e('Default Match Mode', 'smart-image-matcher'); ?></label>
                </th>
                <td>
                    <select name="sim_match_mode" id="sim_match_mode">
                        <option value="keyword" <?php selected($match_mode, 'keyword'); ?>><?php esc_html_e('Keyword (Fast)', 'smart-image-matcher'); ?></option>
                        <option value="ai" <?php selected($match_mode, 'ai'); ?>><?php esc_html_e('AI (Accurate)', 'smart-image-matcher'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Keyword mode is faster, AI mode is more accurate but uses API credits.', 'smart-image-matcher'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="sim_confidence_threshold"><?php esc_html_e('Confidence Threshold', 'smart-image-matcher'); ?></label>
                </th>
                <td>
                    <input type="number" name="sim_confidence_threshold" id="sim_confidence_threshold" value="<?php echo esc_attr($confidence_threshold); ?>" min="0" max="100" step="1">
                    <span>%</span>
                    <p class="description"><?php esc_html_e('Minimum confidence score to consider a match (0-100).', 'smart-image-matcher'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="sim_hierarchy_mode"><?php esc_html_e('Hierarchy Mode', 'smart-image-matcher'); ?></label>
                </th>
                <td>
                    <select name="sim_hierarchy_mode" id="sim_hierarchy_mode">
                        <option value="all" <?php selected($hierarchy_mode, 'all'); ?>><?php esc_html_e('All Headings', 'smart-image-matcher'); ?></option>
                        <option value="primary" <?php selected($hierarchy_mode, 'primary'); ?>><?php esc_html_e('Primary Only (H2)', 'smart-image-matcher'); ?></option>
                        <option value="smart" <?php selected($hierarchy_mode, 'smart'); ?>><?php esc_html_e('Smart Hierarchy (Recommended)', 'smart-image-matcher'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('How to handle heading hierarchy when matching images.', 'smart-image-matcher'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="sim_heading_overlap_threshold"><?php esc_html_e('Heading Overlap Threshold', 'smart-image-matcher'); ?></label>
                </th>
                <td>
                    <input type="number" name="sim_heading_overlap_threshold" id="sim_heading_overlap_threshold" value="<?php echo esc_attr($heading_overlap_threshold); ?>" min="0" max="100" step="1">
                    <span>%</span>
                    <p class="description"><?php esc_html_e('Skip subheadings if keyword overlap exceeds this threshold (Smart Hierarchy mode).', 'smart-image-matcher'); ?></p>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('AI Settings', 'smart-image-matcher'); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="sim_claude_api_key"><?php esc_html_e('Claude API Key', 'smart-image-matcher'); ?></label>
                </th>
                <td>
                    <input type="password" name="sim_claude_api_key" id="sim_claude_api_key" value="<?php echo esc_attr($claude_api_key); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Enter your Claude API key for AI-powered matching.', 'smart-image-matcher'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="sim_claude_model"><?php esc_html_e('Claude Model', 'smart-image-matcher'); ?></label>
                </th>
                <td>
                    <input type="text" name="sim_claude_model" id="sim_claude_model" value="<?php echo esc_attr($claude_model); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Claude model to use (default: claude-sonnet-4-20250514).', 'smart-image-matcher'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="sim_daily_spending_limit"><?php esc_html_e('Daily Spending Limit', 'smart-image-matcher'); ?></label>
                </th>
                <td>
                    <span>$</span>
                    <input type="number" name="sim_daily_spending_limit" id="sim_daily_spending_limit" value="<?php echo esc_attr($daily_spending_limit); ?>" min="0" step="0.01">
                    <p class="description"><?php esc_html_e('Maximum daily API spending limit.', 'smart-image-matcher'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="sim_batch_size_limit"><?php esc_html_e('Batch Size Limit', 'smart-image-matcher'); ?></label>
                </th>
                <td>
                    <input type="number" name="sim_batch_size_limit" id="sim_batch_size_limit" value="<?php echo esc_attr($batch_size_limit); ?>" min="1" max="1000">
                    <p class="description"><?php esc_html_e('Maximum number of posts to process in a single batch.', 'smart-image-matcher'); ?></p>
                </td>
            </tr>
            
            <tr>
                <th scope="row"><?php esc_html_e('Options', 'smart-image-matcher'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="sim_cost_warnings" value="1" <?php checked($cost_warnings, 1); ?>>
                        <?php esc_html_e('Show cost warnings before processing', 'smart-image-matcher'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="sim_email_notifications" value="1" <?php checked($email_notifications, 1); ?>>
                        <?php esc_html_e('Send email notifications for completed batches', 'smart-image-matcher'); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="sim_auto_fallback_keyword" value="1" <?php checked($auto_fallback_keyword, 1); ?>>
                        <?php esc_html_e('Automatically fallback to keyword mode on API errors', 'smart-image-matcher'); ?>
                    </label>
                </td>
            </tr>
        </table>
        
        <h2><?php esc_html_e('Data Management', 'smart-image-matcher'); ?></h2>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Uninstall Options', 'smart-image-matcher'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="sim_delete_on_uninstall" value="1" <?php checked($delete_on_uninstall, 1); ?>>
                        <?php esc_html_e('Delete all plugin data on uninstall', 'smart-image-matcher'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('When checked, all database tables, settings, and files will be removed when the plugin is uninstalled.', 'smart-image-matcher'); ?></p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(__('Save Settings', 'smart-image-matcher'), 'primary', 'sim_save_settings'); ?>
    </form>
</div>

