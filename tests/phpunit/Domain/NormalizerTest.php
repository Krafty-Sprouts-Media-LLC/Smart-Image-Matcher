<?php
/**
 * Unit tests for Normalizer.
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\Normalizer;

/**
 * Class NormalizerTest
 *
 * @since 3.0.0
 */
class NormalizerTest extends TestCase {

	/** @test */
	public function it_returns_an_array(): void {
		$result = Normalizer::normalize( 'Black Swallowtail Butterfly' );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	/** @test */
	public function it_lowercases_and_strips_stop_words(): void {
		$result = Normalizer::normalize( 'The Life of a Bird' );
		$this->assertNotContains( 'the', $result );
		$this->assertNotContains( 'a', $result );
		$this->assertNotContains( 'of', $result );
	}

	/** @test */
	public function it_handles_possessives(): void {
		// "bird's" should stem to "bird".
		$result = Normalizer::normalize( "bird's nest", false, false );
		$this->assertContains( 'bird', $result );
		$this->assertContains( 'nest', $result );
	}

	/** @test */
	public function io_whitelist_regression(): void {
		// "Io" moth caterpillar must not be filtered (CHANGELOG 2.4.1 regression).
		// Whitelist is read from Settings; in tests Settings returns the default 'io'.
		$result = Normalizer::normalize( 'Io Moth Caterpillar', false, false );
		$this->assertContains( 'io', $result, '"io" must survive the short-word filter when whitelisted' );
	}

	/** @test */
	public function stemming_plurals(): void {
		$result = Normalizer::normalize( 'red birds', true, false );
		// "birds" should stem to "bird".
		$this->assertContains( 'bird', $result );
	}

	/** @test */
	public function us_spelling_variant(): void {
		$result = Normalizer::normalize( 'color theory', false, true );
		// Should contain both "color" and "colour" (variant expanded).
		$this->assertContains( 'color', $result );
		$this->assertContains( 'colour', $result );
	}

	/** @test */
	public function british_spelling_variant(): void {
		$result = Normalizer::normalize( 'colour theory', false, true );
		$this->assertContains( 'colour', $result );
		$this->assertContains( 'color', $result );
	}

	/** @test */
	public function words_match_exact(): void {
		$this->assertTrue( Normalizer::wordsMatch( 'butterfly', 'butterfly' ) );
	}

	/** @test */
	public function words_match_stem(): void {
		$this->assertTrue( Normalizer::wordsMatch( 'birds', 'bird', true, false ) );
	}

	/** @test */
	public function words_match_spelling_variant(): void {
		$this->assertTrue( Normalizer::wordsMatch( 'color', 'colour', false, true ) );
	}

	/** @test */
	public function words_do_not_match_unrelated(): void {
		$this->assertFalse( Normalizer::wordsMatch( 'butterfly', 'elephant' ) );
	}
}
