# Design: 3.3.0 admin UI (article processor)

**Date:** 2026-08-31  
**Status:** Draft for review  
**Release:** 3.3.0 (minor). Last 3.2 patch is 3.2.31. Do not ship this as 3.2.32–37.  
**Parent:** `docs/superpowers/specs/2026-08-31-article-processor-design.md` (engine + IA)  
**HTML demos:** `docs/html-demos/index.html` (open in a browser)

This spec is the screen contract. The parent spec remains the engine contract. Where they disagree on admin chrome, **this file wins**.

---

## Problem

The engine will insert, park, generate, or skip per article. The current admin still teaches the opposite: a four-step Bulk wizard (“find matches, then review, then insert”), a Featured Images Match Runner, and a flat Review Queue. Operators will not trust auto-insert if the UI still looks like a suggestion factory.

---

## Goals

1. Three SIM submenu items only: **Dashboard**, **Bulk Processor**, **Settings**.
2. Bulk Processor is an ops desk with persistent **Run | Review**, not a wizard.
3. Every screen state the operator can hit is designed: empty, idle, running, done, failed, review, generate-confirm.
4. Visual language stays WordPress admin + existing SIM chrome (`sim-pages.css`: cards, `#2271b1` primary, metric tiles, pills). No marketing redesign.

---

## Non-goals

- Changing the post-editor modal.
- New wp-admin menu icons or a SIM “app shell” outside `#wpcontent`.
- Auto-inserting historical pending rows.
- URL redirects or hidden aliases for removed pages.
- Pixel-perfect recreation of Featured Images dual-panel generate UI; generate-featured is a Run **mode** plus a confirm modal.

---

## Locked layout decisions

| Question | Choice |
|---|---|
| Version | **3.3.0** |
| Run vs wizard | **No numbered steps.** Run is one screen: filters + mode, then the same tab becomes progress/done. |
| Select then configure | **Same view.** Mode cards sit above filters. No “Next” between them. |
| Review grouping | **Article cards** with nested **slot rows** (not a flat table, not one nested `<table>` of posts). |
| Generate-featured confirm | **Modal** on the Run tab. Count + qualitative time. Cancel / Generate N. |
| Filters after a run starts | Collapse behind **Change selection**. Progress is the hero. |
| Deep links | `page=smart-image-matcher-bulk&sim_tab=run\|review`. Optional `sim_tab=review&sim_run=last` for “Last run”. Never put a queue hash in visible copy. |
| Run identity | Operators see **runs** (“Today 09:14 · 40 articles · Inserted 28 · Review 7”). Parent `job_id` stays in the DB for progress/cancel only. |
| Removed menus | Unregister Featured Images and Review Queue. **No 302s.** |

---

## Runs vs articles

Each article is one Action Scheduler action (`HOOK_PROCESS_ARTICLE`). A **run** is the grouping operators care about: one Process click, or one scheduled tick.

The queue table still stores a parent id so the server can cancel, count progress, and resolve **Last run**. That id is never shown (no Job column, no `<code>sim_…</code>`). Labels are time + article count + outcomes: “Today 09:14 · 40 articles · Inserted 28 · Review 7”.

---

## Menu

```
Smart Image Matcher
  Dashboard
  Bulk Processor    ← awaiting-mod badge = pending review count (articles with ≥1 pending slot)
  Settings
```

Badge count is **distinct `post_id` with status=pending**, not raw heading rows. If zero, no badge.

Primary SIM landing stays Dashboard (`smart-image-matcher`). Settings slug unchanged. Bulk slug unchanged (`smart-image-matcher-bulk`).

---

## Shared chrome (Bulk Processor)

Persistent tab list **above** the card, always clickable:

- **Run** — idle / running / done / failed live here.
- **Review** — always reachable, including during a run (operator can peek leftovers).

Selected tab is the SIM tab style (blue underline, same as today’s step indicator active state). Do not reuse the 1–2–3–4 step nav.

While a job is running, Run tab may show a small “in progress” pill next to the label. Review badge stays the pending count.

---

## Screen: Dashboard

**Purpose:** Am I covered? What needs a human? Where do I go?

### Head

- Title: Smart Image Matcher
- Description: Coverage, pending review, and the last article run.
- Actions: **Settings** (secondary), **Process articles** (primary → Bulk `sim_tab=run`).

### Metrics (four tiles)

