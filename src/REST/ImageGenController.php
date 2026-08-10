<?php
/**
 * REST controller: on-demand AI image generation + status polling.
 *
 * POST /smart-image-matcher/v1/posts/<id>/generate-image
 * GET  /smart-image-matcher/v1/generate-image/status
 *
 * @package SmartImageMatcher\REST
 * @since   3.1.1
 */

declare( strict_types=1 );

namespace SmartImageMatcher\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\GenerationRejectionStore;
use SmartImageMatcher\AI\PromptBuilder;
use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Domain\HeadingExtractor;
use SmartImageMatcher\Domain\PostStatuses;
use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\Premium\AiImageGenerator;
use SmartImageMatcher\Premium\FalRecoverBatch;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Settings\Settings;

/**
 * Class ImageGenController
 *
 * @since 3.1.1
 */
class ImageGenController extends Controller {

	/**
	 * Register routes.
	 *
	 * @since 3.1.1
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>[\d]+)/generate-image',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'enqueueGenerate' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => array(
						'post_id'       => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'heading_hash'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'heading_text'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'focus_keyword' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'style'         => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'photo',
							'enum'              => array( 'photo', 'illustration' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'force'         => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate-images/scan',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'scanPosts' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => array(
						'post_type'     => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'post',
							'sanitize_callback' => 'sanitize_key',
						),
						'post_statuses' => array(
							'type'     => 'array',
							'required' => false,
							'default'  => array( 'publish' ),
							'items'    => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_key',
							),
						),
						'post_ids'      => array(
							'type'     => 'array',
							'required' => false,
							'default'  => array(),
							'items'    => array(
								'type'              => 'integer',
								'sanitize_callback' => 'absint',
							),
						),
						'max_posts'     => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 100,
							'sanitize_callback' => 'absint',
						),
						'style'         => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'photo',
							'enum'              => array( 'photo', 'illustration' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate-images/enqueue',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'enqueueBulk' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => array(
						'items'         => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'post_id'      => array( 'type' => 'integer' ),
									'heading_hash' => array( 'type' => 'string' ),
									'heading_text' => array( 'type' => 'string' ),
								),
							),
						),
						'style'         => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'photo',
							'enum'              => array( 'photo', 'illustration' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'focus_keyword' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate-image/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getStatus' ),
					'permission_callback' => array( $this, 'checkStatusPermission' ),
					'args'                => array(
						'post_id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'heading_hash' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate-images/active',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'listActive' ),
					'permission_callback' => array( $this, 'checkActiveListPermission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate-images/recover',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'recoverFalJobs' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => array(
						'post_id'         => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
						'heading_hash'    => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'featured',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'request_id'      => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'model_id'        => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'all_pending'     => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						),
						'discover_recent' => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						),
						'dry_run'         => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						),
						'hours'           => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 48,
							'sanitize_callback' => 'absint',
						),
						'request_ids'     => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'csv'             => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'match_posts'     => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						),
						'unattached'      => array(
							'type'     => 'boolean',
							'required' => false,
							'default'  => false,
						),
						'min_score'       => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 60,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>[\d]+)/generate-image/reject',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rejectGenerated' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => array(
						'post_id'       => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'heading_hash'  => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'focus_keyword' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'style'         => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'photo',
							'enum'              => array( 'photo', 'illustration' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission for generate.
	 *
	 * @since 3.1.1
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function checkPermission( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to generate images for this post.', 'smart-image-matcher' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Permission for status poll.
	 *
	 * @since 3.1.1
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function checkStatusPermission( \WP_REST_Request $request ) {
		return $this->checkPermission( $request );
	}

	/**
	 * Permission for listing in-flight generation jobs (posts list dock).
	 *
	 * @since 3.2.20
	 * @return bool|\WP_Error
	 */
	public function checkActiveListPermission() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view generation jobs.', 'smart-image-matcher' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Permission for bulk scan / enqueue (site admin).
	 *
	 * @since 3.2.0
	 * @return bool|\WP_Error
	 */
	public function checkAdminPermission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to manage image generation.', 'smart-image-matcher' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Scan posts missing a featured image (one image per post).
	 *
	 * When explicit post_ids are supplied, every ID appears in `posts` or `skipped`.
	 * Bulk admin does NOT scan in-content headings — those stay manual in the modal.
	 *
	 * @since 3.2.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scanPosts( \WP_REST_Request $request ) {
		$post_type     = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$post_statuses = PostStatuses::sanitizeList( $request->get_param( 'post_statuses' ) );
		$post_ids      = array_filter( array_map( 'absint', (array) $request->get_param( 'post_ids' ) ) );
		$max_posts     = max( 1, min( 500, (int) $request->get_param( 'max_posts' ) ) );
		$style         = ( 'illustration' === (string) $request->get_param( 'style' ) ) ? 'illustration' : 'photo';

		if ( empty( $post_ids ) && ( ! post_type_exists( $post_type ) || 'attachment' === $post_type ) ) {
			return new \WP_Error(
				'smart_image_matcher_invalid_post_type',
				__( 'Invalid post type.', 'smart-image-matcher' ),
				array( 'status' => 400 )
			);
		}

		$query_args = array(
			'post_status'            => empty( $post_ids ) ? $post_statuses : 'any',
			'posts_per_page'         => $max_posts,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'fields'                 => 'ids',
		);

		if ( ! empty( $post_ids ) ) {
			$query_args['post__in']       = $post_ids;
			$query_args['orderby']        = 'post__in';
			$query_args['posts_per_page'] = count( $post_ids );
			$query_args['post_type']      = 'any';
		} else {
			$query_args['post_type'] = $post_type;
			// No meta_query here — classify in PHP so filter-only or placeholder fallbacks (e.g. KSM Extensions) are not treated as real featured images.
		}

		$query = new \WP_Query( $query_args );

		$generator    = new AiImageGenerator();
		$posts        = array();
		$skipped      = array();
		$total_images = 0;
		$seen         = array();

		foreach ( $query->posts as $post_id ) {
			$post_id          = (int) $post_id;
			$seen[ $post_id ] = true;

			$classified = $this->classifyFeaturedScanPost( $post_id, $style, $generator );
			if ( 'eligible' === $classified['status'] ) {
				++$total_images;
				$posts[] = $classified['post'];
			} else {
				$skipped[] = $classified['skipped'];
			}
		}

		// Explicit IDs that never appeared in the query (deleted / inaccessible).
		foreach ( $post_ids as $requested_id ) {
			$requested_id = (int) $requested_id;
			if ( isset( $seen[ $requested_id ] ) ) {
				continue;
			}

			if ( ! current_user_can( 'edit_post', $requested_id ) ) {
				$skipped[] = array(
					'id'     => $requested_id,
					'title'  => sprintf(
						/* translators: %d: post ID */
						__( 'Post #%d', 'smart-image-matcher' ),
						$requested_id
					),
					'reason' => 'no_permission',
				);
				continue;
			}

			$post = get_post( $requested_id );
			if ( ! $post instanceof \WP_Post ) {
				$skipped[] = array(
					'id'     => $requested_id,
					'title'  => sprintf(
						/* translators: %d: post ID */
						__( 'Post #%d', 'smart-image-matcher' ),
						$requested_id
					),
					'reason' => 'not_found',
				);
				continue;
			}

			$classified = $this->classifyFeaturedScanPost( $requested_id, $style, $generator );
			if ( 'eligible' === $classified['status'] ) {
				++$total_images;
				$posts[] = $classified['post'];
			} else {
				$skipped[] = $classified['skipped'];
			}
		}

		return rest_ensure_response(
			array(
				'posts'            => $posts,
				'skipped'          => $skipped,
				'total_images'     => $total_images,
				'estimate_seconds' => 0,
				'estimate_hint'    => __( 'Usually a few minutes per image (varies by model and queue).', 'smart-image-matcher' ),
				'mode'             => 'featured',
			)
		);
	}

