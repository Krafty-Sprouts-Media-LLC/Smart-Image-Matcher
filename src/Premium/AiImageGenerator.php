<?php
/**
 * On-demand AI image generation for headings and featured-image fallback.
 *
 * Builds a visual brief via PromptBuilder, generates via ProviderBridge,
 * sideloads into the media library, and records match metadata.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.1.1
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\GenerationRejectionStore;
use SmartImageMatcher\AI\PromptBuilder;
use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Domain\MatchRepository;
use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Settings\Settings;

/**
 * Class AiImageGenerator
 *
 * @since 3.1.1
 */
class AiImageGenerator {

	/**
	 * Transient prefix for modal poll status.
	 *
	 * @since 3.1.1
	 * @var string
	 */
	public const STATUS_TRANSIENT_PREFIX = 'smart_image_matcher_img_gen_';

	/**
	 * Transient prefix for AS job payloads (stable post+hash key).
	 *
	 * @since 3.2.18
	 * @var string
	 */
	public const JOB_PAYLOAD_TRANSIENT_PREFIX = 'smart_image_matcher_img_gen_job_';

	/**
	 * In-flight statuses that should block a second enqueue.
	 *
	 * @since 3.2.18
	 * @var list<string>
	 */
	private const IN_FLIGHT_STATUSES = array( 'queued', 'processing', 'submitted' );

	/**
	 * Cache group key for generated results (30 days).
	 *
	 * @since 3.1.1
	 * @var int
	 */
	private const RESULT_CACHE_TTL = MONTH_IN_SECONDS;

	/**
	 * Prompt builder.
	 *
	 * @since 3.1.1
	 * @var PromptBuilder
	 */
	private PromptBuilder $prompt_builder;

	/**
	 * Constructor.
	 *
	 * @since 3.1.1
	 * @param PromptBuilder|null $prompt_builder Optional builder instance.
	 */
	public function __construct( ?PromptBuilder $prompt_builder = null ) {
		$this->prompt_builder = $prompt_builder ?? new PromptBuilder();
	}

