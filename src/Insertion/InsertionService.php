<?php
/**
 * Block-tree-based image insertion service.
 *
 * This class replaces every byte-offset-based insertion path from the legacy
 * class-sim-ajax.php. It operates entirely on:
 *
 *   - Stable heading hashes (from HeadingLocator::computeHash).
 *   - The Gutenberg block tree (parse_blocks / serialize_blocks).
 *   - A regex fallback for Classic-editor posts.
 *
 * RULES (per agents.md §8):
 *   - Never use byte offsets or heading_position.
 *   - Never call wp_update_post() more than once per bulk operation.
 *   - Never write width/height on the <img> tag.
 *   - Block attrs: id, sizeSlug, linkDestination only.
 *
 * @package SmartImageMatcher\Insertion
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Insertion;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Cache\Cache;
use SmartImageMatcher\Logging\Logger;

/**
 * Class InsertionService
 *
 * @since 3.0.0
 */
class InsertionService {

	/**
	 * @var BlockBuilder
	 */
	private BlockBuilder $builder;

	/**
	 * Constructor.
	 *
	 * @param BlockBuilder $builder Image block factory.
	 */
	public function __construct( BlockBuilder $builder ) {
		$this->builder = $builder;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Insert a single image after a heading.
	 *
	 * @since 3.0.0
	 * @param int    $postId      Post ID.
	 * @param string $headingHash Stable heading hash.
	 * @param int    $imageId     Attachment ID.
	 * @return true|\WP_Error
	 */
	public function insert( int $postId, string $headingHash, int $imageId ) {
		return $this->bulkInsert(
			$postId,
			array(
				array(
					'heading_hash' => $headingHash,
					'image_id'     => $imageId,
				),
			)
		);
	}

	/**
	 * Insert multiple images with a single wp_update_post() call.
	 *
	 * Insertions are sorted from last heading to first so earlier inserts
	 * do not shift block indices for later ones.
	 *
	 * @since 3.0.0
	 * @param int                                                       $postId     Post ID.
	 * @param array<int, array{heading_hash: string, image_id: int}>    $insertions Ordered list of insertions.
	 * @return true|\WP_Error
	 */
	public function bulkInsert( int $postId, array $insertions ) {
		if ( empty( $insertions ) ) {
			return new \WP_Error( 'smart_image_matcher_no_insertions', __( 'No insertions requested.', 'smart-image-matcher' ) );
		}

		try {
			return $this->performBulkInsert( $postId, $insertions );
		} catch ( \Throwable $e ) {
			Logger::error(
				'InsertionService: insert crashed',
				array(
					'post_id' => $postId,
					'error'   => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);

			return new \WP_Error(
				'smart_image_matcher_insertion_crashed',
				sprintf(
					/* translators: %s: exception message */
					__( 'Insert failed: %s', 'smart-image-matcher' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Drop insertion rows that would put the post's featured image in its body.
	 *
	 * @since 3.5.2
	 * @param int                                                    $postId     Post ID.
	 * @param array<int, array{heading_hash: string, image_id: int}> $insertions Rows.
	 * @return array<int, array{heading_hash: string, image_id: int}>
	 */
	public static function withoutFeaturedImage( int $postId, array $insertions ): array {
		$featured = \SmartImageMatcher\FeaturedImages\FeaturedImageService::getStoredThumbnailId( $postId );
		if ( $featured <= 0 ) {
			return $insertions;
		}

		return array_values(
			array_filter(
				$insertions,
				static fn( array $row ): bool => (int) $row['image_id'] !== $featured
			)
		);
	}

	/**
	 * Run the insert after validation.
	 *
	 * @since 3.4.5
	 * @param int                                                    $postId     Post ID.
	 * @param array<int, array{heading_hash: string, image_id: int}> $insertions Ordered list of insertions.
	 * @return true|\WP_Error
	 */
	private function performBulkInsert( int $postId, array $insertions ) {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'smart_image_matcher_invalid_post', __( 'Post not found.', 'smart-image-matcher' ) );
		}

		// The featured image already shows above the article; never repeat it in the body.
		$insertions = self::withoutFeaturedImage( $postId, $insertions );
		if ( empty( $insertions ) ) {
			return new \WP_Error(
				'smart_image_matcher_featured_in_body',
				__( 'That image is this article’s featured image, so it is not inserted into the body.', 'smart-image-matcher' )
			);
		}

		// Never insert twice: skip images already in the post and headings that already have one.
		$present    = $this->attachmentIdsInContent( $postId );
		$insertions = array_values(
			array_filter(
				$insertions,
				fn( array $row ): bool => ! isset( $present[ (int) $row['image_id'] ] )
					&& ! $this->headingHasFollowingImage( $postId, (string) $row['heading_hash'] )
			)
		);
		if ( empty( $insertions ) ) {
			return new \WP_Error(
				'smart_image_matcher_already_inserted',
				__( 'Those images are already in the article.', 'smart-image-matcher' )
			);
		}

		// Validate every image before touching the content.
		foreach ( $insertions as $item ) {
			if ( ! wp_attachment_is_image( (int) $item['image_id'] ) ) {
				return new \WP_Error(
					'smart_image_matcher_invalid_image',
					sprintf(
						/* translators: %d attachment ID */
						__( 'Attachment %d is not a valid image.', 'smart-image-matcher' ),
						(int) $item['image_id']
					)
				);
			}
		}

		$content  = $post->post_content;
		$original = $content;

		if ( has_blocks( $content ) ) {
			$content = $this->insertIntoBlocks( $content, $insertions );
		} else {
			$content = $this->insertIntoHtml( $content, $insertions );
		}

		if ( $content === $original ) {
			Logger::warn(
				'InsertionService: content unchanged — headings may not have been found.',
				array(
					'post_id'    => $postId,
					'insertions' => count( $insertions ),
				)
			);
			return new \WP_Error( 'smart_image_matcher_insertion_failed', __( 'No headings were found for the requested hashes.', 'smart-image-matcher' ) );
		}

		// ONE wp_update_post() for all insertions. wp_update_post expects slashed data.
		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $postId,
					'post_content' => $content,
				)
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Cache::clearPost( $postId );

		Logger::info(
			'InsertionService: bulk insert complete.',
			array(
				'post_id' => $postId,
				'count'   => count( $insertions ),
			)
		);

		return true;
	}

	/**
	 * Whether the heading already has an immediately following image.
	 *
	 * Gutenberg: next sibling is core/image or core/gallery.
	 * Classic: next markup is an img, gallery shortcode, or image block.
	 *
	 * @since 3.3.0
	 * @param int    $post_id      Post ID.
	 * @param string $heading_hash Stable heading hash.
	 * @return bool
	 */
	public function headingHasFollowingImage( int $post_id, string $heading_hash ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$content = (string) $post->post_content;
		if ( '' === $content || '' === $heading_hash ) {
			return false;
		}

		if ( has_blocks( $content ) ) {
			$blocks = parse_blocks( $content );
			$seen   = array();
			return $this->blocksHeadingHasFollowingImage( $blocks, $heading_hash, $seen );
		}

		return $this->htmlHeadingHasFollowingImage( $content, $heading_hash );
	}

	/**
	 * Keep headings that do not already have a following image.
	 *
	 * Editor matching and AI ranking skip these so attached headings
	 * are not sent to the provider.
	 *
	 * @since 3.4.4
	 * @param int                              $post_id  Post ID.
	 * @param array<int, array<string, mixed>> $headings Extracted headings.
	 * @return array<int, array<string, mixed>>
	 */
	public function headingsNeedingImages( int $post_id, array $headings ): array {
		$kept = array();

		foreach ( $headings as $heading ) {
			if ( ! is_array( $heading ) ) {
				continue;
			}

			$hash = isset( $heading['heading_hash'] ) ? (string) $heading['heading_hash'] : '';
			if ( '' === $hash ) {
				continue;
			}

			if ( $this->headingHasFollowingImage( $post_id, $hash ) ) {
				continue;
			}

			$kept[] = $heading;
		}

		return $kept;
	}

	/**
	 * Attachment IDs already present in the post (image blocks, galleries, classic markup).
	 *
	 * @since 3.4.3
	 * @param int $post_id Post ID.
	 * @return array<int, int> Map of attachment ID => attachment ID.
	 */
	public function attachmentIdsInContent( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$content = (string) $post->post_content;
		if ( '' === $content ) {
			return array();
		}

		$ids = array();
		if ( function_exists( 'has_blocks' ) && has_blocks( $content ) && function_exists( 'parse_blocks' ) ) {
			$this->collectBlockAttachmentIds( parse_blocks( $content ), $ids );
		}

		if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			foreach ( $matches[1] as $raw_id ) {
				$id = (int) $raw_id;
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
		}

		return $ids;
	}

	/**
	 * Remove every standalone copy of one image from a post's body (one save).
	 *
	 * Used to move an article's featured image out of the body. Images inside a
	 * gallery are left alone.
	 *
	 * @since 3.7.3
	 * @param int $post_id  Post ID.
	 * @param int $image_id Attachment ID.
	 * @return true|\WP_Error
	 */
	public function removeImage( int $post_id, int $image_id ) {
		if ( $image_id <= 0 ) {
			return new \WP_Error( 'smart_image_matcher_invalid_post', __( 'Post not found.', 'smart-image-matcher' ) );
		}
		$result = $this->removeImages( $post_id, array( $image_id ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Remove standalone images from a post's body in one save.
	 *
	 * @since 3.7.4
	 * @param int        $post_id   Post ID.
	 * @param int[]|null $image_ids Attachment IDs, or null for every standalone image.
	 * @return array<int, int>|\WP_Error IDs removed (0 for an image with no attachment ID).
	 */
	public function removeImages( int $post_id, ?array $image_ids ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'smart_image_matcher_invalid_post', __( 'Post not found.', 'smart-image-matcher' ) );
		}

		$content = (string) $post->post_content;
		$result  = $this->withoutImages( $content, $image_ids );
		if ( empty( $result['removed'] ) || $result['content'] === $content ) {
			return new \WP_Error( 'smart_image_matcher_image_not_in_body', __( 'No matching image was found in the article body.', 'smart-image-matcher' ) );
		}

		$saved = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $result['content'],
				)
			),
			true
		);
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		Cache::clearPost( $post_id );
		return $result['removed'];
	}

	/**
	 * Content with every standalone copy of an image removed.
	 *
	 * @since 3.7.3
	 * @param string $content  Post content.
	 * @param int    $image_id Attachment ID.
	 * @return string Unchanged when the image is not there.
	 */
	public function withoutImage( string $content, int $image_id ): string {
		if ( $image_id <= 0 ) {
			return $content;
		}
		return $this->withoutImages( $content, array( $image_id ) )['content'];
	}

	/**
	 * Blocks whose HTML may hold inline images (removed from their markup).
	 * Layout blocks such as media-text and cover are never edited.
	 *
	 * @since 3.7.5
	 */
	const INLINE_IMAGE_BLOCKS = array( '', 'core/paragraph', 'core/html', 'core/freeform' );

	/**
	 * Content with images removed: image blocks, and images inline in paragraphs,
	 * Classic and Custom HTML blocks. Never images inside a gallery.
	 *
	 * @since 3.7.4
	 * @param string     $content   Post content.
	 * @param int[]|null $image_ids Attachment IDs, or null for every image.
	 * @return array{content:string, removed:array<int, int>}
	 */
	public function withoutImages( string $content, ?array $image_ids ): array {
		$ids     = null === $image_ids ? null : array_values( array_filter( array_map( 'absint', $image_ids ) ) );
		$removed = array();
		if ( '' === $content || ( null !== $ids && empty( $ids ) ) ) {
			return array(
				'content' => $content,
				'removed' => $removed,
			);
		}

		if ( has_blocks( $content ) ) {
			$blocks = $this->dropImageBlocks( parse_blocks( $content ), $ids, '', $removed );
			return array(
				'content' => $removed ? serialize_blocks( $this->normalizeBlocksForSerialize( $blocks ) ) : $content,
				'removed' => $removed,
			);
		}

		return array(
			'content' => self::stripImagesFromHtml( $content, $ids, $removed ),
			'removed' => $removed,
		);
	}

	/**
	 * Remove images from HTML: caption shortcode, figure, a paragraph holding only
	 * the image, then any remaining image tag (with its link).
	 *
	 * @since 3.7.5
	 * @param string          $html    HTML.
	 * @param int[]|null      $ids     Attachment IDs, or null for every image.
	 * @param array<int, int> $removed IDs removed (by ref; 0 when an image has none).
	 * @return string
	 */
	private static function stripImagesFromHtml( string $html, ?array $ids, array &$removed ): string {
		if ( false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$targets = null === $ids ? array( '' ) : array_map( 'strval', $ids );
		foreach ( $targets as $id ) {
			$img      = '' === $id ? '<img\b[^>]*>' : '<img\b[^>]*wp-image-' . $id . '(?!\d)[^>]*>';
			$patterns = array(
				'#\[caption[^\]]*\](?:(?!\[/caption\]).)*?' . $img . '(?:(?!\[/caption\]).)*\[/caption\]\s*#s',
				'#<figure\b[^>]*>(?:(?!</figure>).)*?' . $img . '(?:(?!</figure>).)*</figure>\s*#s',
				'#<p\b[^>]*>\s*(?:<a\b[^>]*>\s*)?' . $img . '\s*(?:</a>\s*)?</p>\s*#s',
				'#(?:<a\b[^>]*>\s*)?' . $img . '(?:\s*</a>)?#s',
			);
			foreach ( $patterns as $pattern ) {
				$html = (string) preg_replace_callback(
					$pattern,
					static function ( array $m ) use ( &$removed ): string {
						$count = preg_match_all( '/<img\b[^>]*>/i', $m[0], $tags );
						for ( $i = 0; $i < max( 1, (int) $count ); $i++ ) {
							$tag       = $tags[0][ $i ] ?? $m[0];
							$removed[] = preg_match( '/wp-image-(\d+)/', $tag, $idm ) ? (int) $idm[1] : 0;
						}
						return '';
					},
					$html
				);
			}
		}
		return $html;
	}

	/**
	 * Images in the body (attachment ID, or 0 when it has none): image blocks and
	 * images inline in paragraphs, Classic and Custom HTML blocks. Gallery images are not counted.
	 *
	 * @since 3.7.4
	 * @param string $content Post content.
	 * @return array<int, int>
	 */
	public function standaloneImageIds( string $content ): array {
		$found = array();
		if ( '' === $content ) {
			return $found;
		}
		if ( has_blocks( $content ) ) {
			$this->collectStandaloneImages( parse_blocks( $content ), '', $found );
			return $found;
		}
		return self::imgIdsInHtml( $content );
	}

	/**
	 * Attachment IDs of every image tag in HTML (0 when a tag has none).
	 *
	 * @param string $html HTML.
	 * @return array<int, int>
	 */
	private static function imgIdsInHtml( string $html ): array {
		$found = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$found[] = preg_match( '/wp-image-(\d+)/', $tag, $m ) ? (int) $m[1] : 0;
			}
		}
		return $found;
	}

	/**
	 * Collect image blocks and inline images outside galleries.
	 *
	 * @param array<int, mixed> $blocks Blocks.
	 * @param string            $parent Parent block name.
	 * @param array<int, int>   $found  Found IDs (by ref).
	 * @return void
	 */
	private function collectStandaloneImages( array $blocks, string $parent, array &$found ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || 'core/gallery' === $parent ) {
				continue;
			}
			$name = (string) ( $block['blockName'] ?? '' );
			if ( 'core/image' === $name ) {
				$found[] = (int) ( $block['attrs']['id'] ?? 0 );
			} elseif ( in_array( $name, self::INLINE_IMAGE_BLOCKS, true ) && empty( $block['innerBlocks'] ) ) {
				foreach ( self::imgIdsInHtml( (string) ( $block['innerHTML'] ?? '' ) ) as $id ) {
					$found[] = $id;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->collectStandaloneImages( $block['innerBlocks'], $name, $found );
			}
		}
	}

