# Changelog

All notable changes to Smart Image Matcher are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
=================================================================================


## [3.2.27] - 11/08/2026

### Fixed

- Fal recovery queued context without `focus_keyword`, so sideloaded media used the full post title for filename/title/alt. Recovery now loads Rank Math / Yoast / SEOPress / TSF focus keywords (same as normal AI generate), including a finalize-time fallback if context omitted it.

## [3.2.26] - 10/08/2026

### Fixed

- Fal recovery background jobs failed for every match: `recoverFalJob()` required `current_user_can( 'edit_post' )`, but Action Scheduler runs with no logged-in user. Capability is now enforced only when a user is present (queue-time auth already happened).
- Recovery matching still under-matched because title tokens kept `why`/`my` and `leaves`≠`leaf`, and `yellow`≠`yellowing`. Matching now strips question fluff, aligns light morphology, and requires a subject/core token hit so symptom-only overlap cannot assign the wrong plant. Failed recovery rows show the server error message.

## [3.2.25] - 10/08/2026

### Fixed

- Recovery matching was under-matching (~12/100) because long SEO titles (`Causes`, `Fixes`, `Prevention`, …) diluted token overlap below 60%. Those boilerplate tokens are stripped before scoring. Focus/target keywords (Rank Math / Yoast / SEOPress / TSF) were already included via `max(title, focus)` and remain so; unmatched preview rows now include `score` + nearest title for debugging.

## [3.2.24] - 10/08/2026

### Fixed

- Recovery Preview no longer dies with a WordPress “critical error” on large libraries: fal history payloads are slimmed to prompt + image URL, candidate posts are limited to missing featured images, matching is pre-tokenized, and fatals return a REST error instead of an HTML crash page.

## [3.2.23] - 10/08/2026

### Added

- Featured Images now includes a complete orphan-recovery UI: choose a fal history window, preview safe post matches, confirm once, and monitor background recovery progress.
- One Action Scheduler job is queued per matched image, avoiding browser/REST timeouts during large recoveries.

### Changed

- Recovery candidate loading now batches posts and post meta efficiently.
- Unmatched fal images remain untouched; the UI never imports them automatically.

## [3.2.22] - 10/08/2026

### Added

- Automatic orphan recovery can query recent successful fal request history, including input/output payloads, then match generated images to posts missing featured images—no CSV or manual request IDs required.
- Recovery records fal request/model IDs on imported attachments so retries cannot duplicate media.
- Unit coverage for fal payload extraction and prompt-to-post recovery matching.

### Changed

- Async fal submit/poll is enabled by default again after queue lifecycle unit tests and a live fal-history → WordPress Media Library → featured-image recovery smoke test passed. Durable handles and request-history fallback prevent paid completions from being orphaned.

## [3.2.21] - 10/08/2026

### Fixed

- **Async fal submit/poll is OFF by default** (`sim_ai_image_use_async_queue` filter). 3.2.18–3.2.20 could mark jobs failed (5‑minute deadline / wiped tracking) after fal had already billed and finished the image, leaving orphans on fal with nothing in WordPress. Generation again waits in one Action Scheduler worker until fal completes.
- Poll path (if re-enabled): 30‑minute deadline, durable `_sim_fal_pending_*` post meta, keep fal handles on failure, retry on transient HTTP errors.
- Recovery: `wp sim fal-recover --all` or `--post_id=… --request_id=… --model_id=…`, plus REST `POST /generate-images/recover`.

## [3.2.20] - 10/08/2026

### Fixed

- Sticky progress dock can resume from the server (Action Scheduler) when the modal was closed before 3.2.19 / with no sessionStorage — hard-refresh the posts list and the dock appears for still-running featured jobs.

## [3.2.19] - 09/08/2026

### Improved

- After dismissing the posts-list featured AI modal, a sticky progress dock stays on screen with per-post status (queued / generating / done / failed), remaining count, Edit links, and “Open dialog”. Progress also resumes after list pagination via sessionStorage.

## [3.2.18] - 09/08/2026

### Added

- Server-side “already queued” guard for AI image generation (status + pending Action Scheduler start/poll actions) so a second Generate cannot double-charge fal.
- Submit/poll pipeline: start jobs build the brief and submit to fal, then short poll jobs finish sideload — large batches can run many fal jobs in parallel. Requires **AI Provider for fal.ai 1.1.8+** (`FalQueueClient`); older fal falls back to the previous blocking generate path.
- Upgrade-safe job runner: pending pre-3.2.18 AS actions (full payload in args) still complete; in-flight PHP workers finish with the code loaded for that request.

### Improved

- Modal generate status polling waits up to ~5 minutes (was ~60s) to match fal queue times.

## [3.2.17] - 07/08/2026

### Fixed

- AI image URL sideload no longer keeps fal’s CDN filename (which included size suffixes like `-2048x1152`). Files are named from the keyword/title, same as the binary sideload path.

## [3.2.16] - 07/08/2026

### Improved

- Featured Seedream 16:9 generations target **2048×1152** via the fal provider (cheap pixel-area tier). SIM stores output width/height and a Seedream cost-tier *hint* on the attachment (real $ still comes from the fal dashboard — API responses do not include price).

