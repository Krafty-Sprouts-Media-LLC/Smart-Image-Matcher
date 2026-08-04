<?php
/**
 * REST controller: on-demand AI image generation + status polling.
 *
 * POST /smart-image-matcher/v1/posts/<id>/generate-image
 * GET  /smart-image-matcher/v1/generate-image/status
 *
 * @package SmartImageMatcher\REST
 * @since   3.1.1
 */

declare( strict_types=1 );

namespace SmartImageMatcher\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\AI\PromptBuilder;
use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Domain\HeadingExtractor;
use SmartImageMatcher\Premium\AiImageGenerator;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Settings\Settings;

/**
 * Class ImageGenController
 *
 * @since 3.1.1
 */
class ImageGenController extends Controller {

	/**
	 * Register routes.
	 *
	 * @since 3.1.1
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>[\d]+)/generate-image',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'enqueueGenerate' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => array(
						'post_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'heading_hash' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'heading_text' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'focus_keyword' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'style' => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => 'photo',
							'enum'              => array( 'photo', 'illustration' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'force' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate-image/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getStatus' ),
					'permission_callback' => array( $this, 'checkStatusPermission' ),
					'args'                => array(
						'post_id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'heading_hash' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission for generate.
	 *
	 * @since 3.1.1
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function checkPermission( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to generate images for this post.', 'smart-image-matcher' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Permission for status poll.
	 *
	 * @since 3.1.1
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function checkStatusPermission( \WP_REST_Request $request ) {
		return $this->checkPermission( $request );
	}

	/**
	 * Enqueue generation (prompt building happens inside the job).
	 *
	 * @since 3.1.1
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enqueueGenerate( \WP_REST_Request $request ) {
		if ( ! Settings::get( 'ai_image_generation_enabled' ) ) {
			return new \WP_Error(
				'smart_image_matcher_disabled',
				__( 'On-demand image generation is disabled in settings.', 'smart-image-matcher' ),
				array( 'status' => 400 )
			);
		}

		if ( ! ProviderBridge::isImageGenerationAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_ai_image_unavailable',
				__( 'No image-capable AI provider configured for the preferred models. Connect fal.ai under Settings → Connectors.', 'smart-image-matcher' ),
				array( 'status' => 503 )
			);
		}

		$post_id       = (int) $request->get_param( 'post_id' );
		$heading_hash  = (string) $request->get_param( 'heading_hash' );
		$heading_text  = (string) $request->get_param( 'heading_text' );
		$focus_keyword = (string) $request->get_param( 'focus_keyword' );
		$style         = (string) $request->get_param( 'style' );
		$force         = (bool) $request->get_param( 'force' );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'smart_image_matcher_post_not_found',
				__( 'Post not found.', 'smart-image-matcher' ),
				array( 'status' => 404 )
			);
		}

		if ( '' === $focus_keyword ) {
			$focus_keyword = PromptBuilder::getFocusKeyword( $post_id );
		}

		// Re-extract section text server-side — do not trust client body text.
		$section_text = $this->extractSectionText( $post->post_content, $heading_hash );

		$generator = new AiImageGenerator();

		if ( ! $force ) {
			$existing = $generator->findGenerated( $post_id, $heading_hash, $focus_keyword, $style );
			if ( $existing ) {
				$url = (string) wp_get_attachment_url( $existing );
				return rest_ensure_response(
					array(
						'status'         => 'exists',
						'attachment_id'  => $existing,
						'attachment_url' => $url,
						'prompt_used'    => (string) get_post_meta( $existing, '_sim_generated_prompt', true ),
						'title'          => get_the_title( $existing ),
						'filename'       => basename( (string) get_attached_file( $existing ) ),
						'message'        => __( 'An image was already generated for this heading.', 'smart-image-matcher' ),
					)
				);
			}
		}

		if ( ! Queue::isAvailable() ) {
			return new \WP_Error(
				'smart_image_matcher_queue_unavailable',
				__( 'Background queue unavailable. Install/activate Action Scheduler and try again.', 'smart-image-matcher' ),
				array( 'status' => 503 )
			);
		}

		AiImageGenerator::setStatus(
			$post_id,
			$heading_hash,
			array(
				'status' => 'queued',
			)
		);

		$job_id = ( new Queue() )->enqueueAiImageGen(
			array(
				'heading_hash'  => $heading_hash,
				'heading_text'  => $heading_text,
				'section_text'  => $section_text,
				'post_id'       => $post_id,
				'focus_keyword' => $focus_keyword,
				'style'         => $style,
				'force'         => $force,
			)
		);

		if ( ! $job_id ) {
			return new \WP_Error(
				'smart_image_matcher_enqueue_failed',
				__( 'Could not enqueue image generation.', 'smart-image-matcher' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'status'       => 'queued',
				'heading_hash' => $heading_hash,
				'job_id'       => $job_id,
				'poll_url'     => add_query_arg(
					array(
						'post_id'      => $post_id,
						'heading_hash' => $heading_hash,
					),
					rest_url( self::NAMESPACE . '/generate-image/status' )
				),
			)
		);
	}

	/**
	 * Poll generation status.
	 *
	 * @since 3.1.1
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function getStatus( \WP_REST_Request $request ) {
		$post_id      = (int) $request->get_param( 'post_id' );
		$heading_hash = (string) $request->get_param( 'heading_hash' );

		$status = AiImageGenerator::getStatus( $post_id, $heading_hash );
		if ( null === $status ) {
			return rest_ensure_response(
				array(
					'status' => 'processing',
				)
			);
		}

		return rest_ensure_response( $status );
	}

	/**
	 * Extract the first paragraph(s) after a heading, by stable hash.
	 *
	 * @since 3.1.1
	 * @param string $content      Post content.
	 * @param string $heading_hash Heading hash.
	 * @return string
	 */
	private function extractSectionText( string $content, string $heading_hash ): string {
		$extractor = new HeadingExtractor();
		$headings  = $extractor->extract( $content );

		$index = null;
		foreach ( $headings as $i => $heading ) {
			if ( ( $heading['heading_hash'] ?? '' ) === $heading_hash ) {
				$index = $i;
				break;
			}
		}

		if ( null === $index ) {
			return '';
		}

		// Prefer Gutenberg: walk blocks for paragraph after matching heading.
		if ( has_blocks( $content ) ) {
			$text = $this->sectionFromBlocks( $content, $heading_hash );
			if ( '' !== $text ) {
				return $text;
			}
		}

		// Classic / fallback: strip tags and take a window after the heading text.
		$plain    = wp_strip_all_tags( $content );
		$needle   = $headings[ $index ]['text'] ?? '';
		$pos      = ( '' !== $needle ) ? stripos( $plain, $needle ) : false;
		if ( false === $pos ) {
			return '';
		}

		$after = substr( $plain, $pos + strlen( $needle ) );
		return wp_trim_words( $after, 80 );
	}

