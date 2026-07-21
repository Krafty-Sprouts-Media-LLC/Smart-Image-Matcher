# wp.org Plugin Review Fixes

Documenting all changes made to address the wp.org Plugin Directory review
feedback (Review ID: AUTOPREREVIEW TRM-LIC smart-image-matcher/iamkingsleyf/20Jun26/T1).

---

## 1. Trialware / Locked Features (Guideline 5)

**Problem:** The review flagged premium feature gating (`Premium::has()` checks,
"Pro" badges, upgrade links, license checks) as trialware — locked functionality
is not permitted in wp.org-hosted plugins.

**Fix:** All features are now fully enabled in the wp.org build. No functionality
is locked, gated, or limited behind a license check.

### Changes made

| File | Change |
|---|---|
| `src/Premium.php` | `Premium::has()` now always returns `true`. The class is kept as a feature registry for future add-on use, but it no longer gates anything. |
| `src/Plugin.php` | `registerFeatures()` now registers all features with `default => true`. Removed `SIM_FREE_BUILD` / `SIM_DISABLE_PREMIUM` / `SIM_ENABLE_FIAA_AUTOMATION_DEV` conditional logic. `registerHooks()` now always registers all services (BulkProcessor, ReviewQueue, FiaaCron, AiMatcher, AiAltText) without `Premium::has()` checks. Removed `clearLockedFiaaAutomation()` method. |
| `src/Settings/Settings.php` | Removed all `Premium::has()` conditional branches from renderers. Removed "Pro" badges, "Upgrade" text, and upgrade page links. AI section title changed from "AI Features (Pro)" to "AI Features". Carousel max changed from 3 (free) / 10 (pro) to always 10. |
| `src/Settings/Sanitizer.php` | Removed `Premium::has()` checks from sanitization logic. All settings now always sanitize as enabled. |
| `admin/views/dashboard.php` | Replaced "Pro active"/"Pro locked" status with "Active". |
| `admin/views/featured-images.php` | Removed "Pro" badge, "Pro Active" label, and the locked-state notice. Scheduled automation section now always shows the enabled UI. |
| `admin/views/bulk-processor.php` | Changed "Pro workflow" label to "Batch workflow". |
| `build-zip.ps1` | Added exclusions for `src/Premium/License.php` and `src/UI/PremiumLock.php` — these files contain license-check and upgrade-link code that should not ship to wp.org. |

### What was NOT removed (kept in source for future add-on)

- `src/Premium/` namespace classes (BulkProcessor, AiMatcher, FiaaCron, etc.) — these implement actual functionality, not gating. They ship in the wp.org zip and are fully active.
- `src/Premium.php` — the feature registry class. Ships in the zip but `has()` always returns `true`.
- `src/UI/PremiumLock.php` — excluded from the zip via `build-zip.ps1`. Retained in source for future add-on use.
- `src/Premium/License.php` — excluded from the zip via `build-zip.ps1`. Retained in source for future add-on use.

---

## 2. Plugin Name

**Status:** The user chose to keep the original name "Smart Image Matcher".
The review team's AI suggested it was too generic. This is the one item that
may require further discussion with the reviewer.

---

## 3. Contributors

**Problem:** The readme.txt contributors list (`kraftysprouts`) did not include
the submitting user's wp.org username (`iamkingsleyf`).

**Fix:** Added `iamkingsleyf` to the Contributors line in `readme.txt`.

---

## 4. load_plugin_textdomain()

**Problem:** The review noted that `load_plugin_textdomain()` is not needed for
wp.org-hosted plugins since WordPress 4.6 — WordPress auto-loads translations.

**Fix:** Removed the `load_plugin_textdomain()` call from `Plugin::init()`.

---

## 5. Outdated Library

**Problem:** Action Scheduler was at version 3.9.3; latest is 4.0.0.

**Fix:** Updated `composer.json` constraint to `"^3.8 || ^4.0"` and ran
`composer update woocommerce/action-scheduler`. Now at 4.0.0.

---

## 6. register_setting() Sanitization

**Status:** False positive. The `register_setting()` call already includes
`'sanitize_callback' => array(new Sanitizer(), 'sanitize')`. The review's
automated tool may not have recognized the array-style callable. No change
needed.

---

## 7. Prefix

**Status:** The `sim_` prefix (3 letters + underscore) is used consistently
across all options, hooks, tables, and functions. The review suggests 4+
character prefixes but did not flag any specific violations. No change made
to avoid breaking existing installs.

---

## Future Pro Add-On Plan

The premium infrastructure remains in the source tree for a future separate
Pro add-on plugin (hosted on kraftysprouts.com, NOT on wp.org). The plan:

1. **Pro add-on plugin** calls `Premium::enable($slug)` for each feature
   it provides.
2. **Pro add-on** ships its own license validation (Freemius or custom).
3. **wp.org build** stays fully functional with all features enabled.
4. **Pro add-on** adds features on top — it does not lock or remove
   functionality from the wp.org build.

### What should be free (wp.org build) vs paid (Pro add-on)

| Feature | wp.org (free) | Pro add-on |
|---|---|---|
| Keyword matching | Yes | — |
| Single-post modal | Yes | — |
| Image insertion | Yes | — |
| Carousel (up to 10) | Yes | — |
| Featured Image Auto-Assigner (upload-time) | Yes | — |
| Scheduled FIAA | Yes | Enhanced scheduling |
| Bulk Processor | Yes | Advanced filters |
| AI matching | Yes | — |
| AI alt-text | Yes | — |
| Vision matching | Yes | — |
| Analytics dashboard | Yes | — |
| Match history review queue | Yes | — |
| Auto-match on publish | Yes | — |
| WP-CLI commands | Yes | — |

The wp.org build includes everything. The Pro add-on would add value through
enhanced scheduling, priority support, and advanced configuration options —
not by locking existing functionality.
