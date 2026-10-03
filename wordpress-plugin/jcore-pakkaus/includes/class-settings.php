<?php
/**
 * Plugin settings.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitizes the plugin options.
 *
 * The service URL and API token can also be defined in wp-config.php as
 * JCORE_PAKKAUS_SERVICE_URL / JCORE_PAKKAUS_API_TOKEN. The stand-alone
 * plugin's VIDEO_OPTIMIZER_SERVICE_URL / VIDEO_OPTIMIZER_API_TOKEN still work.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'jcore_pakkaus_settings';

	/**
	 * Accepted video codecs.
	 */
	public const CODECS = array( 'h264', 'h265' );

	/**
	 * Accepted x264/x265 presets, fastest first.
	 */
	public const PRESETS = array( 'ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow' );

	/**
	 * Accepted maximum resolutions (short side); 0 keeps the original.
	 */
	public const RESOLUTIONS = array( 0, 2160, 1440, 1080, 720, 480 );

	/**
	 * Constants that can lock a setting, most preferred first.
	 */
	private const CONSTANTS = array(
		'service_url' => array( 'JCORE_PAKKAUS_SERVICE_URL', 'VIDEO_OPTIMIZER_SERVICE_URL' ),
		'api_token'   => array( 'JCORE_PAKKAUS_API_TOKEN', 'VIDEO_OPTIMIZER_API_TOKEN' ),
	);

	/**
	 * Default option values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'service_url'         => '',
			'api_token'           => '',
			'auto_optimize'       => 1,
			'codec'               => 'h264',
			'crf'                 => 23,
			'preset'              => 'medium',
			'max_resolution'      => 1080,
			'max_fps'             => 0,
			'audio_bitrate'       => 128,
			'remove_audio'        => 0,
			'tonemap_hdr'         => 1,
			'strip_metadata'      => 1,
			'min_savings_percent' => 5,
			'min_file_size_mb'    => 1,
			'keep_original'       => 0,
		);
	}

	/**
	 * All settings merged with defaults and wp-config.php constants.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$all    = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );

		foreach ( array_keys( self::CONSTANTS ) as $key ) {
			$constant = self::locked_by( $key );
			if ( '' !== $constant ) {
				$all[ $key ] = (string) constant( $constant );
			}
		}

		return $all;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key.
	 *
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();

		return $all[ $key ] ?? null;
	}

	/**
	 * Name of the wp-config.php constant that locks a setting, or '' when none does.
	 *
	 * @param string $key Setting key.
	 *
	 * @return string
	 */
	public static function locked_by( string $key ): string {
		foreach ( self::CONSTANTS[ $key ] ?? array() as $constant ) {
			if ( defined( $constant ) ) {
				return $constant;
			}
		}

		return '';
	}

	/**
	 * Whether the service connection is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::get( 'service_url' ) && '' !== self::get( 'api_token' );
	}

	/**
	 * Transcoding options in the format the optimizer service expects.
	 *
	 * @return array<string, mixed>
	 */
	public static function job_options(): array {
		$s = self::all();

		return array(
			'codec'               => $s['codec'],
			'crf'                 => (int) $s['crf'],
			'preset'              => $s['preset'],
			'max_resolution'      => (int) $s['max_resolution'],
			'max_fps'             => (float) $s['max_fps'],
			'audio_bitrate'       => (int) $s['audio_bitrate'],
			'remove_audio'        => (bool) $s['remove_audio'],
			'tonemap_hdr'         => (bool) $s['tonemap_hdr'],
			'strip_metadata'      => (bool) $s['strip_metadata'],
			'min_savings_percent' => (float) $s['min_savings_percent'],
		);
	}

	/**
	 * Settings as the admin app sees them: the token is never sent back, only
	 * whether one is saved, plus which settings wp-config.php locks.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_client(): array {
		$settings = self::all();

		$settings['api_token_set'] = '' !== $settings['api_token'];
		$settings['api_token']     = '';
		$settings['locked']        = array_filter(
			array(
				'service_url' => self::locked_by( 'service_url' ),
				'api_token'   => self::locked_by( 'api_token' ),
			)
		);

		return $settings;
	}

	/**
	 * Merges the supplied keys into the stored settings and saves them.
	 *
	 * @param array<string, mixed> $input Keys to change. Unknown keys are ignored.
	 *
	 * @return array<string, mixed> The saved settings.
	 */
	public static function update( array $input ): array {
		$stored = get_option( self::OPTION, array() );
		$base   = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		$clean  = self::sanitize( array_merge( $base, array_intersect_key( $input, self::defaults() ) ) );

		update_option( self::OPTION, $clean );

		return $clean;
	}

	/**
	 * Sanitizes a complete settings array.
	 *
	 * Missing flags count as off. An empty token keeps the saved one, so the
	 * token never has to be sent back to the browser.
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::all();
		$defaults = self::defaults();
		$clean    = array();

		$clean['service_url'] = isset( $input['service_url'] ) ? untrailingslashit( esc_url_raw( trim( (string) $input['service_url'] ), array( 'http', 'https' ) ) ) : '';

		$token              = isset( $input['api_token'] ) ? trim( sanitize_text_field( (string) $input['api_token'] ) ) : '';
		$stored             = get_option( self::OPTION, array() );
		$clean['api_token'] = '' !== $token ? $token : ( $stored['api_token'] ?? '' );

		foreach ( array( 'auto_optimize', 'remove_audio', 'tonemap_hdr', 'strip_metadata', 'keep_original' ) as $flag ) {
			$clean[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$clean['codec']  = isset( $input['codec'] ) && in_array( $input['codec'], self::CODECS, true ) ? $input['codec'] : $defaults['codec'];
		$clean['preset'] = isset( $input['preset'] ) && in_array( $input['preset'], self::PRESETS, true ) ? $input['preset'] : $defaults['preset'];

		$resolution              = isset( $input['max_resolution'] ) ? (int) $input['max_resolution'] : $defaults['max_resolution'];
		$clean['max_resolution'] = in_array( $resolution, self::RESOLUTIONS, true ) ? $resolution : $defaults['max_resolution'];

		$min_crf                      = 'h264' === $clean['codec'] ? 1 : 0;
		$clean['crf']                 = self::clamp( $input, 'crf', $min_crf, 51, max( $min_crf, $current['crf'] ) );
		$clean['max_fps']             = self::clamp( $input, 'max_fps', 0, 240, $current['max_fps'] );
		$clean['audio_bitrate']       = self::clamp( $input, 'audio_bitrate', 32, 512, $current['audio_bitrate'] );
		$clean['min_savings_percent'] = self::clamp( $input, 'min_savings_percent', 0, 100, $current['min_savings_percent'] );
		$clean['min_file_size_mb']    = self::clamp( $input, 'min_file_size_mb', 0, 100000, $current['min_file_size_mb'] );

		return $clean;
	}

	/**
	 * Clamps a numeric input value.
	 *
	 * @param array<string, mixed> $input    Input.
	 * @param string               $key      Field.
	 * @param float                $min      Minimum.
	 * @param float                $max      Maximum.
	 * @param mixed                $fallback Value used when the field is missing or not numeric.
	 *
	 * @return float|int
	 */
	private static function clamp( array $input, string $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			return $fallback;
		}
		$value = max( $min, min( $max, (float) $input[ $key ] ) );

		return floor( $value ) === $value ? (int) $value : $value;
	}
}
