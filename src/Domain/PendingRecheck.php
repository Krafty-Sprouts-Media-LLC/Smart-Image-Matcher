<?php
/**
 * Re-check existing Review rows against RelevanceGuard.
 *
 * Rows written before the guard existed (wrong state, or only the state
 * name in common) are marked rejected. One bounded batch per call; the
 * caller persists the cursor and schedules the next batch.
 *
 * @package SmartImageMatcher\Domain
 * @since   3.4.9
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Domain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PendingRecheck
 *
 * @since 3.4.9
 */
class PendingRecheck {

	/**
	 * Option holding { after_id: int, done: bool }. Absent means nothing to do.
	 */
	const STATE_OPTION = 'smart_image_matcher_pending_recheck_state';

	/**
	 * Match persistence.
	 *
	 * @var MatchRepository
	 */
	private MatchRepository $matches;

	/**
	 * Image metadata lookup.
	 *
	 * @var ImageRepository
	 */
	private ImageRepository $images;

	/**
	 * Constructor.
	 *
	 * @since 3.4.9
	 * @param MatchRepository $matches Match repository.
	 * @param ImageRepository $images  Image repository.
	 */
	public function __construct( MatchRepository $matches, ImageRepository $images ) {
		$this->matches = $matches;
		$this->images  = $images;
	}

	/**
	 * Check one batch of pending rows.
	 *
	 * @since 3.4.9
	 * @param int $afterId   Last row ID already checked.
	 * @param int $batchSize Rows per batch.
	 * @return array{checked:int,rejected:int,next_after:int,done:bool}
	 */
	public function runBatch( int $afterId, int $batchSize = 200 ): array {
		$rows     = $this->matches->pendingBatchAfter( $afterId, $batchSize );
		$rejected = 0;
		$last     = $afterId;
		$titles   = array();

		foreach ( $rows as $row ) {
			$last    = max( $last, (int) $row['id'] );
			$post_id = (int) $row['post_id'];
			$image   = $this->images->metadataFor( (int) $row['image_id'] );

			if ( ! isset( $titles[ $post_id ] ) ) {
				$titles[ $post_id ] = (string) get_the_title( $post_id );
			}

			$reason = null === $image
				? RelevanceGuard::NO_SHARED_TOPIC
				: RelevanceGuard::rejectReason( (string) $row['heading_text'], $titles[ $post_id ], $image );

			if ( '' !== $reason ) {
				$this->matches->markRejected( (int) $row['id'] );
				++$rejected;
			}
		}

		return array(
			'checked'    => count( $rows ),
			'rejected'   => $rejected,
			'next_after' => $last,
			'done'       => count( $rows ) < $batchSize,
		);
	}
}
