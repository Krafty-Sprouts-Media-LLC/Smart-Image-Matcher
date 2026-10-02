<?php
/**
 * AI ranking must follow Settings → Default Match Mode, not just "a provider is connected".
 *
 * Regression: match_mode was never read, so every article run made AI calls
 * whenever OpenRouter was connected, even with Match Mode set to Keyword.
 *
 * @package SmartImageMatcher\Tests\AI
 * @since   3.5.1
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\AI;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Premium\ArticleFeaturedAiGate;
use SmartImageMatcher\Premium\ArticleHeadingAiGate;
use SmartImageMatcher\REST\MatchController;
use SmartImageMatcher\Settings\Settings;

/**
 * Class AiMatchingSwitchTest
 *
 * @since 3.5.1
 */
class AiMatchingSwitchTest extends TestCase {

	protected function tearDown(): void {
		$this->setProviderConnected( null );
		unset( $GLOBALS['sim_test_options'][ Settings::OPTION ] );
	}

	/**
	 * Pretend a text provider (OpenRouter) is or isn't connected.
	 *
	 * @param bool|null $connected Null resets the per-request cache.
	 * @return void
	 */
	private function setProviderConnected( ?bool $connected ): void {
		$prop = new \ReflectionProperty( ProviderBridge::class, 'textAvailable' );
		$prop->setAccessible( true );
		$prop->setValue( null, $connected );
	}

	/**
	 * @param string $mode keyword|ai.
	 * @return void
	 */
	private function setMatchMode( string $mode ): void {
		$GLOBALS['sim_test_options'][ Settings::OPTION ] = array( 'match_mode' => $mode );
	}

	/** @test */
	public function keyword_mode_makes_no_ai_calls_even_with_provider_connected(): void {
		$this->setProviderConnected( true );
		$this->setMatchMode( 'keyword' );

		$this->assertFalse( ProviderBridge::isAiMatchingEnabled() );
		$this->assertFalse( ( new ArticleHeadingAiGate( new ImageRepository() ) )->isAvailable() );
		$this->assertFalse( ( new ArticleFeaturedAiGate( new ImageRepository() ) )->isAvailable() );
		$this->assertSame( 'keyword', MatchController::resolveMode( 'ai' ) );
	}

	/** @test */
	public function ai_mode_uses_ai_when_provider_connected(): void {
		$this->setProviderConnected( true );
		$this->setMatchMode( 'ai' );

		$this->assertTrue( ProviderBridge::isAiMatchingEnabled() );
		$this->assertTrue( ( new ArticleHeadingAiGate( new ImageRepository() ) )->isAvailable() );
		$this->assertTrue( ( new ArticleFeaturedAiGate( new ImageRepository() ) )->isAvailable() );
	}

	/** @test */
	public function ai_mode_without_provider_stays_keyword(): void {
		$this->setProviderConnected( false );
		$this->setMatchMode( 'ai' );

		$this->assertFalse( ProviderBridge::isAiMatchingEnabled() );
		$this->assertFalse( ( new ArticleHeadingAiGate( new ImageRepository() ) )->isAvailable() );
	}

	/** @test */
	public function default_settings_are_keyword(): void {
		$this->setProviderConnected( true );
		unset( $GLOBALS['sim_test_options'][ Settings::OPTION ] );

		$this->assertFalse( ProviderBridge::isAiMatchingEnabled() );
	}
}