	/**
	 * Collect paragraph text after a heading block with the given hash.
	 *
	 * @since 3.1.1
	 * @param string $content      Post content.
	 * @param string $heading_hash Heading hash.
	 * @return string
	 */
	private function sectionFromBlocks( string $content, string $heading_hash ): string {
		$blocks   = parse_blocks( $content );
		$found    = false;
		$chunks   = array();
		$extractor = new HeadingExtractor();
		$all       = $extractor->extract( $content );

		// Map client_id / hash for lookup while walking is complex; reuse extract order.
		// Walk flat: when we see the target heading, gather following paragraph blocks until next heading.
		$flat = $this->flattenBlocks( $blocks );
		$seen = array();

		foreach ( $flat as $block ) {
			$name = $block['blockName'] ?? '';
			if ( 'core/heading' === $name ) {
				$level     = (int) ( $block['attrs']['level'] ?? 2 );
				$innerHtml = $block['innerHTML'] ?? '';
				$text      = wp_strip_all_tags( $innerHtml );
				$normalised = strtolower( $text );
				$occurrence = $seen[ $normalised ] ?? 0;
				$seen[ $normalised ] = $occurrence + 1;
				$hash = \SmartImageMatcher\Insertion\HeadingLocator::computeHash( $level, $normalised, $occurrence );

				if ( $found ) {
					break;
				}
				if ( $hash === $heading_hash ) {
					$found = true;
				}
				continue;
			}

			if ( $found && 'core/paragraph' === $name ) {
				$para = trim( wp_strip_all_tags( $block['innerHTML'] ?? '' ) );
				if ( '' !== $para ) {
					$chunks[] = $para;
				}
				if ( count( $chunks ) >= 2 ) {
					break;
				}
			}
		}

		unset( $all );

		return wp_trim_words( implode( ' ', $chunks ), 80 );
	}

	/**
	 * Flatten nested blocks depth-first.
	 *
	 * @since 3.1.1
	 * @param array<int, array<string, mixed>> $blocks Blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function flattenBlocks( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$out[] = $block;
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				foreach ( $this->flattenBlocks( $block['innerBlocks'] ) as $inner ) {
					$out[] = $inner;
				}
			}
		}
		return $out;
	}
}
