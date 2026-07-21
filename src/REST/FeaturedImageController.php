<?php
/**
 * REST controller: featured image assignment endpoints.
 *
 * @package SmartImageMatcher\REST
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\PostStatuses;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Settings\Sanitizer;
use SmartImageMatcher\Settings\Settings;

/**
 * Class FeaturedImageController
 *
 * @since 3.0.0
 */
class FeaturedImageController extends Controller {

	/**
	 * Supported featured-image queue job types.
	 */
	private const JOB_TYPES = array( 'fiaa_manual', 'fiaa_audit_clear' );

	/**
	 * Register routes.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>\d+)/featured-image',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'assign' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'args'                => array(
					'post_id'   => array( 'type' => 'integer', 'required' => true ),
					'overwrite' => array( 'type' => 'boolean', 'default'  => false ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/featured-image-jobs',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createJob' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => array(
						'post_type' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'overwrite' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'post_statuses' => array(
							'type'    => 'array',
							'default' => array( 'publish' ),
							'items'   => array(
								'type' => 'string',
							),
						),
						'featured_filter' => array(
							'type'    => 'string',
							'default' => 'missing',
							'enum'    => array( 'any', 'missing', 'has' ),
						),
						'max_posts' => array(
							'type'    => 'integer',
							'default' => 5000,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/featured-image-jobs/(?P<job_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getJob' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/featured-image-jobs/(?P<job_id>[a-zA-Z0-9_-]+)/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancelJob' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/featured-image-manual-settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getManualSettings' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'saveManualSettings' ),
					'permission_callback' => array( $this, 'checkAdminPermission' ),
					'args'                => array(
						'post_type' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'post_statuses' => array(
							'type'    => 'array',
							'default' => array(),
							'items'   => array(
								'type' => 'string',
							),
						),
						'featured_filter' => array(
							'type'    => 'string',
							'default' => 'missing',
							'enum'    => array( 'any', 'missing', 'has' ),
						),
						'max_posts' => array(
							'type'    => 'integer',
							'default' => 5000,
						),
						'overwrite' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/featured-image-audit',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'scanAudit' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
				'args'                => array(
					'post_type' => array(
						'type'              => 'string',
						'default'           => 'post',
						'sanitize_callback' => 'sanitize_key',
					),
					'post_statuses' => array(
						'type'    => 'array',
						'default' => array( 'publish', 'draft' ),
						'items'   => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/featured-image-audit/clear',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'createAuditClearJob' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
				'args'                => array(
					'post_type' => array(
						'type'              => 'string',
						'default'           => 'post',
						'sanitize_callback' => 'sanitize_key',
					),
					'post_statuses' => array(
						'type'    => 'array',
						'default' => array( 'publish', 'draft' ),
						'items'   => array(
							'type' => 'string',
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
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Permission denied.', 'smart-image-matcher' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Permission callback for admin-level featured image jobs.
	 *
	 * @since 3.0.0
	 * @return bool|\WP_Error
	 */
	public function checkAdminPermission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'Permission denied.', 'smart-image-matcher' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Get saved manual Run Matcher settings.
	 *
	 * @since 3.0.3
	 * @return \WP_REST_Response
	 */
	public function getManualSettings(): \WP_REST_Response {
		return rest_ensure_response( $this->buildManualSettingsPayload() );
	}

