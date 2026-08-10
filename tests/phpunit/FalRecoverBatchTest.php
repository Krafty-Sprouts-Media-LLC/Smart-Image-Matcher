<?php
/**
 * Fal orphan-recovery unit tests.
 *
 * @package SmartImageMatcher\Tests
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Premium\AiImageGenerator;
use SmartImageMatcher\Premium\FalRecoverBatch;

/**
 * Verifies recovery parsing, payload extraction, and post matching.
 */
final class FalRecoverBatchTest extends TestCase {

	/**
	 * Request-id lists are normalized and deduplicated.
	 *
	 * @return void
	 */
	public function test_request_id_list_is_normalized(): void {
		self::assertSame(
			array( 'request-a', 'request-b', 'request-c' ),
			FalRecoverBatch::parseRequestIdList( " request-a,\nrequest-b; request-a request-c " )
		);
	}

	/**
	 * fal platform payloads expose the original prompt.
	 *
	 * @return void
	 */
	public function test_prompt_is_extracted_from_input_payload(): void {
		self::assertSame(
			'A monarch butterfly emerging from a chrysalis.',
			FalRecoverBatch::extractPromptFromFalBody(
				array(
					'prompt' => 'A monarch butterfly emerging from a chrysalis.',
				)
			)
		);
	}

	/**
	 * fal output payloads expose a sideloadable image source.
	 *
	 * @return void
	 */
	public function test_image_source_is_extracted_from_output_payload(): void {
		self::assertSame(
			array(
				'url'  => 'https://v3.fal.media/files/example/result.webp',
				'mime' => 'image/webp',
			),
			FalRecoverBatch::extractImageSourceFromFalBody(
				array(
					'images' => array(
						array(
							'url'          => 'https://v3.fal.media/files/example/result.webp',
							'content_type' => 'image/webp',
						),
					),
				)
			)
		);
	}

	/**
	 * Visual prompts select the relevant article, not an unrelated title.
	 *
	 * @return void
	 */
	public function test_prompt_matches_relevant_post_content(): void {
		$candidates = array(
			101 => array(
				'title'   => 'Monarch Butterfly Life Cycle',
				'content' => 'The caterpillar forms a green chrysalis before the adult monarch butterfly emerges in the garden.',
				'focus'   => 'monarch butterfly',
			),
			202 => array(
				'title'   => 'How to Repair a Leaking Kitchen Tap',
				'content' => 'Replace the washer and tighten the faucet fitting beneath the sink.',
				'focus'   => 'kitchen tap repair',
			),
		);
		$prompt = 'A monarch butterfly emerging from a green chrysalis on a garden leaf. Photorealistic photograph, natural lighting, sharp focus, no text, no watermark.';

		self::assertSame(
			101,
			FalRecoverBatch::matchPromptToPost( $prompt, $candidates, array(), 60 )
		);
	}

	/**
	 * Already-used posts cannot receive a second recovered image.
	 *
	 * @return void
	 */
	public function test_prompt_matching_does_not_reuse_a_post(): void {
		$candidates = array(
			101 => 'Monarch Butterfly Life Cycle',
			202 => 'Kitchen Tap Repair',
		);

		self::assertSame(
			0,
			FalRecoverBatch::matchPromptToPost(
				'Monarch Butterfly Life Cycle hero photograph',
				$candidates,
				array( 101 => true ),
				60
			)
		);
	}

	/**
	 * SEO title fluff ("Causes, Fixes, Prevention") must not dilute the score.
	 *
	 * @return void
	 */
	public function test_seo_title_boilerplate_does_not_block_match(): void {
		$title  = 'Why Are My Tomato Leaves Curling? Causes, Fixes, and Prevention';
		$prompt = 'A close-up realistic photograph of a sunlit tomato plant in a backyard garden bed, its upper leaves curled inward into tight tubes, morning light.';

		self::assertGreaterThanOrEqual(
			60,
			FalRecoverBatch::scoreTitleInPrompt( $title, $prompt )
		);
	}

	/**
	 * Rank Math / Yoast focus keywords are scored and can beat a weak title.
	 *
	 * @return void
	 */
	public function test_focus_keyword_is_factored_into_match_score(): void {
		$prompt = 'A close-up realistic photograph of a blueberry bush branch with green leaves showing distinct yellow chlorosis between the veins.';

		self::assertGreaterThanOrEqual(
			60,
			FalRecoverBatch::scorePostAgainstPrompt(
				'Plant Care Tips for Beginners: Causes and Fixes',
				'',
				$prompt,
				'blueberry leaves yellow'
			)
		);
	}

	/**
	 * Generic composition language must not match an unrelated article title.
	 *
	 * @return void
	 */
	public function test_generic_prompt_words_do_not_create_false_title_match(): void {
		$prompt = 'Wilting pansies in a garden backdrop fading into the upper frame.';

		self::assertLessThan(
			60,
			FalRecoverBatch::scoreTitleInPrompt( 'The Fading Brace-face Fad', $prompt )
		);
	}

	/**
	 * Photography perspective terms must not match an eye-health article.
	 *
	 * @return void
	 */
	public function test_eye_level_prompt_does_not_match_eye_health_article(): void {
		$prompt = 'A dusty snake plant shot at eye level in a bright room.';

		self::assertLessThan(
			60,
			FalRecoverBatch::scorePostAgainstPrompt(
				'What Is Dry Eye and How to Treat It?',
				'',
				$prompt,
				'dry eye'
			)
		);
	}

	/**
	 * Confirmed matches are persisted before one recovery job is queued.
	 *
	 * @return void
	 */
	public function test_confirmed_match_is_queued_for_background_recovery(): void {
		$GLOBALS['sim_test_as_enqueued'] = array();
		$GLOBALS['sim_test_post_meta']   = array();
		$GLOBALS['sim_test_transients']  = array();

		$result = FalRecoverBatch::queueMatched(
			array(
				array(
					'request_id' => 'request-123',
					'model_id'   => 'bytedance/seedream/v5/pro/text-to-image',
					'prompt'     => 'A sweet potato on a wooden table.',
					'source'     => array(
						'url'  => 'https://v3.fal.media/files/example/result.webp',
						'mime' => 'image/webp',
					),
				),
			),
			array(
				array(
					'request_id' => 'request-123',
					'post_id'    => 101,
				),
			)
		);

		self::assertCount( 1, $result['jobs'] );
		self::assertSame( 101, $result['jobs'][0]['post_id'] );
		self::assertSame(
			'smart_image_matcher_queue_fal_recover',
			$GLOBALS['sim_test_as_enqueued'][0]['hook']
		);
		self::assertSame(
			'request-123',
			$GLOBALS['sim_test_post_meta'][101][ AiImageGenerator::falPendingMetaKey( 'featured' ) ]['fal']['request_id']
		);
	}
}
