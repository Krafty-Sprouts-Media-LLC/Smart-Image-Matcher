# agents.md — Rules for AI Coding Agents

This file is for AI coding agents working on Smart Image Matcher. It encodes the lessons from the audit (`docs/audit/`) into prohibitions and patterns. Read this before making any change.

---

## 1. Plugin overview

**Smart Image Matcher** scans posts/pages and intelligently inserts images from the media library next to relevant headings. It also auto-assigns featured images by matching post slugs to attachment slugs.

**Operational modes:**
- Single-post modal (post edit screen): user clicks "Smart Image Matcher" → modal scans headings → user reviews/inserts.
- Featured Image Auto-Assigner: on attachment upload (real-time) and on a configurable cron (bulk).
- (Planned premium) Bulk processor: queue-driven matching across many posts.

**Architecture summary:**
- PHP 7.4+ (target 8.0+ for new code) / WordPress 6.0+ (Abilities require 6.9+, AI features require 7.0+).
- PHP namespace `SmartImageMatcher\<Subns>`. PSR-4 autoload from `src/`. PascalCase class names with no underscores.
- Custom tables: `wp_sim_matches` (audit log + review queue), `wp_sim_queue` (bulk job tracking), `wp_sim_image_terms` (inverted index, post-phase-3).
- Settings stored in a single `sim_settings` option (autoload=no). Runtime state in `sim_runtime`.
- Asset enqueue gated by admin screen hook AND by feature flag.
- REST endpoints under `/wp-json/smart-image-matcher/v1/`. Legacy AJAX shim removed in phase 2.
- External integration: `wp_ai_client_prompt()` only. The plugin never talks to providers directly.

---

## 2. WordPress standards to enforce

- Adopt **WordPress Coding Standards** (WPCS) for PHP, JavaScript, and CSS. Run PHPCS with the WordPress ruleset before committing PHP changes.
- All **user input** is sanitized with `wp_unslash()` followed by an appropriate `sanitize_*()` function at the point of use.
- All **output** is escaped at the point of output: `esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`, `wp_kses_post()`. No exceptions.
- All **database writes** with user input go through `$wpdb->prepare()`. Constant queries do not need `prepare()`.
- All **table references** use `$wpdb->prefix`, `$wpdb->posts`, `$wpdb->options`, etc. Never hardcode `wp_`.
- All **strings** wrapped in i18n functions: `__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `_n()`, `_x()`. Text domain: `smart-image-matcher`.
- **Hooks naming:** prefix every hook with `sim_`. Document non-default priorities in the docblock.
- **Functions/classes naming:** PSR-4 PascalCase classes (`InsertionService`, not `SIM_Insertion_Service`). File name matches class name. Top-level helpers stay `sim_snake_case()` only when they must live at file scope (activation hooks, etc.).
- **Options naming:** prefix every option with `sim_`. **Always pass `'no'` for the `$autoload` parameter** unless the option is genuinely needed on every request.
- **Activation:** check WP version, PHP version, and `extension_loaded('openssl')`. Bail with `wp_die()` on any failure.
- **Deactivation:** clear all `sim_*` cron events. Clear transients via `delete_transient` calls (not raw SQL).
- **Uninstall:** **always** clear cron events. Conditionally clear data based on `sim_delete_on_uninstall` setting.

---

## 3. Security rules — MANDATORY

### 3.1 Capability checks

**WRONG (the plugin's current pattern):**
```php
if ( ! current_user_can( 'edit_posts' ) ) {
    wp_send_json_error( ... );
}
$post_id = (int) $_POST['post_id'];
wp_update_post( array( 'ID' => $post_id, ... ) );
```

**CORRECT:**
```php
$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
if ( ! current_user_can( 'edit_post', $post_id ) ) {
    wp_send_json_error( array( 'message' => __( 'Permission denied', 'smart-image-matcher' ) ) );
}
```

For attachment IDs, also confirm the user has permission to read it (or rely on `wp_attachment_is_image()` and public status as appropriate).

### 3.2 Nonces — per-action

**WRONG (current):**
```php
wp_create_nonce( 'sim_editor_nonce' );  // Same nonce shared by 3 different operations
check_ajax_referer( 'sim_editor_nonce', 'nonce' );
```

**CORRECT:**
```php
wp_create_nonce( 'sim_find_matches' );
wp_create_nonce( 'sim_insert_image' );
wp_create_nonce( 'sim_insert_all_images' );
```

Localize all of them via `wp_localize_script` and use the matching nonce per AJAX action.

### 3.3 Sanitization patterns

| Input | Sanitizer |
|---|---|
| Post/attachment IDs | `(int) $value` or `absint( $value )` |
| Plain text strings | `sanitize_text_field( wp_unslash( $value ) )` |
| Textareas | `sanitize_textarea_field( wp_unslash( $value ) )` |
| URLs | `esc_url_raw( wp_unslash( $value ) )` |
| Slugs / keys | `sanitize_key( wp_unslash( $value ) )` |
| Email addresses | `sanitize_email( wp_unslash( $value ) )` |
| Booleans (checkbox) | `isset( $_POST['flag'] ) ? 1 : 0` |
| HTML allowed | `wp_kses( wp_unslash( $value ), $allowed )` |
| Floats | `floatval( wp_unslash( $value ) )` |
| Comma-separated lists | `array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw ) ) ) )` |

### 3.4 Output escaping

| Context | Escape |
|---|---|
| HTML body | `esc_html()` |
| HTML attribute | `esc_attr()` |
| URL | `esc_url()` |
| `<textarea>` | `esc_textarea()` |
| Allowed HTML | `wp_kses_post()` |
| JSON for `wp_localize_script` | none — WP handles it |
| JS string for inline output | `esc_js()` |

### 3.5 Encryption (sensitive data at rest)

Do **not** copy the legacy `SIM_Core::encrypt_data` pattern in `.legacy/`. It uses a static IV and an unauthenticated cipher. After phase 5 the plugin holds no secrets at all (provider credentials live in WordPress's Connectors store). Should secret storage ever be reintroduced, this is the corrected pattern:

```php
public static function encrypt_data( $plaintext ) {
    if ( '' === $plaintext ) { return ''; }
    $key   = self::get_encryption_key();
    $iv    = random_bytes( 12 );
    $tag   = '';
    $ct    = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
    return base64_encode( $iv . $tag . $ct );
}

