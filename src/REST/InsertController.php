<?php
/**
 * REST controller: insertion endpoints.
 *
 * POST /smart-image-matcher/v1/posts/<id>/insert
 * POST /smart-image-matcher/v1/posts/<id>/insert-batch
 *
 * Replaces the legacy smart_image_matcher_insert_image and smart_image_matcher_insert_all_images AJAX handlers.
 * Uses stable heading hashes, never byte offsets.
 *
 * @package SmartImageMatcher\REST
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\MatchRepository;
use SmartImageMatcher\Insertion\BlockBuilder;
use SmartImageMatcher\Insertion\InsertionService;

/**
 * Class InsertController
 *
 * @since 3.0.0
 */
class InsertController extends Controller {

	/**
	 * Register routes.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerRoutes(): void {
		// Single insert.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>[\d]+)/insert',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'insertOne' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'args'                => array(
					'post_id'      => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'heading_hash' => array( 'type' => 'string',  'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'image_id'     => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				),
			)
		);

		// Batch insert.
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>[\d]+)/insert-batch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'insertBatch' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'args'                => array(
					'post_id'    => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'insertions' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'heading_hash' => array( 'type' => 'string' ),
								'image_id'     => array( 'type' => 'integer' ),
							),
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
				__( 'You do not have permission to insert images into this post.', 'smart-image-matcher' ),
				array( 'status' => 403 )
			);
		}

		$image_ids = $this->getRequestedImageIds( $request );
		if ( empty( $image_ids ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'No valid image attachment was provided.', 'smart-image-matcher' ),
				array( 'status' => 403 )
			);
		}

		foreach ( $image_ids as $image_id ) {
			if (
				$image_id <= 0
				|| ! current_user_can( 'read_post', $image_id )
				|| ! wp_attachment_is_image( $image_id )
			) {
				return new \WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to use one or more selected images.', 'smart-image-matcher' ),
					array( 'status' => 403 )
				);
			}
		}

		return true;
	}

	/**
	 * Extract image attachment IDs from single or batch insert requests.
	 *
	 * @since 3.0.8
	 * @param \WP_REST_Request $request Request.
	 * @return array<int>
	 */
	private function getRequestedImageIds( \WP_REST_Request $request ): array {
		$image_id = absint( $request->get_param( 'image_id' ) );
		if ( $image_id > 0 ) {
			return array( $image_id );
		}

		$raw_items = $request->get_param( 'insertions' );
		if ( ! is_array( $raw_items ) ) {
			return array();
		}

		$image_ids = array();
		foreach ( $raw_items as $item ) {
			if ( is_array( $item ) && isset( $item['image_id'] ) ) {
				$image_ids[] = absint( $item['image_id'] );
			}
		}

		return array_values( array_unique( $image_ids ) );
	}

	/**
	 * Insert a single image after a heading.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function insertOne( \WP_REST_Request $request ) {
		$postId      = (int)    $request->get_param( 'post_id' );
		$headingHash = (string) $request->get_param( 'heading_hash' );
		$imageId     = (int)    $request->get_param( 'image_id' );

		$service = new InsertionService( new BlockBuilder() );
		$result  = $service->insert( $postId, $headingHash, $imageId );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update audit trail.
		( new MatchRepository() )->markApproved( $postId, $imageId, $headingHash );

		return rest_ensure_response( array(
			'inserted'     => true,
			'post_id'      => $postId,
			'image_id'     => $imageId,
			'heading_hash' => $headingHash,
		) );
	}

	/**
	 * Insert multiple images in a single post update (ONE revision).
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function insertBatch( \WP_REST_Request $request ) {
		$postId     = (int) $request->get_param( 'post_id' );
		$rawItems   = $request->get_param( 'insertions' );

		if ( ! is_array( $rawItems ) || empty( $rawItems ) ) {
			return new \WP_Error( 'smart_image_matcher_empty_insertions', __( 'No insertions provided.', 'smart-image-matcher' ), array( 'status' => 400 ) );
		}

		// Sanitize each item.
		$insertions = array();
		foreach ( $rawItems as $item ) {
			if ( empty( $item['heading_hash'] ) || empty( $item['image_id'] ) ) {
				continue;
			}
			$insertions[] = array(
				'heading_hash' => sanitize_text_field( (string) $item['heading_hash'] ),
				'image_id'     => absint( $item['image_id'] ),
			);
		}

		if ( empty( $insertions ) ) {
			return new \WP_Error( 'smart_image_matcher_empty_insertions', __( 'No valid insertions after sanitization.', 'smart-image-matcher' ), array( 'status' => 400 ) );
		}

		$service = new InsertionService( new BlockBuilder() );
		$result  = $service->bulkInsert( $postId, $insertions );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update audit trail for each insertion.
		$repo = new MatchRepository();
		foreach ( $insertions as $item ) {
			$repo->markApproved( $postId, $item['image_id'], $item['heading_hash'] );
		}

		return rest_ensure_response( array(
			'inserted' => count( $insertions ),
			'post_id'  => $postId,
		) );
	}
}
