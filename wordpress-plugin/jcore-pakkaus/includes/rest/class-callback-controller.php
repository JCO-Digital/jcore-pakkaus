<?php
/**
 * Callback endpoint the optimizer service calls when a job changes state.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus\Rest;

use Jcore\Pakkaus\Processor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * POST /wp-json/jcore-pakkaus/v1/callback
 *
 * Each job carries its own random secret; the service signs "<timestamp>.<body>" with it
 * (HMAC-SHA256) and sends the result in the X-Jcore-Pakkaus-Signature header
 * (X-Video-Optimizer-Signature on services older than the rename).
 */
final class Callback_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'callback';

	/**
	 * How far the signed timestamp may be from the local clock, in seconds.
	 */
	private const MAX_CLOCK_SKEW = 300;

	/**
	 * Absolute URL the service is told to call back.
	 *
	 * @return string
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE . '/callback' );
	}

	/**
	 * Registers the public, signature-checked callback route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'verify' ),
			)
		);
	}

	/**
	 * Verifies the HMAC signature and resolves the attachment.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return true|\WP_Error
	 */
	public function verify( \WP_REST_Request $request ) {
		$denied = new \WP_Error( 'jcore_pakkaus_invalid_signature', 'Invalid signature.', array( 'status' => 401 ) );

		// Services older than the rename only send the X-Video-Optimizer-* headers.
		$body      = $request->get_body();
		$timestamp = (string) ( $request->get_header( 'x_jcore_pakkaus_timestamp' ) ?? $request->get_header( 'x_video_optimizer_timestamp' ) );
		$signature = (string) ( $request->get_header( 'x_jcore_pakkaus_signature' ) ?? $request->get_header( 'x_video_optimizer_signature' ) );
		$data      = json_decode( $body, true );

		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return $denied;
		}
		if ( ! is_array( $data ) || empty( $data['job']['id'] ) || empty( $data['job']['metadata']['attachment_id'] ) ) {
			return $denied;
		}

		$id     = (int) $data['job']['metadata']['attachment_id'];
		$job_id = (string) get_post_meta( $id, Processor::META_JOB, true );
		$secret = (string) get_post_meta( $id, Processor::META_SECRET, true );

		if ( '' === $job_id || '' === $secret || ! hash_equals( $job_id, (string) $data['job']['id'] ) ) {
			// Unknown or superseded job (e.g. re-optimized since). Tell the service to stop retrying.
			return new \WP_Error( 'jcore_pakkaus_unknown_job', 'Unknown job.', array( 'status' => 410 ) );
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return $denied;
		}

		$request->set_param( '_jcore_pakkaus_attachment_id', $id );
		$request->set_param( '_jcore_pakkaus_job', $data['job'] );

		return true;
	}

	/**
	 * Handles a verified callback.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		Processor::handle_callback( (int) $request->get_param( '_jcore_pakkaus_attachment_id' ), $request->get_param( '_jcore_pakkaus_job' ) );

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}
}
