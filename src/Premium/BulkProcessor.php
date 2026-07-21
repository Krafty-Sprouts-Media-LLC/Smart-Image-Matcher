<?php
/**
 * Premium: Bulk Processor admin integration.
 *
 * Registers the admin menu page, enqueues bulk.js, and enables the
 * BulkController REST routes.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BulkProcessor
 *
 * @since 3.0.0
 */
class BulkProcessor {

	/**
	 * WordPress admin page hook returned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $pageHook = '';

	/**
	 * Register hooks.
	 *
	 * Called from Plugin::registerHooks() when Premium::has('bulk_processor').
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu',            array( $this, 'registerMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	/**
	 * Register the Bulk Processor submenu under SIM.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerMenu(): void {
		$this->pageHook = (string) add_submenu_page(
			'smart-image-matcher',
			__( 'Smart Image Matcher – Bulk Processor', 'smart-image-matcher' ),
			__( 'Bulk Processor', 'smart-image-matcher' ),
			'manage_options',
			'smart-image-matcher-bulk',
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Enqueue bulk.js + bulk.css on the Bulk Processor page only.
	 *
	 * @since 3.0.0
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueAssets( string $hook ): void {
		if ( $this->pageHook && $hook !== $this->pageHook ) {
			return;
		}

		if ( ! $this->pageHook && false === strpos( $hook, 'smart-image-matcher-bulk' ) ) {
			return;
		}

		$cssPath = SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/css/sim-bulk.css';
		$jsPath  = SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/js/src/bulk.js';

		wp_enqueue_style(
			'smart-image-matcher-bulk-css',
			SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/css/sim-bulk.css',
			array(),
			file_exists( $cssPath ) ? (string) filemtime( $cssPath ) : SMART_IMAGE_MATCHER_VERSION
		);

		wp_enqueue_script(
			'smart-image-matcher-bulk-js',
			SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/js/src/bulk.js',
			array( 'wp-api-fetch' ),
			file_exists( $jsPath ) ? (string) filemtime( $jsPath ) : SMART_IMAGE_MATCHER_VERSION,
			true
		);

		wp_localize_script(
			'smart-image-matcher-bulk-js',
			'smartImageMatcherBulk',
			array(
				'restBase'  => rest_url( 'smart-image-matcher/v1' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'postTypes' => $this->getPublicPostTypes(),
				'samplePostRefs' => $this->getSamplePostRefs(),
				'i18n'      => array(
					'selectPosts'    => __( 'Select Posts', 'smart-image-matcher' ),
					'configure'      => __( 'Configure', 'smart-image-matcher' ),
					'findMatches'    => __( 'Find Matches', 'smart-image-matcher' ),
					'reviewInsert'   => __( 'Review & Insert', 'smart-image-matcher' ),
					'approve'        => __( 'Approve', 'smart-image-matcher' ),
					'reject'         => __( 'Reject', 'smart-image-matcher' ),
					'insertApproved' => __( 'Insert Approved', 'smart-image-matcher' ),
					'cancel'         => __( 'Cancel', 'smart-image-matcher' ),
					'cancelReview'   => __( 'Cancel Review', 'smart-image-matcher' ),
					'noMatches'      => __( 'No matches found.', 'smart-image-matcher' ),
				),
			)
		);
	}

	/**
	 * Render the Bulk Processor page shell (bulk.js mounts the SPA).
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-image-matcher' ) );
		}
		require SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/views/bulk-processor.php';
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Get public post types for the post-type selector.
	 *
	 * @since 3.0.0
	 * @return array<string, string>  slug => label
	 */
	private function getPublicPostTypes(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		$result = array();
		foreach ( $types as $slug => $obj ) {
			$result[ $slug ] = $obj->labels->singular_name;
		}
		return $result;
	}

	/**
	 * Get a small site-specific example for the manual post import helper text.
	 *
	 * @since 3.0.0
	 * @return string
	 */
	private function getSamplePostRefs(): string {
		$postTypes = array_keys( $this->getPublicPostTypes() );
		if ( empty( $postTypes ) ) {
			return '123, 456, sample-post-slug';
		}

		$posts = get_posts( array(
			'post_type'              => $postTypes,
			'post_status'            => array( 'publish', 'draft', 'pending', 'future' ),
			'posts_per_page'         => 20,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );

		if ( empty( $posts ) ) {
			return '123, 456, sample-post-slug';
		}

		$sample = $posts[ array_rand( $posts ) ];
		if ( ! $sample instanceof \WP_Post || empty( $sample->post_name ) ) {
			return '123, 456, sample-post-slug';
		}

		return (string) $sample->ID . ', ' . (string) $sample->post_name;
	}
}
