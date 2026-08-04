# On-Demand AI Image Generation — Implementation Plan

**Status:** Draft  
**Parent:** Smart Image Matcher v3.1+  
**Depends on:** `ai-provider-for-fal-ai` (separate plugin), WP 7.0+ AI Client

---

## 0. Overview

Allow users to generate images on demand from within the Smart Image Matcher modal. When a heading has zero or poor keyword matches, a "Generate" button appears. The system translates the heading + article context into a visual prompt via a cheap text model, then queues an image generation job through fal.ai. The result is sideloaded into the media library and appears as a new match candidate in the modal.

**Key insight:** We never send raw SEO keywords or full articles to the image generator. A cheap text model translates the human-language input into a visual scene description first. The expensive image generation receives only a one-sentence visual brief.

---

## 1. Architecture

```
                           CHEAP (sync, <1s)                    EXPENSIVE (queued, 5-30s)
                                  │                                       │
Heading text + paragraph ──►  PromptBuilder  ──►  visual brief  ──►  fal.ai generateImage()
+ focus keyword (optional)    generateText()                         ProviderBridge::generateImage()
                                  │                                       │
                                  ▼                                       ▼
                          "Closeup of wilting petunias              Sideload → media library
                           with spotted leaves in a garden          → set as match candidate
                           bed, natural outdoor lighting"           → update matches table
```

```
Files to create:
  src/AI/PromptBuilder.php            ← keyword → visual-brief translator
  src/Premium/AiImageGenerator.php    ← on-demand image gen service
  admin/views/generate-images.php     ← bulk generation admin page

Files to modify:
  src/Plugin.php                      ← register feature, boot service
  src/Queue/Queue.php                 ← new hook + enqueue helper
  src/Queue/JobRunner.php             ← new job callback
  src/REST/MatchController.php        ← add /generate-image route (or new controller)
  src/Settings/Settings.php           ← setting + renderer
  src/Settings/Sanitizer.php          ← sanitize new setting
  src/Premium.php                     ← feature registration
  admin/js/src/modal.js               ← "Generate" button + polling UI
```

---

## 2. Component specs

### 2.1 `src/AI/PromptBuilder.php`

**Namespace:** `SmartImageMatcher\AI`  
**Class:** `PromptBuilder`

Public method:

```php
/**
 * Build a visual scene description for an AI image generator.
 *
 * Uses a cheap text model (via ProviderBridge::generateText) to translate
 * a post context into a single-sentence visual prompt suitable for an
 * image generator. Never sends the raw SEO keyword to the image model.
 *
 * @param string $titleOrHeading   Post title or heading text.
 * @param string $focusKeyword     SEO focus keyphrase (optional, can be empty).
 * @param string $contentExcerpt   First 2-3 paragraphs of the section/post.
 * @param string $style            Style hint: 'photo', 'illustration', 'infographic'.
 * @return string|\WP_Error        Single-sentence visual description, or error.
 */
public function buildImagePrompt(
    string $titleOrHeading,
    string $focusKeyword = '',
    string $contentExcerpt = '',
    string $style = 'photo'
);
```

**System message:**

> You are a visual-brief writer. Given a post title, an optional focus keyword, and a short text excerpt, write a single-sentence visual scene description suitable for an AI image generator. Use only visual nouns, colors, composition, lighting, style cues, and mood. Never include questions, pricing data, numbers, statistics, call-to-action phrases, or non-visual concepts. Never output markdown, code fences, or extra prose. Output exactly one sentence ending with a period.

**Temperature:** 0.4 (slight creativity for mood/lighting words, but mostly deterministic)


### 2.2 `src/Premium/AiImageGenerator.php`

**Namespace:** `SmartImageMatcher\Premium`  
**Class:** `AiImageGenerator`  
**Gate:** `Premium::has('ai_image_generation')`

Public methods:

```php
/**
 * Generate an image for a specific heading in a post.
 *
 * Called from the queued job (Action Scheduler). Builds the visual prompt
 * via PromptBuilder, calls ProviderBridge::generateImage(), sideloads
 * the result into the media library, and stores the attachment ID as a
 * match candidate in the matches table.
 *
 * @param string $headingHash   Stable heading identifier.
 * @param string $headingText   Heading text.
 * @param string $sectionText   First paragraph(s) after this heading.
 * @param int    $postId        Post ID the heading belongs to.
 * @param string $focusKeyword  Optional SEO focus keyphrase.
 * @return int|WP_Error         Attachment ID on success, WP_Error on failure.
 */
public function generateForHeading(
    string $headingHash,
    string $headingText,
    string $sectionText,
    int    $postId,
    string $focusKeyword = ''
);

/**
 * Generate a featured image for a post (FIAA fallback).
 *
 * Replaces the inline logic currently in AiFeaturedImage. Uses PromptBuilder
 * to translate the post context into a visual prompt.
 *
 * @param int $postId Post ID.
 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
 */
public function generateFeaturedForPost( int $postId );
```

**Sideload logic** (shared, extracted from existing `AiFeaturedImage::sideloadImage`):

```php
private function sideloadImage( string $url, int $postId, string $title ): ?int
```

