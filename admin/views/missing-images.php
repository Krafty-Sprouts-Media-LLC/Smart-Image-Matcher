<?php
/**
 * Missing Images admin page shell. missing-images.js renders the list.
 *
 * @package SmartImageMatcher
 * @since   3.6.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-image-matcher' ) );
}
?>
<div class="wrap sim-admin-page sim-missing-page" id="sim-missing-app">
	<div class="sim-mi-head">
		<div>
			<h1><?php esc_html_e( 'Missing Images', 'smart-image-matcher' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Every heading that still has no image under it, grouped by article. Copy what you need, find the images, upload them.', 'smart-image-matcher' ); ?></p>
		</div>
		<div class="sim-mi-actions">
			<label class="screen-reader-text" for="sim-mi-copy-as"><?php esc_html_e( 'Copy as', 'smart-image-matcher' ); ?></label>
			<select id="sim-mi-copy-as">
				<option value="phrase"><?php esc_html_e( 'Copy as: search phrases', 'smart-image-matcher' ); ?></option>
				<option value="asis"><?php esc_html_e( 'Copy as: headings as written', 'smart-image-matcher' ); ?></option>
				<option value="grouped"><?php esc_html_e( 'Copy as: grouped by article', 'smart-image-matcher' ); ?></option>
			</select>
			<button type="button" class="button" id="sim-mi-preview"><?php esc_html_e( 'Preview', 'smart-image-matcher' ); ?></button>
			<button type="button" class="button button-primary" id="sim-mi-copy-all"><?php esc_html_e( 'Copy all headings', 'smart-image-matcher' ); ?></button>
		</div>
	</div>

	<div class="sim-mi-loop">
		<div><span class="sim-mi-step"><?php esc_html_e( '1 · Copy', 'smart-image-matcher' ); ?></span><strong><?php esc_html_e( 'One search phrase per heading', 'smart-image-matcher' ); ?></strong><span><?php esc_html_e( 'Numbering removed, the article’s state added. Paste into any image search or bulk tool.', 'smart-image-matcher' ); ?></span></div>
		<div><span class="sim-mi-step"><?php esc_html_e( '2 · Save the image', 'smart-image-matcher' ); ?></span><strong><?php esc_html_e( 'Name it after the phrase', 'smart-image-matcher' ); ?></strong><span><?php esc_html_e( 'e.g. Eastern Garter Snake in Massachusetts.jpg', 'smart-image-matcher' ); ?></span></div>
		<div><span class="sim-mi-step"><?php esc_html_e( '3 · Upload to Media', 'smart-image-matcher' ); ?></span><strong><?php esc_html_e( 'The next run inserts it', 'smart-image-matcher' ); ?></strong><span><?php esc_html_e( 'The filename matches its heading, so each image lands in the right place.', 'smart-image-matcher' ); ?></span></div>
	</div>

	<div class="notice notice-info inline sim-mi-scan" id="sim-mi-scan" hidden><p></p></div>

	<div class="sim-mi-kpis">
		<div class="sim-mi-kpi"><span><?php esc_html_e( 'Articles missing images', 'smart-image-matcher' ); ?></span><strong id="sim-mi-k-articles">–</strong><small id="sim-mi-k-noimg"></small></div>
		<div class="sim-mi-kpi"><span><?php esc_html_e( 'Headings without an image', 'smart-image-matcher' ); ?></span><strong id="sim-mi-k-headings">–</strong><small><?php esc_html_e( 'across all indexed articles', 'smart-image-matcher' ); ?></small></div>
		<div class="sim-mi-kpi"><span><?php esc_html_e( 'Numbered headings', 'smart-image-matcher' ); ?></span><strong id="sim-mi-k-numbered">–</strong><small><?php esc_html_e( 'list items like “1. Red Fox”', 'smart-image-matcher' ); ?></small></div>
		<div class="sim-mi-kpi"><span><?php esc_html_e( 'Marked “not needed”', 'smart-image-matcher' ); ?></span><strong id="sim-mi-k-ignored">–</strong><small><?php esc_html_e( 'hidden from this list', 'smart-image-matcher' ); ?></small></div>
	</div>

	<div class="sim-mi-card">
		<div class="sim-mi-toolbar">
			<label class="screen-reader-text" for="sim-mi-search"><?php esc_html_e( 'Search', 'smart-image-matcher' ); ?></label>
			<input type="search" id="sim-mi-search" class="sim-mi-search" placeholder="<?php esc_attr_e( 'Search article title, heading or post ID…', 'smart-image-matcher' ); ?>" />
			<label class="sim-mi-toggle"><input type="checkbox" id="sim-mi-numbered" /><?php esc_html_e( 'Numbered only', 'smart-image-matcher' ); ?></label>
			<label class="sim-mi-toggle"><input type="checkbox" id="sim-mi-noimg" /><?php esc_html_e( 'No images at all', 'smart-image-matcher' ); ?></label>
			<label class="screen-reader-text" for="sim-mi-status"><?php esc_html_e( 'Status', 'smart-image-matcher' ); ?></label>
			<select id="sim-mi-status"><option value="all"><?php esc_html_e( 'Any status', 'smart-image-matcher' ); ?></option></select>
			<label class="screen-reader-text" for="sim-mi-type"><?php esc_html_e( 'Post type', 'smart-image-matcher' ); ?></label>
			<select id="sim-mi-type">
				<option value="all"><?php esc_html_e( 'Posts and pages', 'smart-image-matcher' ); ?></option>
				<option value="post"><?php esc_html_e( 'Posts', 'smart-image-matcher' ); ?></option>
				<option value="page"><?php esc_html_e( 'Pages', 'smart-image-matcher' ); ?></option>
			</select>
			<label class="screen-reader-text" for="sim-mi-cat"><?php esc_html_e( 'Category', 'smart-image-matcher' ); ?></label>
			<select id="sim-mi-cat"><option value="0"><?php esc_html_e( 'All categories', 'smart-image-matcher' ); ?></option></select>
			<label class="screen-reader-text" for="sim-mi-sort"><?php esc_html_e( 'Sort', 'smart-image-matcher' ); ?></label>
			<select id="sim-mi-sort">
				<option value="most"><?php esc_html_e( 'Most missing first', 'smart-image-matcher' ); ?></option>
				<option value="new"><?php esc_html_e( 'Newest first', 'smart-image-matcher' ); ?></option>
			</select>
		</div>
		<div class="sim-mi-meta">
			<span id="sim-mi-meta-text"></span>
			<span class="sim-mi-meta-acts">
				<button type="button" class="button button-primary button-small" id="sim-mi-copy-picked" hidden></button>
				<button type="button" class="button-link" id="sim-mi-expand"><?php esc_html_e( 'Expand all', 'smart-image-matcher' ); ?></button>
				<button type="button" class="button-link" id="sim-mi-collapse"><?php esc_html_e( 'Collapse all', 'smart-image-matcher' ); ?></button>
				<button type="button" class="button-link" id="sim-mi-rescan"><?php esc_html_e( 'Rescan', 'smart-image-matcher' ); ?></button>
			</span>
		</div>
		<div id="sim-mi-list" aria-live="polite"><p class="sim-mi-empty"><?php esc_html_e( 'Loading…', 'smart-image-matcher' ); ?></p></div>
		<div class="sim-mi-pager" id="sim-mi-pager" hidden>
			<span id="sim-mi-pager-text"></span>
			<span>
				<button type="button" class="button" id="sim-mi-prev"><?php esc_html_e( '‹ Previous', 'smart-image-matcher' ); ?></button>
				<button type="button" class="button" id="sim-mi-next"><?php esc_html_e( 'Next ›', 'smart-image-matcher' ); ?></button>
			</span>
		</div>
	</div>

	<div class="sim-mi-modal" id="sim-mi-modal" hidden>
		<div class="sim-mi-dialog" role="dialog" aria-modal="true" aria-labelledby="sim-mi-modal-title">
			<h2 id="sim-mi-modal-title"><?php esc_html_e( 'Preview', 'smart-image-matcher' ); ?></h2>
			<p class="description" id="sim-mi-modal-desc"></p>
			<div class="sim-mi-seg" role="group" aria-label="<?php esc_attr_e( 'Format', 'smart-image-matcher' ); ?>">
				<button type="button" data-fmt="phrase"><?php esc_html_e( 'Search phrases', 'smart-image-matcher' ); ?></button>
				<button type="button" data-fmt="asis"><?php esc_html_e( 'Headings as written', 'smart-image-matcher' ); ?></button>
				<button type="button" data-fmt="grouped"><?php esc_html_e( 'Grouped by article', 'smart-image-matcher' ); ?></button>
			</div>
			<label class="screen-reader-text" for="sim-mi-out"><?php esc_html_e( 'Text to copy', 'smart-image-matcher' ); ?></label>
			<textarea id="sim-mi-out" readonly></textarea>
			<div class="sim-mi-dialog-acts">
				<button type="button" class="button" id="sim-mi-modal-close"><?php esc_html_e( 'Close', 'smart-image-matcher' ); ?></button>
				<button type="button" class="button button-primary" id="sim-mi-modal-copy"><?php esc_html_e( 'Copy', 'smart-image-matcher' ); ?></button>
			</div>
		</div>
	</div>

	<div class="sim-mi-toast" id="sim-mi-toast" role="status" aria-live="polite" hidden><span></span><button type="button" class="button-link" hidden><?php esc_html_e( 'Undo', 'smart-image-matcher' ); ?></button></div>

	<noscript><p class="notice notice-warning"><?php esc_html_e( 'This page requires JavaScript.', 'smart-image-matcher' ); ?></p></noscript>
</div>
