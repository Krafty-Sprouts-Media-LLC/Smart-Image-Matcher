<?php
/**
 * Unit tests for editor match-mode resolution.
 *
 * @package SmartImageMatcher\Tests\REST
 * @since   3.4.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\REST;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\REST\MatchController;

/**
 * Class MatchControllerResolveModeTest
 *
 * @since 3.4.0
 */
class MatchControllerResolveModeTest extends TestCase {

	/**
	 * Without a text provider, the editor stays on keyword.
	 *
	 * @return void
	 */
	public function test_falls_back_to_keyword_when_ai_unavailable(): void {
		$this->assertSame( 'keyword', MatchController::resolveMode( 'ai' ) );
		$this->assertSame( 'keyword', MatchController::resolveMode( 'keyword' ) );
		$this->assertSame( 'keyword', MatchController::resolveMode( 'nope' ) );
	}
}
