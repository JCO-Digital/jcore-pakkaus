<?php
/**
 * Other image optimization plugins.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects active plugins that optimize, convert or rewrite images too. Two optimizers working on the same
 * files recompress each other's output, and several of these write WebP versions under the same names
 * (photo.jpg.webp), so while one is active this plugin leaves images entirely alone: nothing is sent,
 * installed, restored or deleted until it is deactivated. Videos are not affected.
 */
final class Conflicts {

	/**
	 * Image optimization plugins, by plugin directory (the WordPress.org slug).
	 */
	public const IMAGE_PLUGINS = array(
		'ewww-image-optimizer'        => 'EWWW Image Optimizer',
		'ewww-image-optimizer-cloud'  => 'EWWW Image Optimizer Cloud',
		'shortpixel-image-optimiser'  => 'ShortPixel Image Optimizer',
		'shortpixel-adaptive-images'  => 'ShortPixel Adaptive Images',
		'wp-smushit'                  => 'Smush',
		'wp-smush-pro'                => 'Smush Pro',
		'imagify'                     => 'Imagify',
		'optimole-wp'                 => 'Optimole',
		'webp-express'                => 'WebP Express',
		'webp-converter-for-media'    => 'Converter for Media',
		'tiny-compress-images'        => 'TinyPNG',
		'resmushit-image-optimizer'   => 'reSmush.it',
		'robin-image-optimizer'       => 'Robin Image Optimizer',
		'webp-uploads'                => 'Modern Image Formats',
		'image-optimization'          => 'Image Optimizer by Elementor',
		'kraken-image-optimizer'      => 'Kraken.io Image Optimizer',
		'wp-compress-image-optimizer' => 'WP Compress',
		'optimus'                     => 'Optimus',
	);

	/**
	 * Active image optimization plugins.
	 *
	 * @return array<string, string> Plugin directory => name.
	 */
	public static function active(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		/**
		 * Filters the plugins that pause image optimization while active.
		 *
		 * @param array<string, string> $plugins Plugin directory => name.
		 */
		$plugins = (array) apply_filters( 'jcore_pakkaus_conflicting_plugins', self::IMAGE_PLUGINS );

		$found = array();
		foreach ( $active as $file ) {
			$slug = dirname( (string) $file );
			if ( isset( $plugins[ $slug ] ) ) {
				$found[ $slug ] = (string) $plugins[ $slug ];
			}
		}

		return $found;
	}

	/**
	 * Whether image optimization is paused.
	 *
	 * @return bool
	 */
	public static function blocks_images(): bool {
		return (bool) self::active();
	}

	/**
	 * Why image optimization is paused, or '' when it isn't.
	 *
	 * @return string
	 */
	public static function message(): string {
		$names = array_values( self::active() );
		if ( ! $names ) {
			return '';
		}

		return sprintf(
			/* translators: %s: plugin names, e.g. "EWWW Image Optimizer and Smush" */
			_n(
				'Image optimization is paused because %s is active. Two image optimizers on one site work against each other, so JCORE Pakkaus leaves images alone until it is deactivated. Videos are still optimized.',
				'Image optimization is paused because %s are active. Two image optimizers on one site work against each other, so JCORE Pakkaus leaves images alone until they are deactivated. Videos are still optimized.',
				count( $names ),
				'jcore-pakkaus'
			),
			wp_sprintf( '%l', $names )
		);
	}

	/**
	 * The error image actions return while paused.
	 *
	 * @return \WP_Error
	 */
	public static function error(): \WP_Error {
		return new \WP_Error( 'jcore_pakkaus_conflict', self::message(), array( 'status' => 409 ) );
	}
}
