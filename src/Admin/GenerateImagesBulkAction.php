<?php
/**
 * Posts list bulk action: open featured-AI modal on the same list screen.
 *
 * @package SmartImageMatcher\Admin
 * @since   3.2.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GenerateImagesBulkAction
 *
 * @since 3.2.0
 */
class GenerateImagesBulkAction {

	/**
	 * Bulk action slug.
	 *
	 * @since 3.2.0
	 * @var string
	 */
	private const ACTION = 'sim_generate_images';

	/**
	 * Register bulk action filters for public post types.
	 *
	 * @since 3.2.0
	 * @return void
	 */
	public function register(): void {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $post_types['attachment'] );

		foreach ( $post_types as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'addBulkAction' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handleBulkAction' ), 10, 3 );
		}
	}

	/**
	 * Add "Generate featured images…" to the bulk actions dropdown.
	 *
	 * @since 3.2.0
	 * @param array<string, string> $actions Existing bulk actions.
	 * @return array<string, string>
	 */
	public function addBulkAction( array $actions ): array {
		$actions[ self::ACTION ] = __( 'Generate featured images…', 'smart-image-matcher' );
		return $actions;
	}

	/**
	 * Stay on the posts list and open the featured-AI modal via query args.
	 *
	 * @since 3.2.0
	 * @param string $redirect_url Default redirect URL.
	 * @param string $action       Bulk action slug.
	 * @param int[]  $post_ids     Selected post IDs.
	 * @return string
	 */
	public function handleBulkAction( string $redirect_url, string $action, array $post_ids ): string {
		if ( self::ACTION !== $action ) {
			return $redirect_url;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return $redirect_url;
		}

		$ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		if ( empty( $ids ) ) {
			return $redirect_url;
		}

		return add_query_arg(
			array(
				'sim_featured_ai'  => '1',
				'sim_featured_ids' => implode( ',', $ids ),
			),
			$redirect_url
		);
	}
}
