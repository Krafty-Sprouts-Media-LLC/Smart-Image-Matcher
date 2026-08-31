# Design: One article processor (match / review / generate / skip)

**Date:** 2026-08-31  
**Status:** Engine locked; admin chrome lives in the UI spec  
**Release:** 3.3.0 (not 3.2.x). Last 3.2 patch is 3.2.31.  
**UI spec:** `docs/superpowers/specs/2026-08-31-article-processor-ui-design.md` (screens, states, copy). Wins on admin chrome.  
**Related:** Bulk Processor review gate (`IMPLEMENTATION_PLAN.md` §4), featured-only auto-publish (`docs/superpowers/specs/2026-08-05-sim-image-gen-remaining-surfaces-design.md` §7)

---

## Problem

Featured Images and in-content heading matching are two products.

- Featured Images (upload + cron + publish) assign immediately.
- Bulk Processor writes every heading match as `pending`. Review Queue is a flat, read-only list of those rows. The confidence threshold only hides weak candidates; it never inserts. An unused `auto_insert` flag exists on the bulk ability but is not wired.

Operators must start a bulk job, then approve, then insert — even at 100% keyword matches — while featured images already auto-assign. The queue already processes **one post per Action Scheduler job**. The split is the design, not a throughput limit.

## Goals

1. One processor per article: featured (if missing) then headings that still need an image.
2. For each need, exactly one outcome: **match existing**, **review**, **generate** (if enabled), or **skip**.
3. Generate only when the library would skip. A strong library hit never spends generation credits.
4. The SIM admin is three items: **Dashboard**, **Bulk Processor** (Run + Review), **Settings**. Featured Images and Review Queue are not top-level menus.
5. Automation (cron, publish, upload-on-assign) is Settings. Bulk Processor is the only manual run.
6. Modal stays interactive. Upload-time FIAA stays image-centric (new attachment → maybe featured on a matching post).

## Non-goals (this change)

- Auto-generating on publish when on-demand generation is off.
- Changing modal Generate / Generate All.
- SIM talking to fal HTTP directly (still `ProviderBridge` / `wp_ai_client_prompt()`).
- Auto-inserting historical `pending` rows on deploy.
- Keeping a Featured Images “Match Runner” or a separate Review Queue submenu.

---

## Decisions (locked)

| Decision | Choice |
|---|---|
| Unit of work | One WordPress post per AS job (already true) |
| Engine | `Domain\ArticleProcessor::process( $post_id )` |
| Library path | Score → insert / review / skip |
| Generation | Only if the library outcome would be skip **and** generation is enabled **and** a generator adapter is registered and ready |
| Review UI | Tab on Bulk Processor; group by post; nested headings; only the review band |
| Modal | Unchanged (`saveMatchGroups` still stores editor candidates) |
| Upload FIAA | Settings only (policy). No Match Runner page. |
| Admin menus | Dashboard, Bulk Processor, Settings. Featured Images and Review Queue submenus are removed. No redirects. |

---

## Decision rule

Pure function `Domain\MatchDecision::decide( $score, $auto_insert, $review_min, $can_generate )`.

`$score` is 0–100. No candidate ⇒ score `0`.

| Condition | Outcome |
|---|---|
| `$score >= $auto_insert` | `insert` — use that library attachment now |
| `$score >= $review_min` | `review` — pending row, do not generate |
| `$can_generate` | `generate` — enqueue existing AI image job |
| else | `skip` — write nothing |

Settings:

- `auto_insert_threshold` (new, default `90`). Sanitise so it is never below `confidence_threshold`.
- `confidence_threshold` (existing, default `70`) is the review floor. Bulk job `min_score` may raise the review floor for that job only. Auto-insert always uses the setting (operators cannot bulk-insert at 70 by lowering the job slider).
- `ai_image_generation_enabled` plus provider ready plus a registered generator adapter ⇒ `$can_generate === true`.

The free processor never calls `Premium::has()`. Generation is an optional adapter. If it is missing, `generate` becomes `skip`.

---

## ArticleProcessor

Interface callers need:

```
process( int $post_id ): array{
  inserted: int,
  review: int,
  generated: int,
  skipped: int
}
```

Implementation, in order:

