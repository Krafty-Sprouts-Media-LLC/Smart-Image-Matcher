<?php
/**
 * Action Scheduler job runner callbacks.
 *
 * Each public method is registered as an AS action hook by Queue::registerHooks().
 * All methods are static so AS can invoke them without a service container.
 *
 * @package SmartImageMatcher\Queue
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Queue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\HeadingExtractor;
use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Domain\Matcher;
use SmartImageMatcher\Domain\MatchRepository;
use SmartImageMatcher\Insertion\BlockBuilder;
use SmartImageMatcher\Insertion\InsertionService;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Settings\Settings;

/**
 * Class JobRunner
 *
 * @since 3.0.0
 */
class JobRunner {

	/**
	 * Run an AI match job for a single post.
	 *
	 * Uses AI\Matcher for ai mode; falls back to keyword on any AI error.
	 * Stores results as a short-lived transient so the modal can poll for them.
	 *
	 * @since 3.0.0
	 * @param int    $postId Post ID.
	 * @param string $mode   Matching mode ('ai' or 'keyword').
	 * @return void
	 */
	public static function runAiMatchJob( int $postId, string $mode = 'ai' ): void {
		Logger::info( 'JobRunner: AI match job started', array( 'post_id' => $postId, 'mode' => $mode ) );

		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			Logger::error( 'JobRunner: post not found', array( 'post_id' => $postId ) );
			return;
		}

		$extractor = new HeadingExtractor();
		$headings  = $extractor->extract( $post->post_content );

		if ( empty( $headings ) ) {
			set_transient( "smart_image_matcher_job_result_{$postId}", array( 'matches' => array(), 'done' => true ), 300 );
			return;
		}

		$kwMatcher = new Matcher();
		$hierarchy = (string) Settings::get( 'hierarchy_mode' );
		$headings  = $kwMatcher->filterByHierarchy( $headings, $hierarchy );

		$repo      = new ImageRepository();
		$groups    = array();
		$threshold = (int) Settings::get( 'confidence_threshold' );

		foreach ( $headings as $heading ) {
			if ( 'ai' === $mode ) {
				// AI\Matcher handles the ProviderBridge call and falls back
				// to keyword internally if AI is unavailable.
				$aiMatcher = new \SmartImageMatcher\AI\Matcher();
				$matches   = $aiMatcher->findMatches( $heading, $repo, $threshold );

				if ( is_wp_error( $matches ) ) {
					// AI unavailable; graceful keyword fallback.
					$terms   = $kwMatcher->extractKeywords( $heading['text'] ?? '' );
					$images  = $repo->findCandidates( $terms );
					$matches = $kwMatcher->findKeywordMatches( $heading, $images );
				}
			} else {
				$terms   = $kwMatcher->extractKeywords( $heading['text'] ?? '' );
				$images  = $repo->findCandidates( $terms );
				$matches = $kwMatcher->findKeywordMatches( $heading, $images );
			}

			$groups[] = array( 'heading' => $heading, 'matches' => $matches );
		}

		( new MatchRepository() )->saveMatchGroups( $postId, $groups );

		set_transient( "smart_image_matcher_job_result_{$postId}", array( 'matches' => $groups, 'done' => true ), 300 );

