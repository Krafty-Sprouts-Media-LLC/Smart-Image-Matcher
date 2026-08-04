<?php
/**
 * Curated fal image models selectable in SIM settings.
 *
 * @package SmartImageMatcher\AI
 * @since   3.1.2
 */

declare( strict_types=1 );

namespace SmartImageMatcher\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImageModelCatalog
 *
 * @since 3.1.2
 */
class ImageModelCatalog {

	/**
	 * Default preferred image model ID.
	 *
	 * @since 3.1.2
	 * @var string
	 */
	const DEFAULT_MODEL_ID = 'bytedance/seedream/v5/pro/text-to-image';

	/**
	 * Stable fallback order (preferred is moved to front by preferenceList()).
	 *
	 * @since 3.1.2
	 * @return list<string>
	 */
	public static function fallbackOrder(): array {
		return array(
			'bytedance/seedream/v5/pro/text-to-image',
			'fal-ai/nano-banana-2',
			'fal-ai/nano-banana-pro',
			'openai/gpt-image-2',
		);
	}

	/**
	 * Allowed model IDs.
	 *
	 * @since 3.1.2
	 * @return list<string>
	 */
	public static function allowedIds(): array {
		return self::fallbackOrder();
	}

	/**
	 * Model ID => admin label.
	 *
	 * @since 3.1.2
	 * @return array<string, string>
	 */
	public static function choices(): array {
		return array(
			'bytedance/seedream/v5/pro/text-to-image' => __( 'Seedream 5.0 Pro', 'smart-image-matcher' ),
			'openai/gpt-image-2'                      => __( 'GPT Image 2', 'smart-image-matcher' ),
			'fal-ai/nano-banana-pro'                  => __( 'Nano Banana Pro', 'smart-image-matcher' ),
			'fal-ai/nano-banana-2'                    => __( 'Nano Banana 2', 'smart-image-matcher' ),
		);
	}

	/**
	 * Whether a model ID is in the curated allow-list.
	 *
	 * @since 3.1.2
	 * @param string $id Model ID.
	 * @return bool
	 */
	public static function isAllowed( string $id ): bool {
		return in_array( $id, self::allowedIds(), true );
	}

	/**
	 * Preferred first, then remaining fallback IDs (no duplicates).
	 *
	 * @since 3.1.2
	 * @param string $preferred Preferred model ID.
	 * @return list<string>
	 */
	public static function preferenceList( string $preferred ): array {
		if ( ! self::isAllowed( $preferred ) ) {
			$preferred = self::DEFAULT_MODEL_ID;
		}

		$list = array( $preferred );
		foreach ( self::fallbackOrder() as $id ) {
			if ( $id !== $preferred ) {
				$list[] = $id;
			}
		}

		/**
		 * Filters the ordered image model preference list for ProviderBridge.
		 *
		 * @since 3.1.2
		 * @param list<string> $list      Preference list.
		 * @param string       $preferred Preferred model ID.
		 */
		$filtered = apply_filters( 'sim_ai_image_model_preferences', $list, $preferred );

		if ( ! is_array( $filtered ) || empty( $filtered ) ) {
			return $list;
		}

		$out = array();
		foreach ( $filtered as $id ) {
			$id = is_string( $id ) ? $id : '';
			if ( '' !== $id && self::isAllowed( $id ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}

		return ! empty( $out ) ? $out : $list;
	}
}