## [3.2.15] - 05/08/2026

### Improved

- Featured AI images force **16:9 landscape** via WP AI Client (`as_output_media_aspect_ratio`), mapped by the fal provider to Seedream `image_size` / Nano Banana `aspect_ratio`. Under-heading Generate keeps the model default. Soft landscape cue also appended to featured prompts.

## [3.2.14] - 05/08/2026

### Fixed

- Featured AI Generate button no longer looks primary/clickable while disabled; Scan is the primary action until a scan finds work, then Generate becomes primary.
- Posts-list featured AI modal no longer reopens the same batch when paging the list (strip one-shot query args from pagination; remember dismissed/queued batches in sessionStorage).

## [3.2.13] - 05/08/2026

### Fixed

- AI-generated (and WP AI featured) attachments now get `post_author` set to the parent post’s author when background jobs run with no logged-in user.

## [3.2.12] - 05/08/2026

### Improved

- AI image prompts: richer ~160-word post/section context, category/tag topic hints, stronger visual-brief instructions (subject/setting/lighting/composition), featured vs heading framing, and photo/illustration quality suffixes (no text/watermark/logo) before fal generation.

## [3.2.11] - 05/08/2026

### Changed

- Bulk featured AI estimate sits under the toolbar as a normal description (`N to generate. …`), not a bold mid-panel timer line.
- Featured Images scan card labels the wait as “Typical wait” instead of “Estimated duration”.
- Edit links in featured AI scan tables open in a new tab (`target="_blank"` + `rel="noopener noreferrer"`).

## [3.2.10] - 05/08/2026

### Changed

- Generation time estimates no longer multiply images × 60 seconds. UI now says time varies by model (often a few minutes each).

## [3.2.9] - 05/08/2026

### Fixed

- Featured/heading AI generation failed with `Bad Request (400) - temperature is deprecated for this model` during the visual-brief text step. `ProviderBridge::generateText()` and vision scoring no longer send `temperature` by default (optional override still available).

## [3.2.8] - 05/08/2026

### Fixed

- Posts-list featured AI modal showed the literal string `%d featured image job(s) queued…` because JS `sprintf` only handled `%1$d`-style placeholders; plain `%d` is now substituted.
- Modal never treated successful jobs as finished (JobRunner status is `done`, poll only looked for `completed`), so progress stalled or falsely showed “Completed 2 of 2” while images were not applied in the UI.
- Progress total no longer shrinks mid-run; per-post status updates to Queued / Generating / Featured image set / Failed; final notice tells you to refresh the list.
- Duplicate “Photo (realistic)” label in the bulk modal toolbar (broken screen-reader-only label).

## [3.2.7] - 05/08/2026

### Fixed

- Featured AI bulk scan treated **KSM Extensions** (and similar) placeholder/filter featured images as real thumbnails. Scan now uses stored `_thumbnail_id` via `get_metadata_raw()` and ignores known global fallback attachment IDs, so posts with only a placeholder can queue AI featured generation.

## [3.2.6] - 05/08/2026

### Fixed

- Posts list bulk **Generate featured images** modal failed scan with “Missing parameter(s): post_type”; bulk modal now sends `post_type` and the REST route treats it as optional when explicit `post_ids` are provided.

## [3.2.5] - 05/08/2026

### Fixed

- Gutenberg **Smart Image Matcher** icon alignment in the post sidebar panel header and editor toolbar (24px slot, centered).

## [3.2.4] - 05/08/2026

### Fixed

- Post editor **Smart Image Matcher** modal failed to load (`cfg is not defined` in `modal.js`); restored read from localized `smartImageMatcherData.aiImageStyle`.
- Gutenberg plugin React warning: missing `key` props on `SimPlugin` children.

## [3.2.3] - 05/08/2026

### Added

- **Save prompt as media Description** setting (off by default): when disabled, AI visual briefs are stored only in private attachment meta, not the media library Description field.

### Changed

- **Featured Images** is now the single admin home: **Match Runner** (slug/filename) + **AI Generate Featured Images** card (missing thumbnails only).
- Removed the separate **Generate Featured Images** submenu; old URLs redirect to Featured Images.
- Posts list **Generate featured images…** bulk action stays on `edit.php` with a **dismissable modal**; Action Scheduler jobs keep running after dismiss.
- Featured scan API returns a `skipped` list with reason codes (`already_has_featured`, etc.) so bulk UIs explain why posts were not queued.

## [3.2.2] - 05/08/2026

### Changed

- **Generate Featured Images** admin page and posts-list bulk action now queue **one featured image per post** (posts missing a thumbnail only). In-content heading images stay manual in the post editor modal (Generate / Generate All) — no need to disable on-demand generation to avoid bulk heading spam.

## [3.2.1] - 05/08/2026

### Fixed

- Restored missing `use SmartImageMatcher\Abilities\Registry as AbilitiesRegistry` in `Plugin.php` (fatal on `plugins_loaded` after 3.2.0 edits).

