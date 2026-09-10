<?php
/**
 * Unit tests for InsertionService.
 *
 * Uses WP_UnitTestCase (needs the WP test environment) for the post/attachment
 * factory functions.  Pure logic tests (hash determinism, block structure)
 * use PHPUnit\Framework\TestCase and require no DB.
 *
 * @package SmartImageMatcher\Tests\Insertion
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Insertion;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Insertion\BlockBuilder;
use SmartImageMatcher\Insertion\HeadingLocator;
use SmartImageMatcher\Insertion\InsertionService;

/**
 * Class InsertionServiceTest
 *
 * @since 3.0.0
 */
class InsertionServiceTest extends TestCase {

	private InsertionService $service;

	protected function setUp(): void {
		$this->service = new InsertionService( new BlockBuilder() );
	}

	// -------------------------------------------------------------------------
	// BlockBuilder contract tests (no DB needed)
	// -------------------------------------------------------------------------

	/** @test */
	public function block_builder_produces_correct_structure(): void {
		$builder = new BlockBuilder();
		$block   = $builder->build( 42 );

		$this->assertEquals( 'core/image', $block['blockName'] );
		$this->assertEquals( 42, $block['attrs']['id'] );
		$this->assertEquals( 'large', $block['attrs']['sizeSlug'] );
		$this->assertEquals( 'none', $block['attrs']['linkDestination'] );
		$this->assertArrayNotHasKey( 'width', $block['attrs'] );
		$this->assertArrayNotHasKey( 'height', $block['attrs'] );
	}

	/** @test */
	public function img_tag_has_no_width_or_height(): void {
		$builder = new BlockBuilder();
		$block   = $builder->build( 42 );
		$html    = $block['innerHTML'];

		$this->assertStringNotContainsString( 'width=', $html );
		$this->assertStringNotContainsString( 'height=', $html );
	}

	// -------------------------------------------------------------------------
	// HeadingLocator contract tests (no DB needed)
	// -------------------------------------------------------------------------

