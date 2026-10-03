import { __ } from '@wordpress/i18n';

// Data the admin page passes in through wp_add_inline_script().
export const CONFIG = window.jcorePakkaus ?? {};

// Every stored status, with its badge label and colour.
export const STATUSES = {
	none: { label: __( 'Not optimized', 'jcore-pakkaus' ), tone: 'neutral' },
	restored: {
		label: __( 'Original restored', 'jcore-pakkaus' ),
		tone: 'neutral',
	},
	pending: { label: __( 'Waiting', 'jcore-pakkaus' ), tone: 'info' },
	queued: { label: __( 'Queued', 'jcore-pakkaus' ), tone: 'info' },
	processing: { label: __( 'Optimizing', 'jcore-pakkaus' ), tone: 'info' },
	optimized: { label: __( 'Optimized', 'jcore-pakkaus' ), tone: 'success' },
	skipped: { label: __( 'Kept original', 'jcore-pakkaus' ), tone: 'warning' },
	failed: { label: __( 'Failed', 'jcore-pakkaus' ), tone: 'error' },
};

// Filters of the video list, matching Library::FILTERS.
export const FILTERS = [
	{ name: 'all', label: __( 'All', 'jcore-pakkaus' ) },
	{ name: 'unoptimized', label: __( 'Not optimized', 'jcore-pakkaus' ) },
	{ name: 'active', label: __( 'In progress', 'jcore-pakkaus' ) },
	{ name: 'optimized', label: __( 'Optimized', 'jcore-pakkaus' ) },
	{ name: 'skipped', label: __( 'Kept original', 'jcore-pakkaus' ) },
	{ name: 'failed', label: __( 'Failed', 'jcore-pakkaus' ) },
];

export const CODECS = [
	{
		value: 'h264',
		title: 'H.264',
		description: __(
			'Plays in every browser and on every device. The safe choice.',
			'jcore-pakkaus'
		),
		recommendedCrf: 23,
	},
	{
		value: 'h265',
		title: 'H.265 / HEVC',
		description: __(
			'Noticeably smaller files at the same quality, but not every browser can play it.',
			'jcore-pakkaus'
		),
		recommendedCrf: 28,
	},
];

export const PRESETS = [
	{
		value: 'ultrafast',
		label: __( 'Ultrafast (largest files)', 'jcore-pakkaus' ),
	},
	{ value: 'superfast', label: __( 'Superfast', 'jcore-pakkaus' ) },
	{ value: 'veryfast', label: __( 'Very fast', 'jcore-pakkaus' ) },
	{ value: 'faster', label: __( 'Faster', 'jcore-pakkaus' ) },
	{ value: 'fast', label: __( 'Fast', 'jcore-pakkaus' ) },
	{ value: 'medium', label: __( 'Medium (recommended)', 'jcore-pakkaus' ) },
	{ value: 'slow', label: __( 'Slow', 'jcore-pakkaus' ) },
	{ value: 'slower', label: __( 'Slower', 'jcore-pakkaus' ) },
	{
		value: 'veryslow',
		label: __( 'Very slow (smallest files)', 'jcore-pakkaus' ),
	},
];

export const RESOLUTIONS = [
	{ value: 0, label: __( 'Keep original', 'jcore-pakkaus' ) },
	{ value: 2160, label: __( '2160p (4K)', 'jcore-pakkaus' ) },
	{ value: 1440, label: __( '1440p (QHD)', 'jcore-pakkaus' ) },
	{ value: 1080, label: __( '1080p (Full HD)', 'jcore-pakkaus' ) },
	{ value: 720, label: __( '720p (HD)', 'jcore-pakkaus' ) },
	{ value: 480, label: __( '480p (SD)', 'jcore-pakkaus' ) },
];

export const FRAME_RATES = [ 0, 60, 50, 30, 25, 24 ];

export const AUDIO_BITRATES = [ 64, 96, 128, 160, 192, 256, 320 ];
