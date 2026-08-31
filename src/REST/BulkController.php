<?php
/**
 * REST controller: Bulk Processor job endpoints.
 *
 * Flow:
 *   POST   /jobs                        — create job, queue per-post match AS tasks
 *   GET    /jobs/<id>                   — poll status + progress
 *   POST   /jobs/<id>/cancel            — cancel all pending AS actions for this job
 *   GET    /jobs/<id>/matches           — paginated review queue
 *   POST   /matches/<match_id>          — approve / reject / swap a match
 *   POST   /jobs/<id>/insert-approved  — queue insertion for approved matches
 *
 * @package SmartImageMatcher\REST
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Settings\Settings;

/**
 * Class BulkController
 *
 * @since 3.0.0
 */
class BulkController extends Controller {

	/**
	 * Temporary posts_where filter used for bulk search.
	 *
	 * @var callable|null
	 */
	private $searchFilter = null;

	/**
	 * Register routes.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route( self::NAMESPACE, '/jobs', array(
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'createJob' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
				'args'                => array(
					'post_type'  => array( 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_key' ),
					'post_ids'   => array( 'type' => 'array',   'required' => false ),
					'post_slugs' => array( 'type' => 'array',   'required' => false ),
					'post_statuses' => array( 'type' => 'array', 'required' => false ),
					'search'     => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'taxonomy_filters' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
					'date_after' => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'date_before' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'modified_after' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'modified_before' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
					'featured_filter' => array( 'type' => 'string', 'enum' => array( 'any', 'missing', 'has' ), 'default' => 'any', 'sanitize_callback' => 'sanitize_key' ),
					'content_filter' => array( 'type' => 'string', 'enum' => array( 'any', 'has_headings', 'no_images', 'not_processed' ), 'default' => 'any', 'sanitize_callback' => 'sanitize_key' ),
					'max_posts'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 5000, 'sanitize_callback' => 'absint' ),
					'mode'       => array( 'type' => 'string',  'enum' => array( 'keyword', 'ai', 'process', 'generate-featured' ), 'default' => 'process' ),
					'min_score'  => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'default' => 70, 'sanitize_callback' => 'absint' ),
					'overwrite'  => array( 'type' => 'boolean', 'default' => false ),
					'style'      => array( 'type' => 'string',  'enum' => array( 'photo', 'illustration' ), 'default' => 'photo', 'sanitize_callback' => 'sanitize_key' ),
					'dry_run'    => array( 'type' => 'boolean', 'default' => false ),
				),
			),
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listJobs' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/jobs/(?P<job_id>[a-zA-Z0-9_-]+)', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'getJob' ),
			'permission_callback' => array( $this, 'checkAdminPermission' ),
		) );

		register_rest_route( self::NAMESPACE, '/jobs/(?P<job_id>[a-zA-Z0-9_-]+)/cancel', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'cancelJob' ),
			'permission_callback' => array( $this, 'checkAdminPermission' ),
		) );

		register_rest_route( self::NAMESPACE, '/jobs/(?P<job_id>[a-zA-Z0-9_-]+)/matches', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'getMatches' ),
			'permission_callback' => array( $this, 'checkAdminPermission' ),
			'args'                => array(
				'status' => array( 'type' => 'string', 'enum' => array( 'pending', 'approved', 'rejected', 'all' ), 'default' => 'pending', 'sanitize_callback' => 'sanitize_key' ),
				'page'   => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1, 'sanitize_callback' => 'absint' ),
				'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/matches/(?P<match_id>[\d]+)', array(
			'methods'             => \WP_REST_Server::EDITABLE,
			'callback'            => array( $this, 'updateMatch' ),
			'permission_callback' => array( $this, 'checkAdminPermission' ),
			'args'                => array(
				'status'   => array( 'type' => 'string', 'enum' => array( 'approved', 'rejected', 'pending' ), 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				'image_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/jobs/(?P<job_id>[a-zA-Z0-9_-]+)/insert-approved', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'insertApproved' ),
			'permission_callback' => array( $this, 'checkAdminPermission' ),
		) );

		register_rest_route( self::NAMESPACE, '/review', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getReview' ),
				'permission_callback' => array( $this, 'checkAdminPermission' ),
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20, 'sanitize_callback' => 'absint' ),
					'slot'     => array( 'type' => 'string', 'enum' => array( 'all', 'heading', 'featured' ), 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
					'run'      => array( 'type' => 'string', 'enum' => array( 'all', 'last' ), 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
				),
			),
		) );

		register_rest_route( self::NAMESPACE, '/review/insert-approved', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'insertApprovedPending' ),
			'permission_callback' => array( $this, 'checkAdminPermission' ),
		) );

		register_rest_route( self::NAMESPACE, '/review/approve-above', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'approveAbove' ),
			'permission_callback' => array( $this, 'checkAdminPermission' ),
			'args'                => array(
				'run' => array( 'type' => 'string', 'enum' => array( 'all', 'last' ), 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/review/approve-article', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'approveArticle' ),
			'permission_callback' => array( $this, 'checkAdminPermission' ),
			'args'                => array(
				'post_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
				'run'     => array( 'type' => 'string', 'enum' => array( 'all', 'last' ), 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/review/approve-all', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'approveAllPending' ),
			'permission_callback' => array( $this, 'checkAdminPermission' ),
			'args'                => array(
				'run' => array( 'type' => 'string', 'enum' => array( 'all', 'last' ), 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );
	}

	/**
	 * Permission callback — requires manage_options (bulk is admin-only).
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

	// -------------------------------------------------------------------------
	// Job lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Create a bulk match job.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function createJob( \WP_REST_Request $request ) {
		$postType = (string) $request->get_param( 'post_type' );
		$mode     = (string) $request->get_param( 'mode' );
		$minScore = (int)    $request->get_param( 'min_score' );
		$rawIds   = $request->get_param( 'post_ids' );
		$rawSlugs = $request->get_param( 'post_slugs' );
		$filters  = $this->getSelectionFilters( $request );

		// Validate post type exists.
		if ( ! post_type_exists( $postType ) ) {
			return new \WP_Error( 'smart_image_matcher_invalid_post_type', __( 'Invalid post type.', 'smart-image-matcher' ), array( 'status' => 400 ) );
		}

		// Resolve post IDs: either explicit IDs/slugs or a filtered post query.
		if (
			( ! empty( $rawIds ) && is_array( $rawIds ) )
			|| ( ! empty( $rawSlugs ) && is_array( $rawSlugs ) )
		) {
			$postIds = $this->resolveExplicitPostIds( $postType, $rawIds, $rawSlugs, $filters );
		} else {
			$postIds = $this->getPostIdsForJob( $postType, $filters );
		}

		if ( 'generate-featured' === $mode && ! empty( $postIds ) ) {
			$postIds = array_values(
				array_filter(
					$postIds,
					static function ( $postId ) {
						return ! FeaturedImageService::hasActionableFeaturedImage( (int) $postId );
					}
				)
			);
		}

		$postIds = array_map( 'intval', $postIds );

		if ( (bool) $request->get_param( 'dry_run' ) ) {
			return rest_ensure_response(
				array(
					'total' => count( $postIds ),
					'mode'  => $mode,
				)
			);
		}

		if ( empty( $postIds ) ) {
			$message = 'generate-featured' === $mode
				? __( 'No posts in this selection are missing a featured image.', 'smart-image-matcher' )
				: __( 'No posts found for this job.', 'smart-image-matcher' );
			return new \WP_Error( 'smart_image_matcher_no_posts', $message, array( 'status' => 400 ) );
		}

		$overwrite = (bool) $request->get_param( 'overwrite' );
		$style     = (string) $request->get_param( 'style' );
		if ( 'illustration' !== $style ) {
			$style = 'photo';
		}

		$actionConfig = array(
			'mode'      => $mode,
			'min_score' => $minScore,
			'post_type' => $postType,
			'filters'   => $filters,
			'overwrite' => $overwrite,
			'style'     => $style,
		);
		$parentConfig             = $actionConfig;
		$parentConfig['post_ids'] = $postIds;

		// Create a unique job ID.
		$jobId = 'smart_image_matcher_' . substr( md5( uniqid( '', true ) ), 0, 12 );

		// Store job metadata (includes post_ids for cancel). Per-action config stays slim.
		$this->saveJob( $jobId, 'queued', count( $postIds ), $parentConfig );

		// Enqueue one AS action per post.
		$queue  = new Queue();
		$queued = 0;

		foreach ( $postIds as $postId ) {
			$actionId = $queue->enqueueProcessArticle( (int) $postId, $jobId, $actionConfig );
			if ( $actionId ) {
				$queued++;
			}
		}

		Logger::info( 'BulkController: job created', array( 'job_id' => $jobId, 'queued' => $queued ) );

		return rest_ensure_response( array(
			'job_id'    => $jobId,
			'queued'    => $queued,
			'total'     => count( $postIds ),
			'status'    => 'queued',
			'post_type' => $postType,
			'config'    => $actionConfig,
			'mode'      => $mode,
		) );
	}

	/**
	 * List recent jobs.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function listJobs( \WP_REST_Request $request ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT * FROM {$wpdb->prefix}smart_image_matcher_queue
			 ORDER BY created_at DESC
			 LIMIT 20",
			ARRAY_A
		);

		$rows = is_array( $rows ) ? array_map( array( $this, 'hydrateJobRow' ), $rows ) : array();

		return rest_ensure_response( array( 'jobs' => $rows ?: array() ) );
	}

	/**
	 * Get a single job status and progress metrics.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function getJob( \WP_REST_Request $request ) {
		$jobId = sanitize_text_field( $request->get_param( 'job_id' ) );
		$job   = $this->fetchJob( $jobId );

		if ( ! $job ) {
			return new \WP_Error( 'smart_image_matcher_job_not_found', __( 'Job not found.', 'smart-image-matcher' ), array( 'status' => 404 ) );
		}

		// Augment with live match counts.
		global $wpdb;
		$counts = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT status, COUNT(*) as cnt
				 FROM {$wpdb->prefix}smart_image_matcher_matches
				 WHERE post_id IN (
					SELECT DISTINCT post_id FROM {$wpdb->prefix}smart_image_matcher_matches
					WHERE heading_hash IN (
						SELECT heading_hash FROM {$wpdb->prefix}smart_image_matcher_matches
						WHERE created_at >= (SELECT created_at FROM {$wpdb->prefix}smart_image_matcher_queue WHERE job_id = %s LIMIT 1)
					)
				 )
				 GROUP BY status",
				$jobId
			),
			ARRAY_A
		);

		$job['match_counts'] = $counts ?: array();

		return rest_ensure_response( $this->hydrateJobRow( $job ) );
	}

	/**
	 * Cancel a job — cancels all pending AS actions for this job.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancelJob( \WP_REST_Request $request ) {
		$jobId = sanitize_text_field( $request->get_param( 'job_id' ) );

		if ( Queue::isAvailable()
			&& class_exists( 'ActionScheduler' )
			&& \ActionScheduler::is_initialized()
		) {
			$job          = $this->fetchJob( $jobId );
			$parentConfig = array();
			if ( is_array( $job ) ) {
				$hydrated     = $this->hydrateJobRow( $job );
				$parentConfig = isset( $hydrated['config'] ) && is_array( $hydrated['config'] ) ? $hydrated['config'] : array();
			}

			$postIds      = isset( $parentConfig['post_ids'] ) && is_array( $parentConfig['post_ids'] ) ? $parentConfig['post_ids'] : array();
			$actionConfig = self::actionConfigFromParent( $parentConfig );
			( new Queue() )->unscheduleProcessArticleJob( $jobId, $postIds, $actionConfig );

			// Legacy in-flight jobs from before 3.3.0 used HOOK_BULK_MATCH.
			as_unschedule_all_actions( Queue::HOOK_BULK_MATCH, array( 'job_id' => $jobId ), Queue::GROUP );
			as_unschedule_all_actions( Queue::HOOK_BULK_INSERT, array( 'job_id' => $jobId ), Queue::GROUP );
		}

		$this->updateJobStatus( $jobId, 'cancelled' );

		return rest_ensure_response( array( 'job_id' => $jobId, 'status' => 'cancelled' ) );
	}

	/**
	 * Per-article Action Scheduler config (parent row may also store post_ids).
	 *
	 * @since 3.3.0
	 * @param array<string, mixed> $parent Parent job config.
	 * @return array<string, mixed>
	 */
	public static function actionConfigFromParent( array $parent ): array {
		unset( $parent['post_ids'] );
		return $parent;
	}

