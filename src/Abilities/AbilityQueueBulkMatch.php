<?php
/**
 * Ability: queue-bulk-match (Premium)
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
 * Class AbilityQueueBulkMatch
 *
 * @since 3.0.0
 */
class AbilityQueueBulkMatch {

	/**
	 * Register the ability.
	 *
	 * Only called when Premium::has('bulk_processor') is true.
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
			'smart-image-matcher/queue-bulk-match',
			array(
				'label'       => __( 'Queue bulk image matching', 'smart-image-matcher' ),
				'description' => __( 'Queues a background job to find image matches across all posts of a given post type.', 'smart-image-matcher' ),
				'category'    => 'smart-image-matcher',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'   => array( 'type' => 'string',  'required' => true ),
						'mode'        => array( 'type' => 'string',  'enum' => array( 'keyword', 'ai' ), 'default' => 'keyword' ),
						'min_score'   => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'default' => 70 ),
						'auto_insert' => array( 'type' => 'boolean', 'default' => false ),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'job_id' => array( 'type' => 'string' ),
						'queued' => array( 'type' => 'integer' ),
					),
				),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
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
		$postType   = sanitize_key( $args['post_type'] ?? 'post' );
		$mode       = sanitize_key( $args['mode'] ?? 'keyword' );
		$minScore   = absint( $args['min_score'] ?? 70 );
		$autoInsert = ! empty( $args['auto_insert'] );

		$jobId  = 'smart_image_matcher_' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$config = array( 'mode' => $mode, 'min_score' => $minScore, 'auto_insert' => $autoInsert, 'post_type' => $postType );

		$postIds = $this->getPostIdsForJob( $postType );

		if ( empty( $postIds ) ) {
			return new \WP_Error( 'smart_image_matcher_no_posts', __( 'No posts found.', 'smart-image-matcher' ) );
		}

		$queue  = new \SmartImageMatcher\Queue\Queue();
		$queued = 0;

		foreach ( $postIds as $postId ) {
			$actionId = $queue->enqueueBulkMatchPost( $jobId, (int) $postId, $config );
			if ( $actionId ) {
				$queued++;
			}
		}

		return array( 'job_id' => $jobId, 'queued' => $queued );
	}

	/**
	 * Fetch post IDs in bounded batches for the ability-triggered bulk job.
	 *
	 * @since 3.0.0
	 * @param string $postType Post type.
	 * @return int[]
	 */
	private function getPostIdsForJob( string $postType ): array {
		$postIds = array();
		$page    = 1;
		$perPage = 200;
		$max     = (int) apply_filters( 'smart_image_matcher_bulk_job_max_posts', 5000, $postType ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- smart_image_matcher_ is the project hook prefix.

		do {
			$batch = get_posts( array(
				'post_type'              => $postType,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => $perPage,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );

			if ( empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $postId ) {
				$postIds[] = (int) $postId;

				if ( count( $postIds ) >= $max ) {
					break 2;
				}
			}

			$page++;
		} while ( count( $batch ) === $perPage );

		return $postIds;
	}
}
