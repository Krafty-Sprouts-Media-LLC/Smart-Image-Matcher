<?php
/**
 * Premium: Scheduled Featured Image Auto-Assigner via Action Scheduler.
 *
 * Replaces the WP-Cron-based scheduling that existed in .legacy/.
 * Uses Action Scheduler for reliable, retry-capable scheduling.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Settings\Settings;

/**
 * Class FiaaCron
 *
 * @since 3.0.0
 */
class FiaaCron {

	/**
	 * Action hook name for the scheduled run.
	 */
	const HOOK = 'smart_image_matcher_fiaa_scheduled_run';

	/**
	 * Register hooks.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'runScheduledAssignment' ) );

		// Use action_scheduler_init — fires once AS data store is ready.
		// This is the correct hook for any as_* scheduling calls.
		add_action( 'action_scheduler_init', array( $this, 'maybeReschedule' ) );
	}

	/**
	 * Keep the scheduled action aligned with the configured interval.
	 *
	 * Hooked to action_scheduler_init (not plugins_loaded) so the AS data
	 * store is guaranteed to be ready when we call as_has_scheduled_action().
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function maybeReschedule(): void {
		if ( ! Settings::get( 'fiaa_cron_enabled' ) ) {
			$this->clearSchedule();
			$this->rememberScheduledInterval( '' );
			return;
		}

		// AS is available and initialized at this point.
		$interval    = $this->getInterval();
		$intervalSec = $this->intervalToSeconds( $interval );
		$scheduled   = $this->getRememberedScheduledInterval();
		$hasAction   = as_has_scheduled_action( self::HOOK, array(), Queue::GROUP );

		if ( ! $hasAction || $scheduled !== $interval ) {
			$this->clearSchedule();
			as_schedule_recurring_action( time(), $intervalSec, self::HOOK, array(), Queue::GROUP );
			$this->rememberScheduledInterval( $interval );
			Logger::info( 'FiaaCron: scheduled recurring action', array( 'interval' => $interval ) );
		}
	}

	/**
	 * Batch size for each Action Scheduler execution of the scheduled run.
	 *
	 * Mirrors the manual "Run Matcher" queue job batch size.
	 */
	const BATCH_SIZE = 20;

	/**
	 * Number of post IDs collected per get_posts() page while building the
	 * scheduled job's candidate list.
	 */
	const COLLECT_PAGE_SIZE = 200;

