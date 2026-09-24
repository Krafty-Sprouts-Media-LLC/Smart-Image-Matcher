<?php
/**
 * Keeps the article queue moving and makes every stall visible.
 *
 * Runs on WP-Cron (not Action Scheduler) every 15 minutes, so it still runs
 * when the Action Scheduler slot is held by a dead action:
 *   1. Fails actions stuck "in-progress" and releases stale claims, using
 *      Action Scheduler's own QueueCleaner (15 min, not its 5 min default).
 *   2. Closes bulk jobs with no progress and nothing left in the queue, so
 *      FiaaCron's overlap guard stops skipping every hourly tick.
 * Also counts article actions Action Scheduler kills (timeout / fatal) so
 * their job can still finish, and logs each one to the Dashboard.
 *
 * @package SmartImageMatcher\Queue
 * @since   3.5.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Settings\Settings;

/**
 * Class QueueWatchdog
 *
 * @since 3.5.0
 */
class QueueWatchdog {

	/**
	 * WP-Cron hook.
	 */
	const HOOK = 'smart_image_matcher_watchdog';

	/**
	 * WP-Cron schedule name.
	 */
	const SCHEDULE = 'smart_image_matcher_15min';

	/**
	 * An action running longer than this is treated as dead.
	 */
	const STUCK_MINUTES = 15;

	/**
	 * A job with no progress for this long and an empty queue is closed.
	 */
	const STALL_MINUTES = 45;

	/**
	 * Recent scheduled ticks kept for the Dashboard.
	 */
	const MAX_TICKS = 12;

	/**
	 * Admin-post action for "Check now".
	 */
	const CHECK_NOW_ACTION = 'smart_image_matcher_watchdog_now';