	/**
	 * Persist manual Run Matcher settings.
	 *
	 * @since 3.0.3
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function saveManualSettings( \WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$payload = is_array( $body ) ? $body : array();

		$postType = sanitize_key( (string) ( $payload['post_type'] ?? Settings::get( 'fiaa_manual_post_type' ) ) );
		if ( ! post_type_exists( $postType ) || 'attachment' === $postType ) {
			return new \WP_Error( 'smart_image_matcher_invalid_post_type', __( 'Invalid post type.', 'smart-image-matcher' ), array( 'status' => 400 ) );
		}

		$sanitizer = new Sanitizer();
		$manual    = $sanitizer->sanitizeManualRunSettings( $payload );
		$all       = Settings::all();
		$all       = array_merge( $all, $manual );
		Settings::save( $all );

		return rest_ensure_response( $this->buildManualSettingsPayload() );
	}

	/**
	 * Build the manual settings payload for REST responses.
	 *
	 * @since 3.0.3
	 * @return array<string, mixed>
	 */
	private function buildManualSettingsPayload(): array {
		$postType = sanitize_key( (string) Settings::get( 'fiaa_manual_post_type' ) );
		if ( ! post_type_exists( $postType ) || 'attachment' === $postType ) {
			$postType = 'post';
		}

		$statuses = PostStatuses::sanitizeList( (string) Settings::get( 'fiaa_manual_post_statuses' ) );
		$filter   = (string) Settings::get( 'fiaa_manual_featured_filter' );
		if ( ! in_array( $filter, array( 'any', 'missing', 'has' ), true ) ) {
			$filter = 'missing';
		}

		return array(
			'post_type'       => $postType,
			'post_statuses'   => $statuses,
			'featured_filter' => $filter,
			'max_posts'       => max( 1, min( 50000, (int) Settings::get( 'fiaa_manual_max_posts' ) ) ),
			'overwrite'       => (bool) Settings::get( 'fiaa_manual_overwrite' ),
		);
	}

