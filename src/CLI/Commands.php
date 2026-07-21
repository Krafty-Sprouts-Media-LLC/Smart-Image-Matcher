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
}