| Tile | Value | Click |
|---|---|---|
| Featured coverage | % of posts/pages with a real featured image (existing query) | none |
| Missing featured | count | none (copy: “Process articles or generate featured on Bulk Processor”) |
| Pending review | distinct posts with pending slots | Bulk `sim_tab=review` |
| Last run | “Today 09:14 · 40 articles · Inserted 28 · Review 7” (relative time + counts; never a queue hash) | Bulk `sim_tab=run` if a run is in progress or just finished |

Pending tile is visually clickable (blue top border). Missing featured is **not** a second primary button.

### Empty last-run

If no queue rows: “No article runs yet. Process a selection or wait for the scheduled run.” + link to Bulk Run.

### Side card: How articles are decided

Replace today’s “Exact and prefix slug matches / Auto” list with the four outcomes:

- At or above auto-insert % → insert now
- At or above review floor → Review tab
- Below review, generation on → generate (skip-band only)
- Else → skip

### Queue health

Table of recent **runs**, not IDs. Columns: **When** (Today 09:14), **What** (Process 40 articles / Scheduled / Generate featured), **Status**, **Outcome** (Inserted 28 · Review 7 · Generated 2 · Skipped 3). Do not show `job_id` / `sim_a1b2` / `<code>` hashes.

---

## Screen: Bulk Processor → Run

### Idle (no active job)

**Mode** (two cards, radio):

1. **Process articles** (default) — “Insert strong library matches. Send the middle band to Review. Generate only if on-demand generation is on and the library would skip.”
2. **Generate missing featured images** — “Posts with no real featured image. Does not scan headings. Requires a confirm.”

**Filters** (reuse today’s Bulk step 1 fields, two-column grid): post type, statuses, search, taxonomies, dates, featured filter, content filter, max posts, optional ID/slug list.

**Primary button**

- Process mode: **Process N articles** (N = current selection estimate; if unknown until request, **Process articles**).
- Generate mode: **Generate featured…** (opens confirm; does not enqueue yet).

**Help box** under the button, process mode: “Content can change during this run. Matches at or above the auto-insert threshold are written into the post. You will only review the middle band.”

No “Find Matches” copy. That label is banned on this screen.

### Generate confirm (modal)

Title: **Generate featured images?**

Body:

- “This will queue **N** featured image(s) for posts with no real featured image.”
- Estimate box (amber): “Time varies by model — often a few minutes each. Uses your connected image provider (fal.ai).”
- Does not mention heading generation.

Actions: **Cancel** (closes), **Generate N featured images** (primary, destructive-adjacent but still `button-primary`).

If N is 0: do not open the modal; inline notice “No posts in this selection are missing a featured image.”

If generation is disabled or no provider: disable the generate-featured mode card; description “Turn on on-demand generation in Settings and connect a provider.”

### Running

Same tab. Filters collapse to a one-line summary: “Posts · 40 selected · Process articles” + **Change selection** (disabled while running, or enabled only to cancel first).

Hero:

- Progress bar (posts done / total)
- Four outcome tiles, live: Inserted, Review, Generated, Skipped (from job totals; start at 0)
- Status line: “Processing 18 of 40 articles”
- **Cancel run** (secondary)

Activity log optional, collapsed by default (`<details>`), same monospace style as today.

Generate-featured running uses the same chrome; outcome tiles may show Queued / Done / Failed / Skipped instead of insert/review/generate/skip. Do not pretend generate-featured is ArticleProcessor.

### Done

Outcome tiles final. Status pill **Completed**.

If `review > 0`: primary **Review N articles** → `sim_tab=review&sim_run=last`. Secondary **Run again** resets to idle.

If `review === 0`: primary **Run again**. Copy: “Nothing landed in Review.”

### Failed / cancelled

Warn pill. Message. **Run again**. Cancelled does not jump to Review.

### Empty selection

Idle with filters that yield 0 posts: disable primary; “No posts match these filters.”

---

## Screen: Bulk Processor → Review

Always the same layout whether opened from the menu, Dashboard, or a finished run.

### Toolbar

- Slot filter: **All** | **Headings** | **Featured** (`slot=all|heading|featured`)
- Source: **All pending** (default) | **Last run** (the most recent completed or in-progress article run; includes cron if that was last)
- **Insert approved** (primary, right)
- **Approve all ≥ auto-insert %** (secondary; uses settings auto-insert, not a hardcoded 90 in copy — show the number)

### Article card

Header: post title (edit link, new tab), post ID as `<code>`, slot count (“2 to review”).

Each nested **slot row** (grid: thumb 72×54, text, score, actions):

- Label: `H2 Habitat` or `Featured image`
- Thumb: `image_url` from `wp_get_attachment_image_url( id, 'medium' )`. Empty → em dash, no broken icon.
- Score: `82%` with existing high/mid/low color classes (≥ auto-insert green, else ≥ review amber, else muted).
- **Approve** / **Reject** (small buttons). Approve marks the row; insert is a separate action (today’s model). After reject, row dims and drops out on refresh.

