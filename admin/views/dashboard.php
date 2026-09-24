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

use SmartImageMatcher\REST\BulkController;
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
$bulk_run_url     = admin_url( 'admin.php?page=smart-image-matcher-bulk&sim_tab=run' );
$bulk_review_url  = admin_url( 'admin.php?page=smart-image-matcher-bulk&sim_tab=review' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pending_articles = (int) $wpdb->get_var(
	"SELECT COUNT(DISTINCT post_id) FROM {$matches_table} WHERE status = 'pending'"
);

$recent_jobs = $wpdb->get_results(
	"SELECT job_id, status, totals, created_at, finished_at
	 FROM {$queue_table}
	 ORDER BY created_at DESC
	 LIMIT 5",
	ARRAY_A
);
// phpcs:enable

$last_job   = is_array( $recent_jobs ) && ! empty( $recent_jobs[0] ) ? $recent_jobs[0] : null;
$last_label = '';
if ( $last_job ) {
	$last_job   = ( new BulkController() )->hydrateJobRow( $last_job );
	$last_label = (string) ( $last_job['label'] ?? '' );
}

$auto  = (int) Settings::get( 'auto_insert_threshold' );
$floor = (int) Settings::get( 'confidence_threshold' );

$health       = \SmartImageMatcher\Queue\QueueWatchdog::health();
$health_queue = $health['queue'];
$health_class = array(
	'good' => 'sim-status-good',
	'warn' => 'sim-status-warn',
	'bad'  => 'sim-status-bad',
)[ $health['state'] ] ?? 'sim-status-info';
$tick_labels  = array(
	'queued'  => __( 'Ran', 'smart-image-matcher' ),
	'skipped' => __( 'Skipped', 'smart-image-matcher' ),
	'nothing' => __( 'Nothing to do', 'smart-image-matcher' ),
);
$fmt_time     = static function ( $ts ): string {
	return $ts ? wp_date( 'j M H:i', (int) $ts ) : '—';
};
$watchdog     = $health['watchdog'];
$wd_checked   = isset( $_GET['sim_wd_checked'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after redirect.
?>
<div class="wrap sim-admin-page sim-dashboard-page">
	<div class="sim-page-head">
		<div>
			<h1><?php esc_html_e( 'Smart Image Matcher', 'smart-image-matcher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Coverage, pending review, and the last article run.', 'smart-image-matcher' ); ?>
			</p>
		</div>
		<div class="sim-page-actions">
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=smart-image-matcher-settings' ) ); ?>">
				<?php esc_html_e( 'Settings', 'smart-image-matcher' ); ?>
			</a>
			<a class="button button-primary" href="<?php echo esc_url( $bulk_run_url ); ?>">
				<?php esc_html_e( 'Process articles', 'smart-image-matcher' ); ?>
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
			<small><?php esc_html_e( 'Process articles or generate featured on Bulk Processor', 'smart-image-matcher' ); ?></small>
		</div>
		<a class="sim-card sim-metric sim-metric-link" href="<?php echo esc_url( $bulk_review_url ); ?>">
			<span><?php esc_html_e( 'Pending review', 'smart-image-matcher' ); ?></span>
			<strong><?php echo esc_html( (string) $pending_articles ); ?></strong>
			<small><?php esc_html_e( 'Articles in the middle band', 'smart-image-matcher' ); ?></small>
		</a>
		<?php if ( $last_label ) : ?>
			<a class="sim-card sim-metric sim-metric-link" href="<?php echo esc_url( $bulk_run_url ); ?>">
				<span><?php esc_html_e( 'Last run', 'smart-image-matcher' ); ?></span>
				<strong class="sim-metric-text"><?php echo esc_html( $last_label ); ?></strong>
				<small><?php esc_html_e( 'Open Bulk Processor Run', 'smart-image-matcher' ); ?></small>
			</a>
		<?php else : ?>
			<div class="sim-card sim-metric">
				<span><?php esc_html_e( 'Last run', 'smart-image-matcher' ); ?></span>
				<strong class="sim-metric-text"><?php esc_html_e( 'No article runs yet.', 'smart-image-matcher' ); ?></strong>
				<small>
					<a href="<?php echo esc_url( $bulk_run_url ); ?>">
						<?php esc_html_e( 'Process a selection or wait for the scheduled run.', 'smart-image-matcher' ); ?>
					</a>
				</small>
			</div>
		<?php endif; ?>
	</div>

	<div class="sim-dashboard-grid">
		<section class="sim-card">
			<div class="sim-card-head">
				<div>
					<h2><?php esc_html_e( 'Queue Health', 'smart-image-matcher' ); ?></h2>
					<p class="description"><?php echo esc_html( $health['title'] ); ?></p>
				</div>
				<span class="sim-status <?php echo esc_attr( $health_class ); ?>">
					<?php echo esc_html( array( 'good' => __( 'Healthy', 'smart-image-matcher' ), 'warn' => __( 'Check', 'smart-image-matcher' ), 'bad' => __( 'Blocked', 'smart-image-matcher' ) )[ $health['state'] ] ?? '' ); ?>
				</span>
			</div>

			<?php if ( $wd_checked ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: stuck actions freed, 2: stalled jobs closed */
							__( 'Checked just now. Freed %1$d stuck queue slot(s), closed %2$d stalled run(s).', 'smart-image-matcher' ),
							isset( $_GET['sim_wd_freed'] ) ? absint( $_GET['sim_wd_freed'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							isset( $_GET['sim_wd_closed'] ) ? absint( $_GET['sim_wd_closed'] ) : 0 // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						)
					);
					?>
				</p></div>
			<?php endif; ?>

			<table class="widefat sim-health-table">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Hourly run', 'smart-image-matcher' ); ?></th>
						<td>
							<?php if ( ! empty( $health['ticks'][0] ) ) : ?>
								<?php
								$last_tick = $health['ticks'][0];
								echo esc_html(
									sprintf(
										/* translators: 1: time, 2: outcome, 3: detail */
										__( 'Last %1$s — %2$s: %3$s', 'smart-image-matcher' ),
										$fmt_time( $last_tick['at'] ?? 0 ),
										$tick_labels[ $last_tick['outcome'] ?? '' ] ?? (string) ( $last_tick['outcome'] ?? '' ),
										(string) ( $last_tick['detail'] ?? '' )
									)
								);
								?>
							<?php else : ?>
								<?php esc_html_e( 'No run recorded since this version was installed.', 'smart-image-matcher' ); ?>
							<?php endif; ?>
							<br /><span class="description">
								<?php
								echo esc_html(
									$health['next_tick']
										/* translators: %s: time */
										? sprintf( __( 'Next run %s', 'smart-image-matcher' ), $fmt_time( $health['next_tick'] ) )
										: __( 'Scheduled run is off (Settings → Featured Images).', 'smart-image-matcher' )
								);
								?>
							</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Article queue', 'smart-image-matcher' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: pending, 2: overdue, 3: running */
									__( '%1$d waiting · %2$d overdue · %3$d running', 'smart-image-matcher' ),
									$health_queue['pending'],
									$health_queue['past_due'],
									$health_queue['in_progress']
								)
							);
							if ( $health_queue['past_due'] > 0 ) {
								echo ' ';
								/* translators: %d: minutes */
								echo esc_html( sprintf( __( '(oldest overdue by %d min)', 'smart-image-matcher' ), $health_queue['oldest_past_due_minutes'] ) );
							}
							?>
						</td>
					</tr>
					<?php if ( $health_queue['stuck'] > 0 || $health_queue['stale_claims'] > 0 ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Blocked by', 'smart-image-matcher' ); ?></th>
							<td class="sim-bad">
								<?php
								echo esc_html(
									$health_queue['stuck'] > 0
										/* translators: 1: hook, 2: minutes */
										? sprintf( __( '%1$s, running for %2$d min. Action Scheduler runs one batch at a time, so everything waits behind it.', 'smart-image-matcher' ), $health_queue['stuck_hook'], $health_queue['stuck_minutes'] )
										/* translators: %d: count */
										: sprintf( __( '%d stale claim(s) holding queued actions.', 'smart-image-matcher' ), $health_queue['stale_claims'] )
								);
								?>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Watchdog', 'smart-image-matcher' ); ?></th>
						<td>
							<?php
							if ( ! empty( $watchdog['at'] ) ) {
								echo esc_html(
									sprintf(
										/* translators: 1: time, 2: freed, 3: closed */
										__( 'Checked %1$s · freed %2$d stuck slot(s) · closed %3$d stalled run(s). Runs every 15 minutes.', 'smart-image-matcher' ),
										$fmt_time( $watchdog['at'] ),
										(int) ( $watchdog['freed'] ?? 0 ),
										(int) ( $watchdog['closed'] ?? 0 )
									)
								);
							} else {
								esc_html_e( 'Not run yet. Runs every 15 minutes on WP-Cron.', 'smart-image-matcher' );
							}
							?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:8px">
								<input type="hidden" name="action" value="<?php echo esc_attr( \SmartImageMatcher\Queue\QueueWatchdog::CHECK_NOW_ACTION ); ?>" />
								<?php wp_nonce_field( \SmartImageMatcher\Queue\QueueWatchdog::CHECK_NOW_ACTION ); ?>
								<button type="submit" class="button"><?php esc_html_e( 'Check now', 'smart-image-matcher' ); ?></button>
							</form>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( count( $health['ticks'] ) > 1 ) : ?>
				<details class="sim-health-ticks">
					<summary><?php esc_html_e( 'Last 12 hourly runs', 'smart-image-matcher' ); ?></summary>
					<table class="widefat striped">
						<tbody>
						<?php foreach ( $health['ticks'] as $smart_image_matcher_tick ) : ?>
							<tr>
								<td><?php echo esc_html( $fmt_time( $smart_image_matcher_tick['at'] ?? 0 ) ); ?></td>
								<td><?php echo esc_html( $tick_labels[ $smart_image_matcher_tick['outcome'] ?? '' ] ?? '' ); ?></td>
								<td><?php echo esc_html( (string) ( $smart_image_matcher_tick['detail'] ?? '' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endif; ?>

			<h3 class="sim-health-subhead"><?php esc_html_e( 'Recent article runs', 'smart-image-matcher' ); ?></h3>

			<?php if ( ! empty( $recent_jobs ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'smart-image-matcher' ); ?></th>
							<th><?php esc_html_e( 'What', 'smart-image-matcher' ); ?></th>
							<th><?php esc_html_e( 'Status', 'smart-image-matcher' ); ?></th>
							<th><?php esc_html_e( 'Outcome', 'smart-image-matcher' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $recent_jobs as $smart_image_matcher_job ) : ?>
						<?php
						$smart_image_matcher_job    = ( new BulkController() )->hydrateJobRow( $smart_image_matcher_job );
						$smart_image_matcher_status = (string) ( $smart_image_matcher_job['status'] ?? '' );
						$smart_image_matcher_class  = in_array( $smart_image_matcher_status, array( 'completed' ), true ) ? 'sim-status-good' : 'sim-status-info';
						$smart_image_matcher_class  = in_array( $smart_image_matcher_status, array( 'failed', 'cancelled' ), true ) ? 'sim-status-warn' : $smart_image_matcher_class;
						?>
						<tr>
							<td><?php echo esc_html( (string) ( $smart_image_matcher_job['when_label'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $smart_image_matcher_job['what_label'] ?? '' ) ); ?></td>
							<td><span class="sim-status <?php echo esc_attr( $smart_image_matcher_class ); ?>"><?php echo esc_html( ucfirst( $smart_image_matcher_status ) ); ?></span></td>
							<td><?php echo esc_html( (string) ( $smart_image_matcher_job['outcome'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'No article runs yet. Process a selection or wait for the scheduled run.', 'smart-image-matcher' ); ?>
					<a href="<?php echo esc_url( $bulk_run_url ); ?>"><?php esc_html_e( 'Bulk Processor', 'smart-image-matcher' ); ?></a>
				</p>
			<?php endif; ?>
		</section>

		<section class="sim-card">
			<div class="sim-card-head">
				<div>
					<h2><?php esc_html_e( 'How articles are decided', 'smart-image-matcher' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Library first. Generation only when the library would skip.', 'smart-image-matcher' ); ?></p>
				</div>
			</div>
			<div class="sim-rule-list">
				<div>
					<span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: auto-insert threshold */
								__( 'At or above %d%% → insert now', 'smart-image-matcher' ),
								$auto
							)
						);
						?>
					</span>
					<span class="sim-status sim-status-good"><?php esc_html_e( 'Insert', 'smart-image-matcher' ); ?></span>
				</div>
				<div>
					<span>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: review floor */
								__( 'At or above %d%% → Review tab', 'smart-image-matcher' ),
								$floor
							)
						);
						?>
					</span>
					<span class="sim-status sim-status-info"><?php esc_html_e( 'Review', 'smart-image-matcher' ); ?></span>
				</div>
				<div>
					<span><?php esc_html_e( 'Below review, generation on → generate (skip-band only)', 'smart-image-matcher' ); ?></span>
					<span class="sim-status sim-status-warn"><?php esc_html_e( 'Generate', 'smart-image-matcher' ); ?></span>
				</div>
				<div>
					<span><?php esc_html_e( 'Else → skip', 'smart-image-matcher' ); ?></span>
					<span class="sim-status"><?php esc_html_e( 'Skip', 'smart-image-matcher' ); ?></span>
				</div>
			</div>
		</section>
	</div>

	<?php
	$recent_errors = \SmartImageMatcher\Logging\Logger::getRecentErrors();
	if ( ! empty( $recent_errors ) ) :
		?>
	<section class="sim-card">
		<div class="sim-card-head">
			<div>
				<h2><?php esc_html_e( 'Recent errors', 'smart-image-matcher' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Last 20 plugin errors. Stored on this site; debug.log is not required.', 'smart-image-matcher' ); ?></p>
			</div>
		</div>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'smart-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'What', 'smart-image-matcher' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'smart-image-matcher' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $recent_errors as $smart_image_matcher_error ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( $smart_image_matcher_error['at'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $smart_image_matcher_error['message'] ?? '' ) ); ?></td>
					<td>
						<?php
						$smart_image_matcher_bits = array();
						if ( ! empty( $smart_image_matcher_error['context'] ) && is_array( $smart_image_matcher_error['context'] ) ) {
							foreach ( $smart_image_matcher_error['context'] as $smart_image_matcher_ck => $smart_image_matcher_cv ) {
								$smart_image_matcher_bits[] = $smart_image_matcher_ck . '=' . $smart_image_matcher_cv;
							}
						}
						echo esc_html( implode( ' ', $smart_image_matcher_bits ) );
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php endif; ?>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
