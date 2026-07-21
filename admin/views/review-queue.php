<?php
/**
 * Review Queue admin page.
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

global $wpdb;

$matches_table = esc_sql( $wpdb->prefix . 'smart_image_matcher_matches' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$pending_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$matches_table} WHERE status = 'pending'"
);
$approved_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$matches_table} WHERE status = 'approved'"
);
$rejected_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$matches_table} WHERE status = 'rejected'"
);

$rows = $wpdb->get_results(
	"SELECT id, post_id, image_id, heading_text, heading_tag, confidence_score, match_method, status, created_at
	 FROM {$matches_table}
	 WHERE status = 'pending'
	 ORDER BY confidence_score DESC, created_at DESC
	 LIMIT 50",
	ARRAY_A
);
// phpcs:enable
?>
<div class="wrap sim-admin-page sim-review-page">
	<div class="sim-page-head">
		<div>
			<h1><?php esc_html_e( 'Review Queue', 'smart-image-matcher' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Review probable in-content image matches before insertion.', 'smart-image-matcher' ); ?>
			</p>
		</div>
		<div class="sim-page-actions">
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=smart-image-matcher-bulk' ) ); ?>">
				<?php esc_html_e( 'Open Bulk Processor', 'smart-image-matcher' ); ?>
			</a>
		</div>
	</div>

	<div class="sim-metric-grid">
		<div class="sim-card sim-metric">
			<span><?php esc_html_e( 'Pending', 'smart-image-matcher' ); ?></span>
			<strong><?php echo esc_html( (string) $pending_count ); ?></strong>
			<small><?php esc_html_e( 'Needs decision', 'smart-image-matcher' ); ?></small>
		</div>
		<div class="sim-card sim-metric">
			<span><?php esc_html_e( 'Approved', 'smart-image-matcher' ); ?></span>
			<strong class="sim-good"><?php echo esc_html( (string) $approved_count ); ?></strong>
			<small><?php esc_html_e( 'Ready for insertion', 'smart-image-matcher' ); ?></small>
		</div>
		<div class="sim-card sim-metric">
			<span><?php esc_html_e( 'Rejected', 'smart-image-matcher' ); ?></span>
			<strong><?php echo esc_html( (string) $rejected_count ); ?></strong>
			<small><?php esc_html_e( 'Excluded from insertion', 'smart-image-matcher' ); ?></small>
		</div>
	</div>

	<section class="sim-card">
		<div class="sim-card-head">
			<div>
				<h2><?php esc_html_e( 'Pending Matches', 'smart-image-matcher' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Use the Bulk Processor review step for approve, reject, swap, and insert actions.', 'smart-image-matcher' ); ?></p>
			</div>
		</div>

		<?php if ( empty( $rows ) ) : ?>
			<p class="description"><?php esc_html_e( 'No pending matches are waiting for review.', 'smart-image-matcher' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post', 'smart-image-matcher' ); ?></th>
						<th><?php esc_html_e( 'Heading', 'smart-image-matcher' ); ?></th>
						<th><?php esc_html_e( 'Image', 'smart-image-matcher' ); ?></th>
						<th><?php esc_html_e( 'Score', 'smart-image-matcher' ); ?></th>
						<th><?php esc_html_e( 'Method', 'smart-image-matcher' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $smart_image_matcher_row ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $smart_image_matcher_row['post_id'] ) ); ?>">
								<?php echo esc_html( get_the_title( (int) $smart_image_matcher_row['post_id'] ) ?: '#' . (int) $smart_image_matcher_row['post_id'] ); ?>
							</a>
						</td>
						<td>
							<code><?php echo esc_html( (string) ( $smart_image_matcher_row['heading_tag'] ?? '' ) ); ?></code>
							<?php echo esc_html( (string) ( $smart_image_matcher_row['heading_text'] ?? '' ) ); ?>
						</td>
						<td>
							<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $smart_image_matcher_row['image_id'] ) ); ?>">
								<?php echo esc_html( get_the_title( (int) $smart_image_matcher_row['image_id'] ) ?: '#' . (int) $smart_image_matcher_row['image_id'] ); ?>
							</a>
						</td>
						<td><span class="sim-status sim-status-info"><?php echo esc_html( (string) (int) $smart_image_matcher_row['confidence_score'] ); ?>%</span></td>
						<td><?php echo esc_html( (string) $smart_image_matcher_row['match_method'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>
</div>
<?php // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound ?>
