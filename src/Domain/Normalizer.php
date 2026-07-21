<?php
/**
 * Text normalization with stemming and US/British spelling variants.
 *
 * Ported and modernised from .legacy/includes/class-sim-normalizer.php.
 * All public methods are static — the class is a pure-function utility.
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
 * Class Normalizer
 *
 * @since 3.0.0
 */
class Normalizer {

	/**
	 * US → British spelling pairs.
	 *
	 * @var array<string, string>
	 */
	private static array $spellingVariants = array(
		'color'    => 'colour',
		'gray'     => 'grey',
		'center'   => 'centre',
		'meter'    => 'metre',
		'fiber'    => 'fibre',
		'theater'  => 'theatre',
		'organize' => 'organise',
		'recognize' => 'recognise',
		'realize'  => 'realise',
		'analyze'  => 'analyse',
		'paralyze' => 'paralyse',
		'catalog'  => 'catalogue',
		'dialog'   => 'dialogue',
		'traveler' => 'traveller',
		'canceled' => 'cancelled',
		'labeled'  => 'labelled',
		'modeling' => 'modelling',
		'flavor'   => 'flavour',
		'honor'    => 'honour',
		'labor'    => 'labour',
		'neighbor' => 'neighbour',
		'vigor'    => 'vigour',
		'defense'  => 'defence',
		'offense'  => 'offence',
		'license'  => 'licence',
		'practice' => 'practise',
		'aging'    => 'ageing',
		'jewelry'  => 'jewellery',
		'tire'     => 'tyre',
		'plow'     => 'plough',
	);

	/**
	 * Common irregular singular → plural pairs.
	 *
	 * @var array<string, string>
	 */
	private static array $irregularPlurals = array(
		'child'   => 'children',
		'person'  => 'people',
		'man'     => 'men',
		'woman'   => 'women',
		'tooth'   => 'teeth',
		'foot'    => 'feet',
		'mouse'   => 'mice',
		'goose'   => 'geese',
		'ox'      => 'oxen',
		'leaf'    => 'leaves',
		'life'    => 'lives',
		'knife'   => 'knives',
		'wife'    => 'wives',
		'half'    => 'halves',
		'calf'    => 'calves',
		'shelf'   => 'shelves',
		'wolf'    => 'wolves',
		'thief'   => 'thieves',
		'loaf'    => 'loaves',
		'deer'    => 'deer',
		'sheep'   => 'sheep',
		'fish'    => 'fish',
		'species' => 'species',
		'series'  => 'series',
	);

	/**
	 * Cached stop-words array.
	 *
	 * @var string[]|null
	 */
	private static ?array $stopWords = null;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Normalize text into an array of keywords, reading settings from DB.
	 *
	 * @since 3.0.0
	 * @param string $text Input text.
	 * @return string[]
	 */
	public static function normalizeFromSettings( string $text ): array {
		return self::normalize(
			$text,
			(bool) Settings::get( 'enable_stemming' ),
			(bool) Settings::get( 'enable_spelling_variants' )
		);
	}

	/**
	 * Normalize text into an array of keywords.
	 *
	 * @since 3.0.0
	 * @param string $text                   Input text.
	 * @param bool   $enableStemming         Enable singular/plural handling.
	 * @param bool   $enableSpellingVariants Enable US/British spelling variants.
	 * @return string[]
	 */
	public static function normalize(
		string $text,
		bool $enableStemming = true,
		bool $enableSpellingVariants = true
	): array {
		$text = strtolower( $text );

		// Strip possessives before everything else.
		// "bird's nest" → "bird nest", "birds' nests" → "birds nests".
		$text = preg_replace( "/([a-z])'s?\\b/", '$1', $text );

		// Common separators → spaces.
		$text = str_replace( array( '/', ',', '|', ';', ':', '(', ')', '[', ']', '-', '_' ), ' ', $text );

		// Remove any remaining special characters.
		$text = preg_replace( '/[^a-z0-9\s]/', '', $text );

		// Split.
		$words = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return array();
		}

		// Build the whitelist from settings (static cache per request).
		$whitelist = self::getWhitelist();

		// Filter stop-words and very short words.
		$stopWords = self::getStopWords();
		$keywords  = array_filter(
			$words,
			static function ( string $word ) use ( $stopWords, $whitelist ): bool {
				return ( strlen( $word ) > 1 || in_array( $word, $whitelist, true ) )
					&& ! in_array( $word, $stopWords, true );
			}
		);

