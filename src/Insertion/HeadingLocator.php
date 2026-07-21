<?php
/**
 * Resolves stable heading hashes to Gutenberg block client IDs.
 *
 * The hash = sha1( level : normalised_text : occurrence_index ).
 * It is the same for both Gutenberg and Classic paths (HeadingExtractor
 * computes it with this class) so insertion works against either editor.
 *
 * @package SmartImageMatcher\Insertion
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Insertion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class HeadingLocator
 *
 * @since 3.0.0
 */
class HeadingLocator {

	/**
	 * Compute the stable hash for a heading.
	 *
	 * @since 3.0.0
	 * @param int    $level           Heading level (2–6).
	 * @param string $normalizedText  Normalised (lowercase) heading text.
	 * @param int    $occurrenceIndex 0-based occurrence index among headings
	 *                                with the same level + text in this post.
	 * @return string 40-character hex sha1 string.
	 */
	public static function computeHash(
		int $level,
		string $normalizedText,
		int $occurrenceIndex = 0
	): string {
		return sha1( "{$level}:{$normalizedText}:{$occurrenceIndex}" );
	}

	/**
	 * Find the array index in a flat block list whose heading hash matches.
	 *
	 * Walks the top-level block array. For nested headings (inside Group,
	 * Column, etc.) the caller should flatten first or use findIndexRecursive.
	 *
	 * @since 3.0.0
	 * @param string                          $hash   Target hash.
	 * @param array<int, array<string,mixed>> $blocks Parsed block array.
	 * @return int|null Block array index, or null if not found.
	 */
	public function findIndex( string $hash, array $blocks ): ?int {
		$seen = array(); // normalised_text => count

		foreach ( $blocks as $index => $block ) {
			if ( ( $block['blockName'] ?? '' ) !== 'core/heading' ) {
				continue;
			}

			$level     = (int) ( $block['attrs']['level'] ?? 2 );
			$innerHtml = $block['innerHTML'] ?? '';
			$text      = strtolower( trim( wp_strip_all_tags( html_entity_decode( $innerHtml, ENT_QUOTES, 'UTF-8' ) ) ) );
			$key       = "{$level}:{$text}";
			$occurrence = $seen[ $key ] ?? 0;
			$seen[ $key ] = $occurrence + 1;

			$candidate = self::computeHash( $level, $text, $occurrence );
			if ( $candidate === $hash ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Flatten a nested block tree into a single-level array.
	 *
	 * Useful when headings may be inside container blocks.
	 * Returns flat list of all blocks preserving order.
	 *
	 * @since 3.0.0
	 * @param array<int, array<string,mixed>> $blocks Block array.
	 * @return array<int, array<string,mixed>>
	 */
	public static function flatten( array $blocks ): array {
		$flat = array();
		foreach ( $blocks as $block ) {
			$flat[] = $block;
			if ( ! empty( $block['innerBlocks'] ) ) {
				foreach ( self::flatten( $block['innerBlocks'] ) as $inner ) {
					$flat[] = $inner;
				}
			}
		}
		return $flat;
	}
}