**Caching:** Results cached by `md5(heading_hash . ':' . $focusKeyword . ':' . $style)` for 30 days. Never regenerate the same heading+keyword+style combination.

**Attachment tracking:** Generated images get a `_sim_generated` post meta flag and a `_sim_generated_prompt` meta with the visual brief, so the user can see what prompt produced the image and the system can exclude generated images from future generation prompts (avoiding recursive "AI training on AI output" problems).


### 2.3 Queue integration (`Queue.php` + `JobRunner.php`)

**New hook constant** in `Queue.php`:

```php
const HOOK_AI_IMAGE_GEN = 'smart_image_matcher_queue_ai_image_gen';
```

**New enqueue helper:**

```php
/**
 * Enqueue an AI image generation job.
 *
 * @param string $headingHash  Stable heading identifier (or 'featured' for featured images).
 * @param string $headingText  Heading text or post title.
 * @param string $sectionText  Surrounding paragraph text.
 * @param int    $postId       Post ID.
 * @param string $focusKeyword Optional focus keyphrase.
 * @return string|null AS action ID or null if unavailable.
 */
public function enqueueAiImageGen(
    string $headingHash,
    string $headingText,
    string $sectionText,
    int    $postId,
    string $focusKeyword = ''
): ?string;
```

**New hook registration** in `Queue::registerHooks()`:

```php
add_action( self::HOOK_AI_IMAGE_GEN, array( JobRunner::class, 'runAiImageGenJob' ), 10, 5 );
```

**New job runner** in `JobRunner.php`:

```php
/**
 * Run an AI image generation job.
 *
 * Calls AiImageGenerator::generateForHeading(), stores the result as a
 * transient keyed by (post_id, heading_hash) so the modal can poll for it.
 *
 * @param string $headingHash  Stable heading identifier.
 * @param string $headingText  Heading text.
 * @param string $sectionText  Surrounding paragraph text.
 * @param int    $postId       Post ID.
 * @param string $focusKeyword Optional focus keyphrase.
 */
public static function runAiImageGenJob(
    string $headingHash,
    string $headingText,
    string $sectionText,
    int    $postId,
    string $focusKeyword = ''
): void;
```

Transient storage:

```php
// Key:
"smart_image_matcher_img_gen_{$postId}_{$headingHash}"

// Value:
array(
    'status'           => 'processing' | 'done' | 'failed',
    'attachment_id'    => int|null,
    'attachment_url'   => string|null,
    'prompt_used'      => string,       // visual brief that went to fal.ai
    'error'            => string|null,  // error message if failed
)
```

**Deactivation cleanup:** Add `HOOK_AI_IMAGE_GEN` to the `as_unschedule_all_actions` loop in `Plugin::deactivate()`.


### 2.4 REST endpoint

**New route** on `MatchController` (or a new `ImageGenController` if preferred):

```
POST /smart-image-matcher/v1/posts/<post_id>/generate-image
```

**Args:**

| Param | Type | Required | Description |
|---|---|---|---|
| `post_id` | int | yes | URL param |
| `heading_hash` | string | yes | Stable heading identifier |
| `heading_text` | string | yes | Heading text |
| `section_text` | string | yes | First paragraph(s) after this heading |
| `focus_keyword` | string | no | SEO focus keyphrase (from Rank Math / Yoast) |
| `style` | string | no | `photo`, `illustration`. Default: `photo` |

**Permission:** `current_user_can( 'edit_post', $post_id )`

**Response (queued):**

```json
{
    "status": "queued",
    "heading_hash": "abc123...",
    "job_id": "12345",
    "poll_url": "/wp-json/smart-image-matcher/v1/generate-image/status?post_id=42&heading_hash=abc123"
}
```

**New polling route:**

```
GET /smart-image-matcher/v1/generate-image/status?post_id=<id>&heading_hash=<hash>
```

**Response (in progress):**

```json
{ "status": "processing" }
```

**Response (done):**

```json
{
    "status": "done",
    "attachment_id": 789,
    "attachment_url": "https://...",
    "image_html": "<img src=\"...\" />",
    "prompt_used": "Closeup of wilting petunias..."
}
```

**Response (failed):**

```json
{
    "status": "failed",
    "error": "Image generation timed out."
}
```


### 2.5 Settings

**New settings** in the AI Features section:

| Key | Default | Label | Description |
|---|---|---|---|
| `ai_image_generation_enabled` | `false` | On-demand image generation | When no suitable match is found for a heading, generate an AI image in the modal. Uses image-generation credits. |
| `ai_image_subject_gate` | `true` | Subject gate | Skip generation for product names, branded items, and named people that AI can't render well. |
| `ai_image_verify_vision` | `false` | Vision verification | After generation, verify the image matches the keyword using vision AI before approving. Recommended for bulk and automated runs. Uses additional AI credits per image. |
| `ai_image_alt_mode` | `keyword` | Alt text mode | How generated images get `_wp_attachment_image_alt`. See §6.2. |

| `ai_image_alt_mode` value | Behavior |
|---|---|
| `keyword` | Alt = focus keyword (title-cased), fallback to heading / post title. Best for SEO-first workflows. |
| `descriptive` | Alt = short accessibility description from the visual brief (cheap text model). Optionally weaves in the focus keyword when present. Best for WCAG / screen readers. |