1. Load the post. Missing post → `{0,0,0,0}` and log.
2. **Featured**, if the post supports thumbnails and has no actionable featured image: take the best `FeaturedImageService::scoreSlugMatch()` integer (no candidate ⇒ `0`) and run it through `MatchDecision`. Do not keep a parallel FIAA auto-assign gate that inserts below `auto_insert_threshold`. Insert ⇒ `set_post_thumbnail`. Review ⇒ pending row with `heading_hash = featured`. Generate ⇒ existing `enqueueAiImageGen` with `heading_hash = featured`.
3. Extract headings (`HeadingExtractor` + hierarchy filter). Skip a heading that already has an immediately following image (Gutenberg image block or Classic `<img>` after that heading). Add a small query on `InsertionService` / `HeadingLocator` if one does not exist; do not insert duplicates.
4. For each remaining heading: keyword match (same `Matcher` + `ImageRepository` as today). Best score through `MatchDecision`. Collect `insert` rows; write `review` rows; enqueue at most one generate job per heading hash.
5. Apply all heading inserts in **one** `InsertionService` bulk call / one `wp_update_post` (existing bulk-insert rule). If that update fails, insert nothing for this post and leave those slots as review rows with the intended `image_id`.
6. Return counts.

Do not use `MatchRepository::saveMatchGroups()` here. That method is for the modal (all candidates, status `pending`). Processor writes only review-band rows (dedicated upsert by `post_id` + `heading_hash`).

---

## Admin information architecture

SIM submenu after this change:

```
Dashboard
Bulk Processor    ← Run | Review
Settings          ← all policy, including former FIAA/cron
```

Remove as visible menus: **Featured Images**, **Review Queue**. Unregister those `add_submenu_page` calls. Do not add hidden pages, 302s, or slug aliases. This is wp-admin, not a public site.

### Settings (automation + policy)

Everything that is a standing rule lives here, not on an ops page:

- Matching: review floor (`confidence_threshold`), auto-insert % (new), hierarchy, spacing.
- Upload FIAA: auto-assign on upload, upload post types, excluded filenames.
- Scheduled article processing: enabled, interval, post types/statuses (today’s `fiaa_cron_*` keys; copy says article processor, not featured-only).
- On first publish: existing `ai_image_auto_featured_on_publish` key; copy says process the article.
- Generation: existing on-demand + vision toggles.

No “run now” button on Settings.

### Bulk Processor (manual run + review)

This is the only hands-on operations page. Two tabs (or equivalent persistent nav on the same page):

**Run**

- Select posts (reuse today’s Bulk step 1 filters).
- Mode: **Process articles** (default — `ArticleProcessor`) or **Generate missing featured images** (existing featured-gen path, hard confirm, cost estimate — this is the former Featured Images generate UI, not a setting).
- Enqueue one AS job per post. Progress on this tab.
- When Process articles finishes, show inserted / review / generated / skipped. If `review > 0`, switch to or deep-link the Review tab filtered to that job.

**Review**

- All pending exceptions, including cron/publish leftovers (not only the last manual job). Optional job filter.
- Grouped by article; nested heading or featured slot; thumbnail; score; approve / reject; insert approved.
- Filter: in-content headings | featured slots (unsafe/held featured audit lives here as the featured filter, not a separate page).
- Menu badge on Bulk Processor when pending count > 0.

Bulk Processor exists **because automation is hands-off**. Cron and publish do not require opening this page. Operators open it to run a selection now, or to clear the review band.

Run is **one screen** (filters + mode, then progress on the same tab). It is not the old 1–4 wizard. Review is a sibling tab, always reachable. Screen-level detail is in the UI spec.

### Dashboard

Coverage %, pending review count, last scheduled/manual run. Primary CTA: Bulk Processor (Run). Pending count links to Bulk Processor Review. Remove the “Match Runner” button that currently points at Featured Images.

### Featured Images page

Unregister the submenu. Delete or stop loading `admin/views/featured-images.php` from the menu. Match Runner is Bulk Processor → Run. Generate-missing-featured is a Run mode on that same page.

### Review Queue page

Unregister the submenu. Delete or stop loading `admin/views/review-queue.php` from the menu. Review is the Bulk Processor Review tab.

---

## Triggers (adapters)

| Trigger | Where the operator touches it | After |
|---|---|---|
| Manual run | Bulk Processor → Run | `ArticleProcessor` per selected post |
| Generate missing featured | Bulk Processor → Run, mode generate-featured | Existing featured-gen enqueue (hard confirm) |
| Cron | Settings only | Each selected post is `ArticleProcessor` |
| Publish | Settings only | First publish → `ArticleProcessor` (`ai_image_auto_featured_on_publish`) |
| Upload | Settings only | Attachment → featured on matching post (unchanged) |
| Modal | Post editor | Unchanged |

Manual, cron, and publish enqueue `Queue::HOOK_PROCESS_ARTICLE` → `JobRunner::runArticleProcessJob( $post_id, $job_id )`. Bulk passes the parent `$job_id` for progress/cancel. Cron and publish pass an empty `$job_id`. `runBulkMatchJob` becomes a thin wrapper around this or is deleted once callers are switched.

