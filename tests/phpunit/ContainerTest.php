<?php
/**
 * Unit tests for the Container.
 *
 * @package SmartImageMatcher\Tests
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\Tests;

use PHPUnit\Framework\TestCase;
use SmartImageMatcher\Container;

/**
 * Class ContainerTest
 *
 * @since 3.0.0
 */
class ContainerTest extends TestCase {

	private Container $container;

	protected function setUp(): void {
		$this->container = new Container();
	}

	/** @test */
	public function it_resolves_a_registered_service(): void {
		$this->container->bind( 'test', static fn() => 'hello' );

		$this->assertEquals( 'hello', $this->container->get( 'test' ) );
	}

	/** @test */
	public function it_is_a_lazy_singleton(): void {
		$calls = 0;
		$this->container->bind( 'counter', static function () use ( &$calls ) {
			$calls++;
			return new \stdClass();
		} );

		$this->container->get( 'counter' );
		$this->container->get( 'counter' );

		$this->assertEquals( 1, $calls, 'Factory must be called only once' );
	}

	/** @test */
	public function it_throws_for_unregistered_service(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->container->get( 'does_not_exist' );
	}
}
