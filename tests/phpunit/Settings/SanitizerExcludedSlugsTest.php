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
	 * @return void
	 */
	public function test_empty_input_returns_empty_string(): void {
		$this->assertSame( '', $this->sanitizer->excludedImageSlugs( '   ' ) );
	}
}
