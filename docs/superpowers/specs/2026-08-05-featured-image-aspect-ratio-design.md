# Featured image aspect ratio (API) — Design

**Date:** 2026-08-05  
**Status:** Approved (user: proceed)  
**Version target:** 3.2.15

## Decision

| Surface | Aspect policy |
|---|---|
| Featured (`heading_hash === featured`) | Force **16:9** via WP AI Client `as_output_media_aspect_ratio( '16:9' )` |
| Under-heading (modal Generate) | Leave model default (`auto_2K` / no aspect set) |

SIM expresses one policy; fal provider maps per model (`image_size: landscape_16_9` on Seedream/GPT Image/Flux; `aspect_ratio: 16:9` on Nano Banana).

## Non-goals

- Per-model size UI in SIM settings
- Forcing aspect on heading images
- Prompt-only orientation lock (kept as soft featured wording only)

## Implementation

1. `ProviderBridge::generateImage( $prompt, $purpose = 'heading' )` — when `$purpose === 'featured'`, call `as_output_media_aspect_ratio( '16:9' )` before generate.
2. `AiImageGenerator` passes `featured` vs `heading` purpose into `generateImage`.
3. Soft prompt hint for featured already says “wide editorial”; optional suffix nudge OK but API is the real lock.

## Verification

- Featured generate → attachment ~16:9 landscape (not portrait).
- Heading generate → unchanged (may still vary).