private static function get_encryption_key() {
    $key = get_option( 'sim_encryption_key' );
    if ( ! $key ) {
        $key = base64_encode( random_bytes( 32 ) );
        update_option( 'sim_encryption_key', $key, false );
    }
    return base64_decode( $key );
}
```

On decryption failure: return `WP_Error`, never silently empty string.

### 3.6 No inline JavaScript

**WRONG:**
```php
'onclick' => 'jQuery("#sim-modal").show(); ...'
```

**CORRECT:** Bind events in enqueued JS files via event delegation.

---

## 4. Performance rules — MANDATORY

These rules exist because of documented past performance failures (CHANGELOG 2.5.2) and the structural causes the audit found.

### 4.1 Never on page load

- **NEVER** call `wp_cache_flush()` from any handler. Use `clean_post_cache( $post_id )` for per-post invalidation. The only time `wp_cache_flush()` is acceptable is on uninstall.
- **NEVER** make synchronous HTTP requests from page-load or AJAX handlers. The Anthropic API call must run in a background queue (Action Scheduler or `wp_sim_queue`-driven worker).
- **NEVER** load all attachments via `posts_per_page => -1`. Use paginated batches (the existing `class-sim-cache.php::get_cached_media_library` pattern, with `no_found_rows` and disabled meta/term cache prefetch).
- **NEVER** add an option without explicitly setting `$autoload = 'no'`:
  ```php
  add_option( 'sim_my_option', $default, '', 'no' );  // CORRECT
  add_option( 'sim_my_option', $default );             // WRONG (defaults to autoload=yes)
  ```
- **NEVER** call `print_r()` or `var_dump()` outside `Logger::isDebugMode()` guards.
- **NEVER** flush rewrite rules unless the plugin actually adds rewrite rules.

### 4.2 Always cache

- **Match results** are cached by `(post_id, post_modified, mode)`. If the post hasn't changed, the same modal open returns instantly.
- **Media library inverted index** (when introduced) is updated on `add_attachment`, `attachment_updated`, `delete_attachment`. Never on read.
- **AI API responses** are cached by `(post_id, post_modified, candidate_image_ids hash, model)` for 24 hours.
- **External rate-limit counters** use transients with explicit expiration.

### 4.3 Always queued

- AI Claude calls.
- Bulk operations across many posts (FIAA cron, future bulk processor).
- Match scoring against very large media libraries (5 000+ images) when SQL-driven matching is not yet available.

### 4.4 The 30-second rule

Any single user-facing request must complete in **under 5 seconds** under reasonable assumptions. If a code path has a chance of exceeding that:
- Move it to a background queue.
- Show a "queued" UI to the user.
- Let the user poll for completion.

---

## 5. Database rules

### 5.1 Existing tables

| Table | Purpose | Notes |
|---|---|---|
| `wp_sim_matches` | Audit log of all match attempts | `heading_position` is unstable as a key — current bug; see audit C6/D2 |
| `wp_sim_queue` | Bulk job queue | Currently unused; do not write to it until bulk processor ships |

### 5.2 Indexes

- `wp_sim_matches`: `post_id_idx`, `status_idx`.
- `wp_sim_queue`: `status_idx`, `post_id_idx`.
- When introducing the inverted index (planned): `wp_sim_image_terms(image_id, term, weight, source)` with index on `term`.

### 5.3 Query rules

- All queries with user input use `$wpdb->prepare()`.
- Direct queries against core tables (`$wpdb->posts`, `$wpdb->options`, etc.) are allowed when `WP_Query`/`get_posts` cannot express the need (e.g. selecting only `(ID, post_name)`). Add a PHPCS ignore comment when doing so:
  ```php
  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
  ```
- Never bypass `$wpdb` to talk to MySQL directly.
- Never run `LIKE '%...%'` queries against unindexed columns (e.g. `guid` lookup in current code — must be removed).

### 5.4 Schema changes

- Track schema version in option `sim_db_version`.
- Run migrations in a single `Migrator::maybe_migrate()` invoked on `plugins_loaded`.
- All `CREATE TABLE` statements go through `dbDelta()`. Format must match WP's strict expectations (column types lowercase, two spaces between `PRIMARY KEY` and `(...)`).

### 5.5 Cleanup

The `sim_daily_cleanup` cron is responsible for pruning `wp_sim_matches` and `wp_sim_queue`. When extending the schema, extend the cleanup task.

---

## 6. Freemium boundaries

### 6.1 The boundary

A `Premium` static class controls feature gating. Every premium handler is wrapped:

```php
if ( Premium::has( 'ai_matching' ) ) {
    register_rest_route( 'smart-image-matcher/v1', '/ai-match', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => array( $container->get( 'ai.matcher' ), 'restMatch' ),
        'permission_callback' => array( AI\Matcher::class, 'restPermission' ),
    ) );
}
```

**The free build never assumes premium is active. Premium handlers register themselves via a separate add-on plugin that calls `Premium::enable()`.**

### 6.2 Free vs premium catalog

| Component | Tier |
|---|---|
| Keyword matching | Free |
| Single-post modal | Free |
| Image insertion (single + bulk-in-modal) | Free |
| Carousel up to 3 alternatives | Free |
| Linguistic enhancements (stemming, spelling, whitelist) | Free |
| Cache plugin compatibility | Free |
| Encryption at rest | Free |
| FIAA upload-time auto-assign (post + page only) | Free |
| Settings page | Free |
| AI matching (Claude) | **Premium** |
| FIAA scheduled cron run | **Premium** |
| FIAA admin tool with overwrite | **Premium** |
| FIAA upload-time for arbitrary post types | **Premium** |
| Bulk Processor | **Premium** |
| Match history review queue UI | **Premium** |
| Match analytics dashboard | **Premium** |
| Carousel beyond 3 alternatives | **Premium** |
| Auto-match on publish | **Premium** |
| REST API endpoints | **Premium** |
| WP-CLI commands | **Premium** |

### 6.3 Premium UI rules

- Premium settings are visible in the free build but **disabled** with a "Premium" badge.
- Clicking a premium feature surfaces a polite upgrade page; **never auto-redirects to checkout**.
- Removing premium leaves the free build fully functional with no broken references.
- License key validation: cache result for 24 hours. Tolerate 7 days of license-server unreachability before disabling premium handlers.

### 6.4 Mixing forbidden

Do **not** put free and premium code in the same class. Premium logic lives in classes prefixed `Premium_*` or in the separate add-on plugin entirely.

Do **not** branch inside a free class on `Premium::has()` for non-trivial behavior changes — that's how you end up with class-bodies that can't be reasoned about.

---

## 7. wp.org compliance rules

The agent must never:

1. Set the readme.txt `Stable tag` to anything other than the plugin header `Version`.
2. Skip `load_plugin_textdomain()` or fail to ship `languages/smart-image-matcher.pot`.
3. Hardcode `<script>` or `<link>` tags. Always use `wp_enqueue_script()` / `wp_enqueue_style()`.
4. Use `eval()`, `extract()`, `create_function()`, `assert()`, or PHP short tags.
5. Include obfuscated or minified-without-source code in the wp.org build.
6. Store credentials in plain text.
7. Make external HTTP calls without disclosing them in `readme.txt` `== External services ==`.
8. Add tracking, analytics, advertising, or upsell nags that block UI.
9. Ship placeholder UI (e.g. "Coming Soon - Phase 7"). Hide menus until features ship.
10. Skip running Plugin Check (PCP) before submission.
11. Skip running PHPCS with the WordPress ruleset.
12. For **wp.org** builds, do not ship a competing update server — disable GitHub updates with `SMART_IMAGE_MATCHER_DISABLE_GITHUB_UPDATES`. The GitHub-distributed build may use Plugin Update Checker against the public repo.
13. Write to filesystem paths outside `wp-content/uploads/` and the plugin folder.

---

## 8. Known pitfalls (do not repeat)

These are documented bugs from the plugin's history. Pattern-match against them before making any change in the relevant file.

### Insertion engine (most failure-prone area)

- **Don't use byte offsets to identify headings.** They drift after every edit and after every prior insertion in a bulk operation. Use Gutenberg block client-IDs or content hashes.
- **Don't use `strpos($content, render_block($block))` to find a heading.** Repeated headings collide, and the rendered HTML is not always identical to what's in `post_content`.
- **Don't write `width` and `height` attributes on the `<img>` inside a Gutenberg image block.** Gutenberg will fail validation. Use `sizeSlug` only. (CHANGELOG 1.1.1 — already shipped twice.)
- **Don't set image block attrs to anything other than `id`, `sizeSlug`, `linkDestination` for the basic insertion case.** (CHANGELOG 1.1.1.)
- **Don't construct image blocks as serialized strings AND as PHP arrays in different code paths.** Pick the array approach. Serialize once via `serialize_blocks`. (Audit A5.)
- **Don't `wp_cache_flush()` after every insert.** Use `clean_post_cache( $post_id )`. (CHANGELOG 2.5.2.)
- **Don't `wp_update_post()` once per inserted image in a bulk operation.** That creates one revision per image. Build all insertions, then call `wp_update_post()` once. (Audit D9.)

### Matching

- **Don't extend the scoring algorithm with more layered penalty/bonus rules.** The legacy `Matcher::calculate_match_score` is at maintainability breaking point. New requirements should drive a refactor into per-field scorer classes, not yet another conditional branch.
- **Don't filter words shorter than 2 characters universally.** "Io" (Io Moth Caterpillar) is a valid keyword. The whitelist setting exists for a reason. (CHANGELOG 2.4.1.)
- **Don't use `self::method` in `array_map`/`array_filter` callbacks** in PHP 8.x — deprecated. Use `array($this, 'method')` or full class name. (CHANGELOG 2.2.5, 2.3.2.)

### Settings & options

- **Don't use 27 individual autoloaded options.** Group into one or two options with `autoload=no`.
- **Don't call `wp_cache_flush()` from settings save.** Targeted `delete_transient` calls only.
- **Don't write the `sim_claude_api_key` raw.** Encrypt before save, decrypt on read.

### Performance

- **Don't `posts_per_page => -1`.** (CHANGELOG 2.5.2.) Always paginate.
- **Don't `print_r($_POST)` outside debug-mode guard.** (CHANGELOG 2.5.2.)
- **Don't `wp_remote_post` synchronously from an AJAX request.** Queue it.

### Compatibility

- **Don't break Gutenberg block validation.** Run a manual save→reload→verify after any insertion change.
- **Don't break Classic Editor.** It is older but still in use by a meaningful share of WordPress sites; keep the regex-based path working.
- **Don't drop cache-plugin compatibility hooks** without checking which plugins still use them.

### Lifecycle

- **Don't call `flush_rewrite_rules()` on activation.** No rewrite rules registered. (Audit W10.)
- **Don't skip cron cleanup on uninstall.** Cron events become orphan. (Audit CR11.)

---

## 9. File structure map

End-state (post-rebuild). During phases 1–5 the v2.6.x layout under `.legacy/` exists in parallel — don't put new code there.

```
smart-image-matcher.php             ← thin bootstrap → SmartImageMatcher\Plugin::boot()
uninstall.php                       ← uninstall cleanup (always clears cron)
agents.md                           ← THIS FILE
development.md                      ← human dev docs
IMPLEMENTATION_PLAN.md              ← phased rebuild plan
readme.txt                          ← wp.org listing
README.md                           ← thin GitHub overview
CHANGELOG.md
LICENSE.txt
composer.json                       ← PSR-4: SmartImageMatcher\\ → src/
phpcs.xml.dist                      ← WPCS ruleset
phpunit.xml.dist
phpstan.neon.dist
package.json                        ← @wordpress/scripts

