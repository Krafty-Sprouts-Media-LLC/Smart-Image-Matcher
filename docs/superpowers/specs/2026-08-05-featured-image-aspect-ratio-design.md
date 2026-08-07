# Featured image aspect ratio (API) — Design

**Date:** 2026-08-05 (updated 2026-08-07)  
**Status:** Shipped 3.2.15 aspect; 3.2.16 Seedream cheap size  
**Version target:** 3.2.16 + fal provider 1.1.7

## Decision

| Surface | Aspect policy |
|---|---|
| Featured (`heading_hash === featured`) | Force **16:9** via WP AI Client `as_output_media_aspect_ratio( '16:9' )` |
| Under-heading (modal Generate) | Leave model default (`auto_2K` / no aspect set) |

SIM expresses one policy; fal provider maps per model.

### Seedream cost size (fal 1.1.7 / SIM 3.2.16)

For Seedream + 16:9 / landscape, fal provider sends:

`image_size: { "width": 2048, "height": 1152 }`

Pixel area = 2,359,296 (= 1536²) → documented **$0.0675** Seedream tier (vs $0.135 above that area).

Nano Banana still uses `aspect_ratio: 16:9` only.

## Later — per-model featured size/quality (not shipped)

Logged 2026-08-07 for a follow-up after Seedream cheap-tier is validated in production.

| Model | Featured request to add | Notes |
|---|---|---|
| **GPT Image 2** (`openai/gpt-image-2`) | `image_size: { width: 1920, height: 1080 }`, `quality: "medium"` | Dims multiples of 16; default quality is **high** (expensive) if unset |
| **Nano Banana 2** (`fal-ai/nano-banana-2`) | keep `aspect_ratio: "16:9"`, set `resolution: "1K"` | Skip `enable_web_search` / `thinking_level` for cost |
| **Nano Banana Pro** (`fal-ai/nano-banana-pro`) | keep `aspect_ratio: "16:9"`, set `resolution: "1K"` (or `"2K"` if quality needs it) | Same price band for 1K/2K per fal docs at time of note |

Implementation home: mostly `ai-provider-for-fal-ai` `prepareGenerateImageParams()` / size mapping; SIM keeps sending `as_output_media_aspect_ratio( '16:9' )` for featured only.

## Cost visibility

fal generate responses do **not** include dollar amounts. Use:

1. [fal.ai dashboard](https://fal.ai/dashboard) → Usage / Billing  
2. Attachment meta `_sim_generated_width` / `_sim_generated_height` / `_sim_generated_cost_hint` (Seedream-tier estimate only)  
3. Optional fal usage/billing API with your API key (outside SIM)

## Non-goals

- Per-model size UI in SIM settings (until the “Later” table ships, if ever)
- Forcing aspect on heading images
- Logging exact $ from the generate response (not available)