		// Stem.
		if ( $enableStemming ) {
			$keywords = array_map( array( self::class, 'stemWord' ), $keywords );
		}

		// Expand spelling variants.
		if ( $enableSpellingVariants ) {
			$expanded = array();
			foreach ( $keywords as $keyword ) {
				$expanded[] = $keyword;
				foreach ( self::getSpellingVariants( $keyword ) as $variant ) {
					$expanded[] = $variant;
				}
			}
			$keywords = array_unique( $expanded );
		}

		return array_values( $keywords );
	}

	/**
	 * Stem a single word (simplified Porter-like rules).
	 *
	 * @since 3.0.0
	 * @param string $word Word to stem.
	 * @return string
	 */
	public static function stemWord( string $word ): string {
		// Irregular plurals — keep base form for matching.
		if ( in_array( $word, self::$irregularPlurals, true ) ) {
			return $word;
		}
		$singular = array_search( $word, self::$irregularPlurals, true );
		if ( false !== $singular ) {
			return (string) $singular;
		}

		// -ies → -y (babies → baby).
		if ( preg_match( '/(.{2,})ies$/', $word, $m ) ) {
			return $m[1] . 'y';
		}

		// -es after ss/x/z/ch/sh (boxes → box, churches → church).
		if ( preg_match( '/(.+)(ss|x|z|ch|sh)es$/', $word, $m ) ) {
			return $m[1] . $m[2];
		}

		// -ves → -f (wolves → wolf, knives → knife).
		if ( preg_match( '/(.{2,})ves$/', $word, $m ) ) {
			return $m[1] . 'f';
		}

		// Simple plural -s (not -ss, -us, -is).
		if ( preg_match( '/(.{3,})s$/', $word, $m ) && ! preg_match( '/(ss|us|is)$/', $word ) ) {
			return $m[1];
		}

		return $word;
	}

	/**
	 * Get US/British spelling variants for a word.
	 *
	 * @since 3.0.0
	 * @param string $word Word to check.
	 * @return string[]
	 */
	public static function getSpellingVariants( string $word ): array {
		$variants = array();
		if ( isset( self::$spellingVariants[ $word ] ) ) {
			$variants[] = self::$spellingVariants[ $word ];
		}
		$us = array_search( $word, self::$spellingVariants, true );
		if ( false !== $us ) {
			$variants[] = (string) $us;
		}
		return $variants;
	}

	/**
	 * Check whether two words match, accounting for linguistic variations.
	 *
	 * @since 3.0.0
	 * @param string $word1                  First word.
	 * @param string $word2                  Second word.
	 * @param bool   $enableStemming         Enable stemming.
	 * @param bool   $enableSpellingVariants Enable spelling variants.
	 * @return bool
	 */
	public static function wordsMatch(
		string $word1,
		string $word2,
		bool $enableStemming = true,
		bool $enableSpellingVariants = true
	): bool {
		$w1 = strtolower( trim( $word1 ) );
		$w2 = strtolower( trim( $word2 ) );

		if ( $w1 === $w2 ) {
			return true;
		}

		// Strip possessives.
		$w1p = (string) preg_replace( "/([a-z])'s?\\b/", '$1', $w1 );
		$w2p = (string) preg_replace( "/([a-z])'s?\\b/", '$1', $w2 );
		if ( $w1p === $w2p ) {
			return true;
		}

		if ( $enableStemming && self::stemWord( $w1 ) === self::stemWord( $w2 ) ) {
			return true;
		}

		if ( $enableSpellingVariants ) {
			$v1 = array_merge( array( $w1 ), self::getSpellingVariants( $w1 ) );
			$v2 = array_merge( array( $w2 ), self::getSpellingVariants( $w2 ) );
			if ( array_intersect( $v1, $v2 ) ) {
				return true;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the English stop-words list (static cached per request).
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	private static function getStopWords(): array {
		if ( null === self::$stopWords ) {
			self::$stopWords = array(
				'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
				'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'been', 'be',
				'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
				'should', 'may', 'might', 'can', 'this', 'that', 'these', 'those',
			);
		}
		return self::$stopWords;
	}

	/**
	 * Return the user-configured short-word whitelist.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	private static function getWhitelist(): array {
		$raw       = (string) Settings::get( 'whitelisted_short_words' );
		$whitelist = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		return array_values( $whitelist );
	}
}
