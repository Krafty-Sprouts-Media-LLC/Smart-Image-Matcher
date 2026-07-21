<?php
/**
 * Plugin Name: Smart Image Matcher
 * Plugin URI:  https://kraftysprouts.com/portfolio/smart-image-matcher/
 * Description: Automatically scans the media library and intelligently attaches relevant images to headings within posts and pages. Offers keyword-based and AI-powered matching.
 * Version:     3.0.8
 * Author:      Krafty Sprouts Media, LLC
 * Author URI:  https://kraftysprouts.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smart-image-matcher
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package SmartImageMatcher
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- smart_image_matcher_ is the established plugin prefix for this project.
define( 'SMART_IMAGE_MATCHER_VERSION',       '3.0.8' );
define( 'SMART_IMAGE_MATCHER_PLUGIN_FILE',   __FILE__ );
define( 'SMART_IMAGE_MATCHER_PLUGIN_DIR',    plugin_dir_path( __FILE__ ) );
define( 'SMART_IMAGE_MATCHER_PLUGIN_URL',    plugin_dir_url( __FILE__ ) );
define( 'SMART_IMAGE_MATCHER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

// PSR-4 autoloader: SmartImageMatcher\ → src/
require_once SMART_IMAGE_MATCHER_PLUGIN_DIR . 'src/autoload.php';

// Action Scheduler (bundled via Composer for background processing).
// The library self-manages version conflicts — it runs whichever installed
// copy is newest, so multiple plugins bundling it is safe.
if ( file_exists( SMART_IMAGE_MATCHER_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
	require_once SMART_IMAGE_MATCHER_PLUGIN_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Plugin Update Checker (GitHub → WordPress admin updates).
if ( file_exists( SMART_IMAGE_MATCHER_PLUGIN_DIR . 'vendor/yahnis-elsts/plugin-update-checker/load-v5p7.php' ) ) {
	require_once SMART_IMAGE_MATCHER_PLUGIN_DIR . 'vendor/yahnis-elsts/plugin-update-checker/load-v5p7.php';
}

// Boot the plugin.
( new SmartImageMatcher\Plugin( __FILE__ ) )->boot();
