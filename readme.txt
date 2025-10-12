=== Smart Image Matcher ===
Contributors: kraftysprouts
Tags: images, media, automation, ai, matching
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
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

= 1.3.0 =
* Major simplification: Removed undo functionality and countdown timers
* Added: Warning notice reminding users to review matches before inserting
* Added: Clear in-modal notices - "Page will reload to show changes"
* Changed: Both insert buttons show progress then reload automatically
* Improved: No browser prompts, all messages shown in modal
* Removed: 100+ lines of timer/undo code

= 1.1.1 =
* Fixed: CRITICAL - Gutenberg validation by removing width/height from img tag
* Fixed: Gutenberg expects exactly 3 img attributes (src, alt, class) - not 5
* Fixed: Let Gutenberg's sizeSlug handle dimensions automatically
* Changed: Clean block format matching Gutenberg schema exactly

= 1.1.0 =
* Fixed: CRITICAL - Proper Gutenberg support using WordPress Block Editor API
* Fixed: Uses parse_blocks() and serialize_blocks() for Gutenberg content
* Fixed: Separate code paths for Gutenberg vs Classic Editor  
* Changed: 100% WordPress native functions - no manual HTML/block building

= 1.0.9 =
* Added: Enhanced diagnostics showing if image exists in DB after save
* Improved: Browser console shows content length changes and verification results
* Added: Warning alert if image not found in database after insertion

= 1.0.8 =
* Fixed: CRITICAL - Gutenberg auto-save conflict preventing insertions
* Fixed: Images now properly save and appear after page reload
* Improved: Force cache flush and auto-save deletion for Gutenberg compatibility

= 1.0.7 =
* Fixed: Added comprehensive error logging to debug insertion issues
* Added: Verification checks for post and image existence
* Debugging: Check wp-content/debug.log for detailed logs (all prefixed with "SIM:")

= 1.0.6 =
* Improved: Enhanced scoring with +10 bonus for intentionally-set titles
* Changed: Removed caption from scoring (rarely used)
* Improved: Simplified to 3-field scoring: Filename, Title, Alt Text
* Improved: Better rewards for properly maintained media libraries

= 1.0.5 =
* Fixed: Page now auto-reloads after image insertions to show changes immediately
* Fixed: Modal no longer stays open indefinitely - auto-closes or reloads
* Added: 10-second countdown with auto-reload after bulk insertion
* Added: "Reload Now" and "Cancel Auto-Reload" buttons for user control

= 1.0.4 =
* Fixed: Critical scoring algorithm bug - exact matches now properly prioritized
* Fixed: "western-black-widow.jpg" now scores higher than "types-of-black-widow.jpg" for "Western Black Widow" heading
* Improved: Phrase matching with bonus for exact heading text in filename/title
* Improved: Penalty for overly verbose/generic filenames

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