	// -------------------------------------------------------------------------
	// Review queue
	// -------------------------------------------------------------------------

	/**
	 * Get match results for a job (review queue).
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function getMatches( \WP_REST_Request $request ) {
		$jobId   = sanitize_text_field( $request->get_param( 'job_id' ) );
		$status  = sanitize_key( $request->get_param( 'status' ) );
		$page    = (int) $request->get_param( 'page' );
		$perPage = (int) $request->get_param( 'per_page' );

		$job = $this->fetchJob( $jobId );
		if ( ! $job ) {
			return new \WP_Error( 'smart_image_matcher_job_not_found', __( 'Job not found.', 'smart-image-matcher' ), array( 'status' => 404 ) );
		}

		global $wpdb;

		$offset       = ( $page - 1 ) * $perPage;
		$matchesTable = esc_sql( $wpdb->prefix . 'smart_image_matcher_matches' );
		$queueTable   = esc_sql( $wpdb->prefix . 'smart_image_matcher_queue' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 'all' === $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.*, p.post_title
					 FROM {$matchesTable} m
					 LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
					 WHERE m.created_at >= (SELECT created_at FROM {$queueTable} WHERE job_id = %s LIMIT 1)
					 ORDER BY m.confidence_score DESC
					 LIMIT %d OFFSET %d",
					$jobId,
					$perPage,
					$offset
				),
				ARRAY_A
			);

			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$matchesTable} m
					 WHERE m.created_at >= (SELECT created_at FROM {$queueTable} WHERE job_id = %s LIMIT 1)",
					$jobId
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.*, p.post_title
					 FROM {$matchesTable} m
					 LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
					 WHERE m.created_at >= (SELECT created_at FROM {$queueTable} WHERE job_id = %s LIMIT 1)
					   AND m.status = %s
					 ORDER BY m.confidence_score DESC
					 LIMIT %d OFFSET %d",
					$jobId,
					$status,
					$perPage,
					$offset
				),
				ARRAY_A
			);

			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$matchesTable} m
					 WHERE m.created_at >= (SELECT created_at FROM {$queueTable} WHERE job_id = %s LIMIT 1)
					   AND m.status = %s",
					$jobId,
					$status
				)
			);
		}
		// phpcs:enable

		$rows = self::attachImageUrls( $rows ?: array() );

		return rest_ensure_response( array(
			'articles' => self::groupMatchesByPost( $rows ),
			'matches'  => $rows,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $perPage,
		) );
	}

	/**
	 * Attach a browser-loadable preview URL to each match row.
	 *
	 * The review table must use a real media file URL, not `/wp-json/wp/v2/media/{id}`
	 * (that endpoint returns JSON, which browsers render as a broken image).
	 *
	 * @since 3.2.30
	 *
	 * @param array<int, array<string, mixed>> $rows Match rows from the database.
	 * @return array<int, array<string, mixed>>
	 */
	public static function attachImageUrls( array $rows ): array {
		$ids = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['image_id'] ) ? (int) $row['image_id'] : 0;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		if ( ! empty( $ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( array_unique( $ids ) ), true );
		}

