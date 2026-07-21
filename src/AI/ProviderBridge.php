<?php
/**
 * Thin wrapper over wp_ai_client_prompt().
 *
 * The plugin never talks to any AI provider directly.
 * All AI calls route through the WP 7.0 AI Client so the user's
 * choice of provider (configured in Settings → Connectors) is honoured,
 * and the plugin ships with zero hardcoded API keys.
 *
 * Falls back gracefully when:
 *   - WP < 7.0 (wp_ai_client_prompt does not exist)
 *   - No provider is configured
 *   - The provider returns an error
 *
 * @package SmartImageMatcher\AI
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Logging\Logger;

/**
 * Class ProviderBridge
 *
 * @since 3.0.0
 */
class ProviderBridge {

	// -------------------------------------------------------------------------
	// Availability
	// -------------------------------------------------------------------------

	/**
	 * Whether the WP 7.0 AI Client is present and has a configured provider.
	 *
	 * @since 3.0.0
	 * @return bool
	 */
	public static function isAvailable(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		$wp_ai_client_prompt = 'wp_ai_client_prompt';
		$probe               = $wp_ai_client_prompt();

		if ( is_wp_error( $probe ) ) {
			return false;
		}

		// withText('') + is_supported() returns false when no provider is
		// configured, allowing clean "AI unavailable" UI without an API call.
		try {
			return (bool) $probe->withText( '' )->is_supported();
		} catch ( \Throwable $e ) {
			Logger::warn( 'ProviderBridge::isAvailable() threw', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	// -------------------------------------------------------------------------
	// Text generation
	// -------------------------------------------------------------------------

	/**
	 * Generate text via the configured AI provider.
	 *
	 * @since 3.0.0
	 * @param string $systemPrompt Instructions for the model.
	 * @param string $userPrompt   The actual user-turn prompt.
	 * @param float  $temperature  0.0 = deterministic; 1.0 = creative.
	 * @return string|\WP_Error
	 */
	public static function generateText(
		string $systemPrompt,
		string $userPrompt,
		float  $temperature = 0.2
	) {
		if ( ! self::isAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_ai_unavailable',
				__( 'No AI provider configured. Visit Settings → Connectors to set one up.', 'smart-image-matcher' )
			);
		}

		try {
			$wp_ai_client_prompt = 'wp_ai_client_prompt';
			$builder             = $wp_ai_client_prompt();

			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			$result = $builder
				->withSystemMessage( $systemPrompt )
				->withText( $userPrompt )
				->usingTemperature( $temperature )
				->generateText();

			if ( is_wp_error( $result ) ) {
				Logger::warn( 'ProviderBridge::generateText() error', array( 'error' => $result->get_error_message() ) );
				return $result;
			}

			$text = method_exists( $result, 'getText' ) ? $result->getText() : (string) $result;

			Logger::info( 'ProviderBridge::generateText() success', array( 'chars' => strlen( $text ) ) );

			return $text;

		} catch ( \Throwable $e ) {
			Logger::error( 'ProviderBridge::generateText() exception', array( 'error' => $e->getMessage() ) );
			return new \WP_Error( 'smart_image_matcher_ai_exception', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Image generation
	// -------------------------------------------------------------------------

	/**
	 * Generate an image via the configured AI provider.
	 *
	 * @since 3.0.0
	 * @param string $prompt      Image description prompt.
	 * @return mixed|\WP_Error    Generation result object or error.
	 */
	public static function generateImage( string $prompt ) {
		if ( ! self::isAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_ai_unavailable',
				__( 'No AI provider configured.', 'smart-image-matcher' )
			);
		}

		try {
			$wp_ai_client_prompt = 'wp_ai_client_prompt';
			$builder             = $wp_ai_client_prompt();

			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			$result = $builder
				->withText( $prompt )
				->generateImage();

			if ( is_wp_error( $result ) ) {
				Logger::warn( 'ProviderBridge::generateImage() error', array( 'error' => $result->get_error_message() ) );
				return $result;
			}

			return $result;

		} catch ( \Throwable $e ) {
			Logger::error( 'ProviderBridge::generateImage() exception', array( 'error' => $e->getMessage() ) );
			return new \WP_Error( 'smart_image_matcher_ai_exception', $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Vision
	// -------------------------------------------------------------------------

	/**
	 * Score an image against a heading description using vision.
	 *
	 * Sends the image URL + a structured prompt and returns the raw text
	 * response (caller must parse the score).
	 *
	 * @since 3.0.0
	 * @param string $imageUrl    Publicly accessible image URL.
	 * @param string $headingText Heading text to score against.
	 * @return string|\WP_Error
	 */
	public static function scoreImageWithVision( string $imageUrl, string $headingText ) {
		if ( ! self::isAvailable() ) {
			return new \WP_Error( 'smart_image_matcher_ai_unavailable', __( 'No AI provider configured.', 'smart-image-matcher' ) );
		}

		$prompt = sprintf(
			'Score 0-100 how well this image visually depicts the topic described by the heading: "%s". ' .
			'Return ONLY a JSON object: {"score": <integer 0-100>, "reasoning": "<one sentence>"}',
			$headingText
		);

		try {
			$wp_ai_client_prompt = 'wp_ai_client_prompt';
			$builder             = $wp_ai_client_prompt();

			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			// withImage() is the WP 7.0 vision API surface.
			$result = $builder
				->withImage( $imageUrl )
				->withText( $prompt )
				->usingTemperature( 0 )
				->generateText();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return method_exists( $result, 'getText' ) ? $result->getText() : (string) $result;

		} catch ( \Throwable $e ) {
			return new \WP_Error( 'smart_image_matcher_ai_exception', $e->getMessage() );
		}
	}
}
