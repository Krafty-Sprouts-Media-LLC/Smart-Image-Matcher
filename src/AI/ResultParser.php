<?php
/**
 * Parses the JSON-only AI response into structured match arrays.
 *
 * @package SmartImageMatcher\AI
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Logging\Logger;

/**
 * Class ResultParser
 *
 * @since 3.0.0
 */
class ResultParser {

	/**
	 * Parse raw AI response text into an array of match rows.
	 *
	 * @since 3.0.0
	 * @param string  $responseText Raw text from the AI provider.
	 * @param int[]   $candidateIds Valid image IDs we sent (whitelist for validation).
	 * @param int     $threshold    Minimum relevance_score to include.
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function parse( string $responseText, array $candidateIds, int $threshold = 70 ) {
		// Strip markdown code fences if the model ignored the "no fences" instruction.
		$clean = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', trim( $responseText ) );

		$data = json_decode( $clean, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			Logger::warn( 'ResultParser: JSON decode failed', array(
				'error'    => json_last_error_msg(),
				'response' => substr( $responseText, 0, 200 ),
			) );
			return new \WP_Error( 'smart_image_matcher_ai_parse_error', __( 'Failed to parse AI response.', 'smart-image-matcher' ) );
		}

		if ( ! isset( $data['matches'] ) || ! is_array( $data['matches'] ) ) {
			return new \WP_Error( 'smart_image_matcher_ai_parse_error', __( 'AI response missing "matches" key.', 'smart-image-matcher' ) );
		}

		$results = array();

		foreach ( $data['matches'] as $match ) {
			$imageId = (int) ( $match['image_id'] ?? 0 );
			$score   = (int) ( $match['relevance_score'] ?? 0 );

			// Only accept IDs we actually sent, and only above threshold.
			if ( ! in_array( $imageId, $candidateIds, true ) || $score < $threshold ) {
				continue;
			}

			$results[] = array(
				'image_id'         => $imageId,
				'confidence_score' => min( 100, max( 0, $score ) ),
				'match_method'     => 'ai',
				'ai_reasoning'     => isset( $match['reasoning'] ) ? sanitize_text_field( (string) $match['reasoning'] ) : '',
				'confidence'       => in_array( $match['confidence'] ?? '', array( 'high', 'medium', 'low' ), true )
					? $match['confidence']
					: 'medium',
			);
		}

		// Sort by relevance descending.
		usort( $results, static fn( $a, $b ) => $b['confidence_score'] - $a['confidence_score'] );

		return $results;
	}
}
