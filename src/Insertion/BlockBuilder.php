<?php
/**
 * Single source of truth for building core/image blocks.
 *
 * RULES (see agents.md §8 insertion-engine pitfalls):
 * - Returns block array only — NEVER a serialized string.
 * - Block attrs: id, sizeSlug, linkDestination ONLY.
 * - No width/height on <img>.
 * - Use serialize_blocks() once at the call site.
 *
 * @package SmartImageMatcher\Insertion
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Insertion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BlockBuilder
 *
 * @since 3.0.0
 */
class BlockBuilder {

	/**
	 * Build a core/image block array for the given attachment.
	 *
	 * @since 3.0.0
	 * @param int    $imageId  Attachment ID.
	 * @param string $sizeSlug Image size slug. Default 'large'.
	 * @return array<string, mixed> Block array compatible with serialize_blocks().
	 */
	public function build( int $imageId, string $sizeSlug = 'large' ): array {		$imageUrl = wp_get_attachment_url( $imageId );
		$alt      = (string) get_post_meta( $imageId, '_wp_attachment_image_alt', true );
		$caption  = wp_get_attachment_caption( $imageId );

		// Attrs: id, sizeSlug, linkDestination only (CHANGELOG 1.1.1).
		$attrs = array(
			'id'              => $imageId,
			'sizeSlug'        => $sizeSlug,
			'linkDestination' => 'none',
		);

		// img: src, alt, class — NO width/height (Gutenberg handles via sizeSlug).
		$img = sprintf(
			'<img src="%s" alt="%s" class="wp-image-%d"/>',
			esc_url( (string) $imageUrl ),
			esc_attr( $alt ),
			$imageId
		);

		$inner = '<figure class="wp-block-image size-' . esc_attr( $sizeSlug ) . '">'
			. $img
			. ( $caption ? '<figcaption class="wp-element-caption">' . wp_kses_post( $caption ) . '</figcaption>' : '' )
			. '</figure>';

		return array(
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $inner,
			'innerContent' => array( $inner ),
		);
	}

	/**
	 * Build a serialized (string) image block for Classic editor content.
	 *
	 * Produces the exact wp:image comment format.  Only used by the Classic
	 * HTML insertion path in InsertionService.
	 *
	 * @since 3.0.0
	 * @param int    $imageId  Attachment ID.
	 * @param string $sizeSlug Image size slug. Default 'large'.
	 * @return string
	 */
	public function buildSerialized( int $imageId, string $sizeSlug = 'large' ): string {
		return serialize_blocks( array( $this->build( $imageId, $sizeSlug ) ) );
	}
}
