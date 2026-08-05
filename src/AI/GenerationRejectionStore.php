<?php
/**
 * Persists user rejections of AI-generated image candidates.
 *
 * Blocks future Generate / Generate All / bulk runs for the same
 * (post_id, heading_hash, focus_keyword, style) until Regenerate (force).
 *
 * @package SmartImageMatcher\AI
 * @since   3.2.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GenerationRejectionStore
 *
 * @since 3.2.0
 */
class GenerationRejectionStore {

	/**
	 * Option key (autoload=no).
	 *
	 * @since 3.2.0
	 * @var string
	 */
	private const OPTION = 'sim_ai_generation_rejections';

	/**
	 * Maximum stored rejection entries before oldest are dropped.
	 *
	 * @since 3.2.0
	 * @var int
	 */
	private const MAX_ENTRIES = 500;

	/**
	 * Whether generation is blocked for this combo.
	 *
	 * @since 3.2.0
	 * @param int    $post_id       Post ID.
	 * @param string $heading_hash  Stable heading hash.
	 * @param string $focus_keyword SEO focus keyword (may be empty).
	 * @param string $style         photo|illustration.
	 * @return bool
	 */
	public static function isBlocked( int $post_id, string $heading_hash, string $focus_keyword, string $style ): bool {
		$key  = self::makeKey( $post_id, $heading_hash, $focus_keyword, $style );
		$list = get_option( self::OPTION, array() );

		return is_array( $list ) && isset( $list[ $key ] );
	}

	/**
	 * Record a rejection so future non-forced generation is skipped.
	 *
	 * @since 3.2.0
	 * @param int    $post_id       Post ID.
	 * @param string $heading_hash  Stable heading hash.
	 * @param string $focus_keyword SEO focus keyword (may be empty).
	 * @param string $style         photo|illustration.
	 * @return void
	 */
	public static function block( int $post_id, string $heading_hash, string $focus_keyword, string $style ): void {
		$key  = self::makeKey( $post_id, $heading_hash, $focus_keyword, $style );
		$list = get_option( self::OPTION, array() );

		if ( ! is_array( $list ) ) {
			$list = array();
		}

		$list[ $key ] = time();

		if ( count( $list ) > self::MAX_ENTRIES ) {
			asort( $list, SORT_NUMERIC );
			$list = array_slice( $list, -self::MAX_ENTRIES, null, true );
		}

		update_option( self::OPTION, $list, false );
	}

	/**
	 * Build a stable storage key.
	 *
	 * @since 3.2.0
	 * @param int    $post_id       Post ID.
	 * @param string $heading_hash  Stable heading hash.
	 * @param string $focus_keyword SEO focus keyword.
	 * @param string $style         photo|illustration.
	 * @return string
	 */
	private static function makeKey( int $post_id, string $heading_hash, string $focus_keyword, string $style ): string {
		return md5( $post_id . '|' . $heading_hash . '|' . $focus_keyword . '|' . $style );
	}
}
