<?php
/**
 * Unit tests for BulkController action config (cancel must match enqueue args).
 *
 * @package SmartImageMatcher\Tests\REST
 * @since   3.3.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests\REST;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\REST\BulkController;

/**
 * Class BulkControllerActionConfigTest
 *
 * @since 3.3.0
 */
class BulkControllerActionConfigTest extends TestCase {

	/**
	 * Parent rows store post_ids; per-action config must not.
	 *
	 * @return void
	 */
	public function test_action_config_strips_post_ids(): void {
		$parent = array(
			'mode'      => 'process',
			'min_score' => 70,
			'post_type' => 'post',
			'filters'   => array(),
			'overwrite' => false,
			'style'     => 'photo',
			'post_ids'  => array( 12, 34 ),
		);

		$action = BulkController::actionConfigFromParent( $parent );

		$this->assertArrayNotHasKey( 'post_ids', $action );
		$this->assertSame( 'process', $action['mode'] );
		$this->assertSame( 70, $action['min_score'] );
		$this->assertSame( array( 12, 34 ), $parent['post_ids'] );
	}
}
