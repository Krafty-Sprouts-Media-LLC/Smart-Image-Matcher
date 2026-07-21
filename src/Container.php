<?php
/**
 * Minimal service container.
 *
 * Lazy-instantiates services from registered factory closures.
 *
 * @package SmartImageMatcher
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Container
 *
 * @since 3.0.0
 */
class Container {

	/**
	 * Registered factory closures keyed by service ID.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Resolved service instances keyed by service ID.
	 *
	 * @var array<string, mixed>
	 */
	private array $resolved = array();

	/**
	 * Register a factory for a service.
	 *
	 * @since 3.0.0
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory that returns the service instance.
	 * @return void
	 */
	public function bind( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] ); // Reset if re-registered.
	}

	/**
	 * Resolve and return a service (lazy singleton).
	 *
	 * @since 3.0.0
	 * @param string $id Service identifier.
	 * @return mixed
	 * @throws \InvalidArgumentException When the service has no registered factory.
	 */
	public function get( string $id ) {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					'SmartImageMatcher\\Container: no factory registered for "%s".',
					esc_html( $id )
				)
			);
		}

		$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->resolved[ $id ];
	}

	/**
	 * Check whether a service is registered.
	 *
	 * @since 3.0.0
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}
}
