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
			'You are a media librarian. Given a post heading and candidate images (filename, title, alt), ' .
			'rank only images whose PRIMARY subject is the same thing as the heading. ' .
			'Compound names are different subjects: "Foxes" is not eastern-fox-squirrel; "Chocolate" is not chocolate-cake unless the heading is about cake. ' .
			'If the filename adds another content noun that changes the subject, omit that image or score it under 70. ' .
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

	/**
	 * System message for featured-image matching (post subject, not a heading).
	 *
	 * @since 3.4.1
	 * @return string
	 */
	public function featuredSystemMessage(): string {
		return
			'You are a media librarian choosing a featured image for an article. ' .
			'The PRIMARY subject is the article (title, slug, focus keyword) — not a single heading. ' .
			'Rank only images whose PRIMARY subject is the same thing as the article. ' .
			'Compound names on the image are different subjects: a post titled "Foxes" is not eastern-fox-squirrel; "Chocolate" is not chocolate-cake unless the article is about cake. ' .
			'Extra words on the ARTICLE (diet, habitat, types, identification, location) are modifiers — still accept a clean image of the same subject (american-goldfinch-winter-diet may use american-goldfinch.jpg). ' .
			'If the filename adds another content noun that changes the subject, omit that image or score it under 70. ' .
			'Respond ONLY with valid JSON — no prose, no markdown, no code fences. ' .
			'Format: {"matches":[{"image_id":integer,"relevance_score":integer,"reasoning":"string","confidence":"high|medium|low"}]}';
	}

	/**
	 * Build the user-turn prompt for ranking candidates as a featured image.
	 *
	 * @since 3.4.1
	 * @param array<string, string>            $article    title, slug, focus_keyword.
	 * @param array<int, array<string, mixed>> $candidates Candidate images.
	 * @param int                              $threshold  Confidence threshold 0-100.
	 * @return string
	 */
	public function buildFeatured( array $article, array $candidates, int $threshold = 70 ): string {
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
			"Article title: \"%s\"\nSlug: \"%s\"\nFocus keyword: \"%s\"\n\n" .
			"Candidate images (rank by relevance as a featured image, include only those with relevance_score >= %d):\n%s\n\n" .
			'Return JSON only.',
			$article['title'] ?? '',
			$article['slug'] ?? '',
			$article['focus_keyword'] ?? '',
			$threshold,
			implode( "\n", $lines )
		);
	}
}
