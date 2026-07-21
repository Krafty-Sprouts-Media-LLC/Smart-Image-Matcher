<?php
/**
 * Premium: AI mode handler.
 *
 * Registers the REST poll-status endpoint so the modal can check whether
 * a background AI job has finished, and wires the AI match AS action.
 *
 * The AI call itself runs in JobRunner::runAiMatchJob() via Action Scheduler.
 * The modal calls /match with mode=ai → gets back {status:'queued', job_id} →
 * polls /match/status?post_id=N until done=true.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Queue\Queue;

/**
 * Class AiMatcher
 *
 * @since 3.0.0
 */
class AiMatcher {

	/**
	 * Register hooks.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerStatusRoute' ) );
	}

	/**
	 * Register the AI job status polling endpoint.
	 *
	 * GET /smart-image-matcher/v1/match/status?post_id=N
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerStatusRoute(): void {
		register_rest_route(
			'smart-image-matcher/v1',
			'/match/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getMatchStatus' ),
				'permission_callback' => static function ( \WP_REST_Request $request ) {
					$post_id = (int) $request->get_param( 'post_id' );
					return $post_id > 0 && current_user_can( 'edit_post', $post_id );
				},
				'args'                => array(
					'post_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	/**
	 * Return the status of a background AI match job for a post.
	 *
	 * The job stores its result as a short-lived transient:
	 *   smart_image_matcher_job_result_{$postId} = { matches: [...], done: true|false }
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function getMatchStatus( \WP_REST_Request $request ): \WP_REST_Response {
		$postId = (int) $request->get_param( 'post_id' );
		$result = get_transient( "smart_image_matcher_job_result_{$postId}" );

		if ( false === $result || ! is_array( $result ) ) {
			return rest_ensure_response( array(
				'done'    => false,
				'matches' => array(),
				'ai_available' => ProviderBridge::isAvailable(),
			) );
		}

		return rest_ensure_response( array(
			'done'         => ! empty( $result['done'] ),
			'matches'      => $result['matches'] ?? array(),
			'ai_available' => ProviderBridge::isAvailable(),
		) );
	}

	/**
	 * Enqueue an AI match job for a post (called from MatchController when mode=ai).
	 *
	 * @since 3.0.0
	 * @param int $postId Post ID.
	 * @return string|null Job action ID, or null if AS unavailable.
	 */
	public static function enqueueForPost( int $postId ): ?string {
		return ( new Queue() )->enqueueAiMatch( $postId, 'ai' );
	}
}