	/**
	 * Enqueue a batched featured-image matcher job for this scheduled tick.
	 *
	 * IMPORTANT: this method must return quickly. It used to call
	 * FeaturedImageService::run() synchronously and score every candidate
	 * post against the entire attachment slug map inside a single Action
	 * Scheduler execution — on a library of thousands of posts/images that
	 * reliably exceeded PHP's max_execution_time and Action Scheduler's
	 * async-request watchdog, so the "Every 12 hours" recurring action
	 * failed outright instead of ever completing (agents.md "the 30-second
	 * rule"). It now only collects candidate post IDs and hands the actual
	 * matching work to Queue::enqueueFiaaRun() / JobRunner::runFiaaRunJob(),
	 * the same resumable, chunked pipeline the manual "Run Matcher" admin
	 * button already uses safely.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function runScheduledAssignment(): void {
		if ( ! Settings::get( 'fiaa_cron_enabled' ) ) {
			return;
		}

		if ( ! Queue::isAvailable() ) {
			Logger::warn( 'FiaaCron: Action Scheduler unavailable, skipping scheduled run.' );
			return;
		}

		// Overlap guard: if a previous scheduled run is still queued or
		// processing (e.g. a very large library hasn't finished its batches
		// yet when the next tick fires), skip this tick rather than
		// starting a second concurrent pass over the same posts.
		if ( $this->hasActiveScheduledJob() ) {
			Logger::info( 'FiaaCron: previous scheduled run still in progress, skipping this tick.' );
			return;
		}

		$rawTypes = (string) Settings::get( 'fiaa_cron_post_types' );
		$types    = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $rawTypes ) ) ) );

		if ( empty( $types ) ) {
			$types = array( 'post' );
		}

		$postStatuses   = $this->getPostStatuses();
		$featuredFilter = $this->getFeaturedFilter();
		$overwrite      = (bool) Settings::get( 'fiaa_cron_overwrite' );
		$featuredFilter = $overwrite ? 'any' : $featuredFilter;

		$postIds = array();
		foreach ( $types as $postType ) {
			$postIds = array_merge( $postIds, $this->collectCandidatePostIds( $postType, $postStatuses, $featuredFilter ) );
		}
		$postIds = array_values( array_unique( array_map( 'absint', $postIds ) ) );

		if ( empty( $postIds ) ) {
			Logger::info( 'FiaaCron: no candidate posts for this scheduled run.' );
			return;
		}

		$jobId  = 'smart_image_matcher_fiaa_scheduled_' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$config = array(
			'post_type'       => $types[0],
			'post_types'      => $types,
			'post_statuses'   => $postStatuses,
			'featured_filter' => $featuredFilter,
			'overwrite'       => $overwrite,
			'post_ids'        => $postIds,
			'batch_size'      => self::BATCH_SIZE,
			'interval'        => $this->getInterval(),
		);

		$this->saveScheduledJob( $jobId, count( $postIds ), $config );

		$queued = ( new Queue() )->enqueueFiaaRun( $jobId );

		if ( null === $queued ) {
			Logger::error( 'FiaaCron: could not queue scheduled run job.', array( 'job_id' => $jobId ) );
			return;
		}

		Logger::info( 'FiaaCron: scheduled run queued', array( 'job_id' => $jobId, 'total_posts' => count( $postIds ) ) );
	}

	/**
	 * Whether a scheduled FIAA job is already queued or processing.
	 *
	 * @since 3.1.0
	 * @return bool
	 */
	private function hasActiveScheduledJob(): bool {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'smart_image_matcher_queue' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE job_id LIKE %s AND status IN ('queued', 'processing')
				 LIMIT 1",
				$wpdb->esc_like( 'smart_image_matcher_fiaa_scheduled_' ) . '%'
			)
		);
		// phpcs:enable

		return ! empty( $row );
	}

	/**
	 * Collect candidate post IDs for one post type, paginated so this never
	 * loads the whole post type into memory at once.
	 *
	 * @since 3.1.0
	 * @param string   $postType       Post type slug.
	 * @param string[] $postStatuses   Allowed post statuses.
	 * @param string   $featuredFilter 'any' | 'missing' | 'has'.
	 * @return int[]
	 */
	private function collectCandidatePostIds( string $postType, array $postStatuses, string $featuredFilter ): array {
		$postIds = array();
		$page    = 1;

		do {
			$args = array(
				'post_type'              => $postType,
				'post_status'            => $postStatuses,
				'posts_per_page'         => self::COLLECT_PAGE_SIZE,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			$metaQuery = $this->getFeaturedImageMetaQuery( $featuredFilter );
			if ( ! empty( $metaQuery ) ) {
				$args['meta_query'] = $metaQuery; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}

			$batch = get_posts( $args );

			foreach ( $batch as $postId ) {
				$postIds[] = (int) $postId;
			}

			$page++;
		} while ( count( $batch ) === self::COLLECT_PAGE_SIZE );

		return $postIds;
	}

	/**
	 * Build the featured-image meta query for a filter value.
	 *
	 * Mirrors FeaturedImageService::getFeaturedImageMetaQuery() /
	 * FeaturedImageController::getFeaturedImageMetaQuery() — kept as a
	 * local copy since this class only needs it for candidate collection.
	 *
	 * @since 3.1.0
	 * @param string $filter 'any' | 'missing' | 'has'.
	 * @return array<int|string, mixed>
	 */
	private function getFeaturedImageMetaQuery( string $filter ): array {
		if ( 'missing' === $filter ) {
			return array(
				'relation' => 'OR',
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_thumbnail_id',
					'value'   => 0,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			);
		}

		if ( 'has' === $filter ) {
			return array(
				array(
					'key'     => '_thumbnail_id',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			);
		}

		return array();
	}

	/**
	 * Insert the queue row for a scheduled FIAA run.
	 *
	 * job type 'fiaa_scheduled' is what lets JobRunner::finalizeScheduledFiaaRun()
	 * recognise this job and update the "Last Scheduled Run" summary once
	 * its final batch completes.
	 *
	 * @since 3.1.0
	 * @param string               $jobId  Job ID.
	 * @param int                  $total  Total candidate post count.
	 * @param array<string, mixed> $config Job config.
	 * @return void
	 */
	private function saveScheduledJob( string $jobId, int $total, array $config ): void {
		global $wpdb;

		$totals = array(
			'type'          => 'fiaa_scheduled',
			'total'         => $total,
			'done'          => 0,
			'offset'        => 0,
			'matched'       => 0,
			'skipped'       => 0,
			'unmatched'     => 0,
			'recent'        => array(),
			'config'        => $config,
			'started_at_ms' => (int) round( microtime( true ) * 1000 ),
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_queue',
			array(
				'job_id'     => $jobId,
				'status'     => 'queued',
				'priority'   => 0,
				'attempts'   => 0,
				'totals'     => wp_json_encode( $totals ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Clear all scheduled FIAA actions.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function clearSchedule(): void {
		wp_clear_scheduled_hook( self::HOOK );

		if ( Queue::isAvailable() && class_exists( 'ActionScheduler' ) && \ActionScheduler::is_initialized() ) {
			as_unschedule_all_actions( self::HOOK, array(), Queue::GROUP );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Allowed scheduled-run interval slugs, in ascending frequency order.
	 *
	 * every_4_hours/every_6_hours/every_8_hours were added so large
	 * libraries that only get through part of their post/image backlog
	 * per tick (now safely — each tick is a bounded batch, not a
	 * synchronous full pass) can still converge within a day instead of
	 * being limited to twice-daily or daily runs.
	 *
	 * @since 3.1.0
	 * @var string[]
	 */
	const ALLOWED_INTERVALS = array( 'hourly', 'every_4_hours', 'every_6_hours', 'every_8_hours', 'twicedaily', 'daily' );

	/**
	 * Get the validated cron interval from settings.
	 *
	 * @since 3.0.0
	 * @return string One of ALLOWED_INTERVALS.
	 */
	private function getInterval(): string {
		$interval = (string) Settings::get( 'fiaa_cron_interval' );
		return in_array( $interval, self::ALLOWED_INTERVALS, true ) ? $interval : 'daily';
	}

	/**
	 * Get validated scheduled post statuses.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	private function getPostStatuses(): array {
		$raw      = (string) Settings::get( 'fiaa_cron_post_statuses' );
		$statuses = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw ) ) ) );
		$allowed  = array( 'publish', 'draft', 'pending', 'future', 'private' );
		$statuses = array_values( array_intersect( $statuses, $allowed ) );

		return ! empty( $statuses ) ? $statuses : array( 'publish' );
	}

	/**
	 * Get validated scheduled featured-image filter.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	private function getFeaturedFilter(): string {
		$filter = (string) Settings::get( 'fiaa_cron_featured_filter' );
		return in_array( $filter, array( 'any', 'missing', 'has' ), true ) ? $filter : 'missing';
	}

	/**
	 * Convert a WP-Cron schedule name to seconds.
	 *
	 * @since 3.0.0
	 * @param string $schedule Schedule name.
	 * @return int Seconds.
	 */
	private function intervalToSeconds( string $schedule ): int {
		return array(
			'hourly'        => HOUR_IN_SECONDS,
			'every_4_hours' => 4 * HOUR_IN_SECONDS,
			'every_6_hours' => 6 * HOUR_IN_SECONDS,
			'every_8_hours' => 8 * HOUR_IN_SECONDS,
			'twicedaily'    => 12 * HOUR_IN_SECONDS,
			'daily'         => DAY_IN_SECONDS,
		)[ $schedule ] ?? DAY_IN_SECONDS;
	}

	/**
	 * Get the interval currently represented by the scheduled Action Scheduler event.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	private function getRememberedScheduledInterval(): string {
		$runtime = get_option( Settings::RUNTIME_OPTION, array() );
		return is_array( $runtime ) ? (string) ( $runtime['fiaa_schedule_interval'] ?? '' ) : '';
	}

	/**
	 * Remember the interval currently represented by the scheduled action.
	 *
	 * @since 3.0.0
	 * @param string $interval Interval slug.
	 * @return void
	 */
	private function rememberScheduledInterval( string $interval ): void {
		$runtime = get_option( Settings::RUNTIME_OPTION, array() );
		$runtime = is_array( $runtime ) ? $runtime : array();

		if ( '' === $interval ) {
			unset( $runtime['fiaa_schedule_interval'] );
		} else {
			$runtime['fiaa_schedule_interval'] = $interval;
		}

		update_option( Settings::RUNTIME_OPTION, $runtime, false );
	}
}