	/**
	 * Generate an image for a specific heading and return the attachment ID.
	 *
	 * @since 3.1.1
	 * @param string $heading_hash  Stable heading identifier.
	 * @param string $heading_text  Heading text.
	 * @param string $section_text  Surrounding paragraph text.
	 * @param int    $post_id       Post ID.
	 * @param string $focus_keyword Optional SEO focus keyphrase.
	 * @param string $style         photo|illustration.
	 * @param bool   $force         Bypass dedup / result cache.
	 * @return int|\WP_Error Attachment ID or error.
	 */
	public function generateForHeading(
		string $heading_hash,
		string $heading_text,
		string $section_text,
		int $post_id,
		string $focus_keyword = '',
		string $style = 'photo',
		bool $force = false
	) {
		$prepared = $this->prepareGenerationInputs(
			$heading_hash,
			$heading_text,
			$section_text,
			$post_id,
			$focus_keyword,
			$style,
			$force
		);
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( isset( $prepared['attachment_id'] ) ) {
			return (int) $prepared['attachment_id'];
		}

		$result = ProviderBridge::generateImage( $prepared['image_prompt'], $prepared['purpose'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$source = $this->extractImageSource( $result );
		return $this->finalizeGeneratedImage(
			$source,
			(string) $prepared['heading_hash'],
			(string) $prepared['heading_text'],
			(string) $prepared['section_text'],
			(int) $prepared['post_id'],
			(string) $prepared['focus_keyword'],
			(string) $prepared['style'],
			(string) $prepared['purpose'],
			(string) $prepared['brief'],
			(string) $prepared['image_prompt'],
			(string) $prepared['taxonomy_hint']
		);
	}

	/**
	 * Build brief + submit to fal asynchronously (no long poll in this request).
	 *
	 * @since 3.2.18
	 * @param string $heading_hash  Heading hash.
	 * @param string $heading_text  Heading text.
	 * @param string $section_text  Section excerpt.
	 * @param int    $post_id       Post ID.
	 * @param string $focus_keyword Focus keyword.
	 * @param string $style         photo|illustration.
	 * @param bool   $force         Bypass cache/dedup.
	 * @return array{fal:array<string,string>,context:array<string,mixed>}|int|\WP_Error Attachment if cache hit, submit bundle, or error.
	 */
	public function submitForHeading(
		string $heading_hash,
		string $heading_text,
		string $section_text,
		int $post_id,
		string $focus_keyword = '',
		string $style = 'photo',
		bool $force = false
	) {
		$prepared = $this->prepareGenerationInputs(
			$heading_hash,
			$heading_text,
			$section_text,
			$post_id,
			$focus_keyword,
			$style,
			$force
		);
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( isset( $prepared['attachment_id'] ) ) {
			return (int) $prepared['attachment_id'];
		}

		$fal = ProviderBridge::submitImage( $prepared['image_prompt'], $prepared['purpose'] );
		if ( is_wp_error( $fal ) ) {
			return $fal;
		}

		return array(
			'fal'     => $fal,
			'context' => $prepared,
		);
	}

	/**
	 * Sideload + meta after an async fal result.
	 *
	 * @since 3.2.18
	 * @param array{url?:string,binary?:string,mime?:string} $source  Image source.
	 * @param array<string, mixed>                           $context From submitForHeading.
	 * @return int|\WP_Error
	 */
	public function finalizeFromSource( array $source, array $context ) {
		return $this->finalizeGeneratedImage(
			$source,
			(string) ( $context['heading_hash'] ?? '' ),
			(string) ( $context['heading_text'] ?? '' ),
			(string) ( $context['section_text'] ?? '' ),
			(int) ( $context['post_id'] ?? 0 ),
			(string) ( $context['focus_keyword'] ?? '' ),
			(string) ( $context['style'] ?? 'photo' ),
			(string) ( $context['purpose'] ?? 'heading' ),
			(string) ( $context['brief'] ?? '' ),
			(string) ( $context['image_prompt'] ?? '' ),
			(string) ( $context['taxonomy_hint'] ?? '' )
		);
	}

	/**
	 * Shared preflight + brief for sync and async paths.
	 *
	 * @since 3.2.18
	 * @param string $heading_hash  Heading hash.
	 * @param string $heading_text  Heading text.
	 * @param string $section_text  Section excerpt.
	 * @param int    $post_id       Post ID.
	 * @param string $focus_keyword Focus keyword.
	 * @param string $style         Style.
	 * @param bool   $force         Force.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function prepareGenerationInputs(
		string $heading_hash,
		string $heading_text,
		string $section_text,
		int $post_id,
		string $focus_keyword,
		string $style,
		bool $force
	) {
		if ( ! ProviderBridge::isImageGenerationAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_ai_image_unavailable',
				__( 'No image-capable AI provider configured for the preferred models. Connect fal.ai under Settings → Connectors.', 'smart-image-matcher' )
			);
		}

		$style = ( 'illustration' === $style ) ? 'illustration' : 'photo';

		if ( '' === $focus_keyword ) {
			$focus_keyword = PromptBuilder::getFocusKeyword( $post_id );
		}

		if ( ! $force && GenerationRejectionStore::isBlocked( $post_id, $heading_hash, $focus_keyword, $style ) ) {
			return new \WP_Error(
				'smart_image_matcher_generation_blocked',
				__( 'Generation was previously rejected for this heading. Use Regenerate to try again.', 'smart-image-matcher' )
			);
		}

		if ( ! $force ) {
			$existing = $this->findGenerated( $post_id, $heading_hash, $focus_keyword, $style );
			if ( $existing ) {
				return array( 'attachment_id' => $existing );
			}
		} else {
			delete_transient( $this->resultCacheKey( $heading_hash, $focus_keyword, $style ) );
		}

		$gate_subject = $focus_keyword ? $focus_keyword : $heading_text;
		if ( Settings::get( 'ai_image_subject_gate' ) ) {
			$ok = $this->prompt_builder->isGeneratableSubject( $gate_subject );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			if ( ! $ok ) {
				return new \WP_Error(
					'smart_image_matcher_ungeneratable',
					__( 'AI cannot reliably generate images for product-specific or branded subjects. Try uploading a photo to the media library instead.', 'smart-image-matcher' )
				);
			}
		}

		$purpose       = ( 'featured' === $heading_hash ) ? 'featured' : 'heading';
		$taxonomy_hint = PromptBuilder::buildTaxonomyHint( $post_id );

		if ( 'featured' === $purpose ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				$section_text = PromptBuilder::buildPostContext( $post );
			}
		}

		$brief = $this->prompt_builder->buildImagePrompt(
			$heading_text,
			$focus_keyword,
			$section_text,
			$style,
			$purpose,
			$taxonomy_hint
		);

		if ( is_wp_error( $brief ) ) {
			return $brief;
		}

		$image_prompt = $this->prompt_builder->composeImageModelPrompt( $brief, $style, $purpose );

		return array(
			'heading_hash'  => $heading_hash,
			'heading_text'  => $heading_text,
			'section_text'  => $section_text,
			'post_id'       => $post_id,
			'focus_keyword' => $focus_keyword,
			'style'         => $style,
			'purpose'       => $purpose,
			'taxonomy_hint' => $taxonomy_hint,
			'brief'         => $brief,
			'image_prompt'  => $image_prompt,
		);
	}

	/**
	 * Sideload, meta, vision, match row — shared by sync and async.
	 *
	 * @since 3.2.18
	 * @param array{url?:string,binary?:string,mime?:string} $source        Image source.
	 * @param string                                         $heading_hash  Hash.
	 * @param string                                         $heading_text  Text.
	 * @param string                                         $section_text  Section.
	 * @param int                                            $post_id       Post ID.
	 * @param string                                         $focus_keyword Keyword.
	 * @param string                                         $style         Style.
	 * @param string                                         $purpose       Purpose.
	 * @param string                                         $brief         Brief.
	 * @param string                                         $image_prompt  Prompt.
	 * @param string                                         $taxonomy_hint Topics.
	 * @return int|\WP_Error
	 */
	private function finalizeGeneratedImage(
		array $source,
		string $heading_hash,
		string $heading_text,
		string $section_text,
		int $post_id,
		string $focus_keyword,
		string $style,
		string $purpose,
		string $brief,
		string $image_prompt,
		string $taxonomy_hint
	) {
		if ( empty( $source['url'] ) && empty( $source['binary'] ) ) {
			return new \WP_Error(
				'smart_image_matcher_no_image_url',
				__( 'Image generation returned no usable image data (expected a remote URL or inline image bytes).', 'smart-image-matcher' )
			);
		}

		if ( '' === $focus_keyword && $post_id > 0 ) {
			$focus_keyword = PromptBuilder::getFocusKeyword( $post_id );
		}

		$title = $this->resolveTitle( $focus_keyword, $heading_text );
		$attachment_id = $this->sideloadGeneratedImage( $source, $post_id, $title );

		if ( ! $attachment_id ) {
			return new \WP_Error(
				'smart_image_matcher_sideload_failed',
				__( 'Failed to save the generated image to the media library.', 'smart-image-matcher' )
			);
		}

		$dimensions = $this->recordGeneratedDimensions( $attachment_id, $purpose );

		$alt = $this->resolveAlt( $title, $brief, $focus_keyword );
		$this->writeAttachmentMeta(
			$attachment_id,
			array(
				'prompt'       => $image_prompt,
				'keyword'      => $focus_keyword,
				'heading_hash' => $heading_hash,
				'post_id'      => $post_id,
				'style'        => $style,
				'title'        => $title,
				'alt'          => $alt,
				'input'        => wp_json_encode(
					array(
						'title'     => $heading_text,
						'keyword'   => $focus_keyword,
						'excerpt'   => $section_text,
						'heading'   => $heading_text,
						'purpose'   => $purpose,
						'topics'    => $taxonomy_hint,
						'brief'     => $brief,
						'width'     => $dimensions['width'],
						'height'    => $dimensions['height'],
						'cost_hint' => $dimensions['cost_hint'],
					)
				),
			)
		);

		$vision_subject = $focus_keyword ? $focus_keyword : $heading_text;
		$vision_failed  = false;
		$vision_reason  = '';

		if ( Settings::get( 'ai_image_verify_vision' ) ) {
			$image_url = (string) wp_get_attachment_url( $attachment_id );
			if ( '' !== $image_url ) {
				$vision_raw = ProviderBridge::scoreImageWithVision( $image_url, $vision_subject );
				$vision_score = $this->parseVisionScore( $vision_raw, $vision_reason );

				if ( is_wp_error( $vision_score ) || $vision_score < 50 ) {
					$vision_failed = true;
					update_post_meta( $attachment_id, '_sim_generated_vision_failed', '1' );
					if ( is_wp_error( $vision_score ) ) {
						$vision_reason = $vision_score->get_error_message();
					}
				}
			}
		}

		if ( $vision_failed ) {
			$this->recordMatch(
				$post_id,
				$heading_hash,
				$heading_text,
				$attachment_id,
				$brief,
				$focus_keyword,
				$style,
				'pending',
				50,
				'ai_generated_vision_fail',
				$vision_reason
			);
		} else {
			$this->recordMatch( $post_id, $heading_hash, $heading_text, $attachment_id, $brief, $focus_keyword, $style );
		}

		set_transient(
			$this->resultCacheKey( $heading_hash, $focus_keyword, $style ),
			$attachment_id,
			self::RESULT_CACHE_TTL
		);

		Logger::info(
			'AiImageGenerator: generated for heading',
			array(
				'post_id'       => $post_id,
				'heading_hash'  => $heading_hash,
				'attachment_id' => $attachment_id,
			)
		);

		return $attachment_id;
	}

	/**
	 * Generate a featured image for a post (FIAA fallback).
	 *
	 * @since 3.1.1
	 * @param int $post_id Post ID.
	 * @return int|\WP_Error Attachment ID or error.
	 */
	public function generateFeaturedForPost( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'smart_image_matcher_post_not_found',
				__( 'Post not found.', 'smart-image-matcher' )
			);
		}

		$focus   = PromptBuilder::getFocusKeyword( $post_id );
		$excerpt = PromptBuilder::buildPostContext( $post );
		$style = (string) Settings::get( 'ai_image_style' );
		if ( 'illustration' !== $style ) {
			$style = 'photo';
		}

		$attachment_id = $this->generateForHeading(
			'featured',
			$post->post_title,
			$excerpt,
			$post_id,
			$focus,
			$style,
			false
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		if ( ! get_post_meta( $attachment_id, '_sim_generated_vision_failed', true ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		return $attachment_id;
	}

	/**
	 * Find an existing generated attachment for this combo.
	 *
	 * @since 3.1.1
	 * @param int    $post_id       Post ID.
	 * @param string $heading_hash  Heading hash.
	 * @param string $focus_keyword Focus keyword.
	 * @param string $style         Style.
	 * @return int|null Attachment ID or null.
	 */
	public function findGenerated( int $post_id, string $heading_hash, string $focus_keyword, string $style ): ?int {
		$cached = get_transient( $this->resultCacheKey( $heading_hash, $focus_keyword, $style ) );
		if ( $cached ) {
			$id = (int) $cached;
			if ( $id > 0 && get_post( $id ) ) {
				return $id;
			}
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_sim_generated',
						'value' => '1',
					),
					array(
						'key'   => '_sim_generated_post_id',
						'value' => (string) $post_id,
					),
					array(
						'key'   => '_sim_generated_heading_hash',
						'value' => $heading_hash,
					),
					array(
						'key'   => '_sim_generated_style',
						'value' => $style,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		return (int) $query->posts[0];
	}

	/**
	 * Status transient key for modal polling.
	 *
	 * @since 3.1.1
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @return string
	 */
	public static function statusTransientKey( int $post_id, string $heading_hash ): string {
		return self::STATUS_TRANSIENT_PREFIX . $post_id . '_' . md5( $heading_hash );
	}

	/**
	 * Write a status payload for the modal poller.
	 *
	 * @since 3.1.1
	 * @param int                  $post_id      Post ID.
	 * @param string               $heading_hash Heading hash.
	 * @param array<string, mixed> $payload      Status data.
	 * @return void
	 */
	public static function setStatus( int $post_id, string $heading_hash, array $payload ): void {
		set_transient( self::statusTransientKey( $post_id, $heading_hash ), $payload, HOUR_IN_SECONDS );
	}

	/**
	 * Read status payload.
	 *
	 * @since 3.1.1
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @return array<string, mixed>|null
	 */
	public static function getStatus( int $post_id, string $heading_hash ): ?array {
		$data = get_transient( self::statusTransientKey( $post_id, $heading_hash ) );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Whether a generation for this post+heading is already queued or running.
	 *
	 * @since 3.2.18
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @return bool
	 */
	public static function isInFlight( int $post_id, string $heading_hash ): bool {
		$status = self::getStatus( $post_id, $heading_hash );
		if ( is_array( $status ) ) {
			$state = isset( $status['status'] ) ? (string) $status['status'] : '';
			if ( in_array( $state, self::IN_FLIGHT_STATUSES, true ) ) {
				return true;
			}
		}

		return \SmartImageMatcher\Queue\Queue::hasPendingAiImageGen( $post_id, $heading_hash );
	}

	/**
	 * Transient key for the full AS job payload.
	 *
	 * @since 3.2.18
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @return string
	 */
	public static function jobPayloadTransientKey( int $post_id, string $heading_hash ): string {
		return self::JOB_PAYLOAD_TRANSIENT_PREFIX . $post_id . '_' . md5( $heading_hash );
	}

	/**
	 * Persist job args for the worker (stable AS args are only post_id + hash).
	 *
	 * @since 3.2.18
	 * @param int                  $post_id      Post ID.
	 * @param string               $heading_hash Heading hash.
	 * @param array<string, mixed> $payload      Job payload.
	 * @return void
	 */
	public static function storeJobPayload( int $post_id, string $heading_hash, array $payload ): void {
		set_transient( self::jobPayloadTransientKey( $post_id, $heading_hash ), $payload, HOUR_IN_SECONDS );
	}

	/**
	 * Load job args for the worker.
	 *
	 * @since 3.2.18
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @return array<string, mixed>|null
	 */
	public static function getJobPayload( int $post_id, string $heading_hash ): ?array {
		$data = get_transient( self::jobPayloadTransientKey( $post_id, $heading_hash ) );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Clear stored job payload after completion/failure.
	 *
	 * @since 3.2.18
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @return void
	 */
	public static function clearJobPayload( int $post_id, string $heading_hash ): void {
		delete_transient( self::jobPayloadTransientKey( $post_id, $heading_hash ) );
	}

	/**
	 * Durable post-meta key for in-flight fal queue handles (survives transient loss).
	 *
	 * @since 3.2.21
	 * @param string $heading_hash Heading hash.
	 * @return string
	 */
	public static function falPendingMetaKey( string $heading_hash ): string {
		return '_sim_fal_pending_' . md5( $heading_hash );
	}

	/**
	 * Store fal submit handles + generation context on the post.
	 *
	 * @since 3.2.21
	 * @param int                  $post_id      Post ID.
	 * @param string               $heading_hash Heading hash.
	 * @param array<string, mixed> $payload      fal + context + submitted_at.
	 * @return void
	 */
	public static function storeFalPending( int $post_id, string $heading_hash, array $payload ): void {
		update_post_meta( $post_id, self::falPendingMetaKey( $heading_hash ), $payload );
	}

	/**
	 * Read durable fal pending payload.
	 *
	 * @since 3.2.21
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @return array<string, mixed>|null
	 */
	public static function getFalPending( int $post_id, string $heading_hash ): ?array {
		$data = get_post_meta( $post_id, self::falPendingMetaKey( $heading_hash ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Clear durable fal pending payload after success or manual discard.
	 *
	 * @since 3.2.21
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Heading hash.
	 * @return void
	 */
	public static function clearFalPending( int $post_id, string $heading_hash ): void {
		delete_post_meta( $post_id, self::falPendingMetaKey( $heading_hash ) );
	}

	/**
	 * Find posts with durable fal pending meta (featured hash only by default).
	 *
	 * @since 3.2.21
	 * @param string $heading_hash Heading hash to scan.
	 * @param int    $limit        Max posts.
	 * @return list<array{post_id:int,heading_hash:string,pending:array<string,mixed>}>
	 */
	public static function listFalPending( string $heading_hash = 'featured', int $limit = 200 ): array {
		$meta_key = self::falPendingMetaKey( $heading_hash );
		$query    = new \WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => max( 1, min( 500, $limit ) ),
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => $meta_key,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$out = array();
		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			$pending = self::getFalPending( $post_id, $heading_hash );
			if ( ! is_array( $pending ) ) {
				continue;
			}
			$out[] = array(
				'post_id'      => $post_id,
				'heading_hash' => $heading_hash,
				'pending'      => $pending,
			);
		}

		return $out;
	}

	/**
	 * Fetch a completed fal job and sideload it for a post.
	 *
	 * @since 3.2.21
	 * @param int                  $post_id      Post ID.
	 * @param string               $heading_hash Heading hash.
	 * @param array<string, mixed> $pending      Pending payload (fal + context).
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function recoverFalJob( int $post_id, string $heading_hash, array $pending ) {
		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'smart_image_matcher_recover_invalid_post',
				__( 'Invalid post for fal recovery.', 'smart-image-matcher' )
			);
		}

		// Action Scheduler / cron workers have no logged-in user. Capability was
		// already checked when the recovery job was queued from the admin UI.
		$user_id = get_current_user_id();
		if ( $user_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'smart_image_matcher_recover_forbidden',
				__( 'Permission denied for fal recovery.', 'smart-image-matcher' )
			);
		}

		$fal = isset( $pending['fal'] ) && is_array( $pending['fal'] ) ? $pending['fal'] : array();
		$request_id   = (string) ( $fal['request_id'] ?? '' );
		$response_url = (string) ( $fal['response_url'] ?? '' );
		$model_id     = (string) ( $fal['model_id'] ?? '' );

		if ( '' !== $request_id ) {
			$existing = get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'meta_key'               => '_sim_fal_request_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'             => $request_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( ! empty( $existing[0] ) ) {
				$attachment_id = (int) $existing[0];
				if ( 'featured' === $heading_hash && ! has_post_thumbnail( $post_id ) ) {
					set_post_thumbnail( $post_id, $attachment_id );
				}
				self::clearFalPending( $post_id, $heading_hash );
				self::setStatus(
					$post_id,
					$heading_hash,
					array(
						'status'         => 'done',
						'attachment_id'  => $attachment_id,
						'attachment_url' => (string) wp_get_attachment_url( $attachment_id ),
						'recovered'      => true,
					)
				);
				return $attachment_id;
			}
		}

		$source = isset( $pending['source'] ) && is_array( $pending['source'] )
			? $pending['source']
			: null;
		if ( null === $source && '' !== $response_url ) {
			$source = ProviderBridge::fetchImageSource( $response_url );
		}
		if ( ( null === $source || is_wp_error( $source ) ) && '' !== $model_id && '' !== $request_id ) {
			$source = ProviderBridge::fetchImageByRequestId( $model_id, $request_id );
		}
		if ( null === $source || is_wp_error( $source ) ) {
			return is_wp_error( $source )
				? $source
				: new \WP_Error(
					'smart_image_matcher_recover_no_source',
					__( 'Could not fetch fal image. Need response_url or model_id + request_id.', 'smart-image-matcher' )
				);
		}

		$context = isset( $pending['context'] ) && is_array( $pending['context'] ) ? $pending['context'] : array();
		if ( empty( $context['post_id'] ) ) {
			$context['post_id'] = $post_id;
		}
		if ( empty( $context['heading_hash'] ) ) {
			$context['heading_hash'] = $heading_hash;
		}
		if ( empty( $context['heading_text'] ) ) {
			$context['heading_text'] = (string) get_the_title( $post_id );
		}
		if ( empty( $context['purpose'] ) ) {
			$context['purpose'] = ( 'featured' === $heading_hash ) ? 'featured' : 'heading';
		}
		if ( empty( $context['style'] ) ) {
			$context['style'] = 'photo';
		}
		if ( empty( $context['image_prompt'] ) ) {
			$context['image_prompt'] = (string) ( $context['brief'] ?? $context['heading_text'] );
		}
		if ( empty( $context['brief'] ) ) {
			$context['brief'] = (string) $context['image_prompt'];
		}
		if ( empty( $context['focus_keyword'] ) ) {
			$context['focus_keyword'] = PromptBuilder::getFocusKeyword( $post_id );
		}

		$generator = new self();
		$result    = $generator->finalizeFromSource( $source, $context );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$attachment_id = (int) $result;
		if ( '' !== $request_id ) {
			update_post_meta( $attachment_id, '_sim_fal_request_id', $request_id );
		}
		if ( '' !== $model_id ) {
			update_post_meta( $attachment_id, '_sim_fal_model_id', $model_id );
		}
		if ( 'featured' === $heading_hash && ! get_post_meta( $attachment_id, '_sim_generated_vision_failed', true ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		self::clearFalPending( $post_id, $heading_hash );
		self::setStatus(
			$post_id,
			$heading_hash,
			array(
				'status'         => 'done',
				'attachment_id'  => $attachment_id,
				'attachment_url' => (string) wp_get_attachment_url( $attachment_id ),
				'recovered'      => true,
			)
		);

		Logger::info(
			'AiImageGenerator: recovered fal job',
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'request_id'    => $request_id,
			)
		);

		return $attachment_id;
	}

	/**
	 * Sideload from a remote URL or inline binary payload.
	 *
	 * @since 3.1.3
	 * @param array{url?:string,binary?:string,mime?:string} $source Image source.
	 * @param int                                            $post_id Parent post.
	 * @param string                                         $title   Attachment title.
	 * @return int|null
	 */
	private function sideloadGeneratedImage( array $source, int $post_id, string $title ): ?int {
		if ( ! empty( $source['url'] ) && 0 === strpos( (string) $source['url'], 'http' ) ) {
			return $this->sideloadImage( (string) $source['url'], $post_id, $title );
		}

		if ( empty( $source['binary'] ) ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$mime = ! empty( $source['mime'] ) ? (string) $source['mime'] : 'image/jpeg';
		$ext  = ( false !== strpos( $mime, 'png' ) ) ? 'png' : ( ( false !== strpos( $mime, 'webp' ) ) ? 'webp' : 'jpg' );
		$tmp  = wp_tempnam( 'sim-ai-' . $ext );
		if ( ! $tmp ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp upload for media_handle_sideload.
		if ( false === file_put_contents( $tmp, $source['binary'] ) ) {
			return null;
		}

		$file_array = array(
			'name'     => sanitize_file_name( $title . '.' . $ext ),
			'tmp_name' => $tmp,
			'type'     => $mime,
		);

		$id = media_handle_sideload( $file_array, $post_id, $title );
		if ( is_wp_error( $id ) ) {
			Logger::error( 'AiImageGenerator: binary sideload failed', array( 'error' => $id->get_error_message() ) );
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return null;
		}

		$attachment_id = (int) $id;
		wp_update_post(
			array(
				'ID'         => $attachment_id,
				'post_title' => $title,
			)
		);
		$this->assignAttachmentAuthor( $attachment_id, $post_id );

		return $attachment_id;
	}

	/**
	 * Sideload a remote image URL into the media library.
	 *
	 * Always names the file from $title — never keep fal’s CDN basename
	 * (which can include size suffixes like -2048x1152).
	 *
	 * @since 3.1.1
	 * @param string $url     Remote URL.
	 * @param int    $post_id Parent post.
	 * @param string $title   Attachment title.
	 * @return int|null
	 */
	private function sideloadImage( string $url, int $post_id, string $title ): ?int {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			Logger::error( 'AiImageGenerator: download failed', array( 'error' => $tmp->get_error_message() ) );
			return null;
		}

		$path_ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) );
		$ext      = in_array( $path_ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true )
			? ( 'jpeg' === $path_ext ? 'jpg' : $path_ext )
			: 'jpg';

		$file_array = array(
			'name'     => sanitize_file_name( $title . '.' . $ext ),
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload( $file_array, $post_id, $title );
		if ( is_wp_error( $id ) ) {
			Logger::error( 'AiImageGenerator: sideload failed', array( 'error' => $id->get_error_message() ) );
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return null;
		}

		$attachment_id = (int) $id;

		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_title'   => $title,
				'post_content' => '',
			)
		);
		$this->assignAttachmentAuthor( $attachment_id, $post_id );

		return $attachment_id;
	}

	/**
	 * Assign attachment author (background jobs often have no current user).
	 *
	 * Prefers the parent post author, then the current user when available.
	 *
	 * @since 3.2.13
	 * @param int $attachment_id Attachment ID.
	 * @param int $post_id       Parent post ID.
	 * @return void
	 */
	private function assignAttachmentAuthor( int $attachment_id, int $post_id ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		$author_id = 0;
		if ( $post_id > 0 ) {
			$author_id = (int) get_post_field( 'post_author', $post_id );
		}
		if ( $author_id <= 0 ) {
			$author_id = (int) get_current_user_id();
		}
		if ( $author_id <= 0 ) {
			return;
		}

		wp_update_post(
			array(
				'ID'          => $attachment_id,
				'post_author' => $author_id,
			)
		);
	}

	/**
	 * Record output dimensions and a Seedream-oriented cost-tier hint (not a fal invoice).
	 *
	 * Real $ amounts come from the fal dashboard / usage API — responses do not include price.
	 *
	 * @since 3.2.16
	 * @param int    $attachment_id Attachment ID.
	 * @param string $purpose       featured|heading.
	 * @return array{width:int,height:int,area:int,cost_hint:string}
	 */
	private function recordGeneratedDimensions( int $attachment_id, string $purpose ): array {
		$width  = 0;
		$height = 0;
		$meta   = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) ) {
			$width  = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
			$height = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		}

		if ( ( $width <= 0 || $height <= 0 ) ) {
			$file = get_attached_file( $attachment_id );
			if ( is_string( $file ) && '' !== $file && file_exists( $file ) ) {
				$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $file ) : getimagesize( $file );
				if ( is_array( $size ) ) {
					$width  = (int) $size[0];
					$height = (int) $size[1];
				}
			}
		}

		$area = max( 0, $width * $height );
		// Seedream documented area tiers (other models bill differently).
		$cheap_cap = 1536 * 1536;
		$high_cap  = 2048 * 2048;
		if ( $area <= 0 ) {
			$cost_hint = 'unknown';
		} elseif ( $area <= $cheap_cap ) {
			$cost_hint = 'seedream_tier_low_approx_0.0675';
		} elseif ( $area <= $high_cap ) {
			$cost_hint = 'seedream_tier_high_approx_0.135';
		} else {
			$cost_hint = 'above_seedream_documented_caps';
		}

		update_post_meta( $attachment_id, '_sim_generated_width', $width );
		update_post_meta( $attachment_id, '_sim_generated_height', $height );
		update_post_meta( $attachment_id, '_sim_generated_cost_hint', sanitize_key( $cost_hint ) );

		Logger::info(
			'AiImageGenerator: output dimensions',
			array(
				'attachment_id' => $attachment_id,
				'purpose'       => $purpose,
				'width'         => $width,
				'height'        => $height,
				'area'          => $area,
				'cost_hint'     => $cost_hint,
			)
		);

		return array(
			'width'     => $width,
			'height'    => $height,
			'area'      => $area,
			'cost_hint' => $cost_hint,
		);
	}

	/**
	 * Persist generation meta and alt/description on the attachment.
	 *
	 * @since 3.1.1
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $meta          Meta bag.
	 * @return void
	 */
	private function writeAttachmentMeta( int $attachment_id, array $meta ): void {
		update_post_meta( $attachment_id, '_sim_generated', '1' );
		update_post_meta( $attachment_id, '_sim_generated_prompt', sanitize_textarea_field( (string) $meta['prompt'] ) );
		update_post_meta( $attachment_id, '_sim_generated_keyword', sanitize_text_field( (string) $meta['keyword'] ) );
		update_post_meta( $attachment_id, '_sim_generated_heading_hash', sanitize_text_field( (string) $meta['heading_hash'] ) );
		update_post_meta( $attachment_id, '_sim_generated_post_id', (int) $meta['post_id'] );
		update_post_meta( $attachment_id, '_sim_generated_style', sanitize_key( (string) $meta['style'] ) );
		update_post_meta( $attachment_id, '_sim_generated_input', (string) $meta['input'] );

		$description = '';
		if ( Settings::get( 'ai_image_save_prompt_as_description' ) ) {
			$description = sanitize_textarea_field( (string) $meta['prompt'] );
		}

		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_content' => $description,
			)
		);

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $meta['alt'] ) );
	}

	/**
	 * Upsert a matches-table row for the generated image.
	 *
	 * @since 3.1.1
	 * @param int    $post_id       Post ID.
	 * @param string $heading_hash  Heading hash.
	 * @param string $heading_text  Heading text.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $prompt        Visual brief.
	 * @param string $focus_keyword Focus keyword.
	 * @param string $style         Style.
	 * @param string $status        Match row status (approved|pending).
	 * @param int    $confidence    Confidence score 0-100.
	 * @param string $match_method  Match method slug.
	 * @param string $vision_reason Optional vision-fail reasoning.
	 * @return void
	 */
	private function recordMatch(
		int $post_id,
		string $heading_hash,
		string $heading_text,
		int $attachment_id,
		string $prompt,
		string $focus_keyword,
		string $style,
		string $status = 'approved',
		int $confidence = 75,
		string $match_method = 'ai_generated',
		string $vision_reason = ''
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'smart_image_matcher_matches';

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'post_id'      => $post_id,
				'heading_hash' => $heading_hash,
			),
			array( '%d', '%s' )
		);

		$reasoning = $prompt;
		if ( '' !== $vision_reason ) {
			$reasoning = $prompt . ' [vision_failed: ' . $vision_reason . ']';
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'post_id'          => $post_id,
				'heading_hash'     => $heading_hash,
				'heading_text'     => $heading_text,
				'heading_tag'      => ( 'featured' === $heading_hash ) ? 'h1' : 'h2',
				'image_id'         => $attachment_id,
				'confidence_score' => max( 0, min( 100, $confidence ) ),
				'match_method'     => sanitize_key( $match_method ),
				'ai_reasoning'     => $reasoning,
				'status'           => sanitize_key( $status ),
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		unset( $focus_keyword, $style );
	}

	/**
	 * Parse a vision score from ProviderBridge::scoreImageWithVision() output.
	 *
	 * @since 3.2.0
	 * @param mixed  $raw     Raw provider response.
	 * @param string $reason  Populated with reasoning text when available.
	 * @return int|\WP_Error Score 0-100 or error.
	 */
	private function parseVisionScore( $raw, string &$reason = '' ) {
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return new \WP_Error(
				'smart_image_matcher_vision_empty',
				__( 'Vision verification returned no score.', 'smart-image-matcher' )
			);
		}

		$data = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! isset( $data['score'] ) ) {
			return new \WP_Error(
				'smart_image_matcher_vision_parse',
				__( 'Vision verification returned an unreadable score.', 'smart-image-matcher' )
			);
		}

		$reason = sanitize_text_field( (string) ( $data['reasoning'] ?? '' ) );

		return (int) $data['score'];
	}

	/**
	 * Title for media library / SEO fields.
	 *
	 * @since 3.1.1
	 * @param string $focus_keyword Focus keyword.
	 * @param string $heading_text  Heading fallback.
	 * @return string
	 */
	private function resolveTitle( string $focus_keyword, string $heading_text ): string {
		$raw = ( '' !== $focus_keyword ) ? $focus_keyword : $heading_text;
		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $raw, MB_CASE_TITLE, 'UTF-8' );
		}
		return ucwords( strtolower( $raw ) );
	}

	/**
	 * Alt text per settings mode.
	 *
	 * @since 3.1.1
	 * @param string $title         Keyword/heading title.
	 * @param string $brief         Visual brief.
	 * @param string $focus_keyword Focus keyword.
	 * @return string
	 */
	private function resolveAlt( string $title, string $brief, string $focus_keyword ): string {
		$mode = (string) Settings::get( 'ai_image_alt_mode' );
		if ( 'descriptive' !== $mode ) {
			return $title;
		}

		$alt = $this->prompt_builder->buildDescriptiveAlt( $brief, $focus_keyword );
		if ( is_wp_error( $alt ) || '' === $alt ) {
			return $title;
		}

		return (string) $alt;
	}

	/**
	 * Extract a remote URL and/or binary payload from a generate_image_result File/result.
	 *
	 * @since 3.1.3
	 * @param mixed $result Provider result (GenerativeAiResult or File).
	 * @return array{url?:string,binary?:string,mime?:string}
	 */
	private function extractImageSource( $result ): array {
		$file = null;

		if ( is_object( $result ) && method_exists( $result, 'toFile' ) ) {
			try {
				$file = $result->toFile();
			} catch ( \Throwable $e ) {
				Logger::warn( 'AiImageGenerator: toFile() failed', array( 'error' => $e->getMessage() ) );
			}
		} elseif ( is_object( $result ) && method_exists( $result, 'getUrl' ) ) {
			$file = $result;
		}

		$out = array();

		if ( is_object( $file ) ) {
			if ( method_exists( $file, 'getMimeType' ) ) {
				$mime = $file->getMimeType();
				if ( is_string( $mime ) && '' !== $mime ) {
					$out['mime'] = $mime;
				}
			}
			if ( method_exists( $file, 'getUrl' ) ) {
				$url = $file->getUrl();
				if ( is_string( $url ) && '' !== $url ) {
					$out['url'] = $url;
				}
			}
			if ( method_exists( $file, 'getBase64Data' ) ) {
				$b64 = $file->getBase64Data();
				if ( is_string( $b64 ) && '' !== $b64 ) {
					$decoded = base64_decode( $b64, true );
					if ( false !== $decoded && '' !== $decoded ) {
						$out['binary'] = $decoded;
					}
				}
			}
		}

		if ( empty( $out['url'] ) && is_string( $result ) && 0 === strpos( $result, 'http' ) ) {
			$out['url'] = $result;
		}

		if ( empty( $out['url'] ) && is_object( $result ) && method_exists( $result, 'getCandidates' ) ) {
			foreach ( $result->getCandidates() as $candidate ) {
				if ( ! is_object( $candidate ) || ! method_exists( $candidate, 'getMessage' ) ) {
					continue;
				}
				$message = $candidate->getMessage();
				if ( ! is_object( $message ) || ! method_exists( $message, 'getParts' ) ) {
					continue;
				}
				foreach ( $message->getParts() as $part ) {
					if ( ! is_object( $part ) || ! method_exists( $part, 'getFile' ) ) {
						continue;
					}
					$part_file = $part->getFile();
					if ( ! is_object( $part_file ) ) {
						continue;
					}
					if ( method_exists( $part_file, 'getUrl' ) ) {
						$url = $part_file->getUrl();
						if ( is_string( $url ) && '' !== $url ) {
							$out['url'] = $url;
							return $out;
						}
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Result cache key.
	 *
	 * @since 3.1.1
	 * @param string $heading_hash  Heading hash.
	 * @param string $focus_keyword Focus keyword.
	 * @param string $style         Style.
	 * @return string
	 */
	private function resultCacheKey( string $heading_hash, string $focus_keyword, string $style ): string {
		return 'smart_image_matcher_img_cache_' . md5( $heading_hash . ':' . $focus_keyword . ':' . $style );
	}
}
