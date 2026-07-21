# Smart Image Matcher — Master Implementation Plan

**Plugin:** Smart Image Matcher
**Plan owner:** Krafty Sprouts Media
**Plan target:** wp.org submission as a freemium plugin built on WP 7.0 AI infrastructure
**Source documents:**
- `docs/audit/00-summary-and-verdict.md` … `14-completeness.md` (functional audit)
- `docs/audit/audit-smart-image-matcher-wp70-ai.md` (WP 7.0 AI integration audit)
- `agents.md` (rules for AI coding agents — this plan must comply)
- `.agent-skills/` (workflow guides)
- `.wp-ai/ai/` (canonical AI plugin reference implementation)
- `docs/smart-image-matcher-spec.md` (original product spec — bulk processor design)

This plan supersedes earlier roadmaps in `docs/`. Where it conflicts with the original spec, this plan wins.

---

## 0. Verdict (final)

**Rebuild in place** — keep the plugin slug `smart-image-matcher` and the existing wp.org claim, but rewrite roughly 70% of the PHP and replace the insertion engine, capability model, freemium boundary, and bulk processor entirely.

The domain logic (matcher, normalizer, FIAA scanner, cache-plugin compatibility map) carries over largely unchanged. Everything else is fundamentally broken or absent and will be rewritten against this plan.

This is the practical execution of the audit's "Refactor with Rebuild Threshold" verdict — the threshold has already been crossed because:

- The insertion engine cannot be patched, only replaced (audit C6, D2).
- The freemium boundary doesn't exist (audit C8, F1).
- The bulk processor doesn't exist (audit C5, P3).
- The i18n bootstrap doesn't exist (audit C3, W2).
- The capability model is incoherent (audit C9, I7).
- Version metadata is broken across three files (audit C1, W1).

Calling this a "rebuild" rather than a "refactor" is honest about the work and forces the right design discipline. It is not a "throw it away" rebuild — the plugin slug, the user-facing brand, the database tables (with migrations), and the keyword-matching logic all survive.

---

## 1. Architectural principles

These constrain every decision below.

1. **Build premium-aware from day one. Gate premium last.** Every premium-candidate feature lands in its own class (`Premium_*`) and is registered through `Premium::register_feature()`. Until the gating switch flips, all features are enabled and visible. The free wp.org build is produced by flipping one switch and excluding `includes/premium/` from the zip.
2. **Insertion is block-tree based, never byte-offset based.** The new insertion service operates on Gutenberg block client-IDs and stable heading hashes only.
3. **AI is provider-agnostic via `wp_ai_client_prompt()`.** The plugin never talks to Anthropic or any other provider directly. The user picks a provider in **Settings → Connectors**.
4. **Background work goes through Action Scheduler.** No synchronous AI calls, no synchronous bulk loops, no synchronous large media-library scans.
5. **REST first, AJAX deprecated.** All new endpoints are REST. AJAX handlers stay only for the legacy modal until phase 4 deletes them.
6. **Every operation that mutates state is also an Ability.** This gives the command palette, MCP-aware agents, and WP-CLI a free unified interface.
7. **Settings consolidate into one option per concern.** No more 27 autoloaded rows.
8. **Capability model is documented, two-tier, per-resource.**
   - `edit_post` (per-resource) for content mutation.
   - `manage_options` for global configuration.
9. **Test coverage is built up as we go.** Every new service ships with PHPUnit tests; every new JS module ships with a unit test or integration test. No retroactive backfill.
10. **wp.org compliance gates each phase end.** Phase 0 ends with a Plugin Check (PCP) and PHPCS (WPCS) clean run. Subsequent phases must keep them clean.

---

## 2. Target architecture (end-state)

**Naming convention.** Strict PSR-4 PascalCase. No underscores in class names or filenames. PHP namespace `SmartImageMatcher`. Filename matches class name exactly.

The `sim_` prefix stays on hooks, options, transients, DB tables, and any global functions (activation hooks etc. that must live at file scope). Those are WordPress conventions, not file names.

Composer autoloader handles class loading; no manual `require_once` chains.

```
smart-image-matcher.php                  ← thin bootstrap → SmartImageMatcher\Plugin::boot()
uninstall.php                            ← always-clear cron + conditional data wipe
agents.md
development.md                           ← human dev docs (this plan + standing rules)
IMPLEMENTATION_PLAN.md                   ← this file
readme.txt                               ← wp.org listing
README.md                                ← thin GitHub overview
CHANGELOG.md
LICENSE.txt                              ← GPL-2.0-or-later
composer.json                            ← PSR-4 autoload + dev deps
phpcs.xml.dist                           ← WPCS ruleset
phpunit.xml.dist
phpstan.neon.dist
.editorconfig
package.json                             ← @wordpress/scripts

src/                                     ← namespace SmartImageMatcher
    Plugin.php                           ← bootstrap, lifecycle, hooks
    Container.php                        ← tiny DI / service registry
    Migrator.php                         ← schema versioning
    Premium.php                          ← feature gate
    Settings/
        Settings.php                     ← single sim_settings option, Settings API
        Sanitizer.php                    ← per-field sanitizers
    Domain/
        Matcher.php                      ← scoring engine (free)
        Normalizer.php                   ← text normalization (free)
        HeadingExtractor.php             ← block-tree-aware heading extractor
        ImageRepository.php              ← inverted-index-backed media catalog
    Insertion/
        InsertionService.php             ← block-tree based (Gutenberg + Classic)
        BlockBuilder.php                 ← single source of truth for image blocks
        HeadingLocator.php               ← stable heading hash <-> block client_id
    FeaturedImages/
        FeaturedImageService.php         ← FIAA core: slug→attachment match (free)
        SlugMapBuilder.php               ← cached attachment slug map
    Cache/
        Cache.php                        ← transient + cache-plugin compat
        ObjectCacheAdapter.php           ← per-key, group-aware
    AI/
        ProviderBridge.php               ← thin wrapper over wp_ai_client_prompt()
        MatchPrompt.php                  ← prompt construction for matching
        ResultParser.php                 ← JSON-only response parsing
    Queue/
        Queue.php                        ← thin facade over Action Scheduler
        JobRunner.php                    ← invokes matcher / inserter via queue
    Abilities/
        Registry.php                     ← wp_register_ability_category + bulk registration
        AbilityFindMatches.php           ← one class per ability
        AbilityInsertImage.php
        AbilityScoreImage.php
        AbilityAssignFeaturedImage.php
        AbilityQueueBulkMatch.php        ← (premium-gated registration)
    REST/
        Controller.php                   ← base class
        MatchController.php
        InsertController.php
        FeaturedImageController.php
        BulkController.php               ← (free shape; premium handler when gated)
    Logging/
        Logger.php                       ← WP_DEBUG_LOG-aware structured logger
    Compat/
        CachePluginCompat.php            ← lifted from class-sim-cache
    CLI/
        Commands.php                     ← wp sim ... commands
    Premium/                             ← excluded from wp.org zip in phase 6
        AiMatcher.php                    ← AI mode handler (gated)
        FiaaCron.php                     ← scheduled FIAA + overwrite (gated)
        FiaaTool.php                     ← admin tool with overwrite
        BulkProcessor.php                ← bulk processor handlers
        ReviewQueue.php                  ← review queue UI controller
        Analytics.php                    ← match analytics dashboard
        AutoMatchOnPublish.php           ← auto-match on publish
        AiAltText.php                    ← AI alt-text generator
        AiVisionMatch.php                ← vision-based scoring
        AiFeaturedImage.php              ← AI-generated featured images
        License.php                      ← Freemius integration shim

admin/
    css/
        sim-admin.css                    ← always-loaded, slim
        sim-modal.css                    ← post-edit only
        sim-bulk.css                     ← bulk page only
    js/
        src/
            trigger.js                   ← ~2 KB, always loaded on post edit
            modal.js                     ← lazy-loaded modal logic (vanilla)
            gutenberg.js                 ← Gutenberg sidebar + client abilities
            bulk.js                      ← bulk processor SPA
            svg-icons.js                 ← icon helpers
        build/                           ← @wordpress/scripts output (gitignored)
    views/
        settings-page.php                ← Settings API rendered
        bulk-processor.php               ← shipped premium UI
        review-queue.php                 ← shipped premium UI
        featured-images.php              ← FIAA admin (free + premium tabs)
        analytics.php                    ← premium analytics

languages/
    smart-image-matcher.pot

tests/
    bootstrap.php
    phpunit/
        Domain/
        Insertion/
        FeaturedImages/
        Queue/
        Abilities/
        REST/
    js/
        modal.test.js
        gutenberg.test.js
    fixtures/
        posts/
        media/

docs/
    audit/                               ← keep — historical record
    HOOKS.md                             ← all sim_* actions/filters this plugin defines

.legacy/                                 ← gitignored from wp.org build; deleted at v3.0.0
    (the v2.6.x source files, kept as read-only reference during the rebuild)
```

