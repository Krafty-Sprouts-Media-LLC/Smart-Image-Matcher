<?php
/**
 * Translates post/heading context into a visual brief for image generation.
 *
 * Never sends raw SEO keywords straight to an image model — a cheap text
 * model rewrites them into a single visual scene sentence first.
 *
 * @package SmartImageMatcher\AI
 * @since   3.1.1
 */

declare( strict_types=1 );

namespace SmartImageMatcher\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PromptBuilder
 *
 * @since 3.1.1
 */
class PromptBuilder {

	/**
	 * System message for the visual-brief writer.
	 *
	 * @since 3.1.1
	 * @var string
	 */
	private const SYSTEM_BRIEF = 'You are a visual-brief writer. Given a post title, an optional focus keyword, and a short text excerpt, write a single-sentence visual scene description suitable for an AI image generator. Use only visual nouns, colors, composition, lighting, style cues, and mood. Never include questions, pricing data, numbers, statistics, call-to-action phrases, or non-visual concepts. Never output markdown, code fences, or extra prose. Output exactly one sentence ending with a period.';

	/**
	 * System message for subject fitness (yes/no).
	 *
	 * @since 3.1.1
	 * @var string
	 */
	private const SYSTEM_SUBJECT_GATE = 'You are a subject classifier. Given a focus keyword or heading, determine whether an AI image generator can produce a useful image for it. Respond with exactly one word: "yes" or "no". Answer "no" for specific products, branded items, named real people, or text-heavy listicles that require readable text in the image.';

	/**
	 * System message for descriptive alt text.
	 *
	 * @since 3.1.1
	 * @var string
	 */
	private const SYSTEM_ALT = 'Write one short alt-text sentence (max 125 characters) describing this image for screen readers. Be concrete and visual. If a focus keyword is provided, include it naturally once. No quotes, no markdown, no "image of" prefix.';

	/**
	 * Build a visual scene description for an AI image generator.
	 *
	 * @since 3.1.1
	 * @param string $title_or_heading Post title or heading text.
	 * @param string $focus_keyword    SEO focus keyphrase (optional).
	 * @param string $content_excerpt  First paragraphs of the section/post.
	 * @param string $style            Style hint: photo|illustration.
	 * @return string|\WP_Error
	 */
	public function buildImagePrompt(
		string $title_or_heading,
		string $focus_keyword = '',
		string $content_excerpt = '',
		string $style = 'photo'
	) {
		$style_hint = ( 'illustration' === $style )
			? 'Describe a digital illustration or vector artwork.'
			: 'Describe a realistic photograph.';

		$user = sprintf(
			"Style instruction: %s\nTitle/heading: %s\nFocus keyword: %s\nExcerpt: %s",
			$style_hint,
			$title_or_heading,
			( '' !== $focus_keyword ) ? $focus_keyword : '(none)',
			$content_excerpt
		);

		$result = ProviderBridge::generateText( self::SYSTEM_BRIEF, $user, 0.4 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$brief = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $result ) ) );

		if ( '' === $brief ) {
			return new \WP_Error(
				'smart_image_matcher_empty_brief',
				__( 'The text model returned an empty visual brief.', 'smart-image-matcher' )
			);
		}

		if ( '.' !== substr( $brief, -1 ) ) {
			$brief .= '.';
		}

		return $brief;
	}

	/**
	 * Whether the subject is suitable for AI image generation.
	 *
	 * @since 3.1.1
	 * @param string $heading_or_keyword Heading text or focus keyword.
	 * @return bool|\WP_Error True if generatable, false if not, WP_Error on failure.
	 */
	public function isGeneratableSubject( string $heading_or_keyword ) {
		$heading_or_keyword = trim( $heading_or_keyword );
		if ( '' === $heading_or_keyword ) {
			return true;
		}

		$result = ProviderBridge::generateText(
			self::SYSTEM_SUBJECT_GATE,
			'focus_keyword="' . $heading_or_keyword . '"',
			0.0
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$answer = strtolower( trim( wp_strip_all_tags( (string) $result ) ) );
		$answer = preg_replace( '/[^a-z]/', '', $answer );

		return 'no' !== $answer;
	}

	/**
	 * Build a short accessibility alt text from a visual brief.
	 *
	 * @since 3.1.1
	 * @param string $visual_brief  Scene description sent to the image model.
	 * @param string $focus_keyword Optional focus keyword to weave in.
	 * @return string|\WP_Error
	 */
	public function buildDescriptiveAlt( string $visual_brief, string $focus_keyword = '' ) {
		$user = sprintf(
			"Visual brief: %s\nFocus keyword: %s",
			$visual_brief,
			( '' !== $focus_keyword ) ? $focus_keyword : '(none)'
		);

		$result = ProviderBridge::generateText( self::SYSTEM_ALT, $user, 0.2 );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$alt = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $result ) ) );
		if ( strlen( $alt ) > 125 ) {
			$alt = rtrim( substr( $alt, 0, 122 ) ) . '…';
		}

		return ( '' !== $alt ) ? $alt : $visual_brief;
	}

	/**
	 * Try to read the SEO focus keyword for a post from known SEO plugins.
	 *
	 * @since 3.1.1
	 * @param int $post_id Post ID.
	 * @return string Focus keyword or empty string.
	 */
	public static function getFocusKeyword( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}

		$sources = array(
			'rank_math_focus_keyword',
			'_yoast_wpseo_focuskw',
			'_seopress_analysis_target_kw',
			'_tsf_meta_keyword',
		);

		foreach ( $sources as $meta_key ) {
			$kw = get_post_meta( $post_id, $meta_key, true );
			if ( ! empty( $kw ) && is_string( $kw ) ) {
				// Rank Math may store comma-separated phrases — use the first.
				$parts = array_map( 'trim', explode( ',', $kw ) );
				if ( isset( $parts[0] ) && '' !== $parts[0] ) {
					return sanitize_text_field( $parts[0] );
				}
			}
		}

		return '';
	}
}
