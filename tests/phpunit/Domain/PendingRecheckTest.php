<?php
/**
 * Unit tests for PendingRecheck.
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.4.9
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\ImageRepository;
use SmartImageMatcher\Domain\MatchRepository;
use SmartImageMatcher\Domain\PendingRecheck;

/**
 * Class PendingRecheckTest
 *
 * @since 3.4.9
 */
class PendingRecheckTest extends TestCase {

	/** @test */
	public function rejects_wrong_state_and_state_only_rows_and_keeps_good_ones(): void {
		$rows = array(
			array( 'id' => 11, 'post_id' => 5, 'heading_text' => 'Manure and Animal Waste Duties on a Hobby Farm in Idaho', 'image_id' => 1 ),
			array( 'id' => 12, 'post_id' => 5, 'heading_text' => 'Agricultural Property Tax Classification in Kansas', 'image_id' => 2 ),
			array( 'id' => 13, 'post_id' => 5, 'heading_text' => 'Manure and Animal Waste Duties on a Hobby Farm in Montana', 'image_id' => 3 ),
			array( 'id' => 14, 'post_id' => 5, 'heading_text' => 'Zoning Rules in Montana', 'image_id' => 0 ),
		);
		$files = array(
			1 => 'Animal-Waste-Disposal-Laws-in-Pennsylvania.jpg',
			2 => 'Bowfishing-laws-in-Kansas.jpg',
			3 => 'Animal-Waste-Disposal-Laws-in-Montana.jpg',
		);

		$matches = $this->createMock( MatchRepository::class );
		$matches->method( 'pendingBatchAfter' )->with( 0, 200 )->willReturn( $rows );
		$rejected = array();
		$matches->method( 'markRejected' )->willReturnCallback(
			static function ( int $id ) use ( &$rejected ): void {
				$rejected[] = $id;
			}
		);

		$images = $this->createMock( ImageRepository::class );
		$images->method( 'metadataFor' )->willReturnCallback(
			static fn( int $id ) => isset( $files[ $id ] ) ? array( 'filename' => $files[ $id ] ) : null
		);

		$result = ( new PendingRecheck( $matches, $images ) )->runBatch( 0, 200 );

		$this->assertSame( array( 11, 12, 14 ), $rejected );
		$this->assertSame( 4, $result['checked'] );
		$this->assertSame( 3, $result['rejected'] );
		$this->assertSame( 14, $result['next_after'] );
		$this->assertTrue( $result['done'] );
	}

	/** @test */
	public function full_batch_is_not_done(): void {
		$matches = $this->createMock( MatchRepository::class );
		$matches->method( 'pendingBatchAfter' )->willReturn(
			array( array( 'id' => 7, 'post_id' => 1, 'heading_text' => 'Taxidermy in Alaska', 'image_id' => 1 ) )
		);
		$images = $this->createMock( ImageRepository::class );
		$images->method( 'metadataFor' )->willReturn( array( 'filename' => 'Taxidermy-Laws-in-Alaska.jpg' ) );

		$result = ( new PendingRecheck( $matches, $images ) )->runBatch( 6, 1 );

		$this->assertSame( 0, $result['rejected'] );
		$this->assertFalse( $result['done'] );
		$this->assertSame( 7, $result['next_after'] );
	}
}
