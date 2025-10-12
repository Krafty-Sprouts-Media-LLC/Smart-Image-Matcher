=== Smart Image Matcher ===
Contributors: kraftysprouts
Tags: images, media, automation, ai, matching
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically scans the media library and intelligently attaches relevant images to headings within posts and pages.

== Description ==

Smart Image Matcher is a WordPress plugin that automatically scans the media library and intelligently attaches relevant images to headings within posts and pages. The plugin offers two modes: a fast keyword-based matching system and an AI-powered matching system using the Claude API for enhanced accuracy.

**Key Features:**

* Automatic image-to-heading matching
* Two modes: Keyword (fast) and AI (accurate)
* Modal interface for single post processing
* Bulk processing for multiple posts (coming in future updates)
* Smart hierarchy handling (H2, H3, H4)
* Cache compatibility with major plugins
* API rate limiting and cost controls
* Complete uninstall cleanup

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/smart-image-matcher` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Use the Settings->Smart Image Matcher screen to configure the plugin.
4. (Optional) Enter your Claude API key for AI-powered matching.

== Frequently Asked Questions ==

= Does this plugin work with Gutenberg? =

Yes, Smart Image Matcher is fully compatible with the Gutenberg block editor.

= Do I need an API key? =

No, the keyword matching mode works without any API key. The AI mode requires a Claude API key from Anthropic.

= Will this work with my caching plugin? =

Yes, Smart Image Matcher is compatible with all major WordPress caching plugins including WP Rocket, W3 Total Cache, WP Super Cache, and more.

== Changelog ==

= 1.0.3 =
* Fixed: Added button to WordPress Admin Bar (top black bar) - now ALWAYS visible
* Fixed: Enhanced Gutenberg detection with retry logic and multiple selectors
* Added: Admin Bar integration for 100% reliability across all editors

= 1.0.2 =
* Fixed: Critical fix for Gutenberg Block Editor support - button now appears in toolbar
* Fixed: Button not visible on post edit screen for Gutenberg users
* Changed: Added proper Gutenberg integration hooks

= 1.0.1 =
* Fixed: Corrected image matching priority to properly prioritize Title (90 points) and Alt Text (85 points)
* Fixed: Increased Filename score to 100 points for better accuracy
* Changed: Updated AI matching to send metadata in proper priority order

= 1.0.0 =
* Initial release
* Keyword-based matching engine
* AI-powered matching with Claude API
* Post editor modal interface
* Image insertion functionality
* Settings page
* Cache compatibility

== Upgrade Notice ==

= 1.0.2 =
Critical fix: Adds Gutenberg Block Editor support. Button now appears for all users.

= 1.0.1 =
Critical update: Fixes image matching priority to better match real-world metadata usage.

= 1.0.0 =
Initial release of Smart Image Matcher.