	/**
	 * Register hooks.
	 *
	 * @since 3.5.0
	 * @return void
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'addSchedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 15 min is intentional.
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensureScheduled' ) );
		add_action( 'admin_post_' . self::CHECK_NOW_ACTION, array( $this, 'handleCheckNow' ) );

		add_action( 'action_scheduler_failed_action', array( $this, 'onActionTimedOut' ), 10, 2 );
		add_action( 'action_scheduler_unexpected_shutdown', array( $this, 'onActionFatal' ), 10, 2 );
		add_action( 'action_scheduler_failed_execution', array( $this, 'onActionException' ), 10, 2 );
	}

	/**
	 * Add the 15-minute schedule.
	 *
	 * @since 3.5.0
	 * @param mixed $schedules Schedules (other plugins can pass anything through this filter).
	 * @return array<string, array<string, mixed>>
	 */
	public function addSchedule( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 15 * 60,
				'display'  => __( 'Every 15 minutes (Smart Image Matcher)', 'smart-image-matcher' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the watchdog if missing (plugin updates never re-run activation).
	 *
	 * @since 3.5.0
	 * @return void
	 */
	public function ensureScheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Watchdog pass
	// -------------------------------------------------------------------------

	/**
	 * One watchdog pass. Returns what it found and did.
	 *
	 * @since 3.5.0
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$before = self::queueSnapshot();
		$freed  = 0;

		if ( ( $before['stuck'] > 0 || $before['stale_claims'] > 0 ) && class_exists( '\ActionScheduler_QueueCleaner' ) && class_exists( '\ActionScheduler' ) ) {
			$cleaner = new \ActionScheduler_QueueCleaner( \ActionScheduler::store() );
			$cleaner->mark_failures( self::STUCK_MINUTES * 60 );
			$cleaner->reset_timeouts( self::STUCK_MINUTES * 60 );
			$freed = (int) $before['stuck'] + (int) $before['stale_claims'];

			Logger::error(
				'Queue watchdog: freed a stuck Action Scheduler slot',
				array(
					'stuck_actions' => (int) $before['stuck'],
					'stale_claims'  => (int) $before['stale_claims'],
					'stuck_hook'    => (string) $before['stuck_hook'],
					'running_since' => (string) $before['stuck_since'],
				)
			);
		}

		$closed = $this->closeStalledJobs();
		$after  = self::queueSnapshot();

		$report = array(
			'at'     => time(),
			'freed'  => $freed,
			'closed' => $closed,
			'queue'  => $after,
		);

		self::saveRuntime( 'watchdog', $report );

		return $report;
	}

	/**
	 * Whether a job with no recent progress should be closed.
	 *
	 * @since 3.5.0
	 * @param int $now       Current Unix time.
	 * @param int $touched   Unix time of the job's last progress.
	 * @param int $remaining Queued or running actions still belonging to the job.
	 * @return bool
	 */
	public static function shouldCloseJob( int $now, int $touched, int $remaining ): bool {
		return $remaining <= 0 && ( $now - $touched ) >= self::STALL_MINUTES * 60;
	}

	/**
	 * Close queued/processing jobs that made no progress and have nothing queued.
	 *
	 * @return int Jobs closed.
	 */
	private function closeStalledJobs(): int {
		global $wpdb;

		$table = $wpdb->prefix . 'smart_image_matcher_queue';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT job_id, totals, created_at, started_at FROM {$table} WHERE status IN ('queued','processing') LIMIT 50", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$now    = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- compared with local-time DB columns.
		$closed = 0;

		foreach ( $rows as $row ) {
			$job_id  = (string) $row['job_id'];
			$totals  = json_decode( (string) ( $row['totals'] ?? '' ), true );
			$totals  = is_array( $totals ) ? $totals : array();
			$touched = isset( $totals['touched_at'] )
				? (int) $totals['touched_at']
				: (int) strtotime( (string) ( $row['started_at'] ?: $row['created_at'] ) );

			if ( ! self::shouldCloseJob( $now, $touched, self::remainingActions( $job_id ) ) ) {
				continue;
			}

			$done  = (int) ( $totals['done'] ?? 0 );
			$total = (int) ( $totals['total'] ?? 0 );
			$lost  = max( 0, $total - $done );

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array(
					'status'        => 'failed',
					'finished_at'   => current_time( 'mysql' ),
					'error_message' => sprintf( 'Stalled: %d of %d articles reported back; %d never ran.', $done, $total, $lost ),
				),
				array( 'job_id' => $job_id ),
				array( '%s', '%s', '%s' ),
				array( '%s' )
			);

			Logger::error(
				'Queue watchdog: closed a stalled job so hourly runs can continue',
				array(
					'job_id' => $job_id,
					'done'   => $done,
					'total'  => $total,
					'lost'   => $lost,
				)
			);
			++$closed;
		}

		return $closed;
	}

	/**
	 * Pending + running Action Scheduler actions for one job.
	 *
	 * @param string $job_id Job ID (appears in each action's args).
	 * @return int
	 */
	private static function remainingActions( string $job_id ): int {
		if ( '' === $job_id || ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return 1; // Unknown: never close a job we cannot inspect.
		}

		$count = 0;
		foreach ( array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ) as $status ) {
			$ids    = as_get_scheduled_actions(
				array(
					'group'    => Queue::GROUP,
					'status'   => $status,
					'search'   => $job_id,
					'per_page' => 1,
				),
				'ids'
			);
			$count += count( (array) $ids );
		}

		return $count;
	}

	// -------------------------------------------------------------------------
	// Action Scheduler failure hooks
	// -------------------------------------------------------------------------

	/**
	 * Action Scheduler marked an action failed because it ran too long.
	 *
	 * @since 3.5.0
	 * @param int|string $action_id Action ID.
	 * @param int|string $timeout   Seconds allowed.
	 * @return void
	 */
	public function onActionTimedOut( $action_id, $timeout = 0 ): void {
		/* translators: %d: seconds */
		$this->recordFailure( (int) $action_id, sprintf( __( 'Timed out after %d seconds', 'smart-image-matcher' ), (int) $timeout ), true );
	}

	/**
	 * The PHP process died mid-action (fatal error, memory).
	 *
	 * @since 3.5.0
	 * @param int|string $action_id Action ID.
	 * @param mixed      $error     error_get_last() payload.
	 * @return void
	 */
	public function onActionFatal( $action_id, $error = null ): void {
		$message = is_array( $error ) ? (string) ( $error['message'] ?? '' ) : '';
		$this->recordFailure( (int) $action_id, '' !== $message ? $message : __( 'PHP stopped unexpectedly', 'smart-image-matcher' ), true );
	}

