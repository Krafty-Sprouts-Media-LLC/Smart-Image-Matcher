<?php
/**
 * Premium: Auto-generate featured image when a post is first published.
 *
 * Tries FIAA slug matching first, then queues AI generation when enabled.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\PromptBuilder;
use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\FeaturedImages\SlugMapBuilder;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Settings\Settings;

/**
 * Class AutoMatchOnPublish
 *
 * @since 3.0.0
 */
class AutoMatchOnPublish {

	/**
	 * Register hooks when auto-publish is enabled.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::get( 'ai_image_auto_featured_on_publish' ) ) {
			return;
		}

		if ( ! Settings::get( 'ai_image_generation_enabled' ) ) {
			return;
		}

		add_action( 'transition_post_status', array( $this, 'onTransitionPostStatus' ), 20, 3 );
	}

	/**
	 * Queue featured-image generation when a post is first published.
	 *
	 * @since 3.2.0
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function onTransitionPostStatus( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return;
		}

		if ( get_current_user_id() > 0 && ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		if ( has_post_thumbnail( $post->ID ) ) {
			return;
		}

		if ( ! ProviderBridge::isImageGenerationAvailable() ) {
			return;
		}

		$fiaa = new FeaturedImageService( new SlugMapBuilder() );
		$slug_result = $fiaa->assignBestForPost( $post->ID, false );
		if ( ! empty( $slug_result['assigned'] ) ) {
			Logger::info(
				'AutoMatchOnPublish: FIAA slug match assigned featured image',
				array( 'post_id' => $post->ID )
			);
			return;
		}

		$style = (string) Settings::get( 'ai_image_style' );
		if ( 'illustration' !== $style ) {
			$style = 'photo';
		}

		$focus   = PromptBuilder::getFocusKeyword( $post->ID );
		$excerpt = PromptBuilder::buildPostContext( $post );

		if ( AiImageGenerator::isInFlight( $post->ID, 'featured' ) ) {
			Logger::info(
				'AutoMatchOnPublish: featured AI gen already in flight',
				array( 'post_id' => $post->ID )
			);
			return;
		}

		AiImageGenerator::setStatus(
			$post->ID,
			'featured',
			array(
				'status' => 'queued',
			)
		);

		$job_id = ( new Queue() )->enqueueAiImageGen(
			array(
				'heading_hash'  => 'featured',
				'heading_text'  => $post->post_title,
				'section_text'  => $excerpt,
				'post_id'       => $post->ID,
				'focus_keyword' => $focus,
				'style'         => $style,
				'force'         => false,
			)
		);

		if ( null === $job_id ) {
			AiImageGenerator::setStatus(
				$post->ID,
				'featured',
				array(
					'status' => 'failed',
					'error'  => __( 'Could not enqueue image generation.', 'smart-image-matcher' ),
				)
			);
			Logger::warn(
				'AutoMatchOnPublish: failed to enqueue featured image generation',
				array( 'post_id' => $post->ID )
			);
			return;
		}

		Logger::info(
			'AutoMatchOnPublish: queued featured image generation',
			array(
				'post_id' => $post->ID,
				'job_id'  => $job_id,
			)
		);
	}
}
