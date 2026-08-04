# SIM AI Model Preferences Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let SIM pick a preferred fal image model in settings, and route generation through Connectors/AI Client with a closed preference list (Seedream default) — never open-ended “first suitable.”

**Architecture:** Connectors store the fal API key only. SIM stores `ai_image_model`. `ProviderBridge::generateImage()` calls `using_model_preference( … )` with the preferred ID first, then the other three curated IDs. The standalone `ai-provider-for-fal-ai` plugin registers those four model IDs so the Client can resolve them. No direct fal HTTP from SIM.

**Tech Stack:** WordPress 7.0 `wp_ai_client_prompt()` / `WP_AI_Client_Prompt_Builder` (snake_case), PHP AI Client, fal provider plugin, SIM `sim_settings` option.

**Spec:** `docs/superpowers/specs/2026-08-04-sim-ai-model-preferences-design.md`

## Global Constraints

- SIM never calls fal HTTP directly; always `ProviderBridge` → `wp_ai_client_prompt()`.
- Preference list is **only** these four IDs (plus optional `sim_ai_image_model_preferences` filter).
- Default preferred model: `bytedance/seedream/v5/pro/text-to-image`.
- fal plugin changelog/version are independent of SIM; do not document fal releases in SIM `CHANGELOG.md`.
- WP Coding Standards: tabs, Yoda, `array()`, i18n text domain `smart-image-matcher` / `ai-provider-for-fal-ai`.
- Prefer WP snake_case on the prompt builder: `with_text`, `using_model_preference`, `generate_image`, `is_supported_for_image_generation`.
- User rule: do not git commit unless the user asks (omit commit steps unless committing is explicitly requested mid-run).

---

## File map

| File | Responsibility |
|------|----------------|
| `src/AI/ImageModelCatalog.php` (create) | Allow-list, labels, default ID, preference-list builder |
| `src/AI/ProviderBridge.php` | Apply preferences; image availability probe |
| `src/Settings/Settings.php` | Default + field + renderer |
| `src/Settings/Sanitizer.php` | Sanitize `ai_image_model` |
| `src/Plugin.php` | Localize preferred model / image-ready flag if useful |
| `src/REST/MatchController.php` / `ImageGenController.php` | Gate on image generation availability |
| `admin/js/src/modal.js` | Use localized image-ready feature flag |
| `CHANGELOG.md`, `readme.txt`, `smart-image-matcher.php`, `README.md` | SIM version notes |
| `../ai-provider-for-fal-ai/src/Metadata/FalModelMetadataDirectory.php` | Register four models |
| `../ai-provider-for-fal-ai/src/Models/FalImageGenerationModel.php` | Safe param mapping across schemas |
| `../ai-provider-for-fal-ai/CHANGELOG.md`, `readme.txt`, main plugin file | fal 1.1.0 |

---

### Task 1: SIM `ImageModelCatalog`

**Files:**
- Create: `src/AI/ImageModelCatalog.php`
- Test: manual / PHP lint (`php -l`)

**Interfaces:**
- Produces: `ImageModelCatalog::DEFAULT_MODEL_ID : string`, `::allowedIds() : string[]`, `::choices() : array<string,string>`, `::isAllowed( string $id ) : bool`, `::preferenceList( string $preferred ) : string[]`

- [ ] **Step 1: Add catalog class**