	/**
	 * The action threw. runArticleProcessJob() already counted it in `finally`.
	 *
	 * @since 3.5.0
	 * @param int|string $action_id Action ID.
	 * @param mixed      $e         Throwable.
	 * @return void
	 */
	public function onActionException( $action_id, $e = null ): void {
		$message = $e instanceof \Throwable ? $e->getMessage() : '';
		$this->recordFailure( (int) $action_id, $message, false );
	}

	/**
	 * Log a failed plugin action and, when it never reported back, count it.
	 *
	 * @param int    $action_id  Action ID.
	 * @param string $reason     Why it failed.
	 * @param bool   $count_lost Whether the job's done counter still needs this article.
	 * @return void
	 */
	private function recordFailure( int $action_id, string $reason, bool $count_lost ): void {
		if ( $action_id <= 0 || ! class_exists( '\ActionScheduler' ) ) {
			return;
		}

		try {
			$action = \ActionScheduler::store()->fetch_action( (string) $action_id );
		} catch ( \Throwable $t ) {
			return;
		}

		if ( ! $action instanceof \ActionScheduler_Action || Queue::GROUP !== $action->get_group() ) {
			return;
		}

		$hook = (string) $action->get_hook();
		$args = (array) $action->get_args();

		Logger::error(
			'Queue: action failed',
			array(
				'hook'    => $hook,
				'post_id' => (int) ( $args['post_id'] ?? 0 ),
				'job_id'  => (string) ( $args['job_id'] ?? '' ),
				'reason'  => $reason,
			)
		);

		if ( $count_lost && Queue::HOOK_PROCESS_ARTICLE === $hook && '' !== (string) ( $args['job_id'] ?? '' ) ) {
			JobRunner::recordLostArticle( (string) $args['job_id'] );
		}
	}

	// -------------------------------------------------------------------------
	// Scheduled tick log + Dashboard data
	// -------------------------------------------------------------------------

	/**
	 * Record one scheduled tick's outcome (newest first, capped).
	 *
	 * @since 3.5.0
	 * @param string $outcome queued | skipped | nothing.
	 * @param string $detail  Human-readable detail.
	 * @return void
	 */
	public static function recordTick( string $outcome, string $detail ): void {
		$runtime = get_option( Settings::RUNTIME_OPTION, array() );
		$runtime = is_array( $runtime ) ? $runtime : array();
		$ticks   = isset( $runtime['ticks'] ) && is_array( $runtime['ticks'] ) ? $runtime['ticks'] : array();

		array_unshift(
			$ticks,
			array(
				'at'      => time(),
				'outcome' => $outcome,
				'detail'  => $detail,
			)
		);

		$runtime['ticks'] = array_slice( $ticks, 0, self::MAX_TICKS );
		update_option( Settings::RUNTIME_OPTION, $runtime, false );
	}

	/**
	 * Everything the Dashboard health panel shows.
	 *
	 * @since 3.5.0
	 * @return array<string, mixed>
	 */
	public static function health(): array {
		$runtime = get_option( Settings::RUNTIME_OPTION, array() );
		$runtime = is_array( $runtime ) ? $runtime : array();
		$queue   = self::queueSnapshot();
		$ticks   = isset( $runtime['ticks'] ) && is_array( $runtime['ticks'] ) ? $runtime['ticks'] : array();
		$last    = $ticks[0] ?? null;

		$state = 'good';
		$title = __( 'Queue is healthy', 'smart-image-matcher' );

		if ( $queue['stuck'] > 0 ) {
			$state = 'bad';
			/* translators: 1: hook name, 2: minutes */
			$title = sprintf( __( 'Blocked: %1$s has been running for %2$d min', 'smart-image-matcher' ), $queue['stuck_hook'], $queue['stuck_minutes'] );
		} elseif ( $queue['past_due'] > 0 && $queue['oldest_past_due_minutes'] >= 30 ) {
			$state = 'bad';
			/* translators: 1: count, 2: minutes */
			$title = sprintf( __( '%1$d actions overdue, oldest by %2$d min', 'smart-image-matcher' ), $queue['past_due'], $queue['oldest_past_due_minutes'] );
		} elseif ( is_array( $last ) && 'skipped' === ( $last['outcome'] ?? '' ) ) {
			$state = 'warn';
			$title = __( 'Last hourly run was skipped', 'smart-image-matcher' );
		}

		return array(
			'state'     => $state,
			'title'     => $title,
			'queue'     => $queue,
			'ticks'     => $ticks,
			'watchdog'  => isset( $runtime['watchdog'] ) && is_array( $runtime['watchdog'] ) ? $runtime['watchdog'] : array(),
			'next_tick' => function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( 'smart_image_matcher_fiaa_scheduled_run', array(), Queue::GROUP ) : false,
		);
	}