## [3.2.0] - 05/08/2026

### Added - Image generation shared foundations

- **Preferred image style** setting (`photo` / `illustration`) for on-demand generation, bulk runs, and auto-publish featured images.
- **Vision verification** toggle in Settings (off by default): after generation, scores the image against the focus keyword or heading; failures stay pending for modal review with `_sim_generated_vision_failed` meta instead of auto-approving.
- **GenerationRejectionStore** (`sim_ai_generation_rejections` option): blocks re-generation for rejected `(post, heading_hash, keyword, style)` combos; Regenerate bypasses via `force`.
- **Auto-generate featured image on publish** toggle: on first publish without a featured image, tries FIAA slug match then queues one featured AI image via Action Scheduler.
- `JobRunner` sets the post thumbnail when a queued job completes with `heading_hash` `featured`.

### Added - Generate Images admin page + posts bulk action

- **Generate Images** submenu (SIM → Generate Images): filter by post type/status, scan for headings with weak or no keyword matches, show image count + duration estimate, hard-confirm **Generate All**, then poll per-job status via existing `generate-image/status`.
- REST: `POST /generate-images/scan` (heading eligibility via Matcher + `AiImageGenerator::findGenerated`) and `POST /generate-images/enqueue` (batch Action Scheduler jobs with rejection/dedup skips).
- Posts list bulk action **Generate images…** redirects selected posts to the admin page with `post_ids` pre-filled for scan → confirm → enqueue.

### Added - Modal Generate All + Reject

- Modal **Generate All** button (eligible unmatched/weak headings only): hard-confirm with count and time estimate, then sequential queue + poll using preferred style from settings.
- **Reject** on AI-generated matches stores a rejection so future Generate / Generate All / bulk skips that combo until **Regenerate**.

## [3.1.4] - 05/08/2026

### Fixed - WordPress AI “Generate featured image” vs SIM attachment rules

- When WP AI imports a generated featured image, SIM now rewrites title/slug/filename to the focus keyword (else post title), attaches the media to the post (no longer Unattached), strips the “Generated by … Prompt:” wrapper down to the visual brief, and applies SIM alt-mode rules.
- Clarifies that WP AI only sets `featured_media` in the editor draft — the fal editor guard now also saves the post when generation completes so closing the tab does not lose the featured image.

## [3.1.3] - 04/08/2026

### Fixed - Image generation model pinning and sideload from fal results

- Pin image generation to the `fal-ai` provider and SIM’s preferred model list via `using_provider( 'fal-ai' )` + `using_model_preference()`, so the Client no longer falls back to Flux Schnell when preferences miss.
- Use `generate_image_result()` and accept both remote URLs and inline base64 when sideloading into the Media Library (fixes empty/failed import when the File DTO has no remote URL).

## [3.1.2] - 04/08/2026

### Added - Preferred image model in SIM settings

- Added **Preferred image model** (Seedream 5.0 Pro default, plus GPT Image 2, Nano Banana Pro, Nano Banana 2). Connectors still hold API keys only; SIM chooses the model via `using_model_preference()` against that closed list — no open-ended “first suitable” pick.
- Generate / image readiness now probes image-capable models separately from text providers (PromptBuilder still needs a text connector).

## [3.1.1] - 04/08/2026

### Added - On-demand AI image generation in the modal

- When keyword matches are missing or weak (confidence under 40%), the modal can queue an AI image for that heading via Settings → Connectors (image-capable provider required).
- Generation runs in Action Scheduler: visual brief + optional subject gate inside the job, then sideload to the media library with `_sim_generated*` meta, alt mode (`keyword` / `descriptive`), and an “AI Generated” badge with Regenerate.
- New REST routes: `POST …/posts/{id}/generate-image` and `GET …/generate-image/status` (section text re-extracted server-side by heading hash).
- Settings: On-demand image generation, Subject gate, Generated image alt text.
- Featured-image AI fallback (`AiFeaturedImage`) now uses the shared `AiImageGenerator`.

## [3.1.0] - 30/07/2026

### Fixed - Media library index backfill on large libraries

- Replaced the unchunked `ImageRepository::backfillAll()` loop (which paginated the entire media library inside a single Action Scheduler execution) with a resumable `backfillBatch()` that processes one page per execution and persists a cursor. On libraries of 5,000+ images the old loop could exceed PHP's `max_execution_time` / Action Scheduler's async-request watchdog and be killed partway through, permanently leaving the rest of the library unindexed and therefore invisible to the matcher even though the images existed and were searchable in Media Library.
- `JobRunner::runIndexBackfill()` now runs one batch and self-enqueues the next instead of looping internally.
- Added `Queue::maybeResumeIndexBackfill()`, called on every request via `Migrator::maybeRun()`, which re-enqueues an incomplete backfill that has no pending/in-progress action — self-healing after a killed or abandoned run with no manual intervention required.
- Added `wp sim reindex` (`--fresh`, `--batch-size`) for manually resuming or restarting the backfill outside of Action Scheduler.

### Fixed - Scheduled Featured Image Auto-Assigner reliability