	/**
	 * Scan posts for featured images that fail current auto-assign safety rules.
	 *
	 * @since 3.0.5
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function scanAudit( \WP_REST_Request $request ) {
		$postType     = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$postStatuses = $this->sanitizePostStatuses( $request->get_param( 'post_statuses' ) );

		if ( ! post_type_exists( $postType ) || 'attachment' === $postType ) {
			return new \WP_Error( 'smart_image_matcher_invalid_post_type', __( 'Invalid post type.', 'smart-image-matcher' ), array( 'status' => 400 ) );
		}

		$audit  = new \SmartImageMatcher\FeaturedImages\FeaturedImageAudit(
			new \SmartImageMatcher\FeaturedImages\FeaturedImageService(
				new \SmartImageMatcher\FeaturedImages\SlugMapBuilder()
			)
		);
		$result = $audit->scanUnsafeAssignments( $postType, $postStatuses );

		return rest_ensure_response( $result );
	}

	/**
	 * Queue a background job to clear unsafe featured image assignments.
	 *
	 * @since 3.0.5
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function createAuditClearJob( \WP_REST_Request $request ) {
		$postType     = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$postStatuses = $this->sanitizePostStatuses( $request->get_param( 'post_statuses' ) );

		if ( ! post_type_exists( $postType ) || 'attachment' === $postType ) {
			return new \WP_Error( 'smart_image_matcher_invalid_post_type', __( 'Invalid post type.', 'smart-image-matcher' ), array( 'status' => 400 ) );
		}

		if ( ! Queue::isAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_queue_unavailable',
				__( 'Background processing is not available on this site.', 'smart-image-matcher' ),
				array( 'status' => 500 )
			);
		}

		$audit   = new \SmartImageMatcher\FeaturedImages\FeaturedImageAudit(
			new \SmartImageMatcher\FeaturedImages\FeaturedImageService(
				new \SmartImageMatcher\FeaturedImages\SlugMapBuilder()
			)
		);
		$scan    = $audit->scanUnsafeAssignments( $postType, $postStatuses );
		$postIds = isset( $scan['post_ids'] ) && is_array( $scan['post_ids'] ) ? array_map( 'absint', $scan['post_ids'] ) : array();

		if ( empty( $postIds ) ) {
			return rest_ensure_response(
				array(
					'job_id'    => '',
					'status'    => 'completed',
					'total'     => 0,
					'done'      => 0,
					'matched'   => 0,
					'skipped'   => 0,
					'unmatched' => 0,
					'job_type'  => 'fiaa_audit_clear',
					'message'   => __( 'No unsafe featured images were found.', 'smart-image-matcher' ),
				)
			);
		}

		$jobId  = 'smart_image_matcher_fiaa_audit_' . substr( md5( wp_generate_uuid4() ), 0, 12 );
		$config = array(
			'post_type'     => $postType,
			'post_statuses' => $postStatuses,
			'post_ids'      => $postIds,
			'batch_size'    => (int) apply_filters( 'smart_image_matcher_fiaa_audit_batch_size', 20, $postType ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- smart_image_matcher_ is the project hook prefix.
		);

		$this->saveAuditJob( $jobId, count( $postIds ), $config );

		$queued = ( new Queue() )->enqueueFiaaAuditClear( $jobId );
		if ( null === $queued ) {
			$this->updateJobStatus( $jobId, 'failed', __( 'Could not queue the audit cleanup job.', 'smart-image-matcher' ) );
			return new \WP_Error(
				'smart_image_matcher_queue_failed',
				__( 'Could not queue the audit cleanup job.', 'smart-image-matcher' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $this->hydrateJobRow( (array) $this->fetchJob( $jobId ) ) );
	}

	/**
	 * Assign featured image by slug match.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function assign( \WP_REST_Request $request ) {
		$postId    = (int) $request->get_param( 'post_id' );
		$overwrite = (bool) $request->get_param( 'overwrite' );

		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'smart_image_matcher_post_not_found', __( 'Post not found.', 'smart-image-matcher' ), array( 'status' => 404 ) );
		}

		$service = new \SmartImageMatcher\FeaturedImages\FeaturedImageService(
			new \SmartImageMatcher\FeaturedImages\SlugMapBuilder()
		);
		$result  = $service->assignBestForPost( $postId, $overwrite );

		return rest_ensure_response( $result );
	}

	/**
	 * Create a queued featured image matcher job.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function createJob( \WP_REST_Request $request ) {
		$postType       = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$overwrite      = (bool) $request->get_param( 'overwrite' );
		$postStatuses   = $this->sanitizePostStatuses( $request->get_param( 'post_statuses' ) );
		$featuredFilter = $this->sanitizeFeaturedFilter( (string) $request->get_param( 'featured_filter' ) );
		$maxPosts       = $this->sanitizeMaxPosts( $request->get_param( 'max_posts' ), $postType );

		if ( $overwrite ) {
			$featuredFilter = 'any';
		}

		if ( ! post_type_exists( $postType ) || 'attachment' === $postType ) {
			return new \WP_Error( 'smart_image_matcher_invalid_post_type', __( 'Invalid post type.', 'smart-image-matcher' ), array( 'status' => 400 ) );
		}

		if ( ! Queue::isAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_queue_unavailable',
				__( 'Background processing is not available on this site.', 'smart-image-matcher' ),
				array( 'status' => 500 )
			);
		}

		$postIds = $this->getPostIdsForJob( $postType, $postStatuses, $featuredFilter, $maxPosts );
		$jobId   = 'smart_image_matcher_fiaa_' . substr( md5( wp_generate_uuid4() ), 0, 12 );
		$config  = array(
			'post_type'       => $postType,
			'post_statuses'   => $postStatuses,
			'featured_filter' => $featuredFilter,
			'overwrite'       => $overwrite,
			'max_posts'       => $maxPosts,
			'post_ids'        => $postIds,
			'batch_size'      => (int) apply_filters( 'smart_image_matcher_fiaa_queue_batch_size', 20, $postType ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- smart_image_matcher_ is the project hook prefix.
		);

		$this->saveJob( $jobId, count( $postIds ), $config );

		$queued = ( new Queue() )->enqueueFiaaRun( $jobId );
		if ( null === $queued ) {
			$this->updateJobStatus( $jobId, 'failed', __( 'Could not queue the featured image matcher job.', 'smart-image-matcher' ) );
			return new \WP_Error(
				'smart_image_matcher_queue_failed',
				__( 'Could not queue the featured image matcher job.', 'smart-image-matcher' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( $this->hydrateJobRow( (array) $this->fetchJob( $jobId ) ) );
	}

	/**
	 * Fetch a queued featured image matcher job.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function getJob( \WP_REST_Request $request ) {
		$jobId = sanitize_key( (string) $request->get_param( 'job_id' ) );
		$job   = $this->fetchJob( $jobId );

		if ( null === $job ) {
			return new \WP_Error( 'smart_image_matcher_job_not_found', __( 'Job not found.', 'smart-image-matcher' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $this->hydrateJobRow( $job ) );
	}

	/**
	 * Cancel a queued featured image matcher job.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancelJob( \WP_REST_Request $request ) {
		$jobId = sanitize_key( (string) $request->get_param( 'job_id' ) );
		$job   = $this->fetchJob( $jobId );

		if ( null === $job ) {
			return new \WP_Error( 'smart_image_matcher_job_not_found', __( 'Job not found.', 'smart-image-matcher' ), array( 'status' => 404 ) );
		}

		$this->updateJobStatus( $jobId, 'cancelled' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			$totals = json_decode( (string) ( $job['totals'] ?? '' ), true );
			$type   = is_array( $totals ) ? (string) ( $totals['type'] ?? 'fiaa_manual' ) : 'fiaa_manual';
			$hook   = $this->getQueueHookForJobType( $type );
			as_unschedule_all_actions( $hook, array( 'job_id' => $jobId ), Queue::GROUP );
		}

		$job = $this->fetchJob( $jobId );
		return rest_ensure_response( $this->hydrateJobRow( is_array( $job ) ? $job : array() ) );
	}

	/**
	 * Fetch post IDs for a queued featured image run.
	 *
	 * @since 3.0.0
	 * @param string $postType Post type slug.
	 * @return int[]
	 */
	private function getPostIdsForJob( string $postType, array $postStatuses, string $featuredFilter, int $max ): array {
		$postIds = array();
		$page    = 1;
		$perPage = 200;

		do {
			$args = array(
				'post_type'              => $postType,
				'post_status'            => $postStatuses,
				'posts_per_page'         => min( $perPage, $max - count( $postIds ) ),
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			$metaQuery = $this->getFeaturedImageMetaQuery( $featuredFilter );
			if ( ! empty( $metaQuery ) ) {
				$args['meta_query'] = $metaQuery; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}

			$batch = get_posts( $args );

			foreach ( $batch as $postId ) {
				$postIds[] = (int) $postId;
			}

			$page++;
		} while ( count( $batch ) === $perPage && count( $postIds ) < $max );

		return $postIds;
	}

	/**
	 * Sanitize requested post statuses.
	 *
	 * @since 3.0.0
	 * @param mixed $statuses Raw request value.
	 * @return string[]
	 */
	private function sanitizePostStatuses( $statuses ): array {
		if ( is_string( $statuses ) ) {
			$statuses = explode( ',', $statuses );
		}

		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}

		return PostStatuses::sanitizeList( $statuses );
	}

