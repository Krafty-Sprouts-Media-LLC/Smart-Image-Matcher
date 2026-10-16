<?php
/**
 * Smart Image Matcher dashboard: what ran, what's running, what needs you.
 *
 * Server-rendered; dashboard.js draws the hourly chart and handles dismiss buttons.
 *
 * @package SmartImageMatcher
 * @since   3.0.0 (redesigned 3.7.0)
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local variables are scoped to this admin view include.

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-image-matcher' ) );
}

use SmartImageMatcher\Admin\DashboardData;
use SmartImageMatcher\Premium;
use SmartImageMatcher\Queue\QueueWatchdog;
use SmartImageMatcher\Settings\Settings;

$data      = new DashboardData();
$dash      = $data->collect();
$health    = $dash['health'];
$kpis      = $dash['kpis'];
$queue     = $dash['queue'];
$has_bulk  = Premium::has( 'rest_premium_endpoints' );
$auto      = (int) Settings::get( 'auto_insert_threshold' );
$floor     = (int) Settings::get( 'confidence_threshold' );
$next_tick = $data->toLocal( (int) $health['next_tick'] );
$cron_on   = (bool) Settings::get( 'fiaa_cron_enabled' );

$url_run      = admin_url( 'admin.php?page=smart-image-matcher-bulk&sim_tab=run' );
$url_review   = admin_url( 'admin.php?page=smart-image-matcher-bulk&sim_tab=review' );
$url_schedule = admin_url( 'admin.php?page=smart-image-matcher-bulk&sim_tab=schedule' );
$url_settings = admin_url( 'admin.php?page=smart-image-matcher-settings' );
$url_missing  = admin_url( 'admin.php?page=' . \SmartImageMatcher\Admin\MissingImagesPage::SLUG );

$minutes = static function ( int $mins ): string {
	if ( $mins < 60 ) {
		/* translators: %d: minutes */
		return sprintf( _n( '%d min', '%d min', $mins, 'smart-image-matcher' ), $mins );
	}
	/* translators: 1: hours, 2: minutes */
	return sprintf( __( '%1$d h %2$d min', 'smart-image-matcher' ), intdiv( $mins, 60 ), $mins % 60 );
};

// Health strip meta line.
$health_bits = array();
if ( $queue['pending'] > 0 ) {
	/* translators: %s: count */
	$health_bits[] = sprintf( _n( '%s action waiting', '%s actions waiting', $queue['pending'], 'smart-image-matcher' ), number_format_i18n( $queue['pending'] ) );
}
if ( $kpis['run'] && null !== $kpis['run']['eta_min'] ) {
	/* translators: %s: duration */
	$health_bits[] = sprintf( __( 'current run finishes in about %s', 'smart-image-matcher' ), $minutes( (int) $kpis['run']['eta_min'] ) );
}
if ( $cron_on && $next_tick ) {
	/* translators: %s: time */
	$health_bits[] = sprintf( __( 'next automatic run %s', 'smart-image-matcher' ), $data->when( $next_tick ) );
} elseif ( ! $cron_on ) {
	$health_bits[] = __( 'automatic runs are off', 'smart-image-matcher' );
}

$intervals = array(
	'hourly'        => __( 'Every hour', 'smart-image-matcher' ),
	'every_4_hours' => __( 'Every 4 hours', 'smart-image-matcher' ),
	'every_6_hours' => __( 'Every 6 hours', 'smart-image-matcher' ),
	'every_8_hours' => __( 'Every 8 hours', 'smart-image-matcher' ),
	'twicedaily'    => __( 'Twice daily', 'smart-image-matcher' ),
	'daily'         => __( 'Daily', 'smart-image-matcher' ),
);
$featured_filters = array(
	'missing' => __( 'missing a featured image', 'smart-image-matcher' ),
	'has'     => __( 'with a featured image', 'smart-image-matcher' ),
	'any'     => __( 'any featured image state', 'smart-image-matcher' ),
);
$fills = array(
	'both'     => __( 'Featured image and headings', 'smart-image-matcher' ),
	'featured' => __( 'Featured image only', 'smart-image-matcher' ),
	'headings' => __( 'Headings only', 'smart-image-matcher' ),
);
$statuses = array();
foreach ( \SmartImageMatcher\Domain\PostStatuses::sanitizeList( (string) Settings::get( 'fiaa_cron_post_statuses' ) ) as $status_key ) {
	$status_obj = get_post_status_object( $status_key );
	$statuses[] = $status_obj ? (string) $status_obj->label : $status_key;
}
$match_mode = 'ai' === (string) Settings::get( 'match_mode' )
	/* translators: %s: model id */
	? sprintf( __( 'AI ranking · %s', 'smart-image-matcher' ), (string) Settings::get( 'ai_text_model' ) )
	: __( 'Keyword matching', 'smart-image-matcher' );

