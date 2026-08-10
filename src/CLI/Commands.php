<?php
/**
 * WP-CLI commands for Smart Image Matcher.
 *
 * Registered with: WP_CLI::add_command( 'sim', Commands::class );
 *
 * @package SmartImageMatcher\CLI
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\HeadingExtractor;
use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Domain\Matcher;
use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\FeaturedImages\SlugMapBuilder;
use SmartImageMatcher\Queue\Queue;

/**
 * Smart Image Matcher CLI.
 *
 * @since 3.0.0
 */
class Commands {

	/**
	 * Match images for a single post and output results.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID to match.
	 *
	 * [--mode=<mode>]
	 * : Matching mode. Default: keyword.
	 * ---
	 * options:
	 *   - keyword
	 *   - ai
	 * ---
	 *
	 * [--threshold=<threshold>]
	 * : Confidence threshold 0-100. Default: 70.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   wp sim match 42
	 *   wp sim match 42 --mode=keyword --threshold=80 --format=json
	 *
	 * @since 3.0.0
	 * @param string[] $args       Positional args.
	 * @param string[] $assocArgs  Named args.
	 * @return void
	 */
	public function match( array $args, array $assocArgs ): void {
		$postId    = absint( $args[0] ?? 0 );
		$mode      = sanitize_key( $assocArgs['mode']      ?? 'keyword' );
		$threshold = absint( $assocArgs['threshold'] ?? 70 );
		$format    = sanitize_key( $assocArgs['format']    ?? 'table' );

		if ( ! $postId ) {
			\WP_CLI::error( 'Please provide a valid post ID.' );
		}

		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			\WP_CLI::error( "Post {$postId} not found." );
		}

		\WP_CLI::log( "Matching post {$postId}: {$post->post_title}" );

		$extractor = new HeadingExtractor();
		$headings  = $extractor->extract( $post->post_content );

		if ( empty( $headings ) ) {
			\WP_CLI::warning( 'No headings found in this post.' );
			return;
		}

		$matcher  = new Matcher();
		$headings = $matcher->filterByHierarchy( $headings, (string) \SmartImageMatcher\Settings\Settings::get( 'hierarchy_mode' ) );
		$repo     = new ImageRepository();
		$rows     = array();

