<?php
/**
 * Published must never be picked for the user.
 *
 * @package SmartImageMatcher\Tests\Domain
 * @since   3.6.2
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\Domain;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Domain\PostStatuses;
use SmartImageMatcher\Settings\Sanitizer;
use SmartImageMatcher\Settings\Settings;

/**
 * Class PostStatusesTest
 *
 * @since 3.6.2
 */
class PostStatusesTest extends TestCase {

	/** @test */
	public function an_empty_list_falls_back_to_draft_not_published(): void {
		$this->assertSame( array( 'draft' ), PostStatuses::sanitizeList( '' ) );
		$this->assertSame( array( 'draft' ), PostStatuses::sanitizeList( array() ) );
		$this->assertSame( array( 'draft' ), PostStatuses::sanitizeList( 'nonsense' ) );
	}

	/** @test */
	public function an_explicit_published_choice_is_kept(): void {
		$this->assertContains( 'publish', PostStatuses::sanitizeList( 'publish,draft' ) );
	}

	/** @test */
	public function scheduled_run_defaults_and_fallbacks_never_include_published(): void {
		unset( $GLOBALS['sim_test_options'][ Settings::OPTION ] );
		$this->assertStringNotContainsString( 'publish', (string) Settings::get( 'fiaa_cron_post_statuses' ) );
		$this->assertSame( 'draft', ( new Sanitizer() )->postStatusList( null ) );
	}
}
