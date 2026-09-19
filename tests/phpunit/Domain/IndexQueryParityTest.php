<?php
/**
 * Index terms and lookup terms must use the same normalisation.
 *
 * Regression: the inverted index stored raw tokens ("massachusetts", "rules")
 * while headings were stemmed ("massachusett", "rule"), so the correct image
 * never reached the shortlist and pronoun-heavy filenames ranked first.
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.4.8
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\Normalizer;

/**
 * Class IndexQueryParityTest
 *
 * @since 3.4.8
 */
class IndexQueryParityTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['sim_test_options']['smart_image_matcher_settings'] );
	}

	/**
	 * Terms a heading would look up in the index.
	 *
	 * @param string $text Heading text.
	 * @return string[]
	 */
	private function lookup( string $text ): array {
		return Normalizer::lookupTerms( Normalizer::normalizeFromSettings( $text ) );
	}

	/** @test */
	public function correctly_spelled_state_filename_is_found_by_state_heading(): void {
		$shared = array_intersect(
			$this->lookup( 'Sunday Hunting on Private Property in Massachusetts' ),
			Normalizer::indexTerms( 'hunting laws in massachusetts' )
		);
		$this->assertContains( 'massachusett', $shared );
		$this->assertContains( 'hunting', $shared );
	}

	/** @test */
	public function typo_filename_gets_no_advantage_over_correct_spelling(): void {
		$this->assertSame(
			Normalizer::indexTerms( 'green snakes in massachusetts' ),
			Normalizer::indexTerms( 'green snakes in massachusett' )
		);
	}

	/** @test */
	public function plural_filename_matches_singular_or_plural_heading(): void {
		$this->assertContains( 'rule', Normalizer::indexTerms( 'hunting rules in maine' ) );
		$this->assertContains( 'rule', $this->lookup( 'Firearm Discharge Rules' ) );
	}

	/** @test */
	public function pronouns_do_not_create_matches(): void {
		$shared = array_intersect(
			$this->lookup( 'Can You Hunt on Your Own Property in Massachusetts? (Direct Answer)' ),
			Normalizer::indexTerms( 'can you butcher your own animals in massachusetts' )
		);
		$this->assertSame( array( 'massachusett' ), array_values( $shared ) );
	}

	/** @test */
	public function lookup_is_stemmed_even_when_stemming_setting_is_off(): void {
		$this->assertSame( array( 'rule', 'massachusett' ), Normalizer::lookupTerms( array( 'rules', 'massachusetts' ) ) );
	}
}
