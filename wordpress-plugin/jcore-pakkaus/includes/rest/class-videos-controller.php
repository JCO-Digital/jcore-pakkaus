<?php
/**
 * REST routes for the video list and its actions.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus\Rest;

use Jcore\Pakkaus\Library;
use Jcore\Pakkaus\Processor;
use Jcore\Pakkaus\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists videos and starts or undoes their optimization.
 */
final class Videos_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'videos';

	/**
	 * Registers the video routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				$this->route(
					\WP_REST_Server::READABLE,
					'get_items',
					array(
						'status'   => array(
							'type'    => 'string',
							'enum'    => Library::FILTERS,
							'default' => 'all',
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
						'search'   => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/optimize-all',
			array(
				$this->route( \WP_REST_Server::CREATABLE, 'optimize_all' ),
			)
		);

		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/optimize',
			array(
				$this->route( \WP_REST_Server::CREATABLE, 'optimize_item', $id_arg ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/restore',
			array(
				$this->route( \WP_REST_Server::CREATABLE, 'restore_item', $id_arg ),
			)
		);
	}

	/**
	 * GET /videos — a page of videos plus the library summary.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ): \WP_REST_Response {
		$result = Library::query(
			(string) $request['status'],
			(int) $request['page'],
			(int) $request['per_page'],
			(string) $request['search']
		);

		$result['summary'] = Library::summary();

		return rest_ensure_response( $result );
	}

	/**
	 * POST /videos/optimize-all — marks every video never sent to the optimizer
	 * for the poller to submit, a batch a minute.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function optimize_all( $request ) {
		if ( ! Settings::is_configured() ) {
			return $this->not_configured();
		}

		$queued = 0;
		foreach ( Library::new_ids() as $id ) {
			if ( Processor::enqueue( $id ) ) {
				++$queued;
			}
		}

		if ( $queued ) {
			// Start the first batch now instead of waiting a minute for WP-Cron.
			Processor::poll();
		}

		return rest_ensure_response( array( 'queued' => $queued ) );
	}

	/**
	 * POST /videos/{id}/optimize — sends one video to the service.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function optimize_item( $request ) {
		$id = $this->video_id( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! Settings::is_configured() ) {
			return $this->not_configured();
		}

		$result = Processor::queue( $id );
		// A failed submission stays pending while the poller retries it; the item's message says so.
		if ( is_wp_error( $result ) && 'pending' !== Processor::status( $id ) ) {
			return $this->with_status( $result, 409 );
		}

		return rest_ensure_response( Library::item( $id ) );
	}

	/**
	 * POST /videos/{id}/restore — puts the backed-up original back.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore_item( $request ) {
		$id = $this->video_id( $request );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$result = Processor::restore( $id );
		if ( is_wp_error( $result ) ) {
			return $this->with_status( $result, 409 );
		}

		return rest_ensure_response( Library::item( $id ) );
	}

	/**
	 * The requested attachment ID, if it is a video.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return int|\WP_Error
	 */
	private function video_id( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! Processor::is_video( $id ) ) {
			return new \WP_Error( 'jcore_pakkaus_not_found', __( 'Video not found.', 'jcore-pakkaus' ), array( 'status' => 404 ) );
		}

		return $id;
	}

	/**
	 * Error returned while the service connection is not set up.
	 *
	 * @return \WP_Error
	 */
	private function not_configured(): \WP_Error {
		return new \WP_Error( 'jcore_pakkaus_not_configured', __( 'Connect the optimizer service in the settings first.', 'jcore-pakkaus' ), array( 'status' => 400 ) );
	}

	/**
	 * Gives an error the HTTP status this API answers with. Errors relayed from
	 * the service carry its status, which would read as this site's own.
	 *
	 * @param \WP_Error $error  The error.
	 * @param int       $status HTTP status.
	 *
	 * @return \WP_Error
	 */
	private function with_status( \WP_Error $error, int $status ): \WP_Error {
		$error->add_data( array( 'status' => $status ) );

		return $error;
	}
}
