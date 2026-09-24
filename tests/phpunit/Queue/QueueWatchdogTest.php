<?php
/**
 * Unit tests for QueueWatchdog decisions.
 *
 * @package SmartImageMatcher\Tests\Queue
 * @since   3.5.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Queue;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Queue\QueueWatchdog;
use SmartImageMatcher\Settings\Settings;

/**
 * Class QueueWatchdogTest
 *
 * @since 3.5.0
 */
class QueueWatchdogTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['sim_test_options'][ Settings::RUNTIME_OPTION ] );
	}

	/** @test */
	public function job_with_nothing_queued_and_no_progress_for_45_min_is_closed(): void {
		$now = 1_000_000;
		$this->assertTrue( QueueWatchdog::shouldCloseJob( $now, $now - 46 * 60, 0 ) );
	}

	/** @test */
	public function job_with_articles_still_queued_is_never_closed(): void {
		$now = 1_000_000;
		$this->assertFalse( QueueWatchdog::shouldCloseJob( $now, $now - 31 * 3600, 163 ) );
	}

	/** @test */
	public function recently_active_job_is_kept(): void {
		$now = 1_000_000;
		$this->assertFalse( QueueWatchdog::shouldCloseJob( $now, $now - 30 * 60, 0 ) );
	}

	/** @test */
	public function ticks_are_newest_first_and_capped(): void {
		for ( $i = 1; $i <= 15; $i++ ) {
			QueueWatchdog::recordTick( 'skipped', 'tick ' . $i );
		}

		$runtime = get_option( Settings::RUNTIME_OPTION, array() );
		$this->assertCount( QueueWatchdog::MAX_TICKS, $runtime['ticks'] );
		$this->assertSame( 'tick 15', $runtime['ticks'][0]['detail'] );
		$this->assertSame( 'skipped', $runtime['ticks'][0]['outcome'] );
	}

	/** @test */
	public function recording_a_tick_keeps_other_runtime_data(): void {
		update_option( Settings::RUNTIME_OPTION, array( 'recent_errors' => array( array( 'message' => 'x' ) ) ) );

		QueueWatchdog::recordTick( 'queued', 'Queued 163 articles' );

		$runtime = get_option( Settings::RUNTIME_OPTION, array() );
		$this->assertSame( 'x', $runtime['recent_errors'][0]['message'] );
		$this->assertSame( 'Queued 163 articles', $runtime['ticks'][0]['detail'] );
	}
}
