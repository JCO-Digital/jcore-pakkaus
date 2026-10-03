<?php
/**
 * REST routes for the settings.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus\Rest;

use Jcore\Pakkaus\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the settings the admin app edits.
 */
final class Settings_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'settings';

	/**
	 * Registers the settings routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				$this->route( \WP_REST_Server::READABLE, 'get_item' ),
				$this->route( \WP_REST_Server::CREATABLE, 'update_item' ),
			)
		);
	}

	/**
	 * GET /settings — the settings, without the API token.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ): \WP_REST_Response {
		return rest_ensure_response( Settings::for_client() );
	}

	/**
	 * POST /settings — merges the supplied keys into the stored settings.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function update_item( $request ): \WP_REST_Response {
		$input = $request->get_json_params();
		Settings::update( is_array( $input ) ? $input : $request->get_body_params() );

		return rest_ensure_response( Settings::for_client() );
	}
}
