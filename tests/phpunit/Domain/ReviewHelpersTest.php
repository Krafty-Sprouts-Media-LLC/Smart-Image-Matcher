<?php
/**
 * Review helpers added for the Bulk Processor redesign.
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.7.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\IgnoredArticles;
use SmartImageMatcher\Domain\RelevanceGuard;
use SmartImageMatcher\Settings\Sanitizer;
use SmartImageMatcher\Settings\Settings;

/**
 * Class ReviewHelpersTest
 *
 * @since 3.7.0
 */
class ReviewHelpersTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['sim_test_options'][ Settings::OPTION ], $GLOBALS['sim_test_post_meta'][55] );
	}

	/** @test */
	public function shared_words_ignore_states_and_generic_legal_words(): void {
		$this->assertSame(
			array( 'animal', 'waste' ),
			RelevanceGuard::sharedTopicTerms( 'Manure and Animal Waste Duties on a Hobby Farm in Montana', array( 'filename' => 'Animal-Waste-Disposal-Laws-in-Montana.jpg' ) )
		);
		$this->assertSame(
			array( 'penalty' ),
			RelevanceGuard::sharedTopicTerms( 'Penalties for Unlicensed Online Animal Sales in New Jersey', array( 'filename' => 'Pet-Theft-Penalty-in-New-Jersey.jpg' ) )
		);
	}

	/** @test */
	public function filter_counts_what_it_drops(): void {
		RelevanceGuard::takeRejectedCount();
		RelevanceGuard::filter(
			array(
				array( 'filename' => 'Right-to-Farm-Laws-in-Virginia.jpg' ),
				array( 'filename' => 'Right-to-Farm-Laws-in-Idaho.jpg' ),
			),
			'Right-to-Farm Protections for Hobby Farms in Idaho',
			''
		);
		$this->assertSame( 1, RelevanceGuard::takeRejectedCount() );
		$this->assertSame( 0, RelevanceGuard::takeRejectedCount(), 'Reading resets the count.' );
	}

	/** @test */
	public function unblocking_removes_only_that_filename(): void {
		$GLOBALS['sim_test_options'][ Settings::OPTION ] = array(
			'fiaa_excluded_image_slugs' => "logo-placeholder.jpg\nCan-You-Butcher-Your-Own-Animals-in-Massachusetts.jpg",
		);

		$left = ( new Sanitizer() )->removeExcludedImageSlug( 'can-you-butcher-your-own-animals-in-massachusetts.jpg' );

		$this->assertStringContainsString( 'logo-placeholder', $left );
		$this->assertStringNotContainsStringIgnoringCase( 'butcher', $left );
	}

	/** @test */
	public function ignoring_is_a_post_flag(): void {
		$this->assertFalse( IgnoredArticles::isIgnored( 55 ) );
		$GLOBALS['sim_test_post_meta'][55][ IgnoredArticles::META ] = '1';
		$this->assertTrue( IgnoredArticles::isIgnored( 55 ) );
	}
}
