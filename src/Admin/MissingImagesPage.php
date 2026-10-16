<?php
/**
 * Missing Images admin page: headings that still have no image, per article.
 *
 * @package SmartImageMatcher\Admin
 * @since   3.6.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Domain\MissingImagesIndex;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Class MissingImagesPage
 *
 * @since 3.6.0
 */
class MissingImagesPage {

	const SLUG = 'smart-image-matcher-missing';

	/**
	 * Admin page hook from add_submenu_page().
	 *
	 * @var string
	 */
	private string $pageHook = '';

	/**
	 * Register hooks.
	 *
	 * @since 3.6.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
	}

	/**
	 * Submenu under Smart Image Matcher.
	 *
	 * @since 3.6.0
	 * @return void
	 */
	public function registerMenu(): void {
		$this->pageHook = (string) add_submenu_page(
			'smart-image-matcher',
			__( 'Smart Image Matcher – Missing Images', 'smart-image-matcher' ),
			__( 'Missing Images', 'smart-image-matcher' ),
			'manage_options',
			self::SLUG,
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Render the page shell; missing-images.js fills it.
	 *
	 * @since 3.6.0
	 * @return void
	 */
	public function renderPage(): void {
		require SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/views/missing-images.php';
	}

	/**
	 * Assets on this page only.
	 *
	 * @since 3.6.0
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueueAssets( string $hook ): void {
		if ( $this->pageHook ? $hook !== $this->pageHook : false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		$css = SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/css/sim-missing.css';
		$js  = SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/js/src/missing-images.js';

		wp_enqueue_style(
			'smart-image-matcher-missing-css',
			SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/css/sim-missing.css',
			array(),
			file_exists( $css ) ? (string) filemtime( $css ) : SMART_IMAGE_MATCHER_VERSION
		);

		wp_enqueue_script(
			'smart-image-matcher-missing-js',
			SMART_IMAGE_MATCHER_PLUGIN_URL . 'admin/js/src/missing-images.js',
			array( 'wp-api-fetch' ),
			file_exists( $js ) ? (string) filemtime( $js ) : SMART_IMAGE_MATCHER_VERSION,
			true
		);

		$categories = array();
		foreach ( get_categories( array( 'hide_empty' => true, 'number' => 300 ) ) as $cat ) {
			$categories[] = array(
				'id'   => (int) $cat->term_id,
				'name' => html_entity_decode( (string) $cat->name, ENT_QUOTES, 'UTF-8' ),
			);
		}

		$statuses = array();
		foreach ( MissingImagesIndex::STATUSES as $status ) {
			$obj        = get_post_status_object( $status );
			$statuses[] = array(
				'value' => $status,
				'label' => $obj ? (string) $obj->label : $status,
			);
		}

		wp_localize_script(
			'smart-image-matcher-missing-js',
			'smartImageMatcherMissing',
			array(
				'restPath'   => '/smart-image-matcher/v1',
				'categories' => $categories,
				'statuses'   => $statuses,
				'i18n'       => array(
					'copied'          => __( 'Copied', 'smart-image-matcher' ),
					'copyFailed'      => __( 'Copy failed. Select the text in Preview and copy it there.', 'smart-image-matcher' ),
					'loadFailed'      => __( 'Could not load the list. Reload the page to try again.', 'smart-image-matcher' ),
					'nothing'         => __( 'No headings match these filters.', 'smart-image-matcher' ),
					'nothingHint'     => __( 'Clear a filter, or every heading here already has an image.', 'smart-image-matcher' ),
					'scanning'        => __( 'Building the list in the background: %1$s of %2$s articles checked. Totals fill in as it goes.', 'smart-image-matcher' ),
					'noLibrary'       => __( 'No close library images. Find one with the search links, save it under the search phrase and upload it.', 'smart-image-matcher' ),
					'inserted'        => __( 'Inserted under the heading', 'smart-image-matcher' ),
					'hidden'          => __( 'Hidden from the list', 'smart-image-matcher' ),
					'undo'            => __( 'Undo', 'smart-image-matcher' ),
					'rescanStarted'   => __( 'Rescan started. The list refreshes as articles are checked.', 'smart-image-matcher' ),
					'capped'          => __( 'Copied the first 2,000 articles. Narrow the filters to copy the rest.', 'smart-image-matcher' ),
				),
			)
		);
	}
}
