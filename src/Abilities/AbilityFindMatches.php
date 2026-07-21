<?php
/**
 * Ability: find-matches-for-post
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
 * Class AbilityFindMatches
 *
 * @since 3.0.0
 */
class AbilityFindMatches {

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
			'smart-image-matcher/find-matches-for-post',
			array(
				'label'       => __( 'Find image matches for post', 'smart-image-matcher' ),
				'description' => __( 'Scans a post\'s headings and returns ranked media-library images per heading.', 'smart-image-matcher' ),
				'category'    => 'smart-image-matcher',
				'input_schema' => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'Target post ID.', 'smart-image-matcher' ),
							'required'    => true,
						),
						'mode' => array(
							'type'    => 'string',
							'enum'    => array( 'keyword', 'ai' ),
							'default' => 'keyword',
						),
					),
				),
				'output_schema' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
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
	 * @return array<int, mixed>|\WP_Error
	 */
	public function execute( array $args ) {
		$postId = absint( $args['post_id'] ?? 0 );
		$mode   = sanitize_key( $args['mode'] ?? 'keyword' );

		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'smart_image_matcher_post_not_found', __( 'Post not found.', 'smart-image-matcher' ) );
		}

		$extractor = new \SmartImageMatcher\Domain\HeadingExtractor();
		$headings  = $extractor->extract( $post->post_content );

		if ( empty( $headings ) ) {
			return array();
		}

		$matcher  = new \SmartImageMatcher\Domain\Matcher();
		$headings = $matcher->filterByHierarchy( $headings, (string) \SmartImageMatcher\Settings\Settings::get( 'hierarchy_mode' ) );
		$repo     = new \SmartImageMatcher\Domain\ImageRepository();
		$groups   = array();

		foreach ( $headings as $heading ) {
			$terms   = $matcher->extractKeywords( $heading['text'] ?? '' );
			$images  = $repo->findCandidates( $terms );
			$matches = $matcher->findKeywordMatches( $heading, $images );
			$groups[] = array( 'heading' => $heading, 'matches' => $matches );
		}

		return $groups;
	}
}
