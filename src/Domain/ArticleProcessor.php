<?php
/**
 * Per-article match / review / generate / skip processor.
 *
 * One WordPress post per call: featured slot (if needed) then headings.
 * Library hits at or above auto-insert are written now. The middle band
 * is parked as pending. Generation runs only when the library would skip
 * and a GenerationFallback adapter is available.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.3.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\FeaturedImages\FeaturedImageService;
use SmartImageMatcher\Insertion\InsertionService;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Settings\Settings;

/**
 * Class ArticleProcessor
 *
 * @since 3.3.0
 */
class ArticleProcessor {

	/**
	 * Keyword matcher.
	 *
	 * @var Matcher
	 */
	private Matcher $matcher;

	/**
	 * Media candidate lookup.
	 *
	 * @var ImageRepository
	 */
	private ImageRepository $images;

	/**
	 * Heading extractor.
	 *
	 * @var HeadingExtractor
	 */
	private HeadingExtractor $extractor;

	/**
	 * Block/HTML insertion.
	 *
	 * @var InsertionService
	 */
	private InsertionService $insertion;

	/**
	 * Match audit log.
	 *
	 * @var MatchRepository
	 */
	private MatchRepository $matches;

	/**
	 * Featured-image slug scoring.
	 *
	 * @var FeaturedImageService
	 */
	private FeaturedImageService $featured;

	/**
	 * Optional skip-band generation adapter.
	 *
	 * @var GenerationFallback|null
	 */
	private ?GenerationFallback $generation;

	/**
	 * Optional AI heading matcher (takes over when a text provider is connected).
	 *
	 * @var HeadingMatchGate|null
	 */
	private ?HeadingMatchGate $heading_match;

	/**
	 * Optional AI featured matcher (takes over when a text provider is connected).
	 *
	 * @var FeaturedMatchGate|null
	 */
	private ?FeaturedMatchGate $featured_match;

	/**
	 * Constructor.
	 *
	 * @since 3.3.0
	 * @param Matcher                 $matcher    Keyword matcher.
	 * @param ImageRepository         $images     Candidate lookup.
	 * @param HeadingExtractor        $extractor  Heading extractor.
	 * @param InsertionService        $insertion  Insertion service.
	 * @param MatchRepository         $matches    Match persistence.
	 * @param FeaturedImageService    $featured   Featured-image service.
	 * @param GenerationFallback|null $generation    Optional generation adapter.
	 * @param HeadingMatchGate|null   $heading_match  Optional AI heading matcher.
	 * @param FeaturedMatchGate|null  $featured_match Optional AI featured matcher.
	 */
	public function __construct(
		Matcher $matcher,
		ImageRepository $images,
		HeadingExtractor $extractor,
		InsertionService $insertion,
		MatchRepository $matches,
		FeaturedImageService $featured,
		?GenerationFallback $generation = null,
		?HeadingMatchGate $heading_match = null,
		?FeaturedMatchGate $featured_match = null
	) {
		$this->matcher        = $matcher;
		$this->images         = $images;
		$this->extractor      = $extractor;
		$this->insertion      = $insertion;
		$this->matches        = $matches;
		$this->featured       = $featured;
		$this->generation     = $generation;
		$this->heading_match  = $heading_match;
		$this->featured_match = $featured_match;
	}

	/**
	 * Process one post: featured slot then headings.
	 *
	 * @since 3.3.0
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $options {
	 *     @type bool     $overwrite_featured Replace an existing featured image.
	 *     @type int|null $review_min         Optional raised review floor for this job.
	 * }
	 * @return array{inserted:int,review:int,generated:int,skipped:int}
	 */
	public function process( int $post_id, array $options = array() ): array {
		$counts = array(
			'inserted'  => 0,
			'review'    => 0,
			'generated' => 0,
			'skipped'   => 0,
		);

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			Logger::warn( 'ArticleProcessor: post not found', array( 'post_id' => $post_id ) );
			return $counts;
		}

		$auto       = (int) Settings::get( 'auto_insert_threshold' );
		$review_min = (int) Settings::get( 'confidence_threshold' );
		if ( isset( $options['review_min'] ) && is_numeric( $options['review_min'] ) ) {
			$job_floor = (int) $options['review_min'];
			if ( $job_floor > $review_min ) {
				$review_min = $job_floor;
			}
		}

