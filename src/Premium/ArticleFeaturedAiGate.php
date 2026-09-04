<?php
/**
 * AI featured-image matcher for ArticleProcessor when a text provider is connected.
 *
 * Slug / keyword scoring only builds the candidate shortlist. Assign / review
 * uses the model score. A failed or empty AI response does not fall back to
 * a slug auto-assign.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.4.1
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\Matcher as AiMatcher;
use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Domain\FeaturedMatchGate;
use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Settings\Settings;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Class ArticleFeaturedAiGate
 *
 * @since 3.4.1
 */
class ArticleFeaturedAiGate implements FeaturedMatchGate {

	/**
	 * Candidate lookup shared with the keyword shortlist.
	 *
	 * @var ImageRepository
	 */
	private ImageRepository $images;

	/**
	 * Constructor.
	 *
	 * @since 3.4.1
	 * @param ImageRepository $images Image repository.
	 */
	public function __construct( ImageRepository $images ) {
		$this->images = $images;
	}

	/**
	 * Whether a text provider can run matching.
	 *
	 * @since 3.4.1
	 * @return bool
	 */
	public function isAvailable(): bool {
		return ProviderBridge::isAvailable();
	}

	/**
	 * AI-ranked best featured match, or zeros when the model rejects every candidate.
	 *
	 * @since 3.4.1
	 * @param \WP_Post $post Post.
	 * @return array{score:int,image_id:int}
	 */
	public function bestMatch( \WP_Post $post ): array {
		$threshold = (int) Settings::get( 'confidence_threshold' );
		$ai        = new AiMatcher();
		$results   = $ai->findFeaturedMatches( $post, $this->images, $threshold, false );

		if ( is_wp_error( $results ) ) {
			Logger::warn(
				'ArticleFeaturedAiGate: AI match failed; not assigning from slug score',
				array(
					'post_id' => (int) $post->ID,
					'error'   => $results->get_error_message(),
				)
			);
			return array(
				'score'    => 0,
				'image_id' => 0,
			);
		}

		if ( empty( $results ) ) {
			return array(
				'score'    => 0,
				'image_id' => 0,
			);
		}

		$top = $results[0];
		return array(
			'score'    => (int) ( $top['confidence_score'] ?? 0 ),
			'image_id' => (int) ( $top['image_id'] ?? 0 ),
		);
	}
}
