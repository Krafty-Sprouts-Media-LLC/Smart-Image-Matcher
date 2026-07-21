# Changelog

All notable changes to Smart Image Matcher are documented here.

Format: [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
Versioning: [Semantic Versioning](https://semver.org/spec/v2.0.0.html)

## [Unreleased]

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
