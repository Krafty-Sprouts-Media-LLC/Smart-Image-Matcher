<?php
/**
 * Premium: Vision-based image content scoring.
 *
 * Wraps the keyword candidate shortlist with a visual content verification
 * pass using ProviderBridge::scoreImageWithVision().
 *
 * Only runs on the top-N candidates from the keyword phase to keep costs low.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Logging\Logger;

/**
 * Class AiVisionMatch
 *
 * @since 3.0.0
 */
class AiVisionMatch {

	/**
	 * Maximum candidates to score with vision (cost control).
	 */
	const MAX_VISION_CANDIDATES = 5;

	/**
	 * Register hooks.
	 *
	 * Vision scoring is invoked directly by AI\Matcher, no hooks needed here.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		// No hooks — invoked from AI\Matcher when Premium::has('ai_vision_match').
	}

	/**
	 * Re-rank a candidate list using visual content scoring.
	 *
	 * @since 3.0.0
	 * @param string                           $headingText  Heading text.
	 * @param array<int, array<string, mixed>> $candidates   Candidates from keyword/text-AI phase.
	 * @return array<int, array<string, mixed>> Re-ranked list.
	 */
	public function rerank( string $headingText, array $candidates ): array {
		// Only run if the user enabled vision matching.
		if ( ! \SmartImageMatcher\Settings\Settings::get( 'ai_vision_match_enabled' ) ) {
			return $candidates;
		}

		if ( ! ProviderBridge::isAvailable() ) {
			return $candidates;
		}

		$toScore = array_slice( $candidates, 0, self::MAX_VISION_CANDIDATES );
		$scored  = array();

		foreach ( $toScore as $candidate ) {
			$url = (string) ( $candidate['image_url'] ?? '' );
			if ( '' === $url ) {
				$scored[] = $candidate;
				continue;
			}

			// Cache per image + heading.
			$cacheKey = 'smart_image_matcher_vision_' . md5( $url . ':' . $headingText );
			$cached   = get_transient( $cacheKey );

			if ( false !== $cached && is_array( $cached ) ) {
				$scored[] = array_merge( $candidate, $cached );
				continue;
			}

			$raw = ProviderBridge::scoreImageWithVision( $url, $headingText );

			if ( is_wp_error( $raw ) || ! is_string( $raw ) ) {
				$scored[] = $candidate;
				continue;
			}

			$data = json_decode( $raw, true );

			if ( JSON_ERROR_NONE !== json_last_error() || ! isset( $data['score'] ) ) {
				$scored[] = $candidate;
				continue;
			}

			$visionScore = (int) $data['score'];
			// Blend: 60% vision, 40% text match.
			$blended = (int) round( $visionScore * 0.6 + $candidate['confidence_score'] * 0.4 );

			$update = array(
				'confidence_score' => min( 100, max( 0, $blended ) ),
				'vision_score'     => $visionScore,
				'ai_reasoning'     => sanitize_text_field( (string) ( $data['reasoning'] ?? '' ) ),
				'match_method'     => 'ai_vision',
			);

			set_transient( $cacheKey, $update, DAY_IN_SECONDS * 30 );

			$scored[] = array_merge( $candidate, $update );
		}

		// Re-sort by blended score.
		usort( $scored, static fn( $a, $b ) => $b['confidence_score'] - $a['confidence_score'] );

		// Append any remaining candidates not scored with vision.
		$remaining = array_slice( $candidates, self::MAX_VISION_CANDIDATES );

		return array_merge( $scored, $remaining );
	}
}
