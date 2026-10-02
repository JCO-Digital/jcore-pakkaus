<?php
/**
 * HTTP client for the optimizer service.
 *
 * @package Video_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around the WordPress HTTP API.
 */
class Vopt_Client {

	/**
	 * Perform a request against the service and decode the JSON response.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path starting with a slash.
	 * @param array|null $body   JSON body.
	 * @param array      $args   Extra wp_remote_request() args.
	 * @return array|true|WP_Error Decoded body, true for empty 2xx responses, or an error.
	 */
	public static function request( $method, $path, $body = null, $args = array() ) {
		if ( ! Vopt_Settings::is_configured() ) {
			return new WP_Error( 'vopt_not_configured', __( 'The optimizer service URL and API token are not configured.', 'video-optimizer' ) );
		}

		$args = array_merge(
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(),
			),
			$args
		);

		$args['headers']['Authorization'] = 'Bearer ' . Vopt_Settings::get( 'api_token' );
		$args['headers']['Accept']        = 'application/json';
		$args['user-agent']               = 'video-optimizer-wp/' . VOPT_VERSION . '; ' . home_url( '/' );

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::url( $path ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$detail = is_array( $data ) && isset( $data['detail'] ) ? $data['detail'] : wp_remote_retrieve_response_message( $response );
			if ( is_array( $detail ) ) {
				$detail = wp_json_encode( $detail );
			}
			return new WP_Error(
				'vopt_http_' . $code,
				/* translators: 1: HTTP status code, 2: error detail */
				sprintf( __( 'Optimizer service returned HTTP %1$d: %2$s', 'video-optimizer' ), $code, $detail ),
				array( 'status' => $code )
			);
		}

		return is_array( $data ) ? $data : true;
	}

	/**
	 * Absolute URL for a service path.
	 *
	 * @param string $path Path starting with a slash.
	 * @return string
	 */
	public static function url( $path ) {
		return untrailingslashit( Vopt_Settings::get( 'service_url' ) ) . $path;
	}

	/**
	 * Service capabilities (authenticated, so it also validates the token).
	 *
	 * @return array|WP_Error
	 */
	public static function info() {
		return self::request( 'GET', '/info', null, array( 'timeout' => 10 ) );
	}

	/**
	 * Submit a job.
	 *
	 * @param array $payload Job payload.
	 * @return array|WP_Error
	 */
	public static function create_job( $payload ) {
		return self::request( 'POST', '/jobs', $payload, array( 'timeout' => 10 ) );
	}

	/**
	 * Fetch a job.
	 *
	 * @param string $job_id Job ID.
	 * @return array|WP_Error
	 */
	public static function get_job( $job_id ) {
		return self::request( 'GET', '/jobs/' . rawurlencode( $job_id ) );
	}

	/**
	 * Delete a job and its files on the service.
	 *
	 * @param string $job_id Job ID.
	 * @return array|true|WP_Error
	 */
	public static function delete_job( $job_id ) {
		return self::request( 'DELETE', '/jobs/' . rawurlencode( $job_id ), null, array( 'timeout' => 5 ) );
	}

	/**
	 * Stream the optimized file to disk.
	 *
	 * @param string $job_id Job ID.
	 * @param string $dest   Destination path.
	 * @return true|WP_Error
	 */
	public static function download_output( $job_id, $dest ) {
		$response = wp_remote_get(
			self::url( '/jobs/' . rawurlencode( $job_id ) . '/output' ),
			array(
				'timeout'  => 900,
				'stream'   => true,
				'filename' => $dest,
				'headers'  => array( 'Authorization' => 'Bearer ' . Vopt_Settings::get( 'api_token' ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $dest );
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $dest );
			return new WP_Error(
				'vopt_download_failed',
				/* translators: %d: HTTP status code */
				sprintf( __( 'Downloading the optimized video failed with HTTP %d.', 'video-optimizer' ), $code )
			);
		}

		return true;
	}
}
