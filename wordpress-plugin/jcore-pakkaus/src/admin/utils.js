import { __, sprintf } from '@wordpress/i18n';

const LOCALE = document.documentElement.lang || undefined;
const UNITS = [ 'B', 'KB', 'MB', 'GB', 'TB' ];

/**
 * Joins the truthy arguments into a class string.
 *
 * @param {...(string|false|null|undefined)} names Class names.
 * @return {string} Space-separated class names.
 */
export function cx( ...names ) {
	return names.filter( Boolean ).join( ' ' );
}

/**
 * Formats a count with the locale's digit grouping.
 *
 * @param {number} value Count.
 * @return {string} Formatted count.
 */
export function formatNumber( value ) {
	return new Intl.NumberFormat( LOCALE ).format( value ?? 0 );
}

/**
 * Formats a fraction (0.42) as a whole percentage (42 %).
 *
 * @param {number} fraction Value between 0 and 1.
 * @return {string} Formatted percentage.
 */
export function formatPercent( fraction ) {
	return new Intl.NumberFormat( LOCALE, {
		style: 'percent',
		maximumFractionDigits: 0,
	} ).format( fraction || 0 );
}

/**
 * Formats a byte count with binary units, the way WordPress' size_format() does.
 *
 * @param {number} bytes Size in bytes.
 * @return {string} Formatted size.
 */
export function formatBytes( bytes ) {
	if ( ! bytes || bytes < 0 ) {
		return `0 ${ UNITS[ 0 ] }`;
	}
	const exponent = Math.min(
		Math.floor( Math.log( bytes ) / Math.log( 1024 ) ),
		UNITS.length - 1
	);
	const value = bytes / 1024 ** exponent;
	const digits = exponent > 0 && value < 100 ? 1 : 0;

	return `${ new Intl.NumberFormat( LOCALE, {
		maximumFractionDigits: digits,
	} ).format( value ) } ${ UNITS[ exponent ] }`;
}

const CODEC_NAMES = {
	h264: 'H.264',
	hevc: 'HEVC',
	h265: 'HEVC',
	vp8: 'VP8',
	vp9: 'VP9',
	av1: 'AV1',
	prores: 'ProRes',
	mpeg4: 'MPEG-4',
	mpeg2video: 'MPEG-2',
};

/**
 * Describes a probed video stream, e.g. "HEVC 4K HDR".
 *
 * @param {?Object} stream Stream as Library::stream() returns it.
 * @return {string} Short description, or '' for no stream.
 */
export function describeStream( stream ) {
	if ( ! stream ) {
		return '';
	}
	const shortSide = Math.min( stream.width, stream.height || stream.width );
	const resolution = shortSide >= 2160 ? '4K' : `${ shortSide }p`;
	const codec =
		CODEC_NAMES[ stream.codec ] ?? ( stream.codec || '' ).toUpperCase();

	return [ codec, resolution, stream.hdr ? 'HDR' : '' ]
		.filter( Boolean )
		.join( ' ' );
}

/**
 * Plain-language reading of a CRF value. H.265 reaches the same quality at
 * about five steps higher than H.264.
 *
 * @param {number} crf   Constant rate factor.
 * @param {string} codec `h264` or `h265`.
 * @return {string} Description.
 */
export function describeCrf( crf, codec ) {
	const value = crf - ( codec === 'h265' ? 5 : 0 );
	if ( value <= 18 ) {
		return __( 'Visually lossless, large files', 'jcore-pakkaus' );
	}
	if ( value <= 23 ) {
		return __( 'High quality', 'jcore-pakkaus' );
	}
	if ( value <= 28 ) {
		return __( 'Balanced', 'jcore-pakkaus' );
	}
	if ( value <= 33 ) {
		return __( 'Small files, visible artefacts', 'jcore-pakkaus' );
	}
	return __( 'Low quality', 'jcore-pakkaus' );
}

/**
 * Select options with the current value added when it is not one of them,
 * so a value set elsewhere (WP-CLI, an older version) is shown as is.
 *
 * @param {number[]}                 values  Preset values.
 * @param {number}                   current Current value.
 * @param {function(number): string} label   Formats a value.
 * @return {Array<{value: string, label: string}>} Options.
 */
export function numberOptions( values, current, label ) {
	const all = values.includes( Number( current ) )
		? values
		: [ ...values, Number( current ) ].sort( ( a, b ) => b - a );

	return all.map( ( value ) => ( {
		value: String( value ),
		label: label( value ),
	} ) );
}

/**
 * Message of a failed apiFetch() call.
 *
 * @param {*} error Rejection value.
 * @return {string} Message.
 */
export function errorMessage( error ) {
	return (
		error?.message ||
		sprintf(
			/* translators: %s: error code */
			__( 'Request failed (%s).', 'jcore-pakkaus' ),
			error?.code ?? 'unknown'
		)
	);
}
