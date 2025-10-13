<?php
/**
 * Filename: class-sim-core.php
 * Author: Krafty Sprouts Media, LLC
 * Created: 12/10/2025
 * Version: 1.2.0
 * Last Modified: 12/10/2025
 * Description: Core functionality and initialization for Smart Image Matcher with organized menu structure
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
            wp_enqueue_script(
                'sim-editor-js',
                SIM_PLUGIN_URL . 'admin/js/sim-editor.js',
                array('jquery'),
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
        // Parent menu item with "SIM" abbreviation
        add_menu_page(
            __('Smart Image Matcher', 'smart-image-matcher'),
            '<span class="sim-menu-title" title="Smart Image Matcher">SIM</span>',
            'edit_posts',
            'smart-image-matcher',
            array('SIM_Settings', 'render_settings_page'),
            'dashicons-format-image',
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