- `FiaaCron::runScheduledAssignment()` no longer calls `FeaturedImageService::run()` synchronously inside a single Action Scheduler execution (which scored every candidate post against the entire attachment slug map in one PHP process and could fail outright on large sites). It now collects candidate post IDs and queues a batched job through the same resumable pipeline (`Queue::enqueueFiaaRun()` / `JobRunner::runFiaaRunJob()`) the manual "Run Matcher" button already used safely.
- Added an overlap guard so a new scheduled tick is skipped while a previous scheduled run is still queued or processing, instead of starting a second concurrent pass over the same posts.
- The "Last Scheduled Run" summary on the Featured Images admin page is now written when the batched job completes (`JobRunner::finalizeScheduledFiaaRun()`), preserving the same fields the old synchronous run used to report.

### Fixed - Orphaned Action Scheduler hooks after the sim_* rename

- Actions still scheduled under the pre-rename `sim_*` hook names (e.g. `sim_queue_index_backfill`, `sim_fiaa_scheduled_run`) failed forever with "no callbacks registered" since nothing listens for those names anymore. Added a one-time migration (`Migrator::migration4ClearLegacyActionHooks()`) plus cleanup on deactivation and uninstall to cancel them.

### Added - More frequent scheduled run intervals

- Added `every_4_hours`, `every_6_hours`, and `every_8_hours` options alongside hourly/twice-daily/daily for the Featured Image Auto-Assigner's scheduled run. Safe to use on large libraries now that each run is a bounded, resumable batch rather than a single synchronous pass.

## [3.0.9] - 22/07/2026

### Added - FIAA excluded image filenames

- Added an **Excluded Image Filenames** setting (Settings + Featured Images page) so specific images can be blocked from featured-image auto-assign.
- Accepts filenames or slugs (`fly-fishing` or `fly-fishing.jpg`), one per line (commas also accepted).
- Excluded images are skipped on upload, Match Runner, and scheduled runs.
- Existing featured images that use an excluded filename are flagged as unsafe by **Fix Incorrect Featured Images** (method: `excluded`) so they can be cleared without changing matching rules for other short stems.

## [3.0.8] - 21/07/2026

### Fixed - Post editor FOUC

- Stopped the Classic Editor "Smart Image Matcher" trigger from flashing at the top-left while the block editor loads.
- The classic trigger markup is no longer rendered on block-editor screens; Gutenberg continues to use the sidebar/document panel.
- On Classic Editor screens the button is mounted below the title field before it is shown.

### Added - GitHub → WordPress updates

- Restored automatic updates from the public GitHub repo via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker).
- Releases are published by GitHub Actions when a semver tag is pushed (`3.0.8` — leading `v` optional, not required).
- Release zips attach as `smart-image-matcher.zip` and use the matching `CHANGELOG.md` section as the public release notes.
- GitHub updates can be disabled for wp.org builds with `SMART_IMAGE_MATCHER_DISABLE_GITHUB_UPDATES` or the `smart_image_matcher_enable_github_updates` filter.

## [3.0.7] - 27/06/2026

### Fixed - Featured Images notice placement

- Audit scan and cleanup notices now render inside the **Fix Incorrect Featured Images** card instead of the Match Runner card.

## [3.0.6] - 27/06/2026

### Fixed - Featured image audit scan

- Fixed **Scan for Unsafe Featured Images** failing before the REST request reached WordPress by sending scan filters as query parameters instead of a GET request body.

## [3.0.5] - 27/06/2026

### Added - Featured image audit cleanup (no CLI)

- Added **Fix Incorrect Featured Images** tools on the Featured Images admin page.
- **Scan for Unsafe Featured Images** lists posts whose current featured image would not pass today's strict auto-assign rules.
- **Clear Unsafe Featured Images** queues a background job that removes only those risky assignments (exact/prefix matches are left alone).
- Added REST endpoints: `GET /featured-image-audit` and `POST /featured-image-audit/clear`.
- Added `FeaturedImageAudit` service and `FeaturedImageService::isAutoAssignSafePair()` helper.

## [3.0.4] - 27/06/2026

### Changed - Featured image slug matching safety

- Match Runner now auto-assigns only **exact**, **prefix**, and **reverse-prefix** filename slug matches.
- Token-overlap matches (e.g. `bass-fishing-season` vs `bass-fishing-regulations`) are held for manual review instead of being assigned.
- Added distinguishing-term detection so posts and images that share state/topic words but differ on key terms are flagged as **held for review**.
- Updated Featured Images help copy and Held For Review rules to document the stricter auto-assign policy.

### Added - Tests

- Added unit tests for featured-image slug scoring, including the Rhode Island season/regulations false-positive case.

## [3.0.3] - 27/06/2026

### Added - Featured Images admin (variation 6)

- Redesigned the Featured Images page with a dual-panel layout (run controls left, coverage sidebar right).
- Added a **Save Run Settings** button so post status, featured image filter, max posts, and overwrite choices persist in `smart_image_matcher_settings`.
- Added REST endpoints to read and save manual Run Matcher settings (`/featured-image-manual-settings`).
- Manual runs now auto-save settings when **Run Matcher** is clicked.

