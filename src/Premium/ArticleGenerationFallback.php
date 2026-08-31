<?php
/**
 * Premium skip-band generation adapter for ArticleProcessor.
 *
 * Enqueues on-demand image generation when the library would skip a slot.
 * The free processor never calls Premium::has(); this class is bound only
 * when present.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.3.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\GenerationRejectionStore;
use SmartImageMatcher\AI\PromptBuilder;
use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Domain\GenerationFallback;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Settings\Settings;

/**
 * Class ArticleGenerationFallback
 *
 * @since 3.3.0
 */
class ArticleGenerationFallback implements GenerationFallback {

	/**
	 * Whether skip-band generation may be offered.
	 *
	 * @since 3.3.0
	 * @return bool
	 */
	public function isAvailable(): bool {
		return (bool) Settings::get( 'ai_image_generation_enabled' )
			&& ProviderBridge::isImageGenerationAvailable()
			&& Queue::isAvailable();
	}

	/**
	 * Enqueue generation for one featured slot or heading.
	 *
	 * @since 3.3.0
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash, or "featured".
	 * @param string $heading_text Heading or post title.
	 * @param string $section_text Surrounding section excerpt.
	 * @return bool True if a job was queued.
	 */
	public function enqueue( int $post_id, string $heading_hash, string $heading_text, string $section_text ): bool {
		if ( ! $this->isAvailable() ) {
			return false;
		}

		if ( $post_id <= 0 || '' === $heading_hash ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$style = (string) Settings::get( 'ai_image_style' );
		if ( 'illustration' !== $style ) {
			$style = 'photo';
		}

		/**
		 * Override generation style for one enqueue (e.g. generate-featured Run).
		 *
		 * @since 3.3.0
		 * @param string $style        photo|illustration.
		 * @param int    $post_id      Post ID.
		 * @param string $heading_hash Heading hash or "featured".
		 */
		$filtered = apply_filters( 'sim_generation_fallback_style', $style, $post_id, $heading_hash );
		$style    = 'illustration' === $filtered ? 'illustration' : 'photo';

		$focus   = PromptBuilder::getFocusKeyword( $post_id );
		$excerpt = '' !== $section_text ? $section_text : PromptBuilder::buildPostContext( $post );

		if ( AiImageGenerator::isInFlight( $post_id, $heading_hash ) ) {
			return false;
		}

		if ( GenerationRejectionStore::isBlocked( $post_id, $heading_hash, $focus, $style ) ) {
			return false;
		}

		$generator = new AiImageGenerator();
		if ( $generator->findGenerated( $post_id, $heading_hash, $focus, $style ) ) {
			return false;
		}

		AiImageGenerator::setStatus(
			$post_id,
			$heading_hash,
			array(
				'status' => 'queued',
			)
		);

		$job_id = ( new Queue() )->enqueueAiImageGen(
			array(
				'heading_hash'  => $heading_hash,
				'heading_text'  => $heading_text,
				'section_text'  => $excerpt,
				'post_id'       => $post_id,
				'focus_keyword' => $focus,
				'style'         => $style,
				'force'         => false,
			)
		);

		if ( null === $job_id ) {
			AiImageGenerator::setStatus(
				$post_id,
				$heading_hash,
				array(
					'status' => 'failed',
					'error'  => __( 'Could not enqueue image generation.', 'smart-image-matcher' ),
				)
			);
			Logger::warn(
				'ArticleGenerationFallback: enqueue failed',
				array(
					'post_id'      => $post_id,
					'heading_hash' => $heading_hash,
				)
			);
			return false;
		}

		return true;
	}
}
