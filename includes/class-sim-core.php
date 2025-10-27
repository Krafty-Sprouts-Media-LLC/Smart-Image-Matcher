<?php
/**
 * Filename: class-sim-core.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.1.0
 * Last Modified: 27/10/2025
 * Description: Core functionality with Gutenberg toolbar integration using custom SVG icons
 */

if (!defined('ABSPATH')) {
    exit;
}

class SIM_Core {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        
        SIM_AJAX::init();
        SIM_Admin::init();
    }
    
    public function enqueue_admin_assets($hook) {
        global $post;
        
        // Always enqueue CSS for menu styling
        wp_enqueue_style(
            'sim-admin-css',
            SIM_PLUGIN_URL . 'admin/css/sim-admin.css',
            array(),
            SIM_VERSION
        );
        
        $allowed_hooks = array(
            'post.php',
            'post-new.php',
            'toplevel_page_smart-image-matcher',
            'smart-image-matcher_page_smart-image-matcher-bulk'
        );
        
        if (!in_array($hook, $allowed_hooks)) {
            return;
        }
        
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            // Enqueue jQuery-based editor script (for modal)
            wp_enqueue_script(
                'sim-svg-icons',
                SIM_PLUGIN_URL . 'admin/js/sim-svg-icons.js',
                array('jquery'),
                SIM_VERSION,
                true
            );
            
            wp_enqueue_script(
                'sim-editor-js',
                SIM_PLUGIN_URL . 'admin/js/sim-editor.js',
                array('jquery', 'sim-svg-icons'),
                SIM_VERSION,
                true
            );
            
            wp_localize_script('sim-editor-js', 'simEditor', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sim_editor_nonce'),
                'postId' => isset($post->ID) ? $post->ID : 0,
                'strings' => array(
                    'analyzingContent' => __('Analyzing content...', 'smart-image-matcher'),
                    'foundHeadings' => __('Found %d headings', 'smart-image-matcher'),
                    'searchingImages' => __('Searching %d images', 'smart-image-matcher'),
                    'insertSuccess' => __('Image inserted successfully!', 'smart-image-matcher'),
                    'insertError' => __('Failed to insert image', 'smart-image-matcher'),
                    'noMatches' => __('No matching image found', 'smart-image-matcher'),
                    'confidence' => __('Confidence', 'smart-image-matcher'),
                )
            ));
            
            // Enqueue Gutenberg toolbar plugin (React-based)
            wp_enqueue_script(
                'sim-gutenberg-plugin',
                SIM_PLUGIN_URL . 'admin/js/sim-gutenberg-plugin.js',
                array(
                    'wp-plugins',
                    'wp-edit-post',
                    'wp-element',
                    'wp-components',
                    'wp-block-editor',
                    'wp-i18n',
                    'wp-data',
                    'sim-editor-js' // Depends on the modal script
                ),
                SIM_VERSION,
                true
            );
            
            // Enqueue Gutenberg-specific CSS
            wp_enqueue_style(
                'sim-gutenberg-css',
                SIM_PLUGIN_URL . 'admin/css/sim-gutenberg.css',
                array(),
                SIM_VERSION
            );
        }
        
        if ($hook === 'tools_page_smart-image-matcher') {
            wp_enqueue_script(
                'sim-bulk-js',
                SIM_PLUGIN_URL . 'admin/js/sim-bulk.js',
                array('jquery'),
                SIM_VERSION,
                true
            );
            
            wp_localize_script('sim-bulk-js', 'simBulk', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sim_bulk_nonce'),
            ));
        }
    }
    
    public function register_admin_menu() {
        // Parent menu item with full name
        $sim_icon_base64 = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHZpZXdCb3g9IjAgMCAyMCAyMCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cGF0aCBkPSJNMiAzQzIgMi40NDc3MiAyLjQ0NzcyIDIgMyAySDE3QzE3LjU1MjMgMiAxOCAyLjQ0NzcyIDE4IDNWMTNDMTggMTMuNTUyMyAxNy41NTIzIDE0IDE3IDE0SDNDMi40NDc3MiAxNCAyIDEzLjU1MjMgMiAxM1YzWiIgc3Ryb2tlPSIjNjY2NjY2IiBzdHJva2Utd2lkdGg9IjEuNSIgc3Ryb2tlLWxpbmVjYXA9InJvdW5kIiBzdHJva2UtbGluZWpvaW49InJvdW5kIi8+PGNpcmNsZSBjeD0iNyIgY3k9IjciIHI9IjEuNSIgZmlsbD0iIzY2NjY2NiIvPjxwYXRoIGQ9Ik0yIDExTDUuNSA4TDkgMTAuNUwxMy41IDZMMTggMTAiIHN0cm9rZT0iIzY2NjY2NiIgc3Ryb2tlLXdpZHRoPSIxLjUiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCIvPjwvc3ZnPg==';
        
        add_menu_page(
            __('Smart Image Matcher', 'smart-image-matcher'),
            __('SIM', 'smart-image-matcher'),
            'edit_posts',
            'smart-image-matcher',
            array('SIM_Settings', 'render_settings_page'),
            $sim_icon_base64,
            30
        );
        
        // Submenu: Settings (default page)
        add_submenu_page(
            'smart-image-matcher',
            __('Smart Image Matcher - Settings', 'smart-image-matcher'),
            __('Settings', 'smart-image-matcher'),
            'manage_options',
            'smart-image-matcher',
            array('SIM_Settings', 'render_settings_page')
        );
        
        // Submenu: Bulk Processor
        add_submenu_page(
            'smart-image-matcher',
            __('Smart Image Matcher - Bulk Processor', 'smart-image-matcher'),
            __('Bulk Processor', 'smart-image-matcher'),
            'edit_posts',
            'smart-image-matcher-bulk',
            array('SIM_Bulk', 'render_bulk_processor_page')
        );
    }
}

