<?php
/**
 * Pure match outcome for one featured slot or heading.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.2.31
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MatchDecision
 *
 * @since 3.2.31
 */
class MatchDecision {

	const INSERT   = 'insert';
	const REVIEW   = 'review';
	const GENERATE = 'generate';
	const SKIP     = 'skip';

	/**
	 * Decide the outcome for a 0–100 library score.
	 *
	 * @since 3.2.31
	 * @param int  $score         Best library score (0 if none).
	 * @param int  $auto_insert   Auto-insert threshold.
	 * @param int  $review_min    Review floor.
	 * @param bool $can_generate  Whether skip-band generation is available.
	 * @return string insert|review|generate|skip
	 */
	public static function decide( int $score, int $auto_insert, int $review_min, bool $can_generate ): string {
		if ( $score >= $auto_insert ) {
			return self::INSERT;
		}
		if ( $score >= $review_min ) {
			return self::REVIEW;
		}
		if ( $can_generate ) {
			return self::GENERATE;
		}
		return self::SKIP;
	}
}
