=== Smart Image Matcher ===
Contributors: iamkingsleyf, kraftysprouts
Tags: images, media library, alt text, featured image, automation
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.4.9
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

= 3.4.9 =
* Review no longer suggests images for another state (Idaho heading → `…-in-Virginia.jpg`). The article title counts, so headings without a state name are covered too. West Virginia and Virginia are told apart.
* Review no longer suggests images that share only the state name with the heading (“Property Tax … in Kansas” → `Bowfishing-laws-in-Kansas.jpg`). Generic words (laws, rules, requirements, legal) do not count as a shared topic.
* These rules run before any keyword or AI score, on headings and featured images, so rejected images are never sent to the model.
* Existing Review rows are re-checked once in the background after updating; failing rows are marked Rejected. Re-processing an article also clears its stale Review rows.

= 3.4.8 =
* Fixed wrong images for state and plural headings: the image index and heading lookups now use the same word stemming. The index rebuilds once in the background after updating.
* Pronouns (`you`, `your`, `own`…) no longer count as matching words.
* Featured images auto-assign only on exact / prefix slug matches again; weaker matches go to Review.
* AI-ranked heading images auto-insert only when the filename, title, or alt also matches the heading; otherwise they go to Review.

= 3.4.7 =
* Insert no longer fatals because `src/Cache` was gitignored and missing from the zip.
* Tools → AI Request Logs labels SIM ranking as OpenRouter. Availability no longer probes Anthropic/DeepSeek `/models`.
* OpenRouter App column shows Smart Image Matcher (`X-Title` / `HTTP-Referer`).

= 3.4.6 =
* Text ranking now pins OpenRouter. A `method_exists()` guard on WordPress’s magic prompt builder had skipped `using_provider( 'openrouter' )`, so calls went to Anthropic or DeepSeek.

= 3.4.5 =
* Insert Image: nested headings no longer crash with WordPress's generic critical-error page. The modal shows the real error, and the last 20 errors appear on Smart Image Matcher → Dashboard (no debug.log required).

= 3.4.4 =
* The in-content matcher skips headings that already have an image, so those headings are not sent to the AI.

= 3.4.3 =
* Excluded Image Filenames block featured images only (not heading inserts). The list keeps the filename plus extension. Add or remove one file at a time in a scrollable list. Process articles / Insert all will not reuse the same image twice in one article.

= 3.4.2 =
* Settings → AI Features groups Matching (OpenRouter text), Generation (fal.ai), Vision, and Alt text inside the same card.

= 3.4.1 =
* Featured images on Process articles / on-publish use the same AI ranking as headings when a text provider is connected. Title, slug, and focus keyword only build the shortlist. A rejected or failed AI response does not slug-assign a wrong file. Upload-time FIAA stays slug-only.

= 3.4.0 =
* When a text provider is connected, article matching and auto-insert use the AI score. Keyword matching only builds the candidate shortlist. A failed or empty AI response no longer auto-inserts a 100% filename hit (Foxes ≠ eastern-fox-squirrel).
* The post-edit matcher and the selected-heading insert command now queue AI ranking when a text provider is connected (not on editor load).
* Settings: preferred text model (mistralai/mistral-nemo) and backup (meta-llama/llama-3.1-8b-instruct). With AI Provider for OpenRouter, SIM pins provider `openrouter` and those slugs.

= 3.3.5 =
* Gutenberg: the Post sidebar Smart Image Matcher panel is gone. Open the matcher from the header pin instead.

= 3.3.4 =
* Posts list: Generate sits with Edit / Quick Edit / Trash / Preview so you can generate a featured image for one post without using bulk actions.

= 3.3.3 =
* Review no longer lists headings that already have an image in the article. Auto-insert at or above the threshold now clears leftover pending carousel rows for that heading.
* Featured matching still uses the post slug / primary-keyword filename. WordPress `-2` / `-scaled-1` copies of that file now count as an exact match. Short species files are still blocked with Excluded Image Filenames.
* Admin menu uses the original landscape icon again. The photo+check square is only for plugin updates / wp.org (`assets/icon.svg`).

= 3.3.2 =
* Review rows show the matched image filename next to the heading.
* Excluded Image Filenames accept a full uploads URL and also block in-article heading matches (case-insensitive). Review has Never use so you can block a file without opening Settings.

= 3.3.1 =
* Review lists at most 10 headings per article (Show more to expand). Click a thumbnail to open a larger image modal. Approve all on an article, or Approve all pending in the toolbar. Approve/Reject show a status on the row; Reject (and Approve) can be undone before Insert.
* Admin menu and plugin-update icon is a photo frame with a checkmark.

= 3.3.0 =
* Article processing: insert strong library matches, send the middle band to Review, generate only when the library would skip.
* Bulk Processor is Run | Review (no wizard). Review is grouped by article. Run labels use time and counts, not job IDs. Filter All pending or Last run.
* Admin menu is Dashboard, Bulk Processor, and Settings. Featured Images and Review Queue screens are removed.
* Generate featured confirms the count first. Fal.ai recovery and unsafe-featured cleanup moved onto Bulk Processor. Posts-list bulk actions prefills Bulk for admins.

= 3.2.31 =
* Added an auto-insert threshold. Automation will use it to insert strong library matches without review.

= 3.2.30 =
* Bulk Processor review step now shows real media thumbnails instead of broken images (it was pointing `<img>` at a JSON REST URL).

= 3.2.29 =
* Recovery matching respects Post Status checkboxes (default publish) and no longer lets color listicles (“Foods That Are Green/Yellow…”) steal plant photos via color-only keywords.

= 3.2.28 =
* Unmatched fal recovery rows now show why they were skipped (already has featured image, claimed this batch, below score threshold, or no subject overlap).

= 3.2.27 =
* Fal recovery now names sideloaded attachments from the SEO focus/target keyword (same as normal AI generate), not the full post title.

= 3.2.26 =
* Fixed fal recovery jobs failing with “permission denied” under Action Scheduler (no logged-in user). Improved prompt↔title matching (why/my fluff, yellow/yellowing, subject-token requirement) and show the real error on failed recovery rows.

= 3.2.25 =
* Recovery matching now strips SEO title fluff (Causes/Fixes/Prevention) so focus keywords and real topic words can clear the 60% threshold; focus/target keywords remain part of the score.

= 3.2.24 =
* Fixed recovery preview fatal/timeouts on large sites by slim-loading fal history, matching only posts without featured images, and capping the preview batch.

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