```php
<?php
/**
 * Curated fal image models selectable in SIM settings.
 *
 * @package SmartImageMatcher\AI
 * @since   3.1.1
 */

declare( strict_types=1 );

namespace SmartImageMatcher\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImageModelCatalog
 *
 * @since 3.1.1
 */
class ImageModelCatalog {

	const DEFAULT_MODEL_ID = 'bytedance/seedream/v5/pro/text-to-image';

	/**
	 * Stable fallback order (preferred is moved to front by preferenceList()).
	 *
	 * @since 3.1.1
	 * @return list<string>
	 */
	public static function fallbackOrder(): array {
		return array(
			'bytedance/seedream/v5/pro/text-to-image',
			'fal-ai/nano-banana-2',
			'fal-ai/nano-banana-pro',
			'openai/gpt-image-2',
		);
	}

	/**
	 * @since 3.1.1
	 * @return list<string>
	 */
	public static function allowedIds(): array {
		return self::fallbackOrder();
	}

	/**
	 * @since 3.1.1
	 * @return array<string, string> model_id => label
	 */
	public static function choices(): array {
		return array(
			'bytedance/seedream/v5/pro/text-to-image' => __( 'Seedream 5.0 Pro', 'smart-image-matcher' ),
			'openai/gpt-image-2'                      => __( 'GPT Image 2', 'smart-image-matcher' ),
			'fal-ai/nano-banana-pro'                  => __( 'Nano Banana Pro', 'smart-image-matcher' ),
			'fal-ai/nano-banana-2'                    => __( 'Nano Banana 2', 'smart-image-matcher' ),
		);
	}

	/**
	 * @since 3.1.1
	 * @param string $id Model ID.
	 * @return bool
	 */
	public static function isAllowed( string $id ): bool {
		return in_array( $id, self::allowedIds(), true );
	}

	/**
	 * Preferred first, then remaining fallback IDs (no duplicates).
	 *
	 * @since 3.1.1
	 * @param string $preferred Preferred model ID.
	 * @return list<string>
	 */
	public static function preferenceList( string $preferred ): array {
		if ( ! self::isAllowed( $preferred ) ) {
			$preferred = self::DEFAULT_MODEL_ID;
		}

		$list = array( $preferred );
		foreach ( self::fallbackOrder() as $id ) {
			if ( $id !== $preferred ) {
				$list[] = $id;
			}
		}

		/**
		 * Filters the ordered image model preference list for ProviderBridge.
		 *
		 * @since 3.1.1
		 * @param list<string> $list      Preference list.
		 * @param string       $preferred Preferred model ID.
		 */
		$filtered = apply_filters( 'sim_ai_image_model_preferences', $list, $preferred );

		if ( ! is_array( $filtered ) || empty( $filtered ) ) {
			return $list;
		}

		$out = array();
		foreach ( $filtered as $id ) {
			$id = is_string( $id ) ? $id : '';
			if ( '' !== $id && self::isAllowed( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}

		return ! empty( $out ) ? $out : $list;
	}
}
```

- [ ] **Step 2: Lint**

Run: `php -l src/AI/ImageModelCatalog.php`  
Expected: `No syntax errors detected`

---

### Task 2: Settings + sanitizer for `ai_image_model`

**Files:**
- Modify: `src/Settings/Settings.php` (defaults ~line 82, AI fields ~363, new renderer)
- Modify: `src/Settings/Sanitizer.php` (~line 94)

**Interfaces:**
- Consumes: `ImageModelCatalog::DEFAULT_MODEL_ID`, `::choices()`, `::isAllowed()`
- Produces: `Settings::get( 'ai_image_model' )` returns allowed string

- [ ] **Step 1: Add default**

In `Settings::$defaults` add:

```php
'ai_image_model' => \SmartImageMatcher\AI\ImageModelCatalog::DEFAULT_MODEL_ID,
```

(Or the literal string `'bytedance/seedream/v5/pro/text-to-image'` if class load order is a concern — prefer catalog constant.)

- [ ] **Step 2: Register field** after on-demand generation field:

```php
$this->addField(
	'smart_image_matcher_ai',
	'ai_image_model',
	__( 'Preferred image model', 'smart-image-matcher' ),
	'renderAiImageModelSelect'
);
```

- [ ] **Step 3: Renderer**

