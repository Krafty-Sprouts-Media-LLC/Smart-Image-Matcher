# SIM Image Generation — Remaining Surfaces Design

**Date:** 2026-08-05  
**Status:** Approved (pending file review)  
**Parent plan:** `docs/on-demand-image-generation-plan.md`  
**Builds on:** Working single-heading Generate (3.1.x), fal provider, preferred models, WP AI featured-image compat

---

## Decisions (locked)

| Decision | Choice |
|---|---|
| Build order | User-facing first: Modal Generate All → Bulk admin page → Posts list bulk → Styles → Vision → Rejection → Auto-publish |
| Spend confirmation | Hard confirm: scan → count + estimate → explicit Generate All |
| Vision verification | Off by default; optional setting |
| Auto on publish | Featured image only (toggleable); in-content heading auto-gen deferred |
| Bulk admin / posts list | Featured image only (posts missing thumbnail); heading gen stays modal-only |
| Architecture | Thin wrappers on existing `AiImageGenerator` + Action Scheduler (`HOOK_AI_IMAGE_GEN`); no parent mega-job that loops inside one PHP request |

---

## Non-goals

- SIM talking to fal HTTP directly (still via AI Client / Connectors).
- Auto-generating in-content images on publish (later).
- Infographic style (needs structured data; deferred with original plan).
- Changing fal provider packaging inside SIM zip.

---

## Shared pipeline

All new triggers enqueue the same job shape already used by modal Generate:

1. Subject gate (if enabled)  
2. `PromptBuilder` visual brief (+ style hint)  
3. `ProviderBridge` image gen (preferred fal model)  
4. Sideload + `_sim_generated*` meta (keyword title/slug rules)  
5. Optional vision gate (if enabled)  
6. Upsert `wp_sim_matches` / return to UI  

Hard confirm always happens **before** enqueue. Estimates use: image count × typical model latency band (and optional rough credit note if we keep it qualitative). Modal Generate All estimates by heading count; bulk admin estimates by posts missing a featured image (1:1).

---

## 1. Modal Generate All

**Where:** Smart Image Matcher modal (post edit).

**Flow:**

1. Button visible when ≥1 heading is unmatched or weak (same threshold as single Generate).  
2. Click → client lists eligible headings (hash, text) + estimate (N images, ~time).  
3. Confirm → `POST` batch enqueue (one AS job per heading, existing generate-image path or thin batch wrapper).  
4. Modal shows aggregate progress (done / failed / total); each row can still poll individually.  
5. Completed images appear as match candidates (existing UI).

**Reject / Regenerate:** unchanged per heading; rejection learning (section 6) applies.

---

## 2. Generate Featured Images admin page

**Where:** SIM submenu (alongside Featured Images / Bulk Processor).

**Scope:** Featured images only. Does **not** scan or generate in-content heading images (those stay in the modal, §1).

**Flow (mirror Featured Images UX):**

1. Filters: post type, status, max posts (or prefilled `post_ids` from list bulk).  
2. **Scan** (no generation) → posts without a featured image (+ skip if generated featured already exists / rejection blocked) + total N + estimate.  
3. **Generate Featured Images** (confirm) → enqueue one AS job per post with `heading_hash = featured`; progress panel polls status.  
4. Cancel stops further enqueues / marks pending cancelled where AS allows.

**Cost UI:** Show N featured images and approximate duration before confirm. No silent start.

---

## 3. Posts list bulk action

**Where:** `edit.php` bulk actions for supported post types.

**Flow:**

1. User selects posts → bulk action “Generate featured images…”.  
2. Redirect to Generate Featured Images admin page with `post_ids` pre-filled.  
3. Same scan → estimate → confirm as §2 (no generation on the list table itself).

---

## 4. Image styles

**Styles in v1:** `photo` (default), `illustration`.

**Surfaces:**

- Global setting default style.  
- Optional override on single Generate / Generate All / bulk scan options.  
- Passed into `PromptBuilder` system/user message; stored as `_sim_generated_style`.  
- Dedup key includes style: `(post_id, heading_hash, focus_keyword, style)`.

---

## 5. Vision verification gate

**Setting:** `ai_image_verify_vision` — default **false** (surface in Settings UI).

**When on:** After sideload, run existing vision/score path against focus keyword or heading.  

| Outcome | Behavior |
|---|---|
| Pass | Approve match as today |
| Fail | Do not auto-insert; mark match pending/rejected for review; keep attachment with meta noting vision fail |

Single modal Generate and bulk both respect the same setting. No extra default-on for bulk (cost choice).

---

## 6. Rejection learning

**When user rejects** a generated candidate in the modal:

- Persist blocklist entry: `(post_id, heading_hash, focus_keyword, style)` (option or custom table — prefer post meta / option keyed lightly; avoid new table unless needed).  
- Future Generate / Generate All / bulk **skips** that combo.  
- **Regenerate** explicitly bypasses the skip (forced).  
- Real (non-`_sim_generated`) library images are never blocked by this list.

---

## 7. Auto-match on publish (featured only)

**Setting:** toggle, default **off**.

**On publish/update** (when enabled):

1. If post already has featured image → no-op.  
2. Try existing FIAA slug match if applicable.  
3. Else if on-demand generation enabled + image provider ready → queue `generateFeaturedForPost` once.  
4. Never auto-generate in-content heading images in this phase.

---

## Premium / freemium

Follow existing SIM premium boundaries: AI image generation remains premium-gated where `Premium::has( 'ai_image_generation' )` (or current equivalent) already applies. New UI surfaces hide or badge when gated. Free build must not fatal if premium classes absent.

---

## Testing checklist

- Modal Generate All: 0 / 1 / many headings; cancel mid-run; hard confirm required.  
- Admin page scan without enqueue; confirm then jobs appear in AS.  
- Posts bulk → prefilled IDs → confirm.  
- Style changes dedup and prompt suffix.  
- Vision off: no extra calls; vision on: fail path does not auto-approve.  
- Reject then Generate skips; Regenerate forces.  
- Auto-publish off: no jobs; on: featured-only when missing; long post does not enqueue heading gens.  
- Seedream still uses queue path; preferred model honored.

---

## Build sequence

1. Modal Generate All  
2. Generate Images admin page  
3. Posts list bulk action  
4. Styles  
5. Vision gate (setting + pipeline)  
6. Rejection learning  
7. Auto-publish featured toggle  

Each step ships behind settings/UI so incomplete later steps do not block earlier ones.
