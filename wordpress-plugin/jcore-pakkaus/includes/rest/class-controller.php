<?php
/**
 * Shared base for the plugin's REST controllers.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the namespace and the capability check every admin route shares.
 */
abstract class Controller extends \WP_REST_Controller {

	/**
	 * Route namespace.
	 */
	public const NAMESPACE = 'jcore-pakkaus/v1';

	/**
	 * Route namespace, as `WP_REST_Controller` expects it.
	 *
	 * @var string
	 */
	protected $namespace = self::NAMESPACE;

	/**
	 * Permission callback for the administrator-only routes.
	 *
	 * @return bool
	 */
	public function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Builds a route definition with the administrator permission already set.
	 *
	 * @param string               $method   One of the `WP_REST_Server` method constants.
	 * @param string               $callback Method name on this controller.
	 * @param array<string, mixed> $args     Argument schema.
	 *
	 * @return array<string, mixed>
	 */
	protected function route( string $method, string $callback, array $args = array() ): array {
		return array(
			'methods'             => $method,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, 'admin_permission' ),
			'args'                => $args,
		);
	}
}
