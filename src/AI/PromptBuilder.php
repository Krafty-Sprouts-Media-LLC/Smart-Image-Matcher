<?php
/**
 * Translates post/heading context into a visual brief for image generation.
 *
 * Never sends raw SEO keywords straight to an image model — a cheap text
 * model rewrites them into a single visual scene sentence first, then a
 * style-specific quality suffix is appended for the image provider.
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
	 * Max words of post/section context sent to the brief writer.
	 *
	 * @since 3.2.12
	 * @var int
	 */
	public const CONTEXT_WORD_LIMIT = 160;

	/**
	 * System message for the visual-brief writer.
	 *
	 * @since 3.1.1
	 * @var string
	 */
	private const SYSTEM_BRIEF = 'You are a visual-brief writer for AI image generators. Given a post title or heading, optional focus keyword, optional taxonomy labels, a short excerpt, a style instruction, and a purpose (featured hero vs in-article section), write exactly one concrete visual scene sentence. Name a clear primary subject, a setting or environment, lighting, and composition or camera angle. Prefer specific, photographable (or illustratable) details drawn from the excerpt over abstract SEO phrasing. Use only visual nouns, colors, materials, lighting, style cues, and mood. Never include questions, pricing, numbers, statistics, call-to-action phrases, brand slogans, readable text in the scene, watermarks, logos, UI chrome, or non-visual concepts. Never invent famous real people or trademarked products. Never output markdown, code fences, lists, or extra prose. Output exactly one sentence ending with a period.';

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
	 * Photo realism suffix appended to the fal prompt (not the brief alone).
	 *
	 * @since 3.2.12
	 * @var string
	 */
	private const PHOTO_SUFFIX = ' Photorealistic photograph, natural lighting, sharp focus on the subject, shallow depth of field when appropriate, no text, no watermark, no logo, no UI.';

	/**
	 * Extra cue for featured images (API aspect ratio is the hard lock).
	 *
	 * @since 3.2.15
	 * @var string
	 */
	private const FEATURED_LANDSCAPE_SUFFIX = ' Horizontal landscape 16:9 composition, wide editorial frame, not portrait, not square.';

	/**
	 * Illustration quality suffix appended to the fal prompt.
	 *
	 * @since 3.2.12
	 * @var string
	 */
	private const ILLUSTRATION_SUFFIX = ' High-quality digital illustration, clean composition, coherent style, no text, no watermark, no logo, no UI.';

	/**
	 * Build a visual scene description for an AI image generator.
	 *
	 * Returns the clean one-sentence brief (suitable for alt / meta). Call
	 * composeImageModelPrompt() before sending to the image provider.
	 *
	 * @since 3.1.1
	 * @param string $title_or_heading Post title or heading text.
	 * @param string $focus_keyword    SEO focus keyphrase (optional).
	 * @param string $content_excerpt  First paragraphs of the section/post.
	 * @param string $style            Style hint: photo|illustration.
	 * @param string $purpose          featured|heading.
	 * @param string $taxonomy_hint    Optional category/tag labels.
	 * @return string|\WP_Error
	 */
	public function buildImagePrompt(
		string $title_or_heading,
		string $focus_keyword = '',
		string $content_excerpt = '',
		string $style = 'photo',
		string $purpose = 'heading',
		string $taxonomy_hint = ''
	) {
		$style_hint = ( 'illustration' === $style )
			? 'Describe a digital illustration or vector artwork (not a photograph).'
			: 'Describe a realistic photograph (not an illustration or 3D render).';

		$purpose_hint = ( 'featured' === $purpose )
			? 'Purpose: blog featured / hero image — wide editorial composition, one clear focal subject that still reads at thumbnail size, rule of thirds or centered hero.'
			: 'Purpose: in-article section image under a heading — single clear subject that illustrates this section topic, not a collage or text graphic.';

		$user = sprintf(
			"%s\nStyle instruction: %s\nTitle/heading: %s\nFocus keyword: %s\nTopics: %s\nExcerpt: %s",
			$purpose_hint,
			$style_hint,
			$title_or_heading,
			( '' !== $focus_keyword ) ? $focus_keyword : '(none)',
			( '' !== $taxonomy_hint ) ? $taxonomy_hint : '(none)',
			( '' !== $content_excerpt ) ? $content_excerpt : '(none)'
		);

		$result = ProviderBridge::generateText( self::SYSTEM_BRIEF, $user );

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
	 * Append style quality / negative cues for the image model.
	 *
	 * @since 3.2.12
	 * @param string $brief   Clean one-sentence visual brief.
	 * @param string $style   photo|illustration.
	 * @param string $purpose featured|heading.
	 * @return string Prompt to send to the image provider.
	 */
	public function composeImageModelPrompt( string $brief, string $style = 'photo', string $purpose = 'heading' ): string {
		$brief = trim( $brief );
		if ( '' === $brief ) {
			return '';
		}

		$suffix = ( 'illustration' === $style ) ? self::ILLUSTRATION_SUFFIX : self::PHOTO_SUFFIX;
		if ( 'featured' === $purpose ) {
			$suffix .= self::FEATURED_LANDSCAPE_SUFFIX;
		}
		return $brief . $suffix;
	}

	/**
	 * Build richer post body context for featured-image generation.
	 *
	 * Prefers the manual excerpt, then fills remaining words from post content.
	 *
	 * @since 3.2.12
	 * @param \WP_Post $post       Post object.
	 * @param int      $max_words  Word budget (default CONTEXT_WORD_LIMIT).
	 * @return string Plain-text excerpt.
	 */
	public static function buildPostContext( \WP_Post $post, int $max_words = 0 ): string {
		$max_words = $max_words > 0 ? $max_words : self::CONTEXT_WORD_LIMIT;

		$parts = array();
		if ( is_string( $post->post_excerpt ) && '' !== trim( $post->post_excerpt ) ) {
			$parts[] = wp_strip_all_tags( $post->post_excerpt );
		}
		$parts[] = wp_strip_all_tags( (string) $post->post_content );

		$merged = trim( preg_replace( '/\s+/', ' ', implode( ' ', $parts ) ) );
		if ( '' === $merged ) {
			return '';
		}

		return wp_trim_words( $merged, $max_words );
	}

	/**
	 * Category and tag labels for a post (visual topic hints).
	 *
	 * @since 3.2.12
	 * @param int $post_id Post ID.
	 * @return string Comma-separated labels or empty string.
	 */
	public static function buildTaxonomyHint( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}

		$labels = array();

		$categories = get_the_category( $post_id );
		if ( is_array( $categories ) ) {
			foreach ( $categories as $term ) {
				if ( isset( $term->name ) && '' !== $term->name && 'Uncategorized' !== $term->name ) {
					$labels[] = $term->name;
				}
			}
		}

		$tags = get_the_tags( $post_id );
		if ( is_array( $tags ) ) {
			foreach ( $tags as $term ) {
				if ( isset( $term->name ) && '' !== $term->name ) {
					$labels[] = $term->name;
				}
			}
		}

		$labels = array_values( array_unique( array_map( 'sanitize_text_field', $labels ) ) );
		if ( empty( $labels ) ) {
			return '';
		}

		// Cap to keep the text-model prompt small.
		$labels = array_slice( $labels, 0, 8 );
		return implode( ', ', $labels );
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
			'focus_keyword="' . $heading_or_keyword . '"'
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

		$result = ProviderBridge::generateText( self::SYSTEM_ALT, $user );

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