src/                                ← namespace SmartImageMatcher
    Plugin.php                      ← boot, lifecycle, hooks
    Container.php                   ← service registry
    Migrator.php                    ← schema versioning
    Premium.php                     ← feature gate
    Settings/
        Settings.php                ← single sim_settings option, Settings API
        Sanitizer.php
    Domain/
        Matcher.php                 ← scoring engine (free)
        Normalizer.php              ← text normalization (free)
        HeadingExtractor.php        ← block-tree-aware heading extractor
        ImageRepository.php         ← inverted-index-backed media catalog
        MatchRepository.php         ← wp_sim_matches persistence
    Insertion/
        InsertionService.php        ← block-tree based (Gutenberg + Classic)
        BlockBuilder.php            ← single source of truth for image blocks
        HeadingLocator.php          ← stable heading hash <-> block client_id
    FeaturedImages/
        FeaturedImageService.php    ← FIAA core: slug→attachment match (free)
        SlugMapBuilder.php
    Cache/
        Cache.php                   ← transient + cache-plugin compat
        ObjectCacheAdapter.php
    AI/
        ProviderBridge.php          ← thin wrapper over wp_ai_client_prompt()
        Matcher.php                 ← AI-mode matching (PREMIUM)
        MatchPrompt.php
        ResultParser.php
    Queue/
        Queue.php                   ← thin facade over Action Scheduler
        JobRunner.php
    Abilities/
        Registry.php                ← bulk registration on the right hook
        AbilityFindMatches.php
        AbilityInsertImage.php
        AbilityScoreImage.php
        AbilityAssignFeaturedImage.php
        AbilityQueueBulkMatch.php   ← (premium-gated)
    REST/
        Controller.php              ← base
        MatchController.php
        InsertController.php
        FeaturedImageController.php
        BulkController.php
    Logging/
        Logger.php                  ← WP_DEBUG_LOG-aware structured logger
    Compat/
        CachePluginCompat.php       ← cache-plugin compatibility hooks
        LegacyAjaxAdapter.php       ← only present during phase 1; deleted in phase 2
    UI/
        PremiumLock.php             ← renders premium fields with a "Premium" badge
    CLI/
        Commands.php                ← wp sim ... commands
    Premium/                        ← excluded from wp.org zip in phase 6
        AiMatcher.php
        FiaaCron.php
        FiaaTool.php
        BulkProcessor.php
        ReviewQueue.php
        Analytics.php
        AutoMatchOnPublish.php
        AiAltText.php
        AiVisionMatch.php
        AiFeaturedImage.php
        License.php