	/**
	 * Whether a block is an image block this removal targets.
	 *
	 * @param mixed      $block  Block.
	 * @param int[]|null $ids    Target IDs, or null for every image.
	 * @param string     $parent Parent block name.
	 * @return bool
	 */
	private static function isTargetImage( $block, ?array $ids, string $parent ): bool {
		if ( ! is_array( $block ) || 'core/image' !== ( $block['blockName'] ?? '' ) || 'core/gallery' === $parent ) {
			return false;
		}
		return null === $ids || in_array( (int) ( $block['attrs']['id'] ?? 0 ), $ids, true );
	}

	/**
	 * Remove target images from a block list, keeping innerContent placeholders in step.
	 *
	 * @param array<int, mixed> $blocks  Blocks.
	 * @param int[]|null        $ids     Target IDs, or null for every image.
	 * @param string            $parent  Parent block name ('' at the top level).
	 * @param array<int, int>   $removed IDs removed (by ref).
	 * @return array<int, array<string, mixed>>
	 */
	private function dropImageBlocks( array $blocks, ?array $ids, string $parent, array &$removed ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			$kept = $this->filterImageBlock( $block, $ids, $parent, $removed );
			if ( null !== $kept ) {
				$out[] = $kept;
			}
		}
		return $out;
	}

	/**
	 * One block with its target images removed, or null when the whole block goes.
	 *
	 * @param mixed           $block   Block.
	 * @param int[]|null      $ids     Target IDs, or null for every image.
	 * @param string          $parent  Parent block name.
	 * @param array<int, int> $removed IDs removed (by ref).
	 * @return array<string, mixed>|null
	 */
	private function filterImageBlock( $block, ?array $ids, string $parent, array &$removed ): ?array {
		if ( ! is_array( $block ) ) {
			return null;
		}
		if ( self::isTargetImage( $block, $ids, $parent ) ) {
			$removed[] = (int) ( $block['attrs']['id'] ?? 0 );
			return null;
		}

		$name     = (string) ( $block['blockName'] ?? '' );
		$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();

		// Inline images in a paragraph, Classic or Custom HTML block: edit the markup.
		if ( ! $children && 'core/gallery' !== $parent && in_array( $name, self::INLINE_IMAGE_BLOCKS, true ) && false !== stripos( (string) ( $block['innerHTML'] ?? '' ), '<img' ) ) {
			$before = count( $removed );
			$chunks = array();
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $chunk ) {
				$chunks[] = is_string( $chunk ) ? self::stripImagesFromHtml( $chunk, $ids, $removed ) : $chunk;
			}
			if ( count( $removed ) === $before ) {
				return $block;
			}
			$ignored               = array();
			$block['innerContent'] = $chunks;
			$block['innerHTML']    = self::stripImagesFromHtml( (string) $block['innerHTML'], $ids, $ignored );

			// A paragraph or HTML block that held only the image goes with it.
			$left = trim( str_replace( array( '&nbsp;', "\xC2\xA0" ), ' ', strip_tags( $block['innerHTML'] ) ) );
			if ( '' === $left && '' !== $name ) {
				return null;
			}
			return $block;
		}

		if ( $children ) {
			$new_children = array();
			$keep         = array();
			foreach ( array_values( $children ) as $i => $child ) {
				$kept       = $this->filterImageBlock( $child, $ids, $name, $removed );
				$keep[ $i ] = null !== $kept;
				if ( null !== $kept ) {
					$new_children[] = $kept;
				}
			}
			$block['innerBlocks'] = $new_children;

			// Remove the null placeholder of each dropped direct child.
			if ( in_array( false, $keep, true ) && isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				$content = array();
				$slot    = 0;
				foreach ( $block['innerContent'] as $chunk ) {
					if ( ! is_string( $chunk ) ) {
						$kept_child = $keep[ $slot ] ?? true;
						++$slot;
						if ( ! $kept_child ) {
							continue;
						}
					}
					$content[] = $chunk;
				}
				$block['innerContent'] = $content;
			}
		}
		return $block;
	}

	/**
	 * Collect core/image and core/gallery attachment IDs from a block tree.
	 *
	 * @since 3.4.3
	 * @param array<int, array<string, mixed>> $blocks Block tree.
	 * @param array<int, int>                  $ids    Collected IDs (by ref).
	 * @return void
	 */
	private function collectBlockAttachmentIds( array $blocks, array &$ids ): void {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			if ( 'core/image' === $name ) {
				$id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}

			if ( 'core/gallery' === $name && isset( $attrs['ids'] ) && is_array( $attrs['ids'] ) ) {
				foreach ( $attrs['ids'] as $raw_id ) {
					$id = (int) $raw_id;
					if ( $id > 0 ) {
						$ids[ $id ] = $id;
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->collectBlockAttachmentIds( $block['innerBlocks'], $ids );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Gutenberg path
	// -------------------------------------------------------------------------

	/**
	 * Insert images into a Gutenberg block-tree content string.
	 *
	 * @since 3.0.0
	 * @param string                                                    $content    Post content.
	 * @param array<int, array{heading_hash: string, image_id: int}>    $insertions Insertions.
	 * @return string Updated content.
	 */
	private function insertIntoBlocks( string $content, array $insertions ): string {
		$blocks = parse_blocks( $content );

		// Build a hash → image_id map for fast lookup.
		$hashMap = array();
		foreach ( $insertions as $item ) {
			$hashMap[ $item['heading_hash'] ] = (int) $item['image_id'];
		}

		$newBlocks = $this->insertBlocksRecursive( $blocks, $hashMap );
		$newBlocks = $this->normalizeBlocksForSerialize( $newBlocks );

		if ( ! empty( $hashMap ) ) {
			Logger::warn(
				'InsertionService: some heading hashes not found in block tree.',
				array(
					'unmatched' => array_keys( $hashMap ),
				)
			);
		}

		return serialize_blocks( $newBlocks );
	}

	/**
	 * Recursively walk and modify a block array, inserting image blocks after
	 * matched heading blocks.
	 *
	 * Returns the modified block array.  $hashMap entries are unset as they
	 * are consumed so callers can detect unmatched hashes.
	 *
	 * @since 3.0.0
	 * @param array<int, array<string,mixed>> $blocks  Block array.
	 * @param array<string, int>             &$hashMap hash → image_id (modified in place).
	 * @return array<int, array<string,mixed>>
	 */
	private function insertBlocksRecursive( array $blocks, array &$hashMap ): array {
		$result = array();
		$seen   = array(); // key "{level}:{text}" => occurrence count

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			// Recurse into inner blocks first.
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$before                 = count( $block['innerBlocks'] );
				$block['innerBlocks']   = $this->insertBlocksRecursive( $block['innerBlocks'], $hashMap );
				if ( count( $block['innerBlocks'] ) !== $before ) {
					$block = $this->alignInnerContentPlaceholders( $block );
				}
			}

			$result[] = $block;

			// Check whether this is a heading block we need to follow with an image.
			if ( ( $block['blockName'] ?? '' ) === 'core/heading' && ! empty( $hashMap ) ) {
				$level = (int) ( $block['attrs']['level'] ?? 2 );
				$text  = strtolower(
					trim(
						wp_strip_all_tags(
							html_entity_decode( (string) ( $block['innerHTML'] ?? '' ), ENT_QUOTES, 'UTF-8' )
						)
					)
				);
				$key          = "{$level}:{$text}";
				$occurrence   = $seen[ $key ] ?? 0;
				$seen[ $key ] = $occurrence + 1;

				$hash = HeadingLocator::computeHash( $level, $text, $occurrence );

				if ( isset( $hashMap[ $hash ] ) ) {
					$result[] = $this->builder->build( $hashMap[ $hash ] );
					unset( $hashMap[ $hash ] );
				}
			}
		}

		return $result;
	}

	/**
	 * Make a block tree safe for serialize_blocks() (PHP 8 TypeError guard).
	 *
	 * serialize_block() foreach-es innerContent and then reads innerBlocks
	 * by placeholder index. Missing innerContent, or more placeholders than
	 * inner blocks, fatals with "critical error on this website".
	 *
	 * @since 3.4.5
	 * @param array<int, mixed> $blocks Block list.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalizeBlocksForSerialize( array $blocks ): array {
		$out = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block['blockName']   = $block['blockName'] ?? null;
			$block['attrs']       = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$block['innerHTML']   = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
			$block['innerBlocks'] = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] )
				? $this->normalizeBlocksForSerialize( $block['innerBlocks'] )
				: array();

			if ( ! isset( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
				$block['innerContent'] = array( $block['innerHTML'] );
			}

			$block  = $this->alignInnerContentPlaceholders( $block );
			$out[]  = $block;
		}

		return $out;
	}

	/**
	 * Keep innerContent null placeholders in lockstep with innerBlocks.
	 *
	 * Extra inner blocks (an image inserted after a nested heading) need a
	 * matching null or Gutenberg omits them. Extra nulls make serialize_block()
	 * pass null into itself and throw.
	 *
	 * @since 3.4.5
	 * @param array<string, mixed> $block Block.
	 * @return array<string, mixed>
	 */
	private function alignInnerContentPlaceholders( array $block ): array {
		$inner   = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		$content = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();
		$need    = count( $inner );
		$nulls   = 0;

		foreach ( $content as $chunk ) {
			if ( ! is_string( $chunk ) ) {
				++$nulls;
			}
		}

		if ( $nulls === $need ) {
			$block['innerContent'] = $content;
			return $block;
		}

		if ( $nulls > $need ) {
			$kept = array();
			$seen = 0;
			foreach ( $content as $chunk ) {
				if ( ! is_string( $chunk ) ) {
					if ( $seen >= $need ) {
						continue;
					}
					++$seen;
				}
				$kept[] = $chunk;
			}
			$block['innerContent'] = $kept;
			return $block;
		}

		$extra          = $need - $nulls;
		$last_string_at = -1;
		for ( $i = count( $content ) - 1; $i >= 0; $i-- ) {
			if ( is_string( $content[ $i ] ) ) {
				$last_string_at = $i;
				break;
			}
		}

		$insert_at  = ( $last_string_at >= 0 ) ? $last_string_at : count( $content );
		$injection  = array();
		for ( $i = 0; $i < $extra; $i++ ) {
			$injection[] = "\n";
			$injection[] = null;
		}

		array_splice( $content, $insert_at, 0, $injection );
		$block['innerContent'] = $content;

		return $block;
	}

	// -------------------------------------------------------------------------
	// Classic HTML path
	// -------------------------------------------------------------------------

	/**
	 * Insert images into Classic-editor HTML content.
	 *
	 * Uses the same hash-based matching so it stays consistent with the
	 * Gutenberg path — no byte offsets.
	 *
	 * @since 3.0.0
	 * @param string                                                    $content    HTML content.
	 * @param array<int, array{heading_hash: string, image_id: int}>    $insertions Insertions.
	 * @return string Updated content.
	 */
	private function insertIntoHtml( string $content, array $insertions ): string {
		// Build hash map.
		$hashMap = array();
		foreach ( $insertions as $item ) {
			$hashMap[ $item['heading_hash'] ] = (int) $item['image_id'];
		}

		// Find all headings with their positions.
		preg_match_all(
			'/<(h[2-6])[^>]*>(.*?)<\/\1>/is',
			$content,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		);

		// Build an ordered list of (end_position, image_id) to insert.
		$insertionPoints = array();
		$seen            = array();

		foreach ( $matches as $match ) {
			$tag        = strtolower( $match[1][0] );
			$level      = (int) substr( $tag, 1 );
			$innerHtml  = $match[2][0];
			$text       = strtolower( trim( wp_strip_all_tags( html_entity_decode( $innerHtml, ENT_QUOTES, 'UTF-8' ) ) ) );
			$fullMatch  = $match[0][0];
			$startPos   = (int) $match[0][1];
			$endPos     = $startPos + strlen( $fullMatch );

			$key        = "{$level}:{$text}";
			$occurrence = $seen[ $key ] ?? 0;
			$seen[ $key ] = $occurrence + 1;

			$hash = HeadingLocator::computeHash( $level, $text, $occurrence );

			if ( isset( $hashMap[ $hash ] ) ) {
				$insertionPoints[] = array(
					'end_pos'  => $endPos,
					'image_id' => $hashMap[ $hash ],
				);
				unset( $hashMap[ $hash ] );
			}
		}

		if ( empty( $insertionPoints ) ) {
			return $content;
		}

		// Sort bottom-to-top so earlier insertions don't shift later positions.
		usort( $insertionPoints, static fn( $a, $b ) => $b['end_pos'] - $a['end_pos'] );

		foreach ( $insertionPoints as $point ) {
			$imageBlock = $this->builder->buildSerialized( $point['image_id'] );
			$content    = substr( $content, 0, $point['end_pos'] )
				. "\n\n" . $imageBlock . "\n\n"
				. substr( $content, $point['end_pos'] );
		}

		return $content;
	}

	/**
	 * Recursively detect an image/gallery sibling after a hashed heading.
	 *
	 * @param array<int, array<string, mixed>> $blocks       Block list.
	 * @param string                           $heading_hash Target hash.
	 * @param array<string, int>               $seen         Occurrence map (by ref).
	 * @return bool
	 */
	private function blocksHeadingHasFollowingImage( array $blocks, string $heading_hash, array &$seen ): bool {
		$count = count( $blocks );

		for ( $i = 0; $i < $count; $i++ ) {
			$block = $blocks[ $i ];

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				if ( $this->blocksHeadingHasFollowingImage( $block['innerBlocks'], $heading_hash, $seen ) ) {
					return true;
				}
			}

			if ( ( $block['blockName'] ?? '' ) !== 'core/heading' ) {
				continue;
			}

			$level      = (int) ( $block['attrs']['level'] ?? 2 );
			$text       = strtolower(
				trim(
					wp_strip_all_tags(
						html_entity_decode( (string) ( $block['innerHTML'] ?? '' ), ENT_QUOTES, 'UTF-8' )
					)
				)
			);
			$key        = "{$level}:{$text}";
			$occurrence = $seen[ $key ] ?? 0;
			$seen[ $key ] = $occurrence + 1;
			$hash         = HeadingLocator::computeHash( $level, $text, $occurrence );

			if ( $hash !== $heading_hash ) {
				continue;
			}

			return $this->followingSiblingIsImage( $blocks, $i + 1 );
		}

		return false;
	}

	/**
	 * True when the next meaningful sibling is an image (skip empty spacers).
	 *
	 * @since 3.3.3
	 * @param array<int, array<string, mixed>> $blocks Block list.
	 * @param int                              $start  Index after the heading.
	 * @return bool
	 */
	private function followingSiblingIsImage( array $blocks, int $start ): bool {
		$count = count( $blocks );

		for ( $j = $start; $j < $count; $j++ ) {
			$next = $blocks[ $j ];
			if ( ! is_array( $next ) ) {
				continue;
			}
			if ( $this->blockIsIgnorable( $next ) ) {
				continue;
			}

			return $this->blockContainsImage( $next );
		}

		return false;
	}

	/**
	 * Empty paragraphs, spacers, and whitespace-only freeform blocks.
	 *
	 * @since 3.3.3
	 * @param array<string, mixed> $block Block.
	 * @return bool
	 */
	private function blockIsIgnorable( array $block ): bool {
		$name = (string) ( $block['blockName'] ?? '' );

		if ( '' === $name ) {
			return '' === trim( (string) ( $block['innerHTML'] ?? '' ) );
		}

		if ( in_array( $name, array( 'core/spacer', 'core/separator' ), true ) ) {
			return true;
		}

		if ( 'core/paragraph' === $name ) {
			return '' === trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
		}

		return false;
	}

	/**
	 * Image, gallery, media-text, or a wrapper whose first real child is one.
	 *
	 * @since 3.3.3
	 * @param array<string, mixed> $block Block.
	 * @return bool
	 */
	private function blockContainsImage( array $block ): bool {
		$name = (string) ( $block['blockName'] ?? '' );

		if ( in_array( $name, array( 'core/image', 'core/gallery', 'core/media-text' ), true ) ) {
			return true;
		}

		$inner = $block['innerBlocks'] ?? array();
		if ( ! is_array( $inner ) || empty( $inner ) ) {
			return false;
		}

		if ( ! in_array( $name, array( 'core/group', 'core/columns', 'core/column', 'core/cover' ), true ) ) {
			return false;
		}

		foreach ( $inner as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			if ( $this->blockIsIgnorable( $child ) ) {
				continue;
			}

			return $this->blockContainsImage( $child );
		}

		return false;
	}

	/**
	 * Classic-editor: next markup after the hashed heading is an image.
	 *
	 * @param string $content      Post HTML.
	 * @param string $heading_hash Target hash.
	 * @return bool
	 */
	private function htmlHeadingHasFollowingImage( string $content, string $heading_hash ): bool {
		preg_match_all(
			'/<(h[2-6])[^>]*>(.*?)<\/\1>/is',
			$content,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		);

		$seen = array();

		foreach ( $matches as $match ) {
			$tag        = strtolower( $match[1][0] );
			$level      = (int) substr( $tag, 1 );
			$inner_html = $match[2][0];
			$text       = strtolower( trim( wp_strip_all_tags( html_entity_decode( $inner_html, ENT_QUOTES, 'UTF-8' ) ) ) );
			$full_match = $match[0][0];
			$start_pos  = (int) $match[0][1];
			$end_pos    = $start_pos + strlen( $full_match );

			$key          = "{$level}:{$text}";
			$occurrence   = $seen[ $key ] ?? 0;
			$seen[ $key ] = $occurrence + 1;
			$hash         = HeadingLocator::computeHash( $level, $text, $occurrence );

			if ( $hash !== $heading_hash ) {
				continue;
			}

			$after = ltrim( substr( $content, $end_pos ) );
			$after = preg_replace(
				'/^(?:<!--\s*wp:(?:spacer|separator)\b.*?\/-->\s*|<p(?:\s[^>]*)?>\s*<\/p>\s*)+/is',
				'',
				$after
			);
			$after = is_string( $after ) ? ltrim( $after ) : '';
			if ( '' === $after ) {
				return false;
			}

			if ( preg_match( '/^(<!--\s*wp:(?:image|gallery|media-text)\b|<img\b|\[gallery\b|\[caption\b)/i', $after ) ) {
				return true;
			}

			if ( preg_match( '/^<([a-z][a-z0-9]*)\b/i', $after, $tag_match ) ) {
				$next_tag = strtolower( $tag_match[1] );
				return in_array( $next_tag, array( 'img', 'figure' ), true );
			}

			return false;
		}

		return false;
	}
}
