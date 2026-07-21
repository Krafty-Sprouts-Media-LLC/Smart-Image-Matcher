<?php
/**
 * Builds the AI prompt for heading-to-image matching.
 *
 * Keeps prompt construction separate from the transport layer so
 * it can be tested independently and updated without touching AI logic.
 *
 * @package SmartImageMatcher\AI
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MatchPrompt
 *
 * @since 3.0.0
 */
class MatchPrompt {

	/**
	 * System message for matching prompts.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	public function systemMessage(): string {
		return
			'You are a media librarian. Given a post heading and a list of candidate images ' .
			'(with metadata), rank the images by how well each one would illustrate the heading topic. ' .
			'Respond ONLY with valid JSON — no prose, no markdown, no code fences. ' .
			'Format: {"matches":[{"image_id":integer,"relevance_score":integer,"reasoning":"string","confidence":"high|medium|low"}]}';
	}

	/**
	 * Build the user-turn prompt for ranking candidates against a heading.
	 *
	 * @since 3.0.0
	 * @param array<string, mixed>             $heading    Heading data (text, level).
	 * @param array<int, array<string, mixed>> $candidates Candidate images from the keyword phase.
	 * @param int                              $threshold  Confidence threshold 0-100.
	 * @return string
	 */
	public function build( array $heading, array $candidates, int $threshold = 70 ): string {
		$lines = array();

		foreach ( $candidates as $img ) {
			$lines[] = sprintf(
				'ID:%d | Filename:%s | Title:%s | Alt:%s',
				(int) ( $img['id'] ?? 0 ),
				$img['filename'] ?? '',
				$img['title']    ?? '',
				$img['alt']      ?? ''
			);
		}

		return sprintf(
			"Heading: \"%s\"\n\n" .
			"Candidate images (rank by relevance, include only those with relevance_score >= %d):\n%s\n\n" .
			'Return JSON only.',
			$heading['text'] ?? '',
			$threshold,
			implode( "\n", $lines )
		);
	}
}
