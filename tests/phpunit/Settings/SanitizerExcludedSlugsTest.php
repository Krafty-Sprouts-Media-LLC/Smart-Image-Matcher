<?php
/**
 * Unit tests for FIAA excluded image slug sanitization.
 *
 * @package SmartImageMatcher\Tests\Settings
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Settings;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Settings\Sanitizer;

/**
 * Class SanitizerExcludedSlugsTest
 */
class SanitizerExcludedSlugsTest extends TestCase {

	/**
	 * @var Sanitizer
	 */
	private Sanitizer $sanitizer;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sanitizer = new Sanitizer();
	}

	/**
	 * @return void
	 */
	public function test_normalizes_filenames_and_dedupes(): void {
		$result = $this->sanitizer->excludedImageSlugs(
			"fly-fishing.jpg\nFly Fishing\nfly-fishing, bass-fishing.png"
		);

		$this->assertSame( "fly-fishing\nbass-fishing", $result );
	}

	/**
	 * Full media URLs keep only the filename slug.
	 *
	 * @return void
	 */
	public function test_full_url_uses_basename_case_insensitively(): void {
		$result = $this->sanitizer->excludedImageSlugs(
			'https://animalofthings.com/wp-content/uploads/2025/11/Types-of-Sparrows.jpg'
		);

		$this->assertSame( 'types-of-sparrows', $result );
	}

	/**
	 * WordPress scaled sizes in a URL still match the original filename.
	 *
	 * @return void
	 */
	public function test_sized_filename_strips_pixel_suffix(): void {
		$this->assertSame(
			'types-of-sparrows',
			$this->sanitizer->normalizeImageSlug( 'Types-of-Sparrows-1024x768.jpg' )
		);
	}

	/**
	 * @return void
	 */
	public function test_is_excluded_image_slug_is_case_insensitive(): void {
		$GLOBALS['sim_test_options']['smart_image_matcher_settings'] = array(
			'fiaa_excluded_image_slugs' => 'types-of-sparrows',
		);

		$this->assertTrue( $this->sanitizer->isExcludedImageSlug( 'Types-of-Sparrows.jpg' ) );
		$this->assertTrue(
			$this->sanitizer->isExcludedImageSlug(
				'https://animalofthings.com/wp-content/uploads/2025/11/Types-of-Sparrows.jpg'
			)
		);
		$this->assertFalse( $this->sanitizer->isExcludedImageSlug( 'american-goldfinch.jpg' ) );

		unset( $GLOBALS['sim_test_options']['smart_image_matcher_settings'] );
	}

	/**
	 * @return void
	 */
	public function test_add_excluded_image_slug_appends_and_dedupes(): void {
		$GLOBALS['sim_test_options']['smart_image_matcher_settings'] = array(
			'fiaa_excluded_image_slugs' => 'fly-fishing',
		);

		$result = $this->sanitizer->addExcludedImageSlug(
			'https://animalofthings.com/wp-content/uploads/2025/11/Types-of-Sparrows.jpg'
		);

		$this->assertSame( "fly-fishing\ntypes-of-sparrows", $result );
		$this->assertSame(
			'fly-fishing',
			$this->sanitizer->addExcludedImageSlug( 'Fly-Fishing.jpg' )
		);

		unset( $GLOBALS['sim_test_options']['smart_image_matcher_settings'] );
	}

	/**
	 * @return void
	 */
	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame( '', $this->sanitizer->excludedImageSlugs( '   ' ) );
	}
}
