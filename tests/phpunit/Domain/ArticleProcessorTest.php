<?php
/**
 * Unit tests for ArticleProcessor decision wiring.
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.3.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\ArticleProcessor;
use SmartImageMatcher\Domain\FeaturedMatchGate;
use SmartImageMatcher\Domain\GenerationFallback;
use SmartImageMatcher\Domain\HeadingMatchGate;
use SmartImageMatcher\Domain\HeadingExtractor;
use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Domain\Matcher;
use SmartImageMatcher\Domain\MatchRepository;
use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\Insertion\InsertionService;
use SmartImageMatcher\Settings\Settings;

/**
 * Class ArticleProcessorTest
 *
 * @since 3.3.0
 */
class ArticleProcessorTest extends TestCase {

	/**
	 * Heading fixture used by every case.
	 *
	 * @var array<string, mixed>
	 */
	private array $heading;

	/**
	 * Reset options and get_post between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->heading = array(
			'heading_hash' => 'hash-goldfinch',
			'text'         => 'American Goldfinch',
			'tag'          => 'h2',
			'level'        => 2,
		);
		$GLOBALS['sim_test_options'][ Settings::OPTION ] = array(
			'auto_insert_threshold' => 90,
			'confidence_threshold'  => 70,
			'hierarchy_mode'        => 'all',
		);
		$post               = new \WP_Post();
		$post->ID           = 10;
		$post->post_content = '<!-- wp:heading --><h2>American Goldfinch</h2><!-- /wp:heading -->';
		$post->post_title   = 'Birds';
		$post->post_excerpt = '';
		$post->post_type    = 'post';
		$GLOBALS['sim_test_get_post'] = static function () use ( $post ) {
			return $post;
		};
	}

	/**
	 * A 100% library hit inserts and never enqueues generation.
	 *
	 * @return void
	 */
	public function test_high_score_heading_inserts_and_does_not_generate(): void {
		$generation = $this->recordingFallback( true );
		$matches    = $this->createMock( MatchRepository::class );
		$matches->expects( $this->once() )->method( 'markInserted' )->with( 10, 42, 'hash-goldfinch' );
		$matches->expects( $this->never() )->method( 'upsertPending' );
		$processor = $this->processor( 100, 42, false, $generation, $matches );

		$result = $processor->process( 10 );

		$this->assertSame( 1, $result['inserted'] );
		$this->assertSame( 0, $result['review'] );
		$this->assertSame( 0, $result['generated'] );
		$this->assertSame( array(), $generation->enqueued );
	}

	/**
	 * Middle-band scores write pending review only.
	 *
	 * @return void
	 */
	public function test_middle_band_writes_pending_only(): void {
		$generation = $this->recordingFallback( true );
		$matches    = $this->createMock( MatchRepository::class );
		$matches->expects( $this->once() )->method( 'upsertPending' );
		$processor = $this->processor( 80, 42, false, $generation, $matches );

		$result = $processor->process( 10 );

		$this->assertSame( 0, $result['inserted'] );
		$this->assertSame( 1, $result['review'] );
		$this->assertSame( array(), $generation->enqueued );
	}

	/**
	 * Skip-band with no adapter skips and does not enqueue.
	 *
	 * @return void
	 */
	public function test_skip_band_without_adapter_skips(): void {
		$processor = $this->processor( 0, 0, false, null );

		$result = $processor->process( 10 );

		$this->assertGreaterThanOrEqual( 1, $result['skipped'] );
		$this->assertSame( 0, $result['generated'] );
		$this->assertSame( 0, $result['inserted'] );
	}

	/**
	 * Skip-band with an available adapter enqueues one generation job.
	 *
	 * @return void
	 */
	public function test_skip_band_with_adapter_enqueues_once(): void {
		$generation = $this->recordingFallback( true );
		$processor  = $this->processor( 0, 0, false, $generation );

		$result = $processor->process( 10 );

		$this->assertSame( 1, $result['generated'] );
		$this->assertCount( 1, $generation->enqueued );
		$this->assertSame( 'hash-goldfinch', $generation->enqueued[0]['heading_hash'] );
	}

