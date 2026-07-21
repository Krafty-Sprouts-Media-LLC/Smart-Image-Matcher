<?php
/**
 * Premium: Review Queue controller.
 *
 * The review queue is rendered inside the Bulk Processor SPA by bulk.js
 * consuming the REST endpoints — no separate PHP render required.
 * This class provides any server-side helpers the SPA doesn't cover.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ReviewQueue
 *
 * @since 3.0.0
 */
class ReviewQueue {

	/**
	 * WordPress admin page hook returned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $pageHook = '';

	/**
	 * Register hooks.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'registerMenu' ) );
	}

	/**
	 * Register the Review Queue submenu under SIM.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function registerMenu(): void {
		$this->pageHook = (string) add_submenu_page(
			'smart-image-matcher',
			__( 'Smart Image Matcher - Review Queue', 'smart-image-matcher' ),
			__( 'Review Queue', 'smart-image-matcher' ),
			'manage_options',
			'smart-image-matcher-review-queue',
			array( $this, 'renderPage' )
		);
	}

	/**
	 * Render the Review Queue page.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'smart-image-matcher' ) );
		}

		require SMART_IMAGE_MATCHER_PLUGIN_DIR . 'admin/views/review-queue.php';
	}

	/**
	 * Bulk-approve all matches in a job above a confidence threshold.
	 *
	 * Used by the "Approve all above X%" button in the review queue.
	 *
	 * @since 3.0.0
	 * @param string $jobId     Job ID.
	 * @param int    $threshold Minimum confidence score to approve.
	 * @return int Number of rows approved.
	 */
	public function bulkApproveAboveThreshold( string $jobId, int $threshold ): int {
		global $wpdb;

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smart_image_matcher_matches
				 SET status = 'approved'
				 WHERE status = 'pending'
				   AND confidence_score >= %d
				   AND created_at >= (
					   SELECT created_at FROM {$wpdb->prefix}smart_image_matcher_queue
					   WHERE job_id = %s LIMIT 1
				   )",
				$threshold,
				$jobId
			)
		);

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * Bulk-reject all matches in a job below a confidence threshold.
	 *
	 * @since 3.0.0
	 * @param string $jobId     Job ID.
	 * @param int    $threshold Confidence score below which to reject.
	 * @return int Number of rows rejected.
	 */
	public function bulkRejectBelowThreshold( string $jobId, int $threshold ): int {
		global $wpdb;

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}smart_image_matcher_matches
				 SET status = 'rejected'
				 WHERE status = 'pending'
				   AND confidence_score < %d
				   AND created_at >= (
					   SELECT created_at FROM {$wpdb->prefix}smart_image_matcher_queue
					   WHERE job_id = %s LIMIT 1
				   )",
				$threshold,
				$jobId
			)
		);

		return is_int( $result ) ? $result : 0;
	}
}