Add to:
- `Settings::$defaults` (four values: `ai_image_generation_enabled`, `ai_image_subject_gate`, `ai_image_verify_vision`, `ai_image_alt_mode`)
- `Settings::register()` — `addField()` calls
- `Settings` — new renderer methods: `renderAiImageGenToggle()`, `renderAiSubjectGateToggle()`, `renderAiVisionVerifyToggle()`, `renderAiAltModeSelect()`
- `Sanitizer::sanitize()` — boolean sanitization for the three toggles; `sanitize_key()` allowlist for `ai_image_alt_mode` (`keyword` \| `descriptive`)
- `Premium::registerFeature()` — `ai_image_generation`


### 2.6 Frontend (`modal.js`)

**When to show the "Generate" button:**

A heading card in the modal shows a "Generate" button when:
- Setting `ai_image_generation_enabled` is true
- The heading has zero matches, OR all matches have `relevance_score < 40`

**Button placement:** In the heading card, below "No suitable images found" or in the match list as a last-resort option.

**Flow:**

```
[Generate] click
  → Disable button, show "Building prompt..." spinner
  → POST /generate-image
  → Show "Generating image..." with progress bar
  → Poll /generate-image/status every 3 seconds (max 60 seconds)
  → On done: append generated image as a new match card with a "AI-generated" badge
  → On failed: show error message, re-enable button
```

**State machine:**

```
idle → building_prompt → generating → done
                                     → failed → idle
```

**Match card for generated images:**

```html
<div class="sim-match-card sim-match-generated">
  <span class="sim-ai-badge">AI Generated</span>
  <img src="..." alt="..." />
  <div class="sim-match-actions">
    <button class="sim-insert-btn">Insert</button>
    <button class="sim-regenerate-btn">Regenerate</button>
  </div>
</div>
```

**Regenerate:** Discards the cached prompt transient and restarts the flow. The text model may produce a different visual brief on the second call (`temperature: 0.4` gives slight variation).


### 2.7 Paragraph extraction

The modal needs to send the paragraph text following each heading. Currently `HeadingExtractor` only returns heading metadata — not the content between headings.

**Option A — Frontend extracts:** After `HeadingExtractor::extract()`, the REST endpoint walks the block tree to find the first non-heading paragraph after each heading and includes it as `section_text` in each heading object.

**Option B — New extractor method:** Add `HeadingExtractor::extractWithContext()` that returns `{heading, section_text}` pairs.

**Recommendation: Option A** — the REST endpoint in `MatchController::findMatches()` extracts paragraph context for each heading and includes it in the response. Modal sends it back in the generate-image request. No schema changes, no new extractor methods.


### 2.8 Focus keyword detection

**Where it's read from** — a static helper in `PromptBuilder` checks known SEO plugins:

```php
/**
 * Try to read the SEO focus keyword for a post.
 *
 * Checks active SEO plugins in order. Returns empty string if none found.
 *
 * @param int $postId Post ID.
 * @return string Focus keyword or empty string.
 */
public static function getFocusKeyword( int $postId ): string {
    $sources = array(
        'rank_math_focus_keyword',     // Rank Math
        '_yoast_wpseo_focuskw',        // Yoast SEO
        '_seopress_analysis_target_kw', // SEOPress
        '_tsf_meta_keyword',           // The SEO Framework
    );

    foreach ( $sources as $meta_key ) {
        $kw = get_post_meta( $postId, $meta_key, true );
        if ( ! empty( $kw ) && is_string( $kw ) ) {
            return trim( $kw );
        }
    }

    return '';
}
```

**When it's read** — at two points:

1. **`MatchController::findMatches()`** — reads the keyword once per post, attaches it to the REST response as `focus_keyword` at the top level alongside the matches array. The frontend sends it back in each generate-image request.

2. **`AiImageGenerator::generateForHeading()`** — reads it again inside the queued job as a fallback. This covers the case where the frontend didn't provide it (Gutenberg sidebar trigger, FIAA cron trigger, or a stale REST response).

**What if there's no focus keyword?**

Generation still works. The system degrades gracefully:

```
Has focus keyword?
  YES → title = keyword, prompt seeded with keyword
  NO  → title = heading text, prompt seeded with heading + paragraph

Alt text (independent of the above):
  ai_image_alt_mode = keyword     → same as title rule
  ai_image_alt_mode = descriptive → short scene description from the visual brief
```

The text model in `PromptBuilder` reads both the heading text and the section paragraph. Even a vague heading like "Introduction" combined with a paragraph about "the history of petunia cultivation in North America" produces a useful visual brief. The keyword is a precision booster, not a requirement. Title/slug always follow the keyword-or-heading rule; alt text follows `ai_image_alt_mode` (§6.2).

**Edge case — heading has no visual content at all:**

```
Heading: "Conclusion"
Paragraph: "In summary, the cost of building varies widely..."
Keyword: none
```

`PromptBuilder` extracts visual nouns from the paragraph alone. The text model handles this because it sees the full context, not just the heading. The result still works — it just may not be as precisely targeted as when a keyword is present.


## 3. Trigger surfaces

