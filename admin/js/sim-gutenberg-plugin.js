/**
 * Filename: sim-gutenberg-plugin.js
 * Author: Krafty Sprouts Media, LLC
 * Created: 21/10/2025
 * Version: 1.0.0
 * Last Modified: 21/10/2025
 * Description: Gutenberg editor toolbar integration with custom SVG icon
 */

(function(wp) {
    'use strict';

    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editPost;
    const { PanelBody, Button, Spinner } = wp.components;
    const { Fragment } = wp.element;
    const { __ } = wp.i18n;

    // Custom Smart Image Matcher SVG icon
    const SimIcon = () => (
        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M2 3C2 2.44772 2.44772 2 3 2H17C17.5523 2 18 2.44772 18 3V13C18 13.5523 17.5523 14 17 14H3C2.44772 14 2 13.5523 2 13V3Z" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
            <circle cx="7" cy="7" r="1.5" fill="currentColor"/>
            <path d="M2 11L5.5 8L9 10.5L13.5 6L18 10" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
            <path d="M4 18L6 16L8 18L10 16L12 18L14 16L16 18" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" opacity="0.6"/>
            <circle cx="10" cy="10" r="1" fill="#4CAF50" opacity="0.8"/>
            <circle cx="14" cy="8" r="1" fill="#4CAF50" opacity="0.8"/>
        </svg>
    );

    // Toolbar Button Component
    const SimToolbarButton = () => {
        const handleClick = () => {
            // Open the existing modal
            if (window.simFindMatches) {
                jQuery('#sim-modal').show();
                jQuery('.sim-results-container').html('<div style="text-align: center; padding: 40px;"><span class="spinner is-active"></span><p>Analyzing content...</p></div>');
                window.simFindMatches();
            }
        };

        return (
            <div className="sim-gutenberg-toolbar-button">
                <Button
                    icon={<SimIcon />}
                    label={__('Smart Image Matcher', 'smart-image-matcher')}
                    onClick={handleClick}
                    showTooltip={true}
                    className="sim-toolbar-button"
                >
                    {__('Match Images', 'smart-image-matcher')}
                </Button>
            </div>
        );
    };

    // Document Settings Panel (appears in sidebar when opened)
    const SimDocumentPanel = () => {
        const handleClick = () => {
            if (window.simFindMatches) {
                jQuery('#sim-modal').show();
                jQuery('.sim-results-container').html('<div style="text-align: center; padding: 40px;"><span class="spinner is-active"></span><p>Analyzing content...</p></div>');
                window.simFindMatches();
            }
        };

        return (
            <PluginDocumentSettingPanel
                name="smart-image-matcher"
                title={__('Smart Image Matcher', 'smart-image-matcher')}
                icon={<SimIcon />}
                className="sim-document-panel"
            >
                <PanelBody>
                    <p style={{ marginBottom: '12px' }}>
                        {__('Automatically find and insert relevant images for your headings.', 'smart-image-matcher')}
                    </p>
                    <Button
                        isPrimary
                        icon={<SimIcon />}
                        onClick={handleClick}
                        style={{ width: '100%' }}
                    >
                        {__('Find Matching Images', 'smart-image-matcher')}
                    </Button>
                    <p style={{ marginTop: '12px', fontSize: '12px', color: '#757575' }}>
                        {__('Scans H2-H6 headings and matches them with images from your Media Library.', 'smart-image-matcher')}
                    </p>
                </PanelBody>
            </PluginDocumentSettingPanel>
        );
    };

    // Register the plugin
    registerPlugin('smart-image-matcher', {
        render: () => (
            <Fragment>
                <SimDocumentPanel />
            </Fragment>
        ),
        icon: SimIcon,
    });

    // Also add to the More Tools menu
    const { PluginMoreMenuItem } = wp.editPost;
    
    const SimMoreMenuItem = () => {
        const handleClick = () => {
            if (window.simFindMatches) {
                jQuery('#sim-modal').show();
                jQuery('.sim-results-container').html('<div style="text-align: center; padding: 40px;"><span class="spinner is-active"></span><p>Analyzing content...</p></div>');
                window.simFindMatches();
            }
        };

        return (
            <PluginMoreMenuItem
                icon={<SimIcon />}
                onClick={handleClick}
            >
                {__('Smart Image Matcher', 'smart-image-matcher')}
            </PluginMoreMenuItem>
        );
    };

    // Register More Menu item
    registerPlugin('smart-image-matcher-menu', {
        render: SimMoreMenuItem,
    });

})(window.wp);

