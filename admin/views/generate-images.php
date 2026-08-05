<?php
/**
 * Legacy Generate Featured Images view — unused since 3.2.3 (merged into Featured Images).
 *
 * Kept as a stub so old includes fail loudly if referenced.
 *
 * @package SmartImageMatcher
 * @since   3.2.0
 * @deprecated 3.2.3
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

wp_die(
	esc_html__( 'This page has moved to Smart Image Matcher → Featured Images.', 'smart-image-matcher' ),
	esc_html__( 'Moved', 'smart-image-matcher' ),
	array( 'response' => 302 )
);
