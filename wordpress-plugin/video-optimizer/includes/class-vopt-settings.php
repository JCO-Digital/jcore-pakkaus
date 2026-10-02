<?php
/**
 * Plugin settings.
 *
 * @package Video_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and sanitizes the plugin options. The service URL and API token can also be
 * defined in wp-config.php as VIDEO_OPTIMIZER_SERVICE_URL / VIDEO_OPTIMIZER_API_TOKEN.
 */
class Vopt_Settings {

	const OPTION = 'vopt_settings';

	const CODECS = array( 'h264', 'h265' );

	const PRESETS = array( 'ultrafast', 'superfast', 'veryfast', 'faster', 'fast', 'medium', 'slow', 'slower', 'veryslow' );

	const RESOLUTIONS = array( 0, 2160, 1440, 1080, 720, 480 );

	/**
	 * Default option values.
	 *
	 * @return array
	 */
	public static function defaults() {
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
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$all    = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );

		if ( defined( 'VIDEO_OPTIMIZER_SERVICE_URL' ) ) {
			$all['service_url'] = VIDEO_OPTIMIZER_SERVICE_URL;
		}
		if ( defined( 'VIDEO_OPTIMIZER_API_TOKEN' ) ) {
			$all['api_token'] = VIDEO_OPTIMIZER_API_TOKEN;
		}

		return $all;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a setting is locked by a wp-config.php constant.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_constant( $key ) {
		$map = array(
			'service_url' => 'VIDEO_OPTIMIZER_SERVICE_URL',
			'api_token'   => 'VIDEO_OPTIMIZER_API_TOKEN',
		);
		return isset( $map[ $key ] ) && defined( $map[ $key ] );
	}

	/**
	 * Whether the service connection is configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== self::get( 'service_url' ) && '' !== self::get( 'api_token' );
	}

	/**
	 * Transcoding options in the format the optimizer service expects.
	 *
	 * @return array
	 */
	public static function job_options() {
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
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Raw form input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::all();
		$defaults = self::defaults();
		$clean    = array();

		$clean['service_url'] = isset( $input['service_url'] ) ? untrailingslashit( esc_url_raw( trim( $input['service_url'] ), array( 'http', 'https' ) ) ) : '';

		// An empty token field keeps the saved token so it never has to be printed back into the form.
		$token              = isset( $input['api_token'] ) ? trim( sanitize_text_field( $input['api_token'] ) ) : '';
		$stored             = get_option( self::OPTION, array() );
		$clean['api_token'] = '' !== $token ? $token : ( isset( $stored['api_token'] ) ? $stored['api_token'] : '' );

		foreach ( array( 'auto_optimize', 'remove_audio', 'tonemap_hdr', 'strip_metadata', 'keep_original' ) as $flag ) {
			$clean[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$clean['codec']  = isset( $input['codec'] ) && in_array( $input['codec'], self::CODECS, true ) ? $input['codec'] : $defaults['codec'];
		$clean['preset'] = isset( $input['preset'] ) && in_array( $input['preset'], self::PRESETS, true ) ? $input['preset'] : $defaults['preset'];

		$resolution              = isset( $input['max_resolution'] ) ? (int) $input['max_resolution'] : $defaults['max_resolution'];
		$clean['max_resolution'] = in_array( $resolution, self::RESOLUTIONS, true ) ? $resolution : $defaults['max_resolution'];

		$clean['crf']                 = self::clamp( $input, 'crf', 0, 51, $current['crf'] );
		$clean['max_fps']             = self::clamp( $input, 'max_fps', 0, 240, $current['max_fps'] );
		$clean['audio_bitrate']       = self::clamp( $input, 'audio_bitrate', 32, 512, $current['audio_bitrate'] );
		$clean['min_savings_percent'] = self::clamp( $input, 'min_savings_percent', 0, 100, $current['min_savings_percent'] );
		$clean['min_file_size_mb']    = self::clamp( $input, 'min_file_size_mb', 0, 100000, $current['min_file_size_mb'] );

		return $clean;
	}

	/**
	 * Clamp a numeric input value.
	 *
	 * @param array  $input    Form input.
	 * @param string $key      Field.
	 * @param float  $min      Minimum.
	 * @param float  $max      Maximum.
	 * @param mixed  $fallback Value used when the field is missing or not numeric.
	 * @return float|int
	 */
	private static function clamp( $input, $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || ! is_numeric( $input[ $key ] ) ) {
			return $fallback;
		}
		$value = max( $min, min( $max, (float) $input[ $key ] ) );
		return floor( $value ) === $value ? (int) $value : $value;
	}
}
