<?php
/**
 * Base REST controller.
 *
 * @package SmartImageMatcher\REST
 * @since   3.0.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Controller
 *
 * @since 3.0.0
 */
abstract class Controller extends \WP_REST_Controller {

	/**
	 * REST API namespace.
	 */
	const NAMESPACE = 'smart-image-matcher/v1';

	/**
	 * Register this controller's routes.
	 *
	 * @since 3.0.0
	 * @return void
	 */
	abstract public function registerRoutes(): void;
}
