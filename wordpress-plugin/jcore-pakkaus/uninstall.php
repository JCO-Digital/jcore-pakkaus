<?php
/**
 * Removes the plugin's settings and job state when it is deleted.
 *
 * Optimized files, backups of originals and the size statistics stay, so
 * reinstalling the plugin can still restore an original.
 *
 * @package Jcore\Pakkaus
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Deletes the plugin's options, job meta and cron events on the current site.
 *
 * Names are spelled out rather than read from the classes so this file stands
 * alone, as uninstall.php runs without the plugin loaded.
 *
 * @return void
 */
function jcore_pakkaus_uninstall_site(): void {
	delete_option( 'jcore_pakkaus_settings' );
	delete_option( 'jcore_pakkaus_db_version' );

	foreach ( array( 'status', 'job_id', 'secret', 'progress', 'message', 'attempts', 'updated' ) as $jcore_pakkaus_key ) {
		delete_metadata( 'post', 0, '_jcore_pakkaus_' . $jcore_pakkaus_key, '', true );
	}

	wp_clear_scheduled_hook( 'jcore_pakkaus_poll' );
	wp_unschedule_hook( 'jcore_pakkaus_finalize' );
}

if ( is_multisite() ) {
	$jcore_pakkaus_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $jcore_pakkaus_site_ids as $jcore_pakkaus_site_id ) {
		switch_to_blog( (int) $jcore_pakkaus_site_id );
		jcore_pakkaus_uninstall_site();
		restore_current_blog();
	}
} else {
	jcore_pakkaus_uninstall_site();
}
