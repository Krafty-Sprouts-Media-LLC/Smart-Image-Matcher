<?php
/**
 * Hard relevance rules applied to candidates before any score is trusted.
 *
 * Two rejections, both independent of keyword or model scores:
 *   1. Place conflict — the image names a US state the heading / article
 *      does not ("…in Idaho" vs Right-to-Farm-Laws-in-Virginia.jpg).
 *   2. No shared topic — once places and generic legal words are removed,
 *      heading and image share no word ("Property Tax … in Kansas" vs
 *      Bowfishing-laws-in-Kansas.jpg).
 *
 * @package SmartImageMatcher\Domain
 * @since   3.4.9
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RelevanceGuard
 *
 * @since 3.4.9
 */
class RelevanceGuard {

	const PLACE_CONFLICT  = 'place_conflict';
	const NO_SHARED_TOPIC = 'no_shared_topic';

	/**
	 * US states + DC. Multi-word names first so "west virginia" is consumed
	 * before "virginia" is looked for.
	 *
	 * @var string[]
	 */
	const US_STATES = array(
		'district of columbia',
		'north carolina',
		'south carolina',
		'north dakota',
		'south dakota',
		'west virginia',
		'new hampshire',
		'new jersey',
		'new mexico',
		'new york',
		'rhode island',
		'alabama',
		'alaska',
		'arizona',
		'arkansas',
		'california',
		'colorado',
		'connecticut',
		'delaware',
		'florida',
		'georgia',
		'hawaii',
		'idaho',
		'illinois',
		'indiana',
		'iowa',
		'kansas',
		'kentucky',
		'louisiana',
		'maine',
		'maryland',
		'massachusetts',
		'michigan',
		'minnesota',
		'mississippi',
		'missouri',
		'montana',
		'nebraska',
		'nevada',
		'ohio',
		'oklahoma',
		'oregon',
		'pennsylvania',
		'tennessee',
		'texas',
		'utah',
		'vermont',
		'virginia',
		'washington',
		'wisconsin',
		'wyoming',
	);

	/**
	 * Words nearly every legal-guide filename and heading carries.
	 *
	 * @var string[]
	 */
	const GENERIC_TERMS = array( 'law', 'rule', 'requirement', 'regulation', 'legal', 'state' );

	/**
	 * Why an image must not be matched to a heading, or '' when it may.
	 *
	 * @since 3.4.9
	 * @param string               $heading_text Heading (or post title for the featured slot).
	 * @param string               $context_text Article context, usually the post title.
	 * @param array<string, mixed> $image        Row with filename / title / alt.
	 * @return string '' | self::PLACE_CONFLICT | self::NO_SHARED_TOPIC
	 */
	public static function rejectReason( string $heading_text, string $context_text, array $image ): string {
		$image_text = implode(
			' ',
			array(
				pathinfo( (string) ( $image['filename'] ?? '' ), PATHINFO_FILENAME ),
				(string) ( $image['title'] ?? '' ),
				(string) ( $image['alt'] ?? '' ),
			)
		);

		$places         = self::placeTerms();
		$article_places = self::extractPlaces( $heading_text . ' ' . $context_text, $places );
		$image_places   = self::extractPlaces( $image_text, $places );

		if ( ! empty( $article_places ) && ! empty( $image_places ) && ! array_intersect( $article_places, $image_places ) ) {
			return self::PLACE_CONFLICT;
		}

		$heading_topic = self::topicTerms( $heading_text, $places );
		if ( empty( $heading_topic ) ) {
			return '';
		}

		if ( ! array_intersect( $heading_topic, self::topicTerms( $image_text, $places ) ) ) {
			return self::NO_SHARED_TOPIC;
		}

		return '';
	}

	/**
	 * Drop candidates the guard rejects.
	 *
	 * @since 3.4.9
	 * @param array<int, array<string, mixed>> $candidates   Image rows.
	 * @param string                           $heading_text Heading text.
	 * @param string                           $context_text Article context.
	 * @return array<int, array<string, mixed>>
	 */
	public static function filter( array $candidates, string $heading_text, string $context_text ): array {
		return array_values(
			array_filter(
				$candidates,
				static fn( array $image ): bool => '' === self::rejectReason( $heading_text, $context_text, $image )
			)
		);
	}

	/**
	 * Place names found in text (lowercase, longest names consumed first).
	 *
	 * @param string   $text   Any text; dashes and underscores count as spaces.
	 * @param string[] $places Place names, multi-word first.
	 * @return string[]
	 */
	private static function extractPlaces( string $text, array $places ): array {
		$haystack = self::pad( $text );
		$found    = array();
		foreach ( $places as $place ) {
			if ( false !== strpos( $haystack, ' ' . $place . ' ' ) ) {
				$found[]  = $place;
				$haystack = str_replace( ' ' . $place . ' ', ' ', $haystack );
			}
		}
		return $found;
	}

	/**
	 * Stemmed topic words with places and generic legal words removed.
	 *
	 * @param string   $text   Any text.
	 * @param string[] $places Place names.
	 * @return string[]
	 */
	private static function topicTerms( string $text, array $places ): array {
		$haystack = self::pad( $text );
		foreach ( $places as $place ) {
			$haystack = str_replace( ' ' . $place . ' ', ' ', $haystack );
		}

		/**
		 * Filter words too common to prove two items share a topic.
		 *
		 * @since 3.4.9
		 * @param string[] $terms Stemmed generic terms.
		 */
		$generic = (array) apply_filters( 'sim_relevance_generic_terms', self::GENERIC_TERMS );

		return array_values( array_diff( Normalizer::indexTerms( $haystack ), $generic ) );
	}

	/**
	 * Place names to check, longest first.
	 *
	 * @return string[]
	 */
	private static function placeTerms(): array {
		/**
		 * Filter the place names used for place-conflict rejection.
		 *
		 * @since 3.4.9
		 * @param string[] $places Lowercase place names.
		 */
		$places = array_map( 'strtolower', (array) apply_filters( 'sim_relevance_place_terms', self::US_STATES ) );
		usort( $places, static fn( string $a, string $b ): int => substr_count( $b, ' ' ) - substr_count( $a, ' ' ) );
		return $places;
	}

	/**
	 * Lowercase, non-alphanumerics to single spaces, padded with spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function pad( string $text ): string {
		return ' ' . trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $text ) ) ) . ' ';
	}
}
