<?php
/**
 * Dashboard actions.
 *
 *   POST /dashboard/errors/dismiss     remove one error group (by message)
 *   POST /dashboard/errors/clear       remove every stored error
 *   POST /dashboard/attention/dismiss  hide one "Needs attention" item
 *
 * @package SmartImageMatcher\REST
 * @since   3.7.0
 */

declare( strict_types=1 );

namespace SmartImageMatcher\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use SmartImageMatcher\Admin\DashboardData;
use SmartImageMatcher\Logging\Logger;

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PSR-4 camelCase methods (see agents.md).

/**
 * Class DashboardController
 *
 * @since 3.7.0
 */
class DashboardController extends Controller {

	/**
	 * Register routes.
	 *
	 * @since 3.7.0
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route( self::NAMESPACE, '/dashboard/errors/dismiss', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'dismissError' ),
			'permission_callback' => array( $this, 'canManage' ),
			'args'                => array( 'message' => array( 'type' => 'string', 'required' => true ) ),
		) );

		register_rest_route( self::NAMESPACE, '/dashboard/errors/clear', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'clearErrors' ),
			'permission_callback' => array( $this, 'canManage' ),
		) );

		register_rest_route( self::NAMESPACE, '/dashboard/attention/dismiss', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'dismissAttention' ),
			'permission_callback' => array( $this, 'canManage' ),
			'args'                => array( 'key' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
	}

	/**
	 * Admins only.
	 *
	 * @since 3.7.0
	 * @return bool
	 */
	public function canManage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Remove one error group. The message is matched exactly, never echoed back as HTML.
	 *
	 * @since 3.7.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function dismissError( \WP_REST_Request $request ): \WP_REST_Response {
		// REST params are not slashed; class names in messages keep their backslashes.
		$message = (string) $request->get_param( 'message' );
		return rest_ensure_response( array( 'removed' => Logger::removeErrors( $message ) ) );
	}

	/**
	 * Remove every stored error.
	 *
	 * @since 3.7.0
	 * @return \WP_REST_Response
	 */
	public function clearErrors(): \WP_REST_Response {
		return rest_ensure_response( array( 'removed' => Logger::removeErrors( null ) ) );
	}

	/**
	 * Hide one attention item.
	 *
	 * @since 3.7.0
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function dismissAttention( \WP_REST_Request $request ): \WP_REST_Response {
		DashboardData::dismiss( (string) $request->get_param( 'key' ) );
		return rest_ensure_response( array( 'dismissed' => true ) );
	}
}
