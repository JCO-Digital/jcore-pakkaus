<?php
/**
 * One-off upgrades of stored data.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings stored data up to date, starting with the import from the
 * stand-alone Video Optimizer plugin this one replaces.
 */
final class Migration {

	/**
	 * Option holding the schema version the stored data is at.
	 */
	public const VERSION_OPTION = 'jcore_pakkaus_db_version';

	/**
	 * Current schema version.
	 */
	private const VERSION = 1;

	/**
	 * Basename of the stand-alone plugin.
	 */
	public const LEGACY_PLUGIN = 'video-optimizer/video-optimizer.php';

	/**
	 * Settings option of the stand-alone plugin.
	 */
	private const LEGACY_OPTION = 'vopt_settings';

	/**
	 * Post meta prefix of the stand-alone plugin.
	 */
	private const LEGACY_META_PREFIX = '_vopt_';

	/**
	 * Post meta keys of the stand-alone plugin, without the prefix.
	 */
	private const LEGACY_META = array( 'status', 'job_id', 'secret', 'progress', 'message', 'attempts', 'stats', 'backup', 'updated' );

	/**
	 * Cron hooks of the stand-alone plugin.
	 */
	private const LEGACY_CRON = array( 'vopt_poll', 'vopt_finalize' );

	/**
	 * Runs the pending migrations. Cheap once they have run: one autoloaded option read.
	 *
	 * @return void
	 */
	public static function maybe_run(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
			return;
		}

		self::import_legacy();

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Deactivates the stand-alone plugin, so uploads are not optimized twice.
	 *
	 * @return void
	 */
	public static function deactivate_legacy_plugin(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_plugin_active( self::LEGACY_PLUGIN ) ) {
			deactivate_plugins( self::LEGACY_PLUGIN );
		}
	}

	/**
	 * Moves the stand-alone plugin's settings and per-video state to this plugin's keys.
	 *
	 * Moving rather than copying matters: deleting the old plugin runs its
	 * uninstall.php, which would otherwise take the state with it.
	 *
	 * @return void
	 */
	private static function import_legacy(): void {
		global $wpdb;

		$legacy = get_option( self::LEGACY_OPTION );
		if ( is_array( $legacy ) && false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, $legacy );
		}
		delete_option( self::LEGACY_OPTION );

		$moved = 0;
		foreach ( self::LEGACY_META as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$moved += (int) $wpdb->update( $wpdb->postmeta, array( 'meta_key' => Processor::META_PREFIX . $key ), array( 'meta_key' => self::LEGACY_META_PREFIX . $key ) );
		}

		if ( $moved ) {
			if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
				wp_cache_flush_group( 'post_meta' );
			} else {
				wp_cache_flush();
			}
		}

		foreach ( self::LEGACY_CRON as $hook ) {
			wp_unschedule_hook( $hook );
		}

		if ( Processor::active_attachment_ids( 1 ) ) {
			Processor::ensure_polling();
		}
	}
}