		Logger::info( 'JobRunner: AI match job complete', array( 'post_id' => $postId, 'headings' => count( $groups ) ) );
	}

	/**
	 * Run the one-shot inverted-index backfill job.
	 *
	 * Hooked to Queue::HOOK_INDEX_BACKFILL.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function runIndexBackfill(): void {
		Logger::info( 'JobRunner: index backfill started' );
		$count = ( new ImageRepository() )->backfillAll( 200 );
		Logger::info( 'JobRunner: index backfill done', array( 'count' => $count ) );
	}

	/**
	 * Run a single-post bulk match job.
	 *
	 * Hooked to Queue::HOOK_BULK_MATCH.
	 *
	 * @since 3.0.0
	 * @param string               $jobId  Parent bulk job ID.
	 * @param int                  $postId Post ID.
	 * @param array<string, mixed> $config Matching configuration.
	 * @return void
	 */
	public static function runBulkMatchJob( string $jobId, int $postId, array $config ): void {
		Logger::info( 'JobRunner: bulk match job', array( 'job_id' => $jobId, 'post_id' => $postId ) );

		if ( self::isBulkJobCancelled( $jobId ) ) {
			return;
		}

		self::markBulkJobStarted( $jobId );

		try {
			$post = get_post( $postId );
			if ( ! $post instanceof \WP_Post ) {
				return;
			}

			$extractor = new HeadingExtractor();
			$headings  = $extractor->extract( $post->post_content );

			if ( empty( $headings ) ) {
				return;
			}

			$matcher   = new Matcher();
			$hierarchy = isset( $config['hierarchy_mode'] ) ? (string) $config['hierarchy_mode'] : (string) Settings::get( 'hierarchy_mode' );
			$headings  = $matcher->filterByHierarchy( $headings, $hierarchy );

			$repo   = new ImageRepository();
			$groups = array();

			foreach ( $headings as $heading ) {
				$terms   = $matcher->extractKeywords( $heading['text'] );
				$images  = $repo->findCandidates( $terms );
				$matches = $matcher->findKeywordMatches( $heading, $images );
				$groups[] = array( 'heading' => $heading, 'matches' => $matches );
			}

			( new MatchRepository() )->saveMatchGroups( $postId, $groups );
		} finally {
			self::incrementBulkJobDone( $jobId );
		}
	}

	/**
	 * Run a single-post bulk insert job (inserts all approved matches for that post).
	 *
	 * Hooked to Queue::HOOK_BULK_INSERT.
	 *
	 * @since 3.0.0
	 * @param string $jobId  Parent bulk job ID.
	 * @param int    $postId Post ID.
	 * @return void
	 */
	public static function runBulkInsertJob( string $jobId, int $postId ): void {
		Logger::info( 'JobRunner: bulk insert job', array( 'job_id' => $jobId, 'post_id' => $postId ) );

		if ( self::isBulkJobCancelled( $jobId ) ) {
			return;
		}

		$matchRepo = new MatchRepository();
		$approved  = $matchRepo->getApprovedForPost( $postId );

		if ( empty( $approved ) ) {
			return;
		}

		$insertions = array();
		foreach ( $approved as $row ) {
			$insertions[] = array(
				'heading_hash' => (string) $row['heading_hash'],
				'image_id'     => (int)    $row['image_id'],
			);
		}

		$service = new InsertionService( new BlockBuilder() );
		$result  = $service->bulkInsert( $postId, $insertions );

		if ( is_wp_error( $result ) ) {
			Logger::error( 'JobRunner: bulk insert failed', array(
				'post_id' => $postId,
				'error'   => $result->get_error_message(),
			) );
		}
	}

	/**
	 * Run one batch of a featured image auto-assigner manual job.
	 *
	 * Hooked to Queue::HOOK_FIAA_RUN.
	 *
	 * @since 3.0.0
	 * @param string $jobId Parent job ID.
	 * @return void
	 */
	public static function runFiaaRunJob( string $jobId ): void {
		global $wpdb;

		Logger::info( 'JobRunner: FIAA run batch', array( 'job_id' => $jobId ) );

		if ( self::isBulkJobCancelled( $jobId ) ) {
			return;
		}

		self::markBulkJobStarted( $jobId );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT totals, status FROM {$wpdb->prefix}smart_image_matcher_queue WHERE job_id = %s LIMIT 1",
				$jobId
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || 'cancelled' === ( $row['status'] ?? '' ) ) {
			return;
		}

		$totals = json_decode( (string) ( $row['totals'] ?? '' ), true );
		if ( ! is_array( $totals ) ) {
			self::markFiaaJobFailed( $jobId, __( 'Invalid job payload.', 'smart-image-matcher' ) );
			return;
		}

		$config   = isset( $totals['config'] ) && is_array( $totals['config'] ) ? $totals['config'] : array();
		$postIds  = isset( $config['post_ids'] ) && is_array( $config['post_ids'] ) ? array_map( 'absint', $config['post_ids'] ) : array();
		$overwrite = ! empty( $config['overwrite'] );
		$batchSize = isset( $config['batch_size'] ) ? (int) $config['batch_size'] : 20;
		$batchSize = max( 1, min( 50, $batchSize ) );

		$total  = count( $postIds );
		$offset = isset( $totals['offset'] ) ? (int) $totals['offset'] : 0;
		$offset = max( 0, min( $offset, $total ) );

		$totals['type']      = 'fiaa_manual';
		$totals['total']     = $total;
		$totals['done']      = isset( $totals['done'] ) ? (int) $totals['done'] : 0;
		$totals['matched']   = isset( $totals['matched'] ) ? (int) $totals['matched'] : 0;
		$totals['skipped']   = isset( $totals['skipped'] ) ? (int) $totals['skipped'] : 0;
		$totals['unmatched'] = isset( $totals['unmatched'] ) ? (int) $totals['unmatched'] : 0;
		$totals['recent']    = isset( $totals['recent'] ) && is_array( $totals['recent'] ) ? $totals['recent'] : array();

		if ( 0 === $total || $offset >= $total ) {
			$totals['done']   = $total;
			$totals['offset'] = $total;
			self::saveFiaaJobTotals( $jobId, $totals, 'completed' );
			return;
		}

		$service = new \SmartImageMatcher\FeaturedImages\FeaturedImageService(
			new \SmartImageMatcher\FeaturedImages\SlugMapBuilder()
		);

		$batch = array_slice( $postIds, $offset, $batchSize );

		foreach ( $batch as $postId ) {
			if ( self::isBulkJobCancelled( $jobId ) ) {
				return;
			}

			$post = get_post( $postId );
			if ( ! $post instanceof \WP_Post ) {
				$totals['unmatched']++;
				$totals['recent'][] = self::formatFiaaRecentItem(
					$postId,
					'',
					'',
					__( 'Post not found.', 'smart-image-matcher' ),
					array()
				);
				$totals['done']++;
				$offset++;
				continue;
			}

			$result = $service->assignBestForPost( $postId, $overwrite );
			$reason = (string) ( $result['reason'] ?? '' );

			if ( ! empty( $result['assigned'] ) ) {
				$totals['matched']++;
				$status = __( 'Matched', 'smart-image-matcher' );
			} elseif ( __( 'Post already has a featured image.', 'smart-image-matcher' ) === $reason ) {
				$totals['skipped']++;
				$status = __( 'Skipped', 'smart-image-matcher' );
			} else {
				$totals['unmatched']++;
				$status = '' !== $reason ? $reason : __( 'Unmatched', 'smart-image-matcher' );
			}

			$totals['recent'][] = self::formatFiaaRecentItem(
				$postId,
				get_the_title( $postId ),
				(string) $post->post_name,
				$status,
				$result
			);

			$totals['done']++;
			$offset++;
		}

		$totals['done']   = min( $total, (int) $totals['done'] );
		$totals['offset'] = min( $total, $offset );
		$totals['recent'] = array_slice( $totals['recent'], -30 );

		if ( $totals['done'] >= $total ) {
			self::saveFiaaJobTotals( $jobId, $totals, 'completed' );
			return;
		}

		self::saveFiaaJobTotals( $jobId, $totals, 'processing' );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				Queue::HOOK_FIAA_RUN,
				array( 'job_id' => $jobId ),
				Queue::GROUP
			);
		}
	}

	/**
	 * Run one batch of a featured image audit cleanup job.
	 *
	 * Hooked to Queue::HOOK_FIAA_AUDIT_CLEAR.
	 *
	 * @since 3.0.5
	 * @param string $jobId Parent job ID.
	 * @return void
	 */
	public static function runFiaaAuditClearJob( string $jobId ): void {
		global $wpdb;

		Logger::info( 'JobRunner: FIAA audit clear batch', array( 'job_id' => $jobId ) );

		if ( self::isBulkJobCancelled( $jobId ) ) {
			return;
		}

		self::markBulkJobStarted( $jobId );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT totals, status FROM {$wpdb->prefix}smart_image_matcher_queue WHERE job_id = %s LIMIT 1",
				$jobId
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || 'cancelled' === ( $row['status'] ?? '' ) ) {
			return;
		}

		$totals = json_decode( (string) ( $row['totals'] ?? '' ), true );
		if ( ! is_array( $totals ) ) {
			self::markFiaaJobFailed( $jobId, __( 'Invalid job payload.', 'smart-image-matcher' ) );
			return;
		}

		$config    = isset( $totals['config'] ) && is_array( $totals['config'] ) ? $totals['config'] : array();
		$postIds   = isset( $config['post_ids'] ) && is_array( $config['post_ids'] ) ? array_map( 'absint', $config['post_ids'] ) : array();
		$batchSize = isset( $config['batch_size'] ) ? (int) $config['batch_size'] : 20;
		$batchSize = max( 1, min( 50, $batchSize ) );

		$total  = count( $postIds );
		$offset = isset( $totals['offset'] ) ? (int) $totals['offset'] : 0;
		$offset = max( 0, min( $offset, $total ) );

		$totals['type']      = 'fiaa_audit_clear';
		$totals['total']     = $total;
		$totals['done']      = isset( $totals['done'] ) ? (int) $totals['done'] : 0;
		$totals['matched']   = isset( $totals['matched'] ) ? (int) $totals['matched'] : 0;
		$totals['skipped']   = isset( $totals['skipped'] ) ? (int) $totals['skipped'] : 0;
		$totals['unmatched'] = isset( $totals['unmatched'] ) ? (int) $totals['unmatched'] : 0;
		$totals['recent']    = isset( $totals['recent'] ) && is_array( $totals['recent'] ) ? $totals['recent'] : array();

		if ( 0 === $total || $offset >= $total ) {
			$totals['done']   = $total;
			$totals['offset'] = $total;
			self::saveFiaaJobTotals( $jobId, $totals, 'completed' );
			return;
		}

		$audit = new \SmartImageMatcher\FeaturedImages\FeaturedImageAudit(
			new \SmartImageMatcher\FeaturedImages\FeaturedImageService(
				new \SmartImageMatcher\FeaturedImages\SlugMapBuilder()
			)
		);

		$batch = array_slice( $postIds, $offset, $batchSize );

		foreach ( $batch as $postId ) {
			if ( self::isBulkJobCancelled( $jobId ) ) {
				return;
			}

			$result = $audit->clearIfUnsafe( $postId );
			$status = (string) ( $result['status'] ?? '' );

			if ( ! empty( $result['cleared'] ) ) {
				$totals['matched']++;
			} elseif (
				__( 'No featured image.', 'smart-image-matcher' ) === $status
				|| __( 'Already safe.', 'smart-image-matcher' ) === $status
			) {
				$totals['skipped']++;
			} else {
				$totals['unmatched']++;
			}

			$totals['recent'][] = self::formatFiaaRecentItem(
				$postId,
				get_the_title( $postId ),
				(string) ( $result['post_slug'] ?? '' ),
				$status,
				$result
			);

			$totals['done']++;
			$offset++;
		}

		$totals['done']   = min( $total, (int) $totals['done'] );
		$totals['offset'] = min( $total, $offset );
		$totals['recent'] = array_slice( $totals['recent'], -30 );

		if ( $totals['done'] >= $total ) {
			self::saveFiaaJobTotals( $jobId, $totals, 'completed' );
			return;
		}

		self::saveFiaaJobTotals( $jobId, $totals, 'processing' );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				Queue::HOOK_FIAA_AUDIT_CLEAR,
				array( 'job_id' => $jobId ),
				Queue::GROUP
			);
		}
	}

	/**
	 * Check whether a bulk job was cancelled.
	 *
	 * @since 3.0.0
	 * @param string $jobId Job ID.
	 * @return bool
	 */
	private static function isBulkJobCancelled( string $jobId ): bool {
		global $wpdb;

		$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}smart_image_matcher_queue WHERE job_id = %s LIMIT 1",
				$jobId
			)
		);

		return 'cancelled' === $status;
	}

	/**
	 * Mark a bulk job as processing.
	 *
	 * @since 3.0.0
	 * @param string $jobId Job ID.
	 * @return void
	 */
	private static function markBulkJobStarted( string $jobId ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smart_image_matcher_queue
				 SET status = %s, started_at = COALESCE(started_at, %s)
				 WHERE job_id = %s AND status = %s",
				'processing',
				current_time( 'mysql' ),
				$jobId,
				'queued'
			)
		);
	}

	/**
	 * Increment bulk job progress and mark complete when all posts are scanned.
	 *
	 * @since 3.0.0
	 * @param string $jobId Job ID.
	 * @return void
	 */
	private static function incrementBulkJobDone( string $jobId ): void {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT totals, status FROM {$wpdb->prefix}smart_image_matcher_queue WHERE job_id = %s LIMIT 1",
				$jobId
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || 'cancelled' === ( $row['status'] ?? '' ) ) {
			return;
		}

		$totals = json_decode( (string) ( $row['totals'] ?? '' ), true );
		if ( ! is_array( $totals ) ) {
			$totals = array( 'total' => 0, 'done' => 0 );
		}

		$totals['total'] = isset( $totals['total'] ) ? (int) $totals['total'] : 0;
		$totals['done']  = min( $totals['total'], ( isset( $totals['done'] ) ? (int) $totals['done'] : 0 ) + 1 );

		$status     = $totals['total'] > 0 && $totals['done'] >= $totals['total'] ? 'completed' : 'processing';
		$finishedAt = 'completed' === $status ? current_time( 'mysql' ) : null;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_queue',
			array(
				'status'      => $status,
				'totals'      => wp_json_encode( $totals ),
				'finished_at' => $finishedAt,
			),
			array( 'job_id' => $jobId ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Format one recent featured-image job activity row.
	 *
	 * @since 3.0.0
	 * @param int                  $postId Post ID.
	 * @param string               $title  Post title.
	 * @param string               $slug   Post slug.
	 * @param string               $status Row status text.
	 * @param array<string, mixed> $result Assignment result.
	 * @return array<string, mixed>
	 */
	private static function formatFiaaRecentItem( int $postId, string $title, string $slug, string $status, array $result ): array {
		return array(
			'id'            => $postId,
			'title'         => $title,
			'slug'          => $slug,
			'status'        => $status,
			'attachment_id' => isset( $result['attachment_id'] ) ? (int) $result['attachment_id'] : 0,
			'image_slug'    => isset( $result['image_slug'] ) ? (string) $result['image_slug'] : '',
			'score'         => isset( $result['score'] ) ? (int) $result['score'] : 0,
			'method'        => isset( $result['method'] ) ? (string) $result['method'] : '',
		);
	}

	/**
	 * Save featured-image job progress.
	 *
	 * @since 3.0.0
	 * @param string               $jobId  Job ID.
	 * @param array<string, mixed> $totals Progress payload.
	 * @param string               $status New status.
	 * @return void
	 */
	private static function saveFiaaJobTotals( string $jobId, array $totals, string $status ): void {
		global $wpdb;

		$values = array(
			'status' => $status,
			'totals' => wp_json_encode( $totals ),
		);
		$formats = array( '%s', '%s' );

		if ( 'completed' === $status ) {
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
	 * Mark a featured-image job as failed.
	 *
	 * @since 3.0.0
	 * @param string $jobId   Job ID.
	 * @param string $message Error message.
	 * @return void
	 */
	private static function markFiaaJobFailed( string $jobId, string $message ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'smart_image_matcher_queue',
			array(
				'status'        => 'failed',
				'error_message' => $message,
				'finished_at'   => current_time( 'mysql' ),
			),
			array( 'job_id' => $jobId ),
			array( '%s', '%s', '%s' ),
			array( '%s' )
		);
	}
}
