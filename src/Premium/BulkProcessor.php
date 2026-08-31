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

use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Settings\Settings;

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
		$count = $this->countPendingArticles();
		$label = __( 'Bulk Processor', 'smart-image-matcher' );
		if ( $count > 0 ) {
			$label .= ' <span class="awaiting-mod">' . esc_html( (string) $count ) . '</span>';
		}

		$this->pageHook = (string) add_submenu_page(
			'smart-image-matcher',
			__( 'Smart Image Matcher – Bulk Processor', 'smart-image-matcher' ),
			$label,
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
			'smart-image-matcher-svg-icons',
			SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/js/src/svg-icons.js',
			array(),
			SMART_IMAGE_MATCHER_VERSION,
			true
		);

		wp_enqueue_script(
			'smart-image-matcher-bulk-js',
			SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/js/src/bulk.js',
			array( 'wp-api-fetch', 'smart-image-matcher-svg-icons' ),
			file_exists( $jsPath ) ? (string) filemtime( $jsPath ) : SMART_IMAGE_MATCHER_VERSION,
			true
		);

		$generationOn = (bool) Settings::get( 'ai_image_generation_enabled' )
			&& ProviderBridge::isImageGenerationAvailable();

		wp_localize_script(
			'smart-image-matcher-bulk-js',
			'smartImageMatcherBulk',
			array(
				'restBase'              => rest_url( 'smart-image-matcher/v1' ),
				'nonce'                 => wp_create_nonce( 'wp_rest' ),
				'postTypes'             => $this->getPublicPostTypes(),
				'samplePostRefs'        => $this->getSamplePostRefs(),
				'autoInsertThreshold'   => (int) Settings::get( 'auto_insert_threshold' ),
				'reviewThreshold'       => (int) Settings::get( 'confidence_threshold' ),
				'generationAvailable'   => $generationOn,
				'defaultStyle'          => (string) Settings::get( 'ai_image_style' ),
				'i18n'                  => array(
					'run'            => __( 'Run', 'smart-image-matcher' ),
					'review'         => __( 'Review', 'smart-image-matcher' ),
					'approve'        => __( 'Approve', 'smart-image-matcher' ),
					'reject'         => __( 'Reject', 'smart-image-matcher' ),
					'insertApproved' => __( 'Insert approved', 'smart-image-matcher' ),
					'cancel'         => __( 'Cancel run', 'smart-image-matcher' ),
					'noMatches'      => __( 'Nothing to review.', 'smart-image-matcher' ),
					'allPending'     => __( 'All pending', 'smart-image-matcher' ),
					'lastRun'        => __( 'Last run', 'smart-image-matcher' ),
					'noMissingFeatured' => __( 'No posts in this selection are missing a featured image.', 'smart-image-matcher' ),
					'changeSelection'   => __( 'Change selection', 'smart-image-matcher' ),
					'confirmCancelRun'  => __( 'Cancel the current run so you can change the selection?', 'smart-image-matcher' ),
					'confirmRecovery'   => __( 'Recover %d matched image(s) into WordPress? Unmatched images will not be imported.', 'smart-image-matcher' ),
					'confirmAuditClear' => __( 'Remove unsafe featured images from the scanned posts? Exact and prefix matches are left alone.', 'smart-image-matcher' ),
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
	 * Distinct posts with at least one pending review slot.
	 *
	 * @since 3.3.0
	 * @return int
	 */
	private function countPendingArticles(): int {
		global $wpdb;

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->prefix}smart_image_matcher_matches WHERE status = 'pending'"
		);

		return (int) $count;
	}

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