### Naming rules in one place

| Surface | Convention | Example |
|---|---|---|
| PHP namespace | `SmartImageMatcher\<Subns>` | `SmartImageMatcher\Insertion\InsertionService` |
| PHP class | PascalCase, no underscores | `InsertionService`, `BlockBuilder` |
| PHP file | matches class name exactly | `InsertionService.php` |
| PHP method | camelCase or snake_case (WPCS allows both; project picks camelCase for new code, snake_case when overriding WP base classes like `WP_REST_Controller::register_routes`) | `findMatchesForPost()` |
| Hook (action/filter) | `sim_` prefix, snake_case | `sim_before_insert`, `sim_premium_features` |
| Option / transient | `sim_` prefix, snake_case | `sim_settings`, `sim_runtime` |
| DB table | `sim_` prefix on the suffix, WP `$wpdb->prefix` on the front | `wp_sim_matches`, `wp_sim_queue`, `wp_sim_image_terms` |
| DB column | snake_case | `heading_hash`, `confidence_score` |
| Capability | WP convention | `edit_post`, `manage_options` |
| Ability slug | `smart-image-matcher/<verb-noun>` | `smart-image-matcher/find-matches-for-post` |
| JS file | kebab-case | `gutenberg.js`, `svg-icons.js` |
| CSS file | kebab-case with `sim-` prefix | `sim-admin.css`, `sim-modal.css` |

---

## 3. Phase plan

Each phase ends with a hard gate. No advancing past the gate until the gate passes.

### Phase 0 — Foundations & wp.org submission readiness

**Goal:** stop the bleeding, ship a free wp.org-acceptable version of what already works, lay foundations for everything else. This is the only phase that CAN be skipped if we want the rebuild to happen entirely as a 2.7.0 jump — but I recommend doing it because it gets a baseline known-good build out the door.

**Tasks:**
1. **Version reconciliation.**
   - readme.txt `Stable tag: 2.6.6`.
   - Update `Tested up to:` to current WP.
   - Delete or rewrite `README.md` so it is a thin overview pointing to readme.txt.
   - Delete obsolete docs (`BUILD_SUMMARY.md`, `UX_FLOW_v1.2.0.md`, `WORDPRESS_FUNCTIONS_AUDIT.md`, `VERSION_HISTORY.md`) — keep `CHANGELOG.md` and `smart-image-matcher-spec.md`.
2. **Live bug fixes.**
   - Fix the undefined `$content` in `class-sim-ajax.php::insert_image` (audit C4, E1).
   - Strip leftover undo state from `sim-editor.js` (audit W5, J2).
   - Remove `flush_rewrite_rules()` from activation (audit W10, I3).
   - Remove inline `onclick=` from admin-bar item, bind in JS (audit W8, J5).
   - Fix the `tools_page_...` enqueue hook mismatch — bulk JS never loads anyway, so the right move is to delete bulk JS and the placeholder bulk page.
3. **i18n bootstrap.**
   - Add `load_plugin_textdomain()` on `init`.
   - Add `composer.json` with `wp-cli/i18n-command` dev requirement.
   - Run `wp i18n make-pot . languages/smart-image-matcher.pot`.
   - Commit the POT.
4. **Hide the placeholder Bulk Processor menu** (do not delete files yet — phase 4 builds the real one).
5. **Tooling.**
   - Add `phpcs.xml.dist` with WordPress ruleset.
   - Add `phpunit.xml.dist` with the WP test environment.
   - Add `package.json` with `@wordpress/scripts` and `@wordpress/eslint-plugin`.
   - Add `phpstan.neon.dist` (level 5).
   - Add `.editorconfig`.
6. **wp.org submission package.**
   - Add `LICENSE.txt`.
   - Add `screenshot-1.png` … `screenshot-5.png` to `assets/` (NOT plugin root — they live in the SVN `assets/` directory).
   - Add `== External services ==` section to readme.txt (still required because v2.6.x ships direct Anthropic). Phase 1 will replace this with a "no external services" claim.
   - Add `== Privacy ==` section.
   - Add `== Integrations ==` placeholder for phase 2.
7. **Submit to wp.org as v2.6.7.**

**Gate (Phase 0 end):**
- Plugin Check (PCP) passes with zero errors.
- PHPCS WordPress ruleset clean (or warnings explicitly documented in `phpcs.xml.dist`).
- Fresh install → activate → use modal → uninstall → no residue.
- POT generates without errors.
- wp.org submission filed.

