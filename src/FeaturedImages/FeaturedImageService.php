<?php
/**
 * Featured Image Auto-Assigner service.
 *
 * Matches post slugs to attachment filenames and sets featured images.
 * Ported from .legacy/includes/class-sim-featured-image-auto-assigner.php.
 *
 * Free tier:  upload-time auto-assign for post/page (configurable post types).
 * Premium:    scheduled cron run + admin tool + overwrite mode (FiaaCron / FiaaTool).
 *
 * @package SmartImageMatcher\FeaturedImages
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\FeaturedImages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Settings\Settings;
use SmartImageMatcher\Domain\PostStatuses;

/**
 * Class FeaturedImageService
 *
 * @since 3.0.0
 */
class FeaturedImageService {

	/**
	 * Batch size for run() pagination.
	 */
	const DEFAULT_BATCH_SIZE     = 200;
	const MIN_SMART_SCORE        = 70;
	const MIN_SUGGESTION_SCORE   = 70;
	const AMBIGUITY_GAP          = 8;
	const AUTO_ASSIGN_METHODS    = array( 'exact', 'prefix', 'reverse_prefix' );

	/**
	 * @var SlugMapBuilder
	 */
	private SlugMapBuilder $slugMap;

	/**
	 * Constructor.
	 *
	 * @param SlugMapBuilder $slugMap Attachment slug map builder.
	 */
	public function __construct( SlugMapBuilder $slugMap ) {
		$this->slugMap = $slugMap;
	}

