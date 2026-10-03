<?php
/**
 * Plugin bootstrap.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

use Jcore\Update\Config\UpdateConfig;
use Jcore\Update\Hooks\PluginUpdateHooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every component to its hooks.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers every hook. Called once on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function boot(): void {
		// Registers the cron schedule the migration may need to resume polling.
		Processor::init();
		Migration::maybe_run();

		$this->register_updater();

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( is_admin() ) {
			Admin\Menu::register();
			Admin\Media::register();
			add_filter( 'plugin_action_links_' . plugin_basename( JCORE_PAKKAUS_FILE ), array( $this, 'action_links' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pakkaus', Cli::class );
		}
	}

	/**
	 * Activation: takes over from the stand-alone Video Optimizer plugin and
	 * resumes polling if jobs were left in flight.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// `plugins_loaded` has already run on the activation request.
		Processor::init();
		Migration::deactivate_legacy_plugin();
		Migration::maybe_run();
		Processor::activate();
	}

	/**
	 * Deactivation: stops the cron events.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Processor::deactivate();
	}

	/**
	 * Hooks the plugin into the J&Co Digital update service.
	 *
	 * The library is vendored into the release; a source checkout without a
	 * `composer install` simply runs without update checks.
	 *
	 * @return void
	 */
	private function register_updater(): void {
		if ( ! class_exists( UpdateConfig::class ) ) {
			return;
		}

		$config = new UpdateConfig(
			pluginFile: JCORE_PAKKAUS_FILE,
			slug: 'jcore-pakkaus',
			version: JCORE_PAKKAUS_VERSION,
			apiBaseUrl: 'https://update.jcore.fi/v1',
		);

		( new PluginUpdateHooks( $config ) )->register();
	}

	/**
	 * Registers the REST routes: the service callback and the admin app's API.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new Rest\Callback_Controller() )->register_routes();
		( new Rest\Settings_Controller() )->register_routes();
		( new Rest\Service_Controller() )->register_routes();
		( new Rest\Videos_Controller() )->register_routes();
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 *
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( Admin\Menu::url( 'settings' ) ),
				esc_html__( 'Settings', 'jcore-pakkaus' )
			)
		);

		return $links;
	}
}
