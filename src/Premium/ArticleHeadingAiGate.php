<?php
/**
 * AI heading matcher for ArticleProcessor when a text provider is connected.
 *
 * Keyword scoring only builds the candidate shortlist. Insert / review uses
 * the model score. A failed or empty AI response does not fall back to
 * keyword auto-insert.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.4.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\Matcher as AiMatcher;
use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Domain\HeadingMatchGate;
use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Settings\Settings;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Class ArticleHeadingAiGate
 *
 * @since 3.4.0
 */
class ArticleHeadingAiGate implements HeadingMatchGate {

	/**
	 * Candidate lookup shared with the keyword shortlist.
	 *
	 * @var ImageRepository
	 */
	private ImageRepository $images;

	/**
	 * Constructor.
	 *
	 * @since 3.4.0
	 * @param ImageRepository $images Image repository.
	 */
	public function __construct( ImageRepository $images ) {
		$this->images = $images;
	}

	/**
	 * Whether a text provider can run matching.
	 *
	 * @since 3.4.0
	 * @return bool
	 */
	public function isAvailable(): bool {
		return ProviderBridge::isAvailable();
	}

	/**
	 * AI-ranked best match, or zeros when the model rejects every candidate.
	 *
	 * @since 3.4.0
	 * @param array<string, mixed> $heading Heading descriptor.
	 * @return array{score:int,image_id:int}
	 */
	public function bestMatch( array $heading ): array {
		$threshold = (int) Settings::get( 'confidence_threshold' );
		$ai        = new AiMatcher();
		$results   = $ai->findMatches( $heading, $this->images, $threshold, false );

		if ( is_wp_error( $results ) ) {
			Logger::warn(
				'ArticleHeadingAiGate: AI match failed; not inserting from keywords',
				array(
					'heading' => (string) ( $heading['text'] ?? '' ),
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