---

### Phase 1 — Core foundations: container, settings, lifecycle, premium gate

**Goal:** introduce the new internal architecture without changing user-facing behavior. Premium gate is built but always returns true so all features remain visible.

**Tasks:**

0. **Move existing code to `.legacy/` and bootstrap the new tree.**
   - Add `.legacy/` to `.gitignore`'s wp.org-zip-exclude list (alongside `.cursor/`, `.kiro/`, `.wp-ai/`, `.agent-skills/`, `tests/`, etc.).
   - Move `includes/`, `admin/`, and the `class-sim-*.php` style of organization into `.legacy/` exactly as-is — preserve the v2.6.7 baseline as a read-only reference.
   - Create the new `src/` tree (empty namespaces) and `tests/` tree.
   - Add `composer.json` with PSR-4 autoload mapping `SmartImageMatcher\\` → `src/`.
   - Run `composer dump-autoload` so the autoloader works before any class lands.
   - The main `smart-image-matcher.php` keeps the same plugin header but its body becomes:
     ```php
     require_once __DIR__ . '/vendor/autoload.php';
     ( new SmartImageMatcher\Plugin( __FILE__ ) )->boot();
     ```
   - The plugin still activates and runs, but the new class tree is empty — phase 1 fills it. During the fill, hooks formerly registered by `.legacy/` files are NOT loaded; new code takes over each surface as it's written.
   - Phase 1 is checkpointed: at the end of phase 1, all functionality previously in `.legacy/includes/` has an equivalent in `src/`, and `.legacy/` becomes pure documentation.

1. **`Plugin` bootstrap class.** (`src/Plugin.php`)
   - Single instance, holds the container, registers lifecycle hooks.
   - `boot()` is called from the main file. The main file becomes ~10 lines.
2. **`Container` (tiny service registry).** (`src/Container.php`)
   - Map of service-name → factory closure.
   - Lazy instantiation.
   - Used for `inserter`, `matcher`, `cache`, `imageRepository`, `queue`, `logger`, `providerBridge`, etc.
3. **`Premium` feature gate.** (`src/Premium.php`)
   - `registerFeature($slug, $args)` — collected during plugins_loaded.
   - `has($slug)` — reads `apply_filters( 'sim_premium_features', $defaults )` once, cached for the request.
   - **Default for every feature is `true`** (premium-active). Phase 6 flips defaults to `false` for the wp.org build.
   - Hard kill switch: `if ( defined( 'SIM_DISABLE_PREMIUM' ) && SIM_DISABLE_PREMIUM ) { return false; }`.
4. **`Settings` consolidation.** (`src/Settings/`)
   - Migrate the 27 individual `sim_*` options to a single `sim_settings` array, autoload=no.
   - Migrate the run-summary options to `sim_runtime`, autoload=no.
   - Write a one-shot migration in `Migrator::migrateToV3Settings()`.
   - Keep backward compat shims for any third-party code that reads the old options (filter `option_<old_name>`).
5. **Settings API rebuild.**
   - `Settings\SettingsPage` registers settings via `register_setting()` and field/section helpers.
   - Per-field sanitizer callbacks in `Settings\Sanitizer`.
   - Settings page now uses `do_settings_sections()`.
   - View file `admin/views/settings-page.php` becomes a thin shell.
   - Premium fields render with `data-sim-premium="<feature_slug>"` attribute and a "Premium" badge when `! Premium::has($feature)`.
6. **Capability model.**
   - Document the two-tier model in `agents.md` and `development.md`.
   - Top-level menu: `edit_posts`.
   - Settings submenu: `manage_options`.
   - Bulk submenu: `manage_options`.
   - FIAA admin: `manage_options`.
   - Featured Image (per-post): `edit_post` against the post.
   - All AJAX/REST handlers: `current_user_can('edit_post', $post_id)` for content mutation.
7. **`Migrator`.** (`src/Migrator.php`)
   - Tracks `sim_db_version` option.
   - Runs migrations on `plugins_loaded` priority 9 (before any service that depends on schema).
   - Migration 1: settings consolidation.
   - Migration 2: stable heading-hash column on `wp_sim_matches` (phase 2 prep).
   - Migration 3: image inverted-index table (phase 3 prep).
8. **`Logger`.** (`src/Logging/Logger.php`)
   - `info()`, `warn()`, `error()` with structured key=value payloads.
   - Respects `WP_DEBUG_LOG` and the user's `sim_debug_mode` setting.
   - Replaces all `error_log()` and any debug-log calls in the `.legacy/` code.
9. **`Cache` rebuild.** (`src/Cache/`)
   - Existing transient + cache-plugin-compat code lifts almost as-is from `.legacy/`.
   - Replace `clear_all_transients()` raw SQL with explicit `delete_transient()` calls per known key.
   - Add `ObjectCacheAdapter` for non-transient cached values (so Redis/Memcached sites benefit).
10. **AJAX shim during transition.**
    - The legacy modal in `.legacy/admin/js/sim-editor.js` is replaced by `admin/js/src/modal.js` in phase 2. Until phase 2 lands, a thin AJAX adapter in `src/Compat/LegacyAjaxAdapter.php` keeps the old modal working against the new services.
    - Per-action nonces (`sim_find_matches`, `sim_insert_image`, `sim_insert_all_images`).
    - `current_user_can('edit_post', $post_id)` per-resource gate.
    - Adapter is short-lived; phase 2 deletes it.
11. **Lifecycle.**
    - Activation: WP version, PHP version, OpenSSL extension, table creation, default settings (singleton bag), migrator run, no rewrite flush.
    - Deactivation: explicit transient deletes, cron clear.
    - Uninstall: always clear cron, conditionally delete data.

**Gate:**
- Existing modal still works end-to-end (driven by the AJAX shim).
- All settings save and read through new pipeline.
- Migrator runs cleanly on a v2.6.7 install (verified in a Playground sandbox).
- Capability model verified: contributor/author roles cannot mutate other users' posts.
- `.legacy/` excluded from any wp.org zip build (verify with the build script).
- Plugin Check + PHPCS still clean.

---

### Phase 2 — Insertion engine rebuild (the rebuild threshold)

**Goal:** replace the byte-offset-based insertion mess with a block-tree-based service. This is the audit's identified rebuild threshold (audit C6) and the source of every "CRITICAL Gutenberg" entry in the changelog.

**Tasks:**

1. **`HeadingLocator`.** (`src/Insertion/HeadingLocator.php`)
   - Computes a deterministic hash for a heading: `sha1( $level . ':' . normalize( $text ) . ':' . $occurrence_index_in_post )`.
   - Maps `(post_id, heading_hash) → block_client_id` for a given parsed block tree.
   - Repeated headings stay distinct because of the occurrence index.
