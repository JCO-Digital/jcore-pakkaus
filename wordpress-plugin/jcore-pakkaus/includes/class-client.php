<?php
/**
 * HTTP client for the optimizer service.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the WordPress HTTP API.
 */
final class Client {

	// Image results downloaded at once; more would only crowd the service while it optimizes other images.
	private const PARALLEL_DOWNLOADS = 6;

	/**
	 * Perform a request against the service and decode the JSON response.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path starting with a slash.
	 * @param array|null $body   JSON body.
	 * @param array      $args   Extra wp_remote_request() args.
	 * @return array|true|\WP_Error Decoded body, true for empty 2xx responses, or an error.
	 */
	public static function request( $method, $path, $body = null, $args = array() ) {
		if ( ! Settings::is_configured() ) {
			return new \WP_Error( 'jcore_pakkaus_not_configured', __( 'The optimizer service URL and API token are not configured.', 'jcore-pakkaus' ) );
		}

		$args = array_merge(
			array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(),
			),
			$args
		);

		$args['headers']['Authorization'] = 'Bearer ' . Settings::get( 'api_token' );
		$args['headers']['Accept']        = 'application/json';
		$args['user-agent']               = 'jcore-pakkaus/' . JCORE_PAKKAUS_VERSION . '; ' . home_url( '/' );

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
			return new \WP_Error(
				'jcore_pakkaus_http_' . $code,
				/* translators: 1: HTTP status code, 2: error detail */
				sprintf( __( 'Optimizer service returned HTTP %1$d: %2$s', 'jcore-pakkaus' ), $code, $detail ),
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
		return untrailingslashit( Settings::get( 'service_url' ) ) . $path;
	}

	/**
	 * Service capabilities (authenticated, so it also validates the token).
	 *
	 * @return array|\WP_Error
	 */
	public static function info() {
		return self::request( 'GET', '/info', null, array( 'timeout' => 10 ) );
	}

	/**
	 * Submit a job.
	 *
	 * @param array $payload Job payload.
	 * @return array|\WP_Error
	 */
	public static function create_job( $payload ) {
		return self::request( 'POST', '/jobs', $payload, array( 'timeout' => 10 ) );
	}

	/**
	 * Submit an image job: an image and its resized copies.
	 *
	 * @param array $payload Job payload.
	 * @return array|\WP_Error
	 */
	public static function create_image_job( $payload ) {
		return self::request( 'POST', '/jobs/images', $payload, array( 'timeout' => 10 ) );
	}

	/**
	 * Fetch a job.
	 *
	 * @param string $job_id Job ID.
	 * @return array|\WP_Error
	 */
	public static function get_job( $job_id ) {
		return self::request( 'GET', '/jobs/' . rawurlencode( $job_id ) );
	}

	/**
	 * Delete a job and its files on the service.
	 *
	 * @param string $job_id Job ID.
	 * @return array|true|\WP_Error
	 */
	public static function delete_job( $job_id ) {
		return self::request( 'DELETE', '/jobs/' . rawurlencode( $job_id ), null, array( 'timeout' => 5 ) );
	}

	/**
	 * Stream the optimized video to disk.
	 *
	 * @param string $job_id Job ID.
	 * @param string $dest   Destination path.
	 * @return true|\WP_Error
	 */
	public static function download_output( $job_id, $dest ) {
		return self::download( '/jobs/' . rawurlencode( $job_id ) . '/output', $dest, 900 );
	}

