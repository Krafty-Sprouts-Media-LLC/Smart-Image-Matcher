=== Smart Image Matcher ===
Contributors: iamkingsleyf, kraftysprouts
Tags: images, media library, alt text, featured image, automation
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.0.8
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically scans your media library and intelligently inserts relevant images next to headings in posts and pages.

== Description ==

Smart Image Matcher scans your posts and pages for headings (H2–H6) and matches relevant images from your media library to each heading using keyword-based analysis or AI-powered matching.

**Key Features:**

* Keyword-based image-to-heading matching
* AI-powered matching via any configured AI provider (Settings → Connectors, WordPress 7.0+)
* Post editor modal with image previews and confidence scores
* Image carousel — browse up to 10 alternative matches per heading
* Smart hierarchy filtering — skip redundant sub-headings automatically
* Advanced linguistics — stemming, US/British spelling variants, possessives
* Featured Image Auto-Assigner — match post slugs to image filenames on upload
* Scheduled featured-image assignment with overwrite control
* Bulk Processor — match and review hundreds of posts at once
* AI alt-text generation on upload
* Vision-based content matching
* Match analytics dashboard
* Compatible with all major caching plugins
* WordPress Abilities API integration — discoverable via command palette

== Installation ==

1. Upload the plugin to `/wp-content/plugins/smart-image-matcher/` or install via the WordPress plugin screen.
2. Activate through the Plugins screen.
3. Go to **SIM → Settings** to configure.
4. Open any post or page and click **Smart Image Matcher** to start matching.

== Frequently Asked Questions ==

= Do I need an API key? =

Keyword matching works with no external services. AI features require a provider configured in **Settings → Connectors** (WordPress 7.0+).

= Does this work with Gutenberg? =

Yes. The insertion engine is built on the Gutenberg block tree.

= Does this work with the Classic Editor? =

Yes.

= Is it multisite compatible? =

Yes, on a per-site basis.

== Integrations ==

Smart Image Matcher registers the following WordPress Abilities (WordPress 6.9+), discoverable from the admin command palette, MCP-aware AI agents, and the `@wordpress/abilities` JS API:

* `smart-image-matcher/find-matches-for-post` — find matching images for all headings in a post
* `smart-image-matcher/insert-image-after-heading` — insert an image after a specific heading
* `smart-image-matcher/score-image-against-heading` — score an image's relevance to a heading
* `smart-image-matcher/assign-featured-image-by-slug` — assign a featured image by slug match
* `smart-image-matcher/queue-bulk-match` — queue a bulk match job

== External services ==

**GitHub (plugin updates)**

This plugin checks GitHub for new releases so sites installed from the public repository can update from the WordPress admin (via Plugin Update Checker).

* Service: GitHub — https://github.com/
* Repository: https://github.com/Krafty-Sprouts-Media-LLC/Smart-Image-Matcher/
* Data sent: site URL / WordPress version metadata typical of update checks (no post content)
* When: periodically on admin requests, same pattern as WordPress.org update checks

Disable with `define( 'SMART_IMAGE_MATCHER_DISABLE_GITHUB_UPDATES', true );` if you distribute a build that should not phone home to GitHub.

**AI providers (optional)**

This plugin optionally connects to AI providers configured in **Settings → Connectors** (requires WordPress 7.0+). When AI features are used, the following data is sent to the configured provider:

* Post heading text
* Image metadata (filename, title, alt text)

No AI data is sent automatically — only when you explicitly trigger AI matching. The plugin uses the WordPress AI Client API (`wp_ai_client_prompt()`) to communicate with whichever provider you configure.

== Privacy ==

The plugin stores match results and job metadata in your own database only. Update checks may contact GitHub (see External services). No post content leaves your server unless you explicitly use AI features with a configured provider.

== Changelog ==

= 3.0.8 =
* Fixed a flash of "Smart Image Matcher" text at the top-left when opening the block editor.
* Classic Editor trigger button is only rendered on classic screens and mounted below the title.
* Restored GitHub → WordPress automatic updates from public releases (tags without a required v prefix).

= 3.0.2 =
* Improved scheduled Featured Image Auto-Assigner reporting with next action time, total processed, duration, statuses, and filter details.
* Added manual Featured Image Auto-Assigner filters for multiple post statuses, featured image state, and max posts.
* Changed manual matching defaults to target posts missing featured images instead of queueing every article.
* Added scheduled-run controls for multiple post statuses and featured-image state.
* Added clearer help text and notices for daily schedules, overwrite behavior, skipped posts, and unmatched posts.
* Fixed the scheduled automation badge so it reflects enabled/disabled state instead of always appearing active.

= 3.0.1 =
* wp.org compliance: removed all premium feature gating (Guideline 5) — all features now fully enabled
* wp.org compliance: removed load_plugin_textdomain() (auto-loaded since WordPress 4.6)
* wp.org compliance: excluded license-check and upgrade-link code from the build
* wp.org compliance: documented AI external service usage in readme
* Updated Action Scheduler from 3.9.3 to 4.0.0
* Removed "Pro"/"Upgrade" labels from all admin pages
* See CHANGELOG.md for full history

= 3.0.0 =
* Complete rebuild on a clean PSR-4 architecture
* Block-tree-based insertion engine (no more byte-offset drift)
* REST API replaces admin-ajax.php
* WordPress Abilities API integration
* Action Scheduler for background processing
* Single smart_image_matcher_settings option (no autoloaded option bloat)
* Provider-agnostic AI via wp_ai_client_prompt()
* Full Bulk Processor with find → queue → review → insert workflow
* AI alt-text generation and vision-based matching
* Scheduled featured-image assignment
* See CHANGELOG.md for full history

== Upgrade Notice ==

= 3.0.2 =
Featured-image scheduling and manual runs now include clearer targeting controls and reporting.

= 3.0.0 =
Major rebuild. Settings are migrated automatically. Match history prior to 3.0.0 is not migrated (heading positions were unstable in prior versions).