2. **`HeadingExtractor`.** (`src/Domain/HeadingExtractor.php`)
   - Takes a post's `post_content`.
   - Returns an array of `{ heading_hash, level, text, block_client_id, anchor (if set) }`.
   - For block content: walks `parse_blocks()` recursively, looks at `core/heading` and any heading-like block (`core/post-title`, `core/site-title` are NOT in scope).
   - For classic content: the existing regex path is preserved (with hash generation matching the block path).
3. **`BlockBuilder`.** (`src/Insertion/BlockBuilder.php`)
   - Single source of truth for `core/image` blocks.
   - Returns block array, NEVER serialized strings.
   - Strict attribute set: `id`, `sizeSlug`, `linkDestination` only (audit insertion-engine pitfalls).
   - No `width`/`height` on `<img>` (CHANGELOG 1.1.1).
4. **`InsertionService`.** (`src/Insertion/InsertionService.php`)
   - `insert( $post_id, $heading_hash, $image_id, $opts = [] )` — the canonical API.
   - Walks `parse_blocks( $post->post_content )`.
   - Resolves the heading by hash via `HeadingLocator`.
   - Builds the image block via `BlockBuilder`.
   - Splices the image block immediately after the heading block (preserving inner-block structure if the heading is nested).
   - Serializes with `serialize_blocks()`.
   - Calls `wp_update_post()` ONCE for the entire batch when `bulkInsert($post_id, $insertions[])` is used (audit D9).
   - Returns `WP_Error` on failure with explicit failure modes.
5. **Schema migration.**
   - Add `heading_hash VARCHAR(40) NOT NULL` to `wp_sim_matches`.
   - Backfill is not feasible (the prior `heading_position` is inherently unstable, so we cannot recompute hashes for old rows). Migration deletes pre-v3 rows and notes the change in CHANGELOG.
   - Update inserts/updates in the new match repository to use hash, not position.
6. **REST endpoints (replaces AJAX).** (`src/REST/`)
   - `POST /wp-json/smart-image-matcher/v1/posts/<id>/match` → run match scoring, return per-heading results with `heading_hash` and image candidates. Permission: `edit_post`.
   - `POST /wp-json/smart-image-matcher/v1/posts/<id>/insert` → insert one image after a heading hash. Permission: `edit_post`.
   - `POST /wp-json/smart-image-matcher/v1/posts/<id>/insert-batch` → insert N images in one update. Permission: `edit_post`.
   - All: `permission_callback` runs `current_user_can('edit_post', $post_id)`.
   - All: full JSON Schema validation via `args`.
7. **Modal rewrite.**
   - `admin/js/src/modal.js` — vanilla JS, lazy-loaded by `trigger.js` on first click.
   - Uses fetch + REST.
   - Uses heading hashes, not positions.
   - Renders carousel using DOM nodes (no string concatenation — audit S7).
   - Eliminates `location.reload()` after insert by reading the response and updating the editor state in place (Gutenberg has stable client IDs; we can dispatch `editPost`+`insertBlocks`).
8. **Gutenberg sidebar rewrite.**
   - `admin/js/src/gutenberg.js` continues using wp.element.
   - Modal trigger button moves into a single canonical entry point.
   - Drop the admin-bar entry (audit U3).
9. **Delete the legacy AJAX adapter.**
   - All three AJAX handlers replaced by REST equivalents.
   - The legacy AJAX action names (`sim_find_matches` etc.) stay registered as 410 Gone shims for one minor version, then removed.
   - `.legacy/admin/js/sim-editor.js` becomes documentation only.

**Gate:**
- Insertion across 50 fixture posts (Gutenberg + Classic, mixed H2/H3, repeated-heading cases, image-already-present cases) — zero data corruption.
- Gutenberg validation (orange "block contains unexpected or invalid content" notices) — zero in fixture set.
- New REST endpoints documented at `/wp-json/`.
- Phase 0 + 1 gates still hold.
- PHPUnit coverage on `Insertion_Service`, `Heading_Locator`, `Block_Builder` ≥ 85% line coverage.

---

### Phase 3 — Performance rebuild

**Goal:** address the structural performance issues that survived the v2.5.2 patches.

**Tasks:**

1. **Inverted index for media library.**
   - New table `wp_sim_image_terms( id, image_id, term, weight, source )`.
   - `source` ∈ `{filename, title, alt, caption}`.
   - Index on `term`, `image_id`.
   - Populated by `ImageIndexer` listening on `add_attachment`, `attachment_updated`, `delete_attachment`.
   - One-shot migration backfills existing attachments via Action Scheduler (1 batch of 200 per minute until done).
2. **SQL-driven matcher.**
   - `ImageRepository::find_candidates( $heading_terms, $limit )` runs a single grouped query against the inverted index.
   - Returns top-N image IDs with rough scores.
   - The PHP-side `Matcher` then applies fine scoring (filename vs title vs alt weighting, exact-phrase bonus, intentional-title bonus) only against the candidates returned — not against every image.
   - Drops scoring complexity from O(images × headings × tokens) PHP-side to O(matched_terms) SQL-side.
3. **Match-result caching by `(post_id, post_modified, mode)`.**
   - Cache key: `sim_matches_{$post_id}_{$post_modified_gmt}_{$mode}`.
   - 24-hour transient.
   - Invalidated automatically when `post_modified` changes.
   - Repeated modal opens of an unchanged post → instant.
4. **AI calls go through Action Scheduler.**
   - `Queue::enqueue_ai_match( $post_id )` schedules an AS action.
   - Modal POSTs to `/match`, gets back `{ status: 'queued', job_id: ... }` if AI mode is selected, then polls `/match/status?job_id=...`.
   - Worker runs `AI\Matcher::find` and writes results into a per-job transient.
   - Modal renders when job completes.
   - 30-second client polling timeout with graceful "still working, try again" UX.
5. **Drop the giant media-library transient.**
   - Replaced by the inverted index. The matcher no longer needs the full library array.
   - For other consumers that still want it, provide `ImageRepository::iterate_all()` that returns a paginated generator.
6. **Settings autoload audit.**
   - Verify migration 1 set autoload=no on consolidated options.
   - Add a Site Health check confirming SIM doesn't autoload bulk data.
7. **Drop `wp_remote_post` direct call to Anthropic** — already done in phase 5? No — this phase doesn't touch AI yet; phase 5 does. Defer.
8. **Asset enqueue trimming.**
   - Slim `sim-admin.css` to menu-icon-only styles (always loaded).
   - Move modal styles to `sim-modal.css` (post-edit only).
   - Move bulk-page styles to `sim-bulk.css` (bulk page only).