### Changed - Post status handling

- Post Status checkboxes now list every queryable WordPress post status (including custom statuses), not a hardcoded five-item list.
- Default saved manual run statuses are `publish` and `draft`.
- Scheduled FIAA status pickers on the settings page also use the dynamic status list.

### Fixed - Featured Images form layout

- Fixed Run Matcher options using a stale 3-column grid instead of the variation-6 two-column layout.
- Normalized select and number input sizing so controls align with the prototype instead of oversized WordPress admin defaults.
- Restyled Held For Review rule rows and badges to match the variation-6 card list (stacked items, amber Hold pills, green Auto pills).

### Changed - Match Runner naming and progress UX

- Renamed the manual featured-image tool from **Run Matcher** to **Match Runner** on the Featured Images page and dashboard.
- Match Runner now shows a highlighted progress panel, percent-complete label, **Running...** button state, and auto-scroll to progress when a job starts or resumes.

## [3.0.2] - 2026-06-27

### Fixed - Featured Image Auto-Assigner scheduling

- Added scheduled-run checkbox filters for multiple post statuses and featured-image state so daily automation can target only the posts that should be checked.
- Added scheduled-run summary details for total processed, duration, statuses used, featured-image filter, and overwrite state.
- Added a next scheduled action display on the Featured Images page when Action Scheduler can report the next `smart_image_matcher_fiaa_scheduled_run`.
- Fixed the Scheduled Auto-Assignment card badge so it reflects enabled/disabled state instead of always appearing active.

### Changed - Manual Featured Image runs

- Added manual Run Matcher checkbox filters for multiple post statuses, plus featured-image state and max queued posts.
- Changed manual run defaults to target posts missing featured images instead of queueing every article and then skipping posts with thumbnails.
- Made overwrite mode explicitly switch to the full featured-image sweep behavior so existing images are only replaced intentionally.

### Changed - Admin help text

- Added plain-language guidance for daily schedule timing, background processing, overwrite behavior, skipped posts, unmatched posts, and safe matching holds.
- Clarified settings descriptions for matching, caching, upload-time assignment, scheduled post types, scheduled statuses, and scheduled featured-image filters.

## [3.0.1] - 2026-06-20

### Fixed - wp.org Plugin Directory review compliance

- Removed all premium feature gating to comply with Guideline 5 (no trialware/locked features). `Premium::has()` now always returns `true` — all features are fully enabled in the wp.org build.
- Removed all "Pro", "Upgrade", and "Premium" labels/badges from settings pages, dashboard, featured-images page, and bulk processor page.
- Removed `load_plugin_textdomain()` call (WordPress auto-loads translations for wp.org-hosted plugins since 4.6).
- Excluded `src/Premium/License.php` and `src/UI/PremiumLock.php` from the wp.org zip build (license-check and upgrade-link code).
- Updated Action Scheduler from 3.9.3 to 4.0.0.
- Added `iamkingsleyf` to the readme.txt contributors list.
- Updated readme.txt to remove the "Pro Features" section and document AI external service usage.
- See `docs/wp-org-review-fixes.md` for full details.

### Fixed - Plugin Check compliance

- Made `Migrator::migration2AddHeadingHash()` idempotent for partially migrated installs.
- Fixed activation failure when `wp_smart_image_matcher_matches.heading_text` already exists but `heading_hash` or `heading_tag` is missing.
- Added per-column and per-index existence checks before altering `wp_smart_image_matcher_matches` and `wp_smart_image_matcher_queue`.
- Ensured activation runs the inverted-index migration so `wp_smart_image_matcher_image_terms` is created immediately.
- Added direct-access guards to plugin PHP files that were flagged by Plugin Check.

### Fixed - Bulk processor

- Fixed the Bulk Processor admin page rendering as an empty shell when the page-specific script was not enqueued or failed during boot.
- Fixed Bulk Processor step content mounting into the breadcrumb instead of the panel area because breadcrumb items and panels shared `data-step` attributes.
- Renamed Bulk Processor Step 3/4 copy from "Processing" and "Review Queue" to "Find Matches" and "Review & Insert" to clarify that matching happens before any post content is changed.
- Changed cancelled Bulk Processor jobs to return to Configure with a visible cancellation notice instead of leaving the user stranded on Step 3.
- Fixed cancelled Bulk Processor jobs re-opening Step 3 after refresh by removing arbitrary recent-job auto-resume and remembering cancelled job IDs locally.
- Marked cancelled/failed/completed Bulk Processor jobs with `finished_at` when status is updated through the REST controller.
- Added durable Bulk Processor job resume behavior after page refresh/reload using the persisted current job ID.
- Added a Step 4 "Cancel Review" action and stopped completed jobs from persisting as the current job after refresh/navigation.

### Fixed - Settings