	/**
	 * Register hooks.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_attachment',    array( $this, 'onImageUpload' ) );
		add_action( 'delete_attachment', array( $this->slugMap, 'clearCache' ) );
		add_action( 'edit_attachment',   array( $this->slugMap, 'clearCache' ) );
	}

	/**
	 * Auto-assign on image upload when the attachment slug matches a post slug.
	 *
	 * Only fires when 'fiaa_auto_assign_on_upload' is enabled.
	 * Only assigns when the target post has no real featured image already set.
	 *
	 * @since 3.0.0
	 * @param int $attachmentId Newly-uploaded attachment ID.
	 * @return void
	 */
	public function onImageUpload( int $attachmentId ): void {
		if ( ! Settings::get( 'fiaa_auto_assign_on_upload' ) ) {
			return;
		}

		if ( ! wp_attachment_is_image( $attachmentId ) ) {
			return;
		}

		// Invalidate the slug map so it reflects this new upload.
		$this->slugMap->clearCache();

		$attachment = get_post( $attachmentId );
		if ( ! $attachment instanceof \WP_Post || empty( $attachment->post_name ) ) {
			return;
		}

		// Determine allowed post types.
		$rawTypes      = (string) Settings::get( 'fiaa_upload_post_types' );
		$allowedTypes  = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $rawTypes ) ) ) );
		$supportedTypes = $this->getSupportedPostTypes();
		$allowedTypes   = array_values( array_intersect( $allowedTypes, $supportedTypes ) );

		if ( empty( $allowedTypes ) ) {
			$allowedTypes = array( 'post', 'page' );
		}

		$postIds = $this->getCandidatePostIdsForImageSlug( $attachment->post_name, $allowedTypes );

		foreach ( $postIds as $postId ) {
			if ( $this->hasRealFeaturedImage( $postId ) ) {
				continue;
			}

			$result = $this->assignBestForPost( $postId, false );
			if ( ! empty( $result['assigned'] ) && (int) ( $result['attachment_id'] ?? 0 ) === $attachmentId ) {
				continue;
			}
		}
	}

	/**
	 * Run the slug matcher across all posts of a given type.
	 *
	 * Processes in batches to avoid memory exhaustion on large sites.
	 *
	 * @since 3.0.0
	 * @param string               $postType  Post type slug.
	 * @param bool                 $overwrite Replace existing featured images.
	 * @param array<string, mixed> $args      Optional filters: post_statuses, featured_filter.
	 * @return array<string, mixed>  {matched, skipped, unmatched, total}
	 */
	public function run( string $postType, bool $overwrite = false, array $args = array() ): array {
		$results = array(
			'matched'   => array(),
			'skipped'   => array(),
			'unmatched' => array(),
			'total'     => 0,
		);

		$slugMap        = $this->slugMap->get();
		$batchSize      = (int) apply_filters( 'smart_image_matcher_fiaa_batch_size', self::DEFAULT_BATCH_SIZE ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- smart_image_matcher_ is the project hook prefix.
		$batchSize      = max( 25, min( 1000, $batchSize ) );
		$page           = 1;
		$postStatuses   = $this->sanitizePostStatuses( $args['post_statuses'] ?? array( 'publish', 'draft', 'pending', 'future' ) );
		$featuredFilter = $this->sanitizeFeaturedFilter( (string) ( $args['featured_filter'] ?? ( $overwrite ? 'any' : 'missing' ) ) );

		do {
			$queryArgs = array(
				'post_type'              => sanitize_key( $postType ),
				'post_status'            => $postStatuses,
				'posts_per_page'         => $batchSize,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			);

			$metaQuery = $this->getFeaturedImageMetaQuery( $featuredFilter );
			if ( ! empty( $metaQuery ) ) {
				$queryArgs['meta_query'] = $metaQuery; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			}

			$batch = get_posts( $queryArgs );

			foreach ( $batch as $postId ) {
				$post = get_post( $postId );
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}

				$results['total']++;
				$slug = $post->post_name;

				if ( empty( $slug ) ) {
					$results['unmatched'][] = array(
						'id'     => $postId,
						'title'  => get_the_title( $postId ),
						'slug'   => '(empty)',
						'reason' => __( 'Post has no slug.', 'smart-image-matcher' ),
					);
					continue;
				}

				if ( ! $overwrite && $this->hasRealFeaturedImage( $postId ) ) {
					$results['skipped'][] = array(
						'id'    => $postId,
						'title' => get_the_title( $postId ),
						'slug'  => $slug,
					);
					continue;
				}

				$result = $this->assignBestForPost( $postId, $overwrite );

				if ( ! empty( $result['assigned'] ) ) {
					$results['matched'][] = array(
						'id'            => $postId,
						'title'         => get_the_title( $postId ),
						'slug'          => $slug,
						'attachment_id' => (int) $result['attachment_id'],
						'image_slug'    => (string) ( $result['image_slug'] ?? '' ),
						'score'         => (int) ( $result['score'] ?? 0 ),
						'method'        => (string) ( $result['method'] ?? '' ),
					);
				} else {
					$results['unmatched'][] = array(
						'id'     => $postId,
						'title'  => get_the_title( $postId ),
						'slug'   => $slug,
						'reason' => (string) ( $result['reason'] ?? __( 'No matching image filename found.', 'smart-image-matcher' ) ),
						'candidates' => $result['candidates'] ?? array(),
					);
				}
			}

			$page++;
		} while ( count( $batch ) === $batchSize );

		return $results;
	}

	/**
	 * Set the featured image for a post and attach the media item.
	 *
	 * @since 3.0.0
	 * @param int $postId       Post ID.
	 * @param int $attachmentId Attachment ID.
	 * @return void
	 */
	public function assignFeaturedImage( int $postId, int $attachmentId ): void {
		if ( $postId <= 0 || $attachmentId <= 0 ) {
			return;
		}

		set_post_thumbnail( $postId, $attachmentId );

		// Attach the media item to this post so it doesn't appear "(Unattached)".
		$attachment = get_post( $attachmentId );
		if (
			$attachment instanceof \WP_Post
			&& 'attachment' === $attachment->post_type
			&& $postId !== (int) $attachment->post_parent
		) {
			wp_update_post( array(
				'ID'          => $attachmentId,
				'post_parent' => $postId,
			) );
		}
	}

	/**
	 * Assign the best smart slug match for a post.
	 *
	 * @since 3.0.0
	 * @param int  $postId    Post ID.
	 * @param bool $overwrite Replace existing featured image.
	 * @return array<string, mixed>
	 */
	public function assignBestForPost( int $postId, bool $overwrite = false ): array {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'assigned'      => false,
				'attachment_id' => null,
				'reason'        => __( 'Post not found.', 'smart-image-matcher' ),
			);
		}

		if ( ! $overwrite && $this->hasRealFeaturedImage( $postId ) ) {
			return array(
				'assigned'      => false,
				'attachment_id' => null,
				'reason'        => __( 'Post already has a featured image.', 'smart-image-matcher' ),
			);
		}

		$match = $this->findBestMatchForSlug( $post->post_name );

		if ( empty( $match['matched'] ) ) {
			$match['assigned'] = false;
			return $match;
		}

		$this->assignFeaturedImage( $postId, (int) $match['attachment_id'] );

		$match['assigned'] = true;
		return $match;
	}

	/**
	 * Find the best image attachment for a post slug.
	 *
	 * @since 3.0.0
	 * @param string $postSlug Post slug.
	 * @return array<string, mixed>
	 */
	public function findBestMatchForSlug( string $postSlug ): array {
		return $this->selectBestSlugMatch( $postSlug, $this->slugMap->get() );
	}

	/**
	 * Whether a post slug and image slug are safe to auto-assign under current rules.
	 *
	 * @since 3.0.5
	 * @param string $postSlug  Post slug.
	 * @param string $imageSlug Attachment slug.
	 * @return bool
	 */
	public function isAutoAssignSafePair( string $postSlug, string $imageSlug ): bool {
		$score = $this->scoreSlugMatch( $postSlug, $imageSlug );

		return $this->canAutoAssign( $score ) && (int) $score['score'] >= self::MIN_SMART_SCORE;
	}

	/**
	 * Score an image slug against a post slug.
	 *
	 * @since 3.0.0
	 * @param string $postSlug  Post slug.
	 * @param string $imageSlug Image/attachment slug.
	 * @return array<string, mixed>
	 */
	public function scoreSlugMatch( string $postSlug, string $imageSlug ): array {
		$postSlug  = $this->normalizeSlug( $postSlug );
		$imageSlug = $this->normalizeSlug( $imageSlug );

		if ( '' === $postSlug || '' === $imageSlug ) {
			return array( 'score' => 0, 'method' => 'empty', 'shared_terms' => 0 );
		}

		if ( $postSlug === $imageSlug ) {
			return array( 'score' => 100, 'method' => 'exact', 'shared_terms' => count( $this->slugTerms( $imageSlug ) ) );
		}

		$postTerms  = $this->slugTerms( $postSlug );
		$imageTerms = $this->slugTerms( $imageSlug );

		if ( empty( $postTerms ) || empty( $imageTerms ) ) {
			return array( 'score' => 0, 'method' => 'empty_terms', 'shared_terms' => 0 );
		}

		if ( count( $imageTerms ) < 2 ) {
			return array( 'score' => 0, 'method' => 'too_few_terms', 'shared_terms' => 0 );
		}

		$shared       = array_values( array_intersect( $imageTerms, $postTerms ) );
		$sharedCount  = count( $shared );
		$minimumTerms = min( 2, count( $imageTerms ) );

		if ( $sharedCount < $minimumTerms ) {
			return array( 'score' => 0, 'method' => 'too_few_terms', 'shared_terms' => $sharedCount );
		}

		if ( 0 === strpos( $postSlug, $imageSlug . '-' ) ) {
			return array(
				'score'        => 96,
				'method'       => 'prefix',
				'shared_terms' => $sharedCount,
			);
		}

		if ( 0 === strpos( $imageSlug, $postSlug . '-' ) ) {
			return array(
				'score'        => 88,
				'method'       => 'reverse_prefix',
				'shared_terms' => $sharedCount,
			);
		}

		$imageCoverage = $sharedCount / count( $imageTerms );
		$postCoverage  = $sharedCount / count( $postTerms );
		$score         = (int) round( min( 92, ( $imageCoverage * 82 ) + ( $postCoverage * 12 ) ) );

		if ( $imageCoverage < 0.6 ) {
			$score = min( $score, 74 );
		}

		$method = 'token_overlap';
		if ( $this->hasDistinguishingTermConflict( $postTerms, $imageTerms ) ) {
			$method = 'held_terms';
		}

		return array(
			'score'        => $score,
			'method'       => $method,
			'shared_terms' => $sharedCount,
		);
	}

	/**
	 * Whether two term lists differ on meaningful words on both sides.
	 *
	 * Used to flag "season" vs "regulations" style overlaps for manual review.
	 *
	 * @since 3.0.4
	 * @param string[] $postTerms  Post slug terms.
	 * @param string[] $imageTerms Image slug terms.
	 * @return bool
	 */
	public function hasDistinguishingTermConflict( array $postTerms, array $imageTerms ): bool {
		$postOnly  = array_values( array_diff( $postTerms, $imageTerms ) );
		$imageOnly = array_values( array_diff( $imageTerms, $postTerms ) );

		return ! empty( $postOnly ) && ! empty( $imageOnly );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether a post has a real stored _thumbnail_id (not just a filter-injected one).
	 *
	 * @since 3.0.0
	 * @param int $postId Post ID.
	 * @return bool
	 */
	private function hasRealFeaturedImage( int $postId ): bool {
		if ( ! metadata_exists( 'post', $postId, '_thumbnail_id' ) ) {
			return false;
		}

		$thumbnailId = (int) get_metadata_raw( 'post', $postId, '_thumbnail_id', true );
		return $thumbnailId > 0;
	}

	/**
	 * Select the best slug match from a slug => attachment_id map.
	 *
	 * @since 3.0.0
	 * @param string             $postSlug Post slug.
	 * @param array<string, int> $slugMap  Attachment slug map.
	 * @return array<string, mixed>
	 */
	private function selectBestSlugMatch( string $postSlug, array $slugMap ): array {
		$autoCandidates = array();
		$suggestions    = array();

		foreach ( $slugMap as $imageSlug => $attachmentId ) {
			$score = $this->scoreSlugMatch( $postSlug, (string) $imageSlug );
			$entry = array(
				'attachment_id' => (int) $attachmentId,
				'image_slug'    => (string) $imageSlug,
				'score'         => (int) $score['score'],
				'method'        => (string) $score['method'],
				'shared_terms'  => (int) $score['shared_terms'],
			);

			if ( $this->canAutoAssign( $score ) && (int) $score['score'] >= self::MIN_SMART_SCORE ) {
				$autoCandidates[] = $entry;
				continue;
			}

			if ( $this->isSuggestionCandidate( $score ) ) {
				$suggestions[] = $entry;
			}
		}

		if ( empty( $autoCandidates ) ) {
			if ( ! empty( $suggestions ) ) {
				usort(
					$suggestions,
					static fn( $a, $b ) => (int) $b['score'] <=> (int) $a['score']
				);

				return array(
					'matched'       => false,
					'attachment_id' => null,
					'reason'        => $this->getSuggestionHoldReason( $suggestions[0] ),
					'candidates'    => array_slice( $suggestions, 0, 3 ),
					'held'          => true,
				);
			}

			return array(
				'matched'       => false,
				'attachment_id' => null,
				'reason'        => __( 'No matching image filename found.', 'smart-image-matcher' ),
				'candidates'    => array(),
			);
		}

		usort(
			$autoCandidates,
			static fn( $a, $b ) => (int) $b['score'] <=> (int) $a['score']
		);

		$best   = $autoCandidates[0];
		$second = $autoCandidates[1] ?? null;

		if (
			null !== $second
			&& (int) $best['score'] < 100
			&& ( (int) $best['score'] - (int) $second['score'] ) < self::AMBIGUITY_GAP
		) {
			return array(
				'matched'       => false,
				'attachment_id' => null,
				'reason'        => __( 'Ambiguous image slug match; review manually.', 'smart-image-matcher' ),
				'candidates'    => array_slice( $autoCandidates, 0, 3 ),
				'held'          => true,
			);
		}

		$best['matched']    = true;
		$best['candidates'] = array_slice( array_merge( $autoCandidates, $suggestions ), 0, 3 );

		return $best;
	}

	/**
	 * Whether a scored slug pair may be auto-assigned.
	 *
	 * Token overlap and distinguishing-term conflicts are never auto-assigned.
	 *
	 * @since 3.0.4
	 * @param array<string, mixed> $score Score payload from scoreSlugMatch().
	 * @return bool
	 */
	private function canAutoAssign( array $score ): bool {
		return in_array( (string) ( $score['method'] ?? '' ), self::AUTO_ASSIGN_METHODS, true );
	}

	/**
	 * Whether a scored slug pair should be surfaced as a manual suggestion.
	 *
	 * @since 3.0.4
	 * @param array<string, mixed> $score Score payload from scoreSlugMatch().
	 * @return bool
	 */
	private function isSuggestionCandidate( array $score ): bool {
		$method = (string) ( $score['method'] ?? '' );

		return in_array( $method, array( 'token_overlap', 'held_terms' ), true )
			&& (int) ( $score['score'] ?? 0 ) >= self::MIN_SUGGESTION_SCORE;
	}

	/**
	 * Human-readable hold reason for suggestion-only matches.
	 *
	 * @since 3.0.4
	 * @param array<string, mixed> $topSuggestion Top suggestion row.
	 * @return string
	 */
	private function getSuggestionHoldReason( array $topSuggestion ): string {
		if ( 'held_terms' === (string) ( $topSuggestion['method'] ?? '' ) ) {
			return __( 'Similar filenames differ on key terms; review manually.', 'smart-image-matcher' );
		}

		return __( 'Similar filename held for review; only exact and prefix matches are auto-assigned.', 'smart-image-matcher' );
	}

	/**
	 * Normalize a slug-like string.
	 *
	 * @since 3.0.0
	 * @param string $slug Raw slug.
	 * @return string
	 */
	private function normalizeSlug( string $slug ): string {
		$slug = strtolower( $slug );
		$slug = (string) preg_replace( '/\.[a-z0-9]{2,5}$/', '', $slug );
		$slug = (string) preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return trim( $slug, '-' );
	}

	/**
	 * Convert a slug into meaningful terms.
	 *
	 * @since 3.0.0
	 * @param string $slug Slug.
	 * @return string[]
	 */
	private function slugTerms( string $slug ): array {
		$words = preg_split( '/-+/', $this->normalizeSlug( $slug ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return array();
		}

		$stopWords = array(
			'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'can', 'do', 'does',
			'every', 'for', 'from', 'had', 'has', 'have', 'how', 'in', 'is', 'it',
			'know', 'may', 'must', 'of', 'on', 'or', 'should', 'that', 'the',
			'these', 'this', 'those', 'to', 'was', 'what', 'when', 'where', 'who',
			'why', 'will', 'with', 'would', 'you', 'your',
		);

		return array_values(
			array_unique(
				array_filter(
					$words,
					static fn( $word ) => strlen( $word ) > 1 && ! in_array( $word, $stopWords, true )
				)
			)
		);
	}

	/**
	 * Get posts whose slug could plausibly match a newly-uploaded image slug.
	 *
	 * @since 3.0.0
	 * @param string   $imageSlug    Attachment slug.
	 * @param string[] $allowedTypes Allowed post types.
	 * @return int[]
	 */
	private function getCandidatePostIdsForImageSlug( string $imageSlug, array $allowedTypes ): array {
		global $wpdb;

		$imageSlug = $this->normalizeSlug( $imageSlug );
		if ( '' === $imageSlug || empty( $allowedTypes ) ) {
			return array();
		}

		$typePlaceholders = implode( ', ', array_fill( 0, count( $allowedTypes ), '%s' ) );
		$queryStatuses    = PostStatuses::sanitizeList( array( 'publish', 'draft', 'pending', 'future', 'private' ) );
		$statusPlaceholders = implode( ', ', array_fill( 0, count( $queryStatuses ), '%s' ) );
		$args             = array_merge(
			$allowedTypes,
			$queryStatuses,
			array(
				$imageSlug,
				$wpdb->esc_like( $imageSlug ) . '-%',
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$query = "SELECT ID
				 FROM {$wpdb->posts}
				 WHERE post_type IN ({$typePlaceholders})
				   AND post_status IN ({$statusPlaceholders})
				   AND (post_name = %s OR post_name LIKE %s)
				 ORDER BY ID DESC
				 LIMIT 50";

		$ids = $wpdb->get_col(
			$wpdb->prepare( $query, ...$args )
		);
		// phpcs:enable

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Return public post types excluding attachment.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	private function getSupportedPostTypes(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Sanitize post statuses used by batch queries.
	 *
	 * @since 3.0.0
	 * @param mixed $statuses Raw statuses.
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
	 * Sanitize featured image filter values.
	 *
	 * @since 3.0.0
	 * @param string $filter Raw filter.
	 * @return string
	 */
	private function sanitizeFeaturedFilter( string $filter ): string {
		return in_array( $filter, array( 'any', 'missing', 'has' ), true ) ? $filter : 'missing';
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
}
