<?php
/**
 * Unit tests for RelevanceGuard (rows taken from a real Review queue).
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.4.9
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\RelevanceGuard;

/**
 * Class RelevanceGuardTest
 *
 * @since 3.4.9
 */
class RelevanceGuardTest extends TestCase {

	protected function setUp(): void {
		unset( $GLOBALS['sim_test_options']['smart_image_matcher_settings'] );
	}

	/**
	 * Reason for one heading / image pair.
	 *
	 * @param string $heading  Heading text.
	 * @param string $context  Post title.
	 * @param string $filename Image filename.
	 * @return string
	 */
	private function reason( string $heading, string $context, string $filename ): string {
		return RelevanceGuard::rejectReason( $heading, $context, array( 'filename' => $filename ) );
	}

	/** @test */
	public function image_for_another_state_is_rejected(): void {
		$this->assertSame(
			RelevanceGuard::PLACE_CONFLICT,
			$this->reason( 'Right-to-Farm Protections and Neighbor Disputes for Hobby Farms in Idaho', '', 'Right-to-Farm-Laws-in-Virginia.jpg' )
		);
	}

	/** @test */
	public function post_title_supplies_the_state_when_heading_has_none(): void {
		$this->assertSame(
			RelevanceGuard::PLACE_CONFLICT,
			$this->reason(
				'Dog and Cat Mounts: Why Federal Fur Law Blocks Commercial Sale',
				'Selling Taxidermy in Alaska: The Legal Rules Every Seller Must Know',
				'Dog-Chaining-Laws-in-Ohio.jpg'
			)
		);
	}

	/** @test */
	public function west_virginia_is_not_virginia(): void {
		$this->assertSame(
			RelevanceGuard::PLACE_CONFLICT,
			$this->reason( 'Manure and Animal Waste Duties on a Hobby Farm in Virginia', '', 'Animal-Waste-Disposal-Laws-in-West-Virginia.jpg' )
		);
		$this->assertSame(
			RelevanceGuard::PLACE_CONFLICT,
			$this->reason( 'Right-to-Farm Protections and Neighbor Disputes for Hobby Farms in West Virginia', '', 'Right-to-Farm-Laws-in-Virginia.jpg' )
		);
	}

	/** @test */
	public function sharing_only_the_state_name_is_rejected(): void {
		$this->assertSame(
			RelevanceGuard::NO_SHARED_TOPIC,
			$this->reason( 'Agricultural Property Tax Classification and Acreage Thresholds in Kansas', '', 'Bowfishing-laws-in-Kansas.jpg' )
		);
		$this->assertSame(
			RelevanceGuard::NO_SHARED_TOPIC,
			$this->reason( 'Agricultural Property Tax Classification and Acreage Thresholds in Mississippi', '', 'Taxidermy-Laws-in-Mississippi.jpg' )
		);
	}

	/** @test */
	public function generic_legal_words_do_not_count_as_a_shared_topic(): void {
		$this->assertSame(
			RelevanceGuard::NO_SHARED_TOPIC,
			$this->reason( 'Platform Rules vs New Jersey Law: Facebook Marketplace, Craigslist, and Classifieds', '', 'Neighbors-Dog-on-My-Property-Laws-in-New-Jersey.jpg' )
		);
	}

	/** @test */
	public function same_state_and_same_topic_is_kept(): void {
		$this->assertSame(
			'',
			$this->reason( 'Manure and Animal Waste Duties on a Hobby Farm in Montana', '', 'Animal-Waste-Disposal-Laws-in-Montana.jpg' )
		);
		$this->assertSame(
			'',
			$this->reason( 'Is It Legal to Sell a Taxidermy Mount in Alaska?', 'Selling Taxidermy in Alaska', 'Taxidermy-Laws-in-Alaska.jpg' )
		);
	}

	/** @test */
	public function image_without_a_state_is_not_a_place_conflict(): void {
		$this->assertSame(
			'',
			$this->reason( 'Fencing, Containment, and Escape Liability for Hobby-Farm Animals in Arizona', '', 'Farm-Animals.jpg' )
		);
	}

	/** @test */
	public function article_without_a_state_accepts_state_images(): void {
		$this->assertSame(
			'',
			$this->reason( 'Types of Lizards', 'Common Lizards', 'Types-Of-Lizards-In-California.jpg' )
		);
	}

	/** @test */
	public function title_and_alt_count_as_image_topic(): void {
		$this->assertSame(
			'',
			RelevanceGuard::rejectReason(
				'Agricultural Property Tax Classification in Kansas',
				'',
				array(
					'filename' => 'IMG-2231-kansas.jpg',
					'alt'      => 'Kansas farmland property tax map',
				)
			)
		);
	}
}
