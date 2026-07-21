<?php
/**
 * Ability: assign-featured-image-by-slug
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
 * Class AbilityAssignFeaturedImage
 *
 * @since 3.0.0
 */
class AbilityAssignFeaturedImage {

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
			'smart-image-matcher/assign-featured-image-by-slug',
			array(
				'label'       => __( 'Assign featured image by slug', 'smart-image-matcher' ),
				'description' => __( "Sets the post's featured image to the best media-library attachment slug match.", 'smart-image-matcher' ),
				'category'    => 'smart-image-matcher',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'   => array( 'type' => 'integer', 'required' => true ),
						'overwrite' => array( 'type' => 'boolean', 'default'  => false ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'assigned'      => array( 'type' => 'boolean' ),
						'attachment_id' => array( 'type' => array( 'integer', 'null' ) ),
						'reason'        => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => static function ( array $args ): bool {
					$post_id = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
					return $post_id > 0 && current_user_can( 'edit_post', $post_id );
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
		$postId    = absint( $args['post_id'] ?? 0 );
		$overwrite = ! empty( $args['overwrite'] );

		$service = new \SmartImageMatcher\FeaturedImages\FeaturedImageService(
			new \SmartImageMatcher\FeaturedImages\SlugMapBuilder()
		);

		return $service->assignBestForPost( $postId, $overwrite );
	}
}
