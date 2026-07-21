<?php
/**
 * Featured Images admin page.
 *
 * Post coverage stats + manual and scheduled featured image assignment.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables are scoped to this admin view include.

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-image-matcher' ) );
}

use SmartImageMatcher\Domain\PostStatuses;
use SmartImageMatcher\Settings\Settings;

global $wpdb;

$total_posts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT COUNT(*) FROM {$wpdb->posts}
	 WHERE post_type = 'post' AND post_status IN ('publish','draft')"
);

$with_thumbnail = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
	 WHERE p.post_type = 'post' AND p.post_status IN ('publish','draft')
	   AND CAST(pm.meta_value AS UNSIGNED) > 0"
);

$without              = max( 0, $total_posts - $with_thumbnail );
$pct                  = $total_posts > 0 ? (int) round( ( $with_thumbnail / $total_posts ) * 100 ) : 0;
$post_types           = get_post_types( array( 'public' => true ), 'objects' );
$last_summary         = get_option( 'smart_image_matcher_runtime', array() );
$queryable_statuses   = PostStatuses::queryable();
$manual_post_type     = sanitize_key( (string) Settings::get( 'fiaa_manual_post_type' ) );
$manual_statuses      = PostStatuses::sanitizeList( (string) Settings::get( 'fiaa_manual_post_statuses' ) );
$manual_featured      = (string) Settings::get( 'fiaa_manual_featured_filter' );
$manual_max_posts     = max( 1, min( 50000, (int) Settings::get( 'fiaa_manual_max_posts' ) ) );
$manual_overwrite     = (bool) Settings::get( 'fiaa_manual_overwrite' );

if ( ! post_type_exists( $manual_post_type ) || 'attachment' === $manual_post_type ) {
	$manual_post_type = 'post';
}

if ( ! in_array( $manual_featured, array( 'any', 'missing', 'has' ), true ) ) {
	$manual_featured = 'missing';
}

unset( $post_types['attachment'] );
?>
<div class="wrap sim-admin-page sim-featured-page sim-featured-v6">
	<div class="sim-page-head">
		<div>
			<h1><?php esc_html_e( 'Featured Images', 'smart-image-matcher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Find posts whose slug matches an image filename, then set the matching image as the featured image.', 'smart-image-matcher' ); ?>
			</p>
		</div>
		<div class="sim-page-actions">
			<button type="button" id="sim-fiaa-run-button" class="button button-primary" data-default-label="<?php esc_attr_e( 'Match Runner', 'smart-image-matcher' ); ?>">
				<?php esc_html_e( 'Match Runner', 'smart-image-matcher' ); ?>
			</button>
			<button type="button" id="sim-fiaa-cancel-button" class="button" disabled>
				<?php esc_html_e( 'Cancel Run', 'smart-image-matcher' ); ?>
			</button>
		</div>
	</div>

	<div class="sim-featured-dual">
		<div class="sim-featured-main">
			<div id="sim-fiaa-runner" class="sim-card sim-run-card">
				<div class="sim-card-head">
					<div>
						<h2><?php esc_html_e( 'Match Runner', 'smart-image-matcher' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Fill missing featured images now instead of waiting for the scheduled run.', 'smart-image-matcher' ); ?>
						</p>
					</div>
					<span class="sim-status sim-status-info"><?php esc_html_e( 'Manual', 'smart-image-matcher' ); ?></span>
				</div>

				<div class="sim-card-body">
					<div class="sim-fiaa-notice-box">
						<p><strong><?php esc_html_e( 'Recommended:', 'smart-image-matcher' ); ?></strong> <?php esc_html_e( 'Leave Featured Image set to "Missing featured image" for normal runs. That only queues posts that still need a featured image.', 'smart-image-matcher' ); ?></p>
						<p><?php esc_html_e( 'Turn on Overwrite Existing only when you intentionally want Smart Image Matcher to replace featured images that are already set.', 'smart-image-matcher' ); ?></p>
					</div>

					<form id="sim-fiaa-run-form">
						<div class="sim-form-grid">
							<div class="sim-field">
								<label for="smart_image_matcher_fiaa_post_type"><?php esc_html_e( 'Post Type', 'smart-image-matcher' ); ?></label>
								<select name="smart_image_matcher_fiaa_post_type" id="smart_image_matcher_fiaa_post_type">
									<?php foreach ( $post_types as $slug => $obj ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $manual_post_type, $slug ); ?>>
											<?php echo esc_html( $obj->labels->singular_name . ' (' . $slug . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Choose the content type to scan. Most sites should use Post.', 'smart-image-matcher' ); ?></p>
							</div>

							<div class="sim-field">
								<label for="smart_image_matcher_fiaa_safety"><?php esc_html_e( 'Auto-assign Safety', 'smart-image-matcher' ); ?></label>
								<select id="smart_image_matcher_fiaa_safety" disabled>
									<option><?php esc_html_e( 'Strict: exact and prefix', 'smart-image-matcher' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Risky matches are held instead of assigned. Only exact and prefix filename matches are auto-assigned.', 'smart-image-matcher' ); ?></p>
							</div>

							<div class="sim-field">
								<span class="sim-label"><?php esc_html_e( 'Post Status', 'smart-image-matcher' ); ?></span>
								<fieldset id="smart_image_matcher_fiaa_post_statuses" class="sim-checkbox-group sim-checkbox-col">
									<?php foreach ( $queryable_statuses as $status_slug => $status_object ) : ?>
										<label>
											<input
												type="checkbox"
												name="smart_image_matcher_fiaa_post_statuses[]"
												value="<?php echo esc_attr( $status_slug ); ?>"
												<?php checked( in_array( $status_slug, $manual_statuses, true ) ); ?>
											/>
											<span><?php echo esc_html( $status_object->label ); ?></span>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Select every status you want included. Save your choices before running so they persist on your next visit.', 'smart-image-matcher' ); ?></p>
							</div>

							<div class="sim-field">
								<label for="smart_image_matcher_fiaa_featured_filter"><?php esc_html_e( 'Featured Image', 'smart-image-matcher' ); ?></label>
								<select name="smart_image_matcher_fiaa_featured_filter" id="smart_image_matcher_fiaa_featured_filter">
									<option value="missing" <?php selected( $manual_featured, 'missing' ); ?>><?php esc_html_e( 'Missing featured image', 'smart-image-matcher' ); ?></option>
									<option value="any" <?php selected( $manual_featured, 'any' ); ?>><?php esc_html_e( 'Any featured image state', 'smart-image-matcher' ); ?></option>
									<option value="has" <?php selected( $manual_featured, 'has' ); ?>><?php esc_html_e( 'Has featured image', 'smart-image-matcher' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Normal cleanup = Missing. "Any" checks everything. "Has" is useful with overwrite.', 'smart-image-matcher' ); ?></p>
							</div>

							<div class="sim-field">
								<label for="smart_image_matcher_fiaa_max_posts"><?php esc_html_e( 'Max Posts', 'smart-image-matcher' ); ?></label>
								<input type="number" name="smart_image_matcher_fiaa_max_posts" id="smart_image_matcher_fiaa_max_posts" value="<?php echo esc_attr( (string) $manual_max_posts ); ?>" min="1" max="50000" step="50" />
								<p class="description"><?php esc_html_e( 'Limits how many posts are queued in this manual run.', 'smart-image-matcher' ); ?></p>
							</div>

							<div class="sim-field">
								<div class="sim-overwrite-row">
									<div class="sim-overwrite-copy">
										<strong><?php esc_html_e( 'Overwrite Existing', 'smart-image-matcher' ); ?></strong>
										<span><?php esc_html_e( 'Replace already-assigned featured images.', 'smart-image-matcher' ); ?></span>
									</div>
									<label class="sim-check-row">
										<input type="checkbox" name="smart_image_matcher_fiaa_overwrite" value="1" <?php checked( $manual_overwrite ); ?> />
									</label>
								</div>
							</div>
						</div>

						<div class="sim-form-actions">
							<button type="button" id="sim-fiaa-save-button" class="button button-secondary">
								<?php esc_html_e( 'Save Run Settings', 'smart-image-matcher' ); ?>
							</button>
							<p class="description"><?php esc_html_e( 'Save post status, featured image filter, and other run options. Match Runner also saves these settings automatically when you start a run.', 'smart-image-matcher' ); ?></p>
						</div>
					</form>

					<div id="sim-fiaa-notice" aria-live="polite"></div>

					<div id="sim-fiaa-progress" class="sim-run-progress" style="display:none" aria-live="polite">
						<h3><?php esc_html_e( 'Run Progress', 'smart-image-matcher' ); ?></h3>
						<p class="description"><?php esc_html_e( 'You can leave this page after the run starts. The job continues in the background and this panel reconnects when you return.', 'smart-image-matcher' ); ?></p>
						<div class="sim-progress-bar-wrap">
							<div class="sim-progress-bar" aria-label="<?php esc_attr_e( 'Match Runner progress', 'smart-image-matcher' ); ?>">
								<div id="sim-fiaa-progress-fill" class="sim-progress-fill" style="width:0%"></div>
							</div>
							<strong id="sim-fiaa-progress-percent" class="sim-progress-percent">0%</strong>
						</div>
						<p id="sim-fiaa-status" class="description sim-run-status"></p>

						<table class="widefat striped sim-recent-table" id="sim-fiaa-recent-table" style="display:none">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Recent post', 'smart-image-matcher' ); ?></th>
									<th><?php esc_html_e( 'Slug', 'smart-image-matcher' ); ?></th>
									<th><?php esc_html_e( 'Status', 'smart-image-matcher' ); ?></th>
									<th><?php esc_html_e( 'Image', 'smart-image-matcher' ); ?></th>
								</tr>
							</thead>
							<tbody id="sim-fiaa-recent-body"></tbody>
						</table>
					</div>
				</div>
			</div>

			<div id="sim-fiaa-audit" class="sim-card sim-audit-card">
				<div class="sim-card-head">
					<div>
						<h2><?php esc_html_e( 'Fix Incorrect Featured Images', 'smart-image-matcher' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Find posts that already have a featured image but the filename does not pass current safety rules, then remove those assignments in bulk.', 'smart-image-matcher' ); ?>
						</p>
					</div>
					<span class="sim-status sim-status-warn"><?php esc_html_e( 'Cleanup', 'smart-image-matcher' ); ?></span>
				</div>

				<div class="sim-card-body">
					<div class="sim-fiaa-notice-box">
						<p><?php esc_html_e( 'Use this when older runs assigned the wrong image (for example, token-overlap matches like season vs regulations). Safe exact and prefix matches are left alone.', 'smart-image-matcher' ); ?></p>
						<p><?php esc_html_e( 'After cleanup, run Match Runner with Missing featured image to assign correct images from filenames that match your post slugs.', 'smart-image-matcher' ); ?></p>
					</div>

					<div class="sim-form-actions">
						<button type="button" id="sim-fiaa-audit-scan-button" class="button button-secondary">
							<?php esc_html_e( 'Scan for Unsafe Featured Images', 'smart-image-matcher' ); ?>
						</button>
						<button type="button" id="sim-fiaa-audit-clear-button" class="button button-primary" disabled>
							<?php esc_html_e( 'Clear Unsafe Featured Images', 'smart-image-matcher' ); ?>
						</button>
					</div>

					<div id="sim-fiaa-audit-notice" aria-live="polite"></div>

					<div id="sim-fiaa-audit-summary" class="sim-audit-summary" style="display:none" aria-live="polite">
						<div class="sim-info-rows">
							<div class="sim-info-row"><span><?php esc_html_e( 'Posts with featured image', 'smart-image-matcher' ); ?></span><strong id="sim-fiaa-audit-total-assigned">0</strong></div>
							<div class="sim-info-row"><span><?php esc_html_e( 'Safe assignments', 'smart-image-matcher' ); ?></span><strong class="sim-good" id="sim-fiaa-audit-safe">0</strong></div>
							<div class="sim-info-row"><span><?php esc_html_e( 'Unsafe assignments', 'smart-image-matcher' ); ?></span><strong class="sim-bad" id="sim-fiaa-audit-unsafe">0</strong></div>
						</div>

						<table class="widefat striped sim-recent-table" id="sim-fiaa-audit-table" style="display:none">
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
						<p id="sim-fiaa-audit-preview-note" class="description" style="display:none"></p>
					</div>
				</div>
			</div>

			<?php
			$cron_enabled         = (bool) Settings::get( 'fiaa_cron_enabled' );
			$cron_interval        = (string) Settings::get( 'fiaa_cron_interval' );
			$cron_types           = (string) Settings::get( 'fiaa_cron_post_types' );
			$cron_statuses        = (string) Settings::get( 'fiaa_cron_post_statuses' );
			$cron_featured_filter = (string) Settings::get( 'fiaa_cron_featured_filter' );
			$next_scheduled       = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( 'smart_image_matcher_fiaa_scheduled_run', array(), 'smart-image-matcher' ) : false;
			$next_scheduled_label = $next_scheduled
				? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $next_scheduled )
				: __( 'Not scheduled yet', 'smart-image-matcher' );
			?>
			<div class="sim-card sim-scheduler-card">
				<div class="sim-card-head">
					<div>
						<h2><?php esc_html_e( 'Scheduled Auto-Assignment', 'smart-image-matcher' ); ?></h2>
						<p class="description">
							<?php esc_html_e( 'Runs automatically in the background. Daily means roughly once every 24 hours.', 'smart-image-matcher' ); ?>
						</p>
					</div>
					<span class="sim-status <?php echo esc_attr( $cron_enabled ? 'sim-status-good' : 'sim-status-warn' ); ?>">
						<?php echo $cron_enabled ? esc_html__( 'Enabled', 'smart-image-matcher' ) : esc_html__( 'Disabled', 'smart-image-matcher' ); ?>
					</span>
				</div>

				<div class="sim-card-body">
					<div class="sim-info-rows">
						<div class="sim-info-row"><span><?php esc_html_e( 'Status', 'smart-image-matcher' ); ?></span><strong><?php echo $cron_enabled ? esc_html__( 'Enabled', 'smart-image-matcher' ) : esc_html__( 'Disabled', 'smart-image-matcher' ); ?></strong></div>
						<div class="sim-info-row"><span><?php esc_html_e( 'Interval', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( $cron_interval ); ?></strong></div>
						<div class="sim-info-row"><span><?php esc_html_e( 'Post types', 'smart-image-matcher' ); ?></span><code><?php echo esc_html( $cron_types ); ?></code></div>
						<div class="sim-info-row"><span><?php esc_html_e( 'Post statuses', 'smart-image-matcher' ); ?></span><code><?php echo esc_html( $cron_statuses ); ?></code></div>
						<div class="sim-info-row"><span><?php esc_html_e( 'Featured filter', 'smart-image-matcher' ); ?></span><code><?php echo esc_html( $cron_featured_filter ); ?></code></div>
						<div class="sim-info-row"><span><?php esc_html_e( 'Next action', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( $next_scheduled_label ); ?></strong></div>
					</div>

					<div class="sim-help-box">
						<p><?php esc_html_e( 'The scheduled run uses these settings until you change them. With the default Missing filter and Overwrite off, posts that already have a featured image are not touched.', 'smart-image-matcher' ); ?></p>
						<p><?php esc_html_e( 'Scheduled work depends on WordPress cron and Action Scheduler. If your host blocks background processing, the action may wait until site traffic or a server cron triggers it.', 'smart-image-matcher' ); ?></p>
					</div>

					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-image-matcher-settings#smart_image_matcher_fiaa_cron' ) ); ?>" class="button">
							<?php esc_html_e( 'Edit automation settings', 'smart-image-matcher' ); ?>
						</a>
					</p>

					<?php if ( is_array( $last_summary ) && ! empty( $last_summary['ran_at'] ) ) : ?>
						<div class="sim-sec-div">
							<h3><?php esc_html_e( 'Last Scheduled Run', 'smart-image-matcher' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Matched means an image was assigned. Unmatched means no safe filename match was found. Skipped means the post was intentionally left alone.', 'smart-image-matcher' ); ?></p>
							<div class="sim-info-rows">
								<div class="sim-info-row"><span><?php esc_html_e( 'Ran at', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( (string) $last_summary['ran_at'] ); ?></strong></div>
								<div class="sim-info-row"><span><?php esc_html_e( 'Matched', 'smart-image-matcher' ); ?></span><strong class="sim-good"><?php echo esc_html( (string) (int) ( $last_summary['matched'] ?? 0 ) ); ?></strong></div>
								<div class="sim-info-row"><span><?php esc_html_e( 'Skipped', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( (string) (int) ( $last_summary['skipped'] ?? 0 ) ); ?></strong></div>
								<div class="sim-info-row"><span><?php esc_html_e( 'Unmatched', 'smart-image-matcher' ); ?></span><strong class="sim-bad"><?php echo esc_html( (string) (int) ( $last_summary['unmatched'] ?? 0 ) ); ?></strong></div>
								<div class="sim-info-row"><span><?php esc_html_e( 'Total processed', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( (string) (int) ( $last_summary['total'] ?? 0 ) ); ?></strong></div>
								<div class="sim-info-row"><span><?php esc_html_e( 'Duration', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( (string) (int) ( $last_summary['duration_ms'] ?? 0 ) ); ?> ms</strong></div>
								<div class="sim-info-row"><span><?php esc_html_e( 'Statuses used', 'smart-image-matcher' ); ?></span><code><?php echo esc_html( implode( ',', array_map( 'sanitize_key', (array) ( $last_summary['post_statuses'] ?? array() ) ) ) ); ?></code></div>
								<div class="sim-info-row"><span><?php esc_html_e( 'Filter used', 'smart-image-matcher' ); ?></span><code><?php echo esc_html( (string) ( $last_summary['featured_filter'] ?? '' ) ); ?></code></div>
							</div>
						</div>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'No scheduled runs recorded yet.', 'smart-image-matcher' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<aside class="sim-featured-sidebar">
			<div class="sim-card sim-coverage-card">
				<div class="sim-card-head">
					<div>
						<h2><?php esc_html_e( 'Coverage Overview', 'smart-image-matcher' ); ?></h2>
					</div>
				</div>
				<div class="sim-met-row">
					<div class="sim-met-item">
						<span class="sim-met-label"><?php esc_html_e( 'Total', 'smart-image-matcher' ); ?></span>
						<strong class="sim-met-value"><?php echo esc_html( number_format_i18n( $total_posts ) ); ?></strong>
						<span class="sim-met-sub"><?php esc_html_e( 'posts', 'smart-image-matcher' ); ?></span>
					</div>
					<div class="sim-met-item">
						<span class="sim-met-label"><?php esc_html_e( 'With Image', 'smart-image-matcher' ); ?></span>
						<strong class="sim-met-value sim-good"><?php echo esc_html( number_format_i18n( $with_thumbnail ) ); ?></strong>
						<span class="sim-met-sub sim-good"><?php echo esc_html( (string) $pct ); ?>%</span>
					</div>
					<div class="sim-met-item">
						<span class="sim-met-label"><?php esc_html_e( 'Missing', 'smart-image-matcher' ); ?></span>
						<strong class="sim-met-value <?php echo esc_attr( $without > 0 ? 'sim-bad' : 'sim-good' ); ?>"><?php echo esc_html( number_format_i18n( $without ) ); ?></strong>
						<span class="sim-met-sub sim-warn"><?php echo esc_html( (string) max( 0, 100 - $pct ) ); ?>%</span>
					</div>
				</div>
				<div class="sim-cov-block">
					<div class="sim-progress-bar" aria-label="<?php esc_attr_e( 'Featured image coverage', 'smart-image-matcher' ); ?>">
						<div class="sim-progress-fill" style="width:<?php echo esc_attr( (string) $pct ); ?>%"></div>
					</div>
					<p class="description">
						<?php
						printf(
							/* translators: 1: coverage percentage */
							esc_html__( 'Current coverage: %1$d%% of published and draft posts have a featured image.', 'smart-image-matcher' ),
							absint( $pct )
						);
						?>
					</p>
				</div>
			</div>

			<div class="sim-card">
				<div class="sim-card-head">
					<div>
						<h2><?php esc_html_e( 'Recent Activity', 'smart-image-matcher' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Counts from the last recorded scheduled run.', 'smart-image-matcher' ); ?></p>
					</div>
				</div>
				<div class="sim-card-body sim-card-body-tight">
					<table class="sim-act-table">
						<tbody>
							<tr>
								<td><?php esc_html_e( 'Matched', 'smart-image-matcher' ); ?></td>
								<td><span class="sim-status sim-status-good"><?php echo esc_html( (string) (int) ( is_array( $last_summary ) ? ( $last_summary['matched'] ?? 0 ) : 0 ) ); ?></span></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Skipped', 'smart-image-matcher' ); ?></td>
								<td><span class="sim-status sim-status-info"><?php echo esc_html( (string) (int) ( is_array( $last_summary ) ? ( $last_summary['skipped'] ?? 0 ) : 0 ) ); ?></span></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Unmatched', 'smart-image-matcher' ); ?></td>
								<td><span class="sim-status sim-status-warn"><?php echo esc_html( (string) (int) ( is_array( $last_summary ) ? ( $last_summary['unmatched'] ?? 0 ) : 0 ) ); ?></span></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<div class="sim-card">
				<div class="sim-card-head">
					<div>
						<h2><?php esc_html_e( 'Held For Review', 'smart-image-matcher' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Posts left alone when the match is too broad or there are competing candidates.', 'smart-image-matcher' ); ?></p>
					</div>
				</div>
				<div class="sim-card-body">
					<div class="sim-held-rules">
						<div class="sim-held-rule">
							<span><?php esc_html_e( 'Similar filenames with different key terms', 'smart-image-matcher' ); ?></span>
							<span class="sim-held-badge sim-held-badge-warn"><?php esc_html_e( 'Hold', 'smart-image-matcher' ); ?></span>
						</div>
						<div class="sim-held-rule">
							<span><?php esc_html_e( 'Token overlap only (not exact/prefix)', 'smart-image-matcher' ); ?></span>
							<span class="sim-held-badge sim-held-badge-warn"><?php esc_html_e( 'Hold', 'smart-image-matcher' ); ?></span>
						</div>
						<div class="sim-held-rule">
							<span><?php esc_html_e( 'Exact, prefix, and deduped slug matches', 'smart-image-matcher' ); ?></span>
							<span class="sim-held-badge sim-held-badge-good"><?php esc_html_e( 'Auto', 'smart-image-matcher' ); ?></span>
						</div>
					</div>
				</div>
			</div>
		</aside>
	</div>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
