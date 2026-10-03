<?php
/**
 * REST route for the optimizer service status.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus\Rest;

use Jcore\Pakkaus\Client;
use Jcore\Pakkaus\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells the admin app whether the optimizer service is reachable.
 */
final class Service_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'service';

	/**
	 * Registers the service route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				$this->route( \WP_REST_Server::READABLE, 'get_item' ),
			)
		);
	}

	/**
	 * GET /service — calls the service's authenticated /info endpoint, which
	 * checks the URL and the token in one go.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ): \WP_REST_Response {
		$response = array(
			'configured'   => Settings::is_configured(),
			'connected'    => false,
			'service_url'  => (string) Settings::get( 'service_url' ),
			'callback_url' => Callback_Controller::url(),
			'error'        => null,
			'info'         => null,
		);

		if ( ! $response['configured'] ) {
			return rest_ensure_response( $response );
		}

		$info = Client::info();
		if ( is_wp_error( $info ) ) {
			$response['error'] = $info->get_error_message();
		} elseif ( is_array( $info ) ) {
			$response['connected'] = true;
			$response['info']      = array(
				'version'         => (string) ( $info['version'] ?? '' ),
				'ffmpeg'          => (string) ( $info['ffmpeg'] ?? '' ),
				'codecs'          => array_keys( array_filter( (array) ( $info['codecs'] ?? array() ) ) ),
				'hdr_tonemapping' => ! empty( $info['hdr_tonemapping'] ),
				'workers'         => (int) ( $info['workers'] ?? 0 ),
				'queue'           => array(
					'waiting' => (int) ( $info['queue']['waiting'] ?? 0 ),
					'active'  => (int) ( $info['queue']['active'] ?? 0 ),
				),
			);
		}

		return rest_ensure_response( $response );
	}
}
