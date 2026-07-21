<?php
/**
 * Extracts headings from post content (Gutenberg block tree + Classic HTML).
 *
 * Each returned heading includes a stable sha1 hash computed from its level,
 * normalised text, and occurrence index — so repeated headings never collide
 * and the hash survives content edits that don't touch that heading.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Insertion\HeadingLocator;

/**
 * Class HeadingExtractor
 *
 * @since 3.0.0
 */
class HeadingExtractor {

	/**
	 * Extract headings from post content.
	 *
	 * Returns an array of heading descriptors:
	 * {
	 *   heading_hash    string   sha1 stable identifier
	 *   text            string   clean heading text
	 *   tag             string   h2 … h6
	 *   level           int      2 … 6
	 *   block_client_id string|null  Gutenberg client ID (null for Classic)
	 * }
	 *
	 * @since 3.0.0
	 * @param string $content Raw post_content.
	 * @return array<int, array<string, mixed>>
	 */
	public function extract( string $content ): array {
		if ( has_blocks( $content ) ) {
			return $this->extractFromBlocks( $content );
		}
		return $this->extractFromHtml( $content );
	}

	// -------------------------------------------------------------------------
	// Gutenberg path
	// -------------------------------------------------------------------------

	/**
	 * Walk the parsed block tree and collect heading blocks.
	 *
	 * Recursively descends into inner blocks so headings inside Group,
	 * Column, or other container blocks are found correctly.
	 *
	 * @since 3.0.0
	 * @param string $content Post content.
	 * @return array<int, array<string, mixed>>
	 */
	private function extractFromBlocks( string $content ): array {
		$blocks   = parse_blocks( $content );
		$headings = array();
		$seen     = array(); // Track occurrence index per normalised text.

		$this->walkBlocks( $blocks, $headings, $seen );

		return $headings;
	}

	/**
	 * Recursively walk a block array and collect core/heading entries.
	 *
	 * @since 3.0.0
	 * @param array<int, array<string, mixed>>  $blocks   Block array.
	 * @param array<int, array<string, mixed>>  &$headings Accumulator.
	 * @param array<string, int>               &$seen    Occurrence counter keyed by normalised text.
	 * @return void
	 */
	private function walkBlocks( array $blocks, array &$headings, array &$seen ): void {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === 'core/heading' ) {
				$level     = (int) ( $block['attrs']['level'] ?? 2 );
				$innerHtml = $block['innerHTML'] ?? '';
				$text      = $this->cleanText( $innerHtml );

				if ( '' === $text ) {
					continue;
				}

				$normalised = strtolower( $text );
				$occurrence = $seen[ $normalised ] ?? 0;
				$seen[ $normalised ] = $occurrence + 1;

				$hash = HeadingLocator::computeHash( $level, $normalised, $occurrence );

				$headings[] = array(
					'heading_hash'    => $hash,
					'text'            => $text,
					'tag'             => 'h' . $level,
					'level'           => $level,
					'block_client_id' => $block['attrs']['clientId'] ?? null,
				);
			}

			// Recurse into inner blocks.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walkBlocks( $block['innerBlocks'], $headings, $seen );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Classic HTML path
	// -------------------------------------------------------------------------

	/**
	 * Extract headings from plain HTML (Classic Editor).
	 *
	 * The same hash is computed so the Classic path is consistent with the
	 * block path, and both can be used interchangeably.
	 *
	 * @since 3.0.0
	 * @param string $content Post HTML content.
	 * @return array<int, array<string, mixed>>
	 */
	private function extractFromHtml( string $content ): array {
		$headings = array();
		$seen     = array();

		preg_match_all(
			'/<(h[2-6])[^>]*>(.*?)<\/\1>/is',
			$content,
			$matches,
			PREG_SET_ORDER
		);

		foreach ( $matches as $match ) {
			$tag   = strtolower( $match[1] );
			$level = (int) substr( $tag, 1 );
			$text  = $this->cleanText( $match[2] );

			if ( '' === $text ) {
				continue;
			}

			$normalised = strtolower( $text );
			$occurrence = $seen[ $normalised ] ?? 0;
			$seen[ $normalised ] = $occurrence + 1;

			$hash = HeadingLocator::computeHash( $level, $normalised, $occurrence );

			$headings[] = array(
				'heading_hash'    => $hash,
				'text'            => $text,
				'tag'             => $tag,
				'level'           => $level,
				'block_client_id' => null,
			);
		}

		return $headings;
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Strip tags and HTML entities from heading inner HTML.
	 *
	 * @since 3.0.0
	 * @param string $html Raw inner HTML.
	 * @return string
	 */
	private function cleanText( string $html ): string {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return trim( $text );
	}
}
