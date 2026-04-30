# Featured Image Auto-Assigner Roadmap

This document tracks high-impact, low-risk improvements for the featured image auto-assigner module in Smart Image Matcher.

## Planned Enhancements

1. Dry-run mode (no writes, report only)
- Add an execution mode that performs full matching and reporting without calling `set_post_thumbnail()`.
- Show expected matched/skipped/unmatched outcomes before applying changes.

2. Auto-assign toggles in SIM settings
- Add settings to enable/disable upload-time auto-assigning.
- Add settings to control which post types are included for upload-time matching.

3. WP-CLI runner for large sites/imports
- Add a CLI command for long-running assignment jobs.
- Include arguments for post type, overwrite behavior, limit, and offset.

4. Run summary persistence and history
- Keep last run summary visible in admin (matched/skipped/unmatched/total/duration).
- Extend to short history list (recent runs) for auditability.

## Implemented in 2.6.0

- Batched manual processing for lower memory usage.
- Attachment slug map caching with invalidation hooks.
- Daily cron auto-run (`sim_fiaa_cron_run`) with options:
  - `sim_fiaa_cron_enabled`
  - `sim_fiaa_cron_post_types` (comma-separated)
  - `sim_fiaa_cron_overwrite`
- Persisted last cron run summary in `sim_fiaa_last_run_summary`.
