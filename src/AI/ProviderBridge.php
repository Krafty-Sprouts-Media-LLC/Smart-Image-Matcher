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
use SmartImageMatcher\Settings\Settings;

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
	 * Whether the WP 7.0 AI Client is present and has a configured text provider.
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

		try {
			if ( is_callable( array( $probe, 'is_supported_for_text_generation' ) ) ) {
				return (bool) $probe->with_text( 'x' )->is_supported_for_text_generation();
			}

			return (bool) $probe->with_text( 'x' )->is_supported();
		} catch ( \Throwable $e ) {
			Logger::warn( 'ProviderBridge::isAvailable() threw', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Whether curated image models are reachable via the AI Client / Connectors.
	 *
	 * @since 3.1.2
	 * @return bool
	 */
	public static function isImageGenerationAvailable(): bool {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return false;
		}

		$wp_ai_client_prompt = 'wp_ai_client_prompt';
		$probe               = $wp_ai_client_prompt();

		if ( is_wp_error( $probe ) ) {
			return false;
		}

		try {
			$prefs   = ImageModelCatalog::preferenceList( (string) Settings::get( 'ai_image_model' ) );
			$builder = $probe
				->using_provider( 'fal-ai' )
				->with_text( 'x' )
				->using_model_preference( ...$prefs );

			if ( is_callable( array( $builder, 'is_supported_for_image_generation' ) ) ) {
				return (bool) $builder->is_supported_for_image_generation();
			}

			return (bool) $builder->is_supported();
		} catch ( \Throwable $e ) {
			Logger::warn( 'ProviderBridge::isImageGenerationAvailable() threw', array( 'error' => $e->getMessage() ) );
			return false;
		}
	}

	// -------------------------------------------------------------------------
	// Text generation
	// -------------------------------------------------------------------------

	/**
	 * Generate text via the configured AI provider.
	 *
	 * Temperature is omitted by default — many current models (e.g. GPT-5 family)
	 * reject `temperature` with HTTP 400. Pass a float only when the connector
	 * model is known to accept it.
	 *
	 * @since 3.0.0
	 * @param string     $systemPrompt Instructions for the model.
	 * @param string     $userPrompt   The actual user-turn prompt.
	 * @param float|null $temperature  Optional; null = do not send temperature.
	 * @return string|\WP_Error
	 */
	public static function generateText(
		string $systemPrompt,
		string $userPrompt,
		?float $temperature = null
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

			$builder = $builder
				->using_system_instruction( $systemPrompt )
				->with_text( $userPrompt );

			if ( null !== $temperature ) {
				$builder = $builder->using_temperature( $temperature );
			}

			$result = $builder->generate_text();

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
	 * Uses SIM's preferred image model, then the other curated fal model IDs.
	 * Featured images force 16:9; under-heading images leave the model default.
	 *
	 * @since 3.0.0
	 * @param string $prompt  Image description prompt.
	 * @param string $purpose featured|heading.
	 * @return mixed|\WP_Error Generation result object or error.
	 */
	public static function generateImage( string $prompt, string $purpose = 'heading' ) {
		if ( ! self::isImageGenerationAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_ai_image_unavailable',
				__( 'No image-capable AI provider configured for the preferred models. Connect fal.ai under Settings → Connectors.', 'smart-image-matcher' )
			);
		}

		try {
			$wp_ai_client_prompt = 'wp_ai_client_prompt';
			$builder             = $wp_ai_client_prompt();

			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			$prefs = ImageModelCatalog::preferenceList( (string) Settings::get( 'ai_image_model' ) );

			// Pin fal provider so we never fall through to another connector's first model.
			// Prefer generate_image_result so callers can read model metadata + File URL/base64.
			$builder = $builder
				->using_provider( 'fal-ai' )
				->with_text( $prompt )
				->using_model_preference( ...$prefs );

			// Featured: lock landscape 16:9 (Seedream → image_size landscape_16_9; Nano Banana → aspect_ratio).
			if ( 'featured' === $purpose && is_object( $builder ) && method_exists( $builder, 'as_output_media_aspect_ratio' ) ) {
				$builder = $builder->as_output_media_aspect_ratio( '16:9' );
			}

			$result = $builder->generate_image_result();

			if ( is_wp_error( $result ) ) {
				Logger::warn( 'ProviderBridge::generateImage() error', array( 'error' => $result->get_error_message() ) );
				return $result;
			}

			if ( is_object( $result ) && method_exists( $result, 'getModelMetadata' ) ) {
				$model_meta = $result->getModelMetadata();
				Logger::info(
					'ProviderBridge::generateImage() model',
					array(
						'model_id' => is_object( $model_meta ) && method_exists( $model_meta, 'getId' )
							? $model_meta->getId()
							: '',
						'preferred' => $prefs[0] ?? '',
						'purpose'   => $purpose,
					)
				);
			}

			return $result;

		} catch ( \Throwable $e ) {
			Logger::error( 'ProviderBridge::generateImage() exception', array( 'error' => $e->getMessage() ) );
			return new \WP_Error( 'smart_image_matcher_ai_exception', $e->getMessage() );
		}
	}

	/**
	 * Whether the fal provider exposes non-blocking queue submit/poll.
	 *
	 * @since 3.2.18
	 * @return bool
	 */
	public static function supportsAsyncImageQueue(): bool {
		return class_exists( '\KraftySprouts\AiProviderForFalAi\Queue\FalQueueClient' )
			&& \KraftySprouts\AiProviderForFalAi\Queue\FalQueueClient::isAvailable();
	}

	/**
	 * Submit an image job to fal without waiting for completion.
	 *
	 * @since 3.2.18
	 * @param string $prompt  Image prompt.
	 * @param string $purpose featured|heading.
	 * @return array{request_id:string,status_url:string,response_url:string,model_id:string}|\WP_Error
	 */
	public static function submitImage( string $prompt, string $purpose = 'heading' ) {
		if ( ! self::supportsAsyncImageQueue() ) {
			return new \WP_Error(
				'smart_image_matcher_async_unavailable',
				__( 'Async fal queue is not available. Update AI Provider for fal.ai.', 'smart-image-matcher' )
			);
		}

		$prefs  = ImageModelCatalog::preferenceList( (string) Settings::get( 'ai_image_model' ) );
		$aspect = ( 'featured' === $purpose ) ? '16:9' : null;
		$errors = array();

		foreach ( $prefs as $model_id ) {
			$result = \KraftySprouts\AiProviderForFalAi\Queue\FalQueueClient::submit( $model_id, $prompt, $aspect );
			if ( ! is_wp_error( $result ) ) {
				Logger::info(
					'ProviderBridge::submitImage() ok',
					array(
						'model_id'   => $model_id,
						'request_id' => $result['request_id'] ?? '',
						'purpose'    => $purpose,
					)
				);
				return $result;
			}
			$errors[] = $model_id . ': ' . $result->get_error_message();
		}

		return new \WP_Error(
			'smart_image_matcher_submit_failed',
			implode( ' | ', $errors )
		);
	}

	/**
	 * Poll fal queue status once.
	 *
	 * @since 3.2.18
	 * @param string $status_url Queue status URL.
	 * @param string $request_id Request id.
	 * @return string|\WP_Error Uppercase status or error.
	 */
	public static function pollImageStatus( string $status_url, string $request_id = '' ) {
		if ( ! self::supportsAsyncImageQueue() ) {
			return new \WP_Error(
				'smart_image_matcher_async_unavailable',
				__( 'Async fal queue is not available.', 'smart-image-matcher' )
			);
		}

		return \KraftySprouts\AiProviderForFalAi\Queue\FalQueueClient::status( $status_url, $request_id );
	}

	/**
	 * Fetch a completed fal image as a source array for sideload.
	 *
	 * @since 3.2.18
	 * @param string $response_url Queue response URL.
	 * @return array{url:string,mime?:string}|\WP_Error
	 */
	public static function fetchImageSource( string $response_url ) {
		if ( ! self::supportsAsyncImageQueue() ) {
			return new \WP_Error(
				'smart_image_matcher_async_unavailable',
				__( 'Async fal queue is not available.', 'smart-image-matcher' )
			);
		}

		return \KraftySprouts\AiProviderForFalAi\Queue\FalQueueClient::fetchImage( $response_url );
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

			$result = $builder
				->with_file( $imageUrl )
				->with_text( $prompt )
				->generate_text();

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return method_exists( $result, 'getText' ) ? $result->getText() : (string) $result;

		} catch ( \Throwable $e ) {
			return new \WP_Error( 'smart_image_matcher_ai_exception', $e->getMessage() );
		}
	}
}