	/**
	 * Live Action Scheduler numbers: this plugin's backlog plus any site-wide
	 * action holding the runner (one stuck action blocks every plugin).
	 *
	 * @since 3.5.0
	 * @return array<string, mixed>
	 */
	public static function queueSnapshot(): array {
		global $wpdb;

		$snap = array(
			'pending'                 => 0,
			'past_due'                => 0,
			'oldest_past_due_minutes' => 0,
			'in_progress'             => 0,
			'stuck'                   => 0,
			'stuck_hook'              => '',
			'stuck_since'             => '',
			'stuck_minutes'           => 0,
			'stale_claims'            => 0,
		);

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		$claims  = $wpdb->prefix . 'actionscheduler_claims';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $actions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) ) ) {
			return $snap;
		}

		$own = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(a.status = 'pending') AS pending,
					SUM(a.status = 'pending' AND a.scheduled_date_gmt < UTC_TIMESTAMP()) AS past_due,
					TIMESTAMPDIFF(MINUTE, MIN(IF(a.status = 'pending', a.scheduled_date_gmt, NULL)), UTC_TIMESTAMP()) AS oldest_past_due_minutes,
					SUM(a.status = 'in-progress') AS in_progress
				 FROM {$actions} a
				 INNER JOIN {$groups} g ON g.group_id = a.group_id
				 WHERE g.slug = %s AND a.status IN ('pending','in-progress')",
				Queue::GROUP
			),
			ARRAY_A
		);

		$stuck = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, MIN(last_attempt_gmt) AS since,
					SUBSTRING_INDEX(GROUP_CONCAT(hook ORDER BY last_attempt_gmt ASC), ',', 1) AS hook
				 FROM {$actions}
				 WHERE status = 'in-progress' AND last_attempt_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)",
				self::STUCK_MINUTES
			),
			ARRAY_A
		);

		$stale = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT a.claim_id)
				 FROM {$actions} a
				 INNER JOIN {$claims} c ON c.claim_id = a.claim_id
				 WHERE a.status = 'pending' AND a.claim_id <> 0
				   AND c.date_created_gmt < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)",
				self::STUCK_MINUTES
			)
		);
		// phpcs:enable

		if ( is_array( $own ) ) {
			$snap['pending']                 = (int) $own['pending'];
			$snap['past_due']                = (int) $own['past_due'];
			$snap['in_progress']             = (int) $own['in_progress'];
			$snap['oldest_past_due_minutes'] = $snap['past_due'] > 0 ? max( 0, (int) $own['oldest_past_due_minutes'] ) : 0;
		}

		if ( is_array( $stuck ) && (int) $stuck['n'] > 0 ) {
			$snap['stuck']         = (int) $stuck['n'];
			$snap['stuck_hook']    = (string) $stuck['hook'];
			$snap['stuck_since']   = (string) $stuck['since'];
			$snap['stuck_minutes'] = (int) round( ( time() - (int) strtotime( $stuck['since'] . ' UTC' ) ) / 60 );
		}

		$snap['stale_claims'] = $stale;

		return $snap;
	}

	// -------------------------------------------------------------------------
	// Check now
	// -------------------------------------------------------------------------

	/**
	 * Dashboard "Check now" button.
	 *
	 * @since 3.5.0
	 * @return void
	 */
	public function handleCheckNow(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'smart-image-matcher' ) );
		}
		check_admin_referer( self::CHECK_NOW_ACTION );

		$report = $this->run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'smart-image-matcher',
					'sim_wd_freed'   => (int) $report['freed'],
					'sim_wd_closed'  => (int) $report['closed'],
					'sim_wd_checked' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Merge one key into the runtime option.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	private static function saveRuntime( string $key, $value ): void {
		$runtime         = get_option( Settings::RUNTIME_OPTION, array() );
		$runtime         = is_array( $runtime ) ? $runtime : array();
		$runtime[ $key ] = $value;
		update_option( Settings::RUNTIME_OPTION, $runtime, false );
	}
}
