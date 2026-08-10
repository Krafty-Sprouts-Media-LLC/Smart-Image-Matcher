<?php
/**
 * Unit tests for the scheduled Featured Image Auto-Assigner cron.
 *
 * Regression coverage for the bug where runScheduledAssignment() called
 * FeaturedImageService::run() synchronously inside a single Action
 * Scheduler execution, scoring every candidate post against the entire
 * attachment slug map in one PHP process. On large libraries this
 * exceeded PHP max_execution_time / Action Scheduler's async-request
 * watchdog, so the recurring scheduled run failed outright instead of
 * ever completing.
 *
 * @package SmartImageMatcher\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Premium\FiaaCron;

/**
 * Class FiaaCronTest
 */
class FiaaCronTest extends TestCase {

	/**
	 * Reset test globals between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sim_test_options']          = array(
			'smart_image_matcher_settings' => array(
				'fiaa_cron_enabled'         => true,
				'fiaa_cron_post_types'      => 'post',
				'fiaa_cron_post_statuses'   => 'publish',
				'fiaa_cron_featured_filter' => 'missing',
				'fiaa_cron_overwrite'       => false,
				'fiaa_cron_interval'        => 'daily',
			),
		);
		$GLOBALS['sim_test_as_enqueued']      = array();
		$GLOBALS['sim_test_get_posts']        = null;
		$GLOBALS['sim_test_wpdb_queue_rows']  = array();
		$GLOBALS['sim_test_wpdb_inserted']    = array();
	}

	/**
	 * A stub $wpdb sufficient for FiaaCron::hasActiveScheduledJob() and
	 * FiaaCron::saveScheduledJob() — records inserts, and lets a test
	 * configure whether an "active job" row already exists.
	 *
	 * @return SimTestWpdb
	 */
	private function freshWpdb() {
		$GLOBALS['wpdb'] = new class extends SimTestWpdb {
			public function get_var( $query ) { // phpcs:ignore
				return $GLOBALS['sim_test_wpdb_queue_rows'][0] ?? null;
			}
			public function insert( $table, $data, $format = null ) { // phpcs:ignore
				$GLOBALS['sim_test_wpdb_inserted'][] = $data;
				return 1;
			}
			public function esc_like( $text ) { // phpcs:ignore
				return $text;
			}
		};

		return $GLOBALS['wpdb'];
	}

	/**
	 * The scheduled run must never call into any per-post matching logic
	 * synchronously — it must only enqueue a batched job and return. This
	 * is verified indirectly: with a candidate post available and no
	 * active job, exactly one Action Scheduler action is enqueued and the
	 * queue table receives exactly one inserted job row (the work itself
	 * is deferred to JobRunner::runFiaaRunJob(), which we don't invoke).
	 *
	 * @return void
	 */
	public function test_scheduled_run_enqueues_a_batched_job_instead_of_running_inline(): void {
		$this->freshWpdb();

		$GLOBALS['sim_test_get_posts'] = static function ( $args ) {
			return 1 === ( $args['paged'] ?? 1 ) ? array( 101, 102, 103 ) : array();
		};

		( new FiaaCron() )->runScheduledAssignment();

		$this->assertCount( 1, $GLOBALS['sim_test_as_enqueued'], 'Exactly one batched job must be enqueued per scheduled tick.' );
		$this->assertSame( \SmartImageMatcher\Queue\Queue::HOOK_FIAA_RUN, $GLOBALS['sim_test_as_enqueued'][0]['hook'] );

		$this->assertCount( 1, $GLOBALS['sim_test_wpdb_inserted'] );
		$inserted = $GLOBALS['sim_test_wpdb_inserted'][0];
		$totals   = json_decode( (string) $inserted['totals'], true );

		$this->assertSame( 'fiaa_scheduled', $totals['type'] );
		$this->assertSame( 3, $totals['total'] );
		$this->assertSame( array( 101, 102, 103 ), $totals['config']['post_ids'] );
	}

	/**
	 * If a scheduled run is already queued/processing, the next tick must
	 * skip entirely rather than starting an overlapping pass over the
	 * same posts.
	 *
	 * @return void
	 */
	public function test_scheduled_run_skips_when_a_previous_run_is_still_active(): void {
		$this->freshWpdb();
		$GLOBALS['sim_test_wpdb_queue_rows'] = array( 42 ); // Simulates an existing queued/processing row.

		$GLOBALS['sim_test_get_posts'] = static function () {
			return array( 101 );
		};

		( new FiaaCron() )->runScheduledAssignment();

		$this->assertSame( array(), $GLOBALS['sim_test_as_enqueued'], 'Must not enqueue a second run while one is still active.' );
		$this->assertSame( array(), $GLOBALS['sim_test_wpdb_inserted'] );
	}

	/**
	 * Disabled automation must never enqueue anything.
	 *
	 * @return void
	 */
	public function test_scheduled_run_noop_when_disabled(): void {
		$this->freshWpdb();
		$GLOBALS['sim_test_options']['smart_image_matcher_settings']['fiaa_cron_enabled'] = false;

		( new FiaaCron() )->runScheduledAssignment();

		$this->assertSame( array(), $GLOBALS['sim_test_as_enqueued'] );
	}

	/**
	 * With no candidate posts, nothing should be queued.
	 *
	 * @return void
	 */
	public function test_scheduled_run_noop_when_no_candidates(): void {
		$this->freshWpdb();
		$GLOBALS['sim_test_get_posts'] = static function () {
			return array();
		};

		( new FiaaCron() )->runScheduledAssignment();

		$this->assertSame( array(), $GLOBALS['sim_test_as_enqueued'] );
	}

	/**
	 * The new faster interval options (every_4_hours/6/8) must round-trip
	 * through the settings sanitizer, so the admin UI can actually persist
	 * them instead of being silently coerced back to 'daily'.
	 *
	 * @return void
	 */
	public function test_every_4_6_8_hour_intervals_are_accepted_by_sanitizer(): void {
		$sanitizer = new \SmartImageMatcher\Settings\Sanitizer();

		$base = array(
			'fiaa_cron_featured_filter' => 'missing',
			'fiaa_cron_post_statuses'   => 'publish',
			'fiaa_cron_post_types'      => 'post',
		);

		foreach ( array( 'every_4_hours', 'every_6_hours', 'every_8_hours', 'hourly', 'twicedaily', 'daily' ) as $interval ) {
			$sanitized = $sanitizer->sanitize( $base + array( 'fiaa_cron_interval' => $interval ) );
			$this->assertSame( $interval, $sanitized['fiaa_cron_interval'], "Interval '{$interval}' must survive sanitization unchanged." );
		}

		// An invalid value must still fall back to the safe default.
		$sanitized = $sanitizer->sanitize( $base + array( 'fiaa_cron_interval' => 'every_5_minutes' ) );
		$this->assertSame( 'daily', $sanitized['fiaa_cron_interval'] );
	}
}