**Gate:**
- Seed 5 000 images. Modal-open on 10-heading post completes in < 2 s on a baseline VPS.
- Repeat modal-open on same post (cache hit) completes in < 100 ms.
- No autoloaded `sim_*` options larger than 1 KB (verified via `wp option list --autoload=yes`).
- Action Scheduler installed and running.
- Phase 0–2 gates hold.

---

### Phase 4 — Bulk Processor (the missing premium feature, built in)

**Goal:** ship the long-promised Bulk Processor as a working, queue-driven, multi-step workflow. Built premium-aware (gated by `Premium::has('bulk_processor')`) but enabled for everyone until phase 7.

**Original spec from `docs/smart-image-matcher-spec.md` interpreted into a concrete design:**

The original spec described a 4-step flow:

1. **Select Posts** — filters, preview, count.
2. **Configure** — mode, confidence, options, cost estimate.
3. **Processing** — progress bar, activity log, stats.
4. **Review Queue** — table, bulk actions, summary.

Reading between the lines, the operator's mental model is: scan many posts → review all suggested matches in one place → approve in bulk → insertions happen as a background job. The `wp_sim_queue` table was provisioned for this; the `wp_sim_matches.status` column (`pending`/`approved`/`rejected`) was the review-queue state machine.

**Tasks:**

1. **`Queue` facade over Action Scheduler.** (`src/Queue/Queue.php`)
   - `enqueue_bulk_match( $job_id, $post_ids[], $config )` schedules per-post match jobs.
   - `enqueue_bulk_insert( $job_id, $approved_match_ids[] )` schedules per-post insert jobs.
   - `wp_sim_queue` table now actively holds job metadata (job_id, status, totals, started_at, finished_at, error_summary).
   - One row per `job_id`, NOT per post-in-job (per-post status lives in `wp_sim_matches`).
2. **Bulk Processor admin page (Step 1: Select).**
   - Filterable post list: post type, status (publish/draft), category, tag, date range, author, "has at least one heading", "has no inline images yet".
   - Server-side pagination via REST.
   - Multi-select with "select all matching filter".
   - Live preview count.
   - Capability: `manage_options` (premium feature).
3. **Step 2: Configure.**
   - Mode (keyword / AI), confidence threshold, hierarchy mode, minimum image spacing, max matches per heading.
   - Cost estimate (only meaningful when AI is selected; uses provider model + estimated tokens).
   - Confirmation gate ("I understand the cost") before starting.
4. **Step 3: Processing.**
   - SPA view that polls `/jobs/<job_id>` every 2 seconds.
   - Live progress bar, current post, success/fail counts.
   - Activity log streams from `wp_sim_queue` + `wp_sim_matches`.
   - "Pause" / "Cancel" actions (Action Scheduler supports cancellation).
5. **Step 4: Review Queue.**
   - Table view of all match results from the job (joined `wp_sim_matches` rows).
   - Per-row: post link, heading text, suggested image preview, confidence, AI reasoning (if any), status (pending/approved/rejected).
   - Per-row actions: approve, reject, swap (open carousel).
   - Bulk actions: "approve all above 90% confidence", "reject all below 70%", "approve all selected".
   - One "Insert Approved" button at the end → enqueues bulk insert job → returns to processing view.
6. **REST endpoints.**
   - `POST /smart-image-matcher/v1/jobs` — create a bulk job (steps 1-2 submitted together).
   - `GET /smart-image-matcher/v1/jobs/<id>` — job status + progress.
   - `POST /smart-image-matcher/v1/jobs/<id>/cancel` — cancel.
   - `GET /smart-image-matcher/v1/jobs/<id>/matches?status=pending&page=1` — paginated match results for the review queue.
   - `POST /smart-image-matcher/v1/matches/<match_id>` — update match status (approve/reject) or swap image.
   - `POST /smart-image-matcher/v1/jobs/<id>/insert-approved` — enqueue insertion of approved matches.
7. **Cleanup expansion.**
   - `sim_daily_cleanup`: also delete `pending` matches older than 30 days (likely abandoned), `approved` matches older than 90 days, `failed` queue rows older than 30 days, mark `processing` queue rows >24h as failed.
8. **WP-CLI commands.**
   - `wp sim bulk match --post-type=post --status=publish --mode=keyword --threshold=70`
   - `wp sim bulk approve --job=<id> --min-confidence=90`
   - `wp sim bulk insert --job=<id>`
   - `wp sim job status --job=<id>`
9. **Premium gate.**
   - Wrap the bulk page registration, REST endpoints, CLI commands, and Action Scheduler hooks in `if ( Premium::has( 'bulk_processor' ) )`.
   - Default for the gate is `true` until phase 7 — so the feature ships visible to everyone during phases 4-6 (early access / dogfooding).

**Gate:**
- End-to-end test: select 100 fixture posts → keyword match → review queue → approve all > 80% → insert → verify content.
- Action Scheduler tasks run reliably; no orphan jobs after cancellation.
- Plugin Check, PHPCS, PHPUnit (≥ 80% on bulk classes) clean.
- Phase 0–3 gates hold.

---

**Current status and remaining backlog:**

Phase 4 now has a working queue-driven foundation: the Bulk Processor page renders, starts jobs, survives refresh/reload, records job progress in `wp_sim_queue`, supports cancellation, scans posts in background jobs, and exposes a review/insert flow. Step 1 selection has also moved beyond "all posts or explicit IDs" and now supports statuses, IDs/slugs, text search, taxonomy filters, date ranges, featured-image state, content state, job limits, and browser-local saved selections.

The remaining Phase 4 product backlog lives in `docs/ROADMAP_BACKLOG.md`. The major unshipped items are server-side saved segments, a searchable checkbox post picker, live preview counts, author filters, richer review queue controls, insert-job progress visibility, true pause/resume, scheduled bulk jobs, completion notifications, WP-CLI commands, and broader integration/E2E coverage.

---

### Phase 5 — WP 7.0 AI integration

**Goal:** replace the bespoke Anthropic-direct client with `wp_ai_client_prompt()` and ship the AI-derived features.

**Tasks:**

1. **Provider Bridge.**
   - `ProviderBridge::is_available()` — wraps `wp_ai_client_prompt()->withText('')->is_supported()` with feature detection.
   - `ProviderBridge::generate_text( $prompt_builder )` — runs `->generateText()`, returns `WP_Error` on any failure.
   - `ProviderBridge::generate_image( $prompt_builder )` — same shape for image generation.