	/**
	 * Headings that already have a following image are skipped.
	 *
	 * @return void
	 */
	public function test_heading_with_existing_image_is_skipped(): void {
		$generation = $this->recordingFallback( true );
		$matches    = $this->createMock( MatchRepository::class );
		$matches->expects( $this->atLeastOnce() )->method( 'clearPendingForHeading' )->with(
			$this->equalTo( 10 ),
			$this->logicalOr( $this->equalTo( 'hash-goldfinch' ), $this->equalTo( 'featured' ) )
		);
		$processor  = $this->processor( 100, 42, true, $generation, $matches );

		$result = $processor->process( 10 );

		$this->assertSame( 0, $result['inserted'] );
		$this->assertGreaterThanOrEqual( 1, $result['skipped'] );
		$this->assertSame( array(), $generation->enqueued );
	}

	/**
	 * An available AI gate that scores 0 must not insert a 100% keyword hit.
	 *
	 * @return void
	 */
	public function test_ai_gate_blocks_keyword_auto_insert_when_model_rejects(): void {
		$generation = $this->recordingFallback( true );
		$matches    = $this->createMock( MatchRepository::class );
		$matches->expects( $this->never() )->method( 'markInserted' );
		$gate       = $this->recordingHeadingGate( true, 0, 0 );
		$processor  = $this->processor( 100, 42, false, $generation, $matches, $gate );

		$result = $processor->process( 10 );

		$this->assertSame( 0, $result['inserted'] );
		$this->assertSame( 1, $result['generated'] );
	}

	/**
	 * Adapter that reports unavailable must not be called.
	 *
	 * @return void
	 */
	public function test_ai_featured_gate_blocks_slug_auto_assign_when_model_rejects(): void {
		$generation = $this->recordingFallback( true );
		$matches    = $this->createMock( MatchRepository::class );
		$matches->expects( $this->never() )->method( 'markInserted' );
		$gate       = $this->recordingFeaturedGate( true, 0, 0 );
		$processor  = $this->processor( 0, 0, true, $generation, $matches, null, $gate, true );

		$result = $processor->process( 10 );

		$this->assertSame( 0, $result['inserted'] );
		$this->assertSame( 1, $result['generated'] );
		$this->assertCount( 1, $generation->enqueued );
		$this->assertSame( 'featured', $generation->enqueued[0]['heading_hash'] );
	}

	/**
	 * Adapter that reports unavailable must not be called.
	 *
	 * @return void
	 */
	public function test_unavailable_adapter_never_enqueues(): void {
		$generation = $this->recordingFallback( false );
		$processor  = $this->processor( 0, 0, false, $generation );

		$result = $processor->process( 10 );

		$this->assertSame( 0, $result['generated'] );
		$this->assertSame( array(), $generation->enqueued );
	}

