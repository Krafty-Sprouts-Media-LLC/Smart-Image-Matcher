# Restore Smart Image Matcher Name Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the plugin's complete product and technical identity from Heading Image Matcher to Smart Image Matcher without reverting functional or security fixes.

**Architecture:** Apply a mechanical identity migration across runtime code, public copy, build configuration, and translation metadata. Preserve behavior, stored-data compatibility, and all non-naming changes, then generate a release archive rooted at `smart-image-matcher/`.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, JavaScript, Composer, PowerShell ZIP tooling.

## Global Constraints

- Public product name is `Smart Image Matcher`.
- Free plugin slug, folder, bootstrap filename, and text domain are `smart-image-matcher`.
- PHP namespace is `SmartImageMatcher` and global prefix is `smart_image_matcher_` / `SMART_IMAGE_MATCHER_`.
- Premium edition will use `Smart Image Matcher Pro`; no premium implementation is part of this change.
- Preserve all reviewer-driven security and permission fixes.
- Do not revert unrelated user changes in the dirty worktree.

---

### Task 1: Runtime Identity

**Files:**
- Rename: `heading-image-matcher.php` to `smart-image-matcher.php`
- Modify: `smart-image-matcher.php`, `src/**/*.php`, `admin/**/*.php`, `admin/js/src/*.js`, `uninstall.php`

**Interfaces:**
- Consumes: existing Heading Image Matcher runtime identifiers.
- Produces: `SmartImageMatcher\\*`, `smart_image_matcher_*`, `SMART_IMAGE_MATCHER_*`, and `smart-image-matcher/*` runtime identifiers.

- [ ] **Step 1: Record a failing identity audit**

Run `rg -n "HeadingImageMatcher|heading_image_matcher|HEADING_IMAGE_MATCHER|heading-image-matcher" smart-image-matcher.php src admin uninstall.php` after the bootstrap rename.

Expected: matches showing the old identity.

- [ ] **Step 2: Apply the mechanical runtime rename**

Replace the four identifier families with their Smart Image Matcher equivalents while preserving behavior and data flow.

- [ ] **Step 3: Verify the runtime identity audit passes**

Run the same `rg` command.

Expected: no matches.

### Task 2: Package and Public Identity

**Files:**
- Modify: `readme.txt`, `README.md`, `CHANGELOG.md`, `composer.json`, `package.json`, `phpcs.xml.dist`, `build-zip.ps1`
- Rename: `languages/heading-image-matcher.pot` to `languages/smart-image-matcher.pot`
- Modify: `languages/smart-image-matcher.pot`

**Interfaces:**
- Consumes: restored runtime identity from Task 1.
- Produces: consistent product metadata, translation catalog, and packaging configuration.

- [ ] **Step 1: Record failing package identity checks**

Run `rg -n "Heading Image Matcher|heading-image-matcher" readme.txt README.md CHANGELOG.md composer.json package.json phpcs.xml.dist build-zip.ps1 languages`.

Expected: matches showing the old identity.

- [ ] **Step 2: Restore package and public metadata**

Replace the display name and slug, rename the POT catalog, and ensure package/build configuration targets `smart-image-matcher`.

- [ ] **Step 3: Verify package identity checks pass**

Run the same `rg` command.

Expected: no matches.

### Task 3: Verification and Release Archive

**Files:**
- Create: `smart-image-matcher.zip`

**Interfaces:**
- Consumes: restored runtime and package identities.
- Produces: an installable release ZIP with one `smart-image-matcher/` root.

- [ ] **Step 1: Lint shipped PHP**

Run PHP syntax lint across every PHP file included in the release.

Expected: zero syntax errors.

- [ ] **Step 2: Run available automated checks**

Run PHPUnit and PHPCS when their executables and configured dependencies are available; report exact limitations otherwise.

- [ ] **Step 3: Build the release archive**

Generate `smart-image-matcher.zip` while excluding hidden files, development files, tests, premium-only files, and nested archives.

- [ ] **Step 4: Audit the archive**

Verify the root folder and bootstrap filename are `smart-image-matcher`, hidden files are absent, and no Heading Image Matcher identity remains in shipped text files.

