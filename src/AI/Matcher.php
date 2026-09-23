<?php
/**
 * AI-powered image matching (Premium).
 *
 * Pipeline:
 *   1. Keyword phase — get top-N candidates cheaply via ImageRepository.
 *   2. AI phase      — re-rank candidates via ProviderBridge (runs as background job).
 *
 * This class is called from JobRunner::runAiMatchJob().
 * The modal never calls it synchronously.
 *
 * @package SmartImageMatcher\AI
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Domain\Matcher as KeywordMatcher;
use SmartImageMatcher\Domain\RelevanceGuard;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Settings\Settings;

/**
 * Class Matcher
 *
 * @since 3.0.0
 */
class Matcher {

	/**
	 * Maximum candidates to send to the AI (cost / latency control).
	 */
	const MAX_CANDIDATES = 10;

	/**
	 * Find AI-ranked matches for a single heading.
	 *
	 * Returns WP_Error if AI is unavailable — the caller (JobRunner) falls
	 * back to keyword matches silently and surfaces the fallback to the user.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed> $heading          Heading data (text, heading_hash, level, …).
	 * @param ImageRepository      $repo             Image repository.
	 * @param int                  $threshold        Confidence threshold 0-100.
	 * @param bool                 $keyword_fallback When false, AI failure returns WP_Error (no keyword insert).
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function findMatches(
		array $heading,
		ImageRepository $repo,
		int $threshold = 70,
		bool $keyword_fallback = true
	) {
		if ( ! ProviderBridge::isAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_ai_unavailable',
				__( 'No AI provider configured. Falling back to keyword mode.', 'smart-image-matcher' )
			);
		}

		// Step 1: fast keyword-based candidate list.
		$kwMatcher = new KeywordMatcher();
		$terms     = $kwMatcher->extractKeywords( $heading['text'] ?? '' );
		$candidates = RelevanceGuard::filter(
			$repo->findCandidates( $terms, self::MAX_CANDIDATES * 3 ),
			(string) ( $heading['text'] ?? '' ),
			(string) ( $heading['context'] ?? '' )
		);
		$candidates = array_slice( $candidates, 0, self::MAX_CANDIDATES );

		if ( empty( $candidates ) ) {
			Logger::info( 'AI\Matcher: no keyword candidates, skipping AI', array( 'heading' => $heading['text'] ?? '' ) );
			return array();
		}

		// Step 2: AI re-ranking.
		$prompt = new MatchPrompt();

		$responseText = ProviderBridge::generateText(
			$prompt->systemMessage(),
			$prompt->build( $heading, $candidates, $threshold )
		);

		return $this->finishRanking(
			$responseText,
			$candidates,
			$terms,
			$kwMatcher,
			$threshold,
			$keyword_fallback,
			array( 'heading' => $heading['text'] ?? '' )
		);
	}

	/**
	 * Find AI-ranked featured-image matches for a post.
	 *
	 * Title, slug, and focus keyword build the candidate shortlist. The model
	 * score decides assign / review. When $keyword_fallback is false, failure
	 * returns WP_Error (no slug auto-assign).
	 *
	 * @since 3.4.1
	 * @param \WP_Post        $post             Post.
	 * @param ImageRepository $repo             Image repository.
	 * @param int             $threshold        Confidence threshold 0-100.
	 * @param bool            $keyword_fallback When false, AI failure returns WP_Error.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function findFeaturedMatches(
		\WP_Post $post,
		ImageRepository $repo,
		int $threshold = 70,
		bool $keyword_fallback = true
	) {
		if ( ! ProviderBridge::isAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_ai_unavailable',
				__( 'No AI provider configured. Falling back to keyword mode.', 'smart-image-matcher' )
			);
		}

		$kwMatcher = new KeywordMatcher();
		$focus     = PromptBuilder::getFocusKeyword( (int) $post->ID );
		$blob      = trim(
			implode(
				' ',
				array_filter(
					array(
						(string) $post->post_title,
						str_replace( '-', ' ', (string) $post->post_name ),
						$focus,
					)
				)
			)
		);
		$terms      = $kwMatcher->extractKeywords( $blob );
		$candidates = RelevanceGuard::filter(
			$repo->findCandidates( $terms, self::MAX_CANDIDATES * 3 ),
			(string) $post->post_title,
			$focus
		);
		$candidates = array_slice( $candidates, 0, self::MAX_CANDIDATES );

		if ( empty( $candidates ) ) {
			Logger::info(
				'AI\Matcher: no featured keyword candidates, skipping AI',
				array( 'post_id' => (int) $post->ID )
			);
			return array();
		}

		$prompt = new MatchPrompt();
		$article = array(
			'title'          => (string) $post->post_title,
			'slug'           => (string) $post->post_name,
			'focus_keyword'  => $focus,
		);

		$responseText = ProviderBridge::generateText(
			$prompt->featuredSystemMessage(),
			$prompt->buildFeatured( $article, $candidates, $threshold )
		);

		return $this->finishRanking(
			$responseText,
			$candidates,
			$terms,
			$kwMatcher,
			$threshold,
			$keyword_fallback,
			array( 'post_id' => (int) $post->ID )
		);
	}

	/**
	 * Parse an AI ranking or fall back to keyword scores.
	 *
	 * @param string|\WP_Error                 $responseText     Model output or error.
	 * @param array<int, array<string, mixed>> $candidates       Shortlist.
	 * @param string[]                         $terms            Keyword terms.
	 * @param KeywordMatcher                   $kwMatcher        Keyword matcher.
	 * @param int                              $threshold        Score floor.
	 * @param bool                             $keyword_fallback Whether to return keyword rows on AI failure.
	 * @param array<string, mixed>             $log_context      Extra log fields.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private function finishRanking(
		$responseText,
		array $candidates,
		array $terms,
		KeywordMatcher $kwMatcher,
		int $threshold,
		bool $keyword_fallback,
		array $log_context
	) {
		if ( is_wp_error( $responseText ) ) {
			if ( ! $keyword_fallback ) {
				return $responseText;
			}

			Logger::warn(
				'AI\Matcher: AI call failed, falling back to keyword',
				array_merge( $log_context, array( 'error' => $responseText->get_error_message() ) )
			);

			$kwResults = array();
			foreach ( $candidates as $img ) {
				$score = $kwMatcher->calculateScore( $terms, $img );
				if ( $score >= $threshold ) {
					$kwResults[] = array(
						'image_id'         => (int) $img['id'],
						'confidence_score' => $score,
						'match_method'     => 'keyword_fallback',
						'image_url'        => (string) ( $img['url'] ?? '' ),
						'filename'         => (string) ( $img['filename'] ?? '' ),
						'title'            => (string) ( $img['title'] ?? '' ),
					);
				}
			}
			return $kwResults;
		}

		$parser       = new ResultParser();
		$candidateIds = array_column( $candidates, 'id' );
		$aiResults    = $parser->parse( (string) $responseText, $candidateIds, $threshold );

		if ( is_wp_error( $aiResults ) ) {
			Logger::warn(
				'AI\Matcher: response parse failed',
				array_merge( $log_context, array( 'error' => $aiResults->get_error_message() ) )
			);
			return array();
		}

		$metaMap = array_column( $candidates, null, 'id' );

		foreach ( $aiResults as &$result ) {
			$meta = $metaMap[ $result['image_id'] ] ?? array();
			$result['image_url'] = (string) ( $meta['url'] ?? '' );
			$result['filename']  = (string) ( $meta['filename'] ?? '' );
			$result['title']     = (string) ( $meta['title'] ?? '' );
		}
		unset( $result );

		Logger::info(
			'AI\Matcher: done',
			array_merge(
				$log_context,
				array(
					'candidates' => count( $candidates ),
					'results'    => count( $aiResults ),
				)
			)
		);

		return $aiResults;
	}
}
