<?php
/**
 * Unit tests for BlockBuilder.
 *
 * @package SmartImageMatcher\Tests\Insertion
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Insertion;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Insertion\BlockBuilder;

/**
 * Class BlockBuilderTest
 *
 * @since 3.0.0
 */
class BlockBuilderTest extends TestCase {

	private BlockBuilder $builder;

	protected function setUp(): void {
		$this->builder = new BlockBuilder();
	}

	/** @test */
	public function block_name_is_core_image(): void {
		$block = $this->builder->build( 1 );

		$this->assertEquals( 'core/image', $block['blockName'] );
	}

	/** @test */
	public function attrs_contain_only_allowed_keys(): void {
		$block    = $this->builder->build( 1 );
		$attrKeys = array_keys( $block['attrs'] );

		// Only id, sizeSlug, linkDestination are allowed (CHANGELOG 1.1.1).
		foreach ( $attrKeys as $key ) {
			$this->assertContains(
				$key,
				array( 'id', 'sizeSlug', 'linkDestination' ),
				"Unexpected attr key: {$key}"
			);
		}
	}

	/** @test */
	public function img_tag_has_no_width_or_height(): void {
		$block = $this->builder->build( 1 );
		$html  = $block['innerHTML'];

		$this->assertStringNotContainsString( 'width=', $html );
		$this->assertStringNotContainsString( 'height=', $html );
	}

	/** @test */
	public function block_has_empty_inner_blocks(): void {
		$block = $this->builder->build( 1 );

		$this->assertIsArray( $block['innerBlocks'] );
		$this->assertEmpty( $block['innerBlocks'] );
	}
}