| Trigger | Where | Calls | Result |
|---|---|---|---|
| Modal "Generate" button | Post edit screen (Gutenberg + Classic) | `AiImageGenerator::generateForHeading()` | New match candidate in modal |
| FIAA fallback | `FiaaCron` / bulk processor | `AiImageGenerator::generateFeaturedForPost()` | Featured image set on post |

All three share `PromptBuilder` and `ProviderBridge::generateImage()`. Only the trigger and attachment target differ.


## 4. Image style support (future)

The `style` parameter is passed through to `PromptBuilder`, which adjusts the visual brief:

| Style | System message addition | Example suffix |
|---|---|---|
| `photo` | "Describe a realistic photograph." | "...natural daylight, wide shot, photographic" |
| `illustration` | "Describe a digital illustration or vector artwork." | "...clean vector illustration, flat design" |
| `infographic` | "Describe an infographic layout with visual data representation." | Not in v1 — needs structured data input |

Model selection (which fal.ai model to use) is determined by the WP Connector, not SIM. SIM just passes `style` as a hint.


## 5. Dependency: AI Provider for fal.ai (standalone)

Smart Image Matcher never talks to fal.ai directly. Image generation goes through `wp_ai_client_prompt()->generateImage()`.

The **standalone** WordPress plugin [`ai-provider-for-fal-ai`](https://wordpress.org/plugins/ai-provider-for-fal-ai/) (provider id `fal-ai`) — usable by any AI Client consumer, not only SIM — must be installed and configured:

1. Registers with `AiClient::defaultRegistry()->registerProvider( FalProvider::class )`
2. Exposes Flux / Recraft image models to the AI Client
3. Authenticates with `Authorization: Key …` (official `FAL_KEY`)
4. Relies on WordPress Connectors for API key storage / Settings → Connectors UI

Without this provider (or another image-capable provider), `ProviderBridge::generateImage()` returns `WP_Error`.

```php
// ai-provider-for-fal-ai registers so that wp_ai_client_prompt()->generateImage()
// can route to fal. SIM does not need to know about fal.ai at all.
```

Install it like any other plugin under `wp-content/plugins/ai-provider-for-fal-ai/`. It is not part of the SIM package.


## 6. Metadata saved after generation

Every generated image lands in the media library as a standard WordPress attachment. These post meta keys are written to the attachment record:

### 6.1 Attachment meta (post_meta on the attachment)

| Meta key | Value | Purpose |
|---|---|---|
| `_sim_generated` | `1` | Flag so the system knows it's AI-generated (skip from keyword searches, exclude from future generation prompts) |
| `_sim_generated_prompt` | `string` | The visual brief sent to fal.ai (auditable, reusable) |
| `_sim_generated_keyword` | `string` | The primary/focus keyword that seeded the prompt |
| `_sim_generated_heading_hash` | `string` | Which heading triggered this generation |
| `_sim_generated_post_id` | `int` | Which post this image belongs to |
| `_sim_generated_style` | `string` | `photo`, `illustration`, etc. |
| `_sim_generated_model` | `string` | Which model generated it (e.g. `fal-ai/flux-pro/v1.1`) |
| `_sim_generated_input` | `string` | JSON of the full input context: `{title, keyword, excerpt, heading}` |

### 6.2 Attachment record itself

| Field | Rule | Example (with focus keyword) | Example (no focus keyword) |
|---|---|---|---|
| `post_title` | Focus keyword (title-cased), fallback to heading text | "Cost To Build A House In Columbus Ohio" | "Why Are My Petunias Dying?" |
| `post_name` (slug) | `sanitize_title( $title )` | `cost-to-build-a-house-in-columbus-ohio` | `why-are-my-petunias-dying` |
| `post_excerpt` (caption) | *left empty* | — | — |
| `post_content` (description) | The visual brief sent to fal.ai | "Suburban home under construction in Columbus Ohio..." | "Closeup of wilting petunias..." |
| `_wp_attachment_image_alt` | Depends on `ai_image_alt_mode` (see below) | — | — |

**Title / slug rationale:** Always keyword-or-heading. That keeps the media library searchable by SEO term and matches how editors already name uploads. The visual brief stays in `post_content` for audit and prompt review. Never prefix titles with "AI-generated for…".

**Alt text — both modes ship:**

| Mode | Setting value | Alt text source | Example |
|---|---|---|---|
| SEO keyword (default) | `keyword` | Same as `post_title` — focus keyword, else heading / post title | `Cost To Build A House In Columbus Ohio` |
| Descriptive | `descriptive` | Short accessibility sentence derived from the visual brief via the cheap text model. If a focus keyword exists, weave it in naturally once — do not keyword-stuff. | `Wilting petunias with spotted leaves in a garden bed under natural daylight` |

Descriptive-mode prompt (cheap text model, after the visual brief exists):

> Write one short alt-text sentence (max 125 characters) describing this image for screen readers. Be concrete and visual. If a focus keyword is provided, include it naturally once. No quotes, no markdown, no "image of" prefix.

**Cost note:** `keyword` mode is free (string copy). `descriptive` mode adds one cheap `generateText()` call after the visual brief is ready — typically < 400 ms, still inside the queued job.

### 6.3 Priority: title vs alt

```
Title / slug (always):
  featured:   post_title = focus_keyword ?? post_title_of_the_article
  in-content: post_title = focus_keyword ?? heading_text_of_that_section

Alt text (ai_image_alt_mode):
  keyword:     alt = post_title   // same rule as above
  descriptive: alt = text_model(visual_brief, optional focus_keyword)
```

The focus keyword from Rank Math / Yoast owns **title and slug** whenever it exists. Alt text is independently controlled so SEO-first sites and accessibility-first sites can both be correct without fighting each other.

### 6.4 Matches table (`wp_sim_matches`)

A row is upserted so the generated image behaves like any other match candidate:

```json
{
  "post_id": 42,
  "heading_hash": "abc123def",
  "image_id": 789,
  "confidence_score": 100,
  "match_method": "ai_generated",
  "match_data": {
    "prompt": "Closeup of wilting petunias...",
    "focus_keyword": "why are my petunias dying",
    "style": "photo",
    "model": "fal-ai/flux-pro/v1.1",
    "took_ms": 8234,
    "took_prompt_ms": 320
  },
  "status": "approved"
}
```

The `confidence_score` is set to 100 because the image was purpose-built for this heading. The `match_method` of `ai_generated` distinguishes it from keyword and AI-ranked matches.

### 6.5 Why save the keyword?

The focus keyword is the root input — the article's target SEO term. Saving it on the attachment means:

- **Searchability**: The user can later search their media library for "petunias" and find all generated images for that topic.
- **Deduplication**: Before generating, the system checks if an image with this `(post_id, heading_hash, focus_keyword, style)` combo already exists — avoids duplicate generation.
- **Regeneration**: If the user clicks "Regenerate," the system reuses the same keyword + heading but gets a fresh visual brief from the text model (temperature gives variation).
- **Analytics**: The Review Queue can show "12 images generated for keyword 'angel food cake'" across different headings/posts.


## 6A. Duplicate handling

### 6A.1 Same keyword, same heading, same post — skip

Before enqueuing a generation job, check:

```php
// Is there already a generated image for this exact (post, heading, keyword) combo?
$existing = AttachmentRepository::findGenerated( $postId, $headingHash, $focusKeyword );

if ( $existing ) {
    return rest_ensure_response( array(
        'status'       => 'exists',
        'attachment_id' => $existing,
        'message'      => __( 'An image was already generated for this heading.', 'smart-image-matcher' ),
    ) );
}
```

This is a no-op return — the existing generated image is already in the matches table, so it already appears in the modal. No need to regenerate.

### 6A.2 Same keyword, different heading/post — generate new, let WP handle the slug

Two different posts can both target "cost to build a house in columbus ohio". Each gets its own generated image with different visual briefs (different section text → different prompts → different results).

The `post_name` (slug) will collide, but WordPress handles this automatically: the second upload gets `cost-to-build-a-house-in-columbus-ohio-2`, third gets `-3`, etc. This is standard WP behavior via `wp_unique_post_slug()` inside `media_sideload_image()`.

The `post_title` will be identical — "Cost To Build A House In Columbus Ohio" for all of them. This is acceptable because:
- The media library grid view shows thumbnails, not titles first
- The description field (`post_content`) contains the unique visual brief for each
- `_sim_generated_post_id` meta tells you which post each belongs to
- Users search by keyword; seeing 3 images for the same keyword is expected and correct
- WP's built-in attachment slug dedup (`-2`, `-3`) handles URL uniqueness

No need to append `-1`, `-2` suffixes to the title. Let WP handle slugs, let the grid thumbnails differentiate the images visually.

### 6A.3 Same keyword, user already has a real photo with that title — don't touch it

When the user uploaded a real photo and titled it "Cost To Build A House In Columbus Ohio", the generated image gets the same title. The real photo's slug is `cost-to-build-a-house-in-columbus-ohio`, the generated one gets `cost-to-build-a-house-in-columbus-ohio-2`. The real photo is not modified. `_sim_generated` meta flag on the AI image keeps them distinguishable in queries.


## 6B. Ungeneratable subjects

Some keywords describe things AI image generators can't produce well: specific products, branded items, named people, or text-heavy concepts.

### 6B.1 The problem

| Keyword type | Example | What fal.ai produces | Usable? |
|---|---|---|---|
| General concept | "petunias dying" | Closeup of wilting flowers | Yes |
| Location | "homes in columbus ohio" | Suburban houses | Yes |
| Food/drink | "angel food cake pairings" | Cake with berries | Yes |
| Abstract concept | "cost to build a house" | House under construction | Yes (via visual metaphor) |
| Specific product | "iPhone 16 Pro Max review" | Generic smartphone, wrong details | No |
| Branded item | "Nike Air Force 1" | Generic sneaker, wrong logo | No |
| Named person | "Elon Musk" | Approximate face, uncanny valley | No |
| Text-dependent | "top 10 reasons to..." | Garbled text in image | No |

### 6B.2 Detection via the cheap text model

Before generating, `PromptBuilder` can ask the text model a single yes/no question. Controlled by a setting:

| Setting | Default | Description |
|---|---|---|
| `ai_image_subject_gate` | `true` | Before generating, check whether the keyword describes a subject AI can produce (e.g. skip products and named people). Disable to generate for all keywords regardless. |

When enabled:

```
System: You are a subject classifier. Given a focus keyword or heading,
determine whether an AI image generator can produce a useful image for it.
Respond with exactly one word: "yes" or "no".

User: focus_keyword="iPhone 16 Pro Max specs"
```

If the response is `"no"`, the generation is aborted and the frontend shows:

> "AI can't reliably generate images for product-specific or branded subjects. Try uploading a product photo to the media library instead."

### 6B.3 PromptBuilder adjusts for borderline cases

Some keywords are borderline. Rather than rejecting outright, `PromptBuilder` adjusts the visual brief:

| Keyword | Detection | Adjustment |
|---|---|---|
| "iPhone 16 Pro Max review" | `product` | "a smartphone being used outdoors, modern aesthetic, premium feel — do not show brand logos or specific model details" |
| "Elon Musk leadership style" | `person` | "a business leader at a conference, modern office setting, backlit silhouette — do not depict a specific identifiable person" |
| "best laptops for programming" | `product_group` | "a modern laptop on a desk with code on screen, clean workspace — show a generic device, no brand" |

This keeps generation from failing outright on common article types while avoiding uncanny-valley or brand-misrepresentation outputs.

### 6B.4 User rejection as data

If the user rejects a generated image (clicks "Regenerate" or "Skip"), the rejection is logged in the matches table:

```json
{
  "status": "rejected",
  "match_method": "ai_generated",
  "match_data": {
    "rejected_at": "2026-08-04 14:32:00",
    "reject_reason": "poor_quality",
    "attempts": 1
  }
}
```

After 2 rejections for the same heading, the system stops offering generation and shows the "AI can't generate this" message. This is a self-healing mechanism — the system learns from the user what works and what doesn't.


## 6C. Vision verification — post-generation quality gate

The text model translates your keyword into a visual brief. fal.ai generates the image. But how do we catch the case where the model hallucinates? "Angel food cake with berries" should not produce a pizza.

### 6C.1 The gate — reuse existing vision infrastructure

The plugin already has `ProviderBridge::scoreImageWithVision()` (`src/AI/ProviderBridge.php:183`). It sends an image URL + a heading text to a vision model and returns `{score: 0-100, reasoning: "..."}` . Currently used by `AiVisionMatch` for reranking — now reused as a post-generation quality check.

### 6C.2 When it runs

```
fal.ai generates image
  → sideload into media library (now has a public URL)
  → scoreImageWithVision( image_url, focus_keyword ?? heading_text )
  → { score: 82, reasoning: "A white cake with red berries..." }
```

### 6C.3 Thresholds

| Score | Action | Modal display |
|---|---|---|
| ≥ 70 | Auto-approve | Normal match card |
| 40–69 | Flag, let user decide | Match card with "Low confidence" badge, reasoning shown |
| < 40 | Reject automatically | Image is deleted from media library, error shown: "Generated image didn't match the topic. Try again?" |

### 6C.4 When it runs — opt-in

Vision verification is optional. For single on-demand generation, the user sees the image in the modal immediately and can reject it themselves — an automatic vision check is overkill for a one-off.

It becomes valuable in automated/bulk scenarios where nobody is reviewing each image:

| Trigger | Vision verification |
|---|---|
| Modal "Generate" (single) | Off by default — user reviews manually |
| Modal "Generate All" | Off by default — user reviews the batch |
| Bulk processor | Recommended on — no manual review |
| On-publish auto-match | Recommended on — silent background |
| FIAA cron fallback | Recommended on — unattended |

A setting controls it:

| Setting | Default | Description |
|---|---|---|
| `ai_image_verify_vision` | `false` | After generation, verify the image matches the keyword using vision AI before approving. Recommended for bulk and automated runs. Uses additional AI credits per image. |

### 6C.5 Cost tradeoff

Vision scoring costs one additional cheap text-model call per image. In bulk mode with 50 images, that's 50 extra calls — each one is cheap but not free. The setting lets the user decide: pay for the safety net in unattended runs, skip it when they're reviewing manually.

### 6C.6 Full quality chain

```
1. SUBJECT GATE (pre-generation, §6B)
   "Is this keyword generatable?" → no → reject, show reason

2. PROMPT BUILDING (pre-generation, §2.1)
   Text model translates keyword → visual brief

3. IMAGE GENERATION (queued, §2.3)
   fal.ai generates image from visual brief

4. VISION VERIFICATION (post-generation, §6C)
   Vision model scores image against original keyword
   score ≥ 70 → approved
   score 40-69 → flagged, user decides
   score < 40 → rejected, image deleted

5. USER VERDICT (post-display, §6B.4)
   User sees image in modal → inserts or rejects
   Rejection feeds back into system
```

The keyword you set in Rank Math / Yoast is the ground truth for prompting, title/slug, and vision scoring. Alt text follows `ai_image_alt_mode` (§6.2) — keyword mode mirrors the title; descriptive mode uses a short scene description. Even if the visual brief drifts during translation, the vision model catches it by comparing the final image back to the original keyword.


## 7. Timing estimates

### 7.1 Per-image pipeline

| Step | Method | Duration | Blocking? |
|---|---|---|---|---|
| Focus keyword lookup | `get_post_meta()` | < 5 ms | No |
| Subject classification gate | `ProviderBridge::generateText()` (yes/no) | 200-400 ms | **Yes (sync)** |
| Paragraph extraction | Block-tree walk | < 10 ms | No |
| Prompt building | `ProviderBridge::generateText()` cheap model | 300-800 ms | **Yes (sync)** |
| Queue enqueue | `as_enqueue_async_action()` | < 5 ms | **Yes (sync)** |
| Image generation | `ProviderBridge::generateImage()` via fal.ai | 3-30 s | No (queued) |
| Sideload | `media_sideload_image()` | 500-2000 ms | No (queued) |
| Vision verification | `ProviderBridge::scoreImageWithVision()` | 500-1000 ms | No (queued) |
| Meta write | `update_post_meta()` × 8 | < 50 ms | No (queued) |

**User-perceived time:** ~1 second until "Generating..." appears, then 5-60 seconds of polling.

### 7.2 By model (fal.ai)

| Model | Typical time | Best for |
|---|---|---|
| `fal-ai/flux/schnell` | 2-5 s | Fast iteration, drafts |
| `fal-ai/flux-pro/v1.1` | 8-15 s | Production quality |
| `fal-ai/flux-pro/v1.1-ultra` | 15-30 s | Final, high-resolution |
| `fal-ai/recraft-v3` | 5-12 s | Illustration style, vector look |

### 7.3 Bulk timing

For 50 headings needing images, with Flux Pro at 10s average and 2 concurrent jobs:

```
50 jobs ÷ 2 concurrent × 10s = ~250 seconds (~4 minutes)
```

For 500 headings:

```
500 ÷ 2 × 10s = ~2,500 seconds (~42 minutes)
```

Concurrency is capped at the connector level so fal.ai rate limits are respected.


## 8. Bulk and automated generation

### 8.1 Triggers

| Trigger | Where | Enqueues | UI |
|---|---|---|---|
| **Modal "Generate"** | Post edit screen — one heading at a time | Single AS job | Inline in the modal |
| **Modal "Generate All"** | Post edit screen — all unmatched headings in this post | One AS job per heading | Progress bar in modal |
| **Generate Images page** | `SIM → Generate Images` admin page | Batch AS jobs for selected posts | Dedicated page with scan → confirm → progress |
| **Posts list bulk action** | `Posts → All Posts` admin table | Redirects to Generate Images page with IDs pre-filled | Shortcut |
| **On publish** (`auto_match_on_publish`) | Post publish hook | One job per unmatched heading | Silent background |
| **FIAA scheduled cron fallback** | FIAA cron | One job per post with no slug match | Already built, refactored to use `AiImageGenerator` |

### 8.2 UI — dedicated admin page

A new submenu page under the SIM menu, matching the existing pattern used by Featured Images and Bulk Processor:

```
Smart Image Matcher
  ├── Dashboard
  ├── Featured Images
  ├── Generate Images     ← NEW
  ├── Bulk Processor
  └── Settings
```

The page follows the same layout as `admin/views/featured-images.php` — settings at top, scan button, progress below.

**Step 1 — Scan:**

```
┌─────────────────────────────────────────────────────┐
│  Generate Images                                    │
│  Generate AI images for posts missing featured      │
│  images or headings without good in-content images. │
│                                                     │
│  Post type:  [Post ▼]    Status: [Published ▼]     │
│                                                     │
│  Generate for:                                      │
│    ☑ Featured images (posts with no thumbnail)      │
│    ☑ In-content images (headings with no image)     │
│                                                     │
│  Model:      [Flux Pro v1.1 ▼]                     │
│  Style:      [Photo ▼]                             │
│                                                     │
│  [Scan Posts]                                       │
└─────────────────────────────────────────────────────┘
```

**Step 2 — Results:**

```
┌─────────────────────────────────────────────────────┐
│  Scan complete                                      │
│                                                     │
│  12 posts found                                     │
│    8 missing featured images                        │
│    39 headings without in-content images            │
│                                                     │
│  Estimated: 47 images × Flux Pro ≈ $0.94           │
│  Estimated time: ~4 minutes                        │
│                                                     │
│  [Generate All 47 Images]  [Cancel]                 │
└─────────────────────────────────────────────────────┘
```

**Step 3 — Progress:**

```
┌─────────────────────────────────────────────────────┐
│  Generating images...                               │
│                                                     │
│  ████████████░░░░░░░░  23 of 47 (8 min remaining)  │
│                                                     │
│  Recent:                                            │
│  ✓ "Cost To Build A House..." — Post #42           │
│  ✓ "Why Are My Petunias Dying?" — Post #17         │
│  ⏳ "Angel Food Cake Pairings" — generating...      │
│  ✗ "iPhone 16 Pro Max Review" — skipped (product)  │
│                                                     │
│  [Pause]  [Cancel]                                  │
└─────────────────────────────────────────────────────┘
```

**Step 4 — Complete:**

```
┌─────────────────────────────────────────────────────┐
│  Generation complete                                │
│                                                     │
│  43 generated  ·  4 skipped  ·  0 failed            │
│                                                     │
│  [Review in Media Library]  [Run Again]             │
└─────────────────────────────────────────────────────┘
```

**How posts are selected:**

The user selects post type + status + filter, clicks "Scan Posts." The scan runs synchronously — it queries posts, counts unmatched headings per post, and returns the total. The user reviews the estimate before committing to the credit spend. No images are generated until they click "Generate All."

This is the same two-step pattern as the Featured Images Match Runner: scan first, commit second.

### 8.3 Shortcut from the post list

A secondary trigger: a bulk action on the Posts list table.

```
Posts → All Posts
  ┌──────────────────────────┐
  │ ☑ Post #1                │
  │ ☑ Post #2                │
  │ ☑ Post #3                │
  │                          │
  │ Bulk actions:            │
  │ [Generate Images for     │
  │  Selected Posts ▼]       │
  │            [Apply]       │
  └──────────────────────────┘
```

This redirects to the Generate Images page with the selected post IDs pre-filled, skipping the post-type/status selection step and going straight to the scan confirmation. A convenience shortcut, not a replacement for the full page.

### 8.4 Concurrency and rate control

```php
// Constants in the bulk config:
'max_concurrent_jobs' => 2,        // How many AS jobs run simultaneously
'max_jobs_per_minute' => 6,        // fal.ai rate-limit compliance
'rate_limit_pause_seconds' => 10,  // Pause between batches if rate-limited
```

The `JobRunner::runAiImageGenJob()` checks a transient `smart_image_matcher_img_gen_rate` before each call. If the counter exceeds the limit, the job requeues itself with a 10-second delay via `as_schedule_single_action( time() + 10, ... )`.

### 8.5 Cost-awareness

The bulk UI shows an estimate before the user commits:

```
Found: 47 headings with no image matches across 12 posts
Estimated credits: 47 images × Flux Pro = ~$0.94
Estimated time: ~4 min
[Generate All] [Cancel]
```

Credit costs are read from the WP Connector (if the provider exposes pricing metadata) or from a user-configured field in Settings.

### 8.6 Auto-match on publish integration

When `auto_match_on_publish` is enabled (existing premium feature), and a post is published:

1. Keyword matching runs first (existing)
2. AI matching runs for high-confidence headings (existing, if enabled)
3. **New:** For any heading still unmatched after steps 1-2, `AiImageGenerator::generateForHeading()` is enqueued for each
4. Images are generated silently in the background
5. The editor sees a "X images are being generated for this post" admin notice
6. When complete, an email or admin notice confirms: "3 images generated and attached to your post 'Why Are My Petunias Dying?'"

## 9. Build order

**Prerequisite (separate repo — build this first, then return to this plan):**

| Step | File(s) | Effort | Depends on |
|---|---|---|---|
| 0. `ai-provider-for-fal-ai` (standalone plugin) | Separate plugin: `wp-content/plugins/ai-provider-for-fal-ai/` | Large | WP 7.0+ AI Client / Connectors |

Until step 0 is installed, activated, and a fal (or other image) provider is configured in Settings → Connectors, `ProviderBridge::generateImage()` returns `WP_Error`. SIM work below can be stubbed/mocked against the bridge, but end-to-end generation requires an image provider.

**SIM implementation (after connector is usable):**

| Step | File(s) | Effort | Depends on |
|---|---|---|---|
| 1. `PromptBuilder` | `src/AI/PromptBuilder.php` | Small | — |
| 2. `AiImageGenerator` (sideload + metadata + caching + alt modes) | `src/Premium/AiImageGenerator.php` | Medium | Steps 0, 1 |
| 3. Queue hook + enqueue + JobRunner + rate limiting | `src/Queue/Queue.php`, `src/Queue/JobRunner.php` | Small | Step 2 |
| 4. REST routes (generate + status polling) | `src/REST/MatchController.php` or new `ImageGenController` | Small | Step 3 |
| 5. Settings + Sanitizer + feature flag (incl. `ai_image_alt_mode`) | `src/Settings/Settings.php`, `src/Settings/Sanitizer.php`, `src/Premium.php`, `src/Plugin.php` | Small | — |
| 6. Deactivation + cleanup | `src/Plugin.php` | Tiny | Step 3 |
| 7. Paragraph context extraction in REST response | `src/REST/MatchController.php` | Small | — |
| 8. Focus keyword detection helper | `src/AI/PromptBuilder.php` (static) | Tiny | — |
| 9. Frontend modal UI (Generate button + polling) | `admin/js/src/modal.js`, `admin/css/sim-modal.css` | Medium | Steps 4, 5 |
| 10. Refactor `AiFeaturedImage` to use `AiImageGenerator` | `src/Premium/AiFeaturedImage.php` | Small | Step 2 |
| 11. Generate Images admin page + view | `src/Settings/Settings.php` (menu), `admin/views/generate-images.php`, `admin/js/src/generate-images.js` | Medium | Steps 2, 3 |
| 12. `auto_match_on_publish` integration | `src/Premium/AutoMatchOnPublish.php` | Small | Step 2 |