```php
public function renderAiImageModelSelect( array $args ): void {
	$key   = $args['key'];
	$value = (string) self::get( $key );
	if ( ! \SmartImageMatcher\AI\ImageModelCatalog::isAllowed( $value ) ) {
		$value = \SmartImageMatcher\AI\ImageModelCatalog::DEFAULT_MODEL_ID;
	}
	$name = self::OPTION . '[' . $key . ']';
	echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( 'smart_image_matcher_' . $key ) . '">';
	foreach ( \SmartImageMatcher\AI\ImageModelCatalog::choices() as $id => $label ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $id ),
			selected( $value, $id, false ),
			esc_html( $label )
		);
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__(
		'Used for on-demand Generate and AI featured-image fallback. Requires fal.ai connected under Settings → Connectors. Visual briefs still need a separate text provider.',
		'smart-image-matcher'
	) . '</p>';
}
```

- [ ] **Step 4: Sanitize**

```php
'ai_image_model' => ( static function ( $raw_val ) {
	$id = sanitize_text_field( wp_unslash( (string) ( $raw_val ?? '' ) ) );
	return \SmartImageMatcher\AI\ImageModelCatalog::isAllowed( $id )
		? $id
		: \SmartImageMatcher\AI\ImageModelCatalog::DEFAULT_MODEL_ID;
} )( $raw['ai_image_model'] ?? '' ),
```

Or inline without IIFE — read `$id` then ternary into the returned array.

- [ ] **Step 5: Lint Settings + Sanitizer**

---

### Task 3: ProviderBridge preferences + image availability

**Files:**
- Modify: `src/AI/ProviderBridge.php`

**Interfaces:**
- Consumes: `ImageModelCatalog::preferenceList()`, `Settings::get( 'ai_image_model' )`
- Produces: `isImageGenerationAvailable(): bool`, `generateImage()` uses closed preferences

- [ ] **Step 1: Add `isImageGenerationAvailable()`**

```php
public static function isImageGenerationAvailable(): bool {
	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return false;
	}

	$wp_ai_client_prompt = 'wp_ai_client_prompt';
	$probe               = $wp_ai_client_prompt();

	if ( is_wp_error( $probe ) ) {
		return false;
	}

	try {
		$prefs = \SmartImageMatcher\AI\ImageModelCatalog::preferenceList(
			(string) \SmartImageMatcher\Settings\Settings::get( 'ai_image_model' )
		);

		$builder = $probe->with_text( 'x' );
		if ( is_callable( array( $builder, 'using_model_preference' ) ) || method_exists( $builder, '__call' ) ) {
			$builder = $builder->using_model_preference( ...$prefs );
		}

		if ( method_exists( $builder, 'is_supported_for_image_generation' )
			|| ( method_exists( $builder, '__call' ) )
		) {
			return (bool) $builder->is_supported_for_image_generation();
		}

		return (bool) $builder->is_supported();
	} catch ( \Throwable $e ) {
		Logger::warn( 'ProviderBridge::isImageGenerationAvailable() threw', array( 'error' => $e->getMessage() ) );
		return false;
	}
}
```

Keep `isAvailable()` for text features (PromptBuilder, AI match). Do not redefine text availability as image.

- [ ] **Step 2: Update `generateImage()` to prefer curated models**

Replace the generate body so it uses snake_case WP API and preferences:

```php
public static function generateImage( string $prompt ) {
	if ( ! self::isImageGenerationAvailable() ) {
		return new \WP_Error(
			'smart_image_matcher_ai_image_unavailable',
			__( 'No image-capable AI provider configured for the preferred models. Connect fal.ai under Settings → Connectors.', 'smart-image-matcher' )
		);
	}

	try {
		$wp_ai_client_prompt = 'wp_ai_client_prompt';
		$builder             = $wp_ai_client_prompt();

		if ( is_wp_error( $builder ) ) {
			return $builder;
		}

		$prefs = \SmartImageMatcher\AI\ImageModelCatalog::preferenceList(
			(string) \SmartImageMatcher\Settings\Settings::get( 'ai_image_model' )
		);

		$result = $builder
			->with_text( $prompt )
			->using_model_preference( ...$prefs )
			->generate_image();

		if ( is_wp_error( $result ) ) {
			Logger::warn( 'ProviderBridge::generateImage() error', array( 'error' => $result->get_error_message() ) );
			return $result;
		}

		return $result;
	} catch ( \Throwable $e ) {
		Logger::error( 'ProviderBridge::generateImage() exception', array( 'error' => $e->getMessage() ) );
		return new \WP_Error( 'smart_image_matcher_ai_exception', $e->getMessage() );
	}
}
```

