<?php
/**
 * Unit tests for bulk review-queue image preview URLs.
 *
 * @package SmartImageMatcher\Tests\REST
 * @since   3.2.30
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\REST;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\REST\BulkController;

/**
 * Class BulkControllerMatchPreviewTest
 *
 * @since 3.2.30
 */
class BulkControllerMatchPreviewTest extends TestCase {

	/**
	 * Reset attachment URL stubs between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['sim_test_attachment_image_url'] = null;
		$GLOBALS['sim_test_attachment_url']       = null;
		$GLOBALS['sim_test_attached_file']        = null;
		$GLOBALS['sim_test_get_post']             = null;
	}

	/**
	 * Review-queue thumbnails must be real media files, not the wp/v2/media JSON endpoint.
	 *
	 * @return void
	 */
	public function test_attach_image_urls_uses_attachment_preview_not_rest_json(): void {
		$GLOBALS['sim_test_attachment_image_url'] = static function ( $id, $size ) {
			unset( $size );
			return 'https://example.com/wp-content/uploads/american-goldfinch-' . (int) $id . '.jpg';
		};

		$rows = BulkController::attachImageUrls(
			array(
				array(
					'id'           => 1,
					'image_id'     => 42,
					'heading_text' => 'American Goldfinch',
				),
			)
		);

		$this->assertSame(
			'https://example.com/wp-content/uploads/american-goldfinch-42.jpg',
			$rows[0]['image_url']
		);
		$this->assertStringNotContainsString( 'wp-json', $rows[0]['image_url'] );
		$this->assertStringNotContainsString( 'source_url', $rows[0]['image_url'] );
	}

	/**
	 * Fall back to the full attachment URL when no sized preview exists.
	 *
	 * @return void
	 */
	public function test_attach_image_urls_falls_back_to_full_attachment_url(): void {
		$GLOBALS['sim_test_attachment_image_url'] = static function () {
			return false;
		};
		$GLOBALS['sim_test_attachment_url']       = static function ( $id ) {
			return 'https://example.com/wp-content/uploads/full-' . (int) $id . '.png';
		};

		$rows = BulkController::attachImageUrls(
			array(
				array(
					'id'       => 2,
					'image_id' => 99,
				),
			)
		);

		$this->assertSame(
			'https://example.com/wp-content/uploads/full-99.png',
			$rows[0]['image_url']
		);
	}

	/**
	 * Missing or zero image IDs must not invent a URL.
	 *
	 * @return void
	 */
	public function test_attach_image_urls_leaves_empty_string_when_no_image(): void {
		$rows = BulkController::attachImageUrls(
			array(
				array(
					'id'       => 3,
					'image_id' => 0,
				),
			)
		);

		$this->assertSame( '', $rows[0]['image_url'] );
		$this->assertSame( '', $rows[0]['image_full'] );
		$this->assertSame( '', $rows[0]['image_file'] );
	}

	/**
	 * Review rows expose the attached filename (or URL basename) for the UI.
	 *
	 * @return void
	 */
	public function test_attach_image_urls_sets_image_file_label(): void {
		$GLOBALS['sim_test_attachment_image_url'] = static function ( $id, $size ) {
			unset( $size );
			return 'https://example.com/wp-content/uploads/preview-' . (int) $id . '.jpg';
		};
		$GLOBALS['sim_test_attached_file']        = static function ( $id ) {
			return '/var/www/uploads/norwegian-forest-cat-' . (int) $id . '.jpg';
		};

		$rows = BulkController::attachImageUrls(
			array(
				array(
					'id'       => 5,
					'image_id' => 12,
				),
			)
		);

		$this->assertSame( 'norwegian-forest-cat-12.jpg', $rows[0]['image_file'] );
	}

	/**
	 * Modal preview uses a large attachment size when available.
	 *
	 * @return void
	 */
	public function test_attach_image_urls_sets_large_preview_for_modal(): void {
		$GLOBALS['sim_test_attachment_image_url'] = static function ( $id, $size ) {
			return 'https://example.com/wp-content/uploads/' . $size . '-' . (int) $id . '.jpg';
		};

		$rows = BulkController::attachImageUrls(
			array(
				array(
					'id'       => 4,
					'image_id' => 7,
				),
			)
		);

		$this->assertSame(
			'https://example.com/wp-content/uploads/medium-7.jpg',
			$rows[0]['image_url']
		);
		$this->assertSame(
			'https://example.com/wp-content/uploads/large-7.jpg',
			$rows[0]['image_full']
		);
	}
}
