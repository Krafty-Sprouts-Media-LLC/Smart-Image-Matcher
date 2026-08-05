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
	 * Run one batch of the inverted-index backfill job.
	 *
	 * Hooked to Queue::HOOK_INDEX_BACKFILL. Processes a single bounded batch
	 * (see ImageRepository::backfillBatch()) and, if the library is not
	 * fully indexed yet, enqueues a fresh async action for the next batch
	 * instead of looping internally. This keeps each individual Action
	 * Scheduler execution short (avoids PHP max_execution_time and AS's
	 * async-request watchdog killing a single long-running job on large
	 * libraries) and makes the backfill resumable: if one batch's action
	 * fails or is abandoned, Queue::maybeResumeIndexBackfill() detects the
	 * incomplete cursor on the next request and re-enqueues from where it
	 * left off, rather than leaving the rest of the library unindexed.
	 *
	 * @since 3.1.0
	 * @return void
	 */
	public static function runIndexBackfill(): void {
		$repo   = new ImageRepository();
		$state  = $repo->getBackfillState();
		$offset = (int) ( $state['offset'] ?? 0 );

		Logger::info( 'JobRunner: index backfill batch started', array( 'offset' => $offset ) );

		$result = $repo->backfillBatch( $offset, 200 );

		$repo->saveBackfillState( array(
			'offset'     => $result['next_offset'],
			'done'       => $result['done'],
			'updated_at' => current_time( 'mysql' ),
		) );

		if ( $result['done'] ) {
			Logger::info( 'JobRunner: index backfill complete', array( 'total_indexed' => $result['next_offset'] ) );
			return;
		}

		// More images remain. Enqueue the next batch as a fresh async
		// action rather than recursing/looping in this execution.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( Queue::HOOK_INDEX_BACKFILL, array(), Queue::GROUP );
		}
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

		// Preserve the job type set at creation time (e.g. 'fiaa_scheduled')
		// rather than stomping it — this used to be hardcoded to
		// 'fiaa_manual' on every batch, which meant a scheduled run's job
		// row always reported itself as manual and finalizeScheduledFiaaRun()
		// could never recognise it as a scheduled job to summarise.
		$totals['type']      = ( isset( $totals['type'] ) && is_string( $totals['type'] ) && '' !== $totals['type'] )
			? $totals['type']
			: 'fiaa_manual';
		$totals['total']     = $total;
		$totals['done']      = isset( $totals['done'] ) ? (int) $totals['done'] : 0;
		$totals['matched']   = isset( $totals['matched'] ) ? (int) $totals['matched'] : 0;
		$totals['skipped']   = isset( $totals['skipped'] ) ? (int) $totals['skipped'] : 0;
		$totals['unmatched'] = isset( $totals['unmatched'] ) ? (int) $totals['unmatched'] : 0;
		$totals['recent']    = isset( $totals['recent'] ) && is_array( $totals['recent'] ) ? $totals['recent'] : array();

		if ( 0 === $total || $offset >= $total ) {
			$totals['done']   = $total;
			$totals['offset'] = $total;

			if ( 'fiaa_scheduled' === $totals['type'] ) {
				self::finalizeScheduledFiaaRun( $totals, $config );
			}

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
			if ( 'fiaa_scheduled' === $totals['type'] ) {
				self::finalizeScheduledFiaaRun( $totals, $config );
			}

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
	 * Persist the "last scheduled run" summary once a scheduled FIAA job
	 * (job type 'fiaa_scheduled') finishes its final batch.
	 *
	 * Mirrors the summary shape FiaaCron::runScheduledAssignment() used to
	 * write synchronously, so the Featured Images admin page's "Last
	 * Scheduled Run" card keeps working unchanged.
	 *
	 * @since 3.1.0
	 * @param array<string, mixed> $totals Final job totals.
	 * @param array<string, mixed> $config Job config captured at creation time.
	 * @return void
	 */
	private static function finalizeScheduledFiaaRun( array $totals, array $config ): void {
		$summary = array(
			'ran_at'                 => current_time( 'mysql' ),
			'matched'                => (int) ( $totals['matched'] ?? 0 ),
			'skipped'                => (int) ( $totals['skipped'] ?? 0 ),
			'unmatched'              => (int) ( $totals['unmatched'] ?? 0 ),
			'total'                  => (int) ( $totals['total'] ?? 0 ),
			'post_types'             => isset( $config['post_types'] ) && is_array( $config['post_types'] ) ? $config['post_types'] : array(),
			'duration_ms'            => isset( $totals['started_at_ms'] ) ? (int) round( microtime( true ) * 1000 - (int) $totals['started_at_ms'] ) : 0,
			'fiaa_schedule_interval' => isset( $config['interval'] ) ? (string) $config['interval'] : '',
			'post_statuses'          => isset( $config['post_statuses'] ) && is_array( $config['post_statuses'] ) ? $config['post_statuses'] : array(),
			'featured_filter'        => isset( $config['featured_filter'] ) ? (string) $config['featured_filter'] : '',
			'overwrite'              => ! empty( $config['overwrite'] ),
		);

		// Preserve the previously remembered scheduled interval so
		// FiaaCron::maybeReschedule() can still detect interval changes.
		$runtime = get_option( Settings::RUNTIME_OPTION, array() );
		$runtime = is_array( $runtime ) ? $runtime : array();
		if ( isset( $runtime['fiaa_schedule_interval'] ) && '' === $summary['fiaa_schedule_interval'] ) {
			$summary['fiaa_schedule_interval'] = (string) $runtime['fiaa_schedule_interval'];
		}

		update_option( Settings::RUNTIME_OPTION, $summary, false );

		Logger::info( 'JobRunner: scheduled FIAA run complete', $summary );
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

	/**
	 * Run an on-demand AI image generation job.
	 *
	 * @since 3.1.1
	 * @param array<string, mixed> $payload Job payload from Queue::enqueueAiImageGen().
	 * @return void
	 */
	public static function runAiImageGenJob( $payload ): void {
		if ( ! is_array( $payload ) ) {
			Logger::error( 'JobRunner: AI image gen payload invalid' );
			return;
		}

		$post_id       = absint( $payload['post_id'] ?? 0 );
		$heading_hash  = sanitize_text_field( (string) ( $payload['heading_hash'] ?? '' ) );
		$heading_text  = sanitize_text_field( (string) ( $payload['heading_text'] ?? '' ) );
		$section_text  = sanitize_textarea_field( (string) ( $payload['section_text'] ?? '' ) );
		$focus_keyword = sanitize_text_field( (string) ( $payload['focus_keyword'] ?? '' ) );
		$style         = sanitize_key( (string) ( $payload['style'] ?? 'photo' ) );
		$force         = ! empty( $payload['force'] );

		if ( $post_id <= 0 || '' === $heading_hash ) {
			Logger::error( 'JobRunner: AI image gen missing post_id or heading_hash' );
			return;
		}

		Logger::info(
			'JobRunner: AI image gen started',
			array(
				'post_id'      => $post_id,
				'heading_hash' => $heading_hash,
			)
		);

		\SmartImageMatcher\Premium\AiImageGenerator::setStatus(
			$post_id,
			$heading_hash,
			array(
				'status' => 'processing',
			)
		);

		$generator = new \SmartImageMatcher\Premium\AiImageGenerator();
		$result    = $generator->generateForHeading(
			$heading_hash,
			$heading_text,
			$section_text,
			$post_id,
			$focus_keyword,
			$style,
			$force
		);

		if ( is_wp_error( $result ) ) {
			\SmartImageMatcher\Premium\AiImageGenerator::setStatus(
				$post_id,
				$heading_hash,
				array(
					'status' => 'failed',
					'error'  => $result->get_error_message(),
				)
			);
			Logger::warn(
				'JobRunner: AI image gen failed',
				array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
				)
			);
			return;
		}

		$attachment_id  = (int) $result;
		$attachment_url = (string) wp_get_attachment_url( $attachment_id );
		$prompt         = (string) get_post_meta( $attachment_id, '_sim_generated_prompt', true );

		if ( 'featured' === $heading_hash && ! get_post_meta( $attachment_id, '_sim_generated_vision_failed', true ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		\SmartImageMatcher\Premium\AiImageGenerator::setStatus(
			$post_id,
			$heading_hash,
			array(
				'status'         => 'done',
				'attachment_id'  => $attachment_id,
				'attachment_url' => $attachment_url,
				'prompt_used'    => $prompt,
				'image_html'     => $attachment_url
					? '<img src="' . esc_url( $attachment_url ) . '" alt="" />'
					: '',
				'title'          => get_the_title( $attachment_id ),
				'filename'       => basename( (string) get_attached_file( $attachment_id ) ),
			)
		);

		Logger::info(
			'JobRunner: AI image gen done',
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
			)
		);
	}
}
