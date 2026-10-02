<?php
/**
 * Remove plugin data. Optimized files and backups are left untouched.
 *
 * @package Video_Optimizer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'vopt_settings' );

foreach ( array( '_vopt_status', '_vopt_job_id', '_vopt_secret', '_vopt_progress', '_vopt_message', '_vopt_attempts', '_vopt_updated' ) as $vopt_key ) {
	delete_metadata( 'post', 0, $vopt_key, '', true );
}

wp_clear_scheduled_hook( 'vopt_poll' );
wp_clear_scheduled_hook( 'vopt_finalize' );
