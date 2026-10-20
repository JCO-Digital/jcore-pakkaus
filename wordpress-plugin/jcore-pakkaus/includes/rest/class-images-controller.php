<?php
/**
 * REST routes for the image list, its actions, and the WebP delivery check.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus\Rest;

use Jcore\Pakkaus\Images;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists JPEG and PNG images and starts or undoes their optimization.
 */
final class Images_Controller extends Library_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'images';

	/**
	 * Media type the routes cover.
	 *
	 * @var string
	 */
	protected $type = 'image';

	/**
	 * Registers the image routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		parent::register_routes();

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/serving',
			array(
				$this->route( \WP_REST_Server::READABLE, 'check_serving' ),
			)
		);
	}

	/**
	 * GET /images/serving — whether the web server hands WebP versions to browsers that take them, and the
	 * originals to the rest.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function check_serving( $request ): \WP_REST_Response {
		return rest_ensure_response( Images::check_serving() );
	}
}
