<?php
/**
 * Plugin Name:       Video Optimizer
 * Description:       Automatically optimizes uploaded videos (H.264/H.265 MP4, downscaling, HDR to SDR) using a self-hosted FFmpeg optimizer service.
 * Version:           1.0.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       video-optimizer
 */

defined( 'ABSPATH' ) || exit;

define( 'VOPT_VERSION', '1.0.3' );
define( 'VOPT_FILE', __FILE__ );
define( 'VOPT_DIR', plugin_dir_path( __FILE__ ) );

require_once VOPT_DIR . 'includes/class-vopt-settings.php';
require_once VOPT_DIR . 'includes/class-vopt-client.php';
require_once VOPT_DIR . 'includes/class-vopt-processor.php';
require_once VOPT_DIR . 'includes/class-vopt-rest.php';
require_once VOPT_DIR . 'includes/class-vopt-admin.php';

register_activation_hook( __FILE__, array( 'Vopt_Processor', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Vopt_Processor', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Vopt_Processor::init();
		Vopt_Rest::init();

		if ( is_admin() ) {
			Vopt_Admin::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once VOPT_DIR . 'includes/class-vopt-cli.php';
			WP_CLI::add_command( 'video-optimizer', 'Vopt_CLI' );
		}
	}
);