	/**
	 * Sanitize requested featured-image filter.
	 *
	 * @since 3.0.0
	 * @param string $filter Raw request value.
	 * @return string
	 */
	private function sanitizeFeaturedFilter( string $filter ): string {
		return in_array( $filter, array( 'any', 'missing', 'has' ), true ) ? $filter : 'missing';
	}

	/**
	 * Sanitize manual job max post count.
	 *
	 * @since 3.0.0
	 * @param mixed  $value    Raw request value.
	 * @param string $postType Post type slug.
	 * @return int
	 */
	private function sanitizeMaxPosts( $value, string $postType ): int {
		$default = (int) apply_filters( 'smart_image_matcher_fiaa_manual_job_max_posts', 5000, $postType ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- smart_image_matcher_ is the project hook prefix.
		$max     = is_numeric( $value ) ? (int) $value : $default;

		return max( 1, min( 50000, $max ) );
	}

	/**
	 * Build meta query for featured image state filters.
	 *
	 * @since 3.0.0
	 * @param string $filter Featured filter.
	 * @return array<int|string, mixed>
	 */
	private function getFeaturedImageMetaQuery( string $filter ): array {
		if ( 'missing' === $filter ) {
			return array(
				'relation' => 'OR',
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_thumbnail_id',
					'value'   => 0,
					'compare' => '<=',
					'type'    => 'NUMERIC',
				),
			);
		}

		if ( 'has' === $filter ) {
			return array(
				array(
					'key'     => '_thumbnail_id',
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			);
		}

		return array();
	}

	/**
	 * Save a new featured image matcher job row.
	 *
	 * @since 3.0.0
	 * @param string               $jobId  Job ID.
	 * @param int                  $total  Total post count.
	 * @param array<string, mixed> $config Job config.
	 * @return void
	 */
	private function saveJob( string $jobId, int $total, array $config ): void {
		$this->insertQueueJob(
			$jobId,
			array(
				'type'      => 'fiaa_manual',
				'total'     => $total,
				'done'      => 0,
				'offset'    => 0,
				'matched'   => 0,
				'skipped'   => 0,
				'unmatched' => 0,
				'recent'    => array(),
				'config'    => $config,
			)
		);
	}

	/**
	 * Save a new featured image audit cleanup job row.
	 *
	 * @since 3.0.5
	 * @param string               $jobId  Job ID.
	 * @param int                  $total  Total post count.
	 * @param array<string, mixed> $config Job config.
	 * @return void
	 */
	private function saveAuditJob( string $jobId, int $total, array $config ): void {
		$this->insertQueueJob(
			$jobId,
			array(
				'type'      => 'fiaa_audit_clear',
				'total'     => $total,
				'done'      => 0,
				'offset'    => 0,
				'matched'   => 0,
				'skipped'   => 0,
				'unmatched' => 0,
				'recent'    => array(),
				'config'    => $config,
			)
		);
	}

	/**
	 * Insert a featured-image queue job row.
	 *
	 * @since 3.0.5
	 * @param string               $jobId  Job ID.
	 * @param array<string, mixed> $totals Totals payload.
	 * @return void
	 */
	private function insertQueueJob( string $jobId, array $totals ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_queue',
			array(
				'job_id'     => $jobId,
				'status'     => 'queued',
				'priority'   => 0,
				'attempts'   => 0,
				'totals'     => wp_json_encode( $totals ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Fetch a featured image matcher job row.
	 *
	 * @since 3.0.0
	 * @param string $jobId Job ID.
	 * @return array<string, mixed>|null
	 */
	private function fetchJob( string $jobId ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}smart_image_matcher_queue WHERE job_id = %s LIMIT 1",
				$jobId
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$totals = json_decode( (string) ( $row['totals'] ?? '' ), true );
		if ( ! is_array( $totals ) || ! in_array( (string) ( $totals['type'] ?? '' ), self::JOB_TYPES, true ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Add decoded featured-image job fields to a job row.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $job Job row.
	 * @return array<string, mixed>
	 */
	private function hydrateJobRow( array $job ): array {
		$totals = json_decode( (string) ( $job['totals'] ?? '' ), true );
		if ( ! is_array( $totals ) ) {
			$totals = array();
		}

		$config = isset( $totals['config'] ) && is_array( $totals['config'] ) ? $totals['config'] : array();

		unset( $config['post_ids'] );
		unset( $job['totals'] );

		$job['total']     = isset( $totals['total'] ) ? (int) $totals['total'] : 0;
		$job['done']      = isset( $totals['done'] ) ? (int) $totals['done'] : 0;
		$job['matched']   = isset( $totals['matched'] ) ? (int) $totals['matched'] : 0;
		$job['skipped']   = isset( $totals['skipped'] ) ? (int) $totals['skipped'] : 0;
		$job['unmatched'] = isset( $totals['unmatched'] ) ? (int) $totals['unmatched'] : 0;
		$job['recent']    = isset( $totals['recent'] ) && is_array( $totals['recent'] ) ? $totals['recent'] : array();
		$job['post_type'] = isset( $config['post_type'] ) ? (string) $config['post_type'] : '';
		$job['config']    = $config;
		$job['job_type']  = isset( $totals['type'] ) ? (string) $totals['type'] : 'fiaa_manual';

		return $job;
	}

	/**
	 * Resolve the Action Scheduler hook for a featured-image job type.
	 *
	 * @since 3.0.5
	 * @param string $type Job type slug.
	 * @return string
	 */
	private function getQueueHookForJobType( string $type ): string {
		return 'fiaa_audit_clear' === $type ? Queue::HOOK_FIAA_AUDIT_CLEAR : Queue::HOOK_FIAA_RUN;
	}

	/**
	 * Update featured image job status.
	 *
	 * @since 3.0.0
	 * @param string $jobId   Job ID.
	 * @param string $status  New status.
	 * @param string $message Optional error message.
	 * @return void
	 */
	private function updateJobStatus( string $jobId, string $status, string $message = '' ): void {
		global $wpdb;

		$values  = array( 'status' => $status );
		$formats = array( '%s' );

		if ( '' !== $message ) {
			$values['error_message'] = $message;
			$formats[]               = '%s';
		}

		if ( in_array( $status, array( 'cancelled', 'failed', 'completed' ), true ) ) {
			$values['finished_at'] = current_time( 'mysql' );
			$formats[]            = '%s';
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_queue',
			$values,
			array( 'job_id' => $jobId ),
			$formats,
			array( '%s' )
		);
	}
}
