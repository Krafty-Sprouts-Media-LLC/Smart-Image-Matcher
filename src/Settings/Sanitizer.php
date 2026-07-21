<?php
/**
 * Per-field sanitization for the settings form.
 *
 * @package SmartImageMatcher\Settings
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Settings;

use SmartImageMatcher\Domain\PostStatuses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Sanitizer
 *
 * @since 3.0.0
 */
class Sanitizer {

	/**
	 * Sanitize the full settings array from $_POST.
	 *
	 * Called by register_setting() sanitize_callback.
	 *
	 * @since 3.0.0
	 * @param mixed $raw Raw input (must be array).
	 * @return array<string, mixed>
	 */
	public function sanitize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return Settings::all();
		}

		$current = Settings::all();

		return array(
			// Matching.
			'match_mode' => in_array( $raw['match_mode'] ?? '', array( 'keyword', 'ai' ), true )
				? $raw['match_mode']
				: $current['match_mode'],

			'confidence_threshold' => max( 0, min( 100, (int) ( $raw['confidence_threshold'] ?? 70 ) ) ),

			'hierarchy_mode' => in_array( $raw['hierarchy_mode'] ?? '', array( 'all', 'primary', 'smart' ), true )
				? $raw['hierarchy_mode']
				: $current['hierarchy_mode'],

			'heading_overlap_threshold' => max( 0, min( 100, (int) ( $raw['heading_overlap_threshold'] ?? 70 ) ) ),

			'max_matches_per_heading' => max( 1, min( 10, (int) ( $raw['max_matches_per_heading'] ?? 3 ) ) ),

			'minimum_image_spacing' => max( 0, min( 5000, (int) ( $raw['minimum_image_spacing'] ?? 300 ) ) ),
			'cache_match_results_duration' => max( 0, min( 86400, (int) ( $raw['cache_match_results_duration'] ?? 3600 ) ) ),

			// Linguistics.
			'enable_stemming'          => ! empty( $raw['enable_stemming'] ),
			'enable_spelling_variants' => ! empty( $raw['enable_spelling_variants'] ),
			'whitelisted_short_words'  => sanitize_text_field( wp_unslash( $raw['whitelisted_short_words'] ?? 'io' ) ),

			// Featured Images.
			'fiaa_auto_assign_on_upload' => ! empty( $raw['fiaa_auto_assign_on_upload'] ),
			'fiaa_upload_post_types'     => $this->postTypeList( $raw['fiaa_upload_post_types'] ?? 'post,page' ),
			'fiaa_cron_enabled'          => ! empty( $raw['fiaa_cron_enabled'] ),
			'fiaa_cron_interval' => in_array( $raw['fiaa_cron_interval'] ?? 'daily', array( 'hourly', 'twicedaily', 'daily' ), true )
				? $raw['fiaa_cron_interval']
				: 'daily',
			'fiaa_cron_post_types'      => $this->postTypeList( $raw['fiaa_cron_post_types'] ?? 'post' ),
			'fiaa_cron_post_statuses'   => $this->postStatusList( $raw['fiaa_cron_post_statuses'] ?? 'publish' ),
			'fiaa_cron_featured_filter' => in_array( $raw['fiaa_cron_featured_filter'] ?? 'missing', array( 'any', 'missing', 'has' ), true )
				? $raw['fiaa_cron_featured_filter']
				: 'missing',
			'fiaa_cron_overwrite'       => ! empty( $raw['fiaa_cron_overwrite'] ),
			'fiaa_manual_post_type'       => $current['fiaa_manual_post_type'],
			'fiaa_manual_post_statuses'   => $current['fiaa_manual_post_statuses'],
			'fiaa_manual_featured_filter' => $current['fiaa_manual_featured_filter'],
			'fiaa_manual_max_posts'       => $current['fiaa_manual_max_posts'],
			'fiaa_manual_overwrite'       => $current['fiaa_manual_overwrite'],

			// Developer.
			'debug_mode'              => ! empty( $raw['debug_mode'] ),
			'delete_on_uninstall'     => ! empty( $raw['delete_on_uninstall'] ),
			// AI controls.
			'ai_alt_text_on_upload'   => ! empty( $raw['ai_alt_text_on_upload'] ),
			'ai_vision_match_enabled' => ! empty( $raw['ai_vision_match_enabled'] ),
			'ai_featured_image_enabled' => ! empty( $raw['ai_featured_image_enabled'] ),
		);
	}

	/**
	 * Sanitize a comma-separated post-type list.
	 *
	 * @since 3.0.0
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function postTypeList( $value ): string {
		if ( ! is_string( $value ) ) {
			return 'post';
		}
		$types = array_filter(
			array_map( 'sanitize_key', array_map( 'trim', explode( ',', wp_unslash( $value ) ) ) )
		);
		return implode( ',', $types ) ?: 'post';
	}

	/**
	 * Sanitize a post-status list.
	 *
	 * @since 3.0.0
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function postStatusList( $value ): string {
		if ( is_array( $value ) ) {
			$rawStatuses = $value;
		} elseif ( is_string( $value ) ) {
			$rawStatuses = explode( ',', wp_unslash( $value ) );
		} else {
			return 'publish';
		}

		return PostStatuses::toCsv( $rawStatuses );
	}

	/**
	 * Sanitize manual Run Matcher settings from a REST payload.
	 *
	 * @since 3.0.3
	 * @param array<string, mixed> $raw Raw request body.
	 * @return array<string, mixed>
	 */
	public function sanitizeManualRunSettings( array $raw ): array {
		$postType = sanitize_key( (string) ( $raw['post_type'] ?? 'post' ) );
		if ( ! post_type_exists( $postType ) || 'attachment' === $postType ) {
			$postType = 'post';
		}

		return array(
			'fiaa_manual_post_type'       => $postType,
			'fiaa_manual_post_statuses'   => $this->postStatusList( $raw['post_statuses'] ?? 'publish,draft' ),
			'fiaa_manual_featured_filter' => in_array( $raw['featured_filter'] ?? 'missing', array( 'any', 'missing', 'has' ), true )
				? $raw['featured_filter']
				: 'missing',
			'fiaa_manual_max_posts'       => max( 1, min( 50000, (int) ( $raw['max_posts'] ?? 5000 ) ) ),
			'fiaa_manual_overwrite'       => ! empty( $raw['overwrite'] ),
		);
	}
}