2. **`AI\Matcher` rewrite.** (`src/AI/Matcher.php`)
   - Drops the Anthropic-direct client.
   - Drops the legacy `encrypt_data` / `decrypt_data` helpers (no plaintext secret to protect anymore).
   - Drops `sim_claude_api_key` and `sim_claude_model` settings; remove migration step that wipes the option (with notice in CHANGELOG that users must reconfigure provider in Settings → Connectors).
   - Drops `sim_daily_spending_limit`, `sim_cost_warnings`, `sim_email_notifications`, `sim_auto_fallback_keyword` (audit CO3 — they were dead).
   - Builds prompts via `Match_Prompt::for_heading( $heading, $candidates )`.
   - Parses responses via `Result_Parser::extract_matches( $text, $candidate_ids )`.
3. **Settings UI updates.**
   - "AI mode" dropdown only appears when `ProviderBridge::is_available()` is true.
   - When unavailable, a polite admin notice links to **Settings → Connectors**.
4. **`== External services ==` revision in readme.txt.**
   - Now reads: "This plugin does not contact any external service. AI features (when enabled) use whichever AI provider you configure in Settings → Connectors. Disclosure responsibility for that provider lies with its connector plugin."
5. **Abilities registration.**
   - `wp_register_ability_category('smart-image-matcher', ...)` on `wp_abilities_api_categories_init`.
   - On `wp_abilities_api_init`, register:
     - `smart-image-matcher/find-matches-for-post`
     - `smart-image-matcher/insert-image-after-heading`
     - `smart-image-matcher/score-image-against-heading`
     - `smart-image-matcher/assign-featured-image-by-slug`
     - `smart-image-matcher/queue-bulk-match` (premium-gated registration — only registers if `Premium::has('bulk_processor')`)
   - Each ability's `permission_callback` enforces the per-resource capability check.
   - Each ability's `input_schema` and `output_schema` are full JSON Schema (matching the REST endpoints).
6. **Client-side abilities (`@wordpress/abilities`).**
   - `smart-image-matcher/find-images-for-current-post` — opens modal (or runs match in place).
   - `smart-image-matcher/insert-best-match-for-selected-heading` — block-toolbar shortcut on heading blocks.
   - These replace the multiple legacy entry points (audit U3).
7. **AI alt-text generation (premium feature, behind gate).**
   - Setting: "Generate alt text on upload when missing".
   - On `add_attachment`, if image and alt is empty and feature is enabled, queue an AS job.
   - Worker calls `wp_ai_client_prompt()->withImage($url)->withText('Provide concise alt text…')->generateText()`.
   - Caches result by `(image_id, image_modified)`.
   - Bulk admin button: "Fill missing alt text for media library".
   - Gated by `Premium::has('ai_alt_text')`.
8. **Vision-based scoring (premium feature, behind gate).**
   - Adds a new mode `vision` to the matcher.
   - Pipeline: keyword matcher returns top 10 candidates → vision prompt scores each → final ranking is vision score.
   - Cached aggressively by `(image_id, image_modified, heading_hash)`.
   - Gated by `Premium::has('ai_vision_match')`.
9. **AI featured image generation (premium feature, behind gate).**
   - In FIAA, when no slug match exists and the feature is enabled, generate via `wp_ai_client_prompt()->withText(...)->generateImage()`.
   - Sideload the resulting image into the media library, attach to post.
   - One image per post; never overwrites an existing thumbnail.
   - Gated by `Premium::has('ai_featured_image')`.

**Gate:**
- Configure Anthropic via Settings → Connectors → AI mode in modal returns matches successfully.
- Disable Connectors → AI mode disappears from UI; keyword still works.
- Abilities visible in `/wp-json/wp/v2/abilities` (or wherever the Abilities REST namespace lands).
- Command palette shows "Find images for current post" on a post-edit screen.
- Free build (`SIM_DISABLE_PREMIUM` defined) shows no AI option, all premium-gated AI features hidden.
- Phase 0–4 gates hold.

---

### Phase 6 — Polish, premium add-on extraction, wp.org submission

**Goal:** extract the premium build into a separate add-on plugin, ship the free build, file with wp.org.

**Tasks:**

1. **Extract `includes/premium/` into a separate plugin.**
   - New plugin slug: `smart-image-matcher-pro` (or whatever the brand is).
   - Distributed via Freemius (or chosen platform).
   - Free plugin's `Premium::has()` returns `false` for all features by default.
   - Pro plugin registers itself on `plugins_loaded` and calls `Premium::enable( $feature )` for each feature the active license grants.
   - License key validation: cached 24h; tolerates 7-day license-server outage before disabling premium handlers.
2. **Premium UX in the free build.**
   - Premium settings render disabled with "Premium" badge.
   - Bulk Processor menu shows "Bulk Processor (Premium)" — clicking opens a polite upgrade page (NOT auto-redirect to checkout).
   - FIAA scheduled cron tab shows the same.
   - Carousel cap drops to 3 in the free build (audit P8 quick win).
3. **Dual-readme.**
   - Free plugin's `readme.txt`: lists free features only, links premium features as "available in Pro".
   - Pro plugin's `readme.txt`: standalone, lists premium features.
4. **`== External services ==`** in free readme.txt: "None. AI features in the optional Pro add-on use a user-configured provider via WordPress Connectors."
5. **Tags** review: optimize for discoverability. Suggested: `images, media library, alt text, featured image, automation`.
6. **Plugin Check (PCP) full pass.**
7. **Final security review.**
   - No plaintext secrets anywhere.
   - All endpoints have permission_callback.
   - All inputs sanitized.
   - All outputs escaped.
8. **Submit free version 3.0.0 to wp.org.**

**Gate:**
- Free build clean install passes Plugin Check.
- Free build clean install activate → use → uninstall → no residue.
- Pro build installs cleanly alongside free, premium features become active.
- License-server-down test: pro features remain active for 7 days, then degrade gracefully.
- All audit findings (`docs/audit/00-summary-and-verdict.md` Critical + High) are resolved or explicitly out of scope.
- wp.org submission filed.

---

### Phase 7 — Post-launch (premium add-on iteration)

After the free build is live and stable, iterate on premium add-on features (analytics dashboard, auto-match on publish, ACF integration, multi-language, etc. — see `03-freemium.md` and `audit-smart-image-matcher-wp70-ai.md`).

This phase has no gate; it's an ongoing roadmap.

---

## 4. Bulk Processor — original intent decoded

Quoting from the original spec (`docs/smart-image-matcher-spec.md` § "Bulk Processing Interface"), the flow is **find → queue → review → insert**:

> Full admin page at Tools > Smart Image Matcher with 4 steps:
> 1. **Select Posts** — Filters, preview, post count
> 2. **Configure** — Mode, confidence, options, cost estimate
> 3. **Processing** — Progress bar, activity log, stats
> 4. **Review Queue** — Table view, bulk actions, summary

