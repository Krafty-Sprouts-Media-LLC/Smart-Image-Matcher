<?php
/** Premium: Auto-match on post publish/update. @package SmartImageMatcher\Premium @since 3.0.0 */
declare( strict_types=1 );
namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Class AutoMatchOnPublish — @since 3.0.0 */
class AutoMatchOnPublish {
	/** @since 3.0.0 @return void */
	public function register(): void {
		// TODO: Phase 7 — hook transition_post_status, enqueue match job.
	}
}
