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

use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\FeaturedImages\SlugMapBuilder;
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
	 * Execute the scheduled assignment run.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function runScheduledAssignment(): void {
		if ( ! Settings::get( 'fiaa_cron_enabled' ) ) {
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
		$service        = new FeaturedImageService( new SlugMapBuilder() );
		$started        = microtime( true );
		$summary        = array( 'matched' => 0, 'skipped' => 0, 'unmatched' => 0, 'total' => 0 );

		foreach ( $types as $postType ) {
			$results                 = $service->run(
				$postType,
				$overwrite,
				array(
					'post_statuses'   => $postStatuses,
					'featured_filter' => $overwrite ? 'any' : $featuredFilter,
				)
			);
			$summary['matched']     += count( $results['matched'] ?? array() );
			$summary['skipped']     += count( $results['skipped'] ?? array() );
			$summary['unmatched']   += count( $results['unmatched'] ?? array() );
			$summary['total']       += (int) $results['total'];
		}

		$summary['ran_at']                 = current_time( 'mysql' );
		$summary['post_types']             = $types;
		$summary['duration_ms']            = (int) round( ( microtime( true ) - $started ) * 1000 );
		$summary['fiaa_schedule_interval'] = $this->getRememberedScheduledInterval();
		$summary['post_statuses']          = $postStatuses;
		$summary['featured_filter']        = $overwrite ? 'any' : $featuredFilter;
		$summary['overwrite']              = $overwrite;

		update_option( Settings::RUNTIME_OPTION, $summary, false );

		Logger::info( 'FiaaCron: scheduled run complete', $summary );
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
	 * Get the validated cron interval from settings.
	 *
	 * @since 3.0.0
	 * @return string One of hourly, twicedaily, daily.
	 */
	private function getInterval(): string {
		$allowed  = array( 'hourly', 'twicedaily', 'daily' );
		$interval = (string) Settings::get( 'fiaa_cron_interval' );
		return in_array( $interval, $allowed, true ) ? $interval : 'daily';
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
			'hourly'     => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
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
