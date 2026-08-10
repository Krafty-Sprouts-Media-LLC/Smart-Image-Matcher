<?php
/**
 * Unit tests for Queue's index-backfill scheduling, self-healing resume,
 * and legacy Action Scheduler hook cleanup.
 *
 * Regression coverage for:
 *   - the backfill never being resumed after Action Scheduler killed a
 *     long-running unchunked batch (partial index, no retry), and
 *   - recurring actions left orphaned under pre-rename `sim_*` hook names
 *     failing forever with "no callbacks registered".
 *
 * @package SmartImageMatcher\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Queue\Queue;

/**
 * Class QueueBackfillTest
 */
class QueueBackfillTest extends TestCase {

	/**
	 * Reset all test globals between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sim_test_options']         = array();
		$GLOBALS['sim_test_as_enqueued']     = array();
		$GLOBALS['sim_test_as_scheduled']    = array();
		$GLOBALS['sim_test_as_unscheduled']  = array();
		$GLOBALS['sim_test_wp_cron_cleared'] = array();
		$GLOBALS['sim_test_as_has_scheduled'] = static fn() => false;
	}

	/**
	 * If the backfill previously completed, maybeResumeIndexBackfill()
	 * must be a no-op — it should never re-enqueue a finished backfill.
	 *
	 * @return void
	 */
	public function test_resume_is_noop_when_backfill_already_done(): void {
		( new ImageRepository() )->saveBackfillState( array( 'offset' => 15000, 'done' => true ) );

		( new Queue() )->maybeResumeIndexBackfill();

		$this->assertSame( array(), $GLOBALS['sim_test_as_enqueued'], 'A completed backfill must not be re-enqueued.' );
	}

	/**
	 * If the backfill is incomplete but Action Scheduler already has a
	 * pending/in-progress action for it, do not double-enqueue.
	 *
	 * @return void
	 */
	public function test_resume_is_noop_when_action_already_pending(): void {
		( new ImageRepository() )->saveBackfillState( array( 'offset' => 4000, 'done' => false ) );
		$GLOBALS['sim_test_as_has_scheduled'] = static fn() => true;

		( new Queue() )->maybeResumeIndexBackfill();

		$this->assertSame( array(), $GLOBALS['sim_test_as_enqueued'], 'Must not double-enqueue an already-pending backfill action.' );
	}

	/**
	 * This is the core self-healing regression guard: an incomplete
	 * backfill with no pending action (e.g. the previous run was killed
	 * by a timeout, or was orphaned by a hook rename) must be re-enqueued
	 * so the rest of the media library eventually gets indexed.
	 *
	 * @return void
	 */
	public function test_resume_reenqueues_incomplete_backfill_with_no_pending_action(): void {
		( new ImageRepository() )->saveBackfillState( array( 'offset' => 4000, 'done' => false ) );
		$GLOBALS['sim_test_as_has_scheduled'] = static fn() => false;

		( new Queue() )->maybeResumeIndexBackfill();

		$this->assertCount( 1, $GLOBALS['sim_test_as_enqueued'] );
		$this->assertSame( Queue::HOOK_INDEX_BACKFILL, $GLOBALS['sim_test_as_enqueued'][0]['hook'] );
	}

	/**
	 * scheduleIndexBackfill() must not schedule a second run once the
	 * cursor state reports completion (covers the activation path being
	 * hit again, e.g. on a re-activation after the library is fully
	 * indexed).
	 *
	 * @return void
	 */
	public function test_schedule_index_backfill_skips_when_already_done(): void {
		( new ImageRepository() )->saveBackfillState( array( 'offset' => 15000, 'done' => true ) );

		( new Queue() )->scheduleIndexBackfill();

		$this->assertSame( array(), $GLOBALS['sim_test_as_scheduled'] );
		$this->assertSame( array(), $GLOBALS['sim_test_as_enqueued'] );
	}

	/**
	 * clearLegacyHooks() must unschedule every hook passed in, covering
	 * the pre-rename sim_* Action Scheduler hooks and any WP-Cron
	 * schedule under the same name.
	 *
	 * @return void
	 */
	public function test_clear_legacy_hooks_unschedules_each_hook(): void {
		Queue::clearLegacyHooks( array( 'sim_queue_index_backfill', 'sim_fiaa_scheduled_run' ) );

		$unscheduledHooks = array_column( $GLOBALS['sim_test_as_unscheduled'], 'hook' );

		$this->assertContains( 'sim_queue_index_backfill', $unscheduledHooks );
		$this->assertContains( 'sim_fiaa_scheduled_run', $unscheduledHooks );
		$this->assertContains( 'sim_queue_index_backfill', $GLOBALS['sim_test_wp_cron_cleared'] );
		$this->assertContains( 'sim_fiaa_scheduled_run', $GLOBALS['sim_test_wp_cron_cleared'] );
	}
}
