<?php
/**
 * Review, Schedule and Tools actions for the Bulk Processor.
 *
 *   POST /review/decide               approve / reject / reset a list of suggestions
 *   POST /review/reject-article       reject every pending suggestion of one article
 *   POST /review/ignore               ignore / un-ignore an article
 *   GET  /review/ignored              ignored articles
 *   GET  /review/blocked              filenames blocked as featured images site-wide
 *   POST /review/unblock              remove one filename from that list
 *   GET  /review/block-impact         what blocking a suggestion's image would affect
 *   GET  /schedule                    automatic-run settings, open runs, recent ticks
 *   POST /jobs/{id}/close             close a run that will never finish
 *   POST /jobs/{id}/requeue-lost      queue again the articles a run lost
 *
 * @package SmartImageMatcher\REST
 * @since   3.7.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\ArticleProcessor;
use SmartImageMatcher\Domain\IgnoredArticles;
use SmartImageMatcher\Domain\MatchRepository;
use SmartImageMatcher\Domain\PostStatuses;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Queue\QueueWatchdog;
use SmartImageMatcher\Settings\Sanitizer;
use SmartImageMatcher\Settings\Settings;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Class ReviewToolsController
 *
 * @since 3.7.0
 */
class ReviewToolsController extends Controller {

	/** Minutes without progress before an open run is shown as stuck. */
	const STUCK_MINUTES = 45;

