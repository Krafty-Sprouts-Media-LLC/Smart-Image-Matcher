<?php
/**
 * REST endpoints for the Missing Images page.
 *
 *   GET  /missing-images            one page of articles + their headings without images
 *   GET  /missing-images/all        every matching article (for "Copy all"), capped
 *   GET  /missing-images/library    closest library images for one heading
 *   POST /missing-images/ignore     mark a heading "not needed" (or undo)
 *   POST /missing-images/rescan     rebuild the index from the first post
 *
 * Everything reads MissingImagesIndex post meta; nothing parses posts on read.
 *
 * @package SmartImageMatcher\REST
 * @since   3.6.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Domain\MissingImagesIndex;
use SmartImageMatcher\Domain\Normalizer;
use SmartImageMatcher\Domain\RelevanceGuard;
use SmartImageMatcher\Insertion\BlockBuilder;
use SmartImageMatcher\Insertion\InsertionService;
use SmartImageMatcher\Queue\JobRunner;
use SmartImageMatcher\Queue\Queue;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Class MissingImagesController
 *
 * @since 3.6.0
 */
class MissingImagesController extends Controller {

	/** Most articles returned by /all. */
	const ALL_CAP = 2000;

	/**
	 * Register routes.
	 *
	 * @since 3.6.0
	 * @return void
	 */
	public function registerRoutes(): void {
		$filters = array(
			'search'    => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
			'status'    => array( 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
			'post_type' => array( 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_key' ),
			'category'  => array( 'type' => 'integer', 'default' => 0, 'sanitize_callback' => 'absint' ),
			'numbered'  => array( 'type' => 'boolean', 'default' => false ),
			'no_images' => array( 'type' => 'boolean', 'default' => false ),
			'sort'      => array( 'type' => 'string', 'default' => 'most', 'sanitize_callback' => 'sanitize_key' ),
			'ids'       => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
		);

		register_rest_route(
			self::NAMESPACE,
			'/missing-images',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listPage' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => array_merge(
					$filters,
					array(
						'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'type' => 'integer', 'default' => 25, 'sanitize_callback' => 'absint' ),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/missing-images/all',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listAll' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => $filters,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/missing-images/library',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'library' ),
				'permission_callback' => array( $this, 'canEditPost' ),
				'args'                => array(
					'post_id'      => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'heading_hash' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/missing-images/ignore',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ignore' ),
				'permission_callback' => array( $this, 'canEditPost' ),
				'args'                => array(
					'post_id'      => array( 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ),
					'heading_hash' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'ignored'      => array( 'type' => 'boolean', 'default' => true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/missing-images/rescan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rescan' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);
	}

	/**
	 * Admins only for the list.
	 *
	 * @since 3.6.0
	 * @return bool
	 */
	public function canManage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Admin who can also edit the post.
	 *
	 * @since 3.6.0
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function canEditPost( \WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return current_user_can( 'manage_options' ) && $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	// -------------------------------------------------------------------------
	// Lists
	// -------------------------------------------------------------------------

	/**
	 * One page of articles.
	 *
	 * @since 3.6.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function listPage( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$query    = new \WP_Query( $this->queryArgs( $request, $per_page, $page ) );

		return rest_ensure_response(
			array(
				'items'   => $this->items( $query->posts, (bool) $request->get_param( 'numbered' ) ),
				'total'   => (int) $query->found_posts,
				'pages'   => (int) $query->max_num_pages,
				'page'    => $page,
				'summary' => $this->summary(),
			)
		);
	}

	/**
	 * Every matching article (capped), for "Copy all".
	 *
	 * @since 3.6.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function listAll( \WP_REST_Request $request ): \WP_REST_Response {
		$args                  = $this->queryArgs( $request, self::ALL_CAP, 1 );
		$args['no_found_rows'] = true;
		$query                 = new \WP_Query( $args );

		return rest_ensure_response(
			array(
				'items'  => $this->items( $query->posts, (bool) $request->get_param( 'numbered' ) ),
				'capped' => count( $query->posts ) >= self::ALL_CAP,
			)
		);
	}

	/**
	 * WP_Query args for the filters.
	 *
	 * @param \WP_REST_Request $request  Request.
	 * @param int              $per_page Page size.
	 * @param int              $page     Page.
	 * @return array<string, mixed>
	 */
	private function queryArgs( \WP_REST_Request $request, int $per_page, int $page ): array {
		$numbered  = (bool) $request->get_param( 'numbered' );
		$count_key = $numbered ? MissingImagesIndex::META_NUMBERED : MissingImagesIndex::META_COUNT;

		$status = (string) $request->get_param( 'status' );
		$type   = (string) $request->get_param( 'post_type' );

		$args = array(
			'post_type'              => in_array( $type, MissingImagesIndex::POST_TYPES, true ) ? $type : MissingImagesIndex::POST_TYPES,
			'post_status'            => in_array( $status, MissingImagesIndex::STATUSES, true ) ? $status : MissingImagesIndex::STATUSES,
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed numeric flag; the page's whole purpose.
			'meta_query'             => array(
				'missing' => array(
					'key'     => $count_key,
					'value'   => 0,
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
		);

		if ( (bool) $request->get_param( 'no_images' ) ) {
			$args['meta_query'][] = array(
				'key'   => MissingImagesIndex::META_ALL,
				'value' => '1',
			);
		}

		$category = (int) $request->get_param( 'category' );
		if ( $category > 0 ) {
			$args['cat'] = $category;
		}

		$ids = array_filter( array_map( 'absint', explode( ',', (string) $request->get_param( 'ids' ) ) ) );
		if ( ! empty( $ids ) ) {
			$args['post__in'] = $ids;
		}

		$search = trim( (string) $request->get_param( 'search' ) );
		if ( '' !== $search ) {
			if ( ctype_digit( $search ) ) {
				$args['p'] = (int) $search;
			} else {
				$args['s'] = $search;
			}
		}

		if ( 'new' === (string) $request->get_param( 'sort' ) ) {
			$args['orderby'] = array( 'date' => 'DESC' );
		} else {
			$args['orderby'] = array(
				'missing' => 'DESC',
				'date'    => 'DESC',
			);
		}

		return $args;
	}

	/**
	 * Shape posts for the client.
	 *
	 * @param array<int, \WP_Post|int> $posts         Posts.
	 * @param bool                     $numbered_only Keep numbered headings only.
	 * @return array<int, array<string, mixed>>
	 */
	private function items( array $posts, bool $numbered_only ): array {
		$out = array();
		foreach ( $posts as $post ) {
			$post = get_post( $post );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$entry = get_post_meta( $post->ID, MissingImagesIndex::META, true );
			if ( ! is_array( $entry ) || empty( $entry['heads'] ) ) {
				continue;
			}

			$heads = array_values(
				array_filter(
					(array) $entry['heads'],
					static fn( $head ): bool => is_array( $head ) && ( ! $numbered_only || ! empty( $head['numbered'] ) )
				)
			);

			$status_obj = get_post_status_object( $post->post_status );
			$cats       = 'post' === $post->post_type ? get_the_category( $post->ID ) : array();

			$out[] = array(
				'id'          => (int) $post->ID,
				'title'       => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'status'      => $status_obj ? (string) $status_obj->label : (string) $post->post_status,
				'type'        => (string) $post->post_type,
				'category'    => ! empty( $cats ) ? html_entity_decode( (string) $cats[0]->name, ENT_QUOTES, 'UTF-8' ) : '',
				'edit_url'    => (string) get_edit_post_link( $post->ID, 'raw' ),
				'view_url'    => (string) get_permalink( $post ),
				'total'       => (int) ( $entry['total'] ?? 0 ),
				'missing'     => count( (array) $entry['heads'] ),
				'all_missing' => ! empty( $entry['all_missing'] ),
				'heads'       => $heads,
			);
		}
		return $out;
	}

	/**
	 * Site-wide totals and scan progress.
	 *
	 * @return array<string, int|bool>
	 */
	private function summary(): array {
		global $wpdb;

		$statuses = "'" . implode( "','", MissingImagesIndex::STATUSES ) . "'";
		$types    = "'" . implode( "','", MissingImagesIndex::POST_TYPES ) . "'";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant IN lists, keys prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(c.meta_value > 0) AS articles,
					COALESCE(SUM(c.meta_value), 0) AS headings,
					COALESCE(SUM(n.meta_value), 0) AS numbered,
					SUM(a.meta_value = '1' AND c.meta_value > 0) AS no_images,
					COUNT(c.post_id) AS indexed
				 FROM {$wpdb->postmeta} c
				 INNER JOIN {$wpdb->posts} p ON p.ID = c.post_id AND p.post_status IN ({$statuses}) AND p.post_type IN ({$types})
				 LEFT JOIN {$wpdb->postmeta} n ON n.post_id = c.post_id AND n.meta_key = %s
				 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = c.post_id AND a.meta_key = %s
				 WHERE c.meta_key = %s",
				MissingImagesIndex::META_NUMBERED,
				MissingImagesIndex::META_ALL,
				MissingImagesIndex::META_COUNT
			),
			ARRAY_A
		);

		$eligible = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status IN ({$statuses}) AND post_type IN ({$types})" );

		$ignored = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", MissingImagesIndex::META_IGNORED ) );
		// phpcs:enable

		$ignored_total = 0;
		foreach ( (array) $ignored as $raw ) {
			$list           = maybe_unserialize( $raw );
			$ignored_total += is_array( $list ) ? count( $list ) : 0;
		}

		$state = get_option( MissingImagesIndex::SCAN_STATE_OPTION, array() );

		return array(
			'articles'  => (int) ( $row['articles'] ?? 0 ),
			'headings'  => (int) ( $row['headings'] ?? 0 ),
			'numbered'  => (int) ( $row['numbered'] ?? 0 ),
			'no_images' => (int) ( $row['no_images'] ?? 0 ),
			'ignored'   => $ignored_total,
			'indexed'   => min( $eligible, (int) ( $row['indexed'] ?? 0 ) ),
			'eligible'  => $eligible,
			'scanning'  => is_array( $state ) && ! empty( $state ) && empty( $state['done'] ),
		);
	}

	// -------------------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------------------

	/**
	 * Closest library images for one heading (never the featured image or one already in the article).
	 *
	 * @since 3.6.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function library( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$hash    = (string) $request->get_param( 'heading_hash' );
		$post    = get_post( $post_id );
		$head    = $this->findHead( $post_id, $hash );

		if ( ! $post instanceof \WP_Post || null === $head ) {
			return new \WP_Error( 'smart_image_matcher_heading_not_found', __( 'That heading is no longer in the list. Refresh the page.', 'smart-image-matcher' ), array( 'status' => 404 ) );
		}

		$exclude   = ( new InsertionService( new BlockBuilder() ) )->attachmentIdsInContent( $post_id );
		$featured  = (int) get_post_thumbnail_id( $post_id );
		$exclude[] = $featured;

		$candidates = RelevanceGuard::filter(
			( new ImageRepository() )->findCandidates( Normalizer::normalizeFromSettings( (string) $head['text'] ), 30 ),
			(string) $head['text'],
			(string) $post->post_title
		);

		$out = array();
		foreach ( $candidates as $image ) {
			$id = (int) ( $image['id'] ?? 0 );
			if ( $id <= 0 || in_array( $id, $exclude, true ) ) {
				continue;
			}
			$out[] = array(
				'id'       => $id,
				'filename' => (string) ( $image['filename'] ?? '' ),
				'thumb'    => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
			);
			if ( count( $out ) >= 3 ) {
				break;
			}
		}

		return rest_ensure_response( array( 'images' => $out ) );
	}

	/**
	 * Mark a heading "not needed" (or bring it back).
	 *
	 * @since 3.6.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function ignore( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		JobRunner::makeMissingImagesIndex()->setIgnored(
			$post_id,
			(string) $request->get_param( 'heading_hash' ),
			(bool) $request->get_param( 'ignored' )
		);

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Rebuild the index from the first post.
	 *
	 * @since 3.6.0
	 * @return \WP_REST_Response
	 */
	public function rescan(): \WP_REST_Response {
		MissingImagesIndex::armScan();
		( new Queue() )->maybeResumeMissingScan();

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * One indexed heading by hash.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $hash    Heading hash.
	 * @return array<string, mixed>|null
	 */
	private function findHead( int $post_id, string $hash ): ?array {
		$entry = get_post_meta( $post_id, MissingImagesIndex::META, true );
		foreach ( is_array( $entry ) ? (array) ( $entry['heads'] ?? array() ) : array() as $head ) {
			if ( is_array( $head ) && $hash === (string) ( $head['hash'] ?? '' ) ) {
				return $head;
			}
		}
		return null;
	}
}
