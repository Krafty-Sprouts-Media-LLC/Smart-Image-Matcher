<?php
/**
 * Unit tests for the resumable media-library backfill in ImageRepository.
 *
 * Regression coverage for the bug where backfillAll() looped over the
 * entire media library inside a single Action Scheduler execution and
 * was silently killed partway through on large libraries (5,000+ images),
 * leaving the remainder permanently unindexed and therefore unmatchable.
 *
 * @package SmartImageMatcher\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\ImageRepository;

/**
 * Class ImageRepositoryBackfillTest
 */
class ImageRepositoryBackfillTest extends TestCase {

	/**
	 * Reset the in-memory option store and get_posts stub between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sim_test_options']   = array();
		$GLOBALS['sim_test_get_posts'] = null;
	}

	/**
	 * A single batch must never fetch more than one page from the
	 * media library — this is the core regression guard: the old
	 * backfillAll() paginated internally in a do/while loop, which is
	 * exactly what caused multi-minute executions on large libraries.
	 *
	 * @return void
	 */
	public function test_backfill_batch_processes_exactly_one_page(): void {
		$calls = array();

		// Simulate a library of 350 images. Only ever return page 1's
		// worth of IDs for a given offset — if backfillBatch() looped
		// internally, it would call get_posts() more than once and we'd
		// see more than one entry in $calls.
		$GLOBALS['sim_test_get_posts'] = static function ( $args ) use ( &$calls ) {
			$calls[] = $args;
			$offset  = $args['offset'] ?? 0;
			$size    = $args['posts_per_page'] ?? 200;

			$all = range( 1, 350 );
			return array_slice( $all, $offset, $size );
		};

		$repo   = new ImageRepository();
		$result = $repo->backfillBatch( 0, 200 );

		$this->assertCount( 1, $calls, 'backfillBatch() must query get_posts() exactly once per call.' );
		$this->assertSame( 200, $result['indexed'] );
		$this->assertSame( 200, $result['next_offset'] );
		$this->assertFalse( $result['done'], 'More images remain after the first batch of 350.' );
	}

	/**
	 * When a batch returns fewer images than the requested batch size,
	 * the backfill must report itself done so callers stop re-enqueuing.
	 *
	 * @return void
	 */
	public function test_backfill_batch_reports_done_on_final_partial_page(): void {
		$GLOBALS['sim_test_get_posts'] = static function ( $args ) {
			$offset = $args['offset'] ?? 0;
			$all    = range( 1, 350 );
			return array_slice( $all, $offset, $args['posts_per_page'] ?? 200 );
		};

		$repo = new ImageRepository();

		$first  = $repo->backfillBatch( 0, 200 );
		$second = $repo->backfillBatch( $first['next_offset'], 200 );

		$this->assertSame( 150, $second['indexed'] );
		$this->assertSame( 350, $second['next_offset'] );
		$this->assertTrue( $second['done'], 'Batch smaller than requested size must mark the backfill done.' );
	}

	/**
	 * An empty library must not error and must be immediately "done".
	 *
	 * @return void
	 */
	public function test_backfill_batch_handles_empty_library(): void {
		$GLOBALS['sim_test_get_posts'] = static function () {
			return array();
		};

		$repo   = new ImageRepository();
		$result = $repo->backfillBatch( 0, 200 );

		$this->assertSame( 0, $result['indexed'] );
		$this->assertSame( 0, $result['next_offset'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * The cursor state must persist across get/save calls so an
	 * interrupted backfill can resume from where it left off instead of
	 * restarting (and re-scanning already-indexed images) from zero.
	 *
	 * @return void
	 */
	public function test_backfill_state_round_trips_through_options(): void {
		$repo = new ImageRepository();

		$initial = $repo->getBackfillState();
		$this->assertSame( 0, $initial['offset'] );
		$this->assertFalse( $initial['done'] );

		$repo->saveBackfillState( array( 'offset' => 4200, 'done' => false ) );

		$resumed = $repo->getBackfillState();
		$this->assertSame( 4200, $resumed['offset'] );
		$this->assertFalse( $resumed['done'] );

		$repo->saveBackfillState( array( 'offset' => 15000, 'done' => true ) );
		$finished = $repo->getBackfillState();
		$this->assertTrue( $finished['done'] );
	}

	/**
	 * resetBackfillState() must clear the cursor entirely so a fresh
	 * reindex (e.g. `wp sim reindex --fresh`) starts at offset 0 again.
	 *
	 * @return void
	 */
	public function test_reset_backfill_state_clears_cursor(): void {
		$repo = new ImageRepository();

		$repo->saveBackfillState( array( 'offset' => 9999, 'done' => true ) );
		$this->assertTrue( $repo->getBackfillState()['done'] );

		$repo->resetBackfillState();

		$state = $repo->getBackfillState();
		$this->assertSame( 0, $state['offset'] );
		$this->assertFalse( $state['done'] );
	}
}
