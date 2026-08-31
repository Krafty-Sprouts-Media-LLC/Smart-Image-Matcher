<?php
/**
 * Unit tests for featured-image slug scoring.
 *
 * @package SmartImageMatcher\Tests\FeaturedImages
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\FeaturedImages;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\FeaturedImages\SlugMapBuilder;

/**
 * Class FeaturedImageServiceTest
 */
class FeaturedImageServiceTest extends TestCase {

	private FeaturedImageService $service;

	protected function setUp(): void {
		$this->service = new FeaturedImageService( new SlugMapBuilder() );
	}

	public function testExactSlugMatchScoresOneHundred(): void {
		$result = $this->service->scoreSlugMatch(
			'avian-flu-regulations-in-wyoming',
			'avian-flu-regulations-in-wyoming'
		);

		$this->assertSame( 100, $result['score'] );
		$this->assertSame( 'exact', $result['method'] );
	}

	public function testImageSlugPrefixOfLongArticleSlugScoresHigh(): void {
		$result = $this->service->scoreSlugMatch(
			'avian-flu-regulations-in-wyoming-what-every-poultry-owner-must-know',
			'Avian-flu-regulations-in-Wyoming.jpg'
		);

		$this->assertSame( 96, $result['score'] );
		$this->assertSame( 'prefix', $result['method'] );
	}

	public function testGenericSingleWordSlugDoesNotAutoWinByTokenOverlap(): void {
		$result = $this->service->scoreSlugMatch(
			'avian-flu-regulations-in-wyoming',
			'avian'
		);

		$this->assertSame( 0, $result['score'] );
		$this->assertSame( 'too_few_terms', $result['method'] );
	}

	public function testListicleTitleDoesNotSlugMatchShortImageFilename(): void {
		$result = $this->service->scoreSlugMatch(
			'the-2-types-of-eagles-in-georgia-how-to-spot-bald-and-golden-eagles',
			'bald-eagle'
		);

		$this->assertSame( 0, $result['score'] );
		$this->assertSame( 'too_few_terms', $result['method'] );
	}

	public function testTokenOverlapCanMatchNonPrefixSlug(): void {
		$result = $this->service->scoreSlugMatch(
			'what-poultry-owners-need-to-know-about-avian-flu-regulations-in-wyoming',
			'avian-flu-regulations-wyoming'
		);

		$this->assertGreaterThanOrEqual( 70, $result['score'] );
		$this->assertSame( 'token_overlap', $result['method'] );
	}

	public function testWpDuplicateSuffixOnFeaturedFileIsStillExact(): void {
		$result = $this->service->scoreSlugMatch(
			'avian-flu-regulations-in-wyoming',
			'avian-flu-regulations-in-wyoming-2'
		);

		$this->assertSame( 100, $result['score'] );
		$this->assertSame( 'exact', $result['method'] );
	}

	public function testScaledCopySuffixOnFeaturedFileIsStillExact(): void {
		$result = $this->service->scoreSlugMatch(
			'avian-flu-regulations-in-wyoming',
			'avian-flu-regulations-in-wyoming-scaled-1.jpg'
		);

		$this->assertSame( 100, $result['score'] );
		$this->assertSame( 'exact', $result['method'] );
	}

	public function testPrimaryKeywordPrefixStillScoresHigh(): void {
		$result = $this->service->scoreSlugMatch(
			'bald-eagle-in-georgia-how-to-spot-them',
			'bald-eagle.jpg'
		);

		$this->assertSame( 96, $result['score'] );
		$this->assertSame( 'prefix', $result['method'] );
	}
}