	/**
	 * Stream results of an image job to disk, a few at a time: one by one, an image with many sizes holds
	 * a PHP worker for a request per file.
	 *
	 * Each download names the file's position in the job (`index`), `optimized` or a format such as `webp`
	 * (`variant`), and the destination path (`dest`).
	 *
	 * @param string $job_id    Job ID.
	 * @param array  $downloads Files to fetch.
	 * @return array<array-key, true|\WP_Error> Results under the downloads' keys. Errors worth retrying have the
	 *                                          code jcore_pakkaus_download_retry.
	 */
	public static function download_image_outputs( $job_id, $downloads ) {
		$results = array();
		foreach ( array_chunk( $downloads, self::PARALLEL_DOWNLOADS, true ) as $batch ) {
			$requests = array();
			foreach ( $batch as $key => $download ) {
				$url              = self::url( '/jobs/' . rawurlencode( $job_id ) . '/files/' . (int) $download['index'] . '/' . rawurlencode( $download['variant'] ) );
				$requests[ $key ] = array(
					'url'     => $url,
					'headers' => array( 'Authorization' => 'Bearer ' . Settings::get( 'api_token' ) ),
					'options' => array_merge( self::requests_options( $url, 120 ), array( 'filename' => $download['dest'] ) ),
				);
			}

			foreach ( \WpOrg\Requests\Requests::request_multiple( $requests ) as $key => $response ) {
				if ( $response instanceof \WpOrg\Requests\Response && 200 === (int) $response->status_code ) {
					$results[ $key ] = true;
					continue;
				}
				wp_delete_file( $batch[ $key ]['dest'] );
				$results[ $key ] = $response instanceof \WpOrg\Requests\Response
					? self::download_error( (int) $response->status_code )
					: new \WP_Error( 'jcore_pakkaus_download_retry', $response instanceof \Throwable ? $response->getMessage() : __( 'The download failed.', 'jcore-pakkaus' ) );
			}
		}
		return $results;
	}

	/**
	 * Requests options matching what the WordPress HTTP API would use for a GET: its CA bundle, the
	 * `https_ssl_verify` filter, and the configured proxy.
	 *
	 * @param string $url     URL.
	 * @param int    $timeout Seconds.
	 * @return array
	 */
	private static function requests_options( $url, $timeout ) {
		/** This filter is documented in wp-includes/class-wp-http.php */
		$verify  = apply_filters( 'https_ssl_verify', true, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the core filter, applied as WP_Http does.
		$options = array(
			'timeout'          => $timeout,
			'connect_timeout'  => $timeout,
			'useragent'        => 'jcore-pakkaus/' . JCORE_PAKKAUS_VERSION . '; ' . home_url( '/' ),
			'redirects'        => 5,
			'follow_redirects' => true,
			'verify'           => $verify ? ABSPATH . WPINC . '/certificates/ca-bundle.crt' : false,
		);

		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			$options['proxy'] = new \WpOrg\Requests\Proxy\Http( $proxy->host() . ':' . $proxy->port() );
			if ( $proxy->use_authentication() ) {
				$options['proxy']->use_authentication = true;
				$options['proxy']->user               = $proxy->username();
				$options['proxy']->pass               = $proxy->password();
			}
		}

		return $options;
	}

	/**
	 * Stream a file from the service to disk.
	 *
	 * @param string $path    Service path.
	 * @param string $dest    Destination path.
	 * @param int    $timeout Seconds.
	 * @return true|\WP_Error Errors worth retrying have the code jcore_pakkaus_download_retry.
	 */
	private static function download( $path, $dest, $timeout ) {
		$response = wp_remote_get(
			self::url( $path ),
			array(
				'timeout'  => $timeout,
				'stream'   => true,
				'filename' => $dest,
				'headers'  => array( 'Authorization' => 'Bearer ' . Settings::get( 'api_token' ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $dest );
			return new \WP_Error( 'jcore_pakkaus_download_retry', $response->get_error_message(), $response->get_error_data() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $dest );
			return self::download_error( $code );
		}

		return true;
	}

	/**
	 * Error for a download the service answered with something other than 200.
	 *
	 * @param int $code HTTP status code.
	 * @return \WP_Error Errors worth retrying have the code jcore_pakkaus_download_retry.
	 */
	private static function download_error( $code ) {
		return new \WP_Error(
			$code >= 500 || in_array( $code, array( 408, 409, 425, 429 ), true ) ? 'jcore_pakkaus_download_retry' : 'jcore_pakkaus_download_failed',
			/* translators: %d: HTTP status code */
			sprintf( __( 'Downloading the optimized file failed with HTTP %d.', 'jcore-pakkaus' ), $code )
		);
	}
}
