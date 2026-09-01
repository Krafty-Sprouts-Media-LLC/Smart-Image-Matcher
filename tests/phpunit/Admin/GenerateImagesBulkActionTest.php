<?php
/**
 * Unit tests for posts-list Generate row actions.
 *
 * @package SmartImageMatcher\Tests\Admin
 * @since   3.3.4
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Admin\GenerateImagesBulkAction;
use WP_Post;

/**
 * Class GenerateImagesBulkActionTest
 *
 * @since 3.3.4
 */
class GenerateImagesBulkActionTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['sim_test_current_user_can'], $GLOBALS['sim_test_post_type_supports'] );
		parent::tearDown();
	}

	/**
	 * @return WP_Post
	 */
	private function makePost( int $id = 42, string $type = 'post' ): WP_Post {
		$post             = new WP_Post();
		$post->ID         = $id;
		$post->post_type  = $type;
		$post->post_title = 'Bald Eagle';
		return $post;
	}

	/** @test */
	public function it_inserts_generate_before_trash(): void {
		$handler = new GenerateImagesBulkAction();
		$actions = array(
			'edit'   => '<a href="#">Edit</a>',
			'inline' => '<button>Quick Edit</button>',
			'trash'  => '<a href="#">Trash</a>',
			'view'   => '<a href="#">Preview</a>',
		);

		$result = $handler->addRowAction( $actions, $this->makePost() );
		$keys   = array_keys( $result );

		$this->assertSame( array( 'edit', 'inline', 'sim_generate', 'trash', 'view' ), $keys );
		$this->assertStringContainsString( 'sim-generate-featured', $result['sim_generate'] );
		$this->assertStringContainsString( 'data-post-id="42"', $result['sim_generate'] );
		$this->assertStringContainsString( 'Generate', $result['sim_generate'] );
		$this->assertStringContainsString( 'sim_featured_ai=1', $result['sim_generate'] );
		$this->assertStringContainsString( 'sim_featured_ids=42', $result['sim_generate'] );
	}

	/** @test */
	public function it_skips_attachments(): void {
		$handler = new GenerateImagesBulkAction();
		$actions = array( 'edit' => '<a>Edit</a>' );

		$result = $handler->addRowAction( $actions, $this->makePost( 9, 'attachment' ) );

		$this->assertSame( $actions, $result );
	}

	/** @test */
	public function it_skips_when_user_cannot_edit_the_post(): void {
		$GLOBALS['sim_test_current_user_can'] = static function ( $cap = '', $id = 0 ) {
			return ! ( 'edit_post' === $cap && 42 === (int) $id );
		};

		$handler = new GenerateImagesBulkAction();
		$actions = array( 'edit' => '<a>Edit</a>' );

		$result = $handler->addRowAction( $actions, $this->makePost() );

		$this->assertSame( $actions, $result );
	}

	/** @test */
	public function it_skips_post_types_without_thumbnails(): void {
		$GLOBALS['sim_test_post_type_supports'] = static function ( $type, $feature ) {
			return ! ( 'post' === $type && 'thumbnail' === $feature );
		};

		$handler = new GenerateImagesBulkAction();
		$actions = array( 'edit' => '<a>Edit</a>' );

		$result = $handler->addRowAction( $actions, $this->makePost() );

		$this->assertSame( $actions, $result );
	}
}