		foreach ( $headings as $heading ) {
			$terms   = $matcher->extractKeywords( $heading['text'] );
			$images  = $repo->findCandidates( $terms );
			$matches = $matcher->findKeywordMatches( $heading, $images );

			if ( empty( $matches ) ) {
				$rows[] = array(
					'heading' => $heading['tag'] . ': ' . $heading['text'],
					'image'   => '(no match)',
					'score'   => '-',
					'hash'    => $heading['heading_hash'],
				);
				continue;
			}

			foreach ( array_slice( $matches, 0, 1 ) as $m ) {
				$rows[] = array(
					'heading' => $heading['tag'] . ': ' . $heading['text'],
					'image'   => $m['filename'],
					'score'   => $m['confidence_score'] . '%',
					'hash'    => $heading['heading_hash'],
				);
			}
		}

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $rows, JSON_PRETTY_PRINT ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'heading', 'image', 'score', 'hash' ) );
		}
	}

	/**
	 * Run the Featured Image Auto-Assigner for a post type.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<post_type>]
	 * : Post type to process. Default: post.
	 *
	 * [--overwrite]
	 * : Replace existing featured images.
	 *
	 * [--dry-run]
	 * : Show what would be assigned without actually assigning.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sim fiaa
	 *   wp sim fiaa --post-type=page --overwrite
	 *   wp sim fiaa --dry-run
	 *
	 * @since 3.0.0
	 * @param string[] $args       Positional args.
	 * @param string[] $assocArgs  Named args.
	 * @return void
	 */
	public function fiaa( array $args, array $assocArgs ): void {
		$postType  = sanitize_key( $assocArgs['post-type'] ?? 'post' );
		$overwrite = isset( $assocArgs['overwrite'] );
		$dryRun    = isset( $assocArgs['dry-run'] );

		$service = new FeaturedImageService( new SlugMapBuilder() );

		if ( $dryRun ) {
			\WP_CLI::warning( 'Dry run — no changes will be made.' );
		}

		\WP_CLI::log( "Running FIAA for post type '{$postType}'" . ( $overwrite ? ' (overwrite)' : '' ) . ( $dryRun ? ' (dry-run)' : '' ) );

		$results = $dryRun
			? array( 'matched' => array(), 'skipped' => array(), 'unmatched' => array(), 'total' => 0 )
			: $service->run( $postType, $overwrite );

		\WP_CLI::success( sprintf(
			'Done. Total: %d | Matched: %d | Skipped: %d | Unmatched: %d',
			(int) $results['total'],
			count( $results['matched'] ?? array() ),
			count( $results['skipped'] ?? array() ),
			count( $results['unmatched'] ?? array() )
		) );
	}

	/**
	 * Queue a bulk match job via Action Scheduler.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<post_type>]
	 * : Post type to match. Default: post.
	 *
	 * [--mode=<mode>]
	 * : Matching mode. Default: keyword.
	 *
	 * [--threshold=<threshold>]
	 * : Confidence threshold. Default: 70.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sim bulk-match
	 *   wp sim bulk-match --post-type=page --mode=keyword --threshold=80
	 *
	 * @since 3.0.0
	 * @param string[] $args       Positional args.
	 * @param string[] $assocArgs  Named args.
	 * @return void
	 */
	public function bulkMatch( array $args, array $assocArgs ): void {
		$postType  = sanitize_key( $assocArgs['post-type']  ?? 'post' );
		$mode      = sanitize_key( $assocArgs['mode']       ?? 'keyword' );
		$threshold = absint( $assocArgs['threshold'] ?? 70 );

		if ( ! Queue::isAvailable() ) {
			\WP_CLI::error( 'Action Scheduler is not available. Run `composer install` to bundle it.' );
		}

		$postIds = $this->getPostIdsForBulkMatch( $postType );

		if ( empty( $postIds ) ) {
			\WP_CLI::warning( "No posts found for post type '{$postType}'." );
			return;
		}

		$jobId  = 'smart_image_matcher_cli_' . substr( md5( uniqid( '', true ) ), 0, 8 );
		$config = array( 'mode' => $mode, 'min_score' => $threshold, 'post_type' => $postType );
		$queue  = new Queue();
		$queued = 0;

		$progress = \WP_CLI\Utils\make_progress_bar( 'Queueing', count( $postIds ) );

		foreach ( $postIds as $postId ) {
			$queue->enqueueBulkMatchPost( $jobId, (int) $postId, $config );
			$queued++;
			$progress->tick();
		}

		$progress->finish();

		\WP_CLI::success( "Queued {$queued} match jobs. Job ID: {$jobId}" );
		\WP_CLI::log( 'Run `wp action-scheduler run` to process immediately, or wait for WP-Cron.' );
	}

	/**
	 * (Re)build the media library inverted index used by the matcher.
	 *
	 * Runs synchronously in this CLI process rather than via Action
	 * Scheduler, since WP-CLI is not bound by the web server's request
	 * timeout the way an AS async-request or wp-cron execution is. Still
	 * processes in bounded batches and reports progress, so an interrupted
	 * run (Ctrl-C, SSH drop) can be resumed with `wp sim reindex` again —
	 * it picks up from the last saved cursor unless --fresh is passed.
	 *
	 * ## OPTIONS
	 *
	 * [--fresh]
	 * : Ignore any existing progress and reindex the entire library from
	 * the beginning.
	 *
	 * [--batch-size=<size>]
	 * : Attachments indexed per batch. Default: 200.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sim reindex
	 *   wp sim reindex --fresh
	 *   wp sim reindex --batch-size=500
	 *
	 * @since 3.1.0
	 * @param string[] $args       Positional args.
	 * @param string[] $assocArgs  Named args.
	 * @return void
	 */
	public function reindex( array $args, array $assocArgs ): void {
		$repo      = new ImageRepository();
		$batchSize = absint( $assocArgs['batch-size'] ?? 200 );
		$batchSize = $batchSize > 0 ? $batchSize : 200;

		if ( isset( $assocArgs['fresh'] ) ) {
			$repo->resetBackfillState();
			\WP_CLI::log( 'Cleared previous backfill progress — starting from the beginning.' );
		}

		$state  = $repo->getBackfillState();
		$offset = (int) ( $state['offset'] ?? 0 );

		if ( ! empty( $state['done'] ) ) {
			\WP_CLI::success( 'Media library is already fully indexed. Use --fresh to reindex anyway.' );
			return;
		}

		if ( $offset > 0 ) {
			\WP_CLI::log( "Resuming from offset {$offset}." );
		}

		$totalIndexed = 0;

		do {
			$result = $repo->backfillBatch( $offset, $batchSize );

			$totalIndexed += $result['indexed'];
			$offset        = $result['next_offset'];

			$repo->saveBackfillState( array(
				'offset'     => $offset,
				'done'       => $result['done'],
				'updated_at' => current_time( 'mysql' ),
			) );

			\WP_CLI::log( "Indexed batch: {$result['indexed']} images (offset now {$offset})." );
		} while ( ! $result['done'] );

		\WP_CLI::success( "Reindex complete. {$totalIndexed} images processed in this run." );
	}

	/**
	 * Fetch post IDs in bounded batches for the bulk CLI command.
	 *
	 * @since 3.0.0
	 * @param string $postType Post type.
	 * @return int[]
	 */
	private function getPostIdsForBulkMatch( string $postType ): array {
		$postIds = array();
		$page    = 1;
		$perPage = 200;
		$max     = (int) apply_filters( 'smart_image_matcher_bulk_job_max_posts', 5000, $postType ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- smart_image_matcher_ is the project hook prefix.

		do {
			$batch = get_posts( array(
				'post_type'              => $postType,
				'post_status'            => array( 'publish', 'draft' ),
				'posts_per_page'         => $perPage,
				'paged'                  => $page,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			) );

			if ( empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $postId ) {
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
	 * Recover completed fal.ai images that never landed in WordPress.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Recover every post with stored fal pending meta.
	 *
	 * [--discover]
	 * : Query recent successful fal requests and match them to posts automatically.
	 *
	 * [--hours=<n>]
	 * : fal history lookback for --discover. Default: 48.
	 *
	 * [--dry-run]
	 * : Preview automatic request-to-post matches without importing images.
	 *
	 * [--file=<path>]
	 * : Optional fallback CSV: post_id,request_id[,model_id].
	 *
	 * [--request-ids=<list>]
	 * : Comma/space-separated fal request ids (bulk).
	 *
	 * [--post_id=<id>]
	 * : Recover one post (optional with --request_id).
	 *
	 * [--request_id=<uuid>]
	 * : Single fal request id.
	 *
	 * [--model_id=<id>]
	 * : fal model id (default: preferred SIM image model).
	 *
	 * [--match-posts]
	 * : When rows lack post_id, match fal prompt text to posts missing a featured image.
	 *
	 * [--unattached]
	 * : If no post match, still import into the media library (unassigned).
	 *
	 * [--heading_hash=<hash>]
	 * : Heading hash. Default: featured.
	 *
	 * [--min_score=<n>]
	 * : Min title→prompt match score 0–100 when using --match-posts. Default: 60.
	 *
	 * ## EXAMPLES
	 *
	 *   wp sim fal-recover --discover --hours=48 --dry-run
	 *   wp sim fal-recover --discover --hours=48
	 *   wp sim fal-recover --all
	 *   wp sim fal-recover --request-ids="id1,id2,id3" --match-posts --unattached
	 *
	 * @subcommand fal-recover
	 * @since 3.2.21
	 * @param string[]             $args      Unused.
	 * @param array<string,string> $assocArgs Flags.
	 * @return void
	 */
	public function fal_recover( array $args, array $assocArgs ): void { // phpcs:ignore NeutronStandard.Functions.TypeHint.NoArgumentTypeHint,Squiz.Commenting.FunctionComment.ParamNameNoMatch
		$all         = isset( $assocArgs['all'] );
		$discover    = isset( $assocArgs['discover'] );
		$hours       = isset( $assocArgs['hours'] ) ? absint( $assocArgs['hours'] ) : 48;
		$dry_run     = isset( $assocArgs['dry-run'] ) || isset( $assocArgs['dry_run'] );
		$file        = isset( $assocArgs['file'] ) ? (string) $assocArgs['file'] : '';
		$request_ids = isset( $assocArgs['request-ids'] ) ? (string) $assocArgs['request-ids'] : (string) ( $assocArgs['request_ids'] ?? '' );
		$post_id     = absint( $assocArgs['post_id'] ?? 0 );
		$heading_hash = sanitize_text_field( (string) ( $assocArgs['heading_hash'] ?? 'featured' ) );
		$request_id  = sanitize_text_field( (string) ( $assocArgs['request_id'] ?? '' ) );
		$model_id    = sanitize_text_field( (string) ( $assocArgs['model_id'] ?? '' ) );
		$match_posts = isset( $assocArgs['match-posts'] ) || isset( $assocArgs['match_posts'] );
		$unattached  = isset( $assocArgs['unattached'] );
		$min_score   = isset( $assocArgs['min_score'] ) ? absint( $assocArgs['min_score'] ) : 60;

		if ( '' === $model_id ) {
			$model_id = (string) \SmartImageMatcher\Settings\Settings::get( 'ai_image_model' );
		}

		$options = array(
			'heading_hash' => $heading_hash,
			'model_id'     => $model_id,
			'match_posts'  => $match_posts,
			'unattached'   => $unattached,
			'min_score'    => $min_score,
		);

		if ( $all ) {
			$targets = \SmartImageMatcher\Premium\AiImageGenerator::listFalPending( $heading_hash, 500 );
			\WP_CLI::log( sprintf( 'Found %d pending fal job(s) in post meta.', count( $targets ) ) );
			$ok = 0;
			foreach ( $targets as $row ) {
				$result = \SmartImageMatcher\Premium\AiImageGenerator::recoverFalJob(
					(int) $row['post_id'],
					(string) $row['heading_hash'],
					is_array( $row['pending'] ) ? $row['pending'] : array()
				);
				if ( is_wp_error( $result ) ) {
					\WP_CLI::warning( sprintf( 'Post %d: %s', (int) $row['post_id'], $result->get_error_message() ) );
					continue;
				}
				++$ok;
				\WP_CLI::log( sprintf( 'Post %d → attachment %d', (int) $row['post_id'], (int) $result ) );
			}
			\WP_CLI::success( sprintf( 'Recovered %d of %d.', $ok, count( $targets ) ) );
			return;
		}

		$rows = array();

		if ( $discover ) {
			$found = \SmartImageMatcher\Premium\FalRecoverBatch::discoverRecentRows( $hours, 500 );
			if ( is_wp_error( $found ) ) {
				\WP_CLI::error( $found->get_error_message() );
			}
			$rows                   = $found;
			$options['match_posts'] = true;
			\WP_CLI::log( sprintf( 'Discovered %d successful fal image request(s) from the last %d hour(s).', count( $rows ), $hours ) );
			if ( $dry_run ) {
				$preview = \SmartImageMatcher\Premium\FalRecoverBatch::previewMatches( $rows, $min_score );
				foreach ( $preview['matched'] as $row ) {
					\WP_CLI::log(
						sprintf(
							'MATCH request %s → post %d (%s)',
							$row['request_id'],
							(int) $row['post_id'],
							$row['post_title']
						)
					);
				}
				foreach ( $preview['unmatched'] as $row ) {
					\WP_CLI::warning( sprintf( 'UNMATCHED request %s: %s', $row['request_id'], $row['prompt'] ) );
				}
				\WP_CLI::success(
					sprintf(
						'Preview only: %d matched, %d unmatched. No images imported.',
						count( $preview['matched'] ),
						count( $preview['unmatched'] )
					)
				);
				return;
			}
		} elseif ( '' !== $file ) {
			$parsed = \SmartImageMatcher\Premium\FalRecoverBatch::parseCsvFile( $file );
			if ( is_wp_error( $parsed ) ) {
				\WP_CLI::error( $parsed->get_error_message() );
			}
			$rows = $parsed;
			\WP_CLI::log( sprintf( 'Loaded %d row(s) from file.', count( $rows ) ) );
		} elseif ( '' !== $request_ids ) {
			foreach ( \SmartImageMatcher\Premium\FalRecoverBatch::parseRequestIdList( $request_ids ) as $rid ) {
				$rows[] = array(
					'post_id'    => 0,
					'request_id' => $rid,
					'model_id'   => $model_id,
				);
			}
			\WP_CLI::log( sprintf( 'Loaded %d request id(s).', count( $rows ) ) );
		} elseif ( $post_id > 0 ) {
			if ( '' === $request_id ) {
				$pending = \SmartImageMatcher\Premium\AiImageGenerator::getFalPending( $post_id, $heading_hash );
				if ( is_array( $pending ) ) {
					$result = \SmartImageMatcher\Premium\AiImageGenerator::recoverFalJob( $post_id, $heading_hash, $pending );
					if ( is_wp_error( $result ) ) {
						\WP_CLI::error( $result->get_error_message() );
					}
					\WP_CLI::success( sprintf( 'Post %d → attachment %d', $post_id, (int) $result ) );
					return;
				}
				\WP_CLI::error( 'No pending meta. Pass --request_id or --file / --request-ids.' );
			}
			$rows[] = array(
				'post_id'    => $post_id,
				'request_id' => $request_id,
				'model_id'   => $model_id,
			);
		} else {
			\WP_CLI::error( 'Pass --discover, --all, --post_id, --request-ids, or --file.' );
		}

		if ( empty( $options['match_posts'] ) && ! $unattached ) {
			foreach ( $rows as $row ) {
				if ( empty( $row['post_id'] ) ) {
					\WP_CLI::error( 'CSV/request list has rows without post_id. Add --match-posts and/or --unattached.' );
				}
			}
		}

		$result = \SmartImageMatcher\Premium\FalRecoverBatch::run( $rows, $options );
		foreach ( $result['recovered'] as $row ) {
			\WP_CLI::log(
				sprintf(
					'OK request %s → post %s attachment %d%s',
					$row['request_id'],
					(string) ( $row['post_id'] ?: '—' ),
					(int) $row['attachment_id'],
					! empty( $row['unattached'] ) ? ' (unattached)' : ''
				)
			);
		}
		foreach ( $result['failed'] as $row ) {
			\WP_CLI::warning(
				sprintf(
					'FAIL request %s (post %s): %s',
					$row['request_id'] ?? '',
					(string) ( $row['post_id'] ?? '—' ),
					$row['error'] ?? ''
				)
			);
		}

		\WP_CLI::success(
			sprintf(
				'Recovered %d, failed %d, skipped %d.',
				count( $result['recovered'] ),
				count( $result['failed'] ),
				(int) $result['skipped']
			)
		);
	}
}
