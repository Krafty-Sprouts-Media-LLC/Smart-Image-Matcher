<?php
/**
 * Unit tests for Featured Image slug scoring.
 *
 * @package SmartImageMatcher\Tests
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\FeaturedImages\SlugMapBuilder;

/**
 * Class FeaturedImageServiceTest
 */
class FeaturedImageServiceTest extends TestCase {

	/**
	 * @var FeaturedImageService
	 */
	private FeaturedImageService $service;

	/**
	 * Set up shared service instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new FeaturedImageService( new SlugMapBuilder() );
	}

	/**
	 * Invoke private selectBestSlugMatch for fixture maps.
	 *
	 * @param string             $postSlug Post slug.
	 * @param array<string, int> $slugMap  Image slug map.
	 * @return array<string, mixed>
	 */
	private function selectBest( string $postSlug, array $slugMap ): array {
		$reflection = new ReflectionClass( FeaturedImageService::class );
		$method     = $reflection->getMethod( 'selectBestSlugMatch' );
		$method->setAccessible( true );

		return $method->invoke( $this->service, $postSlug, $slugMap );
	}

	/**
	 * Exact slug matches should auto-assign.
	 *
	 * @return void
	 */
	public function test_exact_slug_auto_assigns(): void {
		$result = $this->selectBest(
			'bass-fishing-regulations-in-rhode-island',
			array(
				'bass-fishing-regulations-in-rhode-island' => 101,
			)
		);

		$this->assertTrue( $result['matched'] );
		$this->assertSame( 101, $result['attachment_id'] );
		$this->assertSame( 'exact', $result['method'] );
	}

	/**
	 * Season vs regulations overlap must not auto-assign.
	 *
	 * @return void
	 */
	public function test_distinguishing_overlap_is_held_not_assigned(): void {
		$score = $this->service->scoreSlugMatch(
			'bass-fishing-regulations-in-rhode-island',
			'bass-fishing-season-in-rhode-island'
		);

		$this->assertSame( 'held_terms', $score['method'] );
		$this->assertTrue(
			$this->service->hasDistinguishingTermConflict(
				array( 'bass', 'fishing', 'regulations', 'rhode', 'island' ),
				array( 'bass', 'fishing', 'season', 'rhode', 'island' )
			)
		);

		$result = $this->selectBest(
			'bass-fishing-regulations-in-rhode-island',
			array(
				'bass-fishing-season-in-rhode-island' => 202,
			)
		);

		$this->assertFalse( $result['matched'] );
		$this->assertTrue( ! empty( $result['held'] ) );
		$this->assertStringContainsString( 'key terms', (string) $result['reason'] );
	}

	/**
	 * Exact candidate must beat a held overlap suggestion.
	 *
	 * @return void
	 */
	public function test_exact_candidate_wins_over_overlap_suggestion(): void {
		$result = $this->selectBest(
			'bass-fishing-regulations-in-rhode-island',
			array(
				'bass-fishing-season-in-rhode-island'      => 202,
				'bass-fishing-regulations-in-rhode-island' => 303,
			)
		);

		$this->assertTrue( $result['matched'] );
		$this->assertSame( 303, $result['attachment_id'] );
		$this->assertSame( 'exact', $result['method'] );
	}

	/**
	 * Deduped image slug suffix should still auto-assign via reverse prefix.
	 *
	 * @return void
	 */
	public function test_reverse_prefix_slug_auto_assigns(): void {
		$result = $this->selectBest(
			'can-you-own-a-squirrel-in-oklahoma',
			array(
				'can-you-own-a-squirrel-in-oklahoma-2' => 404,
			)
		);

		$this->assertTrue( $result['matched'] );
		$this->assertSame( 404, $result['attachment_id'] );
		$this->assertSame( 'reverse_prefix', $result['method'] );
	}

	/**
	 * Excluded image slugs must never auto-assign, even for strong prefix matches.
	 *
	 * @return void
	 */
	public function test_excluded_image_slug_is_skipped(): void {
		$this->setExcludedSlugs( array( 'fly-fishing' ) );

		$this->assertTrue( $this->service->isExcludedImageSlug( 'fly-fishing.jpg' ) );
		$this->assertFalse(
			$this->service->isAutoAssignSafePair(
				'fly-fishing-regulations-in-west-virginia',
				'fly-fishing'
			)
		);

		$result = $this->selectBest(
			'fly-fishing-regulations-in-west-virginia',
			array(
				'fly-fishing' => 606,
			)
		);

		$this->assertFalse( $result['matched'] );
		$this->assertEmpty( $result['candidates'] ?? array() );
	}

	/**
	 * Seed the request-local excluded-slug cache for unit tests.
	 *
	 * @param string[] $slugs Normalized slugs.
	 * @return void
	 */
	private function setExcludedSlugs( array $slugs ): void {
		$reflection = new ReflectionClass( FeaturedImageService::class );
		$property   = $reflection->getProperty( 'excludedImageSlugsCache' );
		$property->setAccessible( true );
		$property->setValue( $this->service, array_values( $slugs ) );
	}
}
