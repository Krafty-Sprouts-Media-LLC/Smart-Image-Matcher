# Design: SIM-owned AI model preferences (Connectors for credentials only)

**Date:** 2026-08-04  
**Status:** Draft for review  
**Branch:** `feature/on-demand-image-generation`  
**Related:** On-demand image generation (SIM 3.1.1 work-in-progress)

---

## Problem

Smart Image Matcher currently calls `wp_ai_client_prompt()->generateText()` / `generateImage()` with **no model preference**. The AI Client then uses the first suitable model among all configured providers. That is unpredictable for cost, quality, and billing.

WordPress Connectors (**Settings → Connectors**) only manage provider credentials. Model choice belongs in the consumer plugin (SIM), via `using_model_preference()` (or equivalent) on the AI Client builder.

## Goals

1. **Connectors** = API keys / connection status only (fal, OpenAI, etc.).
2. **SIM** = which image model to use for on-demand generation (and later text models for PromptBuilder).
3. Never rely on open-ended “first suitable model in the world.”
4. Keep the call path provider-agnostic: SIM → `ProviderBridge` → `wp_ai_client_prompt()` → AI Client → Connectors credentials → fal provider plugin → fal HTTP. **No direct fal HTTP from SIM.**

## Non-goals (this change)

- Bulk Generate Images admin page.
- SIM talking to fal.ai HTTP directly.
- Dynamic discovery of every fal gallery model in the SIM UI (v1 uses a curated list).
- Replacing text-provider Connectors (PromptBuilder still needs a text-capable connector).

---

## Architecture

```
[Settings → Connectors]     fal API key (FAL_KEY)
[SIM Settings]              preferred image model ID (+ existing gen toggles)
        │
        ▼
ProviderBridge::generateImage( $prompt )
  → wp_ai_client_prompt()
  → using_model_preference( preferred, …other curated IDs… )
  → generateImage()
        │
        ▼
AiClient → FalProvider (ai-provider-for-fal-ai)
  → POST https://fal.run/{model_id}
```

PromptBuilder / subject gate / descriptive alt continue to use `generateText()` through the same bridge (text preferences are a follow-up; see below).

---

## Image models in SIM (curated)

| SIM label | fal model ID | Role |
|-----------|--------------|------|
| Seedream 5.0 Pro | `bytedance/seedream/v5/pro/text-to-image` | **Default** |
| GPT Image 2 | `openai/gpt-image-2` | Typography / detail |
| Nano Banana Pro | `fal-ai/nano-banana-pro` | Higher-quality Gemini image |
| Nano Banana 2 | `fal-ai/nano-banana-2` | Faster / Flash-class |

Setting key: `ai_image_model` (string, one of the four IDs above).  
Default: `bytedance/seedream/v5/pro/text-to-image`.

UI: select under **Settings → Smart Image Matcher → AI Features**, next to on-demand generation / subject gate / alt mode.

### Preference resolution (hybrid, closed set)

When generating an image:

1. Build preference list = `[ $preferred, …other three IDs in stable order… ]`.
2. Pass that list to `using_model_preference( … )` (or the Client’s camelCase equivalent).
3. **Do not** append arbitrary other registry models (no Flux/Recraft unless we later add them to this curated SIM list).
4. If **none** of the four can run (fal not connected, catalog missing IDs, or Client returns unsupported): return a clear `WP_Error` and surface it in the modal / job status. Do not silently pick an unrelated model.

Stable fallback order after the user’s preferred model (skipping the preferred so it isn’t duplicated):

1. `bytedance/seedream/v5/pro/text-to-image`
2. `fal-ai/nano-banana-2`
3. `fal-ai/nano-banana-pro`
4. `openai/gpt-image-2`

Example: user picks Nano Banana Pro → preference list  
`[ fal-ai/nano-banana-pro, bytedance/seedream/v5/pro/text-to-image, fal-ai/nano-banana-2, openai/gpt-image-2 ]`.

Optional: `apply_filters( 'sim_ai_image_model_preferences', $list, $preferred )` for advanced sites — filter must not be required for normal use.

---

## fal connector plugin changes

Standalone plugin path: `wp-content/plugins/ai-provider-for-fal-ai/` (not inside SIM).

`FalModelMetadataDirectory` currently catalogs Flux / Recraft only. **Those four SIM models must be registered** in the fal provider catalog (image-generation capability) so the AI Client can resolve `using_model_preference` to `FalImageGenerationModel` and `POST https://fal.run/{model_id}`.

- Keep existing Flux/Recraft entries only if still useful for other consumers; SIM will not prefer them unless added to the SIM curated list later.
- Request-body mapping in `FalImageGenerationModel` must tolerate schema differences across endpoints (`prompt` is common; `image_size` / aspect / quality may differ). Prefer a minimal shared mapping (prompt + safe defaults) with per-model overrides where required so Seedream / GPT Image 2 / Nano Banana do not break on unknown params.
- Bump fal plugin version/changelog independently — **never** put fal packaging changes in SIM’s CHANGELOG.

---

## SIM code changes

| Area | Change |
|------|--------|
| `Settings` / `Sanitizer` | Add `ai_image_model` default + sanitize against allow-list |
| Settings UI | Select renderer for the four labels |
| `ProviderBridge::generateImage()` | Apply closed preference list from settings |
| `ProviderBridge::isAvailable()` / image readiness | Prefer a dedicated `isImageGenerationAvailable()` (or stronger probe) so Generate UI does not show when only text is connected |
| Modal / REST | Keep gating on setting + image-capable path; errors from preference miss are user-visible |
| Version | Fold into current on-demand work (3.1.1) or bump patch if already tagged |

`PromptBuilder` and `AiImageGenerator` keep calling the bridge; they do not hardcode fal IDs.

---

## Text models (follow-up, same product idea)

PromptBuilder needs a **text** connector (OpenAI, etc.). A SIM **Preferred text model** setting (curated list + `using_model_preference`) should follow the same pattern. Out of scope for the first implementation slice unless it lands in the same PR as a small companion setting with sensible cheap defaults for briefs / subject gate / alt.

Until then: document in settings help that on-demand generation needs (1) fal connected for images and (2) a text provider for visual briefs.

---

## Error handling

| Condition | Behavior |
|-----------|----------|
| On-demand setting off | REST 400; no Generate button |
| No fal / no image-capable Client path | Generate hidden or disabled; REST 503 with Connectors link |
| Preferred model missing but another curated ID works | Client uses next in preference list (silent fallback within the four) |
| None of the four available | Job status `failed` + clear message |
| fal HTTP / sideload failure | Existing generator error path |

---

## Testing

1. Connect fal only + text provider; set Seedream default; Generate → image via Seedream ID.
2. Switch SIM setting to Nano Banana 2; Generate → that model ID in logs / attachment meta if recorded.
3. Disconnect fal; Generate must not succeed via an unrelated provider model outside the four.
4. fal plugin inactive; SIM shows unavailable, no fatals.
5. Confirm SIM changelog has no fal-provider release notes; fal plugin has its own.

---

## Decisions log

| # | Decision |
|---|----------|
| 1 | Model selection lives in **SIM settings**, not Connectors UI |
| 2 | Hybrid: curated list + fallback **only among the four** |
| 3 | Default image model = **Seedream 5.0 Pro** |
| 4 | Never bypass Connectors / AI Client for fal HTTP |
| 5 | fal plugin catalog must include the four IDs |