---

## Generation finalize

Reuse `AiImageGenerator` + `JobRunner` persist path.

- Success, vision off or vision pass → insert / set featured as today.
- Vision fail (`ai_image_verify_vision`) → pending review row, do not auto-insert.
- Enqueue failure, provider error, in-flight duplicate, rejection blocklist → skip, log. No pending row.

Hard confirm for generation remains on modal Generate All and on Bulk Processor **Generate missing featured**. Cron / publish / Process-articles that already run with generation enabled **are** the consent for skip-band generate. Do not add a second confirm dialog on those paths.

---

## Review data (Bulk Processor Review tab)

- Query pending rows grouped by `post_id`. Paginate **articles** (e.g. 20 posts/page), not raw heading rows.
- Each article: post title + nested heading or featured slot / thumbnail / score / approve / reject.
- Thumbnails use `image_url` from `wp_get_attachment_image_url()` (not `/wp-json/wp/v2/media/{id}`).

REST shape:

```
{
  articles: [
    {
      post_id, post_title, edit_url,
      headings: [ { match_id, heading_tag, heading_text, image_id, image_url, confidence_score } ]
    }
  ],
  total_articles, page, per_page
}
```

Existing `GET /jobs/{id}/matches` may keep a job filter; ungrouped `matches[]` is insufficient for the new UI.

---

## Failures

| Case | Behavior |
|---|---|
| Missing post / no headings | Skip, log |
| Heading already has an image | Skip |
| Featured already set | Skip featured slot |
| Content `wp_update_post` fails | No heading inserts applied; intended inserts stored as review |
| Generate enqueue / provider fail | Skip, log |
| Generate in flight for that hash | Skip |
| Vision fail on generated image | Review |
| Historical `pending` rows | Stay pending; not auto-inserted on deploy |

---

## Settings copy (required)

- New number field: **Auto-insert threshold (%)** — “Matches at or above this score are inserted without review.”
- Existing confidence field: modal floor **and** review floor for automation.
- Scheduled section title/help: article processing (featured + headings), not “featured auto-assigner.”
- `ai_image_auto_featured_on_publish` label/help: process the article on first publish.
- Upload section stays upload-only. Do not describe it as a bulk runner.

---

## Premium / free

| Piece | Tier |
|---|---|
| `MatchDecision`, `ArticleProcessor`, keyword insert | Free (same as modal insert) |
| Generator adapter | Premium / existing `ai_image_generation` |
| Bulk Processor UI (Run + Review) | Existing `bulk_processor` (Review tab is not a separate menu; `review_queue` gate may alias the same UI) |
| Cron, publish automation | Existing `fiaa_scheduled_cron`, `auto_match_on_publish` |

Wire the adapter in `Plugin::registerPremiumServices()` only when generation is available. The free class must run without that class on disk.

---

## Testing

1. `MatchDecisionTest`: 100 → insert; 80 with review 70 / auto 90 → review; 40 generation off → skip; 40 generation on → generate; 90 with auto 90 → insert (boundary).
2. `ArticleProcessorTest` with fakes: high-score heading inserts and does not enqueue generate; mid-score writes pending only; skip-band with adapter enqueues one job; skip-band without adapter enqueues nothing; heading that already has an image is skipped.
3. Review grouping: two headings on one post → one article with two nested rows on the Bulk Review tab.
4. `saveMatchGroups` modal path still writes all candidates pending (no regression).
5. Featured Images and Review Queue submenus are gone; Dashboard CTAs point at Bulk Processor.

Manual: bulk a post with mixed 100% / 80% / unmatched headings, generation off → inserts / review / skip. Repeat with generation on → unmatched enqueues generate, not skip. Cron leftover appears on Review without starting a new run.

---

## Build sequence

1. `MatchDecision` + unit tests + `auto_insert_threshold` setting.  
2. `ArticleProcessor` (library insert + review + skip) + tests; point manual bulk Run at it.  
3. Bulk Processor Review tab (grouped by article, actions, thumbnails); remove Review Queue submenu.  
4. Move FIAA/cron/publish copy into Settings; Dashboard CTAs to Bulk Processor; unregister Featured Images submenu; Match Runner is Bulk Run.  
5. Generate-missing-featured as a Bulk Run mode (hard confirm).  
6. Generation adapter on skip-band for Process articles.  
7. Cron + publish call the processor.

Ship as **3.3.0** once engine + UI spec are both implemented. Do not cut patch versions 3.2.32–37 for this work.
