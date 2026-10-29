<?php
/**
 * REST routes shared by the video and image lists and their actions.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus\Rest;

use Jcore\Pakkaus\Conflicts;
use Jcore\Pakkaus\Images;
use Jcore\Pakkaus\Library;
use Jcore\Pakkaus\Processor;
use Jcore\Pakkaus\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists one type of attachment and starts or undoes their optimization.
 */
abstract class Library_Controller extends Controller {

	/**
	 * Attachments "optimize all" marks per request at most, and for how many seconds; the app calls again
	 * until it's done, so a large library doesn't run into the request's time limit.
	 */
	private const BULK_BATCH   = 500;
	private const BULK_SECONDS = 10;

	/**
	 * Media type the routes cover: `video` or `image`.
	 *
	 * @var string
	 */
	protected $type = 'video';

	/**
	 * Registers the list routes.
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
	 * GET /videos, /images — a page of attachments plus the library summary.
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
			(string) $request['search'],
			$this->type
		);

		$result['summary'] = Library::summary( $this->type );
		// Why images are left alone right now, if they are.
		$result['paused'] = 'image' === $this->type ? Conflicts::message() : '';

		return rest_ensure_response( $result );
	}

	/**
	 * POST /videos/optimize-all, /images/optimize-all — marks every attachment
	 * never sent to the optimizer for the poller to submit, a batch a minute.
	 * A large library is marked over several requests: `more` says to call again.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function optimize_all( $request ) {
		if ( ! Settings::is_configured() ) {
			return $this->not_configured();
		}
		if ( 'image' === $this->type && Conflicts::blocks_images() ) {
			return Conflicts::error();
		}

		$ids      = Library::new_ids( $this->type, self::BULK_BATCH );
		$deadline = microtime( true ) + self::BULK_SECONDS;
		$queued   = 0;
		$stopped  = false;
		foreach ( $ids as $id ) {
			if ( microtime( true ) > $deadline ) {
				$stopped = true;
				break;
			}
			if ( Processor::enqueue( $id ) ) {
				++$queued;
			}
		}

		if ( $queued ) {
			// Start the first batch now instead of in a minute, without making this request wait for it.
			Processor::poll_soon();
		}

		return rest_ensure_response(
			array(
				'queued' => $queued,
				// Nothing marked means whatever is left can't be, so calling again wouldn't end.
				'more'   => $queued > 0 && ( $stopped || count( $ids ) === self::BULK_BATCH ),
			)
		);
	}

	/**
	 * POST /videos/{id}/optimize, /images/{id}/optimize — sends one attachment to the service.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function optimize_item( $request ) {
		$id = $this->attachment_id( $request );
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
	 * POST /videos/{id}/restore, /images/{id}/restore — puts the original back.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore_item( $request ) {
		$id = $this->attachment_id( $request );
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
	 * The requested attachment ID, if it is of this list's type.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return int|\WP_Error
	 */
	private function attachment_id( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $id || 'attachment' !== get_post_type( $id ) || ! $this->is_type( $id ) ) {
			return new \WP_Error(
				'jcore_pakkaus_not_found',
				'image' === $this->type ? __( 'Image not found.', 'jcore-pakkaus' ) : __( 'Video not found.', 'jcore-pakkaus' ),
				array( 'status' => 404 )
			);
		}

		return $id;
	}

	/**
	 * Whether an attachment belongs in this list.
	 *
	 * @param int $id Attachment ID.
	 *
	 * @return bool
	 */
	private function is_type( int $id ): bool {
		return 'image' === $this->type ? Images::is_image( $id ) : Processor::is_video( $id );
	}

	/**
	 * Error returned while the service connection is not set up.
	 *
	 * @return \WP_Error
	 */
	protected function not_configured(): \WP_Error {
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