	/**
	 * Build a processor with fakes.
	 *
	 * @param int                     $score              Forced heading score.
	 * @param int                     $image_id           Best image id.
	 * @param bool                    $already_has_image  Heading already has an image.
	 * @param GenerationFallback|null $generation         Optional adapter.
	 * @param MatchRepository|null    $matches            Optional match repo mock.
	 * @param HeadingMatchGate|null   $heading_match      Optional AI heading gate.
	 * @param FeaturedMatchGate|null  $featured_match     Optional AI featured gate.
	 * @param bool                    $needs_featured     Whether the featured slot is open.
	 * @return ArticleProcessor
	 */
	private function processor( int $score, int $image_id, bool $already_has_image, ?GenerationFallback $generation, ?MatchRepository $matches = null, ?HeadingMatchGate $heading_match = null, ?FeaturedMatchGate $featured_match = null, bool $needs_featured = false ): ArticleProcessor {
		$extractor = $this->createMock( HeadingExtractor::class );
		$extractor->method( 'extract' )->willReturn( array( $this->heading ) );

		$matcher = $this->createMock( Matcher::class );
		$matcher->method( 'filterByHierarchy' )->willReturnCallback(
			static function ( array $headings ) {
				return $headings;
			}
		);
		$matcher->method( 'extractKeywords' )->willReturn( array( 'goldfinch' ) );
		$matcher->method( 'calculateScore' )->willReturn( $score );

		$images = $this->createMock( ImageRepository::class );
		$images->method( 'findCandidates' )->willReturn(
			$image_id > 0 || $score > 0
				? array( array( 'id' => max( 1, $image_id ) ) )
				: array()
		);

		$insertion = $this->createMock( InsertionService::class );
		$insertion->method( 'headingHasFollowingImage' )->willReturn( $already_has_image );
		$insertion->method( 'bulkInsert' )->willReturn( true );

		if ( null === $matches ) {
			$matches = $this->createMock( MatchRepository::class );
			$matches->method( 'upsertPending' );
		}

		$featured = $this->createMock( FeaturedImageService::class );
		$featured->method( 'needsFeaturedImage' )->willReturn( $needs_featured );
		$featured->expects( $needs_featured && null !== $featured_match && $featured_match->isAvailable() ? $this->never() : $this->any() )
			->method( 'scoreBestForPost' )
			->willReturn( array( 'score' => 100, 'attachment_id' => 99 ) );

		return new ArticleProcessor( $matcher, $images, $extractor, $insertion, $matches, $featured, $generation, $heading_match, $featured_match );
	}

	/**
	 * Recording featured match gate.
	 *
	 * @param bool $available Whether the gate replaces slug scores.
	 * @param int  $score     Forced score.
	 * @param int  $image_id  Forced image id.
	 * @return FeaturedMatchGate
	 */
	private function recordingFeaturedGate( bool $available, int $score, int $image_id ): FeaturedMatchGate {
		return new class( $available, $score, $image_id ) implements FeaturedMatchGate {
			private bool $available;
			private int $score;
			private int $image_id;

			public function __construct( bool $available, int $score, int $image_id ) {
				$this->available = $available;
				$this->score     = $score;
				$this->image_id  = $image_id;
			}

			public function isAvailable(): bool {
				return $this->available;
			}

			public function bestMatch( \WP_Post $post ): array {
				unset( $post );
				return array(
					'score'    => $this->score,
					'image_id' => $this->image_id,
				);
			}
		};
	}

	/**
	 * Recording heading match gate.
	 *
	 * @param bool $available Whether the gate replaces keyword scores.
	 * @param int  $score     Forced score.
	 * @param int  $image_id  Forced image id.
	 * @return HeadingMatchGate
	 */
	private function recordingHeadingGate( bool $available, int $score, int $image_id ): HeadingMatchGate {
		return new class( $available, $score, $image_id ) implements HeadingMatchGate {
			private bool $available;
			private int $score;
			private int $image_id;

			public function __construct( bool $available, int $score, int $image_id ) {
				$this->available = $available;
				$this->score     = $score;
				$this->image_id  = $image_id;
			}

			public function isAvailable(): bool {
				return $this->available;
			}

			public function bestMatch( array $heading ): array {
				unset( $heading );
				return array(
					'score'    => $this->score,
					'image_id' => $this->image_id,
				);
			}
		};
	}

	/**
	 * Recording generation adapter.
	 *
	 * @param bool $available Whether enqueue should be offered.
	 * @return GenerationFallback
	 */
	private function recordingFallback( bool $available ): GenerationFallback {
		return new class( $available ) implements GenerationFallback {
			public array $enqueued = array();
			private bool $available;

			public function __construct( bool $available ) {
				$this->available = $available;
			}

			public function isAvailable(): bool {
				return $this->available;
			}

			public function enqueue( int $post_id, string $heading_hash, string $heading_text, string $section_text ): bool {
				if ( ! $this->available ) {
					return false;
				}
				$this->enqueued[] = array(
					'post_id'      => $post_id,
					'heading_hash' => $heading_hash,
					'heading_text' => $heading_text,
					'section_text' => $section_text,
				);
				return true;
			}
		};
	}
}
