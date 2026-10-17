<?php
/**
 * Removing one image from a post body (moving a misplaced featured image).
 *
 * @package SmartImageMatcher\Tests\Insertion
 * @since   3.7.3
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Insertion;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Insertion\BlockBuilder;
use SmartImageMatcher\Insertion\InsertionService;

/**
 * Class RemoveImageTest
 *
 * @since 3.7.3
 */
class RemoveImageTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['sim_test_parse_blocks'], $GLOBALS['sim_test_serialize_blocks'] );
	}

	/** @test */
	public function classic_figure_and_paragraph_images_are_removed_but_similar_ids_are_kept(): void {
		$content = "<h2>Can You Butcher Your Own Animals?</h2>\n"
			. "<figure class=\"wp-caption\"><img class=\"size-large wp-image-1\" src=\"a.jpg\" /><figcaption>x</figcaption></figure>\n"
			. "<p>Text.</p>\n"
			. "<p><img class=\"wp-image-1\" src=\"a.jpg\" /></p>\n"
			. "<p><img class=\"wp-image-12\" src=\"b.jpg\" /></p>";

		$out = ( new InsertionService( new BlockBuilder() ) )->withoutImage( $content, 1 );

		$this->assertStringNotContainsString( 'wp-image-1"', $out );
		$this->assertStringContainsString( 'wp-image-12', $out );
		$this->assertStringContainsString( '<p>Text.</p>', $out );
		$this->assertStringContainsString( '<h2>Can You Butcher Your Own Animals?</h2>', $out );
	}

	/** @test */
	public function block_image_is_dropped_with_its_placeholder_and_gallery_images_stay(): void {
		$image   = static fn( int $id ): array => array(
			'blockName'    => 'core/image',
			'attrs'        => array( 'id' => $id ),
			'innerBlocks'  => array(),
			'innerHTML'    => '<figure></figure>',
			'innerContent' => array( '<figure></figure>' ),
		);
		$heading = array(
			'blockName'    => 'core/heading',
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => '<h2>A</h2>',
			'innerContent' => array( '<h2>A</h2>' ),
		);
		$group   = array(
			'blockName'    => 'core/group',
			'attrs'        => array(),
			'innerBlocks'  => array( $heading, $image( 5 ), $image( 6 ) ),
			'innerHTML'    => '<div></div>',
			'innerContent' => array( '<div>', null, "\n", null, "\n", null, '</div>' ),
		);
		$gallery = array(
			'blockName'    => 'core/gallery',
			'attrs'        => array(),
			'innerBlocks'  => array( $image( 5 ) ),
			'innerHTML'    => '<figure></figure>',
			'innerContent' => array( '<figure>', null, '</figure>' ),
		);

		$GLOBALS['sim_test_parse_blocks']     = static fn() => array( $image( 5 ), $group, $gallery );
		$GLOBALS['sim_test_serialize_blocks'] = static fn( array $blocks ) => wp_json_encode_for_test( $blocks );

		$out    = ( new InsertionService( new BlockBuilder() ) )->withoutImage( '<!-- wp:paragraph -->x<!-- /wp:paragraph -->', 5 );
		$blocks = json_decode( $out, true );

		$this->assertCount( 2, $blocks, 'Top-level image removed.' );
		$this->assertSame( 'core/group', $blocks[0]['blockName'] );
		$this->assertCount( 2, $blocks[0]['innerBlocks'], 'Nested image removed.' );
		$this->assertSame( 6, $blocks[0]['innerBlocks'][1]['attrs']['id'] );
		$this->assertCount( 2, array_filter( $blocks[0]['innerContent'], 'is_null' ), 'One placeholder per remaining inner block.' );
		$this->assertCount( 1, $blocks[1]['innerBlocks'], 'Gallery image kept.' );
	}

	/** @test */
	public function removing_every_image_reports_what_it_removed(): void {
		$content = "<h2>One</h2>\n<p><img class=\"wp-image-3\" src=\"a.jpg\" /></p>\n<p>Keep me.</p>\n<img src=\"https://example.com/x.jpg\" />";

		$result = ( new InsertionService( new BlockBuilder() ) )->withoutImages( $content, null );

		$this->assertStringNotContainsString( '<img', $result['content'] );
		$this->assertStringContainsString( '<p>Keep me.</p>', $result['content'] );
		$this->assertSame( array( 3, 0 ), $result['removed'] );
	}

	/** @test */
	public function standalone_count_skips_gallery_images(): void {
		$GLOBALS['sim_test_parse_blocks'] = static fn() => array(
			array( 'blockName' => 'core/image', 'attrs' => array( 'id' => 4 ), 'innerBlocks' => array() ),
			array(
				'blockName'   => 'core/gallery',
				'attrs'       => array(),
				'innerBlocks' => array( array( 'blockName' => 'core/image', 'attrs' => array( 'id' => 9 ), 'innerBlocks' => array() ) ),
			),
		);

		$ids = ( new InsertionService( new BlockBuilder() ) )->standaloneImageIds( '<!-- wp:image -->' );

		$this->assertSame( array( 4 ), $ids );
	}

	/** @test */
	public function an_image_already_in_the_post_is_never_inserted_twice(): void {
		$post               = new \WP_Post();
		$post->ID           = 31;
		$post->post_content = '<h2>Goldfinch</h2><p><img class="wp-image-5" src="g.jpg" /></p>';
		$GLOBALS['sim_test_get_post'] = static fn() => $post;

		$result = ( new InsertionService( new BlockBuilder() ) )->bulkInsert(
			31,
			array( array( 'heading_hash' => 'any-hash', 'image_id' => 5 ) )
		);

		unset( $GLOBALS['sim_test_get_post'] );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'smart_image_matcher_already_inserted', $result->get_error_code() );
	}

	/** @test */
	public function content_without_the_image_is_unchanged(): void {
		$content = '<p><img class="wp-image-7" src="c.jpg" /></p>';
		$this->assertSame( $content, ( new InsertionService( new BlockBuilder() ) )->withoutImage( $content, 8 ) );
	}
}

/**
 * JSON for assertions (keeps nulls).
 *
 * @param array<int, mixed> $blocks Blocks.
 * @return string
 */
function wp_json_encode_for_test( array $blocks ): string {
	return (string) json_encode( $blocks );
}