$status_pills = array(
	'completed'  => array( 'is-ok', __( 'Completed', 'smart-image-matcher' ) ),
	'processing' => array( 'is-run', __( 'Running', 'smart-image-matcher' ) ),
	'queued'     => array( 'is-run', __( 'Queued', 'smart-image-matcher' ) ),
	'failed'     => array( 'is-bad', __( 'Stopped', 'smart-image-matcher' ) ),
	'cancelled'  => array( 'is-mute', __( 'Cancelled', 'smart-image-matcher' ) ),
);

$wd_checked = isset( $_GET['sim_wd_checked'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag after redirect.

wp_localize_script(
	'smart-image-matcher-dashboard',
	'smartImageMatcherDashboard',
	array(
		'nonce' => wp_create_nonce( 'wp_rest' ),
		'slots' => $dash['slots'],
	)
);
?>
<div class="wrap sim-admin-page sim-dash" id="sim-dash">
	<div class="sim-dash-head">
		<div>
			<h1><?php esc_html_e( 'Smart Image Matcher', 'smart-image-matcher' ); ?></h1>
			<p class="description"><?php esc_html_e( 'What ran, what’s running, and what needs you.', 'smart-image-matcher' ); ?></p>
		</div>
		<div class="sim-dash-actions">
			<a class="button" href="<?php echo esc_url( $url_settings ); ?>"><?php esc_html_e( 'Settings', 'smart-image-matcher' ); ?></a>
			<?php if ( $has_bulk ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $url_run ); ?>"><?php esc_html_e( 'Process articles', 'smart-image-matcher' ); ?></a>
			<?php endif; ?>
		</div>
	</div>
	<?php // Admin notices are moved here by WordPress instead of inside the header row. ?>
	<hr class="wp-header-end" />

	<div class="sim-dash-health is-<?php echo esc_attr( (string) $health['state'] ); ?>" role="status">
		<span class="sim-dash-dot" aria-hidden="true"></span>
		<strong><?php echo esc_html( (string) $health['title'] ); ?></strong>
		<span class="sim-dash-muted"><?php echo esc_html( implode( ' · ', $health_bits ) ); ?></span>
		<span class="sim-dash-grow"></span>
		<a class="button button-small" href="#sim-dash-queue"><?php esc_html_e( 'View queue', 'smart-image-matcher' ); ?></a>
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

	<section class="sim-dash-kpis" aria-label="<?php esc_attr_e( 'Summary', 'smart-image-matcher' ); ?>">
		<a class="sim-dash-kpi" href="#sim-dash-runs">
			<span class="sim-dash-lbl"><?php esc_html_e( 'Images inserted today', 'smart-image-matcher' ); ?> <span class="sim-dash-go"><?php esc_html_e( 'Runs →', 'smart-image-matcher' ); ?></span></span>
			<span class="sim-dash-num"><?php echo esc_html( number_format_i18n( $kpis['today']['inserted'] ) ); ?></span>
			<span class="sim-dash-sub">
				<?php
				$delta = $kpis['today']['inserted'] - $kpis['yesterday']['inserted'];
				echo esc_html(
					sprintf(
						/* translators: 1: signed difference, 2: yesterday count, 3: review count */
						__( '%1$s vs yesterday (%2$s) · %3$s sent to Review', 'smart-image-matcher' ),
						( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta ),
						number_format_i18n( $kpis['yesterday']['inserted'] ),
						number_format_i18n( $kpis['today']['review'] )
					)
				);
				?>
			</span>
		</a>

		<a class="sim-dash-kpi" href="<?php echo esc_url( $has_bulk ? $url_review : '#sim-dash-attn' ); ?>">
			<span class="sim-dash-lbl"><?php esc_html_e( 'Waiting for your review', 'smart-image-matcher' ); ?> <span class="sim-dash-go"><?php esc_html_e( 'Review →', 'smart-image-matcher' ); ?></span></span>
			<span class="sim-dash-num"><?php echo esc_html( number_format_i18n( $kpis['review']['articles'] ) ); ?> <small><?php esc_html_e( 'articles', 'smart-image-matcher' ); ?></small></span>
			<span class="sim-dash-sub">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: suggestions, 2: one-word matches */
						__( '%1$s suggestions · %2$s matched on one word', 'smart-image-matcher' ),
						number_format_i18n( $kpis['review']['suggestions'] ),
						number_format_i18n( $kpis['review']['thin'] )
					)
				);
				?>
			</span>
		</a>

		<a class="sim-dash-kpi" href="#sim-dash-queue">
			<span class="sim-dash-lbl"><?php esc_html_e( 'Current run', 'smart-image-matcher' ); ?> <span class="sim-dash-go"><?php esc_html_e( 'Queue →', 'smart-image-matcher' ); ?></span></span>
			<?php if ( $kpis['run'] ) : ?>
				<?php $run_pct = $kpis['run']['total'] > 0 ? min( 100, ( $kpis['run']['done'] / $kpis['run']['total'] ) * 100 ) : 0; ?>
				<span class="sim-dash-num"><?php echo esc_html( number_format_i18n( $kpis['run']['done'] ) ); ?> <small>/ <?php echo esc_html( number_format_i18n( $kpis['run']['total'] ) ); ?> <?php esc_html_e( 'articles', 'smart-image-matcher' ); ?></small></span>
				<span class="sim-dash-bar" aria-hidden="true"><i style="width:<?php echo esc_attr( number_format( $run_pct, 1, '.', '' ) ); ?>%"></i></span>
				<span class="sim-dash-sub">
					<?php
					$run_sub = sprintf(
						/* translators: 1: trigger, 2: start time */
						__( '%1$s · started %2$s', 'smart-image-matcher' ),
						$kpis['run']['trigger'],
						$kpis['run']['started']
					);
					if ( null !== $kpis['run']['eta_min'] ) {
						/* translators: %s: duration */
						$run_sub .= ' · ' . sprintf( __( 'about %s left', 'smart-image-matcher' ), $minutes( (int) $kpis['run']['eta_min'] ) );
					}
					echo esc_html( $run_sub );
					?>
				</span>
			<?php else : ?>
				<span class="sim-dash-num sim-dash-num-text"><?php esc_html_e( 'Idle', 'smart-image-matcher' ); ?></span>
				<span class="sim-dash-sub"><?php echo esc_html( $cron_on && $next_tick ? sprintf( /* translators: %s: time */ __( 'Next automatic run %s', 'smart-image-matcher' ), $data->when( $next_tick ) ) : __( 'No run is open.', 'smart-image-matcher' ) ); ?></span>
			<?php endif; ?>
		</a>

		<a class="sim-dash-kpi" href="<?php echo esc_url( $url_missing ); ?>">
			<span class="sim-dash-lbl"><?php esc_html_e( 'Featured images', 'smart-image-matcher' ); ?> <span class="sim-dash-go"><?php esc_html_e( 'Missing →', 'smart-image-matcher' ); ?></span></span>
			<span class="sim-dash-num"><?php echo esc_html( (string) $kpis['coverage']['pct'] ); ?>%</span>
			<span class="sim-dash-sub">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: with, 2: total, 3: missing */
						__( '%1$s of %2$s posts · %3$s missing', 'smart-image-matcher' ),
						number_format_i18n( $kpis['coverage']['with'] ),
						number_format_i18n( $kpis['coverage']['total'] ),
						number_format_i18n( $kpis['coverage']['missing'] )
					)
				);
				?>
			</span>
		</a>
	</section>

	<div class="sim-dash-grid">
		<div class="sim-dash-stack">
			<section class="sim-dash-card" id="sim-dash-runs" aria-labelledby="sim-dash-chart-h">
				<div class="sim-dash-card-head">
					<div>
						<h2 id="sim-dash-chart-h"><?php esc_html_e( 'Runs by hour', 'smart-image-matcher' ); ?></h2>
						<span class="sim-dash-muted"><?php esc_html_e( 'Headings and featured images handled per hour. Grey stubs are automatic runs that were skipped.', 'smart-image-matcher' ); ?></span>
					</div>
					<div class="sim-dash-seg" role="group" aria-label="<?php esc_attr_e( 'Time range', 'smart-image-matcher' ); ?>">
						<button type="button" data-range="24" aria-pressed="false">24 h</button>
						<button type="button" data-range="48" aria-pressed="true">48 h</button>
					</div>
				</div>
				<div class="sim-dash-legend" aria-hidden="true">
					<span><i class="is-ins"></i><?php esc_html_e( 'Inserted', 'smart-image-matcher' ); ?></span>
					<span><i class="is-rev"></i><?php esc_html_e( 'Sent to Review', 'smart-image-matcher' ); ?></span>
					<span><i class="is-skip"></i><?php esc_html_e( 'Skipped', 'smart-image-matcher' ); ?></span>
				</div>
				<div class="sim-dash-chart" id="sim-dash-chart-wrap">
					<svg id="sim-dash-chart" role="img" aria-labelledby="sim-dash-chart-desc" preserveAspectRatio="none"></svg>
					<p class="screen-reader-text" id="sim-dash-chart-desc"></p>
					<div class="sim-dash-tip" id="sim-dash-tip" hidden></div>
				</div>
				<div class="sim-dash-chart-foot">
					<span id="sim-dash-chart-sum"></span>
					<button type="button" class="button-link" id="sim-dash-toggle-table" aria-expanded="false"><?php esc_html_e( 'View as table', 'smart-image-matcher' ); ?></button>
				</div>
				<div class="sim-dash-table-wrap" id="sim-dash-chart-table" hidden></div>
			</section>

			<section class="sim-dash-card" aria-labelledby="sim-dash-list-h">
				<div class="sim-dash-card-head sim-dash-pad-b"><h2 id="sim-dash-list-h"><?php esc_html_e( 'Recent runs', 'smart-image-matcher' ); ?></h2><span class="sim-dash-muted"><?php esc_html_e( 'Counts are per slot: the featured image and each heading', 'smart-image-matcher' ); ?></span></div>
				<?php if ( empty( $dash['runs'] ) ) : ?>
					<p class="sim-dash-empty"><?php esc_html_e( 'No runs yet. Process a selection or turn on automatic runs in Settings.', 'smart-image-matcher' ); ?></p>
				<?php else : ?>
					<div class="sim-dash-table-wrap">
						<table class="sim-dash-t">
							<thead><tr>
								<th><?php esc_html_e( 'Started', 'smart-image-matcher' ); ?></th>
								<th><?php esc_html_e( 'Trigger', 'smart-image-matcher' ); ?></th>
								<th><?php esc_html_e( 'Status', 'smart-image-matcher' ); ?></th>
								<th class="sim-dash-n"><?php esc_html_e( 'Articles', 'smart-image-matcher' ); ?></th>
								<th><?php esc_html_e( 'Result', 'smart-image-matcher' ); ?></th>
								<th class="sim-dash-n"><?php esc_html_e( 'Inserted', 'smart-image-matcher' ); ?></th>
								<th class="sim-dash-n"><?php esc_html_e( 'Review', 'smart-image-matcher' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $dash['runs'] as $run ) : ?>
								<?php
								$pill  = $status_pills[ $run['status'] ] ?? array( 'is-mute', ucfirst( (string) $run['status'] ) );
								$slots = max( 1, $run['inserted'] + $run['review'] + $run['skipped'] );
								?>
								<tr>
									<td><?php echo esc_html( $run['when'] ); ?></td>
									<td><?php echo esc_html( $run['trigger'] ); ?></td>
									<td>
										<span class="sim-dash-pill <?php echo esc_attr( $pill[0] ); ?>"><?php echo esc_html( $pill[1] ); ?></span>
										<?php if ( '' !== $run['note'] ) : ?>
											<div class="sim-dash-lost"><?php echo esc_html( $run['note'] ); ?></div>
										<?php endif; ?>
									</td>
									<td class="sim-dash-n"><?php echo esc_html( $run['articles'] ); ?></td>
									<td>
										<span class="sim-dash-minibar" title="<?php echo esc_attr( sprintf( /* translators: 1: inserted, 2: review, 3: skipped */ __( '%1$d inserted · %2$d to Review · %3$d skipped or already had an image', 'smart-image-matcher' ), $run['inserted'], $run['review'], $run['skipped'] ) ); ?>">
											<?php if ( $run['inserted'] ) : ?><i class="is-ins" style="width:<?php echo esc_attr( number_format( $run['inserted'] / $slots * 100, 1, '.', '' ) ); ?>%"></i><?php endif; ?>
											<?php if ( $run['review'] ) : ?><i class="is-rev" style="width:<?php echo esc_attr( number_format( $run['review'] / $slots * 100, 1, '.', '' ) ); ?>%"></i><?php endif; ?>
											<i class="is-rest"></i>
										</span>
									</td>
									<td class="sim-dash-n"><?php echo esc_html( number_format_i18n( $run['inserted'] ) ); ?></td>
									<td class="sim-dash-n"><?php echo esc_html( number_format_i18n( $run['review'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</section>
		</div>

		<aside class="sim-dash-stack">
			<section class="sim-dash-card" id="sim-dash-queue" aria-labelledby="sim-dash-q-h">
				<div class="sim-dash-card-head">
					<h2 id="sim-dash-q-h"><?php esc_html_e( 'Queue', 'smart-image-matcher' ); ?></h2>
					<?php
					$q_pill = array(
						'good' => array( 'is-ok', $queue['running'] || $queue['pending'] ? __( 'Moving', 'smart-image-matcher' ) : __( 'Idle', 'smart-image-matcher' ) ),
						'warn' => array( 'is-run', __( 'Busy', 'smart-image-matcher' ) ),
						'bad'  => array( 'is-bad', __( 'Blocked', 'smart-image-matcher' ) ),
					)[ $health['state'] ] ?? array( 'is-mute', '' );
					?>
					<span class="sim-dash-pill <?php echo esc_attr( $q_pill[0] ); ?>"><?php echo esc_html( $q_pill[1] ); ?></span>
				</div>
				<div class="sim-dash-card-body">
					<div class="sim-dash-qnums">
						<div><b><?php echo esc_html( number_format_i18n( $queue['pending'] ) ); ?></b><span><?php esc_html_e( 'Waiting', 'smart-image-matcher' ); ?></span></div>
						<div><b><?php echo esc_html( number_format_i18n( $queue['past_due'] ) ); ?></b><span><?php esc_html_e( 'Overdue', 'smart-image-matcher' ); ?></span></div>
						<div><b><?php echo esc_html( number_format_i18n( $queue['running'] ) ); ?></b><span><?php esc_html_e( 'Running', 'smart-image-matcher' ); ?></span></div>
					</div>
					<?php if ( $queue['now'] ) : ?>
						<div class="sim-dash-now">
							<span class="sim-dash-k">
								<?php
								/* translators: %s: duration */
								echo esc_html( sprintf( __( 'Running now · %s', 'smart-image-matcher' ), $queue['now']['seconds'] < 120 ? sprintf( /* translators: %d: seconds */ __( '%d s', 'smart-image-matcher' ), $queue['now']['seconds'] ) : $minutes( intdiv( $queue['now']['seconds'], 60 ) ) ) );
								?>
							</span>
							<span class="sim-dash-t2"><?php echo esc_html( (string) $queue['now']['title'] ); ?></span>
							<?php if ( $queue['now']['post_id'] ) : ?>
								<span class="sim-dash-muted">#<?php echo esc_html( (string) $queue['now']['post_id'] ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<?php if ( $health['queue']['stuck'] > 0 ) : ?>
						<p class="sim-dash-bad">
							<?php
							/* translators: 1: hook, 2: minutes */
							echo esc_html( sprintf( __( '%1$s has been running for %2$d min. Action Scheduler runs one batch at a time, so everything waits behind it.', 'smart-image-matcher' ), $health['queue']['stuck_hook'], $health['queue']['stuck_minutes'] ) );
							?>
						</p>
					<?php endif; ?>
					<div class="sim-dash-kv">
						<div><span><?php esc_html_e( 'Pace', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( $queue['pace'] > 0 ? sprintf( /* translators: %s: rate */ __( '≈ %s articles / min', 'smart-image-matcher' ), number_format_i18n( $queue['pace'], 1 ) ) : __( 'Nothing finished in the last 30 min', 'smart-image-matcher' ) ); ?></strong></div>
						<div><span><?php esc_html_e( 'Last finished', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( $queue['last'] ? $queue['last']['when'] . ( $queue['last']['title'] ? ' · ' . $queue['last']['title'] : '' ) : '—' ); ?></strong></div>
						<div><span><?php esc_html_e( 'Watchdog', 'smart-image-matcher' ); ?></span><strong>
							<?php
							$wd = $queue['watchdog'];
							if ( ! empty( $wd['at'] ) ) {
								$wd_found = (int) ( $wd['freed'] ?? 0 ) + (int) ( $wd['closed'] ?? 0 );
								echo esc_html(
									sprintf(
										/* translators: 1: time, 2: result */
										__( 'Checked %1$s · %2$s', 'smart-image-matcher' ),
										$data->when( $data->toLocal( (int) $wd['at'] ) ),
										$wd_found
											/* translators: 1: freed, 2: closed */
											? sprintf( __( 'freed %1$d, closed %2$d', 'smart-image-matcher' ), (int) ( $wd['freed'] ?? 0 ), (int) ( $wd['closed'] ?? 0 ) )
											: __( 'nothing stuck', 'smart-image-matcher' )
									)
								);
							} else {
								esc_html_e( 'Not run yet', 'smart-image-matcher' );
							}
							?>
						</strong></div>
					</div>
					<form class="sim-dash-card-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( QueueWatchdog::CHECK_NOW_ACTION ); ?>" />
						<?php wp_nonce_field( QueueWatchdog::CHECK_NOW_ACTION ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Check now', 'smart-image-matcher' ); ?></button>
						<span class="sim-dash-muted"><?php esc_html_e( 'Frees a stuck slot and closes stalled runs. Also runs every 15 min.', 'smart-image-matcher' ); ?></span>
					</form>
				</div>
			</section>

			<section class="sim-dash-card" aria-labelledby="sim-dash-auto-h">
				<div class="sim-dash-card-head">
					<h2 id="sim-dash-auto-h"><?php esc_html_e( 'Automation', 'smart-image-matcher' ); ?></h2>
					<a class="button button-small" href="<?php echo esc_url( $has_bulk ? $url_schedule : $url_settings ); ?>"><?php echo esc_html( $has_bulk ? __( 'Schedule', 'smart-image-matcher' ) : __( 'Edit', 'smart-image-matcher' ) ); ?></a>
				</div>
				<div class="sim-dash-card-body">
					<div class="sim-dash-kv sim-dash-flush">
						<div><span><?php esc_html_e( 'Automatic run', 'smart-image-matcher' ); ?></span><strong>
							<?php
							echo esc_html(
								$cron_on
									/* translators: 1: frequency, 2: next run */
									? sprintf( __( '%1$s · next %2$s', 'smart-image-matcher' ), $intervals[ (string) Settings::get( 'fiaa_cron_interval' ) ] ?? (string) Settings::get( 'fiaa_cron_interval' ), $data->when( $next_tick ) )
									: __( 'Off', 'smart-image-matcher' )
							);
							?>
						</strong></div>
						<div><span><?php esc_html_e( 'Articles', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( implode( ', ', $statuses ) . ' · ' . ( $featured_filters[ (string) Settings::get( 'fiaa_cron_featured_filter' ) ] ?? '' ) ); ?></strong></div>
						<div><span><?php esc_html_e( 'Fills', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( $fills[ \SmartImageMatcher\Domain\ArticleProcessor::normalizeFill( Settings::get( 'fiaa_cron_fill' ) ) ] ); ?></strong></div>
						<div><span><?php esc_html_e( 'On publish', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( Settings::get( 'ai_image_auto_featured_on_publish' ) ? __( 'On', 'smart-image-matcher' ) : __( 'Off', 'smart-image-matcher' ) ); ?></strong></div>
						<div><span><?php esc_html_e( 'Image generation', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( Settings::get( 'ai_image_generation_enabled' ) ? __( 'On', 'smart-image-matcher' ) : __( 'Off', 'smart-image-matcher' ) ); ?></strong></div>
						<div><span><?php esc_html_e( 'Matching', 'smart-image-matcher' ); ?></span><strong><?php echo esc_html( $match_mode ); ?></strong></div>
					</div>
				</div>
			</section>

			<section class="sim-dash-card" aria-labelledby="sim-dash-rules-h">
				<div class="sim-dash-card-head">
					<h2 id="sim-dash-rules-h"><?php esc_html_e( 'How images are chosen', 'smart-image-matcher' ); ?></h2>
					<a class="button button-small" href="<?php echo esc_url( $url_settings ); ?>"><?php esc_html_e( 'Edit', 'smart-image-matcher' ); ?></a>
				</div>
				<div class="sim-dash-card-body">
					<div class="sim-dash-rules">
						<div class="is-ins"><b>≥ <?php echo esc_html( (string) $auto ); ?>%</b><span><?php esc_html_e( 'Inserted automatically', 'smart-image-matcher' ); ?></span></div>
						<div class="is-rev"><b><?php echo esc_html( $floor . '–' . ( $auto - 1 ) ); ?>%</b><span><?php esc_html_e( 'Sent to Review', 'smart-image-matcher' ); ?></span></div>
						<div class="is-skp"><b>&lt; <?php echo esc_html( (string) $floor ); ?>%</b><span><?php esc_html_e( 'Left alone', 'smart-image-matcher' ); ?></span></div>
					</div>
					<hr class="sim-dash-sep" />
					<div class="sim-dash-rules">
						<div class="is-skp"><b><?php esc_html_e( 'Always', 'smart-image-matcher' ); ?></b><span><?php esc_html_e( 'Never an image for another place, or one that shares only the place name', 'smart-image-matcher' ); ?></span></div>
						<div class="is-skp"><b><?php esc_html_e( 'Featured', 'smart-image-matcher' ); ?></b><span><?php esc_html_e( 'Assigned only on an exact or prefix filename match; anything weaker goes to Review', 'smart-image-matcher' ); ?></span></div>
						<div class="is-skp"><b><?php esc_html_e( 'Rejected', 'smart-image-matcher' ); ?></b><span><?php esc_html_e( 'A rejected image is never suggested for that heading again', 'smart-image-matcher' ); ?></span></div>
					</div>
				</div>
			</section>
		</aside>
	</div>

	<section class="sim-dash-card" id="sim-dash-attn" aria-labelledby="sim-dash-attn-h">
		<div class="sim-dash-card-head sim-dash-pad-b"><h2 id="sim-dash-attn-h"><?php esc_html_e( 'Needs your attention', 'smart-image-matcher' ); ?> <span class="sim-dash-pill is-mute" id="sim-dash-attn-count"><?php echo esc_html( (string) count( $dash['attention'] ) ); ?></span></h2></div>
		<div id="sim-dash-attn-list">
			<?php foreach ( $dash['attention'] as $item ) : ?>
				<div class="sim-dash-attn is-<?php echo esc_attr( $item['severity'] ); ?>" data-key="<?php echo esc_attr( $item['key'] ); ?>">
					<span class="sim-dash-sev" aria-hidden="true"></span>
					<div><strong><?php echo esc_html( $item['title'] ); ?></strong><?php if ( '' !== (string) $item['text'] ) : ?><p><?php echo esc_html( $item['text'] ); ?></p><?php endif; ?></div>
					<div class="sim-dash-acts">
						<?php if ( ! empty( $item['job_id'] ) && $has_bulk ) : ?>
							<button type="button" class="button button-small" data-requeue="<?php echo esc_attr( $item['job_id'] ); ?>"><?php esc_html_e( 'Re-queue', 'smart-image-matcher' ); ?></button>
						<?php endif; ?>
						<?php if ( ! empty( $item['link'] ) && $has_bulk ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $item['link'] ); ?>"><?php echo esc_html( $item['link_text'] ); ?></a>
						<?php endif; ?>
						<button type="button" class="button-link" data-dismiss-attn><?php esc_html_e( 'Dismiss', 'smart-image-matcher' ); ?></button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="sim-dash-empty" id="sim-dash-attn-empty"<?php echo empty( $dash['attention'] ) ? '' : ' hidden'; ?>><?php esc_html_e( 'Nothing needs you right now.', 'smart-image-matcher' ); ?></p>
	</section>

	<section class="sim-dash-card" aria-labelledby="sim-dash-err-h">
		<div class="sim-dash-card-head sim-dash-pad-b">
			<div><h2 id="sim-dash-err-h"><?php esc_html_e( 'Errors', 'smart-image-matcher' ); ?></h2><span class="sim-dash-muted"><?php esc_html_e( 'Grouped by message. Stored on this site — no debug.log needed.', 'smart-image-matcher' ); ?></span></div>
			<?php if ( ! empty( $dash['errors'] ) ) : ?>
				<button type="button" class="button button-small" id="sim-dash-clear-errors"><?php esc_html_e( 'Clear all', 'smart-image-matcher' ); ?></button>
			<?php endif; ?>
		</div>
		<div id="sim-dash-errors">
			<?php foreach ( $dash['errors'] as $group ) : ?>
				<div class="sim-dash-err<?php echo $group['old'] ? ' is-old' : ''; ?>" data-message="<?php echo esc_attr( $group['message'] ); ?>">
					<div>
						<div class="sim-dash-msg"><?php echo esc_html( $group['message'] ); ?></div>
						<div class="sim-dash-meta">
							<span class="sim-dash-times"><?php echo esc_html( $group['count'] . '×' ); ?></span>
							<span>
								<?php
								$range = $group['first'] === $group['last'] || gmdate( 'Y-m-d H:i', $group['first'] ) === gmdate( 'Y-m-d H:i', $group['last'] )
									? $data->when( $group['last'] )
									: $data->when( $group['first'] ) . ' – ' . $data->when( $group['last'] );
								echo esc_html( $range . ' · ' . human_time_diff( $group['last'], (int) current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'smart-image-matcher' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- local-time comparison.
								?>
							</span>
							<?php if ( $group['posts'] ) : ?>
								<span><?php echo esc_html( sprintf( /* translators: %d: count */ _n( '%d post', '%d posts', count( $group['posts'] ), 'smart-image-matcher' ), count( $group['posts'] ) ) ); ?></span>
							<?php endif; ?>
						</div>
					</div>
					<div class="sim-dash-acts">
						<span class="sim-dash-pill <?php echo esc_attr( $group['old'] ? 'is-mute' : 'is-bad' ); ?>"><?php echo esc_html( $group['old'] ? __( 'Old', 'smart-image-matcher' ) : __( 'Recent', 'smart-image-matcher' ) ); ?></span>
						<button type="button" class="button-link" data-dismiss-error><?php esc_html_e( 'Dismiss', 'smart-image-matcher' ); ?></button>
					</div>
					<?php if ( $group['details'] || $group['posts'] ) : ?>
						<details>
							<summary><?php esc_html_e( 'Details', 'smart-image-matcher' ); ?></summary>
							<pre><?php echo esc_html( implode( "\n", $group['details'] ) . ( $group['posts'] ? "\n" . __( 'posts:', 'smart-image-matcher' ) . ' ' . implode( ' ', $group['posts'] ) : '' ) ); ?></pre>
						</details>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="sim-dash-empty" id="sim-dash-err-empty"<?php echo empty( $dash['errors'] ) ? '' : ' hidden'; ?>><?php esc_html_e( 'No errors. New ones appear here with the post they affected.', 'smart-image-matcher' ); ?></p>
	</section>

	<div class="sim-dash-toast" id="sim-dash-toast" role="status" aria-live="polite" hidden></div>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
