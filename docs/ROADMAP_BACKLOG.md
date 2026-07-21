# Smart Image Matcher Roadmap Backlog

This backlog tracks the remaining work after the 3.0 rebuild tasks that are already implemented in `src/`.
`IMPLEMENTATION_PLAN.md` remains the source of truth for phases; this document is the practical punch list for unfinished product details.

## Bulk Processor

### Implemented Foundation

- Queue-driven match jobs through Action Scheduler.
- Refresh/reload recovery for active and completed bulk jobs.
- Job progress persisted in `wp_sim_queue`.
- Four-step admin page: Select Posts, Configure, Find Matches, Review & Insert.
- Expanded post filters:
  - post type
  - manual IDs or slugs
  - post status
  - title/content/excerpt/slug search
  - taxonomy filters with `taxonomy:term-slug,term-slug` syntax
  - published and modified date ranges
  - featured image state
  - content state
  - max post limit
- Browser-local saved selections.

### Remaining Backlog

- Server-side saved segments:
  - Store reusable selections in an option or custom table.
  - Add create, rename, delete, and duplicate actions.
  - Support shared admin presets, not only browser-local presets.
- Searchable manual post picker:
  - REST endpoint for paginated post search.
  - Checkbox table with select-current-page and select-all-matching-filter actions.
  - Show selected count before the job starts.
- Live preview count:
  - Add a REST endpoint that returns how many posts match current filters.
  - Warn when a filter set would queue zero posts or a very large job.
- Author filter:
  - Add UI and REST support for author/user selection.
- Rich review queue:
  - Status tabs for pending, approved, rejected, failed, and all.
  - Pagination controls in the SPA.
  - Server-side bulk approve/reject endpoints.
  - Swap-image flow using the carousel/match alternatives.
  - Show AI reasoning when the job used AI mode.
- Insert job visibility:
  - Poll insertion jobs after "Insert Approved".
  - Show inserted, skipped, and failed counts.
  - Mark failed match rows with actionable errors.
- Pause/resume:
  - Add an explicit paused job state.
  - Prevent queued actions from running while paused.
  - Resume remaining actions without creating duplicate work.
- Scheduling:
  - Allow a bulk job to be scheduled for off-peak execution.
  - Show scheduled jobs in the recent-job list.
- Notifications:
  - Optional completion email for long jobs.
  - Optional admin notice when the user returns after a job completed.
- WP-CLI:
  - `wp sim bulk match`
  - `wp sim bulk approve`
  - `wp sim bulk insert`
  - `wp sim job status`
- Tests:
  - Integration tests for job creation filters.
  - Integration tests for cancellation, reload recovery, and insertion queueing.
  - E2E test for selecting, matching, approving, and inserting a 50-post job.

## Featured Image Auto-Assigner

### Implemented Foundation

- Smart slug scorer shared by upload-time assignment, manual runs, REST, and Abilities.
- Exact slug, prefix, reverse-prefix, and token-overlap scoring.
- Minimum shared-term guard to prevent generic one-word image slugs from auto-winning.
- Ambiguity guard that refuses close calls instead of auto-assigning.
- Manual results show image slug, score, method, and ambiguous candidates.

### Remaining Backlog

- Dry-run mode:
  - Run the full matcher without calling `set_post_thumbnail()`.
  - Show matched, skipped, unmatched, and ambiguous results before writes.
- Review queue for probable featured-image matches:
  - Store ambiguous/probable featured matches for approval.
  - Add approve/reject/swap actions before assignment.
  - Allow batch approval of high-confidence featured matches.
- Scoring controls:
  - Settings for minimum smart slug score.
  - Settings for ambiguity gap.
  - Optional "prefix-only" strict mode for conservative sites.
- Better upload-time matching:
  - Consider token-overlap candidates on upload without unbounded post scans.
  - Queue expensive upload-time work when candidate search grows large.
- Run history:
  - Persist recent FIAA manual and scheduled runs.
  - Store matched/skipped/unmatched/ambiguous counts and duration.
- WP-CLI:
  - Add dry-run, overwrite, post type, status, limit, and offset flags.
- AI fallback:
  - Keep AI-generated featured-image fallback behind the premium gate.
  - Make sure AI generation only runs after slug scoring and review-safe checks fail.
- Tests:
  - Add integration coverage for assignment, overwrite behavior, and ambiguity handling.
  - Add fixture tests for common long-post-slug/short-image-slug cases.

## Cross-Feature Decisions

- Decide whether Bulk Processor should optionally assign featured images and insert in-content images in one campaign, or keep those as separate workflows.
- Define a shared "review queue" abstraction so in-content matches and featured-image candidates do not grow two unrelated approval systems.
- Decide how "auto-run on publish" should behave:
  - featured image only,
  - in-content suggestions only,
  - or both behind separate toggles.
- Add clear free/pro gating once phase 7 starts:
  - Bulk Processor is premium.
  - Featured image upload-time assignment remains free.
  - Scheduled FIAA and arbitrary post types remain premium.
