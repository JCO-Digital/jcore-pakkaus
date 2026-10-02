<?php
/**
 * Callback endpoint the optimizer service calls when a job finishes.
 *
 * @package Video_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/video-optimizer/v1/callback
 *
 * Each job carries its own random secret; the service signs "<timestamp>.<body>" with it
 * (HMAC-SHA256) and sends the result in the X-Video-Optimizer-Signature header.
 */
class Vopt_Rest {

	const NAMESPACE = 'video-optimizer/v1';

	const MAX_CLOCK_SKEW = 300;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the callback route.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/callback',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'verify' ),
			)
		);
	}

	/**
	 * Verify the HMAC signature and resolve the attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function verify( $request ) {
		$denied = new WP_Error( 'vopt_invalid_signature', 'Invalid signature.', array( 'status' => 401 ) );

		$body      = $request->get_body();
		$timestamp = (string) $request->get_header( 'x_video_optimizer_timestamp' );
		$signature = (string) $request->get_header( 'x_video_optimizer_signature' );
		$data      = json_decode( $body, true );

		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_CLOCK_SKEW ) {
			return $denied;
		}
		if ( ! is_array( $data ) || empty( $data['job']['id'] ) || empty( $data['job']['metadata']['attachment_id'] ) ) {
			return $denied;
		}

		$id     = (int) $data['job']['metadata']['attachment_id'];
		$job_id = (string) get_post_meta( $id, Vopt_Processor::META_JOB, true );
		$secret = (string) get_post_meta( $id, Vopt_Processor::META_SECRET, true );

		if ( '' === $job_id || '' === $secret || ! hash_equals( $job_id, (string) $data['job']['id'] ) ) {
			// Unknown or superseded job (e.g. re-optimized since). Tell the service to stop retrying.
			return new WP_Error( 'vopt_unknown_job', 'Unknown job.', array( 'status' => 410 ) );
		}

		$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
		if ( ! hash_equals( $expected, $signature ) ) {
			return $denied;
		}

		$request->set_param( '_vopt_attachment_id', $id );
		$request->set_param( '_vopt_job', $data['job'] );
		return true;
	}

	/**
	 * Handle a verified callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		Vopt_Processor::handle_callback( (int) $request->get_param( '_vopt_attachment_id' ), $request->get_param( '_vopt_job' ) );
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}
