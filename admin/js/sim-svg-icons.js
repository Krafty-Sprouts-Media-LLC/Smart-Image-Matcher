/**
 * Filename: sim-svg-icons.js
 * Author: Krafty Sprouts Media, LLC
 * Created: 26/10/2025
 * Version: 1.0.0
 * Last Modified: 26/10/2025
 * Description: Custom SVG icons to replace deprecated Dashicons
 * Following WordPress Design Team recommendations for modern icon usage
 */

(function($) {
    'use strict';

    // SVG Icon definitions
    window.SimSvgIcons = {
        
        // Check/Yes icon (green when selected, red when unselected)
        check: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-check ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M13.5 4.5L6 12L2.5 8.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        },

        // X/No icon
        close: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-close ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 4L4 12M4 4L12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        },

        // Arrow left icon
        arrowLeft: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-arrow-left ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M10 12L6 8L10 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        },

        // Arrow right icon
        arrowRight: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-arrow-right ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 4L10 8L6 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        },

        // Warning icon
        warning: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-warning ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M8 1L15 14H1L8 1Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M8 6V9" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                <circle cx="8" cy="11" r="1" fill="currentColor"/>
            </svg>`;
        },

        // Image/Format image icon
        image: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-image ${className}" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="2" y="3" width="16" height="11" rx="2" stroke="currentColor" stroke-width="1.5"/>
                <circle cx="7" cy="7" r="1.5" fill="currentColor"/>
                <path d="M2 11L5.5 8L9 10.5L13.5 6L18 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        },

        // Lightbulb icon
        lightbulb: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-lightbulb ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 14H10M8 14V12M8 2C5.79086 2 4 3.79086 4 6C4 7.86384 5.27477 9.42994 7 9.87402V10C7 10.5523 7.44772 11 8 11C8.55228 11 9 10.5523 9 10V9.87402C10.7252 9.42994 12 7.86384 12 6C12 3.79086 10.2091 2 8 2Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        },

        // Info icon
        info: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-info ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                <path d="M8 6V8M8 10H8.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        },

        // Post/Admin post icon
        post: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-post ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="2" y="3" width="12" height="10" rx="2" stroke="currentColor" stroke-width="1.5"/>
                <path d="M6 7H10M6 9H8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        },

        // Settings icon
        settings: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-settings ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="8" cy="8" r="2.5" stroke="currentColor" stroke-width="1.5"/>
                <path d="M8 1V2M8 14V15M15 8H14M2 8H1M13.364 3.636L12.657 4.343M3.343 11.657L2.636 12.364M13.364 12.364L12.657 11.657M3.343 4.343L2.636 3.636" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
            </svg>`;
        },

        // Chart/Stats icon
        chart: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-chart ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 12L6 9L9 11L13 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="3" cy="12" r="1.5" fill="currentColor"/>
                <circle cx="6" cy="9" r="1.5" fill="currentColor"/>
                <circle cx="9" cy="11" r="1.5" fill="currentColor"/>
                <circle cx="13" cy="7" r="1.5" fill="currentColor"/>
            </svg>`;
        },

        // Success/Check circle icon
        success: function(className = '') {
            return `<svg class="sim-svg-icon sim-icon-success ${className}" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                <path d="M5 8L7 10L11 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>`;
        }
    };

    // Helper function to replace Dashicons with SVG
    window.replaceDashiconsWithSvg = function() {
        // Replace check/yes icons
        $('.dashicons-yes').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-yes', '').trim();
            $this.replaceWith(SimSvgIcons.check(className));
        });

        // Replace close/no icons
        $('.dashicons-no').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-no', '').trim();
            $this.replaceWith(SimSvgIcons.close(className));
        });

        // Replace arrow left icons
        $('.dashicons-arrow-left-alt2').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-arrow-left-alt2', '').trim();
            $this.replaceWith(SimSvgIcons.arrowLeft(className));
        });

        // Replace arrow right icons
        $('.dashicons-arrow-right-alt2').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-arrow-right-alt2', '').trim();
            $this.replaceWith(SimSvgIcons.arrowRight(className));
        });

        // Replace warning icons
        $('.dashicons-warning').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-warning', '').trim();
            $this.replaceWith(SimSvgIcons.warning(className));
        });

        // Replace format-image icons
        $('.dashicons-format-image').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-format-image', '').trim();
            $this.replaceWith(SimSvgIcons.image(className));
        });

        // Replace lightbulb icons
        $('.dashicons-lightbulb').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-lightbulb', '').trim();
            $this.replaceWith(SimSvgIcons.lightbulb(className));
        });

        // Replace info icons
        $('.dashicons-info').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-info', '').trim();
            $this.replaceWith(SimSvgIcons.info(className));
        });

        // Replace admin-post icons
        $('.dashicons-admin-post').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-admin-post', '').trim();
            $this.replaceWith(SimSvgIcons.post(className));
        });

        // Replace admin-settings icons
        $('.dashicons-admin-settings').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-admin-settings', '').trim();
            $this.replaceWith(SimSvgIcons.settings(className));
        });

        // Replace chart-bar icons
        $('.dashicons-chart-bar').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-chart-bar', '').trim();
            $this.replaceWith(SimSvgIcons.chart(className));
        });

        // Replace yes-alt icons
        $('.dashicons-yes-alt').each(function() {
            const $this = $(this);
            const className = $this.attr('class').replace('dashicons dashicons-yes-alt', '').trim();
            $this.replaceWith(SimSvgIcons.success(className));
        });
    };

    // Auto-replace on document ready
    $(document).ready(function() {
        replaceDashiconsWithSvg();
    });

})(jQuery);
