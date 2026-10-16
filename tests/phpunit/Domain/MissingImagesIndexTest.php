<?php
/**
 * Unit tests for MissingImagesIndex.
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.6.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\HeadingExtractor;
use SmartImageMatcher\Domain\Matcher;
use SmartImageMatcher\Domain\MissingImagesIndex;
use SmartImageMatcher\Insertion\InsertionService;

/**
 * Class MissingImagesIndexTest
 *
 * @since 3.6.0
 */
class MissingImagesIndexTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['sim_test_options']['smart_image_matcher_settings'] );
	}

	/** @test */
	public function numbered_headings_are_recognised(): void {
		foreach ( array( '1. Eastern Garter Snake', '10) Timber Rattlesnake', '#3 Red Fox', 'Step 2: Fill the form', '4: Copperhead' ) as $text ) {
			$this->assertTrue( MissingImagesIndex::isNumbered( $text ), $text );
		}
		foreach ( array( 'How to Identify Snakes', '2026 Hunting Seasons Explained', 'Top 10 Tips' ) as $text ) {
			$this->assertFalse( MissingImagesIndex::isNumbered( $text ), $text );
		}
	}

	/** @test */
	public function search_phrase_drops_numbering_and_adds_the_articles_state(): void {
		$title = '10 Most Common Snakes in Massachusetts (With Pictures)';
		$this->assertSame( 'Eastern Garter Snake in Massachusetts', MissingImagesIndex::searchPhrase( '1. Eastern Garter Snake', $title ) );
		$this->assertSame( 'Timber Rattlesnake in Massachusetts', MissingImagesIndex::searchPhrase( '9. Timber Rattlesnake (Venomous)', $title ) );
	}

	/** @test */
	public function search_phrase_keeps_a_state_the_heading_already_names(): void {
		$this->assertSame(
			'Landowner, Family, and Tenant Exemptions in Massachusetts',
			MissingImagesIndex::searchPhrase( 'Landowner, Family, and Tenant Exemptions in Massachusetts', 'Can You Hunt on Your Own Property in Massachusetts?' )
		);
	}

	/** @test */
	public function search_phrase_handles_two_word_states_and_unsafe_filename_characters(): void {
		$this->assertSame(
			'Manure Duties on a Hobby Farm in West Virginia',
			MissingImagesIndex::searchPhrase( 'Manure Duties on a Hobby Farm', 'Running a Hobby Farm in West Virginia' )
		);
		$this->assertSame(
			'Interstate Sales When the Lacey Act Applies in Alaska',
			MissingImagesIndex::searchPhrase( 'Interstate Sales: When the Lacey Act Applies?', 'Selling Taxidermy in Alaska' )
		);
	}

	/** @test */
	public function search_phrase_without_a_state_is_just_the_cleaned_heading(): void {
		$this->assertSame( 'Red Fox', MissingImagesIndex::searchPhrase( '1. Red Fox', 'Common Foxes of North America' ) );
	}

	/** @test */
	public function compute_lists_headings_without_images_minus_ignored(): void {
		$headings = array(
			array( 'heading_hash' => 'a', 'text' => '1. Eastern Garter Snake', 'level' => 2, 'tag' => 'h2' ),
			array( 'heading_hash' => 'b', 'text' => '2. Northern Water Snake', 'level' => 2, 'tag' => 'h2' ),
			array( 'heading_hash' => 'c', 'text' => 'Frequently Asked Questions', 'level' => 2, 'tag' => 'h2' ),
		);

		$extractor = $this->createMock( HeadingExtractor::class );
		$extractor->method( 'extract' )->willReturn( $headings );
		$matcher = $this->createMock( Matcher::class );
		$matcher->method( 'filterByHierarchy' )->willReturnArgument( 0 );
		$insertion = $this->createMock( InsertionService::class );
		// Heading "b" already has an image.
		$insertion->method( 'headingsNeedingImages' )->willReturn( array( $headings[0], $headings[2] ) );

		$post             = new \WP_Post();
		$post->ID         = 7;
		$post->post_title = '10 Most Common Snakes in Massachusetts';

		$data = ( new MissingImagesIndex( $extractor, $insertion, $matcher ) )->compute( $post, array( 'c' ) );

		$this->assertSame( 3, $data['total'] );
		$this->assertFalse( $data['all_missing'] );
		$this->assertCount( 1, $data['heads'] );
		$this->assertSame( 'a', $data['heads'][0]['hash'] );
		$this->assertTrue( $data['heads'][0]['numbered'] );
		$this->assertSame( 'Eastern Garter Snake in Massachusetts', $data['heads'][0]['phrase'] );
	}

	/** @test */
	public function compute_flags_articles_with_no_images_at_all(): void {
		$headings = array(
			array( 'heading_hash' => 'a', 'text' => 'Red Fox', 'level' => 2, 'tag' => 'h2' ),
			array( 'heading_hash' => 'b', 'text' => 'Gray Fox', 'level' => 2, 'tag' => 'h2' ),
		);
		$extractor = $this->createMock( HeadingExtractor::class );
		$extractor->method( 'extract' )->willReturn( $headings );
		$matcher = $this->createMock( Matcher::class );
		$matcher->method( 'filterByHierarchy' )->willReturnArgument( 0 );
		$insertion = $this->createMock( InsertionService::class );
		$insertion->method( 'headingsNeedingImages' )->willReturn( $headings );

		$post             = new \WP_Post();
		$post->ID         = 8;
		$post->post_title = 'Foxes';

		$data = ( new MissingImagesIndex( $extractor, $insertion, $matcher ) )->compute( $post, array() );

		$this->assertTrue( $data['all_missing'] );
		$this->assertCount( 2, $data['heads'] );
	}
}