		$overwrite    = ! empty( $options['overwrite_featured'] );
		$can_generate = null !== $this->generation && $this->generation->isAvailable();

		$this->processFeatured( $post, $counts, $auto, $review_min, $can_generate, $overwrite );
		$this->processHeadings( $post, $counts, $auto, $review_min, $can_generate );

		Logger::info(
			'ArticleProcessor: finished',
			array(
				'post_id'   => $post_id,
				'inserted'  => $counts['inserted'],
				'review'    => $counts['review'],
				'generated' => $counts['generated'],
				'skipped'   => $counts['skipped'],
			)
		);

		return $counts;
	}

	/**
	 * Decide and apply the featured-image slot.
	 *
	 * @param \WP_Post             $post         Post.
	 * @param array<string, int>   $counts       Running counts (by ref).
	 * @param int                  $auto         Auto-insert threshold.
	 * @param int                  $review_min   Review floor.
	 * @param bool                 $can_generate Whether generation is available.
	 * @param bool                 $overwrite    Replace existing featured image.
	 * @return void
	 */
	private function processFeatured( \WP_Post $post, array &$counts, int $auto, int $review_min, bool $can_generate, bool $overwrite ): void {
		if ( ! $this->featured->needsFeaturedImage( (int) $post->ID, $overwrite ) ) {
			$this->matches->clearPendingForHeading( (int) $post->ID, 'featured' );
			return;
		}

		$use_ai = null !== $this->featured_match && $this->featured_match->isAvailable();
		if ( $use_ai ) {
			$best  = $this->featured_match->bestMatch( $post );
			$score = (int) ( $best['score'] ?? 0 );
			$image = (int) ( $best['image_id'] ?? 0 );
		} else {
			$best  = $this->featured->scoreBestForPost( (int) $post->ID );
			$score = (int) ( $best['score'] ?? 0 );
			$image = (int) ( $best['attachment_id'] ?? 0 );
		}
		$action = MatchDecision::decide( $score, $auto, $review_min, $can_generate );

		// Only exact / prefix slug matches go straight onto the post.
		if ( MatchDecision::INSERT === $action && ! $this->featured->isAutoAssignSafeAttachment( (int) $post->ID, $image ) ) {
			$action = MatchDecision::REVIEW;
		}

		$this->applyOutcome(
			$post,
			$action,
			'featured',
			(string) $post->post_title,
			'featured',
			$image,
			$score,
			$this->featuredSectionText( $post ),
			$counts,
			true,
			$use_ai ? 'ai' : 'slug'
		);
	}

	/**
	 * Decide and apply each heading that still needs an image.
	 *
	 * @param \WP_Post           $post         Post.
	 * @param array<string, int> $counts       Running counts (by ref).
	 * @param int                $auto         Auto-insert threshold.
	 * @param int                $review_min   Review floor.
	 * @param bool               $can_generate Whether generation is available.
	 * @return void
	 */
	private function processHeadings( \WP_Post $post, array &$counts, int $auto, int $review_min, bool $can_generate ): void {
		$headings = $this->extractor->extract( (string) $post->post_content );
		if ( empty( $headings ) ) {
			return;
		}

		$hierarchy = (string) Settings::get( 'hierarchy_mode' );
		$headings  = $this->matcher->filterByHierarchy( $headings, $hierarchy );

		$insertions = array();
		$use_ai     = null !== $this->heading_match && $this->heading_match->isAvailable();
		$used_ids   = $this->insertion->attachmentIdsInContent( (int) $post->ID );

		foreach ( $headings as $heading ) {
			$hash = (string) ( $heading['heading_hash'] ?? '' );
			if ( '' === $hash ) {
				++$counts['skipped'];
				continue;
			}

			if ( $this->insertion->headingHasFollowingImage( (int) $post->ID, $hash ) ) {
				$this->matches->clearPendingForHeading( (int) $post->ID, $hash );
				++$counts['skipped'];
				continue;
			}

			$heading['context'] = (string) $post->post_title;

			$best   = $this->bestHeadingMatch( $heading, $used_ids );
			$score  = (int) $best['score'];
			$image  = (int) $best['image_id'];
			$action = MatchDecision::decide( $score, $auto, $review_min, $can_generate );
			$text   = (string) ( $heading['text'] ?? '' );
			$tag    = (string) ( $heading['tag'] ?? 'h2' );

			// A model score alone never auto-inserts: the filename/title/alt
			// must carry most of the heading's keywords too.
			if ( MatchDecision::INSERT === $action && $use_ai && $this->keywordScore( $heading, $image ) < $review_min ) {
				$action = MatchDecision::REVIEW;
			}

			if ( MatchDecision::INSERT === $action && $image > 0 ) {
				$used_ids[ $image ] = $image;
				$insertions[]       = array(
					'heading_hash' => $hash,
					'image_id'     => $image,
					'heading_text' => $text,
					'heading_tag'  => $tag,
					'score'        => $score,
				);
				continue;
			}

			if ( MatchDecision::REVIEW === $action && $image > 0 ) {
				$used_ids[ $image ] = $image;
			}

			$this->applyOutcome(
				$post,
				$action,
				$hash,
				$text,
				$tag,
				$image,
				$score,
				$this->headingSectionText( $post, $heading ),
				$counts,
				false,
				$use_ai ? 'ai' : 'keyword'
			);
		}

		if ( empty( $insertions ) ) {
			return;
		}

		$rows = array();
		foreach ( $insertions as $item ) {
			$rows[] = array(
				'heading_hash' => $item['heading_hash'],
				'image_id'     => $item['image_id'],
			);
		}

		$result = $this->insertion->bulkInsert( (int) $post->ID, $rows );
		if ( is_wp_error( $result ) ) {
			Logger::warn(
				'ArticleProcessor: bulk insert failed; parking as review',
				array(
					'post_id' => (int) $post->ID,
					'error'   => $result->get_error_message(),
				)
			);
			foreach ( $insertions as $item ) {
				$this->matches->upsertPending(
					(int) $post->ID,
					$item['heading_hash'],
					$item['heading_text'],
					$item['heading_tag'],
					$item['image_id'],
					$item['score'],
					$use_ai ? 'ai' : 'keyword'
				);
				++$counts['review'];
			}
			return;
		}

		$counts['inserted'] += count( $insertions );
		foreach ( $insertions as $item ) {
			$this->matches->markInserted(
				(int) $post->ID,
				(int) $item['image_id'],
				(string) $item['heading_hash']
			);
		}
	}

	/**
	 * Apply a non-insert outcome (or featured insert).
	 *
	 * @param \WP_Post           $post         Post.
	 * @param string             $action       MatchDecision outcome.
	 * @param string             $heading_hash Heading hash or "featured".
	 * @param string             $heading_text Heading or title.
	 * @param string             $heading_tag  Heading tag or "featured".
	 * @param int                $image_id     Best library attachment ID.
	 * @param int                $score        Best library score.
	 * @param string             $section_text Section excerpt for generation.
	 * @param array<string, int> $counts       Running counts (by ref).
	 * @param bool               $is_featured  Whether this is the featured slot.
	 * @param string             $match_method Optional match method for pending rows.
	 * @return void
	 */
	private function applyOutcome(
		\WP_Post $post,
		string $action,
		string $heading_hash,
		string $heading_text,
		string $heading_tag,
		int $image_id,
		int $score,
		string $section_text,
		array &$counts,
		bool $is_featured,
		string $match_method = ''
	): void {
		if ( '' === $match_method ) {
			$match_method = $is_featured ? 'slug' : 'keyword';
		}

		if ( MatchDecision::INSERT === $action && $is_featured && $image_id > 0 ) {
			set_post_thumbnail( (int) $post->ID, $image_id );
			$this->matches->markInserted( (int) $post->ID, $image_id, $heading_hash );
			++$counts['inserted'];
			return;
		}

		if ( MatchDecision::REVIEW === $action && $image_id > 0 ) {
			$this->matches->upsertPending(
				(int) $post->ID,
				$heading_hash,
				$heading_text,
				$heading_tag,
				$image_id,
				$score,
				$match_method
			);
			++$counts['review'];
			return;
		}

		// Not a review outcome any more: drop the stale Review row from an earlier run.
		$this->matches->clearPendingForHeading( (int) $post->ID, $heading_hash );

		if ( MatchDecision::GENERATE === $action ) {
			$ok = null !== $this->generation
				&& $this->generation->enqueue( (int) $post->ID, $heading_hash, $heading_text, $section_text );
			if ( $ok ) {
				++$counts['generated'];
				return;
			}
		}

		++$counts['skipped'];
	}

	/**
	 * Best keyword score for a heading without applying the review floor.
	 *
	 * @param array<string, mixed> $heading     Heading descriptor.
	 * @param array<int, int>      $exclude_ids Attachment IDs already used in this article.
	 * @return array{score:int,image_id:int}
	 */
	private function bestHeadingMatch( array $heading, array $exclude_ids = array() ): array {
		$skip = array();
		foreach ( $exclude_ids as $exclude_id ) {
			$id = (int) $exclude_id;
			if ( $id > 0 ) {
				$skip[ $id ] = true;
			}
		}

		if ( null !== $this->heading_match && $this->heading_match->isAvailable() ) {
			return $this->heading_match->bestMatch( $heading, array_keys( $skip ) );
		}

		$terms      = $this->matcher->extractKeywords( (string) ( $heading['text'] ?? '' ) );
		$candidates = RelevanceGuard::filter(
			$this->images->findCandidates( $terms ),
			(string) ( $heading['text'] ?? '' ),
			(string) ( $heading['context'] ?? '' )
		);
		$best_score = 0;
		$best_id    = 0;

		foreach ( $candidates as $image ) {
			$id = (int) ( $image['id'] ?? 0 );
			if ( $id > 0 && isset( $skip[ $id ] ) ) {
				continue;
			}
			$score = $this->matcher->calculateScore( $terms, $image );
			if ( $score > $best_score ) {
				$best_score = $score;
				$best_id    = $id;
			}
		}

		return array(
			'score'    => $best_score,
			'image_id' => $best_id,
		);
	}

	/**
	 * Keyword score of one specific image against a heading.
	 *
	 * @param array<string, mixed> $heading  Heading descriptor.
	 * @param int                  $image_id Attachment ID.
	 * @return int 0 when the image cannot be loaded.
	 */
	private function keywordScore( array $heading, int $image_id ): int {
		$meta = $this->images->metadataFor( $image_id );
		if ( null === $meta ) {
			return 0;
		}
		$terms = $this->matcher->extractKeywords( (string) ( $heading['text'] ?? '' ) );
		return $this->matcher->calculateScore( $terms, $meta );
	}

	/**
	 * Section excerpt for featured generation.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function featuredSectionText( \WP_Post $post ): string {
		$excerpt = trim( (string) $post->post_excerpt );
		if ( '' !== $excerpt ) {
			return $excerpt;
		}
		return $this->trimWords( wp_strip_all_tags( (string) $post->post_content ), 80 );
	}

	/**
	 * Section excerpt after a heading.
	 *
	 * @param \WP_Post             $post    Post.
	 * @param array<string, mixed> $heading Heading descriptor.
	 * @return string
	 */
	private function headingSectionText( \WP_Post $post, array $heading ): string {
		$plain  = wp_strip_all_tags( (string) $post->post_content );
		$needle = (string) ( $heading['text'] ?? '' );
		if ( '' === $needle ) {
			return $this->trimWords( $plain, 80 );
		}
		$pos = stripos( $plain, $needle );
		if ( false === $pos ) {
			return $this->trimWords( $plain, 80 );
		}
		$after = substr( $plain, $pos + strlen( $needle ) );
		return $this->trimWords( $after, 80 );
	}

	/**
	 * Trim to a word budget without requiring wp_trim_words in unit tests.
	 *
	 * @param string $text  Source text.
	 * @param int    $words Word budget.
	 * @return string
	 */
	private function trimWords( string $text, int $words ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );
		if ( function_exists( 'wp_trim_words' ) ) {
			return wp_trim_words( $text, $words );
		}
		$parts = preg_split( '/\s+/', $text, $words + 1 ) ?: array();
		if ( count( $parts ) > $words ) {
			array_pop( $parts );
		}
		return implode( ' ', $parts );
	}
}
