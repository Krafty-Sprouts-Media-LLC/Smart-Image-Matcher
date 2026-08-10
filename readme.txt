=== Smart Image Matcher ===
Contributors: iamkingsleyf, kraftysprouts
Tags: images, media library, alt text, featured image, automation
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.2.23
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

* Post heading text and short section excerpts (for matching and on-demand image generation)
* Focus keyword / SEO keyphrase when available
* Image metadata (filename, title, alt text)
* Visual brief prompts and optional subject-gate checks for image generation

No AI data is sent automatically — only when you explicitly trigger AI matching or image generation. The plugin uses the WordPress AI Client API (`wp_ai_client_prompt()`) to communicate with whichever provider you configure.

== Privacy ==

The plugin stores match results and job metadata in your own database only. Update checks may contact GitHub (see External services). No post content leaves your server unless you explicitly use AI features with a configured provider.

== Changelog ==

= 3.2.23 =
* Added a Featured Images recovery UI: preview safe fal.ai matches, explicitly confirm, then recover every matched image through background jobs with live progress.
* Unmatched fal images are never imported automatically.

= 3.2.22 =
* Restored parallel fal submit/poll after durable tracking, history-based recovery, queue lifecycle tests, and a live WordPress sideload smoke test.
* Automatic fal history recovery no longer requires CSV or manually supplied request IDs.

= 3.2.21 =
* Async fal submit/poll disabled by default (prevents orphaned fal images). Added fal-recover CLI/REST for images that finished on fal but never landed in WordPress.

= 3.2.20 =
* Progress dock resumes from the server when sessionStorage is empty (e.g. modal closed before 3.2.19).

= 3.2.19 =
* Sticky progress dock after dismissing the posts-list featured AI modal (per-post status; resumes on pagination).

= 3.2.18 =
* Blocks duplicate AI Generate while a job is already queued/processing.
* Submit/poll fal pipeline for parallel batch throughput (needs fal provider 1.1.8+).
* Safe for mid-batch plugin updates; modal poll window extended to ~5 minutes.

= 3.2.17 =
* AI sideload filenames use the post keyword/title — no more fal CDN names with -2048x1152 suffixes.

= 3.2.16 =
* Featured Seedream images target 2048×1152 (cheap fal area tier); stores size + cost-tier hint (fal dashboard has real $).

= 3.2.15 =
* Featured AI images force 16:9 landscape; under-heading Generate keeps the model default.

= 3.2.14 =
* Featured AI Generate stays muted until a scan finds work; posts-list modal no longer reopens the same batch on pagination.

= 3.2.13 =
* AI-generated attachments inherit the parent post’s author (fixes empty author on background jobs).

= 3.2.12 =
* Improved AI image prompts: richer post context, topic hints, stronger visual briefs, and photo/illustration quality suffixes.

= 3.2.11 =
* Featured AI estimate sits under the toolbar as a normal description; Edit links open in a new tab.

= 3.2.10 =
* Replaced fake “N minutes” generation estimates with honest “varies by model” wording.

= 3.2.9 =
* Fixed AI text calls failing on models that reject `temperature` (visual brief / subject gate / alt / matching).

= 3.2.8 =
* Fixed bulk featured modal: literal “%d” notice, jobs stuck because status “done” was ignored, progress/status UX, and duplicate style label.

= 3.2.7 =
* Featured AI bulk scan ignores KSM Extensions (and similar) placeholder/filter fallbacks — only real stored featured images count as “already has featured image”.

= 3.2.6 =
* Fixed posts-list bulk “Generate featured images” modal error (Missing parameter: post_type).

= 3.2.5 =
* Fixed Smart Image Matcher Gutenberg sidebar/toolbar icon alignment (24px slot, flex-centered).

= 3.2.4 =
* Fixed post editor Smart Image Matcher modal (ReferenceError: cfg is not defined).
* Fixed React missing-key warning in the Gutenberg sidebar plugin.

= 3.2.3 =
* Merged Generate Featured Images into Featured Images (Match Runner + AI Generate on one page).
* Posts list bulk action now opens a dismissable modal on the same screen; jobs continue in the background after dismiss.
* Scan shows per-post skip reasons (e.g. already has featured image).
* New setting: optionally save AI visual brief as media Description (off by default).

= 3.2.2 =
* Generate Featured Images admin + posts bulk now generate one featured image per post missing a thumbnail. Heading images stay manual in the editor modal.

= 3.2.1 =
* Fixed a fatal error on load: restored the Abilities Registry import dropped in 3.2.0.

= 3.2.0 =
* Added Modal Generate All (hard-confirm estimate) and Reject (skip combo until Regenerate).
* Added SIM → Generate Images admin page (scan → estimate → enqueue) and Posts list bulk action (later scoped to featured-only in 3.2.2).
* Added preferred image style, vision verification toggle (off by default), and auto-generate featured image on publish (toggle, featured-only).

= 3.1.4 =
* Fixed WordPress AI “Generate featured image” imports: title/slug/filename use focus keyword (else post title), media is attached to the post, and the “Generated by…” description is reduced to the visual brief.

= 3.1.3 =
* Fixed on-demand generation so SIM’s preferred fal model is used (no silent Flux Schnell fallback).
* Fixed importing generated images when the AI Client returns inline image bytes instead of a remote URL.

= 3.1.2 =
* Added Preferred image model setting (Seedream 5.0 Pro default; also GPT Image 2, Nano Banana Pro, Nano Banana 2). SIM selects models; Connectors only store provider credentials.
* Image generation availability is checked separately from text AI so Generate only appears when curated image models can run.

= 3.1.1 =
* Added on-demand AI image generation from the matcher modal (Generate / Regenerate) when no suitable library match exists. Uses a configured image provider via Settings → Connectors and queues work in Action Scheduler.
* Added settings for on-demand generation, subject gate, and generated-image alt text mode (keyword or descriptive).
* Featured-image AI fallback now shares the same generator pipeline.

= 3.1.0 =
* Fixed the media library index backfill silently failing to finish on large libraries (5,000+ images), leaving newer/later images unmatchable even though they were searchable in Media Library. The backfill now runs in resumable batches and self-heals if interrupted. Added `wp sim reindex` for manual reindexing.
* Fixed the scheduled Featured Image Auto-Assigner failing on large sites because it ran synchronously in one background job. It now runs in the same safe batched queue as the manual Match Runner.
* Fixed orphaned background jobs left over from an internal naming change that failed forever with "no callbacks registered" errors.
* Added more frequent scheduled run options: every 4, 6, or 8 hours (in addition to hourly/twice daily/daily).

= 3.0.9 =
* Added Excluded Image Filenames for Featured Image Auto-Assigner (blocklist for images like fly-fishing.jpg).
* Excluded images are skipped on upload, Match Runner, and scheduled runs, and flagged by Fix Incorrect Featured Images.

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
