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
}