- Fixed Plugin Check errors for unescaped numeric settings output and featured-image coverage output.
- Reduced Plugin Check warnings for custom-table SQL, template-local variable naming, intentional `smart_image_matcher_` hooks, bundled Composer metadata, and non-wp.org textdomain loading.
- Reworked Abilities and WP AI Client calls so future WordPress APIs are invoked only through runtime-checked dynamic callables on older supported WordPress versions.
- Included Composer metadata in release zips when bundling the Composer `vendor` directory.
- Reworked the Settings admin page into WordPress-native visible sections with an anchored section nav instead of a single undifferentiated Settings API dump.
- Reworked the Featured Image Auto-Assigner page with coverage metrics, action header, progress card, and clearer manual-vs-scheduled boundaries.
- Tightened the Featured Images page to match the UI prototype more closely: shorter title, colored metric deltas, safety selector, recent activity panel, held-for-review panel, and stronger admin page heading treatment.
- Added a dashboard landing page with coverage metrics, queue health, and matching safety rules to match the UI prototype information architecture.
- Added a Review Queue admin screen for pending/approved/rejected match visibility.
- Reworked the Bulk Processor shell and generated step content with clearer page headers, section cards, grid controls, and flattened step-panel styling.
- Added the missing Scheduled Auto-Assignment settings section linked from the Featured Images page.
- Added settings controls for FIAA scheduled runs: enable/disable, interval, post types, and overwrite behavior.
- Updated the "Edit cron settings" button to jump directly to the scheduled auto-assignment section.
- Reschedule FIAA Action Scheduler events when the saved interval changes.
- Added missing settings fields for `minimum_image_spacing` and `cache_match_results_duration`.
- Fixed AI Vision and AI Featured Image field keys so they save to the actual settings read by premium handlers.
- Fixed uninstall/deactivation cleanup to use the consolidated `smart_image_matcher_settings.delete_on_uninstall` value and clear the new scheduled FIAA action hook.
- Updated Bulk Processor job progress in `wp_smart_image_matcher_queue` as Action Scheduler jobs run, allowing the UI to reconnect to queued, processing, completed, or cancelled jobs.
- Made queued bulk match actions skip work after the parent job is cancelled.
- Enqueue Bulk Processor assets using the actual admin page hook returned by WordPress, with a fallback hook check for custom submenu variants.
- Added a visible loading/error state to the Bulk Processor page so asset or REST boot failures are diagnosable in the UI.
- Added file-modified asset versions for Bulk Processor CSS/JS to avoid stale browser cache during local testing.
- Replaced full-table `posts_per_page => -1` bulk post lookups with bounded, paginated ID loading.
- Fixed bulk insertion so approved matches are fetched and inserted correctly instead of filtering approved rows out of a pending-only query.
- Added `MatchRepository::getApprovedForPost()` and shared status-based match retrieval.

### Fixed - Plugin Check compliance

- Resolved 6 Plugin Check errors for unprepared SQL in `FeaturedImageService::getCandidatePostIdsForImageSlug()` by replacing the unrecognised `call_user_func_array` call with `$wpdb->prepare( $query, ...$args )`.
- Resolved `PluginCheck.Security.DirectDB.UnescapedDBParameter` warnings in `ImageRepository::indexImage()` and `FeaturedImageService` by adding the sniff code to the existing PHPCS ignore/disable comments.
- Resolved `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` and `WordPress.DB.DirectDatabaseQuery.NoCaching` warnings in `dashboard.php`, `review-queue.php`, and `Plugin::runDailyCleanup()` by replacing misplaced inline `phpcs:ignore` comments (which only suppress the line they sit on, not the interpolated SQL on the next line) with correct `phpcs:disable`/`phpcs:enable` blocks that include all three sniff codes.
- Resolved `Internal.LineEndings.Mixed` warnings in all five Abilities classes by normalising mixed CRLF/LF line endings to consistent LF.
- Resolved `WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound` warnings in `dashboard.php` and `review-queue.php` by prefixing template-local foreach variables with `smart_image_matcher_`.

### Fixed - wp.org Plugin Directory review compliance

- Removed all premium feature gating to comply with Guideline 5 (no trialware/locked features). `Premium::has()` now always returns `true` — all features are fully enabled in the wp.org build.
- Removed all "Pro", "Upgrade", and "Premium" labels/badges from settings pages, dashboard, featured-images page, and bulk processor page.
- Removed `load_plugin_textdomain()` call (WordPress auto-loads translations for wp.org-hosted plugins since 4.6).
- Excluded `src/Premium/License.php` and `src/UI/PremiumLock.php` from the wp.org zip build (license-check and upgrade-link code).
- Updated Action Scheduler from 3.9.3 to 4.0.0.
- Added `iamkingsleyf` to the readme.txt contributors list.
- Updated readme.txt to remove the "Pro Features" section and document AI external service usage.
- See `docs/wp-org-review-fixes.md` for full details.

### Added - Bulk post selection

- Expanded Bulk Processor post selection beyond post type plus explicit IDs.
- Added status filters for published, draft, pending, scheduled, and private posts.
- Added manual ID/slug import using comma, space, or newline-separated values.
- Added search filtering across title, content, excerpt, and slug.
- Added taxonomy filtering with `taxonomy:term-slug,term-slug` syntax for categories, tags, and custom taxonomies.
- Added published-date and modified-date filters.
- Added featured-image filters for any, missing featured image, or has featured image.
- Added content filters for posts with headings, posts with no existing images, and posts not previously processed by SIM.
- Added a max-post limit control to cap queued work.
- Added browser-local saved selections for repeatable bulk filter sets.

