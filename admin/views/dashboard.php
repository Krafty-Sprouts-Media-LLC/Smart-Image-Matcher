<?php
/**
 * Smart Image Matcher dashboard page.
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

use SmartImageMatcher\Settings\Settings;

global $wpdb;

$total_posts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT COUNT(*) FROM {$wpdb->posts}
	 WHERE post_type = 'post' AND post_status IN ('publish','draft')"
);

$with_thumbnail = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT COUNT(*) FROM {$wpdb->posts} p
	 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_thumbnail_id'
	 WHERE p.post_type = 'post' AND p.post_status IN ('publish','draft')"
);

$missing_featured = max( 0, $total_posts - $with_thumbnail );
$coverage         = $total_posts > 0 ? (int) round( ( $with_thumbnail / $total_posts ) * 100 ) : 0;
$matches_table    = esc_sql( $wpdb->prefix . 'smart_image_matcher_matches' );
$queue_table      = esc_sql( $wpdb->prefix . 'smart_image_matcher_queue' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pending_matches = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$matches_table} WHERE status = 'pending'"
);

$held_matches = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$matches_table} WHERE status IN ('unmatched','rejected')"
);

$recent_jobs = $wpdb->get_results(
	"SELECT job_id, status, totals, created_at, finished_at
	 FROM {$queue_table}
	 ORDER BY created_at DESC
	 LIMIT 5",
	ARRAY_A
);
// phpcs:enable
?>
<div class="wrap sim-admin-page sim-dashboard-page">
	<div class="sim-page-head">
		<div>
			<h1><?php esc_html_e( 'Smart Image Matcher', 'smart-image-matcher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Coverage, queue health, and matching safety at a glance.', 'smart-image-matcher' ); ?>
			</p>
		</div>
		<div class="sim-page-actions">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=smart-image-matcher-settings' ) ); ?>">
				<?php esc_html_e( 'Settings', 'smart-image-matcher' ); ?>
			</a>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=smart-image-matcher-featured-images' ) ); ?>">
				<?php esc_html_e( 'Match Runner', 'smart-image-matcher' ); ?>
			</a>
		</div>
	</div>

	<div class="sim-metric-grid sim-metric-grid-four">
		<div class="sim-card sim-metric">
			<span><?php esc_html_e( 'Featured coverage', 'smart-image-matcher' ); ?></span>
			<strong><?php echo esc_html( (string) $coverage ); ?>%</strong>
			<small><?php echo esc_html( (string) $with_thumbnail ); ?> / <?php echo esc_html( (string) $total_posts ); ?> <?php esc_html_e( 'posts', 'smart-image-matcher' ); ?></small>
		</div>
		<div class="sim-card sim-metric">
			<span><?php esc_html_e( 'Missing featured images', 'smart-image-matcher' ); ?></span>
			<strong class="<?php echo esc_attr( $missing_featured > 0 ? 'sim-bad' : 'sim-good' ); ?>"><?php echo esc_html( (string) $missing_featured ); ?></strong>
			<small><?php esc_html_e( 'Match Runner can process these', 'smart-image-matcher' ); ?></small>
		</div>
		<div class="sim-card sim-metric">
			<span><?php esc_html_e( 'Pending content matches', 'smart-image-matcher' ); ?></span>
			<strong><?php echo esc_html( (string) $pending_matches ); ?></strong>
			<small><?php esc_html_e( 'Waiting for review or insertion', 'smart-image-matcher' ); ?></small>
		</div>
		<div class="sim-card sim-metric">
			<span><?php esc_html_e( 'Safety holds', 'smart-image-matcher' ); ?></span>
			<strong><?php echo esc_html( (string) $held_matches ); ?></strong>
			<small><?php esc_html_e( 'Rejected, unmatched, or ambiguous results', 'smart-image-matcher' ); ?></small>
		</div>
	</div>

	<div class="sim-dashboard-grid">
		<section class="sim-card">
			<div class="sim-card-head">
				<div>
					<h2><?php esc_html_e( 'Queue Health', 'smart-image-matcher' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Recent background jobs created by SIM.', 'smart-image-matcher' ); ?></p>
				</div>
				<span class="sim-status sim-status-good"><?php esc_html_e( 'Action Scheduler', 'smart-image-matcher' ); ?></span>
			</div>

			<?php if ( ! empty( $recent_jobs ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Job', 'smart-image-matcher' ); ?></th>
							<th><?php esc_html_e( 'Status', 'smart-image-matcher' ); ?></th>
							<th><?php esc_html_e( 'Progress', 'smart-image-matcher' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $recent_jobs as $smart_image_matcher_job ) : ?>
						<?php
						$smart_image_matcher_totals = json_decode( (string) ( $smart_image_matcher_job['totals'] ?? '' ), true );
						$smart_image_matcher_totals = is_array( $smart_image_matcher_totals ) ? $smart_image_matcher_totals : array();
						$smart_image_matcher_total  = isset( $smart_image_matcher_totals['total'] ) ? (int) $smart_image_matcher_totals['total'] : 0;
						$smart_image_matcher_done   = isset( $smart_image_matcher_totals['done'] ) ? (int) $smart_image_matcher_totals['done'] : 0;
						$smart_image_matcher_status = (string) ( $smart_image_matcher_job['status'] ?? '' );
						$smart_image_matcher_class  = in_array( $smart_image_matcher_status, array( 'completed' ), true ) ? 'sim-status-good' : 'sim-status-info';
						$smart_image_matcher_class  = in_array( $smart_image_matcher_status, array( 'failed', 'cancelled' ), true ) ? 'sim-status-warn' : $smart_image_matcher_class;
						?>
						<tr>
							<td><code><?php echo esc_html( (string) $smart_image_matcher_job['job_id'] ); ?></code></td>
							<td><span class="sim-status <?php echo esc_attr( $smart_image_matcher_class ); ?>"><?php echo esc_html( ucfirst( $smart_image_matcher_status ) ); ?></span></td>
							<td><?php echo esc_html( (string) $smart_image_matcher_done ); ?> / <?php echo esc_html( (string) $smart_image_matcher_total ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No SIM queue jobs recorded yet.', 'smart-image-matcher' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="sim-card">
			<div class="sim-card-head">
				<div>
					<h2><?php esc_html_e( 'Safety Rules', 'smart-image-matcher' ); ?></h2>
					<p class="description"><?php esc_html_e( 'How automatic assignment is constrained.', 'smart-image-matcher' ); ?></p>
				</div>
			</div>
			<div class="sim-rule-list">
				<div><span><?php esc_html_e( 'Exact and prefix slug matches', 'smart-image-matcher' ); ?></span><span class="sim-status sim-status-good"><?php esc_html_e( 'Auto', 'smart-image-matcher' ); ?></span></div>
				<div><span><?php esc_html_e( 'Generic one-word filenames', 'smart-image-matcher' ); ?></span><span class="sim-status sim-status-warn"><?php esc_html_e( 'Blocked', 'smart-image-matcher' ); ?></span></div>
				<div><span><?php esc_html_e( 'Close competing candidates', 'smart-image-matcher' ); ?></span><span class="sim-status sim-status-info"><?php esc_html_e( 'Review', 'smart-image-matcher' ); ?></span></div>
				<div><span><?php esc_html_e( 'Scheduled automation', 'smart-image-matcher' ); ?></span><span class="sim-status sim-status-good"><?php esc_html_e( 'Active', 'smart-image-matcher' ); ?></span></div>
			</div>
		</section>
	</div>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