	/**
	 * Register routes.
	 *
	 * @since 3.7.0
	 * @return void
	 */
	public function registerRoutes(): void {
		$post_arg = array(
			'post_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
		);

		register_rest_route( self::NAMESPACE, '/review/decide', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'decide' ),
			'permission_callback' => array( $this, 'canManage' ),
			'args'                => array(
				'ids'    => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer' ) ),
				'status' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'approved', 'rejected', 'pending' ) ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/review/reject-article', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rejectArticle' ),
			'permission_callback' => array( $this, 'canEditPost' ),
			'args'                => $post_arg,
		) );

		register_rest_route( self::NAMESPACE, '/review/ignore', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'ignore' ),
			'permission_callback' => array( $this, 'canEditPost' ),
			'args'                => array_merge( $post_arg, array( 'ignored' => array( 'type' => 'boolean', 'default' => true ) ) ),
		) );

		register_rest_route( self::NAMESPACE, '/review/ignored', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'ignoredList' ),
			'permission_callback' => array( $this, 'canManage' ),
		) );

		register_rest_route( self::NAMESPACE, '/review/blocked', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'blockedList' ),
			'permission_callback' => array( $this, 'canManage' ),
		) );

		register_rest_route( self::NAMESPACE, '/review/unblock', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'unblock' ),
			'permission_callback' => array( $this, 'canManage' ),
			'args'                => array( 'file' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );

		register_rest_route( self::NAMESPACE, '/review/block-impact', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'blockImpact' ),
			'permission_callback' => array( $this, 'canManage' ),
			'args'                => array( 'match_id' => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ) ),
		) );

		register_rest_route( self::NAMESPACE, '/featured-misplaced', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'misplacedScan' ),
			'permission_callback' => array( $this, 'canManage' ),
			'args'                => array(
				'post_type'     => array( 'type' => 'string', 'default' => 'post', 'sanitize_callback' => 'sanitize_key' ),
				'post_statuses' => array( 'type' => 'string', 'default' => 'draft', 'sanitize_callback' => 'sanitize_text_field' ),
				'after_id'      => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/featured-misplaced/move', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'misplacedMove' ),
			'permission_callback' => array( $this, 'canManage' ),
			'args'                => array(
				'post_ids' => array( 'type' => 'array', 'required' => true, 'items' => array( 'type' => 'integer' ) ),
			),
		) );

		register_rest_route( self::NAMESPACE, '/schedule', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'schedule' ),
			'permission_callback' => array( $this, 'canManage' ),
		) );

		register_rest_route( self::NAMESPACE, '/jobs/(?P<job_id>[a-zA-Z0-9_-]+)/close', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'closeJob' ),
			'permission_callback' => array( $this, 'canManage' ),
		) );

		register_rest_route( self::NAMESPACE, '/jobs/(?P<job_id>[a-zA-Z0-9_-]+)/requeue-lost', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'requeueLost' ),
			'permission_callback' => array( $this, 'canManage' ),
		) );
	}

	/**
	 * Admins only.
	 *
	 * @since 3.7.0
	 * @return bool
	 */
	public function canManage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Admin who can edit the post.
	 *
	 * @since 3.7.0
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function canEditPost( \WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return current_user_can( 'manage_options' ) && $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	// -------------------------------------------------------------------------
	// Review
	// -------------------------------------------------------------------------

	/**
	 * Approve, reject or reset (pending) a list of suggestions in one request.
	 *
	 * Approve and reject only touch pending rows; reset only touches decided rows,
	 * so an Undo never resurrects something that was already inserted.
	 *
	 * @since 3.7.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function decide( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$ids    = array_slice( array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) ), 0, 500 );
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( empty( $ids ) || ! in_array( $status, array( 'approved', 'rejected', 'pending' ), true ) ) {
			return rest_ensure_response( array( 'updated' => 0 ) );
		}

		$from  = 'pending' === $status ? "'approved','rejected'" : "'pending'";
		$table = $wpdb->prefix . 'smart_image_matcher_matches';
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id IN ({$in}) AND status IN ({$from})", array_merge( array( $status ), $ids ) ) );

		return rest_ensure_response( array( 'updated' => is_int( $updated ) ? $updated : 0, 'status' => $status ) );
	}

	/**
	 * Reject every pending suggestion of one article (each is remembered).
	 *
	 * @since 3.7.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rejectArticle( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		return rest_ensure_response( array( 'rejected' => ( new MatchRepository() )->rejectPendingForPost( $post_id ) ) );
	}

	/**
	 * Ignore or un-ignore an article.
	 *
	 * @since 3.7.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ignore( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$ignored = (bool) $request->get_param( 'ignored' );
		IgnoredArticles::set( $post_id, $ignored );

		if ( ! $ignored ) {
			// Back in scope: rebuild its Missing Images entry.
			\SmartImageMatcher\Queue\JobRunner::makeMissingImagesIndex()->indexPost( $post_id );
		}

		return rest_ensure_response( array( 'post_id' => $post_id, 'ignored' => $ignored ) );
	}

	/**
	 * Ignored articles.
	 *
	 * @since 3.7.0
	 * @return \WP_REST_Response
	 */
	public function ignoredList(): \WP_REST_Response {
		return rest_ensure_response( array( 'articles' => IgnoredArticles::all() ) );
	}

	/**
	 * Filenames blocked as featured images site-wide.
	 *
	 * @since 3.7.0
	 * @return \WP_REST_Response
	 */
	public function blockedList(): \WP_REST_Response {
		$lines = preg_split( '/\R+/', (string) Settings::get( 'fiaa_excluded_image_slugs' ) ) ?: array();
		$files = array_values( array_filter( array_map( 'trim', $lines ) ) );
		return rest_ensure_response( array( 'files' => $files ) );
	}

	/**
	 * Unblock one filename.
	 *
	 * @since 3.7.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function unblock( \WP_REST_Request $request ): \WP_REST_Response {
		$all                              = Settings::all();
		$all['fiaa_excluded_image_slugs'] = ( new Sanitizer() )->removeExcludedImageSlug( (string) $request->get_param( 'file' ) );
		Settings::save( $all );

		return $this->blockedList();
	}

	/**
	 * What blocking a suggestion's image site-wide would affect (shown before confirming).
	 *
	 * @since 3.7.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function blockImpact( \WP_REST_Request $request ) {
		global $wpdb;

		$match_id = (int) $request->get_param( 'match_id' );
		$table    = $wpdb->prefix . 'smart_image_matcher_matches';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, image_id FROM {$table} WHERE id = %d", $match_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return new \WP_Error( 'smart_image_matcher_match_not_found', __( 'Match not found.', 'smart-image-matcher' ), array( 'status' => 404 ) );
		}

		$image_id = (int) $row['image_id'];
		$featured = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE image_id = %d AND status = 'pending' AND heading_hash = 'featured' AND id <> %d", $image_id, $match_id ) );
		$headings = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE image_id = %d AND status = 'pending' AND heading_hash <> 'featured' AND id <> %d", $image_id, $match_id ) );
		// phpcs:enable

		$file = get_attached_file( $image_id );

		return rest_ensure_response(
			array(
				'file'              => $file ? wp_basename( (string) $file ) : '',
				'other_featured'    => $featured,
				'other_headings'    => $headings,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Featured images stuck in the body
	// -------------------------------------------------------------------------

	/** Posts checked per scan request (keeps each request short). */
	const MISPLACED_BATCH = 100;

	/** Posts moved per request. */
	const MISPLACED_MOVE_MAX = 25;

	/**
	 * Mover service.
	 *
	 * @return \SmartImageMatcher\FeaturedImages\MisplacedFeaturedImages
	 */
	private function misplaced(): \SmartImageMatcher\FeaturedImages\MisplacedFeaturedImages {
		return new \SmartImageMatcher\FeaturedImages\MisplacedFeaturedImages(
			new \SmartImageMatcher\FeaturedImages\FeaturedImageService( new \SmartImageMatcher\FeaturedImages\SlugMapBuilder() ),
			new \SmartImageMatcher\Insertion\InsertionService( new \SmartImageMatcher\Insertion\BlockBuilder() )
		);
	}

	/**
	 * Scan one batch of posts (by ID) for a featured image sitting in the body.
	 *
	 * The client calls again with `after_id = next_after_id` until `done`.
	 *
	 * @since 3.7.3
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function misplacedScan( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$type     = (string) $request->get_param( 'post_type' );
		$type     = post_type_exists( $type ) ? $type : 'post';
		$statuses = PostStatuses::sanitizeList( (string) $request->get_param( 'post_statuses' ) );
		$after    = (int) $request->get_param( 'after_id' );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- only (ID) by keyset; placeholders built above.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$in}) AND ID > %d ORDER BY ID ASC LIMIT %d", array_merge( array( $type ), $statuses, array( $after, self::MISPLACED_BATCH ) ) ) );
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );

		$mover = $this->misplaced();
		$items = array();
		foreach ( $ids as $post_id ) {
			$image_id = $mover->candidate( $post_id );
			if ( $image_id <= 0 ) {
				continue;
			}
			$file    = get_attached_file( $image_id );
			$items[] = array(
				'id'       => $post_id,
				'title'    => html_entity_decode( (string) get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
				'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
				'status'   => (string) get_post_status( $post_id ),
				'image_id' => $image_id,
				'file'     => $file ? wp_basename( (string) $file ) : '',
				'thumb'    => (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ),
			);
		}

		return rest_ensure_response(
			array(
				'items'         => $items,
				'scanned'       => count( $ids ),
				'next_after_id' => $ids ? (int) end( $ids ) : $after,
				'done'          => count( $ids ) < self::MISPLACED_BATCH,
			)
		);
	}

	/**
	 * Move up to 25 posts' body image to their featured image.
	 *
	 * @since 3.7.3
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function misplacedMove( \WP_REST_Request $request ): \WP_REST_Response {
		$ids   = array_slice( array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'post_ids' ) ) ) ), 0, self::MISPLACED_MOVE_MAX );
		$mover = $this->misplaced();
		$out   = array();
		foreach ( $ids as $post_id ) {
			$out[] = array_merge( array( 'id' => $post_id ), $mover->move( $post_id ) );
		}
		return rest_ensure_response( array( 'results' => $out ) );
	}

	// -------------------------------------------------------------------------
	// Schedule
	// -------------------------------------------------------------------------

	/**
	 * Automatic-run settings, open runs (with stuck detection) and recent ticks.
	 *
	 * @since 3.7.0
	 * @return \WP_REST_Response
	 */
	public function schedule(): \WP_REST_Response {
		$intervals = array(
			'hourly'        => __( 'Every hour', 'smart-image-matcher' ),
			'every_4_hours' => __( 'Every 4 hours', 'smart-image-matcher' ),
			'every_6_hours' => __( 'Every 6 hours', 'smart-image-matcher' ),
			'every_8_hours' => __( 'Every 8 hours', 'smart-image-matcher' ),
			'twicedaily'    => __( 'Twice daily', 'smart-image-matcher' ),
			'daily'         => __( 'Daily', 'smart-image-matcher' ),
		);
		$fills = array(
			'both'     => __( 'Featured image and headings', 'smart-image-matcher' ),
			'featured' => __( 'Featured image only', 'smart-image-matcher' ),
			'headings' => __( 'Headings only', 'smart-image-matcher' ),
		);

		$statuses = array();
		foreach ( PostStatuses::sanitizeList( (string) Settings::get( 'fiaa_cron_post_statuses' ) ) as $status ) {
			$obj        = get_post_status_object( $status );
			$statuses[] = $obj ? (string) $obj->label : $status;
		}

		$types = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'fiaa_cron_post_types' ) ) ) ) as $type ) {
			$obj     = get_post_type_object( $type );
			$types[] = $obj ? (string) $obj->labels->name : $type;
		}

		$health = QueueWatchdog::health();

		return rest_ensure_response(
			array(
				'settings'     => array(
					'enabled'   => (bool) Settings::get( 'fiaa_cron_enabled' ),
					'interval'  => $intervals[ (string) Settings::get( 'fiaa_cron_interval' ) ] ?? (string) Settings::get( 'fiaa_cron_interval' ),
					'types'     => implode( ', ', $types ),
					'statuses'  => implode( ', ', $statuses ),
					'featured'  => (string) Settings::get( 'fiaa_cron_featured_filter' ),
					'fill'      => $fills[ ArticleProcessor::normalizeFill( Settings::get( 'fiaa_cron_fill' ) ) ],
					'publish'   => (bool) Settings::get( 'ai_image_auto_featured_on_publish' ),
					'settings_url' => admin_url( 'admin.php?page=smart-image-matcher-settings' ),
				),
				'next_tick'    => $health['next_tick'] ? (int) $health['next_tick'] : 0,
				'ticks'        => $health['ticks'],
				'open_runs'    => $this->openRuns(),
				'health'       => array(
					'state' => $health['state'],
					'title' => $health['title'],
					'queue' => $health['queue'],
				),
			)
		);
	}

	/**
	 * Runs still queued or processing, with progress and stuck flag.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function openRuns(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'smart_image_matcher_queue';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT job_id, totals, created_at, started_at FROM {$table} WHERE status IN ('queued','processing') ORDER BY created_at DESC LIMIT 10", ARRAY_A );

		$now = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- compared with local-time DB columns.
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$totals  = json_decode( (string) $row['totals'], true );
			$totals  = is_array( $totals ) ? $totals : array();
			$touched = isset( $totals['touched_at'] ) ? (int) $totals['touched_at'] : (int) strtotime( (string) ( $row['started_at'] ?: $row['created_at'] ) );
			$idle    = max( 0, (int) floor( ( $now - $touched ) / 60 ) );
			$job_id  = (string) $row['job_id'];

			$out[] = array(
				'job_id'      => $job_id,
				'label'       => false !== strpos( $job_id, '_fiaa_scheduled_' ) ? __( 'Scheduled run', 'smart-image-matcher' ) : __( 'Bulk Processor run', 'smart-image-matcher' ),
				'started'     => (string) ( $row['started_at'] ?: $row['created_at'] ),
				'done'        => (int) ( $totals['done'] ?? 0 ),
				'total'       => (int) ( $totals['total'] ?? 0 ),
				'idle_min'    => $idle,
				'remaining'   => QueueWatchdog::remainingActions( $job_id ),
				'stuck'       => $idle >= self::STUCK_MINUTES,
			);
		}
		return $out;
	}

	/**
	 * Close a run that will never finish, so the next scheduled run can start.
	 *
	 * @since 3.7.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function closeJob( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$table  = $wpdb->prefix . 'smart_image_matcher_queue';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT totals FROM {$table} WHERE job_id = %s", $job_id ) ), true );
		$totals = is_array( $totals ) ? $totals : array();

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'status'        => 'failed',
				'finished_at'   => current_time( 'mysql' ),
				'error_message' => sprintf( 'Closed by user at %d of %d articles.', (int) ( $totals['done'] ?? 0 ), (int) ( $totals['total'] ?? 0 ) ),
			),
			array( 'job_id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);

		Logger::info( 'ReviewToolsController: run closed by user', array( 'job_id' => $job_id ) );

		return rest_ensure_response( array( 'job_id' => $job_id, 'closed' => true ) );
	}

	/**
	 * Queue again the articles a run lost (actions that failed or never ran).
	 *
	 * @since 3.7.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function requeueLost( \WP_REST_Request $request ): \WP_REST_Response {
		$job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
		$queued = 0;

		if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( '\ActionScheduler' ) && class_exists( '\ActionScheduler_Store' ) ) {
			$ids = as_get_scheduled_actions(
				array(
					'hook'     => Queue::HOOK_PROCESS_ARTICLE,
					'group'    => Queue::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_FAILED,
					'search'   => $job_id,
					'per_page' => 500,
				),
				'ids'
			);

			$queue = new Queue();
			$seen  = array();
			foreach ( (array) $ids as $action_id ) {
				try {
					$action = \ActionScheduler::store()->fetch_action( (string) $action_id );
				} catch ( \Throwable $t ) {
					continue;
				}
				$args    = (array) $action->get_args();
				$post_id = (int) ( $args['post_id'] ?? 0 );
				if ( $post_id <= 0 || isset( $seen[ $post_id ] ) || IgnoredArticles::isIgnored( $post_id ) ) {
					continue;
				}
				$seen[ $post_id ] = true;
				$config           = (array) ( $args['config'] ?? array() );
				unset( $config['post_ids'] );
				if ( $queue->enqueueProcessArticle( $post_id, '', $config ) ) {
					++$queued;
				}
			}
		}

		return rest_ensure_response( array( 'queued' => $queued ) );
	}
}
