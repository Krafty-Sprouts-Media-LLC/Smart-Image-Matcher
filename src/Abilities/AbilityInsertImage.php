<?php
/**
 * Ability: insert-image-after-heading
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
 * Class AbilityInsertImage
 *
 * @since 3.0.0
 */
class AbilityInsertImage {

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
			'smart-image-matcher/insert-image-after-heading',
			array(
				'label'       => __( 'Insert image after heading', 'smart-image-matcher' ),
				'description' => __( 'Inserts a media-library image immediately after the specified heading block in a post.', 'smart-image-matcher' ),
				'category'    => 'smart-image-matcher',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer', 'required' => true ),
						'heading_hash' => array( 'type' => 'string',  'required' => true ),
						'image_id'     => array( 'type' => 'integer', 'required' => true ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'inserted' => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => static function ( array $args ): bool {
					$post_id  = isset( $args['post_id'] )  ? (int) $args['post_id']  : 0;
					$image_id = isset( $args['image_id'] ) ? (int) $args['image_id'] : 0;
					return $post_id > 0
						&& current_user_can( 'edit_post', $post_id )
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
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $args ) {
		$postId      = absint( $args['post_id'] ?? 0 );
		$headingHash = sanitize_text_field( $args['heading_hash'] ?? '' );
		$imageId     = absint( $args['image_id'] ?? 0 );

		$service = new \SmartImageMatcher\Insertion\InsertionService(
			new \SmartImageMatcher\Insertion\BlockBuilder()
		);

		$result = $service->insert( $postId, $headingHash, $imageId );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		( new \SmartImageMatcher\Domain\MatchRepository() )->markApproved( $postId, $imageId, $headingHash );

		return array( 'inserted' => true, 'post_id' => $postId );
	}
}
