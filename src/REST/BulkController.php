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

use SmartImageMatcher\Domain\MatchRepository;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;

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
					'mode'       => array( 'type' => 'string',  'enum' => array( 'keyword', 'ai' ), 'default' => 'keyword' ),
					'min_score'  => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'default' => 70, 'sanitize_callback' => 'absint' ),
					'overwrite'  => array( 'type' => 'boolean', 'default' => false ),
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
				'status'   => array( 'type' => 'string', 'enum' => array( 'approved', 'rejected' ), 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
				'image_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/jobs/(?P<job_id>[a-zA-Z0-9_-]+)/insert-approved', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'insertApproved' ),
			'permission_callback' => array( $this, 'checkAdminPermission' ),
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

		if ( empty( $postIds ) ) {
			return new \WP_Error( 'smart_image_matcher_no_posts', __( 'No posts found for this job.', 'smart-image-matcher' ), array( 'status' => 400 ) );
		}

		// Create a unique job ID.
		$jobId  = 'smart_image_matcher_' . substr( md5( uniqid( '', true ) ), 0, 12 );
		$config = array(
			'mode'      => $mode,
			'min_score' => $minScore,
			'post_type' => $postType,
			'filters'   => $filters,
		);

		// Store job metadata.
		$this->saveJob( $jobId, 'queued', count( $postIds ), $config );

		// Enqueue one AS action per post.
		$queue  = new Queue();
		$queued = 0;

		foreach ( $postIds as $postId ) {
			$actionId = $queue->enqueueBulkMatchPost( $jobId, (int) $postId, $config );
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
			as_unschedule_all_actions( Queue::HOOK_BULK_MATCH,  array( 'job_id' => $jobId ), Queue::GROUP );
			as_unschedule_all_actions( Queue::HOOK_BULK_INSERT, array( 'job_id' => $jobId ), Queue::GROUP );
		}

		$this->updateJobStatus( $jobId, 'cancelled' );

		return rest_ensure_response( array( 'job_id' => $jobId, 'status' => 'cancelled' ) );
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

		return rest_ensure_response( array(
			'matches'  => $rows ?: array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $perPage,
		) );
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
				'totals'     => wp_json_encode( array( 'total' => $total, 'done' => 0, 'config' => $config ) ),
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
	private function hydrateJobRow( array $job ): array {
		$totals = json_decode( (string) ( $job['totals'] ?? '' ), true );
		if ( ! is_array( $totals ) ) {
			$totals = array();
		}

		$config = isset( $totals['config'] ) && is_array( $totals['config'] ) ? $totals['config'] : array();

		$job['total']     = isset( $totals['total'] ) ? (int) $totals['total'] : 0;
		$job['done']      = isset( $totals['done'] ) ? (int) $totals['done'] : 0;
		$job['post_type'] = isset( $config['post_type'] ) ? (string) $config['post_type'] : '';
		$job['config']    = $config;

		return $job;
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
