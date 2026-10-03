<?php
/**
 * Dev stack only: the site lives at http://localhost:8080 for the browser, but
 * that address means nothing inside the containers. Point the optimizer service
 * at the in-network hostname, and WP-Cron's loopback at Apache's real port.
 */

const DEV_PUBLIC_URL   = 'http://localhost:8080';
const DEV_INTERNAL_URL = 'http://wordpress';

add_filter(
	'jcore_pakkaus_job_payload',
	static function ( array $payload ): array {
		foreach ( array( 'source_url', 'callback_url' ) as $key ) {
			$payload[ $key ] = str_replace( DEV_PUBLIC_URL, DEV_INTERNAL_URL, $payload[ $key ] );
		}
		return $payload;
	}
);

add_filter(
	'cron_request',
	static function ( array $request ): array {
		$request['url'] = str_replace( DEV_PUBLIC_URL, 'http://localhost', $request['url'] );
		return $request;
	}
);
