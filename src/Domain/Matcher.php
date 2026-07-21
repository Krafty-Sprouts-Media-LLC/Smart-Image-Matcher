<?php
/**
 * Keyword-based image-to-heading scoring engine.
 *
 * Ported and modernised from .legacy/includes/class-sim-matcher.php.
 *
 * Scoring priority (0-100):
 *   1. Filename  — up to 100 pts (primary, always exists)
 *   2. Title     — up to 90 pts (+10 if intentionally set)
 *   3. Alt text  — up to 85 pts
 *
 * @package SmartImageMatcher\Domain
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Settings\Settings;

/**
 * Class Matcher
 *
 * @since 3.0.0
 */
class Matcher {

	/**
	 * Extract keywords from heading text using current linguistic settings.
	 *
	 * @since 3.0.0
	 * @param string $text Heading text.
	 * @return string[]
	 */
	public function extractKeywords( string $text ): array {
		return Normalizer::normalizeFromSettings( $text );
	}

	/**
	 * Apply smart hierarchy filtering to a set of headings.
	 *
	 * Modes: 'all' | 'primary' | 'smart'
	 *
	 * @since 3.0.0
	 * @param array<int, array<string, mixed>> $headings  Extracted headings.
	 * @param string                           $mode      Hierarchy mode.
	 * @return array<int, array<string, mixed>>
	 */
	public function filterByHierarchy( array $headings, string $mode = 'smart' ): array {
		if ( 'all' === $mode ) {
			return $headings;
		}

		if ( 'primary' === $mode ) {
			return array_values(
				array_filter( $headings, static fn( $h ) => 'h2' === ( $h['tag'] ?? '' ) )
			);
		}

		// Smart hierarchy.
		$threshold   = (int) Settings::get( 'heading_overlap_threshold' );
		$filtered    = array();
		$lastH2Keys  = array();

		foreach ( $headings as $heading ) {
			$level = (int) ( $heading['level'] ?? 2 );

			if ( 2 === $level ) {
				$filtered[]  = $heading;
				$lastH2Keys  = $this->extractKeywords( $heading['text'] ?? '' );
				continue;
			}

			if ( empty( $lastH2Keys ) ) {
				$filtered[] = $heading;
				continue;
			}

			$current = $this->extractKeywords( $heading['text'] ?? '' );
			$overlap = $this->calculateKeywordOverlap( $lastH2Keys, $current );

			if ( $overlap < $threshold ) {
				$filtered[] = $heading;
			}
		}

		return $filtered;
	}

	/**
	 * Find keyword matches for a heading against a flat image array.
	 *
	 * During Phase 3 this will be replaced by an SQL-backed ImageRepository query.
	 * For now it still iterates the array (mirrors legacy behaviour).
	 *
	 * @since 3.0.0
	 * @param array<string, mixed>             $heading    Heading data (text, level, hash …).
	 * @param array<int, array<string, mixed>> $images     Flat image metadata array.
	 * @return array<int, array<string, mixed>>
	 */
	public function findKeywordMatches( array $heading, array $images ): array {
		$keywords  = $this->extractKeywords( $heading['text'] ?? '' );
		$threshold = (int) Settings::get( 'confidence_threshold' );
		$maxHits   = (int) Settings::get( 'max_matches_per_heading' );

		$scored = array();
		foreach ( $images as $image ) {
			$score = $this->calculateScore( $keywords, $image );
			if ( $score >= $threshold ) {
				$scored[] = array(
					'image_id'         => (int) $image['id'],
					'confidence_score' => $score,
					'match_method'     => 'keyword',
					'image_url'        => (string) ( $image['url'] ?? '' ),
					'filename'         => (string) ( $image['filename'] ?? '' ),
					'title'            => (string) ( $image['title'] ?? '' ),
				);
			}
		}

		usort( $scored, static fn( $a, $b ) => $b['confidence_score'] - $a['confidence_score'] );

		return array_slice( $scored, 0, $maxHits );
	}

