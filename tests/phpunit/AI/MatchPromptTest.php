<?php
/**
 * Unit tests for the heading-match AI prompt.
 *
 * @package SmartImageMatcher\Tests\AI
 * @since   3.4.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\AI;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\AI\MatchPrompt;

/**
 * Class MatchPromptTest
 *
 * @since 3.4.0
 */
class MatchPromptTest extends TestCase {

	/**
	 * System prompt must reject compound-name false friends.
	 *
	 * @return void
	 */
	public function test_system_message_rejects_compound_false_friends(): void {
		$prompt = ( new MatchPrompt() )->systemMessage();

		$this->assertStringContainsString( 'Foxes', $prompt );
		$this->assertStringContainsString( 'eastern-fox-squirrel', $prompt );
		$this->assertStringContainsString( 'PRIMARY subject', $prompt );
	}

	/**
	 * Featured prompt must allow article modifiers and reject extra image nouns.
	 *
	 * @return void
	 */
	public function test_featured_system_message_allows_article_modifiers(): void {
		$prompt = ( new MatchPrompt() )->featuredSystemMessage();

		$this->assertStringContainsString( 'featured image', $prompt );
		$this->assertStringContainsString( 'american-goldfinch-winter-diet', $prompt );
		$this->assertStringContainsString( 'eastern-fox-squirrel', $prompt );
	}
}
