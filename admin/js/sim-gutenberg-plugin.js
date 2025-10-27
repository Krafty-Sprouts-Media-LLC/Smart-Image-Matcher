/**
 * Filename: sim-gutenberg-plugin.js
 * Author: Krafty Sprouts Media, LLC
 * Created: 21/10/2025
 * Version: 1.0.5
 * Last Modified: 27/10/2025
 * Description: Gutenberg editor toolbar integration with custom SVG icon
 */

(function(wp) {
    'use strict';

    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel, PluginSidebar, PluginSidebarMoreMenuItem } = wp.editor;
    const { PanelBody, Button, Spinner } = wp.components;
    const { ToolbarButton } = wp.blockEditor;
    const { Fragment, createElement } = wp.element;
    const { __ } = wp.i18n;

    // Custom Smart Image Matcher SVG icon
    const SimIcon = () => createElement('svg', {
        width: '20',
        height: '20',
        viewBox: '0 0 20 20',
        fill: 'none',
        xmlns: 'http://www.w3.org/2000/svg'
    }, [
        createElement('path', {
            key: 'frame',
            d: 'M2 3C2 2.44772 2.44772 2 3 2H17C17.5523 2 18 2.44772 18 3V13C18 13.5523 17.5523 14 17 14H3C2.44772 14 2 13.5523 2 13V3Z',
            stroke: 'currentColor',
            strokeWidth: '1.5',
            strokeLinecap: 'round',
            strokeLinejoin: 'round'
        }),
        createElement('circle', {
            key: 'center-dot',
            cx: '7',
            cy: '7',
            r: '1.5',
            fill: 'currentColor'
        }),
        createElement('path', {
            key: 'image-line',
            d: 'M2 11L5.5 8L9 10.5L13.5 6L18 10',
            stroke: 'currentColor',
            strokeWidth: '1.5',
            strokeLinecap: 'round',
            strokeLinejoin: 'round'
        }),
        createElement('path', {
            key: 'bottom-line',
            d: 'M4 18L6 16L8 18L10 16L12 18L14 16L16 18',
            stroke: 'currentColor',
            strokeWidth: '1.5',
            strokeLinecap: 'round',
            strokeLinejoin: 'round',
            opacity: '0.6'
        }),
        createElement('circle', {
            key: 'match-1',
            cx: '10',
            cy: '10',
            r: '1',
            fill: '#4CAF50',
            opacity: '0.8'
        }),
        createElement('circle', {
            key: 'match-2',
            cx: '14',
            cy: '8',
            r: '1',
            fill: '#4CAF50',
            opacity: '0.8'
        })
    ]);


    // Document Settings Panel (appears in sidebar when opened)
    const SimDocumentPanel = () => {
        const handleClick = () => {
            if (window.simFindMatches) {
                jQuery('#sim-modal').show();
                jQuery('.sim-results-container').html('<div style="text-align: center; padding: 40px;"><span class="spinner is-active"></span><p>Analyzing content...</p></div>');
                window.simFindMatches();
            }
        };

        return createElement(PluginDocumentSettingPanel, {
            name: 'smart-image-matcher',
            title: __('Smart Image Matcher', 'smart-image-matcher'),
            icon: createElement(SimIcon),
            className: 'sim-document-panel'
        }, createElement(PanelBody, null, [
            createElement('p', {
                key: 'description',
                style: { marginBottom: '12px' }
            }, __('Automatically find and insert relevant images for your headings.', 'smart-image-matcher')),
            createElement(Button, {
                key: 'button',
                isPrimary: true,
                icon: createElement(SimIcon),
                onClick: handleClick,
                style: { width: '100%' }
            }, __('Find Matching Images', 'smart-image-matcher')),
            createElement('p', {
                key: 'info',
                style: { marginTop: '12px', fontSize: '12px', color: '#757575' }
            }, __('Scans H2-H6 headings and matches them with images from your Media Library.', 'smart-image-matcher'))
        ]));
    };

    // Main Plugin Component
    const SimMainPlugin = () => {
        const handleClick = () => {
            // Open the existing modal
            if (window.simFindMatches) {
                jQuery('#sim-modal').show();
                jQuery('.sim-results-container').html('<div style="text-align: center; padding: 40px;"><span class="spinner is-active"></span><p>Analyzing content...</p></div>');
                window.simFindMatches();
            }
        };

        return createElement(Fragment, null, [
            // Document Settings Panel
            createElement(SimDocumentPanel),
            
            // More Menu Item (this creates the icon in the 3 dots menu)
            createElement(PluginSidebarMoreMenuItem, {
                target: 'smart-image-matcher-sidebar',
                icon: createElement(SimIcon)
            }, __('Smart Image Matcher', 'smart-image-matcher')),
            
            // Sidebar (opens when menu item is clicked)
            createElement(PluginSidebar, {
                name: 'smart-image-matcher-sidebar',
                title: __('Smart Image Matcher', 'smart-image-matcher'),
                icon: createElement(SimIcon)
            }, createElement(PanelBody, {
                title: __('Smart Image Matcher', 'smart-image-matcher'),
                initialOpen: true
            }, [
                createElement('p', {
                    key: 'description',
                    style: { marginBottom: '16px', color: '#757575', fontSize: '13px', lineHeight: '1.6' }
                }, __('Automatically match headings with images from your Media Library.', 'smart-image-matcher')),
                createElement(Button, {
                    key: 'button',
                    isPrimary: true,
                    onClick: handleClick,
                    style: { width: '100%' }
                }, __('Open Smart Image Matcher', 'smart-image-matcher'))
            ]))
        ]);
    };

    // Register the plugin
    registerPlugin('smart-image-matcher', {
        render: SimMainPlugin,
        icon: createElement(SimIcon),
    });

    // No additional menu items needed - already included in main plugin

})(window.wp);

