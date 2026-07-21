<?php
/** Premium: Freemius / license integration shim. @package SmartImageMatcher\Premium @since 3.0.0 */
declare( strict_types=1 );
namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use SmartImageMatcher\Premium as PremiumGate;
/** Class License — @since 3.0.0 */
class License {
	/**
	 * Called by the Pro add-on plugin to unlock all paid features.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public static function activate(): void {
		// TODO: Phase 6 — validate license via Freemius, then call PremiumGate::enable() per feature.
		$features = array(
			'ai_matching', 'ai_alt_text', 'ai_vision_match', 'ai_featured_image',
			'bulk_processor', 'review_queue', 'analytics', 'auto_match_on_publish',
			'fiaa_scheduled_cron', 'extended_carousel', 'cli_commands',
		);
		foreach ( $features as $slug ) {
			PremiumGate::enable( $slug );
		}
	}
}
