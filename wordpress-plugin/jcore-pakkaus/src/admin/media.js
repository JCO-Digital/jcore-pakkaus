import { __, _n, sprintf } from '@wordpress/i18n';
import { image, video } from '@wordpress/icons';
import { formatNumber } from './utils';

/**
 * What differs between the Videos and Images tabs: the route, the icon and
 * the wording. Each string is written out in full so it can be translated.
 */
export const MEDIA = {
	videos: {
		type: 'videos',
		icon: video,
		title: __( 'Videos', 'jcore-pakkaus' ),
		column: __( 'Video', 'jcore-pakkaus' ),
		search: __( 'Search videos', 'jcore-pakkaus' ),
		filters: __( 'Filter videos by status', 'jcore-pakkaus' ),
		noMatch: __( 'No videos match.', 'jcore-pakkaus' ),
		emptyTitle: __( 'No videos yet', 'jcore-pakkaus' ),
		emptyText: __(
			'Videos you upload to the media library show up here with their optimization status.',
			'jcore-pakkaus'
		),
		upload: __( 'Upload a video', 'jcore-pakkaus' ),
		bulkText: __(
			'Probably uploaded before the plugin was set up. They are sent to the service in small batches, so it is never flooded.',
			'jcore-pakkaus'
		),
		restoreConfirm: __(
			'Replace the optimized video with the original file?',
			'jcore-pakkaus'
		),
		count: ( count ) =>
			sprintf(
				/* translators: %s: number of videos */
				_n( '%s video', '%s videos', count, 'jcore-pakkaus' ),
				formatNumber( count )
			),
		neverOptimized: ( count ) =>
			sprintf(
				/* translators: %s: number of videos */
				_n(
					'%s video has never been optimized',
					'%s videos have never been optimized',
					count,
					'jcore-pakkaus'
				),
				formatNumber( count )
			),
		queued: ( count ) =>
			sprintf(
				/* translators: %s: number of videos */
				_n(
					'%s video queued for optimization.',
					'%s videos queued for optimization.',
					count,
					'jcore-pakkaus'
				),
				formatNumber( count )
			),
	},
	images: {
		type: 'images',
		icon: image,
		title: __( 'Images', 'jcore-pakkaus' ),
		column: __( 'Image', 'jcore-pakkaus' ),
		search: __( 'Search images', 'jcore-pakkaus' ),
		filters: __( 'Filter images by status', 'jcore-pakkaus' ),
		noMatch: __( 'No images match.', 'jcore-pakkaus' ),
		emptyTitle: __( 'No JPEG or PNG images yet', 'jcore-pakkaus' ),
		emptyText: __(
			'JPEG and PNG images you upload to the media library show up here with their optimization status.',
			'jcore-pakkaus'
		),
		upload: __( 'Upload an image', 'jcore-pakkaus' ),
		bulkText: __(
			'Probably uploaded before the plugin was set up. They are sent to the service in batches every minute.',
			'jcore-pakkaus'
		),
		restoreConfirm: __(
			'Delete the WebP versions and put back any kept original PNG files?',
			'jcore-pakkaus'
		),
		count: ( count ) =>
			sprintf(
				/* translators: %s: number of images */
				_n( '%s image', '%s images', count, 'jcore-pakkaus' ),
				formatNumber( count )
			),
		neverOptimized: ( count ) =>
			sprintf(
				/* translators: %s: number of images */
				_n(
					'%s image has never been optimized',
					'%s images have never been optimized',
					count,
					'jcore-pakkaus'
				),
				formatNumber( count )
			),
		queued: ( count ) =>
			sprintf(
				/* translators: %s: number of images */
				_n(
					'%s image queued for optimization.',
					'%s images queued for optimization.',
					count,
					'jcore-pakkaus'
				),
				formatNumber( count )
			),
	},
};