- [ ] **Step 3: Align `generateText()` to snake_case** (`with_system_message`, `with_text`, `using_temperature`, `generate_text`) for consistency with WP 7.0 builder — keep behavior identical.

- [ ] **Step 4: Lint `ProviderBridge.php`**

---

### Task 4: Wire Generate gating to image availability

**Files:**
- Modify: `src/Plugin.php` (localize `features.aiImageGeneration`)
- Modify: `src/REST/MatchController.php` (`features.ai_image_generation`)
- Modify: `src/REST/ImageGenController.php` (enqueue check)
- Modify: `src/Premium/AiImageGenerator.php` (early check — use `isImageGenerationAvailable()` instead of/in addition to `isAvailable()` for image path; keep text checks for PromptBuilder via `isAvailable()`)

**Interfaces:**
- Consumes: `ProviderBridge::isImageGenerationAvailable()`
- Produces: Generate UI/REST only when setting on **and** image path ready

- [ ] **Step 1: Localize**

```php
'aiImageGeneration' => (bool) Settings::get( 'ai_image_generation_enabled' )
	&& \SmartImageMatcher\AI\ProviderBridge::isImageGenerationAvailable(),
```

- [ ] **Step 2: MatchController features** — same boolean with `isImageGenerationAvailable()`.

- [ ] **Step 3: ImageGenController** — replace `ProviderBridge::isAvailable()` with `isImageGenerationAvailable()` for the image enqueue gate. PromptBuilder inside the job still needs text; if text fails, existing job error path applies.

- [ ] **Step 4: AiImageGenerator::generateForHeading** — gate image provider with `isImageGenerationAvailable()`; PromptBuilder calls continue to use text `isAvailable()` indirectly via `generateText()`.

---

### Task 5: fal provider — register the four models

**Files:**
- Modify: `C:/Users/kings/Local Sites/yenimi/app/public/wp-content/plugins/ai-provider-for-fal-ai/src/Metadata/FalModelMetadataDirectory.php`
- Modify: fal `CHANGELOG.md`, `readme.txt`, main plugin header / version constant → **1.1.0**
- Date: use `Get-Date -Format 'dd/MM/yyyy'`

**Interfaces:**
- Produces: `hasModelMetadata()` true for all four SIM IDs

- [ ] **Step 1: Replace/extend `$definitions` in `buildCatalog()`**

Include at minimum (keep Flux/Recraft only if you want other consumers; SIM will not prefer them):

```php
$definitions = array(
	array( 'bytedance/seedream/v5/pro/text-to-image', 'Seedream 5.0 Pro' ),
	array( 'openai/gpt-image-2', 'GPT Image 2' ),
	array( 'fal-ai/nano-banana-pro', 'Nano Banana Pro' ),
	array( 'fal-ai/nano-banana-2', 'Nano Banana 2' ),
	// Optional legacy:
	// array( 'fal-ai/flux/schnell', 'Flux Schnell' ),
	...
);
```

Per spec: SIM closed list is the four above. Prefer **registering the four as primary**; retaining Flux is optional for non-SIM consumers.

- [ ] **Step 2: Bump fal to 1.1.0** with changelog entry: added Seedream / GPT Image 2 / Nano Banana models to catalog.

- [ ] **Step 3: Lint fal metadata file**

---

### Task 6: fal provider — request body compatibility

**Files:**
- Modify: `.../ai-provider-for-fal-ai/src/Models/FalImageGenerationModel.php`

**Interfaces:**
- Consumes: model metadata ID
- Produces: request JSON with `prompt` always; model-safe optional size fields

