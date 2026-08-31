<?php
/**
 * Premium: Process the article when a post is first published.
 *
 * Enqueues ArticleProcessor (one Action Scheduler job). Library insert/review
 * always runs; skip-band generation happens only if the adapter is available.
 *
 * @package SmartImageMatcher\Premium
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Premium;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Logging\Logger;
use SmartImageMatcher\Queue\Queue;
use SmartImageMatcher\Settings\Settings;

/**
 * Class AutoMatchOnPublish
 *
 * @since 3.0.0
 */
class AutoMatchOnPublish {

	/**
	 * Register hooks when auto-publish is enabled.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::get( 'ai_image_auto_featured_on_publish' ) ) {
			return;
		}

		add_action( 'transition_post_status', array( $this, 'onTransitionPostStatus' ), 20, 3 );
	}

	/**
	 * Queue article processing when a post is first published.
	 *
	 * @since 3.2.0
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 * @return void
	 */
	public function onTransitionPostStatus( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}

		if ( get_current_user_id() > 0 && ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		if ( ! Queue::isAvailable() ) {
			Logger::warn(
				'AutoMatchOnPublish: Action Scheduler unavailable',
				array( 'post_id' => $post->ID )
			);
			return;
		}

		$job_id = ( new Queue() )->enqueueProcessArticle( (int) $post->ID );

		if ( null === $job_id ) {
			Logger::warn(
				'AutoMatchOnPublish: failed to enqueue article processing',
				array( 'post_id' => $post->ID )
			);
			return;
		}

		Logger::info(
			'AutoMatchOnPublish: queued article processing',
			array(
				'post_id' => $post->ID,
				'job_id'  => $job_id,
			)
		);
	}
}
