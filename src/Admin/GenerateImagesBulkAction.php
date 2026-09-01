<?php
/**
 * Posts list bulk actions and per-row Generate for featured images.
 *
 * Admins are sent to Bulk Processor with the selection prefilled. Editors
 * without manage_options still get the list-screen generate modal. A Generate
 * row action opens that modal for a single post.
 *
 * @package SmartImageMatcher\Admin
 * @since   3.2.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

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
	 * Bulk action slug for article processing.
	 *
	 * @since 3.3.0
	 * @var string
	 */
	private const ACTION_PROCESS = 'sim_process_articles';

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

		add_filter( 'post_row_actions', array( $this, 'addRowAction' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'addRowAction' ), 10, 2 );

		// Keep pagination / filter links from re-carrying the one-shot modal args.
		add_filter( 'removable_query_args', array( $this, 'removableQueryArgs' ) );
	}

	/**
	 * Strip one-shot modal query args from admin-generated URLs (pagination, etc.).
	 *
	 * @since 3.2.14
	 * @param string[] $args Removable query arg names.
	 * @return string[]
	 */
	public function removableQueryArgs( array $args ): array {
		$args[] = 'sim_featured_ai';
		$args[] = 'sim_featured_ids';
		$args[] = 'sim_mode';
		$args[] = 'sim_post_ids';
		$args[] = 'sim_post_type';
		return $args;
	}

	/**
	 * Add "Generate featured images…" to the bulk actions dropdown.
	 *
	 * @since 3.2.0
	 * @param array<string, string> $actions Existing bulk actions.
	 * @return array<string, string>
	 */
	public function addBulkAction( array $actions ): array {
		$actions[ self::ACTION_PROCESS ] = __( 'Process articles…', 'smart-image-matcher' );
		$actions[ self::ACTION ]         = __( 'Generate featured images…', 'smart-image-matcher' );
		return $actions;
	}

	/**
	 * Add a per-row Generate link next to Edit / Quick Edit.
	 *
	 * Opens the list-screen featured-image modal for that one post (JS intercepts
	 * the click; the href is a fallback that auto-opens after reload).
	 *
	 * @since 3.3.4
	 * @param array<string, string> $actions Existing row actions.
	 * @param \WP_Post              $post    Current row post.
	 * @return array<string, string>
	 */
	public function addRowAction( array $actions, $post ): array {
		if ( ! $post instanceof \WP_Post ) {
			return $actions;
		}

		if ( 'attachment' === $post->post_type ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
			return $actions;
		}

		$url = add_query_arg(
			array(
				'sim_featured_ai'  => '1',
				'sim_featured_ids' => (string) (int) $post->ID,
			)
		);

		$link = sprintf(
			'<a href="%1$s" class="sim-generate-featured" data-post-id="%2$d" title="%3$s">%4$s</a>',
			esc_url( $url ),
			(int) $post->ID,
			esc_attr__( 'Generate featured image', 'smart-image-matcher' ),
			esc_html__( 'Generate', 'smart-image-matcher' )
		);

		$placed  = false;
		$updated = array();
		foreach ( $actions as $key => $html ) {
			if ( ! $placed && ( 'trash' === $key || 'delete' === $key ) ) {
				$updated['sim_generate'] = $link;
				$placed                  = true;
			}
			$updated[ $key ] = $html;
		}

		if ( ! $placed ) {
			$updated['sim_generate'] = $link;
		}

		return $updated;
	}

	/**
	 * Route selected posts to Bulk Processor (admins) or the list modal.
	 *
	 * @since 3.2.0
	 * @param string $redirect_url Default redirect URL.
	 * @param string $action       Bulk action slug.
	 * @param int[]  $post_ids     Selected post IDs.
	 * @return string
	 */
	public function handleBulkAction( string $redirect_url, string $action, array $post_ids ): string {
		if ( self::ACTION !== $action && self::ACTION_PROCESS !== $action ) {
			return $redirect_url;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return $redirect_url;
		}

		$ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		if ( empty( $ids ) ) {
			return $redirect_url;
		}

		$mode = self::ACTION_PROCESS === $action ? 'process' : 'generate-featured';

		if ( current_user_can( 'manage_options' ) ) {
			$post_type = '';
			if ( function_exists( 'get_current_screen' ) ) {
				$screen = get_current_screen();
				if ( $screen && ! empty( $screen->post_type ) ) {
					$post_type = (string) $screen->post_type;
				}
			}
			if ( '' === $post_type ) {
				$first     = get_post( $ids[0] );
				$post_type = $first instanceof \WP_Post ? (string) $first->post_type : 'post';
			}

			return add_query_arg(
				array(
					'page'          => 'smart-image-matcher-bulk',
					'sim_tab'       => 'run',
					'sim_mode'      => $mode,
					'sim_post_ids'  => implode( ',', $ids ),
					'sim_post_type' => $post_type,
				),
				admin_url( 'admin.php' )
			);
		}

		if ( self::ACTION !== $action ) {
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
