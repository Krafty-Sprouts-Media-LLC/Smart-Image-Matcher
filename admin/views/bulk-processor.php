<?php
/**
 * Bulk Processor admin page: Run | Review | Schedule | Tools.
 *
 * bulk.js renders Run, Review and Schedule; Tools is mostly static markup.
 *
 * @package SmartImageMatcher
 * @since   3.0.0 (four-tab layout since 3.7.0)
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-image-matcher' ) );
}

$smart_image_matcher_tab = isset( $_GET['sim_tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['sim_tab'] ) ) : 'run'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $smart_image_matcher_tab, array( 'run', 'review', 'schedule', 'tools' ), true ) ) {
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
$smart_image_matcher_remove    = isset( $_GET['sim_remove_ids'] ) ? implode( ',', array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( (string) $_GET['sim_remove_ids'] ) ) ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- prefill only; removal needs a REST nonce.
$smart_image_matcher_post_type = isset( $_GET['sim_post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['sim_post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$smart_image_matcher_tabs = array(
	'run'      => __( 'Run', 'smart-image-matcher' ),
	'review'   => __( 'Review', 'smart-image-matcher' ),
	'schedule' => __( 'Schedule', 'smart-image-matcher' ),
	'tools'    => __( 'Tools', 'smart-image-matcher' ),
);
?>
<div class="wrap sim-admin-page sim-bulk-page sim-bp" id="sim-bulk-app"
	data-sim-tab="<?php echo esc_attr( $smart_image_matcher_tab ); ?>"
	data-sim-run="<?php echo esc_attr( $smart_image_matcher_run ); ?>"
	data-sim-mode="<?php echo esc_attr( $smart_image_matcher_mode ); ?>"
	data-sim-post-ids="<?php echo esc_attr( $smart_image_matcher_post_ids ); ?>"
	data-sim-post-type="<?php echo esc_attr( $smart_image_matcher_post_type ); ?>"
	data-sim-remove-ids="<?php echo esc_attr( $smart_image_matcher_remove ); ?>">

	<div class="sim-bp-head">
		<div>
			<h1><?php esc_html_e( 'Bulk Processor', 'smart-image-matcher' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Match library images to many articles at once, then decide the uncertain ones.', 'smart-image-matcher' ); ?></p>
		</div>
	</div>
	<?php // Admin notices are moved here by WordPress instead of inside the header row. ?>
	<hr class="wp-header-end" />

	<div class="sim-bp-health is-pending" id="sim-bp-health">
		<span class="sim-bp-dot" aria-hidden="true"></span>
		<strong id="sim-bp-health-title"><?php esc_html_e( 'Checking automatic runs…', 'smart-image-matcher' ); ?></strong>
		<span class="sim-bp-health-meta" id="sim-bp-health-meta"></span>
		<span class="sim-bp-grow"></span>
		<button type="button" class="button button-small" data-goto-tab="schedule"><?php esc_html_e( 'See schedule', 'smart-image-matcher' ); ?></button>
	</div>

	<nav class="sim-bp-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Bulk Processor', 'smart-image-matcher' ); ?>">
		<?php foreach ( $smart_image_matcher_tabs as $smart_image_matcher_key => $smart_image_matcher_label ) : ?>
			<a role="tab" class="sim-bp-tab<?php echo $smart_image_matcher_key === $smart_image_matcher_tab ? ' is-on' : ''; ?>"
				aria-selected="<?php echo $smart_image_matcher_key === $smart_image_matcher_tab ? 'true' : 'false'; ?>"
				data-tab="<?php echo esc_attr( $smart_image_matcher_key ); ?>"
				href="<?php echo esc_url( admin_url( 'admin.php?page=smart-image-matcher-bulk&sim_tab=' . $smart_image_matcher_key ) ); ?>">
				<?php echo esc_html( $smart_image_matcher_label ); ?>
				<?php if ( 'run' === $smart_image_matcher_key ) : ?>
					<span class="sim-bp-count is-live" id="sim-run-pill" hidden><?php esc_html_e( 'Running', 'smart-image-matcher' ); ?></span>
				<?php elseif ( 'review' === $smart_image_matcher_key ) : ?>
					<span class="sim-bp-count" id="sim-review-count" hidden></span>
				<?php elseif ( 'schedule' === $smart_image_matcher_key ) : ?>
					<span class="sim-bp-count" id="sim-schedule-pill" hidden></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<section class="sim-bulk-panel" data-panel="run" <?php echo 'run' === $smart_image_matcher_tab ? '' : 'hidden'; ?>>
		<p class="sim-bp-loading"><?php esc_html_e( 'Loading…', 'smart-image-matcher' ); ?></p>
	</section>

	<section class="sim-bulk-panel" data-panel="review" <?php echo 'review' === $smart_image_matcher_tab ? '' : 'hidden'; ?>></section>

	<section class="sim-bulk-panel" data-panel="schedule" <?php echo 'schedule' === $smart_image_matcher_tab ? '' : 'hidden'; ?>></section>

	<section class="sim-bulk-panel" data-panel="tools" <?php echo 'tools' === $smart_image_matcher_tab ? '' : 'hidden'; ?>>
		<div class="sim-bp-tools">
			<div class="sim-bp-card sim-bp-tool sim-bp-tool-wide" id="sim-remove-images">
				<h2><?php esc_html_e( 'Remove images from articles', 'smart-image-matcher' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Takes images out of the article body. The featured image is never touched, and images inside a gallery are left alone. Each article is saved once, so WordPress keeps a revision you can restore. Images this plugin added will not be suggested for those headings again.', 'smart-image-matcher' ); ?></p>
				<div class="sim-bp-fields">
					<div class="sim-bp-field sim-bp-full">
						<label for="sim-remove-ids"><?php esc_html_e( 'Articles', 'smart-image-matcher' ); ?></label>
						<textarea id="sim-remove-ids" rows="2" placeholder="<?php esc_attr_e( 'Post IDs, comma separated — or choose “Remove images from body…” in the Posts list bulk actions', 'smart-image-matcher' ); ?>"></textarea>
					</div>
					<div class="sim-bp-field">
						<span class="sim-bp-label" id="sim-remove-scope-label"><?php esc_html_e( 'Remove', 'smart-image-matcher' ); ?></span>
						<div class="sim-bp-seg is-stack" role="radiogroup" aria-labelledby="sim-remove-scope-label">
							<label><input type="radio" name="sim-remove-scope" value="all" checked /><?php esc_html_e( 'Every image in the body', 'smart-image-matcher' ); ?></label>
							<label><input type="radio" name="sim-remove-scope" value="plugin" /><?php esc_html_e( 'Only images added by Smart Image Matcher', 'smart-image-matcher' ); ?></label>
						</div>
					</div>
					<div class="sim-bp-field">
						<span class="sim-bp-label"><?php esc_html_e( 'Afterwards', 'smart-image-matcher' ); ?></span>
						<label><input type="checkbox" id="sim-remove-ignore" /> <?php esc_html_e( 'Ignore these articles (no run adds images to them again)', 'smart-image-matcher' ); ?></label>
					</div>
				</div>
				<div class="sim-bp-actions">
					<button type="button" id="sim-remove-preview" class="button"><?php esc_html_e( 'Preview', 'smart-image-matcher' ); ?></button>
					<button type="button" id="sim-remove-run" class="button sim-bp-solid-danger" disabled><?php esc_html_e( 'Remove images', 'smart-image-matcher' ); ?></button>
					<span class="sim-bp-help" id="sim-remove-status"></span>
				</div>
				<div class="sim-bp-table-wrap">
					<table class="widefat striped" id="sim-remove-table" hidden>
						<thead><tr>
							<td class="check-column"><input type="checkbox" id="sim-remove-all" checked aria-label="<?php esc_attr_e( 'Select all', 'smart-image-matcher' ); ?>" /></td>
							<th><?php esc_html_e( 'Article', 'smart-image-matcher' ); ?></th>
							<th><?php esc_html_e( 'Images in the body', 'smart-image-matcher' ); ?></th>
							<th><?php esc_html_e( 'Added by this plugin', 'smart-image-matcher' ); ?></th>
							<th><?php esc_html_e( 'Result', 'smart-image-matcher' ); ?></th>
						</tr></thead>
						<tbody id="sim-remove-body"></tbody>
					</table>
				</div>
			</div>

			<div class="sim-bp-card sim-bp-tool sim-bp-tool-wide" id="sim-misplaced">
				<h2><?php esc_html_e( 'Featured images stuck in the article body', 'smart-image-matcher' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Articles with no featured image whose own image (exact or prefix filename match) sits in the body, usually under the first heading. Moving sets it as the featured image and removes it from the body. Preview first.', 'smart-image-matcher' ); ?></p>
				<div class="sim-bp-fields">
					<div class="sim-bp-field">
						<label for="sim-misplaced-type"><?php esc_html_e( 'Post type', 'smart-image-matcher' ); ?></label>
						<select id="sim-misplaced-type"></select>
					</div>
					<div class="sim-bp-field">
						<span class="sim-bp-label"><?php esc_html_e( 'Status', 'smart-image-matcher' ); ?></span>
						<div class="sim-bp-chips">
							<label class="sim-bp-chip"><input type="checkbox" class="sim-misplaced-status" value="publish" /> <?php esc_html_e( 'Published', 'smart-image-matcher' ); ?></label>
							<label class="sim-bp-chip"><input type="checkbox" class="sim-misplaced-status" value="draft" checked /> <?php esc_html_e( 'Draft', 'smart-image-matcher' ); ?></label>
							<label class="sim-bp-chip"><input type="checkbox" class="sim-misplaced-status" value="future" checked /> <?php esc_html_e( 'Scheduled', 'smart-image-matcher' ); ?></label>
							<label class="sim-bp-chip"><input type="checkbox" class="sim-misplaced-status" value="pending" /> <?php esc_html_e( 'Pending', 'smart-image-matcher' ); ?></label>
							<label class="sim-bp-chip"><input type="checkbox" class="sim-misplaced-status" value="private" /> <?php esc_html_e( 'Private', 'smart-image-matcher' ); ?></label>
						</div>
					</div>
				</div>
				<div class="sim-bp-actions">
					<button type="button" id="sim-misplaced-scan" class="button"><?php esc_html_e( 'Scan', 'smart-image-matcher' ); ?></button>
					<button type="button" id="sim-misplaced-move" class="button button-primary" disabled><?php esc_html_e( 'Move to featured image', 'smart-image-matcher' ); ?></button>
					<span class="sim-bp-help" id="sim-misplaced-status"></span>
				</div>
				<div class="sim-bp-table-wrap">
					<table class="widefat striped" id="sim-misplaced-table" hidden>
						<thead><tr>
							<td class="check-column"><input type="checkbox" id="sim-misplaced-all" checked aria-label="<?php esc_attr_e( 'Select all', 'smart-image-matcher' ); ?>" /></td>
							<th><?php esc_html_e( 'Image in the body', 'smart-image-matcher' ); ?></th>
							<th><?php esc_html_e( 'Article', 'smart-image-matcher' ); ?></th>
							<th><?php esc_html_e( 'Result', 'smart-image-matcher' ); ?></th>
						</tr></thead>
						<tbody id="sim-misplaced-body"></tbody>
					</table>
				</div>
			</div>

			<div class="sim-bp-card sim-bp-tool">
				<h2><?php esc_html_e( 'Fix incorrect featured images', 'smart-image-matcher' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Find featured images whose filename fails today’s rules (not an exact or prefix match), then remove them so the next run can assign a better one.', 'smart-image-matcher' ); ?></p>
				<div class="sim-bp-fields">
					<div class="sim-bp-field">
						<label for="sim-audit-post-type"><?php esc_html_e( 'Post type', 'smart-image-matcher' ); ?></label>
						<select id="sim-audit-post-type"></select>
					</div>
					<div class="sim-bp-field">
						<span class="sim-bp-label"><?php esc_html_e( 'Status', 'smart-image-matcher' ); ?></span>
						<div class="sim-bp-chips">
							<label class="sim-bp-chip"><input type="checkbox" class="sim-audit-status" value="publish" /> <?php esc_html_e( 'Published', 'smart-image-matcher' ); ?></label>
							<label class="sim-bp-chip"><input type="checkbox" class="sim-audit-status" value="draft" checked /> <?php esc_html_e( 'Draft', 'smart-image-matcher' ); ?></label>
							<label class="sim-bp-chip"><input type="checkbox" class="sim-audit-status" value="pending" /> <?php esc_html_e( 'Pending', 'smart-image-matcher' ); ?></label>
							<label class="sim-bp-chip"><input type="checkbox" class="sim-audit-status" value="private" /> <?php esc_html_e( 'Private', 'smart-image-matcher' ); ?></label>
						</div>
					</div>
				</div>
				<div class="sim-bp-actions">
					<button type="button" id="sim-fiaa-audit-scan-button" class="button"><?php esc_html_e( 'Scan', 'smart-image-matcher' ); ?></button>
					<button type="button" id="sim-fiaa-audit-clear-button" class="button button-primary" disabled><?php esc_html_e( 'Remove unsafe featured images', 'smart-image-matcher' ); ?></button>
				</div>
				<div id="sim-fiaa-audit-notice"></div>
				<div id="sim-fiaa-audit-summary" hidden>
					<p class="sim-bp-kv"><span><?php esc_html_e( 'With a featured image', 'smart-image-matcher' ); ?></span> <strong id="sim-fiaa-audit-total-assigned">0</strong> · <span><?php esc_html_e( 'Safe', 'smart-image-matcher' ); ?></span> <strong class="sim-good" id="sim-fiaa-audit-safe">0</strong> · <span><?php esc_html_e( 'Unsafe', 'smart-image-matcher' ); ?></span> <strong class="sim-bad" id="sim-fiaa-audit-unsafe">0</strong></p>
					<div class="sim-bp-table-wrap">
						<table class="widefat striped" id="sim-fiaa-audit-table" hidden>
							<thead><tr><th><?php esc_html_e( 'Post', 'smart-image-matcher' ); ?></th><th><?php esc_html_e( 'Post slug', 'smart-image-matcher' ); ?></th><th><?php esc_html_e( 'Image slug', 'smart-image-matcher' ); ?></th><th><?php esc_html_e( 'Method', 'smart-image-matcher' ); ?></th><th><?php esc_html_e( 'Score', 'smart-image-matcher' ); ?></th></tr></thead>
							<tbody id="sim-fiaa-audit-body"></tbody>
						</table>
					</div>
					<p id="sim-fiaa-audit-preview-note" class="description" hidden></p>
				</div>
			</div>

			<div class="sim-bp-card sim-bp-tool">
				<h2><?php esc_html_e( 'Recover finished fal.ai images', 'smart-image-matcher' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Images that finished on fal.ai but never reached your Media Library. Preview first; only confirmed matches are imported. Uses the post statuses ticked on Run.', 'smart-image-matcher' ); ?></p>
				<div class="sim-bp-field">
					<label for="sim-fal-recovery-hours"><?php esc_html_e( 'Look back', 'smart-image-matcher' ); ?></label>
					<select id="sim-fal-recovery-hours">
						<option value="24"><?php esc_html_e( 'Last 24 hours', 'smart-image-matcher' ); ?></option>
						<option value="48" selected><?php esc_html_e( 'Last 48 hours', 'smart-image-matcher' ); ?></option>
						<option value="72"><?php esc_html_e( 'Last 72 hours', 'smart-image-matcher' ); ?></option>
						<option value="168"><?php esc_html_e( 'Last 7 days', 'smart-image-matcher' ); ?></option>
					</select>
				</div>
				<div class="sim-bp-actions">
					<button type="button" id="sim-fal-recovery-preview-button" class="button"><?php esc_html_e( 'Preview', 'smart-image-matcher' ); ?></button>
					<button type="button" id="sim-fal-recovery-run-button" class="button button-primary" disabled><?php esc_html_e( 'Import matched images', 'smart-image-matcher' ); ?></button>
				</div>
				<div id="sim-fal-recovery-notice"></div>
				<div id="sim-fal-recovery-summary" hidden>
					<p class="sim-bp-kv"><span><?php esc_html_e( 'Safe matches', 'smart-image-matcher' ); ?></span> <strong id="sim-fal-recovery-matched">0</strong> · <span><?php esc_html_e( 'Unmatched (not imported)', 'smart-image-matcher' ); ?></span> <strong id="sim-fal-recovery-unmatched">0</strong></p>
				</div>
				<div class="sim-bp-table-wrap">
					<table class="widefat striped" id="sim-fal-recovery-table" hidden>
						<thead><tr><th><?php esc_html_e( 'Post', 'smart-image-matcher' ); ?></th><th><?php esc_html_e( 'fal request', 'smart-image-matcher' ); ?></th><th><?php esc_html_e( 'Prompt', 'smart-image-matcher' ); ?></th><th><?php esc_html_e( 'Status', 'smart-image-matcher' ); ?></th></tr></thead>
						<tbody id="sim-fal-recovery-body"></tbody>
					</table>
				</div>
			</div>

			<div class="sim-bp-card sim-bp-tool">
				<h2><?php esc_html_e( 'Blocked images', 'smart-image-matcher' ); ?> <span class="sim-bp-count" id="sim-blocked-count">0</span></h2>
				<p class="description"><?php esc_html_e( 'Never chosen as a featured image on any post. They can still be inserted under headings. Unblock to let them be suggested as featured images again.', 'smart-image-matcher' ); ?></p>
				<div class="sim-bp-list" id="sim-blocked-list"></div>
			</div>

			<div class="sim-bp-card sim-bp-tool">
				<h2><?php esc_html_e( 'Ignored articles', 'smart-image-matcher' ); ?> <span class="sim-bp-count" id="sim-ignored-count">0</span></h2>
				<p class="description"><?php esc_html_e( 'No run — manual, scheduled or on publish — touches these. Un-ignore to let them be processed again.', 'smart-image-matcher' ); ?></p>
				<div class="sim-bp-list" id="sim-ignored-list"></div>
			</div>
		</div>
	</section>

	<div class="sim-bp-dock" id="sim-bp-dock" hidden>
		<div class="sim-bp-tallies">
			<span><strong id="sim-dock-approved">0</strong> <?php esc_html_e( 'approved', 'smart-image-matcher' ); ?></span>
			<span><strong id="sim-dock-rejected">0</strong> <?php esc_html_e( 'rejected', 'smart-image-matcher' ); ?></span>
			<span class="sim-bp-keys"><kbd>A</kbd> <?php esc_html_e( 'approve', 'smart-image-matcher' ); ?> · <kbd>R</kbd> <?php esc_html_e( 'reject here', 'smart-image-matcher' ); ?> · <kbd>J</kbd>/<kbd>K</kbd> <?php esc_html_e( 'move', 'smart-image-matcher' ); ?></span>
		</div>
		<span class="sim-bp-grow"></span>
		<button type="button" class="button button-primary button-hero" id="sim-insert-approved"><?php esc_html_e( 'Insert approved', 'smart-image-matcher' ); ?></button>
	</div>

	<div class="sim-bp-toast" id="sim-bp-toast" role="status" aria-live="polite" hidden><span></span><button type="button" class="button-link" hidden><?php esc_html_e( 'Undo', 'smart-image-matcher' ); ?></button></div>

	<div id="sim-gen-modal" class="sim-modal" hidden>
		<div class="sim-modal-backdrop" data-sim-gen-cancel></div>
		<div class="sim-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="sim-gen-modal-title">
			<h2 id="sim-gen-modal-title"><?php esc_html_e( 'Generate featured images?', 'smart-image-matcher' ); ?></h2>
			<p id="sim-gen-modal-body"></p>
			<div class="sim-gen-estimate"><?php esc_html_e( 'Time varies by model — often a few minutes each. Uses your connected image provider (fal.ai).', 'smart-image-matcher' ); ?></div>
			<div class="sim-step-actions">
				<button type="button" class="button" data-sim-gen-cancel><?php esc_html_e( 'Cancel', 'smart-image-matcher' ); ?></button>
				<button type="button" class="button button-primary" id="sim-gen-confirm"><?php esc_html_e( 'Generate featured images', 'smart-image-matcher' ); ?></button>
			</div>
		</div>
	</div>

	<div id="sim-review-modal" class="sim-modal" hidden>
		<div class="sim-modal-backdrop" data-sim-review-close></div>
		<div class="sim-modal-dialog sim-review-dialog" role="dialog" aria-modal="true" aria-labelledby="sim-review-modal-title">
			<div class="sim-review-modal-head">
				<h2 id="sim-review-modal-title"></h2>
				<button type="button" class="button-link" data-sim-review-close><?php esc_html_e( 'Close', 'smart-image-matcher' ); ?></button>
			</div>
			<p class="description" id="sim-review-modal-meta"></p>
			<div class="sim-review-modal-figure"><img id="sim-review-modal-img" alt="" /></div>
			<div class="sim-step-actions">
				<button type="button" class="button" id="sim-review-modal-prev"><?php esc_html_e( 'Previous', 'smart-image-matcher' ); ?></button>
				<button type="button" class="button" id="sim-review-modal-next"><?php esc_html_e( 'Next', 'smart-image-matcher' ); ?></button>
				<span id="sim-review-modal-status" class="sim-slot-state" hidden></span>
				<button type="button" class="button" id="sim-review-modal-undo" hidden><?php esc_html_e( 'Undo', 'smart-image-matcher' ); ?></button>
				<button type="button" class="button" id="sim-review-modal-reject"><?php esc_html_e( 'Reject here', 'smart-image-matcher' ); ?></button>
				<button type="button" class="button button-primary" id="sim-review-modal-approve"><?php esc_html_e( 'Approve', 'smart-image-matcher' ); ?></button>
			</div>
		</div>
	</div>

	<noscript><p class="notice notice-warning"><?php esc_html_e( 'The Bulk Processor requires JavaScript to be enabled.', 'smart-image-matcher' ); ?></p></noscript>
</div>
