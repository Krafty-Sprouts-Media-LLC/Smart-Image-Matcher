# Design: One article processor (match / review / generate / skip)

**Date:** 2026-08-31  
**Status:** Draft for review  
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
4. Review Queue lists **articles**, with pending headings nested. Approve / reject / insert live there.
5. Bulk, cron, and publish are triggers. They do not own separate match/insert pipelines.
6. Modal stays interactive. Upload-time FIAA stays image-centric (new attachment → maybe featured on a matching post).

## Non-goals (this change)

- Merging Featured Images and Bulk Processor into one admin screen.
- Auto-generating on publish when on-demand generation is off.
- Changing modal Generate / Generate All.
- SIM talking to fal HTTP directly (still `ProviderBridge` / `wp_ai_client_prompt()`).
- Auto-inserting historical `pending` rows on deploy.

---

## Decisions (locked)

| Decision | Choice |
|---|---|
| Unit of work | One WordPress post per AS job (already true) |
| Engine | `Domain\ArticleProcessor::process( $post_id )` |
| Library path | Score → insert / review / skip |
| Generation | Only if the library outcome would be skip **and** generation is enabled **and** a generator adapter is registered and ready |
| Review UI | Group by post; nested headings; only the review band |
| Modal | Unchanged (`saveMatchGroups` still stores editor candidates) |
| Upload FIAA | Unchanged |
| Admin menus | Keep both entry points; both call the processor where they currently run matching |

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

## Triggers (adapters)

| Trigger | Today | After |
|---|---|---|
| Bulk Processor | `JobRunner::runBulkMatchJob` saves all matches pending | Calls `ArticleProcessor`. Step 4 / Review Queue only shows leftover review rows. “Insert approved” remains for human-approved rows. |
| FIAA cron | Featured-only assignment | Same schedule and post filters; each selected post is `ArticleProcessor` (featured + headings). |
| Publish | `AutoMatchOnPublish`: FIAA then featured generate | Same setting key `ai_image_auto_featured_on_publish` (default off). Meaning becomes: on first publish, run `ArticleProcessor`. Help text: process the article (library match; generate skip-band only if generation is on). |
| Upload | Attachment → featured on matching post | Unchanged |
| Modal | Interactive match + insert | Unchanged |

All three triggers enqueue `Queue::HOOK_PROCESS_ARTICLE` → `JobRunner::runArticleProcessJob( $post_id, $job_id )`. Bulk passes the parent `$job_id` for progress/cancel. Cron and publish pass an empty `$job_id`. `runBulkMatchJob` becomes a thin wrapper around this or is deleted once callers are switched.

---

## Generation finalize

Reuse `AiImageGenerator` + `JobRunner` persist path.

- Success, vision off or vision pass → insert / set featured as today.
- Vision fail (`ai_image_verify_vision`) → pending review row, do not auto-insert.
- Enqueue failure, provider error, in-flight duplicate, rejection blocklist → skip, log. No pending row.

Hard confirm for generation remains on modal Generate All and Featured Generate. Cron / publish / bulk that already run with generation enabled **are** the consent for skip-band generate. Do not add a second confirm dialog on those paths.

---

## Review Queue

- Query pending rows grouped by `post_id`. Paginate **articles** (e.g. 20 posts/page), not raw heading rows.
- Each article: post title + nested heading / thumbnail / score / approve / reject.
- Thumbnails use `image_url` from `wp_get_attachment_image_url()` (not `/wp-json/wp/v2/media/{id}`).
- This page is the operator surface. It is not read-only. Bulk Processor step 4 uses the same grouping (same REST shape or a shared JS renderer).

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
- Existing confidence field description changes from “controls matches shown in the modal” to: modal floor **and** review floor for automation.
- `ai_image_auto_featured_on_publish` label/help: process the article on first publish (not featured-generate-only).
- Cron help: scheduled run processes each post through the same article rules (featured + headings).

---

## Premium / free

| Piece | Tier |
|---|---|
| `MatchDecision`, `ArticleProcessor`, keyword insert | Free (same as modal insert) |
| Generator adapter | Premium / existing `ai_image_generation` |
| Bulk Processor UI, cron, publish automation | Existing premium slugs (`bulk_processor`, `fiaa_scheduled_cron`, `auto_match_on_publish`) |
| Review Queue UI | Existing `review_queue` |

Wire the adapter in `Plugin::registerPremiumServices()` only when generation is available. The free class must run without that class on disk.

---

## Testing

1. `MatchDecisionTest`: 100 → insert; 80 with review 70 / auto 90 → review; 40 generation off → skip; 40 generation on → generate; 90 with auto 90 → insert (boundary).
2. `ArticleProcessorTest` with fakes: high-score heading inserts and does not enqueue generate; mid-score writes pending only; skip-band with adapter enqueues one job; skip-band without adapter enqueues nothing; heading that already has an image is skipped.
3. Review grouping: two headings on one post → one article with two nested rows.
4. `saveMatchGroups` modal path still writes all candidates pending (no regression).

Manual: bulk a post with mixed 100% / 80% / unmatched headings, generation off → inserts / review / skip. Repeat with generation on → unmatched enqueues generate, not skip.

---

## Build sequence

1. `MatchDecision` + unit tests + `auto_insert_threshold` setting.  
2. `ArticleProcessor` (library insert + review + skip) + tests; point `runBulkMatchJob` at it.  
3. Review Queue grouped by article + actions + thumbnails.  
4. Generation adapter on skip-band.  
5. Cron + publish call the processor; update setting copy.

Each step is shippable. After (2), bulk already auto-inserts strong matches. After (3), the review page matches how operators think (articles, not a flat heading dump).
