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

use SmartImageMatcher\Domain\ImageRepository;
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

	/**
	 * Action hook: on-demand AI image generation.
	 */
	const HOOK_AI_IMAGE_GEN = 'smart_image_matcher_queue_ai_image_gen';

	/**
	 * Action hook: poll fal for a submitted AI image job.
	 *
	 * @since 3.2.18
	 */
	const HOOK_AI_IMAGE_GEN_POLL = 'smart_image_matcher_queue_ai_image_gen_poll';

	/**
	 * Action hook: recover one completed fal image.
	 *
	 * @since 3.2.23
	 */
	const HOOK_FAL_RECOVER = 'smart_image_matcher_queue_fal_recover';

	/**
	 * Seconds between fal poll AS jobs.
	 *
	 * @since 3.2.18
	 */
	const AI_IMAGE_POLL_DELAY = 5;

	/**
	 * Max seconds to keep polling fal for one image.
	 *
	 * @since 3.2.18
	 */
	const AI_IMAGE_POLL_DEADLINE = 1800;

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
		add_action( self::HOOK_AI_MATCH, array( JobRunner::class, 'runAiMatchJob' ), 10, 2 );
		add_action( self::HOOK_INDEX_BACKFILL, array( JobRunner::class, 'runIndexBackfill' ) );
		add_action( self::HOOK_BULK_MATCH, array( JobRunner::class, 'runBulkMatchJob' ), 10, 3 );
		add_action( self::HOOK_BULK_INSERT, array( JobRunner::class, 'runBulkInsertJob' ), 10, 2 );
		add_action( self::HOOK_FIAA_RUN, array( JobRunner::class, 'runFiaaRunJob' ), 10, 1 );
		add_action( self::HOOK_FIAA_AUDIT_CLEAR, array( JobRunner::class, 'runFiaaAuditClearJob' ), 10, 1 );
		add_action( self::HOOK_AI_IMAGE_GEN, array( JobRunner::class, 'runAiImageGenJob' ), 10, 2 );
		add_action( self::HOOK_AI_IMAGE_GEN_POLL, array( JobRunner::class, 'runAiImageGenPollJob' ), 10, 2 );
		add_action( self::HOOK_FAL_RECOVER, array( JobRunner::class, 'runFalRecoverJob' ), 10, 2 );
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
			array(
				'post_id' => $postId,
				'mode'    => $mode,
			),
			self::GROUP
		);

		return $actionId ? (string) $actionId : null;
	}

	/**
	 * Schedule the one-shot inverted-index backfill job.
	 *
	 * Safe to call multiple times — only creates the job if no pending
	 * or in-progress backfill already exists AND the backfill hasn't
	 * already run to completion (per the persisted cursor state).
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

		// Already fully indexed — nothing to do.
		if ( ( new ImageRepository() )->getBackfillState()['done'] ?? false ) {
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
	 * Self-healing check: re-schedule the backfill if it never completed.
	 *
	 * Unlike scheduleIndexBackfill() (only ever called from Plugin::activate(),
	 * so a plugin update without deactivate/reactivate never re-triggers it),
	 * this is safe to call on every request. It is a no-op unless all of the
	 * following are true:
	 *   - the persisted cursor state says the backfill is not done, AND
	 *   - there is no pending/in-progress backfill action already queued
	 *     (i.e. the previous run failed, was abandoned, or was orphaned by
	 *     a hook rename and Action Scheduler gave up on it).
	 *
	 * @since 3.1.0
	 * @return void
	 */
	public function maybeResumeIndexBackfill(): void {
		if ( ! self::isAvailable() ) {
			return;
		}

		if ( class_exists( 'ActionScheduler' ) && ! \ActionScheduler::is_initialized() ) {
			add_action( 'action_scheduler_init', array( $this, 'maybeResumeIndexBackfill' ) );
			return;
		}

		$state = ( new ImageRepository() )->getBackfillState();

		if ( ! empty( $state['done'] ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::HOOK_INDEX_BACKFILL, array(), self::GROUP ) ) {
			return;
		}

		Logger::warn(
			'Queue: index backfill was incomplete with no pending action — resuming.',
			array(
				'offset' => (int) ( $state['offset'] ?? 0 ),
			)
		);

		as_enqueue_async_action( self::HOOK_INDEX_BACKFILL, array(), self::GROUP );
	}

	/**
	 * Unschedule any Action Scheduler actions still registered under a
	 * legacy hook name so they stop firing "no callbacks registered"
	 * failures forever after a hook rename.
	 *
	 * @since 3.1.0
	 * @param string[] $legacyHooks Legacy hook names (any group).
	 * @return void
	 */
	public static function clearLegacyHooks( array $legacyHooks ): void {
		if ( ! self::isAvailable() || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		if ( class_exists( 'ActionScheduler' ) && ! \ActionScheduler::is_initialized() ) {
			return;
		}

		foreach ( $legacyHooks as $hook ) {
			// No group filter: the whole point is to catch actions scheduled
			// under a prior hook-naming scheme, whatever group they used.
			as_unschedule_all_actions( $hook );
			wp_clear_scheduled_hook( $hook );
		}
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
			array(
				'job_id'  => $jobId,
				'post_id' => $postId,
				'config'  => $config,
			),
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
			array(
				'job_id'  => $jobId,
				'post_id' => $postId,
			),
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

	/**
	 * Enqueue an on-demand AI image generation job.
	 *
	 * Uses unique AS args (post_id + heading_hash) so a second Generate while
	 * pending cannot double-spend. Full payload lives in a transient.
	 *
	 * @since 3.1.1
	 * @param array<string, mixed> $args {
	 *     @type string $heading_hash  Heading hash.
	 *     @type string $heading_text  Heading text.
	 *     @type string $section_text  Section excerpt.
	 *     @type int    $post_id       Post ID.
	 *     @type string $focus_keyword Focus keyword.
	 *     @type string $style         photo|illustration.
	 *     @type bool   $force         Bypass cache/dedup.
	 * }
	 * @return string|null AS action ID, or null if unavailable / already queued.
	 */
	public function enqueueAiImageGen( array $args ): ?string {
		if ( ! self::isAvailable() ) {
			Logger::warn( 'Queue::enqueueAiImageGen: Action Scheduler not available.' );
			return null;
		}

		$payload = array(
			'heading_hash'  => sanitize_text_field( (string) ( $args['heading_hash'] ?? '' ) ),
			'heading_text'  => sanitize_text_field( (string) ( $args['heading_text'] ?? '' ) ),
			'section_text'  => sanitize_textarea_field( (string) ( $args['section_text'] ?? '' ) ),
			'post_id'       => absint( $args['post_id'] ?? 0 ),
			'focus_keyword' => sanitize_text_field( (string) ( $args['focus_keyword'] ?? '' ) ),
			'style'         => sanitize_key( (string) ( $args['style'] ?? 'photo' ) ),
			'force'         => ! empty( $args['force'] ),
		);

		$post_id      = (int) $payload['post_id'];
		$heading_hash = (string) $payload['heading_hash'];

		if ( $post_id <= 0 || '' === $heading_hash ) {
			return null;
		}

		$stable_args = array(
			'post_id'      => $post_id,
			'heading_hash' => $heading_hash,
		);

		if ( ! empty( $payload['force'] ) && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_AI_IMAGE_GEN, $stable_args, self::GROUP );
			as_unschedule_all_actions( self::HOOK_AI_IMAGE_GEN_POLL, $stable_args, self::GROUP );
			as_unschedule_all_actions( self::HOOK_FAL_RECOVER, $stable_args, self::GROUP );
		} elseif ( empty( $payload['force'] ) && self::hasPendingAiImageGen( $post_id, $heading_hash ) ) {
			Logger::info(
				'Queue::enqueueAiImageGen: already queued/processing',
				array(
					'post_id'      => $post_id,
					'heading_hash' => $heading_hash,
				)
			);
			return null;
		}

		\SmartImageMatcher\Premium\AiImageGenerator::storeJobPayload( $post_id, $heading_hash, $payload );

		// unique=true: AS refuses a second pending/running action with same hook+args+group.
		$action_id = as_enqueue_async_action(
			self::HOOK_AI_IMAGE_GEN,
			$stable_args,
			self::GROUP,
			true
		);

		return $action_id ? (string) $action_id : null;
	}

	/**
	 * Schedule a fal poll job for a submitted image generation.
	 *
	 * @since 3.2.18
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @param int    $delay        Seconds from now.
	 * @return string|null
	 */
	public function enqueueAiImageGenPoll( int $post_id, string $heading_hash, int $delay = self::AI_IMAGE_POLL_DELAY ): ?string {
		if ( ! self::isAvailable() ) {
			return null;
		}

		$post_id      = absint( $post_id );
		$heading_hash = sanitize_text_field( $heading_hash );
		if ( $post_id <= 0 || '' === $heading_hash ) {
			return null;
		}

		$args = array(
			'post_id'      => $post_id,
			'heading_hash' => $heading_hash,
		);

		$delay = max( 1, $delay );

		// Not unique: the current poll job is still RUNNING when we schedule the next.
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$action_id = as_schedule_single_action(
				time() + $delay,
				self::HOOK_AI_IMAGE_GEN_POLL,
				$args,
				self::GROUP,
				false
			);
			return $action_id ? (string) $action_id : null;
		}

		$action_id = as_enqueue_async_action(
			self::HOOK_AI_IMAGE_GEN_POLL,
			$args,
			self::GROUP,
			false
		);

		return $action_id ? (string) $action_id : null;
	}

	/**
	 * Enqueue recovery for one completed fal image.
	 *
	 * The recovery payload is stored in post meta before this method is called,
	 * keeping Action Scheduler arguments small and durable.
	 *
	 * @since 3.2.23
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @return string|null AS action ID, or null when unavailable/already queued.
	 */
	public function enqueueFalRecovery( int $post_id, string $heading_hash = 'featured' ): ?string {
		if ( ! self::isAvailable() ) {
			return null;
		}

		$post_id      = absint( $post_id );
		$heading_hash = sanitize_text_field( $heading_hash );
		if ( $post_id <= 0 || '' === $heading_hash ) {
			return null;
		}

		$args = array(
			'post_id'      => $post_id,
			'heading_hash' => $heading_hash,
		);
		if ( as_has_scheduled_action( self::HOOK_FAL_RECOVER, $args, self::GROUP ) ) {
			return null;
		}

		$action_id = as_enqueue_async_action(
			self::HOOK_FAL_RECOVER,
			$args,
			self::GROUP,
			true
		);

		return $action_id ? (string) $action_id : null;
	}

	/**
	 * Whether a start or poll AS action is pending/running for this pair.
	 *
	 * @since 3.2.18
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @return bool
	 */
	public static function hasPendingAiImageGen( int $post_id, string $heading_hash ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		$args = array(
			'post_id'      => absint( $post_id ),
			'heading_hash' => sanitize_text_field( $heading_hash ),
		);

		if ( as_has_scheduled_action( self::HOOK_AI_IMAGE_GEN, $args, self::GROUP ) ) {
			return true;
		}

		if ( as_has_scheduled_action( self::HOOK_AI_IMAGE_GEN_POLL, $args, self::GROUP ) ) {
			return true;
		}
		if ( as_has_scheduled_action( self::HOOK_FAL_RECOVER, $args, self::GROUP ) ) {
			return true;
		}

		return false;
	}

	/**
	 * List in-flight AI image gen jobs from Action Scheduler (for progress dock resume).
	 *
	 * @since 3.2.20
	 * @param int $limit Max actions to scan per hook.
	 * @return list<array{post_id:int,heading_hash:string}>
	 */
	public static function listInFlightAiImageGens( int $limit = 100 ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return array();
		}

		$limit = max( 1, min( 200, $limit ) );
		$found = array();

		foreach ( array( self::HOOK_AI_IMAGE_GEN, self::HOOK_AI_IMAGE_GEN_POLL, self::HOOK_FAL_RECOVER ) as $hook ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'group'    => self::GROUP,
					'status'   => array(
						\ActionScheduler_Store::STATUS_PENDING,
						\ActionScheduler_Store::STATUS_RUNNING,
					),
					'per_page' => $limit,
					'orderby'  => 'date',
					'order'    => 'DESC',
				),
				OBJECT
			);

			if ( ! is_array( $actions ) ) {
				continue;
			}

			foreach ( $actions as $action ) {
				if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
					continue;
				}
				$parsed = self::parseAiImageGenArgs( $action->get_args() );
				if ( null === $parsed ) {
					continue;
				}
				$key           = $parsed['post_id'] . ':' . $parsed['heading_hash'];
				$found[ $key ] = $parsed;
			}
		}

		return array_values( $found );
	}

	/**
	 * Extract post_id + heading_hash from AS action args (new or legacy shapes).
	 *
	 * @since 3.2.20
	 * @param mixed $args Action args.
	 * @return array{post_id:int,heading_hash:string}|null
	 */
	private static function parseAiImageGenArgs( $args ): ?array {
		if ( ! is_array( $args ) ) {
			return null;
		}

		$payload = $args;
		if ( isset( $args['payload'] ) && is_array( $args['payload'] ) ) {
			$payload = $args['payload'];
		}

		$post_id      = isset( $payload['post_id'] ) ? absint( $payload['post_id'] ) : 0;
		$heading_hash = isset( $payload['heading_hash'] ) ? sanitize_text_field( (string) $payload['heading_hash'] ) : '';

		if ( $post_id <= 0 || '' === $heading_hash ) {
			return null;
		}

		return array(
			'post_id'      => $post_id,
			'heading_hash' => $heading_hash,
		);
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