### Changed - Featured image matching

- Replaced exact-only featured-image slug matching with a smart slug scorer shared by upload-time assignment, manual runs, REST, and Abilities.
- Added exact, prefix, reverse-prefix, and token-overlap scoring for featured image assignment.
- Added minimum shared-term rules so generic one-word image slugs do not auto-win broad article slugs.
- Added ambiguity protection: close-scoring featured-image candidates are reported for manual review instead of being auto-assigned.
- Manual Featured Image Auto-Assigner results now show image slug, score, method, and top ambiguous candidates.
- Changed the Featured Image "Run Matcher" admin action from a blocking page submit to a queued Action Scheduler job with live progress polling.
- Added progress counts, recent activity, refresh resume, and cancellation for manual Featured Image Auto-Assigner runs.
- Delayed the activation index-backfill action so it does not immediately appear as a past-due Action Scheduler task on activation.
- Added a queued-job warning when Action Scheduler has not picked up a manual Featured Image Auto-Assigner run.
- Expanded deactivation/uninstall cleanup for SIM Action Scheduler hooks, including index backfill and manual featured-image runs.
- Locked scheduled Featured Image Auto-Assigner automation behind the Pro feature flag ahead of Freemius integration.
- Set scheduled FIAA defaults to disabled and force scheduled-run settings off when the Pro feature is inactive.

### Changed - Insertion service

- Simplified `InsertionService` construction by removing an unused `HeadingLocator` dependency.
- Updated REST, queue, ability, container, and unit-test call sites for the new constructor.

### Changed - Developer tooling

- Restored Composer dev dependencies with PHPUnit, PHPCS, PHPStan, WPCS, and WP-CLI i18n tooling.
- Fixed `tests/run-unit.ps1` so it calls the installed PHPUnit binary reliably on Windows.
- Migrated `phpunit.xml.dist` to the PHPUnit 10 schema.
- Added `tests/phpstan-bootstrap.php` for project-specific WordPress, Action Scheduler, WP-CLI, and WP AI symbols.
- Updated `phpstan.neon.dist` to use the PHPStan bootstrap.
- Fixed PHPCS text-domain property syntax in `phpcs.xml.dist`.

### Verified

- PHP syntax check passes for non-vendor, non-legacy plugin/test PHP files.
- PHPUnit passes: 41 tests, 64 assertions.
- PHPStan level 5 passes with no errors.
- Composer validation passes.
- WP-CLI activation could not be verified from this shell because the Local database named `local` was not selectable.

## [3.0.0] — 2026-06-01

### Architectural — Complete rebuild on PSR-4 foundation