	/**
	 * Classify one post for featured AI scan.
	 *
	 * @since 3.2.3
	 * @param int              $post_id   Post ID.
	 * @param string           $style     photo|illustration.
	 * @param AiImageGenerator $generator Generator.
	 * @return array{status:string,post?:array<string,mixed>,skipped?:array<string,mixed>}
	 */
	private function classifyFeaturedScanPost( int $post_id, string $style, AiImageGenerator $generator ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'status'  => 'skipped',
				'skipped' => array(
					'id'     => $post_id,
					'title'  => sprintf(
						/* translators: %d: post ID */
						__( 'Post #%d', 'smart-image-matcher' ),
						$post_id
					),
					'reason' => 'not_found',
				),
			);
		}

		$title = get_the_title( $post_id );

		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return array(
				'status'  => 'skipped',
				'skipped' => array(
					'id'     => $post_id,
					'title'  => $title,
					'reason' => 'no_thumbnail_support',
				),
			);
		}

		if ( FeaturedImageService::hasActionableFeaturedImage( $post_id ) ) {
			return array(
				'status'  => 'skipped',
				'skipped' => array(
					'id'     => $post_id,
					'title'  => $title,
					'reason' => 'already_has_featured',
				),
			);
		}

		$focus_keyword = PromptBuilder::getFocusKeyword( $post_id );

		if ( $generator->findGenerated( $post_id, 'featured', $focus_keyword, $style ) ) {
			return array(
				'status'  => 'skipped',
				'skipped' => array(
					'id'     => $post_id,
					'title'  => $title,
					'reason' => 'already_generated',
				),
			);
		}

		if ( GenerationRejectionStore::isBlocked( $post_id, 'featured', $focus_keyword, $style ) ) {
			return array(
				'status'  => 'skipped',
				'skipped' => array(
					'id'     => $post_id,
					'title'  => $title,
					'reason' => 'rejected',
				),
			);
		}

		return array(
			'status' => 'eligible',
			'post'   => array(
				'id'              => $post_id,
				'title'           => $title,
				'unmatched_count' => 1,
				'heading_hashes'  => array( 'featured' ),
				'headings'        => array(
					array(
						'heading_hash' => 'featured',
						'heading_text' => (string) $post->post_title,
					),
				),
			),
		);
	}

	/**
	 * Enqueue featured-image generation jobs (one Action Scheduler job per post).
	 *
	 * @since 3.2.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enqueueBulk( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'ai_image_generation_enabled' ) ) {
			return new \WP_Error(
				'smart_image_matcher_disabled',
				__( 'On-demand image generation is disabled in settings.', 'smart-image-matcher' ),
				array( 'status' => 400 )
			);
		}

		if ( ! ProviderBridge::isImageGenerationAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_ai_image_unavailable',
				__( 'No image-capable AI provider configured for the preferred models. Connect fal.ai under Settings → Connectors.', 'smart-image-matcher' ),
				array( 'status' => 503 )
			);
		}

		if ( ! Queue::isAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_queue_unavailable',
				__( 'Background queue unavailable. Install/activate Action Scheduler and try again.', 'smart-image-matcher' ),
				array( 'status' => 503 )
			);
		}

		$items         = (array) $request->get_param( 'items' );
		$style         = ( 'illustration' === (string) $request->get_param( 'style' ) ) ? 'illustration' : 'photo';
		$focus_keyword = (string) $request->get_param( 'focus_keyword' );
		$generator     = new AiImageGenerator();
		$queue         = new Queue();

		$queued  = 0;
		$skipped = 0;
		$jobs    = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				++$skipped;
				continue;
			}

			$post_id = absint( $item['post_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				++$skipped;
				continue;
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				++$skipped;
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				++$skipped;
				continue;
			}

			if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
				++$skipped;
				continue;
			}

			if ( FeaturedImageService::hasActionableFeaturedImage( $post_id ) ) {
				++$skipped;
				continue;
			}

			$heading_hash = 'featured';
			$heading_text = (string) $post->post_title;
			$item_keyword = '' !== $focus_keyword ? $focus_keyword : PromptBuilder::getFocusKeyword( $post_id );
			$section_text = PromptBuilder::buildPostContext( $post );

			if ( $generator->findGenerated( $post_id, $heading_hash, $item_keyword, $style ) ) {
				++$skipped;
				continue;
			}

			if ( GenerationRejectionStore::isBlocked( $post_id, $heading_hash, $item_keyword, $style ) ) {
				++$skipped;
				continue;
			}

			if ( AiImageGenerator::isInFlight( $post_id, $heading_hash ) ) {
				++$skipped;
				continue;
			}

			AiImageGenerator::setStatus(
				$post_id,
				$heading_hash,
				array(
					'status' => 'queued',
				)
			);

			$job_id = $queue->enqueueAiImageGen(
				array(
					'heading_hash'  => $heading_hash,
					'heading_text'  => $heading_text,
					'section_text'  => $section_text,
					'post_id'       => $post_id,
					'focus_keyword' => $item_keyword,
					'style'         => $style,
					'force'         => false,
				)
			);

			if ( ! $job_id ) {
				AiImageGenerator::setStatus(
					$post_id,
					$heading_hash,
					array(
						'status' => 'failed',
						'error'  => __( 'Could not enqueue image generation.', 'smart-image-matcher' ),
					)
				);
				++$skipped;
				continue;
			}

			++$queued;
			$jobs[] = array(
				'post_id'      => $post_id,
				'heading_hash' => $heading_hash,
				'job_id'       => $job_id,
				'poll_url'     => add_query_arg(
					array(
						'post_id'      => $post_id,
						'heading_hash' => $heading_hash,
					),
					rest_url( self::NAMESPACE . '/generate-image/status' )
				),
			);
		}

		return rest_ensure_response(
			array(
				'queued'  => $queued,
				'skipped' => $skipped,
				'jobs'    => $jobs,
			)
		);
	}

	/**
	 * Enqueue generation (prompt building happens inside the job).
	 *
	 * @since 3.1.1
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enqueueGenerate( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'ai_image_generation_enabled' ) ) {
			return new \WP_Error(
				'smart_image_matcher_disabled',
				__( 'On-demand image generation is disabled in settings.', 'smart-image-matcher' ),
				array( 'status' => 400 )
			);
		}

		if ( ! ProviderBridge::isImageGenerationAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_ai_image_unavailable',
				__( 'No image-capable AI provider configured for the preferred models. Connect fal.ai under Settings → Connectors.', 'smart-image-matcher' ),
				array( 'status' => 503 )
			);
		}

		$post_id       = (int) $request->get_param( 'post_id' );
		$heading_hash  = (string) $request->get_param( 'heading_hash' );
		$heading_text  = (string) $request->get_param( 'heading_text' );
		$focus_keyword = (string) $request->get_param( 'focus_keyword' );
		$style         = (string) $request->get_param( 'style' );
		$force         = (bool) $request->get_param( 'force' );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'smart_image_matcher_post_not_found',
				__( 'Post not found.', 'smart-image-matcher' ),
				array( 'status' => 404 )
			);
		}

		if ( '' === $focus_keyword ) {
			$focus_keyword = PromptBuilder::getFocusKeyword( $post_id );
		}

		// Re-extract section text server-side — do not trust client body text.
		if ( 'featured' === $heading_hash ) {
			$section_text = PromptBuilder::buildPostContext( $post );
		} else {
			$section_text = $this->extractSectionText( $post->post_content, $heading_hash );
		}

		$generator = new AiImageGenerator();

		if ( ! $force ) {
			$existing = $generator->findGenerated( $post_id, $heading_hash, $focus_keyword, $style );
			if ( $existing ) {
				$url = (string) wp_get_attachment_url( $existing );
				return rest_ensure_response(
					array(
						'status'         => 'exists',
						'attachment_id'  => $existing,
						'attachment_url' => $url,
						'prompt_used'    => (string) get_post_meta( $existing, '_sim_generated_prompt', true ),
						'title'          => get_the_title( $existing ),
						'filename'       => basename( (string) get_attached_file( $existing ) ),
						'message'        => __( 'An image was already generated for this heading.', 'smart-image-matcher' ),
					)
				);
			}

			if ( AiImageGenerator::isInFlight( $post_id, $heading_hash ) ) {
				return rest_ensure_response(
					array(
						'status'       => 'queued',
						'heading_hash' => $heading_hash,
						'already'      => true,
						'message'      => __( 'A generation job is already queued or running for this heading.', 'smart-image-matcher' ),
						'poll_url'     => add_query_arg(
							array(
								'post_id'      => $post_id,
								'heading_hash' => $heading_hash,
							),
							rest_url( self::NAMESPACE . '/generate-image/status' )
						),
					)
				);
			}
		}

		if ( ! Queue::isAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_queue_unavailable',
				__( 'Background queue unavailable. Install/activate Action Scheduler and try again.', 'smart-image-matcher' ),
				array( 'status' => 503 )
			);
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
				'section_text'  => $section_text,
				'post_id'       => $post_id,
				'focus_keyword' => $focus_keyword,
				'style'         => $style,
				'force'         => $force,
			)
		);

		if ( ! $job_id ) {
			// Race: another request claimed the unique AS slot after our check.
			if ( AiImageGenerator::isInFlight( $post_id, $heading_hash ) ) {
				return rest_ensure_response(
					array(
						'status'       => 'queued',
						'heading_hash' => $heading_hash,
						'already'      => true,
						'message'      => __( 'A generation job is already queued or running for this heading.', 'smart-image-matcher' ),
						'poll_url'     => add_query_arg(
							array(
								'post_id'      => $post_id,
								'heading_hash' => $heading_hash,
							),
							rest_url( self::NAMESPACE . '/generate-image/status' )
						),
					)
				);
			}

			return new \WP_Error(
				'smart_image_matcher_enqueue_failed',
				__( 'Could not enqueue image generation.', 'smart-image-matcher' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'status'       => 'queued',
				'heading_hash' => $heading_hash,
				'job_id'       => $job_id,
				'poll_url'     => add_query_arg(
					array(
						'post_id'      => $post_id,
						'heading_hash' => $heading_hash,
					),
					rest_url( self::NAMESPACE . '/generate-image/status' )
				),
			)
		);
	}

	/**
	 * Poll generation status.
	 *
	 * @since 3.1.1
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function getStatus( \WP_REST_Request $request ) {
		$post_id      = (int) $request->get_param( 'post_id' );
		$heading_hash = (string) $request->get_param( 'heading_hash' );

		$status = AiImageGenerator::getStatus( $post_id, $heading_hash );
		if ( null === $status ) {
			return rest_ensure_response(
				array(
					'status' => 'processing',
				)
			);
		}

		return rest_ensure_response( $status );
	}

	/**
	 * List in-flight AI image jobs (for sticky dock when sessionStorage is empty).
	 *
	 * @since 3.2.20
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function listActive( \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$pairs = Queue::listInFlightAiImageGens( 100 );
		$jobs  = array();

		foreach ( $pairs as $pair ) {
			$post_id      = (int) $pair['post_id'];
			$heading_hash = (string) $pair['heading_hash'];

			if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			// Posts-list dock tracks featured generations; skip heading jobs.
			if ( 'featured' !== $heading_hash ) {
				continue;
			}

			$status_payload = AiImageGenerator::getStatus( $post_id, $heading_hash );
			$state          = is_array( $status_payload ) && isset( $status_payload['status'] )
				? (string) $status_payload['status']
				: 'processing';

			if ( in_array( $state, array( 'done', 'completed', 'failed', 'error', 'exists' ), true ) ) {
				continue;
			}

			$post  = get_post( $post_id );
			$title = ( $post instanceof \WP_Post ) ? $post->post_title : '';

			$jobs[] = array(
				'post_id'      => $post_id,
				'heading_hash' => $heading_hash,
				'title'        => $title,
				'status'       => $state,
				'poll_url'     => add_query_arg(
					array(
						'post_id'      => $post_id,
						'heading_hash' => $heading_hash,
					),
					rest_url( self::NAMESPACE . '/generate-image/status' )
				),
			);
		}

		return rest_ensure_response(
			array(
				'jobs'  => $jobs,
				'total' => count( $jobs ),
			)
		);
	}

	/**
	 * Recover completed fal images into WordPress (orphan recovery).
	 *
	 * Modes:
	 * - all_pending=true: complete every post with _sim_fal_pending_* meta
	 * - discover_recent=true: query fal history and match requests automatically
	 * - post_id + optional request_id/model_id: recover one post
	 * - csv or request_ids: bulk recover (optionally match_posts / unattached)
	 *
	 * @since 3.2.21
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function recoverFalJobs( \WP_REST_Request $request ) {
		$all_pending  = (bool) $request->get_param( 'all_pending' );
		$discover     = (bool) $request->get_param( 'discover_recent' );
		$dry_run      = (bool) $request->get_param( 'dry_run' );
		$hours        = absint( $request->get_param( 'hours' ) );
		$post_id      = (int) $request->get_param( 'post_id' );
		$heading_hash = (string) $request->get_param( 'heading_hash' );
		$request_id   = (string) $request->get_param( 'request_id' );
		$model_id     = (string) $request->get_param( 'model_id' );
		$csv          = (string) $request->get_param( 'csv' );
		$request_ids  = (string) $request->get_param( 'request_ids' );
		$match_posts  = (bool) $request->get_param( 'match_posts' );
		$unattached   = (bool) $request->get_param( 'unattached' );
		$min_score    = absint( $request->get_param( 'min_score' ) );

		if ( '' === $heading_hash ) {
			$heading_hash = 'featured';
		}
		if ( $min_score <= 0 ) {
			$min_score = 60;
		}
		if ( $hours <= 0 ) {
			$hours = 48;
		}
		if ( '' === $model_id ) {
			$model_id = (string) Settings::get( 'ai_image_model' );
		}

		// Automatic fal history discovery or manually supplied fallback rows.
		if ( $discover || '' !== $csv || '' !== $request_ids ) {
			$rows = array();
			if ( $discover ) {
				$found = FalRecoverBatch::discoverRecentRows( $hours, 500 );
				if ( is_wp_error( $found ) ) {
					return $found;
				}
				$rows        = $found;
				$match_posts = true;
				$preview     = FalRecoverBatch::previewMatches( $rows, $min_score );

				if ( $dry_run ) {
					return rest_ensure_response(
						array(
							'preview'   => true,
							'matched'   => $preview['matched'],
							'unmatched' => $preview['unmatched'],
							'total'     => count( $rows ),
						)
					);
				}

				$queued = FalRecoverBatch::queueMatched( $rows, $preview['matched'], $heading_hash );
				return rest_ensure_response(
					array(
						'jobs'      => $queued['jobs'],
						'queued'    => count( $queued['jobs'] ),
						'failed'    => $queued['failed'],
						'skipped'   => $queued['skipped'],
						'unmatched' => count( $preview['unmatched'] ),
						'total'     => count( $rows ),
					)
				);
			} elseif ( '' !== $csv ) {
				$parsed = FalRecoverBatch::parseCsvString( $csv );
				if ( is_wp_error( $parsed ) ) {
					return $parsed;
				}
				$rows = $parsed;
			} else {
				foreach ( FalRecoverBatch::parseRequestIdList( $request_ids ) as $rid ) {
					$rows[] = array(
						'post_id'    => 0,
						'request_id' => $rid,
						'model_id'   => $model_id,
					);
				}
			}

			$result = FalRecoverBatch::run(
				$rows,
				array(
					'heading_hash' => $heading_hash,
					'model_id'     => $model_id,
					'match_posts'  => $match_posts,
					'unattached'   => $unattached,
					'min_score'    => $min_score,
				)
			);

			return rest_ensure_response(
				array(
					'recovered' => $result['recovered'],
					'failed'    => $result['failed'],
					'skipped'   => $result['skipped'],
					'total'     => count( $rows ),
				)
			);
		}

		$targets = array();

		if ( $all_pending ) {
			foreach ( AiImageGenerator::listFalPending( $heading_hash, 200 ) as $row ) {
				$targets[] = array(
					'post_id'      => (int) $row['post_id'],
					'heading_hash' => (string) $row['heading_hash'],
					'pending'      => $row['pending'],
				);
			}
		} elseif ( $post_id > 0 ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new \WP_Error(
					'rest_forbidden',
					__( 'You do not have permission to recover images for this post.', 'smart-image-matcher' ),
					array( 'status' => 403 )
				);
			}
			$pending = AiImageGenerator::getFalPending( $post_id, $heading_hash );
			if ( ! is_array( $pending ) && '' !== $request_id ) {
				$pending = array(
					'fal'     => array(
						'request_id' => $request_id,
						'model_id'   => $model_id,
					),
					'context' => array(
						'post_id'      => $post_id,
						'heading_hash' => $heading_hash,
						'heading_text' => get_the_title( $post_id ),
						'purpose'      => ( 'featured' === $heading_hash ) ? 'featured' : 'heading',
						'style'        => 'photo',
					),
				);
			}
			if ( ! is_array( $pending ) ) {
				return new \WP_Error(
					'smart_image_matcher_no_pending',
					__( 'No fal pending data for this post. Provide request_id + model_id from the fal dashboard.', 'smart-image-matcher' ),
					array( 'status' => 404 )
				);
			}
			$targets[] = array(
				'post_id'      => $post_id,
				'heading_hash' => $heading_hash,
				'pending'      => $pending,
			);
		} else {
			return new \WP_Error(
				'smart_image_matcher_bad_recover',
				__( 'Pass discover_recent=true, all_pending=true, or post_id. Manual request_ids/csv remain available as fallbacks.', 'smart-image-matcher' ),
				array( 'status' => 400 )
			);
		}

		$recovered = array();
		$failed    = array();

		foreach ( $targets as $target ) {
			$result = AiImageGenerator::recoverFalJob(
				(int) $target['post_id'],
				(string) $target['heading_hash'],
				is_array( $target['pending'] ) ? $target['pending'] : array()
			);
			if ( is_wp_error( $result ) ) {
				$failed[] = array(
					'post_id' => (int) $target['post_id'],
					'error'   => $result->get_error_message(),
				);
				continue;
			}
			$recovered[] = array(
				'post_id'       => (int) $target['post_id'],
				'attachment_id' => (int) $result,
			);
		}

		return rest_ensure_response(
			array(
				'recovered' => $recovered,
				'failed'    => $failed,
				'total'     => count( $targets ),
			)
		);
	}

	/**
	 * Record a user rejection so this combo is not auto-generated again.
	 *
	 * @since 3.2.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rejectGenerated( \WP_REST_Request $request ) {
		$post_id       = (int) $request->get_param( 'post_id' );
		$heading_hash  = (string) $request->get_param( 'heading_hash' );
		$focus_keyword = (string) $request->get_param( 'focus_keyword' );
		$style         = (string) $request->get_param( 'style' );

		if ( '' === $focus_keyword ) {
			$focus_keyword = PromptBuilder::getFocusKeyword( $post_id );
		}

		$style = ( 'illustration' === $style ) ? 'illustration' : 'photo';

		GenerationRejectionStore::block( $post_id, $heading_hash, $focus_keyword, $style );

		return rest_ensure_response(
			array(
				'status'  => 'rejected',
				'message' => __( 'This heading will be skipped on future Generate runs. Use Regenerate to override.', 'smart-image-matcher' ),
			)
		);
	}

	/**
	 * Extract the first paragraph(s) after a heading, by stable hash.
	 *
	 * @since 3.1.1
	 * @param string $content      Post content.
	 * @param string $heading_hash Heading hash.
	 * @return string
	 */
	protected function extractSectionText( string $content, string $heading_hash ): string {
		$extractor = new HeadingExtractor();
		$headings  = $extractor->extract( $content );

		$index = null;
		foreach ( $headings as $i => $heading ) {
			if ( ( $heading['heading_hash'] ?? '' ) === $heading_hash ) {
				$index = $i;
				break;
			}
		}

		if ( null === $index ) {
			return '';
		}

		// Prefer Gutenberg: walk blocks for paragraph after matching heading.
		if ( has_blocks( $content ) ) {
			$text = $this->sectionFromBlocks( $content, $heading_hash );
			if ( '' !== $text ) {
				return $text;
			}
		}

		// Classic / fallback: strip tags and take a window after the heading text.
		$plain  = wp_strip_all_tags( $content );
		$needle = $headings[ $index ]['text'] ?? '';
		$pos    = ( '' !== $needle ) ? stripos( $plain, $needle ) : false;
		if ( false === $pos ) {
			return '';
		}

		$after = substr( $plain, $pos + strlen( $needle ) );
		return wp_trim_words( $after, PromptBuilder::CONTEXT_WORD_LIMIT );
	}

	/**
	 * Collect paragraph text after a heading block with the given hash.
	 *
	 * @since 3.1.1
	 * @param string $content      Post content.
	 * @param string $heading_hash Heading hash.
	 * @return string
	 */
	private function sectionFromBlocks( string $content, string $heading_hash ): string {
		$blocks    = parse_blocks( $content );
		$found     = false;
		$chunks    = array();
		$extractor = new HeadingExtractor();
		$all       = $extractor->extract( $content );

		// Map client_id / hash for lookup while walking is complex; reuse extract order.
		// Walk flat: when we see the target heading, gather following paragraph blocks until next heading.
		$flat = $this->flattenBlocks( $blocks );
		$seen = array();

		foreach ( $flat as $block ) {
			$name = $block['blockName'] ?? '';
			if ( 'core/heading' === $name ) {
				$level               = (int) ( $block['attrs']['level'] ?? 2 );
				$innerHtml           = $block['innerHTML'] ?? '';
				$text                = wp_strip_all_tags( $innerHtml );
				$normalised          = strtolower( $text );
				$occurrence          = $seen[ $normalised ] ?? 0;
				$seen[ $normalised ] = $occurrence + 1;
				$hash                = \SmartImageMatcher\Insertion\HeadingLocator::computeHash( $level, $normalised, $occurrence );

				if ( $found ) {
					break;
				}
				if ( $hash === $heading_hash ) {
					$found = true;
				}
				continue;
			}

			if ( $found && 'core/paragraph' === $name ) {
				$para = trim( wp_strip_all_tags( $block['innerHTML'] ?? '' ) );
				if ( '' !== $para ) {
					$chunks[] = $para;
				}
				if ( count( $chunks ) >= 4 ) {
					break;
				}
			}
		}

		unset( $all );

		return wp_trim_words( implode( ' ', $chunks ), PromptBuilder::CONTEXT_WORD_LIMIT );
	}

	/**
	 * Flatten nested blocks depth-first.
	 *
	 * @since 3.1.1
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function flattenBlocks( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$out[] = $block;
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				foreach ( $this->flattenBlocks( $block['innerBlocks'] ) as $inner ) {
					$out[] = $inner;
				}
			}
		}
		return $out;
	}
}
