<?php
/**
 * Queue facade over Action Scheduler.
 *
 * All background work (AI calls, bulk operations, backfill) goes through here.
 * If Action Scheduler is unavailable the queue silently degrades — the caller
 * either falls back to synchronous execution or reports the limitation.
 *
 * @package SmartImageMatcher\Queue
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Logging\Logger;

/**
 * Class Queue
 *
 * @since 3.0.0
 */
class Queue {

	/**
	 * Action Scheduler group name for all SIM jobs.
	 */
	const GROUP = 'smart-image-matcher';

	/**
	 * Action hook: per-post AI match job.
	 */
	const HOOK_AI_MATCH = 'smart_image_matcher_queue_ai_match';

	/**
	 * Action hook: inverted-index backfill.
	 */
	const HOOK_INDEX_BACKFILL = 'smart_image_matcher_queue_index_backfill';

	/**
	 * Action hook: bulk match (one post per job).
	 */
	const HOOK_BULK_MATCH = 'smart_image_matcher_queue_bulk_match';

	/**
	 * Action hook: bulk insert (one post per job).
	 */
	const HOOK_BULK_INSERT = 'smart_image_matcher_queue_bulk_insert';

	/**
	 * Action hook: featured image auto-assigner manual run.
	 */
	const HOOK_FIAA_RUN = 'smart_image_matcher_queue_fiaa_run';

	/**
	 * Action hook: featured image audit cleanup (clear unsafe assignments).
	 */
	const HOOK_FIAA_AUDIT_CLEAR = 'smart_image_matcher_queue_fiaa_audit_clear';

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Register Action Scheduler action hooks.
	 *
	 * Called during Plugin::registerHooks().
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function registerHooks(): void {
		add_action( self::HOOK_AI_MATCH,       array( JobRunner::class, 'runAiMatchJob' ), 10, 2 );
		add_action( self::HOOK_INDEX_BACKFILL, array( JobRunner::class, 'runIndexBackfill' ) );
		add_action( self::HOOK_BULK_MATCH,     array( JobRunner::class, 'runBulkMatchJob' ), 10, 3 );
		add_action( self::HOOK_BULK_INSERT,    array( JobRunner::class, 'runBulkInsertJob' ), 10, 2 );
		add_action( self::HOOK_FIAA_RUN,       array( JobRunner::class, 'runFiaaRunJob' ), 10, 1 );
		add_action( self::HOOK_FIAA_AUDIT_CLEAR, array( JobRunner::class, 'runFiaaAuditClearJob' ), 10, 1 );
	}

	// -------------------------------------------------------------------------
	// Enqueue helpers
	// -------------------------------------------------------------------------

	/**
	 * Enqueue an AI match job for a single post.
	 *
	 * @since 3.0.0
	 * @param int    $postId Post ID.
	 * @param string $mode   Matching mode ('ai').
	 * @return string|null AS action ID, or null if AS unavailable.
	 */
	public function enqueueAiMatch( int $postId, string $mode = 'ai' ): ?string {
		if ( ! self::isAvailable() ) {
			Logger::warn( 'Queue::enqueueAiMatch: Action Scheduler not available.', array( 'post_id' => $postId ) );
			return null;
		}

		$actionId = as_enqueue_async_action(
			self::HOOK_AI_MATCH,
			array( 'post_id' => $postId, 'mode' => $mode ),
			self::GROUP
		);

		return $actionId ? (string) $actionId : null;
	}

	/**
	 * Schedule the one-shot inverted-index backfill job.
	 *
	 * Safe to call multiple times — only creates the job if no pending
	 * or in-progress backfill already exists.
	 *
	 * Must be called from action_scheduler_init or later, not plugins_loaded.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function scheduleIndexBackfill(): void {
		if ( ! self::isAvailable() ) {
			return;
		}

		// Guard: AS data store must be initialized before calling as_* functions.
		if ( class_exists( 'ActionScheduler' ) && ! \ActionScheduler::is_initialized() ) {
			// Defer to action_scheduler_init.
			add_action( 'action_scheduler_init', array( $this, 'scheduleIndexBackfill' ) );
			return;
		}

		// Don't double-schedule.
		if ( as_has_scheduled_action( self::HOOK_INDEX_BACKFILL, array(), self::GROUP ) ) {
			return;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_INDEX_BACKFILL, array(), self::GROUP );
		} else {
			as_enqueue_async_action( self::HOOK_INDEX_BACKFILL, array(), self::GROUP );
		}

		Logger::info( 'Queue: index backfill scheduled.' );
	}

	/**
	 * Enqueue a bulk match job for a single post within a named job.
	 *
	 * @since 3.0.0
	 * @param string               $jobId  Parent job identifier.
	 * @param int                  $postId Post ID.
	 * @param array<string, mixed> $config Matching configuration.
	 * @return string|null
	 */
	public function enqueueBulkMatchPost( string $jobId, int $postId, array $config ): ?string {
		if ( ! self::isAvailable() ) {
			return null;
		}

		$actionId = as_enqueue_async_action(
			self::HOOK_BULK_MATCH,
			array( 'job_id' => $jobId, 'post_id' => $postId, 'config' => $config ),
			self::GROUP
		);

		return $actionId ? (string) $actionId : null;
	}

	/**
	 * Enqueue a bulk insert job for a single post within a named job.
	 *
	 * @since 3.0.0
	 * @param string $jobId  Parent job identifier.
	 * @param int    $postId Post ID.
	 * @return string|null
	 */
	public function enqueueBulkInsertPost( string $jobId, int $postId ): ?string {
		if ( ! self::isAvailable() ) {
			return null;
		}

		$actionId = as_enqueue_async_action(
			self::HOOK_BULK_INSERT,
			array( 'job_id' => $jobId, 'post_id' => $postId ),
			self::GROUP
		);

		return $actionId ? (string) $actionId : null;
	}

	/**
	 * Enqueue a featured image auto-assigner manual run batch.
	 *
	 * @since 3.0.0
	 * @param string $jobId Parent job identifier.
	 * @return string|null
	 */
	public function enqueueFiaaRun( string $jobId ): ?string {
		if ( ! self::isAvailable() ) {
			return null;
		}

		$actionId = as_enqueue_async_action(
			self::HOOK_FIAA_RUN,
			array( 'job_id' => $jobId ),
			self::GROUP
		);

		return $actionId ? (string) $actionId : null;
	}

	/**
	 * Enqueue a featured image audit cleanup batch.
	 *
	 * @since 3.0.5
	 * @param string $jobId Parent job identifier.
	 * @return string|null
	 */
	public function enqueueFiaaAuditClear( string $jobId ): ?string {
		if ( ! self::isAvailable() ) {
			return null;
		}

		$actionId = as_enqueue_async_action(
			self::HOOK_FIAA_AUDIT_CLEAR,
			array( 'job_id' => $jobId ),
			self::GROUP
		);

		return $actionId ? (string) $actionId : null;
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	/**
	 * Whether Action Scheduler is loaded and functional.
	 *
	 * @since 3.0.0
	 * @return bool
	 */
	public static function isAvailable(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}
}
