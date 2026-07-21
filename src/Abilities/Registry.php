<?php
/**
 * Registers all Smart Image Matcher Abilities.
 *
 * Categories are registered first (on wp_abilities_api_categories_init),
 * then abilities (on wp_abilities_api_init) — order is required by the API.
 *
 * @package SmartImageMatcher\Abilities
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Premium;

/**
 * Class Registry
 *
 * @since 3.0.0
 */
class Registry {

	/**
	 * Register hooks.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		if ( ! class_exists( 'WP_Ability' ) ) {
			return; // Abilities API not available (WP < 6.9).
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategories' ) );
		add_action( 'wp_abilities_api_init',            array( $this, 'registerAbilities' ) );
	}

	/**
	 * Register the plugin's ability category.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerCategories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		$register_category = 'wp_register_ability_category';
		$register_category(
			'smart-image-matcher',
			array(
				'label' => __( 'Smart Image Matcher', 'smart-image-matcher' ),
			)
		);
	}

	/**
	 * Register all abilities.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerAbilities(): void {
		( new AbilityFindMatches() )->register();
		( new AbilityInsertImage() )->register();
		( new AbilityScoreImage() )->register();
		( new AbilityAssignFeaturedImage() )->register();

		// Premium-gated ability.
		if ( Premium::has( 'bulk_processor' ) ) {
			( new AbilityQueueBulkMatch() )->register();
		}
	}
}