	/** @test */
	public function locator_finds_first_heading_in_flat_block_list(): void {
		$locator = new HeadingLocator();

		$blocks = array(
			array( 'blockName' => 'core/paragraph', 'innerHTML' => '<p>Intro</p>', 'attrs' => array(), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/heading',   'innerHTML' => '<h2>Black Swallowtail</h2>', 'attrs' => array( 'level' => 2 ), 'innerBlocks' => array() ),
			array( 'blockName' => 'core/paragraph', 'innerHTML' => '<p>Body.</p>', 'attrs' => array(), 'innerBlocks' => array() ),
		);

		$hash  = HeadingLocator::computeHash( 2, 'black swallowtail', 0 );
		$index = $locator->findIndex( $hash, $blocks );

		$this->assertEquals( 1, $index, 'Should return array index 1' );
	}

	/** @test */
	public function locator_returns_null_for_unknown_hash(): void {
		$locator = new HeadingLocator();
		$blocks  = array(
			array( 'blockName' => 'core/paragraph', 'innerHTML' => '<p>Only paragraph.</p>', 'attrs' => array(), 'innerBlocks' => array() ),
		);

		$this->assertNull( $locator->findIndex( sha1( 'nonexistent:hash' ), $blocks ) );
	}

	/** @test */
	public function repeated_headings_have_distinct_hashes(): void {
		$hash0 = HeadingLocator::computeHash( 2, 'introduction', 0 );
		$hash1 = HeadingLocator::computeHash( 2, 'introduction', 1 );

		$this->assertNotEquals( $hash0, $hash1 );
	}

	// -------------------------------------------------------------------------
	// InsertionService — empty insertions
	// -------------------------------------------------------------------------

	/** @test */
	public function bulk_insert_with_no_insertions_returns_error(): void {
		$result = $this->service->bulkInsert( 1, array() );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/** @test */
	public function classic_heading_reports_following_img(): void {
		$hash = HeadingLocator::computeHash( 2, 'american goldfinch', 0 );
		$post = new \WP_Post();
		$post->ID = 11;
		$post->post_content = '<h2>American Goldfinch</h2><img src="goldfinch.jpg" alt="" />';
		$GLOBALS['sim_test_get_post'] = static function () use ( $post ) {
			return $post;
		};

		$this->assertTrue( $this->service->headingHasFollowingImage( 11, $hash ) );
	}

	/** @test */
	public function classic_heading_without_image_is_false(): void {
		$hash = HeadingLocator::computeHash( 2, 'american goldfinch', 0 );
		$post = new \WP_Post();
		$post->ID = 12;
		$post->post_content = '<h2>American Goldfinch</h2><p>Diet and range.</p>';
		$GLOBALS['sim_test_get_post'] = static function () use ( $post ) {
			return $post;
		};

		$this->assertFalse( $this->service->headingHasFollowingImage( 12, $hash ) );
	}

	/** @test */
	public function gutenberg_heading_reports_following_image_block(): void {
		$hash = HeadingLocator::computeHash( 2, 'american goldfinch', 0 );
		$post = new \WP_Post();
		$post->ID = 13;
		$post->post_content = '<!-- wp:heading --><h2>American Goldfinch</h2><!-- /wp:heading -->';
		$GLOBALS['sim_test_get_post'] = static function () use ( $post ) {
			return $post;
		};
		$GLOBALS['sim_test_parse_blocks'] = static function () {
			return array(
				array(
					'blockName'   => 'core/heading',
					'innerHTML'   => '<h2>American Goldfinch</h2>',
					'attrs'       => array( 'level' => 2 ),
					'innerBlocks' => array(),
				),
				array(
					'blockName'   => 'core/image',
					'innerHTML'   => '<figure><img src="x.jpg" /></figure>',
					'attrs'       => array( 'id' => 42 ),
					'innerBlocks' => array(),
				),
			);
		};

		$this->assertTrue( $this->service->headingHasFollowingImage( 13, $hash ) );
		unset( $GLOBALS['sim_test_parse_blocks'] );
	}

	/** @test */
	public function gutenberg_heading_reports_image_after_empty_paragraph(): void {
		$hash = HeadingLocator::computeHash( 2, 'bald eagle', 0 );
		$post = new \WP_Post();
		$post->ID = 14;
		$post->post_content = '<!-- wp:heading --><h2>Bald Eagle</h2><!-- /wp:heading -->';
		$GLOBALS['sim_test_get_post'] = static function () use ( $post ) {
			return $post;
		};
		$GLOBALS['sim_test_parse_blocks'] = static function () {
			return array(
				array(
					'blockName'   => 'core/heading',
					'innerHTML'   => '<h2>Bald Eagle</h2>',
					'attrs'       => array( 'level' => 2 ),
					'innerBlocks' => array(),
				),
				array(
					'blockName'   => 'core/paragraph',
					'innerHTML'   => '<p></p>',
					'attrs'       => array(),
					'innerBlocks' => array(),
				),
				array(
					'blockName'   => 'core/image',
					'innerHTML'   => '<figure><img src="bald-eagle.jpg" /></figure>',
					'attrs'       => array( 'id' => 99 ),
					'innerBlocks' => array(),
				),
			);
		};

		$this->assertTrue( $this->service->headingHasFollowingImage( 14, $hash ) );
		unset( $GLOBALS['sim_test_parse_blocks'] );
	}

	/** @test */
	public function headings_needing_images_drops_attached_headings(): void {
		$attached = HeadingLocator::computeHash( 2, 'american goldfinch', 0 );
		$open     = HeadingLocator::computeHash( 2, 'bald eagle', 0 );
		$post     = new \WP_Post();
		$post->ID = 15;
		$post->post_content = '<h2>American Goldfinch</h2><img src="goldfinch.jpg" alt="" /><h2>Bald Eagle</h2><p>Diet.</p>';
		$GLOBALS['sim_test_get_post'] = static function () use ( $post ) {
			return $post;
		};

		$kept = $this->service->headingsNeedingImages(
			15,
			array(
				array(
					'heading_hash' => $attached,
					'text'         => 'American Goldfinch',
				),
				array(
					'heading_hash' => $open,
					'text'         => 'Bald Eagle',
				),
				array(
					'heading_hash' => '',
					'text'         => 'Broken',
				),
			)
		);

		$this->assertCount( 1, $kept );
		$this->assertSame( $open, $kept[0]['heading_hash'] );
	}
}
