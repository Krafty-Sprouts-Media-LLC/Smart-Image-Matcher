<?php
/**
 * Featured Images — AI Generate card (missing featured only).
 *
 * Included from featured-images.php. Heading images stay in the editor modal.
 *
 * @package SmartImageMatcher
 * @since   3.2.3
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables.

use SmartImageMatcher\AI\ProviderBridge;
use SmartImageMatcher\Domain\PostStatuses;
use SmartImageMatcher\Settings\Settings;

$sim_ai_post_types         = get_post_types( array( 'public' => true ), 'objects' );
$sim_ai_queryable_statuses = PostStatuses::queryable();
$sim_ai_generation_enabled = (bool) Settings::get( 'ai_image_generation_enabled' );
$sim_ai_provider_ready     = ProviderBridge::isImageGenerationAvailable();
$sim_ai_default_style      = (string) Settings::get( 'ai_image_style' );
if ( ! in_array( $sim_ai_default_style, array( 'photo', 'illustration' ), true ) ) {
	$sim_ai_default_style = 'photo';
}

$sim_ai_prefill_ids = array();
if ( isset( $_GET['post_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only prefill.
	$raw_ids            = sanitize_text_field( wp_unslash( (string) $_GET['post_ids'] ) );
	$sim_ai_prefill_ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
}

unset( $sim_ai_post_types['attachment'] );
?>
<div id="sim-featured-ai" class="sim-card sim-run-card" style="margin-top:24px">
	<div class="sim-card-head">
		<div>
			<h2><?php esc_html_e( 'AI Generate Featured Images', 'smart-image-matcher' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Create one AI featured image per post that is missing a thumbnail. Does not generate images under headings — use the post editor Smart Image Matcher modal for that.', 'smart-image-matcher' ); ?>
			</p>
		</div>
		<span class="sim-status sim-status-info"><?php esc_html_e( 'AI', 'smart-image-matcher' ); ?></span>
	</div>

	<div class="sim-card-body">
		<?php if ( ! $sim_ai_generation_enabled || ! $sim_ai_provider_ready ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php if ( ! $sim_ai_generation_enabled ) : ?>
						<?php esc_html_e( 'On-demand image generation is disabled. Enable it under Smart Image Matcher → Settings → AI Image Generation.', 'smart-image-matcher' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'No image-capable AI provider is configured. Connect fal.ai under Settings → Connectors and choose a preferred image model.', 'smart-image-matcher' ); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $sim_ai_prefill_ids ) ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %d: number of posts */
						esc_html(
							_n(
								'%d post was passed in. Scan to see which still need a featured image.',
								'%d posts were passed in. Scan to see which still need a featured image.',
								count( $sim_ai_prefill_ids ),
								'smart-image-matcher'
							)
						),
						count( $sim_ai_prefill_ids )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form id="sim-generate-images-form">
			<div class="sim-form-grid">
				<div class="sim-field">
					<label for="sim-generate-post-type"><?php esc_html_e( 'Post Type', 'smart-image-matcher' ); ?></label>
					<select id="sim-generate-post-type" name="post_type" <?php disabled( ! empty( $sim_ai_prefill_ids ) ); ?>>
						<?php foreach ( $sim_ai_post_types as $slug => $obj ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( 'post', $slug ); ?>>
								<?php echo esc_html( $obj->labels->singular_name . ' (' . $slug . ')' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="sim-field">
					<span class="sim-label"><?php esc_html_e( 'Post Status', 'smart-image-matcher' ); ?></span>
					<fieldset id="sim-generate-post-statuses" class="sim-checkbox-group sim-checkbox-col">
						<?php foreach ( $sim_ai_queryable_statuses as $status_slug => $status_object ) : ?>
							<label>
								<input
									type="checkbox"
									name="post_statuses[]"
									value="<?php echo esc_attr( $status_slug ); ?>"
									<?php checked( in_array( $status_slug, array( 'publish', 'draft' ), true ) ); ?>
								/>
								<span><?php echo esc_html( $status_object->label ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</div>

				<div class="sim-field">
					<label for="sim-generate-style"><?php esc_html_e( 'Image Style', 'smart-image-matcher' ); ?></label>
					<select id="sim-generate-style" name="style">
						<option value="photo" <?php selected( $sim_ai_default_style, 'photo' ); ?>><?php esc_html_e( 'Photo (realistic)', 'smart-image-matcher' ); ?></option>
						<option value="illustration" <?php selected( $sim_ai_default_style, 'illustration' ); ?>><?php esc_html_e( 'Illustration', 'smart-image-matcher' ); ?></option>
					</select>
				</div>

				<div class="sim-field">
					<label for="sim-generate-max-posts"><?php esc_html_e( 'Max Posts', 'smart-image-matcher' ); ?></label>
					<input type="number" id="sim-generate-max-posts" name="max_posts" value="100" min="1" max="500" step="1" <?php disabled( ! empty( $sim_ai_prefill_ids ) ); ?> />
					<p class="description"><?php esc_html_e( 'Limit how many posts are scanned when no specific IDs are selected.', 'smart-image-matcher' ); ?></p>
				</div>
			</div>

			<div class="sim-form-actions">
				<button type="button" id="sim-generate-scan-button" class="button button-primary">
					<?php esc_html_e( 'Scan', 'smart-image-matcher' ); ?>
				</button>
				<button type="button" id="sim-generate-all-button" class="button" disabled aria-disabled="true">
					<?php esc_html_e( 'Generate Featured Images', 'smart-image-matcher' ); ?>
				</button>
			</div>
		</form>

		<div id="sim-generate-notice" aria-live="polite"></div>

		<div id="sim-generate-estimate" class="sim-info-rows" style="display:none" aria-live="polite">
			<div class="sim-info-row">
				<span><?php esc_html_e( 'Featured images to generate', 'smart-image-matcher' ); ?></span>
				<strong id="sim-generate-total-images">0</strong>
			</div>
			<div class="sim-info-row">
				<span><?php esc_html_e( 'Typical wait', 'smart-image-matcher' ); ?></span>
				<strong id="sim-generate-estimate-time"><?php esc_html_e( '—', 'smart-image-matcher' ); ?></strong>
			</div>
		</div>

		<table class="widefat striped sim-recent-table" id="sim-generate-results-table" style="display:none">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Post', 'smart-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Status', 'smart-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Edit', 'smart-image-matcher' ); ?></th>
				</tr>
			</thead>
			<tbody id="sim-generate-results-body"></tbody>
		</table>

		<div id="sim-generate-progress" class="sim-run-progress" style="display:none" aria-live="polite">
			<h3><?php esc_html_e( 'Generation Progress', 'smart-image-matcher' ); ?></h3>
			<div class="sim-progress-bar-wrap">
				<div class="sim-progress-bar" aria-label="<?php esc_attr_e( 'Featured image generation progress', 'smart-image-matcher' ); ?>">
					<div id="sim-generate-progress-fill" class="sim-progress-fill" style="width:0%"></div>
				</div>
				<strong id="sim-generate-progress-percent" class="sim-progress-percent">0%</strong>
			</div>
			<p id="sim-generate-progress-status" class="description sim-run-status"></p>
		</div>

		<hr class="sim-card-divider" />

		<section id="sim-fal-recovery" class="sim-recovery-panel" aria-labelledby="sim-fal-recovery-title">
			<div class="sim-section-head">
				<div>
					<h3 id="sim-fal-recovery-title"><?php esc_html_e( 'Recover completed fal.ai images', 'smart-image-matcher' ); ?></h3>
					<p class="description">
						<?php esc_html_e( 'Find images that completed on fal.ai but never reached WordPress. Matches use the post title and SEO focus/target keyword (Rank Math, Yoast, SEOPress, or The SEO Framework) against the fal prompt — only posts still missing a featured image. Preview first, then recover confirmed matches in the background.', 'smart-image-matcher' ); ?>
					</p>
				</div>
			</div>

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
					<button type="button" id="sim-fal-recovery-preview-button" class="button" <?php disabled( ! $sim_ai_provider_ready ); ?>>
						<?php esc_html_e( 'Preview Recovery', 'smart-image-matcher' ); ?>
					</button>
					<button type="button" id="sim-fal-recovery-run-button" class="button button-primary" disabled aria-disabled="true">
						<?php esc_html_e( 'Recover Matched Images', 'smart-image-matcher' ); ?>
					</button>
				</div>
			</div>

			<div id="sim-fal-recovery-notice" aria-live="polite"></div>

			<div id="sim-fal-recovery-summary" class="sim-info-rows" hidden>
				<div class="sim-info-row">
					<span><?php esc_html_e( 'Safe matches', 'smart-image-matcher' ); ?></span>
					<strong id="sim-fal-recovery-matched">0</strong>
				</div>
				<div class="sim-info-row">
					<span><?php esc_html_e( 'Unmatched (not imported)', 'smart-image-matcher' ); ?></span>
					<strong id="sim-fal-recovery-unmatched">0</strong>
				</div>
			</div>

			<table class="widefat striped sim-recent-table" id="sim-fal-recovery-table" hidden>
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
		</section>
	</div>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