		foreach ( $rows as $index => $row ) {
			$image_id = isset( $row['image_id'] ) ? (int) $row['image_id'] : 0;
			$url      = '';
			$full     = '';

			if ( $image_id > 0 ) {
				$thumb = wp_get_attachment_image_url( $image_id, 'medium' );
				if ( is_string( $thumb ) && '' !== $thumb ) {
					$url = $thumb;
				} else {
					$raw = wp_get_attachment_url( $image_id );
					$url = is_string( $raw ) ? $raw : '';
				}

				$large = wp_get_attachment_image_url( $image_id, 'large' );
				if ( is_string( $large ) && '' !== $large ) {
					$full = $large;
				} else {
					$raw  = wp_get_attachment_url( $image_id );
					$full = is_string( $raw ) && '' !== $raw ? $raw : $url;
				}
			}

			$rows[ $index ]['image_url']  = $url;
			$rows[ $index ]['image_full'] = $full;
		}

		return $rows;
	}

	/**
	 * Group flat match rows into one article per post_id.
	 *
	 * @since 3.3.0
	 * @param array<int, array<string, mixed>> $rows Match rows (with image_url already attached).
	 * @return array<int, array<string, mixed>>
	 */
	public static function groupMatchesByPost( array $rows ): array {
		$grouped = array();

		foreach ( $rows as $row ) {
			$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			if ( $post_id <= 0 ) {
				continue;
			}

			if ( ! isset( $grouped[ $post_id ] ) ) {
				$edit = function_exists( 'get_edit_post_link' ) ? get_edit_post_link( $post_id, 'raw' ) : '';
				if ( ! is_string( $edit ) || '' === $edit ) {
					$edit = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
				}

				$grouped[ $post_id ] = array(
					'post_id'    => $post_id,
					'post_title' => isset( $row['post_title'] ) ? (string) $row['post_title'] : '',
					'edit_url'   => $edit,
					'headings'   => array(),
				);
			}

			$grouped[ $post_id ]['headings'][] = $row;
		}

		return array_values( $grouped );
	}

	/**
	 * Paginated review queue grouped by article.
	 *
	 * @since 3.3.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function getReview( \WP_REST_Request $request ) {
		$page    = max( 1, (int) $request->get_param( 'page' ) );
		$perPage = max( 1, min( 50, (int) $request->get_param( 'per_page' ) ) );
		$slot    = sanitize_key( (string) $request->get_param( 'slot' ) );
		$run     = sanitize_key( (string) $request->get_param( 'run' ) );

		global $wpdb;

		$matchesTable = esc_sql( $wpdb->prefix . 'smart_image_matcher_matches' );
		$where        = array( "m.status = 'pending'" );
		$args         = array();

		if ( 'featured' === $slot ) {
			$where[] = "( m.heading_hash = 'featured' OR m.heading_tag = 'featured' )";
		} elseif ( 'heading' === $slot ) {
			$where[] = "m.heading_hash <> 'featured' AND ( m.heading_tag IS NULL OR m.heading_tag <> 'featured' )";
		}

		if ( 'last' === $run ) {
			$last = $this->fetchLatestRun();
			if ( ! $last ) {
				return rest_ensure_response(
					array(
						'articles'       => array(),
						'total_articles' => 0,
						'page'           => $page,
						'per_page'       => $perPage,
						'run'            => 'last',
					)
				);
			}
			$where[] = 'm.created_at >= %s';
			$args[]  = (string) $last['created_at'];
		}

		$whereSql = implode( ' AND ', $where );
		$offset   = ( $page - 1 ) * $perPage;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COUNT(DISTINCT m.post_id) FROM {$matchesTable} m WHERE {$whereSql}";
		$list_sql  = "SELECT DISTINCT m.post_id FROM {$matchesTable} m WHERE {$whereSql} ORDER BY m.post_id DESC LIMIT %d OFFSET %d";

		if ( ! empty( $args ) ) {
			$total_articles = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
			$list_args      = array_merge( $args, array( $perPage, $offset ) );
			$post_ids       = $wpdb->get_col( $wpdb->prepare( $list_sql, $list_args ) );
		} else {
			$total_articles = (int) $wpdb->get_var( $count_sql );
			$post_ids       = $wpdb->get_col( $wpdb->prepare( $list_sql, $perPage, $offset ) );
		}
		// phpcs:enable

		$post_ids = array_map( 'absint', is_array( $post_ids ) ? $post_ids : array() );
		$post_ids = array_values( array_filter( $post_ids ) );

		if ( empty( $post_ids ) ) {
			return rest_ensure_response(
				array(
					'articles'       => array(),
					'total_articles' => $total_articles,
					'page'           => $page,
					'per_page'       => $perPage,
					'run'            => $run,
				)
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$row_args     = $post_ids;

		$slot_sql = '';
		if ( 'featured' === $slot ) {
			$slot_sql = " AND ( m.heading_hash = 'featured' OR m.heading_tag = 'featured' )";
		} elseif ( 'heading' === $slot ) {
			$slot_sql = " AND m.heading_hash <> 'featured' AND ( m.heading_tag IS NULL OR m.heading_tag <> 'featured' )";
		}

		$since_sql = '';
		if ( 'last' === $run && ! empty( $args ) ) {
			$since_sql  = ' AND m.created_at >= %s';
			$row_args[] = $args[0];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.*, p.post_title
				 FROM {$matchesTable} m
				 LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.status = 'pending'
				   AND m.post_id IN ({$placeholders})
				   {$slot_sql}{$since_sql}
				 ORDER BY m.post_id DESC, m.confidence_score DESC",
				$row_args
			),
			ARRAY_A
		);
		// phpcs:enable

		$articles = self::groupMatchesByPost( self::attachImageUrls( is_array( $rows ) ? $rows : array() ) );

		return rest_ensure_response(
			array(
				'articles'       => $articles,
				'total_articles' => $total_articles,
				'page'           => $page,
				'per_page'       => $perPage,
				'run'            => $run,
			)
		);
	}

	/**
	 * Most recent article run (any mode).
	 *
	 * @since 3.3.0
	 * @return array<string, mixed>|null
	 */
	private function fetchLatestRun(): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT * FROM {$wpdb->prefix}smart_image_matcher_queue ORDER BY created_at DESC LIMIT 1",
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Approve pending slots at or above the auto-insert threshold.
	 *
	 * @since 3.3.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function approveAbove( \WP_REST_Request $request ) {
		$run       = sanitize_key( (string) $request->get_param( 'run' ) );
		$threshold = (int) Settings::get( 'auto_insert_threshold' );

		return rest_ensure_response(
			array(
				'approved'  => $this->approvePendingMatches( $run, 0, $threshold ),
				'threshold' => $threshold,
			)
		);
	}

	/**
	 * Approve every pending slot on one article.
	 *
	 * @since 3.3.1
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approveArticle( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$run     = sanitize_key( (string) $request->get_param( 'run' ) );

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Permission denied.', 'smart-image-matcher' ),
				array( 'status' => 403 )
			);
		}

		return rest_ensure_response(
			array(
				'approved' => $this->approvePendingMatches( $run, $post_id, null ),
				'post_id'  => $post_id,
			)
		);
	}

	/**
	 * Approve every pending slot in the current Review filter (All pending or Last run).
	 *
	 * @since 3.3.1
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function approveAllPending( \WP_REST_Request $request ) {
		$run = sanitize_key( (string) $request->get_param( 'run' ) );

		return rest_ensure_response(
			array(
				'approved' => $this->approvePendingMatches( $run, 0, null ),
			)
		);
	}

	/**
	 * Mark pending match rows as approved.
	 *
	 * @since 3.3.1
	 * @param string   $run       all|last.
	 * @param int      $post_id   Limit to one post, or 0 for all.
	 * @param int|null $min_score Minimum confidence, or null for any score.
	 * @return int Rows updated.
	 */
	private function approvePendingMatches( string $run, int $post_id, $min_score ): int {
		global $wpdb;

		$where = array( "status = 'pending'" );
		$args  = array();

		if ( $post_id > 0 ) {
			$where[] = 'post_id = %d';
			$args[]  = $post_id;
		}

		if ( null !== $min_score ) {
			$where[] = 'confidence_score >= %d';
			$args[]  = (int) $min_score;
		}

		if ( 'last' === $run ) {
			$last = $this->fetchLatestRun();
			if ( $last ) {
				$where[] = 'created_at >= %s';
				$args[]  = (string) $last['created_at'];
			}
		}

		$sql = 'UPDATE ' . $wpdb->prefix . 'smart_image_matcher_matches SET status = \'approved\' WHERE ' . implode( ' AND ', $where );

		if ( empty( $args ) ) {
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		} else {
			$result = $wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Queue insertion for all currently approved match rows (any run).
	 *
	 * @since 3.3.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function insertApprovedPending( \WP_REST_Request $request ) {
		unset( $request );

		global $wpdb;

		$postIds = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT DISTINCT post_id FROM {$wpdb->prefix}smart_image_matcher_matches WHERE status = 'approved'"
		);

		if ( empty( $postIds ) ) {
			return new \WP_Error( 'smart_image_matcher_no_approved', __( 'No approved matches found.', 'smart-image-matcher' ), array( 'status' => 400 ) );
		}

		$jobId = 'smart_image_matcher_' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$this->saveJob( $jobId, 'inserting', count( $postIds ), array( 'mode' => 'insert-approved' ) );

		$queue  = new Queue();
		$queued = 0;

		foreach ( $postIds as $postId ) {
			$actionId = $queue->enqueueBulkInsertPost( $jobId, (int) $postId );
			if ( $actionId ) {
				$queued++;
			}
		}

		Logger::info( 'BulkController: review insert-approved queued', array( 'posts' => $queued ) );

		return rest_ensure_response(
			array(
				'queued' => $queued,
			)
		);
	}

	/**
	 * Approve, reject, or swap a single match row.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function updateMatch( \WP_REST_Request $request ) {
		$matchId  = (int)    $request->get_param( 'match_id' );
		$status   = sanitize_key( $request->get_param( 'status' ) );
		$newImage = $request->get_param( 'image_id' );

		global $wpdb;

		$update = array( 'status' => $status );
		$format = array( '%s' );

		if ( ! empty( $newImage ) ) {
			$update['image_id'] = absint( $newImage );
			$format[]           = '%d';
		}

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_matches',
			$update,
			array( 'id' => $matchId ),
			$format,
			array( '%d' )
		);

		if ( false === $result ) {
			return new \WP_Error( 'smart_image_matcher_update_failed', __( 'Failed to update match.', 'smart-image-matcher' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'match_id' => $matchId, 'status' => $status ) );
	}

	/**
	 * Queue insertion for all approved matches in a job.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function insertApproved( \WP_REST_Request $request ) {
		$jobId = sanitize_text_field( $request->get_param( 'job_id' ) );
		$job   = $this->fetchJob( $jobId );

		if ( ! $job ) {
			return new \WP_Error( 'smart_image_matcher_job_not_found', __( 'Job not found.', 'smart-image-matcher' ), array( 'status' => 404 ) );
		}

		// Get unique post IDs with approved matches since job start.
		global $wpdb;

		$postIds = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT post_id
				 FROM {$wpdb->prefix}smart_image_matcher_matches
				 WHERE status = 'approved'
				   AND created_at >= (SELECT created_at FROM {$wpdb->prefix}smart_image_matcher_queue WHERE job_id = %s LIMIT 1)",
				$jobId
			)
		);

		if ( empty( $postIds ) ) {
			return new \WP_Error( 'smart_image_matcher_no_approved', __( 'No approved matches found for this job.', 'smart-image-matcher' ), array( 'status' => 400 ) );
		}

		$queue  = new Queue();
		$queued = 0;

		foreach ( $postIds as $postId ) {
			$actionId = $queue->enqueueBulkInsertPost( $jobId, (int) $postId );
			if ( $actionId ) {
				$queued++;
			}
		}

		$this->updateJobStatus( $jobId, 'inserting' );

		Logger::info( 'BulkController: insert-approved queued', array( 'job_id' => $jobId, 'posts' => $queued ) );

		return rest_ensure_response( array(
			'job_id' => $jobId,
			'queued' => $queued,
		) );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Persist a new job row.
	 *
	 * @since 3.0.0
	 * @param string               $jobId   Job ID.
	 * @param string               $status  Initial status.
	 * @param int                  $total   Total post count.
	 * @param array<string, mixed> $config  Job config.
	 * @return void
	 */
	private function saveJob( string $jobId, string $status, int $total, array $config ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_queue',
			array(
				'job_id'     => $jobId,
				'status'     => $status,
				'priority'   => 0,
				'attempts'   => 0,
				'totals'     => wp_json_encode(
					array(
						'total'     => $total,
						'done'      => 0,
						'inserted'  => 0,
						'review'    => 0,
						'generated' => 0,
						'skipped'   => 0,
						'config'    => $config,
					)
				),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Fetch a job row by job_id.
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

		return $row ?: null;
	}

	/**
	 * Add decoded totals/config convenience fields to a job row.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $job Job row.
	 * @return array<string, mixed>
	 */
	public function hydrateJobRow( array $job ): array {
		$totals = json_decode( (string) ( $job['totals'] ?? '' ), true );
		if ( ! is_array( $totals ) ) {
			$totals = array();
		}

		$config = isset( $totals['config'] ) && is_array( $totals['config'] ) ? $totals['config'] : array();

		$job['total']      = isset( $totals['total'] ) ? (int) $totals['total'] : 0;
		$job['done']       = isset( $totals['done'] ) ? (int) $totals['done'] : 0;
		$job['inserted']   = isset( $totals['inserted'] ) ? (int) $totals['inserted'] : 0;
		$job['review']     = isset( $totals['review'] ) ? (int) $totals['review'] : 0;
		$job['generated']  = isset( $totals['generated'] ) ? (int) $totals['generated'] : 0;
		$job['skipped']    = isset( $totals['skipped'] ) ? (int) $totals['skipped'] : 0;
		$job['post_type']  = isset( $config['post_type'] ) ? (string) $config['post_type'] : '';
		$job['config']     = $config;
		$job['label']      = self::formatRunLabel( $job );
		$job['when_label'] = self::formatRunWhen( (string) ( $job['created_at'] ?? '' ) );
		$job['what_label'] = self::formatRunWhat( $job );
		$job['outcome']    = self::formatRunOutcome( $job );

		return $job;
	}

	/**
	 * Human "Today 09:14" (or dated) label for a run. Never includes a job hash.
	 *
	 * @since 3.3.0
	 * @param string $mysql MySQL datetime.
	 * @return string
	 */
	public static function formatRunWhen( string $mysql ): string {
		if ( '' === $mysql ) {
			return '';
		}

		$ts = strtotime( $mysql );
		if ( false === $ts ) {
			return $mysql;
		}

		$today = function_exists( 'current_time' ) ? substr( (string) current_time( 'mysql' ), 0, 10 ) : gmdate( 'Y-m-d' );
		$day   = substr( $mysql, 0, 10 );
		$clock = function_exists( 'date_i18n' ) ? date_i18n( 'H:i', $ts ) : gmdate( 'H:i', $ts );

		if ( $day === $today ) {
			/* translators: %s: time, e.g. 09:14 */
			return sprintf( __( 'Today %s', 'smart-image-matcher' ), $clock );
		}

		$dated = function_exists( 'date_i18n' ) ? date_i18n( 'j M H:i', $ts ) : gmdate( 'j M H:i', $ts );
		return (string) $dated;
	}

	/**
	 * Operator-facing run label: time · article count · outcomes.
	 *
	 * @since 3.3.0
	 * @param array<string, mixed> $job Hydrated or raw job row.
	 * @return string
	 */
	public static function formatRunLabel( array $job ): string {
		$when     = isset( $job['when_label'] ) ? (string) $job['when_label'] : self::formatRunWhen( (string) ( $job['created_at'] ?? '' ) );
		$total    = isset( $job['total'] ) ? (int) $job['total'] : 0;
		$inserted = isset( $job['inserted'] ) ? (int) $job['inserted'] : 0;
		$review   = isset( $job['review'] ) ? (int) $job['review'] : 0;

		/* translators: 1: when, 2: article count, 3: inserted count, 4: review count */
		return sprintf(
			__( '%1$s · %2$d articles · Inserted %3$d · Review %4$d', 'smart-image-matcher' ),
			$when,
			$total,
			$inserted,
			$review
		);
	}

	/**
	 * What this run did (process / scheduled / generate featured).
	 *
	 * @since 3.3.0
	 * @param array<string, mixed> $job Hydrated job row.
	 * @return string
	 */
	public static function formatRunWhat( array $job ): string {
		$config = isset( $job['config'] ) && is_array( $job['config'] ) ? $job['config'] : array();
		$mode   = isset( $config['mode'] ) ? (string) $config['mode'] : '';
		$total  = isset( $job['total'] ) ? (int) $job['total'] : 0;
		$type   = '';

		if ( isset( $job['totals'] ) && is_string( $job['totals'] ) ) {
			$decoded = json_decode( $job['totals'], true );
			if ( is_array( $decoded ) && isset( $decoded['type'] ) ) {
				$type = (string) $decoded['type'];
			}
		}

		if ( 'generate-featured' === $mode ) {
			/* translators: %d: article count */
			return sprintf( __( 'Generate featured %d articles', 'smart-image-matcher' ), $total );
		}

		if ( 'fiaa_scheduled' === $type || false !== strpos( (string) ( $job['job_id'] ?? '' ), '_fiaa_scheduled_' ) ) {
			return __( 'Scheduled', 'smart-image-matcher' );
		}

		/* translators: %d: article count */
		return sprintf( __( 'Process %d articles', 'smart-image-matcher' ), $total );
	}

	/**
	 * Outcome column: Inserted · Review · Generated · Skipped.
	 *
	 * @since 3.3.0
	 * @param array<string, mixed> $job Hydrated job row.
	 * @return string
	 */
	public static function formatRunOutcome( array $job ): string {
		/* translators: 1: inserted, 2: review, 3: generated, 4: skipped */
		return sprintf(
			__( 'Inserted %1$d · Review %2$d · Generated %3$d · Skipped %4$d', 'smart-image-matcher' ),
			isset( $job['inserted'] ) ? (int) $job['inserted'] : 0,
			isset( $job['review'] ) ? (int) $job['review'] : 0,
			isset( $job['generated'] ) ? (int) $job['generated'] : 0,
			isset( $job['skipped'] ) ? (int) $job['skipped'] : 0
		);
	}

	/**
	 * Update the status column of a job.
	 *
	 * @since 3.0.0
	 * @param string $jobId  Job ID.
	 * @param string $status New status.
	 * @return void
	 */
	private function updateJobStatus( string $jobId, string $status ): void {
		global $wpdb;

		$values  = array( 'status' => $status );
		$formats = array( '%s' );

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

	/**
	 * Fetch post IDs in bounded batches for a bulk job.
	 *
	 * @since 3.0.0
	 * @param string $postType Post type.
	 * @return int[]
	 */
	private function getPostIdsForJob( string $postType, array $filters ): array {
		$postIds = array();
		$page    = 1;
		$perPage = 200;
		$max     = min(
			(int) ( $filters['max_posts'] ?? 5000 ),
			(int) apply_filters( 'smart_image_matcher_bulk_job_max_posts', 5000, $postType ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- smart_image_matcher_ is the project hook prefix.
		);
		$max     = max( 1, $max );
		$perPage = min( $perPage, $max );
		$taxQuery = $this->parseTaxonomyFilters( (string) ( $filters['taxonomy_filters'] ?? '' ) );

		do {
			$args = array(
				'post_type'              => $postType,
				'post_status'            => $filters['post_statuses'],
				'posts_per_page'         => $perPage,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			$dateQuery = $this->buildDateQuery( $filters );
			if ( ! empty( $dateQuery ) ) {
				$args['date_query'] = $dateQuery;
			}

			if ( ! empty( $taxQuery ) ) {
				$args['tax_query'] = $taxQuery; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			}

			$featuredFilter = (string) ( $filters['featured_filter'] ?? 'any' );
			if ( 'missing' === $featuredFilter ) {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => '_thumbnail_id',
						'value' => '',
					),
				);
			} elseif ( 'has' === $featuredFilter ) {
				$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'EXISTS',
					),
				);
			}

			$search = (string) ( $filters['search'] ?? '' );
			if ( '' !== $search ) {
				$this->withSearchFilter( $search );
			}

			$batch = get_posts( $args );

			if ( '' !== $search ) {
				$this->removeSearchFilter();
			}

			if ( empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $postId ) {
				if ( ! $this->postPassesContentFilter( (int) $postId, (string) ( $filters['content_filter'] ?? 'any' ) ) ) {
					continue;
				}

				$postIds[] = (int) $postId;

				if ( count( $postIds ) >= $max ) {
					break 2;
				}
			}

			$page++;
		} while ( count( $batch ) === $perPage );

		return $postIds;
	}

	/**
	 * Normalize post-selection filters from a request.
	 *
	 * @since 3.0.0
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function getSelectionFilters( \WP_REST_Request $request ): array {
		$statuses = $request->get_param( 'post_statuses' );
		$statuses = is_array( $statuses ) ? array_map( 'sanitize_key', $statuses ) : array();
		$allowed  = array( 'publish', 'draft', 'pending', 'future', 'private' );
		$statuses = array_values( array_intersect( $statuses, $allowed ) );

		if ( empty( $statuses ) ) {
			$statuses = array( 'publish', 'draft' );
		}

		return array(
			'post_statuses'   => $statuses,
			'search'          => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'taxonomy_filters' => sanitize_textarea_field( (string) $request->get_param( 'taxonomy_filters' ) ),
			'date_after'      => sanitize_text_field( (string) $request->get_param( 'date_after' ) ),
			'date_before'     => sanitize_text_field( (string) $request->get_param( 'date_before' ) ),
			'modified_after'  => sanitize_text_field( (string) $request->get_param( 'modified_after' ) ),
			'modified_before' => sanitize_text_field( (string) $request->get_param( 'modified_before' ) ),
			'featured_filter' => sanitize_key( (string) $request->get_param( 'featured_filter' ) ?: 'any' ),
			'content_filter'  => sanitize_key( (string) $request->get_param( 'content_filter' ) ?: 'any' ),
			'max_posts'       => max( 1, min( 5000, (int) $request->get_param( 'max_posts' ) ) ),
		);
	}

	/**
	 * Resolve explicit post IDs and slugs.
	 *
	 * @since 3.0.0
	 * @param string       $postType Post type.
	 * @param mixed        $rawIds   Raw post IDs.
	 * @param mixed        $rawSlugs Raw post slugs.
	 * @param array<string, mixed> $filters Selection filters.
	 * @return int[]
	 */
	private function resolveExplicitPostIds( string $postType, $rawIds, $rawSlugs, array $filters ): array {
		$ids   = is_array( $rawIds ) ? array_values( array_unique( array_filter( array_map( 'absint', $rawIds ) ) ) ) : array();
		$slugs = is_array( $rawSlugs )
			? array_values( array_unique( array_filter( array_map( 'sanitize_title', $rawSlugs ) ) ) )
			: array();

		$postIds = array();
		if ( ! empty( $ids ) ) {
			$args = array(
				'post_type'              => $postType,
				'post_status'            => $filters['post_statuses'],
				'posts_per_page'         => min( 5000, max( count( $ids ), 1 ) ),
				'post__in'               => $ids,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);
			$postIds = array_merge( $postIds, array_map( 'intval', get_posts( $args ) ) );
		}

		if ( ! empty( $slugs ) ) {
			$args = array(
				'post_type'              => $postType,
				'post_status'            => $filters['post_statuses'],
				'posts_per_page'         => min( 5000, max( count( $slugs ), 1 ) ),
				'post_name__in'          => $slugs,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);
			$postIds = array_merge( $postIds, array_map( 'intval', get_posts( $args ) ) );
		}

		$postIds = array_values( array_unique( $postIds ) );

		/*
		 * If WordPress cannot express one side of this explicit request, avoid
		 * falling through to "all posts". Explicit means explicit.
		 */
		if ( empty( $postIds ) ) {
			return array();
		}

		$max = max( 1, min( 5000, (int) ( $filters['max_posts'] ?? 5000 ) ) );

		return array_slice(
			array_values(
				array_filter(
					$postIds,
					fn( $postId ) => $this->postPassesContentFilter( (int) $postId, (string) ( $filters['content_filter'] ?? 'any' ) )
				)
			),
			0,
			$max
		);
	}

	/**
	 * Build date filters for published and modified dates.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, array<string, mixed>>
	 */
	private function buildDateQuery( array $filters ): array {
		$dateQuery = array();

		if ( ! empty( $filters['date_after'] ) || ! empty( $filters['date_before'] ) ) {
			$dateQuery[] = array_filter(
				array(
					'column'    => 'post_date',
					'after'     => $filters['date_after'] ?: null,
					'before'    => $filters['date_before'] ?: null,
					'inclusive' => true,
				)
			);
		}

		if ( ! empty( $filters['modified_after'] ) || ! empty( $filters['modified_before'] ) ) {
			$dateQuery[] = array_filter(
				array(
					'column'    => 'post_modified',
					'after'     => $filters['modified_after'] ?: null,
					'before'    => $filters['modified_before'] ?: null,
					'inclusive' => true,
				)
			);
		}

		return $dateQuery;
	}

	/**
	 * Parse taxonomy filters from "taxonomy:term,term; taxonomy2:term" syntax.
	 *
	 * @since 3.0.0
	 * @param string $raw Raw filter string.
	 * @return array<int|string, mixed>
	 */
	private function parseTaxonomyFilters( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return array();
		}

		$taxQuery = array( 'relation' => 'AND' );
		$groups   = preg_split( '/[;\n]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		foreach ( $groups as $group ) {
			$parts = array_map( 'trim', explode( ':', $group, 2 ) );
			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$taxonomy = sanitize_key( $parts[0] );
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = array_values(
				array_filter(
					array_map( 'sanitize_title', array_map( 'trim', explode( ',', $parts[1] ) ) )
				)
			);

			if ( empty( $terms ) ) {
				continue;
			}

			$taxQuery[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'slug',
				'terms'    => $terms,
			);
		}

		return count( $taxQuery ) > 1 ? $taxQuery : array();
	}

	/**
	 * Add a temporary title/content/slug search filter.
	 *
	 * @since 3.0.0
	 * @param string $search Search text.
	 * @return void
	 */
	private function withSearchFilter( string $search ): void {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $search ) . '%';

		$this->searchFilter = static function ( string $where ) use ( $wpdb, $like ): string {
			return $where . $wpdb->prepare(
				" AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_name LIKE %s)",
				$like,
				$like,
				$like,
				$like
			);
		};

		add_filter( 'posts_where', $this->searchFilter );
	}

	/**
	 * Remove the temporary search filter.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	private function removeSearchFilter(): void {
		if ( null !== $this->searchFilter ) {
			remove_filter( 'posts_where', $this->searchFilter );
			$this->searchFilter = null;
		}
	}

	/**
	 * Apply content-oriented filters that cannot be expressed cleanly in WP_Query.
	 *
	 * @since 3.0.0
	 * @param int    $postId Post ID.
	 * @param string $filter Filter slug.
	 * @return bool
	 */
	private function postPassesContentFilter( int $postId, string $filter ): bool {
		if ( 'any' === $filter ) {
			return true;
		}

		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( 'has_headings' === $filter ) {
			return (bool) preg_match( '/<!--\s+wp:heading\b|<h[2-6][\s>]/i', $post->post_content );
		}

		if ( 'no_images' === $filter ) {
			return ! preg_match( '/<!--\s+wp:image\b|<img\b/i', $post->post_content );
		}

		if ( 'not_processed' === $filter ) {
			global $wpdb;
			$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}smart_image_matcher_matches WHERE post_id = %d LIMIT 1",
					$postId
				)
			);

			return 0 === $count;
		}

		return true;
	}
}
