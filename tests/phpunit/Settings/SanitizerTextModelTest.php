<?php
/**
 * Unit tests for OpenRouter-style text model slug sanitization.
 *
 * @package SmartImageMatcher\Tests\Settings
 * @since   3.4.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Settings;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Settings\Sanitizer;
use ReflectionMethod;

/**
 * Class SanitizerTextModelTest
 *
 * @since 3.4.0
 */
class SanitizerTextModelTest extends TestCase {

	/**
	 * Call the private slug sanitizer.
	 *
	 * @param string $raw     Raw slug.
	 * @param string $default Fallback.
	 * @return string
	 */
	private function sanitizeSlug( string $raw, string $default ): string {
		$method = new ReflectionMethod( Sanitizer::class, 'sanitizeTextModelSlug' );
		$method->setAccessible( true );
		return (string) $method->invoke( null, $raw, $default );
	}

	/**
	 * Slugs are lowercased and stripped to OpenRouter-safe characters.
	 *
	 * @return void
	 */
	public function test_normalizes_openrouter_slug(): void {
		$this->assertSame(
			'mistralai/mistral-nemo',
			$this->sanitizeSlug( 'MistralAI/Mistral-Nemo!!', 'mistralai/mistral-nemo' )
		);
	}

	/**
	 * Empty input restores the default.
	 *
	 * @return void
	 */
	public function test_empty_slug_restores_default(): void {
		$this->assertSame(
			'mistralai/mistral-nemo',
			$this->sanitizeSlug( '   ', 'mistralai/mistral-nemo' )
		);
		$this->assertSame(
			'meta-llama/llama-3.1-8b-instruct',
			$this->sanitizeSlug( '', 'meta-llama/llama-3.1-8b-instruct' )
		);
	}
}
