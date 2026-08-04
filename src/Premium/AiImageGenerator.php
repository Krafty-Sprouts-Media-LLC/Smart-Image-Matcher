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

		if ( ! $force ) {
			$existing = $this->findGenerated( $post_id, $heading_hash, $focus_keyword, $style );
			if ( $existing ) {
				return $existing;
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

		$brief = $this->prompt_builder->buildImagePrompt(
			$heading_text,
			$focus_keyword,
			$section_text,
			$style
		);

		if ( is_wp_error( $brief ) ) {
			return $brief;
		}

		$result = ProviderBridge::generateImage( $brief );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$image_url = $this->extractImageUrl( $result );
		if ( '' === $image_url ) {
			return new \WP_Error(
				'smart_image_matcher_no_image_url',
				__( 'Image generation returned no usable URL.', 'smart-image-matcher' )
			);
		}

		$title = $this->resolveTitle( $focus_keyword, $heading_text );
		$attachment_id = $this->sideloadImage( $image_url, $post_id, $title );

		if ( ! $attachment_id ) {
			return new \WP_Error(
				'smart_image_matcher_sideload_failed',
				__( 'Failed to save the generated image to the media library.', 'smart-image-matcher' )
			);
		}

		$alt = $this->resolveAlt( $title, $brief, $focus_keyword );
		$this->writeAttachmentMeta(
			$attachment_id,
			array(
				'prompt'       => $brief,
				'keyword'      => $focus_keyword,
				'heading_hash' => $heading_hash,
				'post_id'      => $post_id,
				'style'        => $style,
				'title'        => $title,
				'alt'          => $alt,
				'input'        => wp_json_encode(
					array(
						'title'   => $heading_text,
						'keyword' => $focus_keyword,
						'excerpt' => $section_text,
						'heading' => $heading_text,
					)
				),
			)
		);

		$this->recordMatch( $post_id, $heading_hash, $heading_text, $attachment_id, $brief, $focus_keyword, $style );

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

		$focus = PromptBuilder::getFocusKeyword( $post_id );
		$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_excerpt ?: $post->post_content ), 80 );

		$attachment_id = $this->generateForHeading(
			'featured',
			$post->post_title,
			$excerpt,
			$post_id,
			$focus,
			'photo',
			false
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		set_post_thumbnail( $post_id, $attachment_id );

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
	 * Sideload a remote image URL into the media library.
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

		$id = media_sideload_image( $url, $post_id, $title, 'id' );

		if ( is_wp_error( $id ) ) {
			Logger::error( 'AiImageGenerator: sideload failed', array( 'error' => $id->get_error_message() ) );
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

		return $attachment_id;
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

		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_content' => sanitize_textarea_field( (string) $meta['prompt'] ),
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
	 * @return void
	 */
	private function recordMatch(
		int $post_id,
		string $heading_hash,
		string $heading_text,
		int $attachment_id,
		string $prompt,
		string $focus_keyword,
		string $style
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'smart_image_matcher_matches';

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'post_id'      => $post_id,
				'heading_hash' => $heading_hash,
				'match_method' => 'ai_generated',
			),
			array( '%d', '%s', '%s' )
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'post_id'          => $post_id,
				'heading_hash'     => $heading_hash,
				'heading_text'     => $heading_text,
				'heading_tag'      => ( 'featured' === $heading_hash ) ? 'h1' : 'h2',
				'image_id'         => $attachment_id,
				'confidence_score' => 75,
				'match_method'     => 'ai_generated',
				'ai_reasoning'     => $prompt,
				'status'           => 'approved',
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		unset( $focus_keyword, $style );
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
	 * Extract a remote image URL from a ProviderBridge generateImage result.
	 *
	 * @since 3.1.1
	 * @param mixed $result Provider result.
	 * @return string
	 */
	private function extractImageUrl( $result ): string {
		if ( is_string( $result ) && 0 === strpos( $result, 'http' ) ) {
			return $result;
		}

		if ( is_object( $result ) && method_exists( $result, 'getUrl' ) ) {
			$url = $result->getUrl();
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		if ( is_object( $result ) && method_exists( $result, 'getCandidates' ) ) {
			foreach ( $result->getCandidates() as $candidate ) {
				if ( ! is_object( $candidate ) || ! method_exists( $candidate, 'getMessage' ) ) {
					continue;
				}
				$message = $candidate->getMessage();
				if ( ! is_object( $message ) || ! method_exists( $message, 'getParts' ) ) {
					continue;
				}
				foreach ( $message->getParts() as $part ) {
					if ( ! is_object( $part ) ) {
						continue;
					}
					$file = null;
					if ( method_exists( $part, 'getFile' ) ) {
						$file = $part->getFile();
					}
					if ( is_object( $file ) && method_exists( $file, 'getUrl' ) ) {
						$url = $file->getUrl();
						if ( is_string( $url ) && '' !== $url ) {
							return $url;
						}
					}
				}
			}
		}

		return '';
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
