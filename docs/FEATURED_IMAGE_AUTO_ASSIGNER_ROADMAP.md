# Featured Image Auto-Assigner Roadmap

This document tracks high-impact, low-risk improvements for the featured image auto-assigner module in Smart Image Matcher.

## Planned Enhancements

1. Dry-run mode (no writes, report only)
- Add an execution mode that performs full matching and reporting without calling `set_post_thumbnail()`.
- Show expected matched/skipped/unmatched outcomes before applying changes.

2. Review queue for probable matches
- Store ambiguous/probable featured-image candidates instead of only reporting them.
- Add approve/reject/swap actions before assigning featured images.
- Allow batch approval of high-confidence featured-image candidates.

3. Scoring controls
- Add settings for minimum smart slug score and ambiguity gap.
- Add a strict mode that only accepts exact and prefix matches.

4. WP-CLI runner for large sites/imports
- Add a CLI command for long-running assignment jobs.
- Include arguments for post type, status, overwrite behavior, dry-run, limit, and offset.

5. Run summary persistence and history
- Keep last run summary visible in admin (matched/skipped/unmatched/total/duration).
- Extend to short history list (recent runs) for auditability.

## Implemented in 3.0.0 Rebuild

- Smart slug scoring shared by upload-time assignment, manual runs, REST, and Abilities.
- Exact, prefix, reverse-prefix, and token-overlap matching.
- Minimum shared-term guard so generic one-word image slugs do not auto-win broad article slugs.
- Ambiguity guard that refuses close calls instead of auto-assigning.
- Manual results show image slug, score, method, and top ambiguous candidates.
- Expanded remaining backlog in `docs/ROADMAP_BACKLOG.md`.

## Implemented in 2.6.0

- Batched manual processing for lower memory usage.
- Attachment slug map caching with invalidation hooks.
- Daily cron auto-run (`sim_fiaa_cron_run`) with options:
  - `sim_fiaa_cron_enabled`
  - `sim_fiaa_cron_post_types` (comma-separated)
  - `sim_fiaa_cron_overwrite`
- Persisted last cron run summary in `sim_fiaa_last_run_summary`.
