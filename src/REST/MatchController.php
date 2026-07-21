<?php
/**
 * REST controller: POST /smart-image-matcher/v1/posts/<id>/match
 *
 * Replaces the legacy smart_image_matcher_find_matches AJAX handler.
 *
 * @package SmartImageMatcher\REST
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Cache\Cache;
use SmartImageMatcher\Domain\HeadingExtractor;
use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Domain\Matcher;
use SmartImageMatcher\Domain\MatchRepository;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Settings\Settings;

/**
 * Class MatchController
 *
 * @since 3.0.0
 */
class MatchController extends Controller {

	/**
	 * Register routes.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>[\d]+)/match',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'findMatches' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => array(
						'post_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'mode' => array(
							'type'              => 'string',
							'enum'              => array( 'keyword', 'ai' ),
							'default'           => 'keyword',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function checkPermission( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to match images for this post.', 'smart-image-matcher' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Find matches for a post and return per-heading results.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function findMatches( \WP_REST_Request $request ) {
		$postId = (int) $request->get_param( 'post_id' );
		$mode   = (string) $request->get_param( 'mode' );

		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'smart_image_matcher_post_not_found', __( 'Post not found.', 'smart-image-matcher' ), array( 'status' => 404 ) );
		}

		Logger::info( 'MatchController: find matches', array( 'post_id' => $postId, 'mode' => $mode ) );

		// Cache key: post + its last modified time + mode.
		// If the post hasn't changed, return cached matches instantly.
		$cacheKey = 'smart_image_matcher_matches_' . $postId . '_' . strtotime( $post->post_modified_gmt ) . '_' . $mode;
		$cached   = get_transient( $cacheKey );

		if ( false !== $cached && is_array( $cached ) ) {
			Logger::info( 'MatchController: cache hit', array( 'post_id' => $postId ) );
			return rest_ensure_response( array( 'matches' => $cached, 'from_cache' => true ) );
		}

		// Extract headings.
		$extractor = new HeadingExtractor();
		$headings  = $extractor->extract( $post->post_content );

		if ( empty( $headings ) ) {
			return rest_ensure_response( array( 'matches' => array(), 'headings_found' => 0 ) );
		}

		// Apply hierarchy filter.
		$matcher        = new Matcher();
		$hierarchyMode  = (string) Settings::get( 'hierarchy_mode' );
		$headings       = $matcher->filterByHierarchy( $headings, $hierarchyMode );

		// Score per heading using the inverted index (Phase 3).
		// For AI mode: enqueue a background job and return a queued status.
		if ( 'ai' === $mode && \SmartImageMatcher\AI\ProviderBridge::isAvailable() && \SmartImageMatcher\Queue\Queue::isAvailable() ) {
			$actionId = ( new \SmartImageMatcher\Queue\Queue() )->enqueueAiMatch( $postId, 'ai' );

			if ( $actionId ) {
				return rest_ensure_response( array(
					'status'     => 'queued',
					'job_id'     => $actionId,
					'post_id'    => $postId,
					'poll_url'   => rest_url( 'smart-image-matcher/v1/match/status?post_id=' . $postId ),
					'from_cache' => false,
				) );
			}
		}

		// Keyword mode (or AI mode fallback when AS/provider unavailable).
		$repo   = new ImageRepository();
		$groups = array();
		foreach ( $headings as $heading ) {
			$terms  = $matcher->extractKeywords( $heading['text'] ?? '' );
			$images = $repo->findCandidates( $terms );
			$matches = $matcher->findKeywordMatches( $heading, $images );
			$groups[] = array( 'heading' => $heading, 'matches' => $matches );
		}

		// Persist to the matches table (deduped by MatchRepository).
		( new MatchRepository() )->saveMatchGroups( $postId, $groups );

		// Cache for post_modified lifetime.
		$cacheTtl = (int) Settings::get( 'cache_match_results_duration' ) ?: 3600;
		set_transient( $cacheKey, $groups, $cacheTtl );

		return rest_ensure_response( array(
			'matches'       => $groups,
			'headings_found' => count( $headings ),
			'from_cache'    => false,
		) );
	}

}