admin/
    css/
        sim-admin.css               ← always-loaded, slim
        sim-modal.css               ← post-edit only
        sim-bulk.css                ← bulk page only
    js/
        src/
            trigger.js              ← tiny entry; lazy-loads modal
            modal.js                ← modal logic (vanilla)
            gutenberg.js            ← Gutenberg sidebar + client abilities
            bulk.js                 ← bulk processor SPA
            svg-icons.js
        build/                      ← @wordpress/scripts output (gitignored)
    views/
        settings-page.php
        bulk-processor.php
        review-queue.php
        featured-images.php
        analytics.php

languages/
    smart-image-matcher.pot         ← MUST EXIST before wp.org submission

tests/
    bootstrap.php
    phpunit/                        ← unit + integration suites
    js/
    fixtures/

docs/
    audit/                          ← historical audit findings
    smart-image-matcher-spec.md     ← original spec (defer to IMPLEMENTATION_PLAN.md on conflict)
    HOOKS.md                        ← all sim_* actions/filters this plugin defines

.legacy/                            ← gitignored from wp.org build; deleted at v3.0.0
    (the v2.6.x source files, kept as read-only reference during the rebuild)
```

### Where new code goes

- **New free service** → `src/<Subns>/<ClassName>.php` with namespace `SmartImageMatcher\<Subns>`. Register in `Plugin::registerServices()` via the container.
- **New premium service** → `src/Premium/<ClassName>.php` with namespace `SmartImageMatcher\Premium`. Register in `Plugin::registerPremiumServices()` only when the matching `Premium::has()` flag is true.
- **New REST endpoint** → controller in `src/REST/<Area>Controller.php`. Wire into `REST\Registrar` which hooks `rest_api_init`.
- **New ability** → one class in `src/Abilities/Ability<VerbNoun>.php`. Register through `Abilities\Registry` on `wp_abilities_api_init` (categories on `wp_abilities_api_categories_init` first).
- **New admin view** → `admin/views/<feature>.php`.
- **New admin asset** → `admin/css/sim-<feature>.css` or `admin/js/src/<feature>.js`. Enqueue conditionally — never always-on.
- **New CLI command** → method on `CLI\Commands` registered with `WP_CLI::add_command( 'sim <subcommand>', ... )`.
- **Never** put new code in `.legacy/`. That tree is read-only.

---

## 10. Testing expectations

Before considering any change done:

### 10.1 Static checks

- **PHPCS** with WordPress ruleset passes (or new warnings are documented).
- **PHPStan** at level 5+ passes (when introduced).
- **ESLint** with the `@wordpress/eslint-plugin` config passes for any JS change.

### 10.2 Manual verification

For every change, run through:

1. Fresh install on a clean WordPress: activate → verify no fatals → settings save → uninstall → verify clean removal.
2. On a post with mixed H2/H3/H4 headings: open modal → select matches → insert all → verify post saves correctly → verify Gutenberg validation passes (no orange "block contains unexpected or invalid content" notices).
3. With a provider configured in **Settings → Connectors**: switch matcher to AI mode → open modal → verify AI matches arrive with reasoning.
4. With NO provider configured: verify AI mode is hidden from the UI and keyword mode still works.
5. Cache plugin installed (WP Rocket or W3TC): insert image → verify cached page reflects change.
6. Featured Image Auto-Assigner: upload an image with slug matching an existing post → verify featured image set; run cron → verify summary persists.
7. Multilingual: switch site to German/Spanish → verify strings translate (after pot generation + .mo for test).
8. Free build (`SIM_DISABLE_PREMIUM` defined): verify all premium features are hidden, free features still work.

### 10.3 Automated tests (when introduced — Phase 5+ priority)

- Unit tests for `Domain\Normalizer` (pure functions).
- Unit tests for `Domain\Matcher::calculateScore` against fixture image sets.
- Integration tests for `Insertion\InsertionService` against fixture posts in both Gutenberg and Classic flavors.
- Integration tests for `FeaturedImages\FeaturedImageService::run` with seeded data.

### 10.4 Performance smoke test

For changes touching the matcher or media library:

- Seed 5 000 attachments in a test environment.
- Open the modal on a post with 10 H2s.
- Cold path (cache miss): must complete < 2 s.
- Warm path (cache hit on the same post): must complete < 100 ms.

For changes touching the AI path:

- Mock the AI provider to delay 5 s.
- Verify the user is **not** blocked (queue-based design — modal returns immediately, polls for completion).