- Namespace `SmartImageMatcher\`, PSR-4 autoload from `src/`, PascalCase class names (no underscores)
- Hand-rolled `spl_autoload_register` (same pattern as the canonical WP AI plugin)
- Action Scheduler bundled via Composer for reliable background processing
- Zero hardcoded API keys — AI routes through `wp_ai_client_prompt()` (WP 7.0)

### Added — Insertion Engine (Phase 2)

- `HeadingExtractor` — walks Gutenberg block tree; falls back to regex for Classic posts
- `HeadingLocator` — stable `sha1(level:normalised_text:occurrence_index)` heading hashes; no byte offsets ever
- `InsertionService` — block-tree-based; splices image blocks by hash; ONE `wp_update_post()` per bulk operation
- `BlockBuilder` — single source of truth for `core/image` blocks; no `width`/`height` on `<img>`

### Added — Performance (Phase 3)

- `wp_smart_image_matcher_image_terms` inverted index — SQL `SUM(weight)` query replaces full-library PHP iteration
- Match result cache by `(post_id, post_modified, mode)` — repeat modal opens on unchanged posts are instant
- Action Scheduler–backed AI calls — no synchronous 30-second blocks on the post-edit screen
- `ImageRepository::backfillAll()` — one-time AS job populates the index on activation

### Added — REST API (Phase 2)

- `POST /smart-image-matcher/v1/posts/<id>/match` — per-heading match results
- `POST /smart-image-matcher/v1/posts/<id>/insert` — insert single image by heading hash
- `POST /smart-image-matcher/v1/posts/<id>/insert-batch` — insert N images in one post update
- `POST /smart-image-matcher/v1/posts/<id>/featured-image` — assign featured image by slug
- `GET  /smart-image-matcher/v1/match/status` — poll AI job status

### Added — Bulk Processor (Phase 4) — Premium

- Find → Queue → Review → Insert workflow driven by Action Scheduler
- Full REST API: `POST /jobs`, `GET /jobs/<id>`, cancel, paginated review queue, per-match approve/reject/swap, `POST /jobs/<id>/insert-approved`
- `bulk.js` 4-step SPA: Select Posts → Configure → Processing (live polling) → Review Queue
- `wp sim bulk-match` WP-CLI command

### Added — WP 7.0 AI integration (Phase 5) — Premium

- `ProviderBridge` — wraps `wp_ai_client_prompt()`; zero hardcoded provider credentials
- `AI\Matcher` — 2-phase: keyword candidates → AI re-ranking; auto-fallback to keyword on any AI error
- `AI\ResultParser` — JSON-only contract; strips markdown fences; validates candidate IDs
- `Premium\AiAltText` — generate alt text on upload (when enabled) and bulk fill
- `Premium\AiVisionMatch` — blend visual content scoring (60%) with keyword scoring (40%)
- `Premium\AiFeaturedImage` — AI-generated featured images as FIAA cron fallback (when enabled)
- `gutenberg.js` — Gutenberg sidebar, document panel, two `@wordpress/abilities` registered

### Added — WordPress Abilities API (Phase 4–5)

- `smart-image-matcher/find-matches-for-post`
- `smart-image-matcher/insert-image-after-heading`
- `smart-image-matcher/score-image-against-heading`
- `smart-image-matcher/assign-featured-image-by-slug`
- `smart-image-matcher/queue-bulk-match` (premium-gated)
- Two client-side Abilities for the command palette

### Added — Settings (Phase 6)

- `smart_image_matcher_settings` single option (autoload=no) replaces 27 individual autoloaded options
- Settings API with per-field sanitizer callbacks and range clamping
- AI feature controls: alt-text on upload, vision matching, featured image generation
- Premium badge on locked fields in the free build

### Fixed (from audit)

- Stable tag mismatch (was `1.3.0`, now `3.0.0`) — audit C1
- Missing `load_plugin_textdomain` — audit C3
- Undefined `$content` variable in insert response — audit C4
- `flush_rewrite_rules()` on activation — audit W10
- Inline `onclick=` on admin-bar button — audit W8
- Capability `edit_posts` used for per-post operations — now `edit_post($id)` — audit S1/H9
- Shared nonce across all AJAX actions — now per-action — audit M6
- Orphan cron events on uninstall — audit CR11
- `wp_cache_flush()` after every insert — was already fixed in 2.5.2; confirmed gone
- `print_r($_POST)` unconditionally logged — was already fixed in 2.5.2; confirmed gone
- GUID `LIKE %pattern%` full-table scan in FIAA — removed — audit PERF6

### Removed

- Direct Anthropic API client (`class-sim-ajax.php`, `class-sim-ai.php`)
- `smart_image_matcher_claude_api_key` encryption (no secrets stored; use Settings → Connectors)
- Byte-offset `heading_position` column in `wp_smart_image_matcher_matches` (replaced by `heading_hash`)
- 27 individual autoloaded `smart_image_matcher_*` options (replaced by `smart_image_matcher_settings`)
- Placeholder Bulk Processor "Coming Soon" page

---

For history prior to 3.0.0 see `.legacy/CHANGELOG.md`.

## [3.0.0] — TBD

### Changed — Complete rebuild

- PSR-4 architecture under namespace `SmartImageMatcher\`
- Block-tree-based insertion via `Insertion\InsertionService` and `Insertion\HeadingLocator`
  — eliminates byte-offset drift (root cause of every "CRITICAL Gutenberg" release)
- REST API (`smart-image-matcher/v1/*`) replaces `admin-ajax.php` handlers
- Single `smart_image_matcher_settings` option (autoload=no) replaces 27 individual autoloaded options
- Action Scheduler for AI calls and bulk operations — no synchronous page-load blocks
- SQL inverted index (`wp_smart_image_matcher_image_terms`) replaces full-library PHP array
- Provider-agnostic AI via `wp_ai_client_prompt()` — no hardcoded Anthropic dependency
- WordPress Abilities API integration — five abilities registered
- Full Bulk Processor: find → queue → review → insert workflow
- Per-action REST nonces replacing shared `smart_image_matcher_editor_nonce`
- `current_user_can( 'edit_post', $post_id )` per-resource capability checks
- Settings page rebuilt on WordPress Settings API

### Removed
- Direct Anthropic API client (`class-sim-ai.php`)
- `smart_image_matcher_claude_api_key` encryption (no secrets stored; use Settings → Connectors)
- Byte-offset `heading_position` column (replaced by `heading_hash`)
- Admin-bar inline `onclick` JavaScript
- `flush_rewrite_rules()` on activation

### Fixed
- Undefined `$content` variable in `insert_image()` success response (audit C4)
- `edit_posts` generic capability on per-post AJAX mutations (audit S1/H9)
- Cron events not cleared on uninstall (audit CR11)
- Bulk Processor placeholder page shipped to users (audit C5)

### Added
- `src/autoload.php` — hand-rolled PSR-4 autoloader (no Composer required in production)
- `src/Plugin.php` — central bootstrap
- `src/Premium.php` — feature gate; all features default-on during development
- `.legacy/` — v2.6.x source preserved as read-only reference until v3.0.0 ships

---

For history prior to 3.0.0 see `.legacy/CHANGELOG.md`.