In behavioral terms:

1. **Find** — operator picks a set of posts and a matching configuration. Background jobs run the matcher across each post.
2. **Queue** — match results land in `wp_sim_matches` with `status = pending`. The job's metadata lives in `wp_sim_queue` (one row per job, tracking lifecycle).
3. **Review** — operator opens the review queue: every (post, heading, suggested image) row visible in one table, with per-row approve/reject/swap actions and bulk operations like "approve all > 90% confidence".
4. **Insert** — operator clicks "Insert Approved". Background jobs run insertion against approved matches only. `status` transitions to `approved` on success or `failed` if the insertion errors.

The unique design choice is the **explicit review gate between scanning and insertion**. The author rightly didn't trust the v2.6.x insertion engine to fire blindly across hundreds of posts. With phase 2's block-tree-based insertion service, that trust is earned, but the review gate stays — operators want it.

**Why each existing piece exists:**
- **`wp_sim_queue` table** — was meant to hold per-post jobs (status: queued / processing / completed / failed). Originally the spec implied one row per post-in-job; my redesign uses one row per JOB and tracks per-post state in `wp_sim_matches.status`. Cleaner.
- **`wp_sim_matches.status` (`pending` / `approved` / `rejected`)** — was meant to be the review-queue state machine. Modal flow already moves `pending → approved` on insert. The bulk flow needs the explicit `rejected` transition (currently never used) and bulk transitions ("approve all >80%").
- **Activation defaults `sim_batch_size_limit`, `sim_minimum_image_spacing`** — were placeholder knobs for the bulk UI's "Configure" step. Wire them up in phase 4.
- **Activation defaults `sim_email_notifications`, `sim_cost_warnings`** — were placeholders for the bulk UI's "long job notification" feature. Drop these (audit CO3); the modern equivalent is Action Scheduler's built-in completion hooks, plus the optional WP-Mail-based notification we add in phase 4 task 8.

**Why nothing ever shipped:**
- The insertion engine wasn't reliable enough to trust at scale (audit C6). The author rightly didn't want to insert 800 posts of broken content.
- Action Scheduler wasn't introduced.
- The review-queue UI is genuinely a lot of work for a non-priced feature.

The phase-2 insertion rebuild + phase-3 Action Scheduler integration unblock phase 4. Build it premium-aware so that when we flip the gate in phase 7 it lands as a paid feature.

---

## 5. Premium-feature catalog (final, mapped to gates)

| Feature slug (`Premium::has`) | What | Phase that lands it | Default in phases 1-6 | Default in free build (phase 7+) |
|---|---|---|---|---|
| `ai_matching` | AI mode in modal | 5 | true | false |
| `ai_alt_text` | AI alt-text on upload + bulk fill | 5 | true | false |
| `ai_vision_match` | Vision-based scoring | 5 | true | false |
| `ai_featured_image` | AI-generated featured images via FIAA | 5 | true | false |
| `bulk_processor` | Bulk Processor admin page + REST + CLI | 4 | true | false |
| `review_queue` | Match-history review UI | 4 | true | false |
| `analytics` | Match analytics dashboard | 7 | true | false |
| `auto_match_on_publish` | Run keyword match on `transition_post_status` | 7 | true | false |
| `fiaa_scheduled_cron` | FIAA cron run + overwrite mode | (already exists; gate added in 1) | true | false |
| `fiaa_arbitrary_post_types` | FIAA upload-time auto-assign for non-post/page | (already exists; gate added in 1) | true | false |
| `extended_carousel` | Carousel beyond 3 alternatives | 6 | true | false |
| `cli_commands` | WP-CLI commands | 4 | true | false |
| `rest_premium_endpoints` | Bulk REST endpoints | 4 | true | false |

The defaults flip in phase 6/7 by overriding `Premium::default_state()`.

---

## 6. Test strategy

### Unit (PHPUnit)