Paginate **20 articles per page**, not 50 heading rows.

### Empty

Centered: “Nothing to review.” + “Strong matches already inserted. Cron leftovers appear here when they need a human.” + **Process articles** (to Run).

### Last-run filter empty

If Last run has no pending slots: “This run has no review items.” + switcher to **All pending**.

---

## Screen: Settings

Policy only. Existing settings layout (left section nav + cards) stays.

| Section | What changes |
|---|---|
| Matching | Keep auto-insert + confidence. Confidence help: “Minimum score for the editor modal and for Review during automation.” Auto-insert help: “At or above this score, article processing inserts without review.” |
| Upload FIAA | Unchanged controls. Copy stays upload-only. No run button. |
| Scheduled | Title **Scheduled article processing**. Description from parent spec (featured + headings, insert/review/generate/skip). No “Run now”. |
| AI / publish | Label **Process article on first publish**. Help: library follows auto-insert/review; skip-band generate only if on-demand generation is on. |
| Generation | Existing toggles. |

Do not add Match Runner, generate-featured, or review tables here.

---

## Copy bans

Do not use on Dashboard, Bulk, or Settings:

- Match Runner
- Find Matches
- Review & Insert (as a wizard step name)
- Featured-image matching (for cron/publish — say article processing)
- Coming soon
- Job IDs, queue hashes, or a “Job” column (`sim_a1b2`, `smart_image_matcher_…`)

Bulk page `<h1>` stays **Bulk Processor**. Description: “Run article processing now, or review the middle band — including leftovers from scheduled and publish runs.”

---

## REST / query (UI needs)

Parent spec REST `articles[]` is required. Add:

- `GET /smart-image-matcher/v1/review?page&per_page&slot&run=all|last`
  - `run=all` or omitted → all pending (cron/publish leftovers included)
  - `run=last` → pending rows from the most recent article run’s time window (server resolves the queue row; JS never displays the id)
  - `slot=featured|heading|all`
- Existing job matches URL may keep working internally; 3.3 JS calls `/review` only. Ungrouped `matches[]` is not used.

Run progress payload must include running totals: `inserted`, `review`, `generated`, `skipped` (and generate-featured: `queued`, `done`, `failed`, `skipped`). The REST object may still have an internal `id` for cancel; the UI labels it a **run**, never a job hash.

---

## States checklist (implement / demo / QA)

| ID | Screen | Must exist |
|---|---|---|
| D1 | Dashboard | Runs exist (human labels, no hashes) |
| D0 | Dashboard | No jobs (empty last-run) |
| R0 | Run idle | Process selected |
| R0g | Run idle | Generate-featured selected |
| R0z | Run idle | Zero posts |
| C1 | Generate confirm | N > 0 |
| P1 | Run running | Process |
| P1g | Run running | Generate-featured |
| P2 | Run done | review > 0 |
| P2z | Run done | review = 0 |
| P3 | Run failed/cancelled | |
| V1 | Review | Grouped articles |
| V0 | Review | Empty |
| S1 | Settings | Matching + schedule + publish copy, no run |

---

## HTML demos

Throwaway previews (not production) live in `docs/html-demos/`. They must show D1, R0, C1, P1, P2, V1, V0, S1 in a fake wp-admin chrome so layout can be rejected before PHP/JS work.

---

## Relationship to parent spec

Parent spec § Admin still mentioned keeping Bulk steps 1–3 as the Run flow. **Superseded:** Run is a single screen, not a wizard. Review remains a sibling tab. Engine, thresholds, adapter, and no-redirects are unchanged.

Unreleased PHP already using `@since 3.2.32` is retagged to **3.3.0** before ship (3.2.32 never released).

---

## Testing (UI)

1. SIM menu is only Dashboard, Bulk Processor, Settings. Grep must not `add_submenu_page` for `smart-image-matcher-featured-images` or `smart-image-matcher-review-queue`.
2. Dashboard primary goes to Bulk Run; pending tile to Bulk Review.
3. Bulk has Run | Review with no step numbers. Review works with no current job (cron leftover fixture).
4. Process run: progress shows four outcome counts; done with review > 0 offers Review N.
5. Generate-featured: confirm modal required; cancel does not enqueue.
6. Review: two pending rows same `post_id` → one card, two slot rows; thumbs are file URLs.
7. Settings has no run control.
8. Modal Generate All unchanged (smoke).
