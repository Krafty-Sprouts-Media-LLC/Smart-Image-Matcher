# Article Processor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One per-article processor that inserts strong library matches, parks the middle band on Bulk Processor Review, generates only when the library would skip (if enabled), and skips the rest — with SIM admin reduced to Dashboard, Bulk Processor (Run | Review), and Settings.

**Architecture:** Pure `MatchDecision` plus `ArticleProcessor::process( $post_id )`. Bulk Run, cron, and publish enqueue one Action Scheduler job per post. Generation is an optional adapter; the free processor never calls `Premium::has()`. Featured Images and Review Queue submenus are removed (no URL redirects).

**Tech Stack:** WordPress PHP 7.4+, `sim_settings`, Action Scheduler, REST `smart-image-matcher/v1`, vanilla `admin/js/src/bulk.js`.

**Spec:** `docs/superpowers/specs/2026-08-31-article-processor-design.md`

## Global Constraints

- PHP 7.4+, WordPress 6.0+, namespace `SmartImageMatcher\…`, real tabs, `array()`, Yoda, i18n text domain `smart-image-matcher`.
- Do not change `@since` on existing symbols. New symbols `@since 3.2.31` (or current next patch if 3.2.30 already shipped).
- Version bump + `CHANGELOG.md` + `readme.txt` Stable tag + `package.json` after every shippable task.
- Never `Premium::has()` inside free `ArticleProcessor`.
- Never `wp_cache_flush()`; one `wp_update_post()` per post for heading inserts.
- Do not use `MatchRepository::saveMatchGroups()` in the processor (modal-only).
- Do not add admin redirects or hidden submenu aliases for removed pages.
- Commit only when the user asks (do not auto-commit per task unless they say so).
- PHPUnit: `php vendor/phpunit/phpunit/phpunit --no-coverage <path>` from the plugin root.
- Uncommitted 3.2.30 review-thumbnail fix (`BulkController::attachImageUrls`) must land with or before Task 3.

---

## File map

| File | Responsibility |
|---|---|
| `src/Domain/MatchDecision.php` | Pure decide() |
| `src/Domain/GenerationFallback.php` | Interface: `isAvailable()`, `enqueue()` |
| `src/Domain/ArticleProcessor.php` | One post: featured + headings |
| `src/Domain/MatchRepository.php` | `upsertPending()` for review-band rows only |
| `src/Insertion/InsertionService.php` | `headingHasFollowingImage()` |
| `src/FeaturedImages/FeaturedImageService.php` | Public `scoreBestForPost()` (no assign) |
| `src/Premium/ArticleGenerationFallback.php` | Adapter wrapping Queue + AiImageGenerator in-flight |
| `src/Queue/Queue.php` + `JobRunner.php` | `HOOK_PROCESS_ARTICLE` / `runArticleProcessJob` |
| `src/Settings/Settings.php` + `Sanitizer.php` | `auto_insert_threshold`, copy, drop Featured Images menu |
| `src/Premium/ReviewQueue.php` | Stop registering submenu |
| `src/Premium/AutoMatchOnPublish.php` | Call processor |
| `src/Premium/FiaaCron.php` | Enqueue process-article per post |
| `src/REST/BulkController.php` | Grouped `articles[]` review payload |
| `admin/js/src/bulk.js` + `admin/views/bulk-processor.php` | Run \| Review tabs |
| `admin/views/dashboard.php` | CTAs to Bulk Processor |
| `src/Plugin.php` | Bind processor + optional adapter |

---

### Task 1: MatchDecision + auto-insert setting

**Files:**
- Create: `src/Domain/MatchDecision.php`
- Create: `tests/phpunit/Domain/MatchDecisionTest.php`
- Modify: `src/Settings/Settings.php` (`$defaults`, matching section field, `renderConfidenceThreshold` copy, new `renderAutoInsertThreshold`)
- Modify: `src/Settings/Sanitizer.php` (`auto_insert_threshold` clamped 0–100 and `>= confidence_threshold`)
- Modify: `CHANGELOG.md`, `smart-image-matcher.php`, `readme.txt`, `package.json`

**Interfaces:**
- Consumes: nothing new
- Produces: `MatchDecision::decide( int $score, int $auto_insert, int $review_min, bool $can_generate ): string` returning exactly `'insert'|'review'|'generate'|'skip'`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * Unit tests for MatchDecision.
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.2.31
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\MatchDecision;

class MatchDecisionTest extends TestCase {

