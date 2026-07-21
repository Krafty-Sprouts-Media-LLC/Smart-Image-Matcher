<?php
/**
 * Ability: score-image-against-heading
 *
 * @package SmartImageMatcher\Abilities
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AbilityScoreImage
 *
 * @since 3.0.0
 */
class AbilityScoreImage {

	/**
	 * Register the ability.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$register_ability = 'wp_register_ability';
		$register_ability(
			'smart-image-matcher/score-image-against-heading',
			array(
				'label'       => __( 'Score image against heading', 'smart-image-matcher' ),
				'description' => __( 'Returns the keyword-match confidence score (0-100) for an image vs. a heading text.', 'smart-image-matcher' ),
				'category'    => 'smart-image-matcher',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'heading_text' => array( 'type' => 'string',  'required' => true ),
						'image_id'     => array( 'type' => 'integer', 'required' => true ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'confidence_score' => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ),
					),
				),
				'permission_callback' => static function ( array $args ): bool {
					$image_id = absint( $args['image_id'] ?? 0 );

					return $image_id > 0
						&& current_user_can( 'edit_posts' )
						&& current_user_can( 'read_post', $image_id )
						&& wp_attachment_is_image( $image_id );
				},
				'execute_callback' => array( $this, 'execute' ),
				'meta'             => array(
					'category'     => 'smart-image-matcher',
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $args Input args.
	 * @return array<string, mixed>
	 */
	public function execute( array $args ): array {
		$headingText = sanitize_text_field( wp_unslash( (string) ( $args['heading_text'] ?? '' ) ) );
		$imageId     = absint( $args['image_id'] ?? 0 );

		$matcher  = new \SmartImageMatcher\Domain\Matcher();
		$keywords = $matcher->extractKeywords( $headingText );
		$imageMeta = array(
			'id'       => $imageId,
			'filename' => basename( (string) get_attached_file( $imageId ) ),
			'alt'      => (string) get_post_meta( $imageId, '_wp_attachment_image_alt', true ),
			'title'    => get_the_title( $imageId ),
			'caption'  => (string) wp_get_attachment_caption( $imageId ),
			'url'      => (string) wp_get_attachment_url( $imageId ),
		);

		$score = $matcher->calculateScore( $keywords, $imageMeta );

		return array( 'confidence_score' => $score );
	}
}
