<?php
/**
 * Bulk Processor admin page.
 *
 * Persistent Run | Review. The SPA in bulk.js fills the panels.
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

$smart_image_matcher_tab = isset( $_GET['sim_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['sim_tab'] ) ) : 'run'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( 'review' !== $smart_image_matcher_tab ) {
	$smart_image_matcher_tab = 'run';
}

$smart_image_matcher_run = isset( $_GET['sim_run'] ) ? sanitize_key( wp_unslash( (string) $_GET['sim_run'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( 'last' !== $smart_image_matcher_run ) {
	$smart_image_matcher_run = 'all';
}

$smart_image_matcher_mode = isset( $_GET['sim_mode'] ) ? sanitize_key( wp_unslash( (string) $_GET['sim_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $smart_image_matcher_mode, array( 'process', 'generate-featured' ), true ) ) {
	$smart_image_matcher_mode = '';
}

$smart_image_matcher_post_ids  = isset( $_GET['sim_post_ids'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['sim_post_ids'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$smart_image_matcher_post_type = isset( $_GET['sim_post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['sim_post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap sim-admin-page sim-bulk-page" id="sim-bulk-app"
	data-sim-tab="<?php echo esc_attr( $smart_image_matcher_tab ); ?>"
	data-sim-run="<?php echo esc_attr( $smart_image_matcher_run ); ?>"
	data-sim-mode="<?php echo esc_attr( $smart_image_matcher_mode ); ?>"
	data-sim-post-ids="<?php echo esc_attr( $smart_image_matcher_post_ids ); ?>"
	data-sim-post-type="<?php echo esc_attr( $smart_image_matcher_post_type ); ?>">
	<div class="sim-page-head">
		<div>
			<h1><?php esc_html_e( 'Bulk Processor', 'smart-image-matcher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Run article processing now, or review the middle band — including leftovers from scheduled and publish runs.', 'smart-image-matcher' ); ?>
			</p>
		</div>
	</div>

	<nav class="sim-tabs" aria-label="<?php esc_attr_e( 'Bulk Processor', 'smart-image-matcher' ); ?>">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-image-matcher-bulk&sim_tab=run' ) ); ?>" class="sim-tab<?php echo 'run' === $smart_image_matcher_tab ? ' is-on' : ''; ?>" data-tab="run">
			<?php esc_html_e( 'Run', 'smart-image-matcher' ); ?>
			<span class="sim-tab-pill" id="sim-run-pill" hidden><?php esc_html_e( 'In progress', 'smart-image-matcher' ); ?></span>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-image-matcher-bulk&sim_tab=review' ) ); ?>" class="sim-tab<?php echo 'review' === $smart_image_matcher_tab ? ' is-on' : ''; ?>" data-tab="review">
			<?php esc_html_e( 'Review', 'smart-image-matcher' ); ?>
		</a>
	</nav>

	<div class="sim-card sim-bulk-step-wrap">
		<div class="sim-bulk-panel" data-panel="run" <?php echo 'run' === $smart_image_matcher_tab ? '' : 'hidden'; ?>>
			<p class="sim-bulk-loading"><?php esc_html_e( 'Loading Bulk Processor…', 'smart-image-matcher' ); ?></p>
		</div>
		<div class="sim-bulk-panel" data-panel="review" <?php echo 'review' === $smart_image_matcher_tab ? '' : 'hidden'; ?>></div>
	</div>

	<details class="sim-card sim-bulk-tools" id="sim-run-tools" <?php echo 'run' === $smart_image_matcher_tab ? '' : 'hidden'; ?>>
		<summary><?php esc_html_e( 'Recover completed fal.ai images', 'smart-image-matcher' ); ?></summary>
		<p class="description">
			<?php esc_html_e( 'Find images that completed on fal.ai but never reached WordPress. Preview first, then recover confirmed matches. Uses the Post Status checkboxes on Run (Publish recommended).', 'smart-image-matcher' ); ?>
		</p>
		<div class="sim-recovery-controls">
			<div class="sim-field">
				<label for="sim-fal-recovery-hours"><?php esc_html_e( 'Look back', 'smart-image-matcher' ); ?></label>
				<select id="sim-fal-recovery-hours">
					<option value="24"><?php esc_html_e( 'Last 24 hours', 'smart-image-matcher' ); ?></option>
					<option value="48" selected><?php esc_html_e( 'Last 48 hours', 'smart-image-matcher' ); ?></option>
					<option value="72"><?php esc_html_e( 'Last 72 hours', 'smart-image-matcher' ); ?></option>
					<option value="168"><?php esc_html_e( 'Last 7 days', 'smart-image-matcher' ); ?></option>
				</select>
			</div>
			<div class="sim-form-actions">
				<button type="button" id="sim-fal-recovery-preview-button" class="button"><?php esc_html_e( 'Preview Recovery', 'smart-image-matcher' ); ?></button>
				<button type="button" id="sim-fal-recovery-run-button" class="button button-primary" disabled><?php esc_html_e( 'Recover Matched Images', 'smart-image-matcher' ); ?></button>
			</div>
		</div>
		<div id="sim-fal-recovery-notice"></div>
		<div id="sim-fal-recovery-summary" class="sim-info-rows" hidden>
			<div class="sim-info-row"><span><?php esc_html_e( 'Safe matches', 'smart-image-matcher' ); ?></span><strong id="sim-fal-recovery-matched">0</strong></div>
			<div class="sim-info-row"><span><?php esc_html_e( 'Unmatched (not imported)', 'smart-image-matcher' ); ?></span><strong id="sim-fal-recovery-unmatched">0</strong></div>
		</div>
		<table class="widefat striped" id="sim-fal-recovery-table" hidden>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Post', 'smart-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'fal Request', 'smart-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Prompt', 'smart-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Status / Reason', 'smart-image-matcher' ); ?></th>
				</tr>
			</thead>
			<tbody id="sim-fal-recovery-body"></tbody>
		</table>
	</details>

	<details class="sim-card sim-bulk-tools" id="sim-review-tools" <?php echo 'review' === $smart_image_matcher_tab ? '' : 'hidden'; ?>>
		<summary><?php esc_html_e( 'Fix incorrect featured images', 'smart-image-matcher' ); ?></summary>
		<p class="description">
			<?php esc_html_e( 'Find posts whose featured image filename does not pass current safety rules, then clear those assignments. Exact and prefix matches are left alone. After cleanup, process articles to assign from the library.', 'smart-image-matcher' ); ?>
		</p>
		<div class="sim-form-grid sim-bulk-form-grid">
			<div class="sim-field">
				<label for="sim-audit-post-type"><?php esc_html_e( 'Post type', 'smart-image-matcher' ); ?></label>
				<select id="sim-audit-post-type">
					<option value="post"><?php esc_html_e( 'Posts', 'smart-image-matcher' ); ?></option>
					<option value="page"><?php esc_html_e( 'Pages', 'smart-image-matcher' ); ?></option>
				</select>
			</div>
			<div class="sim-field">
				<span class="sim-label"><?php esc_html_e( 'Post status', 'smart-image-matcher' ); ?></span>
				<div class="sim-checkbox-grid">
					<label><input type="checkbox" class="sim-audit-status" value="publish" checked /> <?php esc_html_e( 'Published', 'smart-image-matcher' ); ?></label>
					<label><input type="checkbox" class="sim-audit-status" value="draft" checked /> <?php esc_html_e( 'Draft', 'smart-image-matcher' ); ?></label>
					<label><input type="checkbox" class="sim-audit-status" value="pending" /> <?php esc_html_e( 'Pending', 'smart-image-matcher' ); ?></label>
					<label><input type="checkbox" class="sim-audit-status" value="private" /> <?php esc_html_e( 'Private', 'smart-image-matcher' ); ?></label>
				</div>
			</div>
		</div>
		<div class="sim-form-actions">
			<button type="button" id="sim-fiaa-audit-scan-button" class="button"><?php esc_html_e( 'Scan for unsafe featured images', 'smart-image-matcher' ); ?></button>
			<button type="button" id="sim-fiaa-audit-clear-button" class="button button-primary" disabled><?php esc_html_e( 'Clear unsafe featured images', 'smart-image-matcher' ); ?></button>
		</div>
		<div id="sim-fiaa-audit-notice"></div>
		<div id="sim-fiaa-audit-summary" class="sim-audit-summary" hidden>
			<div class="sim-info-rows">
				<div class="sim-info-row"><span><?php esc_html_e( 'Posts with featured image', 'smart-image-matcher' ); ?></span><strong id="sim-fiaa-audit-total-assigned">0</strong></div>
				<div class="sim-info-row"><span><?php esc_html_e( 'Safe assignments', 'smart-image-matcher' ); ?></span><strong class="sim-good" id="sim-fiaa-audit-safe">0</strong></div>
				<div class="sim-info-row"><span><?php esc_html_e( 'Unsafe assignments', 'smart-image-matcher' ); ?></span><strong class="sim-bad" id="sim-fiaa-audit-unsafe">0</strong></div>
			</div>
			<table class="widefat striped" id="sim-fiaa-audit-table" hidden>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'smart-image-matcher' ); ?></th>
						<th><?php esc_html_e( 'Post slug', 'smart-image-matcher' ); ?></th>
						<th><?php esc_html_e( 'Image slug', 'smart-image-matcher' ); ?></th>
						<th><?php esc_html_e( 'Method', 'smart-image-matcher' ); ?></th>
						<th><?php esc_html_e( 'Score', 'smart-image-matcher' ); ?></th>
					</tr>
				</thead>
				<tbody id="sim-fiaa-audit-body"></tbody>
			</table>
			<p id="sim-fiaa-audit-preview-note" class="description" hidden></p>
		</div>
	</details>

	<div id="sim-gen-modal" class="sim-modal" hidden>
		<div class="sim-modal-backdrop" data-sim-gen-cancel></div>
		<div class="sim-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="sim-gen-modal-title">
			<h2 id="sim-gen-modal-title"><?php esc_html_e( 'Generate featured images?', 'smart-image-matcher' ); ?></h2>
			<p id="sim-gen-modal-body"></p>
			<div class="sim-gen-estimate">
				<?php esc_html_e( 'Time varies by model — often a few minutes each. Uses your connected image provider (fal.ai).', 'smart-image-matcher' ); ?>
			</div>
			<div class="sim-step-actions">
				<button type="button" class="button" data-sim-gen-cancel><?php esc_html_e( 'Cancel', 'smart-image-matcher' ); ?></button>
				<button type="button" class="button button-primary" id="sim-gen-confirm"><?php esc_html_e( 'Generate featured images', 'smart-image-matcher' ); ?></button>
			</div>
		</div>
	</div>

	<noscript>
		<p class="notice notice-warning">
			<?php esc_html_e( 'The Bulk Processor requires JavaScript to be enabled.', 'smart-image-matcher' ); ?>
		</p>
	</noscript>
</div>
