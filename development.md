# development.md — Human Developer Guide

This is the single source of truth for how development is done on Smart Image Matcher.

Read this once before making any change. Pair it with:

- `agents.md` — rules an AI coding agent must follow (read it; you're held to the same rules).
- `IMPLEMENTATION_PLAN.md` — the master plan for getting from today's v2.6.6 to v3.0.0.
- `docs/audit/` — the audit findings this plan is built on.
- `.agent-skills/` — workflow guides (Abilities API, REST, performance, plugin development, etc.). Read the relevant SKILL.md before starting work in that area.
- `.wp-ai/` — canonical AI plugin reference implementation. Treat it as read-only documentation.

---

## 1. Plugin overview

**What it does.** Smart Image Matcher scans posts and pages for headings (H2–H6) and inserts the most relevant images from the media library next to each heading. It also auto-assigns featured images when post slugs match attachment filenames.

**Operational modes.**

- **Single-post modal.** User clicks "Smart Image Matcher" on the post edit screen → modal scans headings → user reviews/inserts.
- **Bulk Processor.** (Premium, building in phase 4.) Multi-step admin page for matching across many posts at once with a review queue.
- **Featured Image Auto-Assigner (FIAA).** Real-time on attachment upload, plus a configurable cron run, plus an admin tool with overwrite mode.

**Tech stack.**

- PHP 7.4+ (target 8.0+ for new code), WordPress 6.0+ baseline (6.9+ for Abilities, 7.0+ for AI Client features).
- Vanilla JavaScript (modal) + `@wordpress/element` + `@wordpress/abilities` (Gutenberg integration + client-side abilities).
- MySQL via `$wpdb`. Two custom tables: `wp_sim_matches`, `wp_sim_queue`.
- Action Scheduler for background work (bundled if not present).
- Build via `@wordpress/scripts` (npm).
- Tests via PHPUnit + Jest.
- Freemium delivered as: free plugin on wp.org + premium add-on plugin distributed via Freemius (or equivalent).

**Operational architecture.** See `IMPLEMENTATION_PLAN.md` § 2 for the file/class layout. Briefly:

- Domain logic (matching, normalization, heading extraction, image repository) is pure / framework-light.
- Insertion uses the Gutenberg block tree and stable heading hashes — never byte offsets.
- Premium-aware from day one. Premium features live in `includes/premium/` and are gated by `Premium`.
- AI is provider-agnostic via `wp_ai_client_prompt()`.

---

## 2. Development environment setup

### 2.1 Prerequisites

- PHP 7.4+ with `openssl`, `mbstring`, `json`, `mysqli` extensions.
- MySQL 5.7+ or MariaDB 10.3+.
- Node 18+.
- Composer 2+.
- WP-CLI.
- (Recommended) `wp-env` or Local for a local WordPress.

### 2.2 Clone and install

```bash
git clone <repo>
cd smart-image-matcher
composer install
npm install
```

### 2.3 Run a local WP

Either:

- **wp-env** (preferred): `npx wp-env start` — uses Docker, mounts the plugin into a fresh WordPress, available at `http://localhost:8888`.
- **Local by Flywheel**: drop the plugin folder into `wp-content/plugins/` of a Local site (this is the current author's setup — the plugin lives at `c:\Users\kings\Local Sites\yenimi\app\public\wp-content\plugins\smart-image-matcher`).
- **Playground**: for quick smoke-tests, the plugin runs in the WordPress Playground via a blueprint we maintain in `tools/playground/blueprint.json` (see `.agent-skills/wp-playground/`).

### 2.4 Build assets

```bash
npm run build           # one-shot production build
npm run dev             # watch mode for active development
```

The wp-block-editor JS is built via `@wordpress/scripts`. The vanilla modal JS is built into `admin/js/build/` from sources in `admin/js/src/`.

### 2.5 Run tests

```bash
composer test           # PHPUnit
composer phpcs          # WordPress ruleset
composer phpstan        # level 5
npm test                # JS tests
```

### 2.6 Common WP-CLI commands during dev

```bash
# Run migrations manually (during development).
wp sim migrate

# Dump the current settings option (compact view).
wp option get sim_settings --format=json | jq

# Trigger the daily cleanup cron immediately.
wp cron event run sim_daily_cleanup

# Reset the plugin to a fresh state (DANGEROUS — dev only).
wp option delete sim_settings sim_runtime sim_db_version
wp db query "DROP TABLE IF EXISTS wp_sim_matches; DROP TABLE IF EXISTS wp_sim_queue; DROP TABLE IF EXISTS wp_sim_image_terms;"
wp plugin deactivate smart-image-matcher && wp plugin activate smart-image-matcher
```

### 2.7 AI provider for dev

To test AI features end-to-end:

1. Install the WP AI plugin from `.wp-ai/ai/` (it's the canonical reference; symlink or copy into `wp-content/plugins/`).
2. Activate it.
3. Settings → Connectors → add an Anthropic key (use a development key with billing limits — see § 6).
4. Settings → AI → enable AI features.
5. SIM's settings page should now show AI mode as available.

**Never commit API keys.** `.gitignore` excludes `.env`, `*.key`, and any `wp-config.php` with secrets in it.

---

## 3. WordPress coding standards

### 3.1 Which standards apply

- **PHP:** WordPress Coding Standards (WPCS) for PHP. Configured in `phpcs.xml.dist`.
- **JavaScript:** `@wordpress/eslint-plugin` configured in `.eslintrc.js`.
- **CSS:** `@wordpress/stylelint-config` configured in `.stylelintrc.js`.

### 3.2 Run linting

```bash
composer phpcs                  # PHP
composer phpcbf                 # auto-fix what's safe
npm run lint:js                 # JavaScript
npm run lint:css                # CSS
```

### 3.3 Documented exceptions

Document any rule we deliberately ignore in `phpcs.xml.dist` with a comment explaining why. Currently:

- `WordPress.DB.DirectDatabaseQuery.*` — allowed only in:
  - `Featured_Image_Service::get_attachment_slug_map()` — bulk slug map cannot be expressed via `WP_Query`.
  - `Image_Indexer::backfill()` — bulk insert into the inverted index.
  - `Migrator::*` — migrations.
  - `uninstall.php` — table drops.

Each exception is annotated with a `// phpcs:ignore` line citing the rule and the justification.

### 3.4 PHPStan level

We target level 5. Higher levels are aspirational. Stubs for WP core are loaded via `php-stubs/wordpress-stubs`.

---

## 4. Security standards

### 4.1 Mandatory patterns

These are non-negotiable. Violations block PRs.

**Sanitization (input).**
- Sanitize at the boundary, with `wp_unslash()` first.
- Choose the right sanitizer per type — see `agents.md` § 3.3 for the table.
- Never read `$_POST`/`$_GET` directly inside REST callbacks; use `WP_REST_Request`.

**Escaping (output).**
- Escape at the point of output. `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- No exceptions. Even "trusted" output must be escaped.

**Capability checks.**
- Use the **resource-specific** capability:
  - `current_user_can( 'edit_post', $post_id )` for post mutations.
  - `current_user_can( 'edit_post', $attachment_id )` for attachment metadata mutations.
  - `current_user_can( 'manage_options' )` for global settings only.
- Never use `'edit_posts'` (the generic "user can edit *some* posts" capability) for operations that target a specific post.

**Nonces.**
- Per-action, never shared.
- REST endpoints automatically have nonces via the cookie auth flow; verify with `permission_callback`.
- Forms (settings page) use `wp_nonce_field()` + `check_admin_referer()`.

**SQL.**
- All user input goes through `$wpdb->prepare()`.
- Never concatenate user input into SQL.
- Use `$wpdb->esc_like()` before `LIKE` queries.

**Secrets.**
- The plugin holds no secrets after phase 5. Provider credentials live in WordPress's Connectors store.
- If we ever need to store a secret again: AES-256-GCM with a per-record IV, key generated on activation and stored as a non-autoloaded option. Never reuse `wp_salt()` directly as a key.

### 4.2 Forbidden patterns

- Inline `onclick=`, `onsubmit=`, etc. (CSP violation, audit W8).
- `eval()`, `extract()`, `create_function()`, `assert()`.
- Reading `$_POST['foo']` without `wp_unslash` + a sanitizer.
- Echoing variables without escaping.
- `LIKE '%...%'` queries against unindexed columns (audit PERF6).
- Storing API keys or other secrets in plain text or with weak/static-IV encryption.

### 4.3 Review checklist for security-relevant changes

Before merging anything that touches input, output, capabilities, or data storage:

- [ ] Inputs sanitized with the correct sanitizer for their type.
- [ ] Outputs escaped at the point of output.
- [ ] Per-resource capability check (`edit_post`, not `edit_posts`).
- [ ] Per-action nonce.
- [ ] No new direct DB queries; if unavoidable, prepared and PHPCS-annotated.
- [ ] No new outbound HTTP — if it's needed, it goes through `wp_ai_client_prompt()` or `wp_safe_remote_*` with a documented disclosure in readme.
- [ ] No secret stored without encryption.
- [ ] Run `composer phpcs` and resolve all WordPress-Security warnings.

---

## 5. Performance standards

These exist because of documented past performance failures (CHANGELOG 2.5.2) and the audit's structural findings (`docs/audit/04-performance.md`).

### 5.1 Prohibited

- `wp_cache_flush()` outside uninstall. Use `clean_post_cache( $post_id )` for per-post invalidation.
- Synchronous `wp_remote_*` calls from page-load or AJAX/REST handlers. Background-queue them via Action Scheduler.
- `posts_per_page => -1`. Always paginate.
- Loading all attachments into PHP memory at request time.
- `add_option( $name, $value )` without explicit `$autoload = 'no'` unless the option is genuinely needed on every request (which is almost never).
- `print_r()` / `var_dump()` outside `Logger::is_debug_mode()` guards.
- `flush_rewrite_rules()` unless we actually added rewrite rules.

### 5.2 Required

- Match results cached by `(post_id, post_modified, mode)`. Repeated modal opens of an unchanged post hit cache.
- AI calls run via Action Scheduler. Modal polls a job status endpoint.
- Image lookups go through the inverted index (`wp_sim_image_terms`). Never iterate the full media library in PHP.
- Large datasets paginated or streamed.
- External requests cached aggressively.
- Settings consolidated into one (or two) options with `autoload=no`.
- Asset enqueue gated by admin-screen hook AND by feature flag (only enqueue what the user actually uses).

### 5.3 Performance smoke tests

Required before any change touching the matcher, image repository, or insertion service:

```bash
# Seed 5 000 attachments and a 10-heading post.
wp sim dev:seed --images=5000 --post-headings=10

# Modal cold (cache miss).
time curl -X POST https://example.com/wp-json/smart-image-matcher/v1/posts/<id>/match -H "X-WP-Nonce: ..."
# must complete in < 2 s

# Modal warm (cache hit).
time curl -X POST https://example.com/wp-json/smart-image-matcher/v1/posts/<id>/match -H "X-WP-Nonce: ..."
# must complete in < 100 ms
```

### 5.4 Performance review checklist

- [ ] No new outbound HTTP on page load or in user-facing AJAX/REST.
- [ ] No new full-table scan; new tables have indexes for the queries that hit them.
- [ ] No new autoloaded option larger than 1 KB.
- [ ] Cache invalidation reasoned through and tested.
- [ ] Action Scheduler used for any operation that may take > 5 s.

---

## 6. Database standards

### 6.1 Schema overview

| Table | Purpose | Key columns | Indexes |
|---|---|---|---|
| `wp_sim_matches` | Per-heading match attempts and review-queue state | `id`, `post_id`, `heading_hash` (post-v3), `image_id`, `confidence_score`, `match_method`, `ai_reasoning`, `status`, `created_at` | `post_id_idx`, `status_idx`, (post-v3) `(post_id, heading_hash)` unique |
| `wp_sim_queue` | Bulk job metadata | `id`, `job_id`, `status`, `priority`, `attempts`, `error_message`, `started_at`, `finished_at`, `created_at` | `status_idx`, `job_id_idx` |
| `wp_sim_image_terms` (post-v3) | Inverted index for matcher | `id`, `image_id`, `term`, `weight`, `source` | `term_idx`, `image_id_idx` |

`heading_hash` is `sha1( level . ':' . normalized_text . ':' . occurrence_index )`. It is stable across edits as long as the heading text and its position-among-same-text-headings don't change. Replaces the old fragile `heading_position`.

### 6.2 Query rules

- All user-input queries use `$wpdb->prepare()`.
- All table references use `$wpdb->prefix` (or `$wpdb->posts`, `$wpdb->options`, etc. for core tables).
- Never bypass `$wpdb`.
- Never run `LIKE '%pattern%'` on an unindexed column.
- Direct queries against core tables are allowed only when `WP_Query`/`get_posts` cannot express the need; annotate with a PHPCS ignore + justification comment.
- Use prepared statements with `%d`/`%s`/`%f` placeholders. Pre-PHP-8.1, `%i` (identifier) is unavailable; use validated identifier whitelists if you need to interpolate column names.

### 6.3 Migrations

- Every schema change ships with a numbered migration in `Migrator`.
- `sim_db_version` option tracks the current schema version.
- Migrator runs on `plugins_loaded` priority 9.
- Migrations must be idempotent and forward-only.
- `dbDelta()` for `CREATE TABLE` statements; format must match WP's strict expectations.

### 6.4 Cleanup

- `sim_daily_cleanup` cron task runs daily.
- Deletes `pending` matches > 30 days old, `approved` matches > 90 days old, `rejected` matches > 30 days old.
- Marks `processing` queue rows > 24h old as `failed` with reason "stuck".
- Deletes `failed` queue rows > 30 days old.
- Deletes `completed` queue rows > 7 days old.

### 6.5 Seed data for development

`Dev\Seeder` (loaded only when `WP_DEBUG && SIM_DEV_TOOLS`) provides:

- `wp sim dev:seed --images=N` — generates attachment records with synthetic filenames.
- `wp sim dev:seed --posts=N --headings=K` — generates posts with K H2 headings each.

---

## 7. Freemium architecture

### 7.1 The boundary

A `Premium` static class controls feature gating:

```php
Premium::register_feature( $slug, array(
    'label'       => __( 'Bulk Processor', 'smart-image-matcher' ),
    'description' => __( 'Process matching across many posts at once.', 'smart-image-matcher' ),
    'default'     => true,  // during phases 1-6; flipped to false in phase 7+
) );

if ( Premium::has( 'bulk_processor' ) ) {
    // Register handlers, menus, REST routes, abilities.
}
```

### 7.2 Free vs premium catalog

See `IMPLEMENTATION_PLAN.md` § 5 for the canonical table. In short:

- **Free:** keyword matching, single-post modal, image insertion, FIAA upload-time auto-assign for post/page, advanced linguistics, cache-plugin compat, settings page, encryption helpers (until phase 5 deletes them).
- **Premium:** AI matching, AI alt-text, AI vision matching, AI featured image generation, Bulk Processor, Review Queue, Match Analytics, Auto-match on publish, FIAA scheduled cron, FIAA arbitrary post types, extended carousel, WP-CLI commands, premium REST endpoints.

### 7.3 How to add a feature

**Free feature:**
1. Decide which package it belongs in: `Domain`, `Insertion`, `Featured_Images`, `Cache`, `Queue`, `Abilities`, `REST`, `Compat`.
2. Add the class under `includes/<package>/`.
3. Register its hooks in `Plugin::register_services()`.
4. Add tests under `tests/phpunit/<package>/`.
5. Update `agents.md` and `development.md` if the addition introduces new patterns.

**Premium feature:**
1. Place the class under `includes/premium/`.
2. In `Plugin::register_premium_services()`, register the feature and conditionally wire its hooks:
   ```php
   Premium::register_feature( 'my_feature', array( 'default' => true ) );
   if ( Premium::has( 'my_feature' ) ) {
       $premium_my_feature = $container->get( 'premium.my_feature' );
       $premium_my_feature->register();
   }
   ```
3. If the feature surfaces UI (settings field, menu, button), use `UI\PremiumLock` to render it as a "Premium" badge when `! Premium::has( 'my_feature' )`.
4. Add tests, both with the gate on and the gate off.

### 7.4 Mixing forbidden

- Never put free and premium logic in the same class.
- Never branch inside a free class on `Premium::has()` for non-trivial behavior changes. The pattern is: register handler in `Plugin` if the gate is on, not "do A in handler if gate is on, do B if not".
- Free class extends are allowed for premium subclasses (e.g. `Premium_Vision_Matcher extends Matcher`).

### 7.5 The kill switch

Sites may define `SIM_DISABLE_PREMIUM` in `wp-config.php` to force-disable all premium handlers. Useful for debugging and for users who want the free experience even with the pro add-on installed.

---

## 8. wp.org compliance checklist

Before tagging any release for wp.org submission:

- [ ] `readme.txt` `Stable tag` matches plugin header `Version`.
- [ ] `Tested up to:` is current (within 2 minor versions of the latest WP release).
- [ ] `Requires at least:` and `Requires PHP:` are accurate.
- [ ] `load_plugin_textdomain()` called.
- [ ] `languages/smart-image-matcher.pot` regenerated and committed.
- [ ] No `eval()`, `extract()`, `create_function()`, `assert()`, PHP short tags.
- [ ] No obfuscated code.
- [ ] `== External services ==` section accurate (post-phase-5 free build: "None").
- [ ] `== Privacy ==` section accurate.
- [ ] `== Integrations ==` section lists Abilities exposed.
- [ ] No tracking, analytics, advertising, or upsell that blocks UI.
- [ ] No placeholder UI ("Coming Soon" pages).
- [ ] Premium features in the free build are visibly disabled, not absent — no broken links.
- [ ] Plugin Check (PCP) passes with zero errors and resolved/justified warnings.
- [ ] PHPCS WordPress ruleset passes.
- [ ] Fresh-install smoke test on a clean WordPress: activate → settings save → modal works → uninstall → no residue.
- [ ] `LICENSE.txt` present.
- [ ] Screenshots present in the SVN `assets/` directory and referenced in readme.txt.
- [ ] CHANGELOG.md entry for this release.
- [ ] No custom update server (free build relies on wp.org).

---

## 9. Contribution workflow

### 9.1 Branching

- `main` — always shippable. Tagged releases live here.
- `develop` — integration branch for the current cycle.
- `feature/<phase>-<short-name>` — feature branches off `develop`.
- `hotfix/<short-name>` — hotfix branches off `main`, merged back to both `main` and `develop`.

### 9.2 Commits

- Conventional Commits prefix: `feat:`, `fix:`, `perf:`, `refactor:`, `docs:`, `test:`, `chore:`, `build:`, `ci:`.
- Reference the audit ID (e.g. `C6`, `H4`) or the implementation-plan phase when relevant: `feat(C6): replace byte-offset insertion with block-tree resolution`.
- Each commit should pass tests and lint independently.

### 9.3 Pull requests

- Always open against `develop` (except hotfixes).
- Include a PR description with:
  - What audit findings or implementation-plan tasks this PR addresses.
  - Which phase of `IMPLEMENTATION_PLAN.md` it advances.
  - Manual test steps performed.
  - New automated tests added (if any).
- Two reviewers required for changes touching `includes/Insertion/`, `includes/AI/`, `includes/Premium/`, or any security-sensitive surface.
- One reviewer required otherwise.
- CI must pass: PHPCS, PHPStan, PHPUnit, Jest, Plugin Check (when wired up).

### 9.4 Code review expectations

Reviewers must check:

- Does it follow `agents.md` § 3 (security)?
- Does it follow `agents.md` § 4 (performance)?
- Does it use the right capability check?
- Does it follow `agents.md` § 8 (known pitfalls)?
- Are tests adequate? (Critical paths require unit + integration; trivial changes may need none.)
- Is documentation updated? (`HOOKS.md`, `agents.md`, `development.md`, `CHANGELOG.md`)

### 9.5 Changelog

`CHANGELOG.md` is the canonical history. Format: [Keep a Changelog](https://keepachangelog.com/) + [Semantic Versioning](https://semver.org/).

Each PR that changes user-visible behavior must update `CHANGELOG.md`'s `## [Unreleased]` section. At release time, that section is renamed to the version and dated.

### 9.6 Release process

1. Tag version on `main`.
2. Update plugin header `Version`, `SIM_VERSION` constant, and `readme.txt` `Stable tag` — all three must agree.
3. Regenerate POT.
4. Run wp.org compliance checklist (§ 8).
5. SVN-push to wp.org (free build only).
6. For premium add-on: separate release pipeline through Freemius.
7. Tag corresponding GitHub release with notes.

---

## 10. Known issues & technical debt

This is the honest record of what we know is wrong, what we've fixed, and what remains. Read `docs/audit/00-summary-and-verdict.md` for the full inventory.

### 10.1 Resolved (in current versions)

- **CHANGELOG 2.5.2**: Memory exhaustion from `posts_per_page => -1` — paginated.
- **CHANGELOG 2.5.2**: `wp_cache_flush()` after every insert — replaced with `clean_post_cache()`.
- **CHANGELOG 2.5.2**: Unconditional `print_r($_POST)` — gated behind debug mode.
- **CHANGELOG 2.6.4–2.6.6**: FIAA fallback-thumbnail edge cases — fixed.
- **CHANGELOG 2.6.0–2.6.3**: FIAA cron interval rescheduling — fixed.

### 10.2 Resolved by Phase 0 (v2.6.7 baseline)

- Stable tag mismatch (audit C1).
- Missing `load_plugin_textdomain` (audit C3).
- Undefined `$content` in `insert_image` (audit C4).
- Inline `onclick=` in admin bar (audit W8).
- Activation `flush_rewrite_rules()` (audit W10).
- Stale `Tested up to:` (audit C2).
- Bulk Processor placeholder UI (audit C5 — hidden in 2.6.7, built in phase 4).
- Undo-functionality false-advertising (audit C7, W5).

### 10.3 Resolved by Phase 1 (v2.7.0)

- Capability mismatch (audit C9, I7).
- Single shared nonce (audit M6).
- AJAX `edit_posts` instead of `edit_post` (audit S1, H9).
- 27 autoloaded options (audit A10, PERF4).
- Raw SQL transient cleanup (audit M13, PERF8).
- `error_log()` not WP-aware (audit H12).
- FIAA double menu register (audit M2).
- FIAA `LIKE %guid%` performance issue (audit PERF6).
- No freemium boundary (audit C8) — gate built; defaults still permissive.

### 10.4 Resolved by Phase 2 (v2.8.0)

- Heading-position drift (audit C6, D2).
- `class-sim-ajax.php` god class (audit A1).
- No REST endpoints (audit M5).
- Block construction duplicated in two places (audit A5).
- Modal `location.reload()` after insert (audit U4).
- Three duplicate UI entry points (audit U3).

### 10.5 Resolved by Phase 3 (v2.9.0)

- Whole-media-library transient (audit H2, PERF1).
- Match table grows on every modal open (audit H4, PERF3).
- Synchronous AI call (audit M8, PERF2).
- Always-loaded JS (audit PERF10).

### 10.6 Resolved by Phase 4 (v2.9.x)

- Bulk Processor placeholder (audit C5, P3) — actual feature.
- `wp_sim_queue` dead weight (audit C10).
- Cleanup insufficient (audit H5, CR4).
- Match history review queue (audit CO2, P7).
- `sim_minimum_image_spacing` unused (audit CO5).
- FIAA cron does too much (audit CR2) — moved to AS.

### 10.7 Resolved by Phase 5 (v2.9.x or 3.0.0-beta)

- Weak encryption (audit H1, S3, R3) — encryption deleted entirely.
- Silent AI fallback (audit M1, R5, E4) — UI signals when AI unavailable.
- Dead AI-related settings (audit CO3) — dropped.
- No external services disclosure (audit W9, R6) — free build makes no external calls.

### 10.8 Resolved by Phase 6 (v3.0.0)

- No freemium UX (audit U10) — premium UI distinct from free.
- `wp.editor` legacy alias (audit M11) — migrated.
- Premium extracted into separate add-on plugin.
- wp.org submission filed.

### 10.9 Out of scope for v3.0.0 (deferred to post-launch)

- English-only stop words (audit M7) — addressed by the multilang premium feature in phase 7.
- Per-post-type matching profiles (audit N2) — premium roadmap.
- ACF integration (audit N8) — premium roadmap.
- Match analytics dashboard (audit N7) — premium roadmap.
- Auto-match on publish (audit N1) — premium roadmap.

### 10.10 Will never be done (deliberately)

- Multilingual core matching beyond stop-word configurability — translate.wordpress.org handles plugin strings; matching in arbitrary languages would require ports of the stemmer and is better left to the multilang premium add-on.
- Custom update server for the free build — wp.org-updated only.
- Bundling Akismet/Hello-Dolly-style premium features in the free build.
- Direct provider integrations bypassing `wp_ai_client_prompt()`.

---

## 11. Documentation map

| File | Audience | Purpose |
|---|---|---|
| `agents.md` | AI coding agents | Rules every agent must obey. Mirrors much of this file with stricter prohibitions. |
| `development.md` (this file) | Human developers | Single source of truth for how dev is done. |
| `IMPLEMENTATION_PLAN.md` | Both | The phased plan from v2.6.6 → v3.0.0. |
| `readme.txt` | wp.org users | Plugin listing. |
| `README.md` | GitHub visitors | Thin overview pointing to readme.txt + development.md. |
| `CHANGELOG.md` | Both | Canonical version history. |
| `docs/HOOKS.md` | Plugin extenders | Every action and filter the plugin defines, with signatures and examples. |
| `docs/audit/` | Both | The audit findings the plan is built on. Historical record. |
| `docs/smart-image-matcher-spec.md` | Both | Original product spec. Outdated in places — defer to `IMPLEMENTATION_PLAN.md` on conflicts. |
| `.agent-skills/*/SKILL.md` | Both | Workflow guides for specific WP areas (Abilities, REST, performance, etc.). Read the relevant SKILL before working in that area. |
| `.wp-ai/ai/` | Both | Reference implementation of the WP AI plugin. Read-only documentation of how the canonical AI patterns work. |

---

## 12. Quick links

- WordPress Coding Standards: https://developer.wordpress.org/coding-standards/
- Plugin Handbook: https://developer.wordpress.org/plugins/
- Abilities API handbook: https://developer.wordpress.org/apis/abilities-api/
- WP AI on Make: https://make.wordpress.org/ai/
- Action Scheduler: https://actionscheduler.org/
- Plugin Check (PCP): https://wordpress.org/plugins/plugin-check/
