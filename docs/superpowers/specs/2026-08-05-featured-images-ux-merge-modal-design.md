# Featured Images UX — Merge + Posts-List Modal

**Date:** 2026-08-05  
**Status:** Approved in chat (pending file review)  
**Version target:** 3.2.3  
**Related:** `docs/superpowers/specs/2026-08-05-sim-image-gen-remaining-surfaces-design.md` (bulk scope corrected to featured-only in 3.2.2)

---

## Decisions (locked)

| Decision | Choice |
|---|---|
| Posts with existing featured image | **Skip** — show reason “Already has featured image”. No overwrite/regenerate in this release. |
| Two admin menus | **Merge** — one **Featured Images** submenu. Remove **Generate Featured Images**. |
| Posts list bulk action | Stay on `edit.php`. **WP-style dismissable modal**; jobs continue in Action Scheduler after dismiss. |
| Admin AI options | Live on merged **Featured Images** page (filters, style, max posts, scan → confirm → enqueue). |
| Heading / under-heading images | **Unchanged** — editor SIM modal only. Not part of featured bulk or Featured Images AI card. |

---

## Problem

1. Posts-list bulk redirected to a separate admin page — jarring and easy to confuse with heading generation.  
2. Two similarly named menus (**Featured Images** vs **Generate Featured Images**) taught the wrong mental model.  
3. Scan returning “0” with no per-post reasons looked broken when selected posts already had thumbnails.

---

## Non-goals

- Overwrite / regenerate existing featured images via AI.  
- Heading image generation from posts list or Featured Images admin.  
- Changing Match Runner (slug/filename → media) behavior beyond sharing the same page.  
- fal / provider packaging changes.

---

## Architecture

### A. Single admin home: Featured Images

**Menu:** keep `smart-image-matcher-featured-images` only.

**Page sections (two cards):**

1. **Match Runner** — existing FIAA UI (unchanged).  
2. **AI Generate featured** — content moved from current Generate Featured Images view:
   - Filters: post type, status, image style, max posts  
   - Scan → estimate → confirm → enqueue → progress  
   - Same REST: `POST /generate-images/scan`, `POST /generate-images/enqueue`, status poll  

**Remove:** submenu + standalone page slug `smart-image-matcher-generate-images`.

**Redirect:** requests to the old page slug → Featured Images (`admin.php?page=smart-image-matcher-featured-images`), preserving `post_ids` query args when present (deep links / bookmarks). Prefer landing with focus on the AI card when `sim_ai=1` or `post_ids` is set.

### B. Posts list: dismissable modal

**Bulk action label:** “Generate featured images…” (unchanged intent).

**Flow (no full-page redirect):**

1. User selects posts → runs bulk action.  
2. Handler does **not** redirect to another SIM page. Instead redirect back to the list with a query flag (e.g. `sim_featured_ai=1` + selected IDs) **or** intercept via JS before navigation — prefer **JS intercept** if feasible so the list selection is not lost; fallback: redirect to same `edit.php` with `post__in` / `ids` in query and auto-open modal.  
3. Modal opens on the posts list screen:
   - Calls scan with those `post_ids`  
   - Table: each post + status (`Needs featured image` | `Already has featured image` | other skip reasons)  
   - Count eligible + estimate  
   - Confirm → enqueue only eligible  
   - Progress while open; **Dismiss** closes UI; AS jobs keep running  
4. After dismiss (or on complete): lightweight admin notice “N featured image job(s) queued / finished” when practical.

**Assets:** enqueue small script + CSS only on `edit.php` for supported public post types (when generation enabled / capability ok). Reuse scan/enqueue API; do not duplicate server logic.

### C. Scan API: explicit skip reasons

Extend `POST /generate-images/scan` response so clients can show **all considered posts**, not only eligible ones:

```json
{
  "mode": "featured",
  "posts": [ /* eligible only — keep for enqueue */ ],
  "skipped": [
    { "id": 123, "title": "…", "reason": "already_has_featured" }
  ],
  "total_images": 1,
  "estimate_seconds": 60
}
```

Reason codes (stable keys; UI maps to i18n strings):

| Code | Meaning |
|---|---|
| `already_has_featured` | `has_post_thumbnail` |
| `no_thumbnail_support` | post type lacks `thumbnail` |
| `already_generated` | `findGenerated` hit for featured + style/keyword |
| `rejected` | `GenerationRejectionStore` blocked |
| `not_found` / `no_permission` | as applicable when scanning explicit IDs |

When `post_ids` are supplied, every ID should appear in `posts` or `skipped` so the UI never looks empty/broken.

Enqueue behavior unchanged: only missing-featured eligible jobs; still no overwrite.

---

## Surfaces map (after change)

| Surface | Featured AI | Heading images |
|---|---|---|
| Posts list bulk + modal | Yes (missing only) | No |
| Featured Images admin — Match Runner | Media slug match | No |
| Featured Images admin — AI Generate | Yes (missing only) | No |
| Post editor SIM modal | No (unless existing featured sidebar tools elsewhere) | Yes |

---

## Implementation notes

- Bump to **3.2.3**; CHANGELOG + `readme.txt` Stable tag.  
- Move/reuse `admin/js/src/generate-images.js` into Featured Images enqueue + new posts-list modal entry (shared helpers OK; avoid one brittle mega-file if split is clearer: `featured-ai-generate.js` + `featured-ai-bulk-modal.js`).  
- `GenerateImagesBulkAction`: stop redirecting to removed page; open list modal flow.  
- Old generate-images view can be deleted or reduced to a redirect stub.  
- Keep REST route paths (`/generate-images/*`) to avoid breaking clients; semantics remain featured-only.

---

## Success criteria

1. Only one SIM submenu related to featured images.  
2. Posts-list bulk never drops the user on a separate SIM settings-like page for this action.  
3. Selecting posts that already have featured images shows those posts with **Already has featured image**, not a blank “0” with no explanation.  
4. Dismissing the modal does not cancel queued AS jobs.  
5. Editor heading Generate / Generate All still only in the SIM editor modal.
