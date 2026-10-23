<?php
/**
 * Regression: posts whose stored featured image is a site-wide placeholder
 * (KSM Extensions fallback) must be selected by "missing featured image" runs.
 *
 * @package SmartImageMatcher\Tests\FeaturedImages
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\FeaturedImages;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\FeaturedImages\FeaturedImageService;

/**
 * Class MissingFeaturedMetaQueryTest
 */
class MissingFeaturedMetaQueryTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['sim_test_options']['ksm_extensions_featured_image_manager'] );
	}

	public function testPlaceholderIdIsTreatedAsMissing(): void {
		$GLOBALS['sim_test_options']['ksm_extensions_featured_image_manager'] = array( 'fallback_image_id' => 55 );

		$query = FeaturedImageService::missingFeaturedMetaQuery();

		$this->assertSame( 'OR', $query['relation'] );
		$this->assertContains(
			array(
				'key'     => '_thumbnail_id',
				'value'   => array( 55 ),
				'compare' => 'IN',
				'type'    => 'NUMERIC',
			),
			$query
		);
	}

	public function testNoPlaceholderKeepsBaseClausesOnly(): void {
		$query = FeaturedImageService::missingFeaturedMetaQuery();

		$this->assertCount( 3, $query );
		$this->assertSame( 'NOT EXISTS', $query[0]['compare'] );
		$this->assertSame( '<=', $query[1]['compare'] );
	}
}
