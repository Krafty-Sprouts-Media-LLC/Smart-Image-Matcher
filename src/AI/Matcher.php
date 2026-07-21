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
	 * @param array<string, mixed> $heading   Heading data (text, heading_hash, level, …).
	 * @param ImageRepository      $repo      Image repository.
	 * @param int                  $threshold Confidence threshold 0-100.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function findMatches(
		array $heading,
		ImageRepository $repo,
		int $threshold = 70
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
		$candidates = $repo->findCandidates( $terms, self::MAX_CANDIDATES );

		if ( empty( $candidates ) ) {
			Logger::info( 'AI\Matcher: no keyword candidates, skipping AI', array( 'heading' => $heading['text'] ?? '' ) );
			return array();
		}

		// Step 2: AI re-ranking.
		$prompt = new MatchPrompt();

		$responseText = ProviderBridge::generateText(
			$prompt->systemMessage(),
			$prompt->build( $heading, $candidates, $threshold ),
			0.2
		);

		if ( is_wp_error( $responseText ) ) {
			// AI call failed — log it but return keyword results as fallback.
			Logger::warn( 'AI\Matcher: AI call failed, falling back to keyword', array(
				'error' => $responseText->get_error_message(),
			) );

			// Return keyword results so the user gets something.
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

		// Step 3: parse response, validate IDs, threshold.
		$parser       = new ResultParser();
		$candidateIds = array_column( $candidates, 'id' );
		$aiResults    = $parser->parse( $responseText, $candidateIds, $threshold );

		if ( is_wp_error( $aiResults ) ) {
			Logger::warn( 'AI\Matcher: response parse failed', array( 'error' => $aiResults->get_error_message() ) );
			return array();
		}

		// Merge AI results with full image metadata.
		$metaMap = array_column( $candidates, null, 'id' );

		foreach ( $aiResults as &$result ) {
			$meta = $metaMap[ $result['image_id'] ] ?? array();
			$result['image_url'] = (string) ( $meta['url']      ?? '' );
			$result['filename']  = (string) ( $meta['filename'] ?? '' );
			$result['title']     = (string) ( $meta['title']    ?? '' );
		}
		unset( $result );

		Logger::info( 'AI\Matcher: done', array(
			'heading'    => $heading['text'] ?? '',
			'candidates' => count( $candidates ),
			'results'    => count( $aiResults ),
		) );

		return $aiResults;
	}
}