- [ ] **Step 1: Soften `prepareGenerateImageParams`**

Some endpoints reject Flux-style `image_size` enums. Strategy:

```php
protected function prepareGenerateImageParams( array $prompt ): array {
	$config = $this->getConfig();
	$model_id = $this->metadata()->getId();

	$params = array(
		'prompt'     => $this->preparePromptParam( $prompt ),
		'num_images' => 1,
	);

	$candidate_count = $config->getCandidateCount();
	if ( null !== $candidate_count && $candidate_count > 0 ) {
		$params['num_images'] = $candidate_count;
	}

	// Only attach image_size when the model is known to accept the shared mapping.
	if ( $this->supportsSharedImageSizeParam( $model_id ) ) {
		$image_size = $this->prepareImageSizeParam(
			$config->getOutputMediaOrientation(),
			$config->getOutputMediaAspectRatio()
		);
		if ( null !== $image_size ) {
			$params['image_size'] = $image_size;
		}
	}

	// custom_options merge (existing logic)...
	return $params;
}

private function supportsSharedImageSizeParam( string $model_id ): bool {
	// GPT Image 2 documents image_size; Seedream uses image_size variants.
	// Nano Banana may use aspect_ratio — omit shared image_size for those if unsure.
	$with_image_size = array(
		'openai/gpt-image-2',
		'bytedance/seedream/v5/pro/text-to-image',
	);
	return in_array( $model_id, $with_image_size, true )
		|| 0 === strpos( $model_id, 'fal-ai/flux' );
}
```

Verify against fal docs during implementation: if Nano Banana accepts only `aspect_ratio`, map orientation → aspect_ratio for those IDs instead of omitting.

- [ ] **Step 2: Manual smoke** — with `FAL_KEY` set, call `wp_ai_client_prompt()->with_text('test')->using_model_preference('bytedance/seedream/v5/pro/text-to-image')->generate_image()` from WP-CLI or a throwaway admin tool if available.

---

### Task 7: SIM version / changelog / docs touch-up

**Files:**
- Modify: `CHANGELOG.md`, `readme.txt`, `smart-image-matcher.php`, `README.md` (already on 3.1.1 for on-demand — extend the 3.1.1 entry)

- [ ] **Step 1: Extend `[3.1.1]` Added section** with preferred image model setting + closed `using_model_preference` list (Seedream default). Do **not** mention fal plugin version bumps in SIM changelog.

- [ ] **Step 2: Confirm header / constant / Stable tag still agree at 3.1.1** (or bump to 3.1.2 only if 3.1.1 was already released — currently WIP on feature branch → keep 3.1.1).

---

### Task 8: End-to-end verification

- [ ] **Step 1:** Activate fal provider + text provider; save fal key in Connectors.
- [ ] **Step 2:** SIM Settings → Preferred image model = Seedream; enable on-demand gen; save.
- [ ] **Step 3:** Open modal on unmatched heading → Generate → job completes; attachment meta / logs show Seedream path when debug on.
- [ ] **Step 4:** Switch to Nano Banana 2; Regenerate with force → different model preference applied.
- [ ] **Step 5:** Deactivate fal provider → Generate hidden / REST 503; no fatal.
- [ ] **Step 6:** `php -l` on all touched SIM + fal PHP files.

---

## Spec coverage self-check

| Spec requirement | Task |
|------------------|------|
| Connectors = credentials only | Tasks 3–5 (no SIM HTTP) |
| SIM dropdown four models | Task 2 |
| Default Seedream | Tasks 1–2 |
| Closed preference list | Tasks 1, 3 |
| fal catalog four IDs | Task 5 |
| Request schema safety | Task 6 |
| Image availability gate | Tasks 3–4 |
| Separate SIM/fal changelogs | Task 7 + Task 5 |
| Text model setting follow-up | Explicitly out of this plan (document in settings help text Task 2) |

## Placeholder scan

None intentional. Nano Banana aspect_ratio mapping refined in Task 6 against live fal docs during implementation.
