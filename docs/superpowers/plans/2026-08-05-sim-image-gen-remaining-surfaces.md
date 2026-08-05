# SIM Image Generation Remaining Surfaces — Implementation Plan

> **For agentic workers:** Execute task-by-task. Steps use checkbox syntax.

**Goal:** Ship the seven remaining on-demand image-generation surfaces from the approved spec (2026-08-05), user-facing first.

**Architecture:** Thin wrappers around existing `AiImageGenerator` + `Queue::HOOK_AI_IMAGE_GEN`. Hard-confirm scan→estimate→enqueue. No mega-job loops.

**Tech Stack:** WordPress PHP 7.4+, REST, Action Scheduler, vanilla admin JS (modal + pages).

**Spec:** `docs/superpowers/specs/2026-08-05-sim-image-gen-remaining-surfaces-design.md`

**Global constraints:**
- Version bump + CHANGELOG per change set (target **3.2.0** for this feature set)
- WPCS tabs; no `@since` updates on existing symbols
- Premium gate where AI image gen already gated
- Commit only when user asks

---

### Task 1: Shared settings + style + rejection store + vision hook

**Files:**
- Create: `src/AI/GenerationRejectionStore.php`
- Modify: `src/Settings/Settings.php`, `Sanitizer.php`, `src/Premium/AiImageGenerator.php`, `src/AI/PromptBuilder.php` (style already present)

- [ ] Add settings defaults: `ai_image_style` (`photo`|`illustration`), `ai_image_auto_featured_on_publish` (bool false), surface `ai_image_verify_vision` in UI
- [ ] `GenerationRejectionStore`: `isBlocked(post,hash,keyword,style)`, `block(...)`, `force` bypass
- [ ] In `generateForHeading`: skip if blocked (unless force); after sideload if vision on, score and fail→pending/rejected path
- [ ] Changelog + version start 3.2.0

### Task 2: Modal Generate All

**Files:** `admin/js/src/modal.js`, `admin/css/sim-modal.css`, `src/Plugin.php` (modal footer button), localize `aiImageStyle`

- [ ] Collect eligible headings (no matches or all confidence &lt; 40, not already generated, not blocked)
- [ ] Confirm dialog with N + ~time estimate
- [ ] Sequential enqueue via existing generate-image REST; aggregate progress
- [ ] Wire style from settings; Reject button → rejection store REST

### Task 3: Generate Images admin page + REST scan/enqueue

**Files:**
- Create: `admin/views/generate-images.php`, `admin/js/src/generate-images.js`, `admin/css/sim-generate-images.css` (or reuse pages css)
- Modify: `ImageGenController.php` (scan + bulk enqueue routes), `Settings.php` menu, `Plugin.php` enqueue

- [ ] Scan posts → unmatched heading counts (reuse Matcher/HeadingExtractor)
- [ ] Hard confirm Generate All → enqueue jobs
- [ ] Progress polling

### Task 4: Posts list bulk action

**Files:** `src/Admin/GenerateImagesBulkAction.php` (or Settings), register `bulk_actions` / `handle_bulk_actions`

- [ ] Add action; redirect to generate-images page with `post_ids`

### Task 5: Auto-publish featured only

**Files:** `src/Premium/AutoMatchOnPublish.php`, `Plugin.php` register

- [ ] On publish when setting on + no thumbnail → queue `generateFeaturedForPost`

### Task 6: Reject REST + modal Reject UI

**Files:** `ImageGenController.php`, `modal.js`

- [ ] POST reject endpoint; modal Reject for generated matches

### Task 7: Verify + docs

- [ ] Manual smoke checklist from spec
- [ ] Update parent plan status note if needed

**Execution:** Inline in this session (user said begin).
