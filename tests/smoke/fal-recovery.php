<?php
/**
 * Live fal recovery smoke test.
 *
 * Run through WP-CLI with SIM_TEST_REQUEST_ID and SIM_TEST_MODEL_ID. The test
 * creates a temporary draft, recovers the existing fal result, verifies the
 * Media Library/featured-image linkage, then deletes all temporary records.
 *
 * @package SmartImageMatcher\Tests
 */

use SmartImageMatcher\Premium\AiImageGenerator;

$request_id = sanitize_text_field( (string) getenv( 'SIM_TEST_REQUEST_ID' ) );
$model_id   = sanitize_text_field( (string) getenv( 'SIM_TEST_MODEL_ID' ) );

if ( '' === $request_id || '' === $model_id ) {
	throw new RuntimeException( 'SIM_TEST_REQUEST_ID and SIM_TEST_MODEL_ID are required.' );
}

$post_id       = 0;
$attachment_id = 0;

try {
	$post_id = wp_insert_post(
		array(
			'post_title'  => 'SIM fal recovery smoke test',
			'post_type'   => 'post',
			'post_status' => 'draft',
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		throw new RuntimeException( $post_id->get_error_message() );
	}
	$post_id = (int) $post_id;

	$result = AiImageGenerator::recoverFalJob(
		$post_id,
		'featured',
		array(
			'fal'     => array(
				'request_id' => $request_id,
				'model_id'   => $model_id,
			),
			'context' => array(
				'post_id'      => $post_id,
				'heading_hash' => 'featured',
				'heading_text' => 'SIM fal recovery smoke test',
				'purpose'      => 'featured',
				'style'        => 'photo',
				'brief'        => 'Existing completed fal image recovery test.',
				'image_prompt' => 'Existing completed fal image recovery test.',
			),
		)
	);
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}
	$attachment_id = (int) $result;

	if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
		throw new RuntimeException( 'Recovery did not create an image attachment.' );
	}
	if ( $attachment_id !== (int) get_post_thumbnail_id( $post_id ) ) {
		throw new RuntimeException( 'Recovered attachment was not assigned as the featured image.' );
	}
	if ( $request_id !== (string) get_post_meta( $attachment_id, '_sim_fal_request_id', true ) ) {
		throw new RuntimeException( 'Recovered attachment did not retain its fal request id.' );
	}

	WP_CLI::success( sprintf( 'Live recovery created attachment %d and assigned it to temporary post %d.', $attachment_id, $post_id ) );
} finally {
	if ( $attachment_id > 0 ) {
		wp_delete_attachment( $attachment_id, true );
	}
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
		global $wpdb;
		$table = $wpdb->prefix . 'sim_matches';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) );
	}
}