	public function test_score_at_or_above_auto_insert_inserts(): void {
		$this->assertSame( 'insert', MatchDecision::decide( 100, 90, 70, false ) );
		$this->assertSame( 'insert', MatchDecision::decide( 90, 90, 70, true ) );
	}

	public function test_middle_band_reviews_even_if_generation_on(): void {
		$this->assertSame( 'review', MatchDecision::decide( 80, 90, 70, true ) );
	}

	public function test_below_review_without_generation_skips(): void {
		$this->assertSame( 'skip', MatchDecision::decide( 40, 90, 70, false ) );
		$this->assertSame( 'skip', MatchDecision::decide( 0, 90, 70, false ) );
	}

	public function test_below_review_with_generation_generates(): void {
		$this->assertSame( 'generate', MatchDecision::decide( 40, 90, 70, true ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/phpunit/phpunit/phpunit --no-coverage tests/phpunit/Domain/MatchDecisionTest.php`

Expected: FAIL — `Class SmartImageMatcher\Domain\MatchDecision not found`

- [ ] **Step 3: Implement MatchDecision + setting**

`src/Domain/MatchDecision.php`:

```php
public static function decide( int $score, int $auto_insert, int $review_min, bool $can_generate ): string {
	if ( $score >= $auto_insert ) {
		return 'insert';
	}
	if ( $score >= $review_min ) {
		return 'review';
	}
	if ( $can_generate ) {
		return 'generate';
	}
	return 'skip';
}
```

Settings `$defaults`: `'auto_insert_threshold' => 90`.

Sanitizer (after reading both ints):

```php
$review = max( 0, min( 100, (int) ( $raw['confidence_threshold'] ?? 70 ) ) );
$auto   = max( 0, min( 100, (int) ( $raw['auto_insert_threshold'] ?? 90 ) ) );
if ( $auto < $review ) {
	$auto = $review;
}
```

Register field after confidence. Copy:

- Confidence: “Minimum score to show in the editor modal and to send to Review during automation.”
- Auto-insert: “Matches at or above this score are inserted without review.”

- [ ] **Step 4: Run tests**

Run: `php vendor/phpunit/phpunit/phpunit --no-coverage tests/phpunit/Domain/MatchDecisionTest.php`

Expected: OK (4 tests)

- [ ] **Step 5: Changelog 3.2.31** — added auto-insert threshold + decision helper (no behavior change yet until Task 2).

---

### Task 2: ArticleProcessor (library insert / review / skip)

**Files:**
- Create: `src/Domain/GenerationFallback.php`
- Create: `src/Domain/ArticleProcessor.php`
- Create: `tests/phpunit/Domain/ArticleProcessorTest.php`
- Modify: `src/Domain/MatchRepository.php` — add `upsertPending()`
- Modify: `src/Insertion/InsertionService.php` — add `headingHasFollowingImage()`
- Modify: `src/FeaturedImages/FeaturedImageService.php` — add public `scoreBestForPost( int $post_id ): array`
- Modify: `src/Plugin.php` — bind `article.processor`
- Modify: `src/Queue/Queue.php`, `src/Queue/JobRunner.php`
- Modify: changelog / version → 3.2.32

**Interfaces:**
- Consumes: `MatchDecision::decide()`
- Produces:
  - `GenerationFallback::isAvailable(): bool`
  - `GenerationFallback::enqueue( int $post_id, string $heading_hash, string $heading_text, string $section_text ): bool` (true if queued)
  - `ArticleProcessor::process( int $post_id, array $options = array() ): array{inserted:int,review:int,generated:int,skipped:int}`
  - `$options['overwrite_featured']` bool default false
  - `$options['review_min']` int|null — if set, raises review floor for this job only; auto-insert always from settings
  - `MatchRepository::upsertPending( int $post_id, string $heading_hash, string $heading_text, string $heading_tag, int $image_id, int $score, string $method ): void`
  - `FeaturedImageService::scoreBestForPost( int $post_id ): array{score:int,attachment_id:int}` — no candidate ⇒ `score => 0`, `attachment_id => 0`
  - `InsertionService::headingHasFollowingImage( int $post_id, string $heading_hash ): bool`
  - `Queue::HOOK_PROCESS_ARTICLE = 'smart_image_matcher_queue_process_article'`
  - `Queue::enqueueProcessArticle( int $post_id, string $job_id = '', array $config = array() ): ?string`
  - `JobRunner::runArticleProcessJob( int $post_id, string $job_id = '', array $config = array() ): void`

- [ ] **Step 1: Failing processor tests** (fakes; do not boot WordPress)

Stub in the test file (or `$GLOBALS` callables already in `tests/bootstrap.php`) for `get_post`, `wp_get_attachment_image_url`, `set_post_thumbnail`. Inject fakes via constructor.

Cases:

1. High-score heading (100) → `inserted === 1`, generation `enqueue` never called.
2. Score 80 with auto 90 / review 70 → `review === 1`, no insert, no enqueue.
3. Score 0, no adapter → `skipped >= 1`, enqueue never called.
4. Score 0, adapter `isAvailable() === true` → `generated === 1`, enqueue called once.
5. Heading that `headingHasFollowingImage` reports true → skip that heading.

Constructor:

```php
public function __construct(
	Matcher $matcher,
	ImageRepository $images,
	HeadingExtractor $extractor,
	InsertionService $insertion,
	MatchRepository $matches,
	FeaturedImageService $featured,
	?GenerationFallback $generation = null
)
```

Scoring headings: do **not** call `findKeywordMatches()` (it already filters at `confidence_threshold`). Use `findCandidates()` + `calculateScore()` and take the max so skip-band is visible.

Featured: if post supports thumbnail and has no actionable featured (existing `FeaturedImageService` stored-thumbnail helpers; add a public wrapper if needed) and not overwrite: `scoreBestForPost` → `MatchDecision`. Insert ⇒ `set_post_thumbnail`. Review ⇒ `upsertPending( …, 'featured', … )`. Generate ⇒ adapter `enqueue( $id, 'featured', title, excerpt )`.

Headings: hierarchy filter as today. Skip if `headingHasFollowingImage`. Collect insert rows; `bulkInsert` once; on `WP_Error` upsert those rows as pending instead.

`upsertPending`: delete existing pending row for that `post_id`+`heading_hash`, then insert one pending row. Do not touch approved rows. Do not delete other headings’ pending rows.

`headingHasFollowingImage`: Gutenberg — locate heading block, next sibling `core/image` or `core/gallery`. Classic — after the heading HTML, next element is `<img` or `[gallery` / image shortcode. False if post missing.

`scoreBestForPost`: reuse private `selectBestSlugMatch` / slug map; return best numeric score even if below old FIAA auto-assign gate. Do not assign.

- [ ] **Step 2: Run tests — expect FAIL** (class missing)

- [ ] **Step 3: Implement classes + `JobRunner::runArticleProcessJob`**

If `$job_id !== ''`, keep existing bulk cancel/progress helpers (`isBulkJobCancelled`, `incrementBulkJobDone`).

Replace body of `runBulkMatchJob` with:

```php
$config = is_array( $config ) ? $config : array();
$options = array();
if ( isset( $config['min_score'] ) ) {
	$options['review_min'] = (int) $config['min_score'];
}
Plugin::instance()->container->get( 'article.processor' )->process( $postId, $options );
```

If `Plugin::instance()` is awkward, instantiate `ArticleProcessor` the same way other JobRunner methods new up services. Prefer container only if already used; otherwise `new ArticleProcessor( ... )` with `null` generation until Task 6.

`Queue::registerHooks`: `add_action( self::HOOK_PROCESS_ARTICLE, array( JobRunner::class, 'runArticleProcessJob' ), 10, 3 );`

- [ ] **Step 4: Run `ArticleProcessorTest` + `MatchDecisionTest` — expect PASS**

- [ ] **Step 5: Manual** — Bulk Processor Run on one post with mixed scores: 100% headings insert in content; ~80% appear pending in DB; unmatched do not generate yet.

---

### Task 3: Bulk Processor Review tab (grouped)

**Files:**
- Modify: `src/REST/BulkController.php` — `getMatches` returns `articles[]` (use existing `attachImageUrls`); keep `matches` as flattened list for one release if JS still reads it, **or** switch JS in the same task (prefer one shape: `articles` only, update JS).
- Modify: `admin/js/src/bulk.js`, `admin/views/bulk-processor.php`
- Modify: `src/Premium/ReviewQueue.php` — `registerMenu()` becomes empty / do not `add_submenu_page`
- Modify: `src/Plugin.php` — stop `( new ReviewQueue() )->register()` if the class only existed for the menu; keep `bulkApproveAboveThreshold` helpers if still used
- Changelog → 3.2.33

**Interfaces:**
- Consumes: `BulkController::attachImageUrls()`
- Produces REST:

```
{
  articles: [ { post_id, post_title, edit_url, headings: [ { id, heading_tag, heading_text, heading_hash, image_id, image_url, confidence_score } ] } ],
  total_articles, page, per_page
}
```

Paginate by distinct `post_id`, not match rows. Default `status=pending`. Optional `job_id` filter: if present, keep today’s job-time window; if absent or `job_id=all`, all pending (cron leftovers).

- [ ] **Step 1:** PHPUnit for grouping: two pending rows same `post_id` → one article, two headings. Can be a pure helper `BulkController::groupMatchesByPost( array $rows ): array` tested without WP REST.

- [ ] **Step 2:** FAIL (method missing)

- [ ] **Step 3:** Implement group helper + change `getMatches`. `bulk.js`: persistent **Run | Review** nav (not wizard-only step 4). Review tab loads `/jobs/{id}/matches` when a job is current, else a new `GET /smart-image-matcher/v1/review?page=1` that lists all pending grouped (add this route on BulkController; `manage_options`). Nested table, `image_url` thumbnails, existing approve/reject/insert-approved. Filter query `slot=featured|heading|all`.

- [ ] **Step 4:** Tests PASS. Unregister Review Queue submenu.

- [ ] **Step 5:** Manual — Review tab shows articles not a flat heading dump; approve still inserts.

No redirects for `smart-image-matcher-review-queue`.

---

### Task 4: Settings + Dashboard + remove Featured Images menu

**Files:**
- Modify: `src/Settings/Settings.php` — remove Featured Images `add_submenu_page`; update cron/publish/upload copy; reorder comment (Dashboard → Bulk → Settings)
- Modify: `src/Settings/Settings.php` `reorderSettingsToBottom` comments
- Modify: `admin/views/dashboard.php` — primary button Bulk Processor Run (`admin.php?page=smart-image-matcher-bulk`); pending metric links to bulk Review (`…bulk&sim_tab=review`). Remove Match Runner → featured-images.
- Stop rendering featured-images as a menu. Leave `admin/views/featured-images.php` on disk until Task 5 ports generate-featured (or delete if generate UI is copied into bulk in Task 5).
- Changelog → 3.2.34

**Copy (replace existing strings):**

- `renderFiaaCronSectionDescription`: “Scheduled article processing. Each selected post runs the same rules as Bulk Processor: insert strong library matches, send the middle band to Review, generate only if on-demand generation is enabled and nothing usable is in the library.”
- `renderFiaaCronEnabled` description: background article processing, not “featured-image matching” only.
- `renderAiAutoFeaturedOnPublishToggle`: “On first publish, process the article (featured + headings). Library matches follow auto-insert/review thresholds. Skip-band generation runs only if on-demand generation is enabled.”
- Upload section: keep as upload-only. Do not add a run button.

- [ ] **Step 1:** No PHPUnit required for copy. Grep after: `add_submenu_page` must not register `smart-image-matcher-featured-images` or `smart-image-matcher-review-queue`.

- [ ] **Step 2:** Implement menu + copy + dashboard CTAs.

- [ ] **Step 3:** Manual — SIM menu is Dashboard, Bulk Processor, Settings. Featured Images gone. Settings has no Run now.

No redirects.

---

### Task 5: Generate missing featured as Bulk Run mode

**Files:**
- Modify: `admin/js/src/bulk.js` — Run mode select: `process` (default) | `generate-featured`
- Modify: `src/REST/BulkController.php` `createJob` — if `mode === 'generate-featured'`, enqueue existing featured-gen jobs (same as current Featured Images generate path / `ImageGenController` bulk enqueue), **not** `ArticleProcessor`. Hard confirm in JS: N posts, estimate copy from current featured generate UI.
- Port confirm + progress from `admin/js/src/generate-images.js` / featured-ai bulk modal as needed; do not keep a second menu.
- Changelog → 3.2.35

**Interfaces:**
- Consumes: existing generate-featured REST
- Produces: bulk job `mode` enum includes `generate-featured`

- [ ] Confirm dialog required before enqueue.
- [ ] Process-articles path unchanged.
- [ ] Manual: generate-featured still only posts missing thumbnails; does not scan headings.

---

### Task 6: Generation adapter on skip-band

**Files:**
- Create: `src/Premium/ArticleGenerationFallback.php`
- Modify: `src/Plugin.php` `registerPremiumServices` / bind — `generation.fallback` then re-`bind` `article.processor` with the adapter
- Modify: `src/Premium/AiImageGenerator.php` persist path — vision fail already pending; ensure skip-band enqueue uses `Queue::enqueueAiImageGen` with `heading_hash` featured or heading hash
- Changelog → 3.2.36

**Interfaces:**
- Consumes: `GenerationFallback`
- Produces: `ArticleGenerationFallback` implements it:

```php
public function isAvailable(): bool {
	return (bool) Settings::get( 'ai_image_generation_enabled' )
		&& ProviderBridge::isImageGenerationAvailable();
}
public function enqueue( int $post_id, string $heading_hash, string $heading_text, string $section_text ): bool {
	if ( ! $this->isAvailable() ) {
		return false;
	}
	if ( AiImageGenerator::isInFlight( $post_id, $heading_hash ) ) {
		return false;
	}
	$id = ( new Queue() )->enqueueAiImageGen( array(
		'post_id'      => $post_id,
		'heading_hash' => $heading_hash,
		'heading_text' => $heading_text,
		'section_text' => $section_text,
		'style'        => (string) Settings::get( 'ai_image_style' ) ?: 'photo',
	) );
	return null !== $id;
}
```

JobRunner must construct ArticleProcessor **with** this adapter when the class exists (or via container after rebind). If JobRunner `new`s the processor, pass `class_exists( ArticleGenerationFallback::class ) ? new ArticleGenerationFallback() : null`.

- [ ] Extend `ArticleProcessorTest` skip-band + fake adapter (already in Task 2). Add one test that `isAvailable() === false` never calls enqueue.
- [ ] Manual: generation ON, unmatched heading enqueues AS `HOOK_AI_IMAGE_GEN`; 100% library hit does not.

---

### Task 7: Cron + publish call the processor

**Files:**
- Modify: `src/Premium/FiaaCron.php` `runScheduledAssignment` — after collecting `$postIds`, enqueue `Queue::enqueueProcessArticle( $id, $jobId, $config )` per post (same overlap guard). Stop using `enqueueFiaaRun` for this scheduled path. `overwrite` → `$config['overwrite_featured']` passed into `process()` options.
- Modify: `src/Premium/AutoMatchOnPublish.php` — on first publish, if setting on: `$processor->process( $post->ID )` (or enqueue `enqueueProcessArticle` so publish request stays fast — **prefer enqueue**, 30-second rule). Remove featured-only generate-after-slug block; processor owns generate-on-skip.
- Modify: `JobRunner::runFiaaRunJob` — if still used by leftover manual Match Runner, either delete the admin button (already gone in Task 4) and leave the hook for in-flight jobs, or make `runFiaaRunJob` call `ArticleProcessor` per post in the batch. Prefer: batch job iterates IDs and `process()` each (keeps 20-post chunks). Scheduled path in FiaaCron should enqueue per-post `HOOK_PROCESS_ARTICLE` to match bulk.
- Changelog → 3.2.37

- [ ] Manual: publish a new post with setting on, generation off → library insert/review/skip, no fal job. Generation on + no library match → featured or heading gen queued. Cron tick processes headings not just featured.
- [ ] Confirm upload-time FIAA still assigns on `add_attachment` (untouched).

---

## Spec coverage

| Spec item | Task |
|---|---|
| MatchDecision insert/review/generate/skip | 1 |
| auto_insert_threshold | 1 |
| ArticleProcessor featured + headings, one wp_update_post | 2 |
| upsertPending not saveMatchGroups | 2 |
| Generate only on skip-band | 2+6 |
| Bulk Run uses processor | 2 |
| Review grouped on Bulk page | 3 |
| Review Queue submenu gone | 3 |
| Settings = policy, no run | 4 |
| Featured Images submenu gone, no redirects | 4 |
| Dashboard CTAs | 4 |
| Generate-featured as Run mode, hard confirm | 5 |
| Generation adapter, no Premium::has in processor | 6 |
| Cron + publish | 7 |
| Upload FIAA unchanged | 7 (verify only) |
| Modal unchanged | 2 (do not touch saveMatchGroups callers in MatchController) |

## Placeholder scan

None: no TBD, no “add validation later”, no redirects.

## Type consistency

- Outcomes: `'insert'|'review'|'generate'|'skip'`
- Counts: `inserted`, `review`, `generated`, `skipped`
- Adapter: `isAvailable()`, `enqueue( int, string, string, string ): bool`
- Hook: `HOOK_PROCESS_ARTICLE` / `runArticleProcessJob( int $post_id, string $job_id = '', array $config = array() )`
