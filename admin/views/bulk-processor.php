<?php
/**
 * Bulk Processor admin page.
 *
 * The four-step SPA is driven by bulk.js.
 * This file provides the WordPress admin shell and step containers.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-image-matcher' ) );
}
?>
<div class="wrap sim-admin-page sim-bulk-page" id="sim-bulk-app">
	<div class="sim-page-head">
		<div>
			<h1><?php esc_html_e( 'Bulk Processor', 'smart-image-matcher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Select posts, find heading/image matches, review candidates, and insert approved images.', 'smart-image-matcher' ); ?>
			</p>
		</div>
		<span class="sim-status sim-status-info"><?php esc_html_e( 'Batch workflow', 'smart-image-matcher' ); ?></span>
	</div>

	<nav class="sim-step-nav" aria-label="<?php esc_attr_e( 'Bulk Processor Steps', 'smart-image-matcher' ); ?>">
		<ol>
			<li class="sim-step-indicator" data-step="1">
				<span>1</span> <?php esc_html_e( 'Select Posts', 'smart-image-matcher' ); ?>
			</li>
			<li class="sim-step-indicator" data-step="2">
				<span>2</span> <?php esc_html_e( 'Configure', 'smart-image-matcher' ); ?>
			</li>
			<li class="sim-step-indicator" data-step="3">
				<span>3</span> <?php esc_html_e( 'Find Matches', 'smart-image-matcher' ); ?>
			</li>
			<li class="sim-step-indicator" data-step="4">
				<span>4</span> <?php esc_html_e( 'Review & Insert', 'smart-image-matcher' ); ?>
			</li>
		</ol>
	</nav>

	<div class="sim-card sim-bulk-step-wrap">
		<div class="sim-bulk-step" data-step="1">
			<p class="sim-bulk-loading">
				<?php esc_html_e( 'Loading Bulk Processor...', 'smart-image-matcher' ); ?>
			</p>
		</div>
		<div class="sim-bulk-step" data-step="2" style="display:none;"></div>
		<div class="sim-bulk-step" data-step="3" style="display:none;"></div>
		<div class="sim-bulk-step" data-step="4" style="display:none;"></div>
	</div>

	<noscript>
		<p class="notice notice-warning">
			<?php esc_html_e( 'The Bulk Processor requires JavaScript to be enabled.', 'smart-image-matcher' ); ?>
		</p>
	</noscript>
</div>