	/**
	 * Calculate the confidence score (0-100) for a heading's keywords against one image.
	 *
	 * @since 3.0.0
	 * @param string[]             $keywords Heading keywords (already normalised).
	 * @param array<string, mixed> $image    Image metadata row.
	 * @return int
	 */
	public function calculateScore( array $keywords, array $image ): int {
		if ( empty( $keywords ) ) {
			return 0;
		}

		$enableStemming  = (bool) Settings::get( 'enable_stemming' );
		$enableVariants  = (bool) Settings::get( 'enable_spelling_variants' );

		// ---- Prepare image fields ----
		$rawFilename = pathinfo( (string) ( $image['filename'] ?? '' ), PATHINFO_FILENAME );
		$filename    = strtolower( str_replace( array( '-', '_' ), ' ', $rawFilename ) );
		$filenameWords = preg_split( '/\s+/', $filename, -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		$title      = strtolower( (string) ( $image['title'] ?? '' ) );
		$titleWords = preg_split( '/\s+/', (string) preg_replace( '/[^a-z0-9\s]/', '', $title ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		$alt      = strtolower( (string) ( $image['alt'] ?? '' ) );
		$altWords = preg_split( '/\s+/', (string) preg_replace( '/[^a-z0-9\s]/', '', $alt ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		$headingText = strtolower( implode( ' ', $keywords ) );

		// Is the title meaningfully different from the filename?
		$filenameNorm    = (string) preg_replace( '/[^a-z0-9\s]/', '', $filename );
		$titleNorm       = (string) preg_replace( '/[^a-z0-9\s]/', '', $title );
		$titleIntentional = ! empty( $title ) && ( $titleNorm !== $filenameNorm );

		// ---- Score filename (max 100) ----
		$fnMatches = $this->countWordMatches( $keywords, $filenameWords, $enableStemming, $enableVariants );
		$fnScore   = 0;

		if ( $fnMatches > 0 ) {
			$fnScore = ( $fnMatches / count( $keywords ) ) * 100;

			// Exact phrase → perfect score, no penalties.
			$fileNormComp = str_replace( array( '-', '_' ), ' ', $filename );
			if ( false !== strpos( $fileNormComp, $headingText ) ) {
				$fnScore = 100;
			} else {
				$extra = count( $filenameWords ) - count( $keywords );
				if ( 0 === $extra ) {
					$fnScore = min( $fnScore * 1.1, 100 );
				} elseif ( 1 === $extra ) {
					$fnScore *= 0.95;
				} elseif ( 2 === $extra ) {
					$fnScore *= 0.90;
				} elseif ( $extra > 2 ) {
					$fnScore *= 0.85;
				}
			}
		}

		// ---- Score title (max 90 + 10 intentional bonus) ----
		$titleMatches = $this->countWordMatches( $keywords, $titleWords, $enableStemming, $enableVariants );
		$titleScore   = 0;

		if ( $titleMatches > 0 && ! empty( $title ) ) {
			$titleScore = ( $titleMatches / count( $keywords ) ) * 90;

			if ( false !== strpos( $title, $headingText ) ) {
				$titleScore = 90;
			}
			if ( $titleIntentional ) {
				$titleScore = min( $titleScore + 10, 100 );
			}

			$extra = count( $titleWords ) - count( $keywords );
			if ( 0 === $extra ) {
				$titleScore = min( $titleScore * 1.1, 100 );
			} elseif ( 1 === $extra ) {
				$titleScore *= 0.90;
			} elseif ( 2 === $extra ) {
				$titleScore *= 0.82;
			} elseif ( $extra > 2 ) {
				$titleScore *= 0.75;
			}
		}

		// ---- Score alt text (max 85) ----
		$altMatches = $this->countWordMatches( $keywords, $altWords, $enableStemming, $enableVariants );
		$altScore   = 0;

		if ( $altMatches > 0 && ! empty( $alt ) ) {
			$altScore = ( $altMatches / count( $keywords ) ) * 85;

			if ( false !== strpos( $alt, $headingText ) ) {
				$altScore = 85;
			}

			$extra = count( $altWords ) - count( $keywords );
			if ( 0 === $extra ) {
				$altScore = min( $altScore * 1.1, 85 );
			} elseif ( 1 === $extra ) {
				$altScore *= 0.90;
			} elseif ( 2 === $extra ) {
				$altScore *= 0.82;
			} elseif ( $extra > 2 ) {
				$altScore *= 0.75;
			}
		}

		// ---- Final: best weighted score ----
		$final = max(
			$fnScore    * 1.0,
			$titleScore * 0.9,
			$altScore   * 0.85
		);

		// All-keyword match boosts.
		if ( $fnMatches === count( $keywords ) ) {
			$final = max( $final, 95 );
			if ( false !== strpos( $filename, $headingText ) ) {
				$final = 100;
			}
		}
		if ( $titleMatches === count( $keywords ) ) {
			$final = max( $final, 92 );
			if ( false !== strpos( $title, $headingText ) ) {
				$final = max( $final, 98 );
			}
			if ( $titleIntentional ) {
				$final = min( $final + 5, 100 );
			}
		}

		return min( (int) round( $final ), 100 );
	}

	/**
	 * Calculate the Jaccard overlap (0-100) between two keyword sets.
	 *
	 * @since 3.0.0
	 * @param string[] $keywords1 First set.
	 * @param string[] $keywords2 Second set.
	 * @return float
	 */
	public function calculateKeywordOverlap( array $keywords1, array $keywords2 ): float {
		if ( empty( $keywords1 ) || empty( $keywords2 ) ) {
			return 0.0;
		}
		$union        = array_unique( array_merge( $keywords1, $keywords2 ) );
		$intersection = array_intersect( $keywords1, $keywords2 );
		return ( count( $intersection ) / count( $union ) ) * 100;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Count how many heading keywords match any word in a field's word list.
	 *
	 * @since 3.0.0
	 * @param string[] $headingKeywords Heading normalised keywords.
	 * @param string[] $fieldWords      Words from the image field.
	 * @param bool     $stemming        Enable stemming.
	 * @param bool     $variants        Enable spelling variants.
	 * @return int
	 */
	private function countWordMatches(
		array $headingKeywords,
		array $fieldWords,
		bool $stemming,
		bool $variants
	): int {
		$count = 0;
		foreach ( $headingKeywords as $kw ) {
			foreach ( $fieldWords as $fw ) {
				if ( Normalizer::wordsMatch( $kw, $fw, $stemming, $variants ) ) {
					$count++;
					break;
				}
			}
		}
		return $count;
	}
}