- `Normalizer` — pure functions, fixture inputs, exhaustive: stemming, spelling variants, possessives, whitelist edge cases (CHANGELOG 2.4.1's "Io" case is a regression test).
- `Matcher::calculateScore` — fixture image sets, exhaustive: exact match, partial, exact-phrase override, smart penalties.
- `HeadingLocator` — heading hash determinism, repeated headings, nested blocks.
- `BlockBuilder` — output schema validation, no width/height attrs, alt fallback.
- `InsertionService` — block-tree splicing across Gutenberg + Classic fixtures.
- `FeaturedImageService` — slug match, fallback-thumbnail handling (CHANGELOG 2.6.4-2.6.6).
- `Settings\Sanitizer` — per-field validation/clamping.
- `Premium` — feature gate state machine.
- `Migrator` — each migration runs idempotently.

### Integration (PHPUnit with WP test env)

- REST endpoints (per-resource capability gates, schema validation, error shapes).
- Abilities registration + execution + REST surface.
- AS-driven match jobs lifecycle (queue → process → complete).
- Cache compatibility hooks (mock the supported plugin functions).
- Activation/deactivation/uninstall idempotent and reversible.

### End-to-end (manual, on every gate)

- Modal: scan, review, insert single, insert all — Gutenberg + Classic.
- Bulk: select 50 posts, scan, review, approve, insert.
- FIAA: upload-time match + scheduled cron + overwrite.
- AI: configure Anthropic via Connectors → AI mode → matches arrive with reasoning.
- Free build (`SIM_DISABLE_PREMIUM`): all premium features hidden, free build works.

### Performance smoke (every phase 3+ gate)

- 5 000 attachments, 10-heading post, modal open: < 2 s cold, < 100 ms warm.
- Bulk job, 100 posts, keyword mode: < 5 minutes total.
- AI mode polling: modal never blocks > 1 s synchronously.

---

## 7. Cross-reference matrix (audit findings → phase)

Every Critical/High finding in the audit maps to a phase. Lower-severity findings batch into adjacent phases.

| Audit ID | Finding | Phase |
|---|---|---|
| C1, W1 | Stable tag mismatch | 0 |
| C2, W3 | Tested up to stale | 0 |
| C3, W2 | Missing i18n bootstrap | 0 |
| C4, E1 | Undefined `$content` | 0 |
| C5, W4, P3 | Bulk Processor placeholder | 0 (hide), 4 (build) |
| C6, D2 | Heading-position drift | 2 |
| C7, W5, J2 | Undo claims false | 0 |
| C8, F1 | No freemium boundary | 1 |
| C9, I7 | Capability mismatch | 1 |
| C10 | `wp_sim_queue` dead | 4 (used by bulk) |
| H1, S3, R3 | Weak encryption | 5 (deleted entirely) |
| H2, PERF1, PERF11 | Whole-library transient | 3 |
| H3 | Transient autoload | 3 |
| H4, PERF3 | Match table grows on every modal open | 3 |
| H5, CR4 | Cleanup insufficient | 4 |
| H6, PERF6 | FIAA `LIKE %guid%` | 1 (delete) |
| H7, M4 | Bulk JS enqueue mismatch | 0 (delete legacy) |
| H8, W10, I3 | `flush_rewrite_rules()` | 0 |
| H9, S1 | `edit_posts` vs `edit_post` | 1 |
| H10 | Version mismatch | 0 |
| H11, W8, J5 | Inline `onclick=` | 0 |
| H12, E5 | `error_log` not WP-aware | 1 |
| M1, R5, E4 | Silent AI fallback | 5 |
| M2 | FIAA double-menu-register | 1 |
| M5 | No REST | 2 |
| M6, S4 | Single shared nonce | 2 |
| M7 | English-only stop words | 7 (multilang premium) |
| M8, PERF2, R7 | Sync AI call | 3 + 5 |
| M11, W11 | `wp.editor` legacy alias | 6 |
| M13, PERF8 | Raw SQL transient delete | 1 |
| M14 | `prepare()` arg mismatch | 1 |
| PERF4, A10 | 27 autoloaded options | 1 |
| PERF5 | Queue cleanup | 4 |
| PERF10 | Always-loaded JS | 3 |
| CR2 | FIAA cron does too much | 4 (AS) |
| CR11 | Orphan cron on uninstall | 0 |
| CO2, P7 | Match history UI | 4 (review queue) |
| CO3 | Dead settings | 0 (hide) + 5 (drop) |
| CO5 | `sim_minimum_image_spacing` unused | 4 (wire up) |
| CO7 | README claims | 0 |
| CO8 | Stale docs | 0 |
| Q7 | Zero test coverage | 1+ (added per phase) |
| (all wp.org compliance) | | 0 + 6 |

---

## 8. Risks and mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Phase 2 insertion engine reveals deeper Gutenberg edge cases than expected | Medium | High | Extensive fixture set covering nested blocks, reusable blocks, synced patterns; mark phase 2 as the rebuild threshold — if it can't be done cleanly, fork to a true rebuild and copy-port domain logic only |
| WP 7.0 AI Client API surface changes between proposal and final ship | Medium | Medium | Bridge via `ProviderBridge`; update in one place when API stabilizes |
| Action Scheduler not present on a target site | Low | Medium | Action Scheduler ships with WC and has its own composer package; bundle it as a fallback library if not present (`if ( ! class_exists( 'ActionScheduler' ) ) require_once ...`) |
| `wp_sim_matches` table grows unmanageably on busy sites between v2.6.7 and v3.0.0 | Medium | Low | Phase 1 cleanup expansion runs early in the migration window |
| Users on PHP 7.4 / WP 6.0 lose features when bumping `Requires PHP/WP` | Low | Medium | Free build keeps `Requires at least: 6.0`; AI features behind feature detection only |
| Freemius (or chosen platform) integration delays phase 6 | Medium | Medium | Phase 6 has the premium extraction as a separate work-stream from polish — polish can ship without premium extraction (free build is shippable independently) |
| Multilingual interest from users before phase 7 multilang lands | Low | Low | Document on roadmap; English-only stop words are an explicit free-tier limit |
| Plugin Check (PCP) flags an issue we missed | Medium | Low | Run PCP at every gate, not just phase 6 |

---

## 9. Definition of done — overall

The plan is complete when:

- [ ] Free wp.org build (3.0.0) is approved and listed.
- [ ] Premium add-on plugin is published via Freemius (or chosen platform).
- [ ] `docs/audit/00-summary-and-verdict.md` Critical + High findings are all resolved or documented as out-of-scope.
- [ ] Plugin Check passes on the free build.
- [ ] PHPCS (WordPress ruleset) passes on the entire codebase.
- [ ] PHPStan level 5 passes.
- [ ] PHPUnit suite passes; coverage ≥ 70% overall, ≥ 85% on `Insertion`, `Domain`, `Featured_Images`, `Premium`.
- [ ] End-to-end manual test pass on Gutenberg + Classic + Multisite + a managed WP host (e.g. WP Engine, Pressable, Kinsta).
- [ ] Capability model documented and enforced.
- [ ] Five Abilities registered and surfacing in the WP command palette.
- [ ] No autoloaded `sim_*` option larger than 1 KB.
- [ ] Free build makes zero outbound HTTP calls.
- [ ] Pro build's outbound calls are entirely via `wp_ai_client_prompt()` (provider chosen by user).
- [ ] `agents.md` and `development.md` reflect the shipped state.

---

## 10. What carries over from the existing codebase

Lift-and-shift candidates (preserve in spirit, modernize for new architecture):

| File | Status |
|---|---|
| `class-sim-normalizer.php` | Keep wholesale, move to `includes/Domain/Normalizer.php`. |
| `class-sim-matcher.php` (scoring) | Keep the scoring logic; refactor `calculate_match_score` into per-field scorer methods (audit Q10). |
| `class-sim-cache.php` (cache-plugin compat) | Lift the third-party cache-clear matrix into `src/Compat/CachePluginCompat.php`. |
| `class-sim-featured-image-auto-assigner.php` (slug matching) | Keep the slug-map approach; drop the GUID `LIKE` fallback (PERF6). |
| Claude prompt format (in `class-sim-ai.php`) | Keep the JSON-only contract; rebuild on top of `wp_ai_client_prompt()`. |
| Heading regex (Classic editor path) | Keep, with hash generation matching the block path. |
| Smart Hierarchy filter | Keep, runs on the new heading set. |

Replace entirely:

- `class-sim-ajax.php` — replaced by REST controllers + `InsertionService`.
- `class-sim-admin.php` — replaced by trigger script + Gutenberg sidebar.
- `class-sim-bulk.php` — replaced by `Queue` + `JobRunner` + REST + CLI.
- `class-sim-settings.php` and `admin/views/settings-page.php` — replaced by Settings API setup.
- `admin/js/sim-editor.js` — replaced by `sim-modal.js` (vanilla).
- `admin/js/sim-bulk.js` — empty file, deleted.
- `admin/js/sim-gutenberg-plugin.js` — replaced by `sim-gutenberg.js`.
- `class-sim-core.php` — its responsibilities split across `Plugin`, `Container`, `Logger`, encryption (deleted entirely after phase 5).
- `uninstall.php::sim_recursive_delete` — deleted (the directory it cleans was never written).

Delete outright:

- The placeholder `admin/views/bulk-processor.php` (replaced in phase 4).
- All stale `docs/*.md` except `CHANGELOG.md` and `smart-image-matcher-spec.md`.
