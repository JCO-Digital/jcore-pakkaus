import { Button, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { backup, Icon, update, video as videoIcon } from '@wordpress/icons';
import StatusBadge from './StatusBadge';
import {
	cx,
	describeStream,
	formatBytes,
	formatPercent,
	formatNumber,
} from '../utils';

const RUNNING = [ 'queued', 'processing' ];

function Thumbnail( { video } ) {
	return (
		<span className="jcore-pakkaus__thumb">
			{ video.thumbnail ? (
				<img src={ video.thumbnail } alt="" loading="lazy" />
			) : (
				<Icon icon={ videoIcon } size={ 28 } />
			) }
			{ video.duration && (
				<span className="jcore-pakkaus__thumb-duration">
					{ video.duration }
				</span>
			) }
		</span>
	);
}

function SizeInfo( { video } ) {
	if ( video.status !== 'optimized' || ! video.original_size ) {
		return (
			<span className="jcore-pakkaus__filesize">
				{ video.filesize ? formatBytes( video.filesize ) : '–' }
			</span>
		);
	}

	const before = describeStream( video.before );
	const after = describeStream( video.after );

	return (
		<>
			<div className="jcore-pakkaus__sizes">
				<span className="jcore-pakkaus__size-before">
					{ formatBytes( video.original_size ) }
				</span>
				<span className="jcore-pakkaus__arrow" aria-hidden="true">
					→
				</span>
				<span className="screen-reader-text">
					{ __( 'optimized to', 'jcore-pakkaus' ) }
				</span>
				<strong>{ formatBytes( video.optimized_size ) }</strong>
				<span className="jcore-pakkaus__saving">
					{ sprintf(
						/* translators: %s: percentage saved, e.g. 42 % */
						__( '−%s', 'jcore-pakkaus' ),
						formatPercent(
							1 - video.optimized_size / video.original_size
						)
					) }
				</span>
			</div>
			{ before && after && before !== after && (
				<div className="jcore-pakkaus__streams">
					{ before } → { after }
				</div>
			) }
		</>
	);
}

function VideoRow( { video, busyAction, canOptimize, onAction } ) {
	const isRunning = RUNNING.includes( video.status );
	const isActive = video.filter === 'active';
	const isDone = [ 'optimized', 'skipped' ].includes( video.status );
	const dimensions =
		video.width && video.height ? `${ video.width }×${ video.height }` : '';

	return (
		<tr className={ cx( 'jcore-pakkaus__row', `is-${ video.filter }` ) }>
			<td className="jcore-pakkaus__cell-video">
				<Thumbnail video={ video } />
				<div className="jcore-pakkaus__video-text">
					<a
						className="jcore-pakkaus__video-title"
						href={ video.edit_url }
					>
						{ video.title }
					</a>
					<span className="jcore-pakkaus__video-meta">
						{ [ video.filename, dimensions ]
							.filter( Boolean )
							.join( ' · ' ) }
					</span>
				</div>
			</td>
			<td className="jcore-pakkaus__cell-status">
				<StatusBadge
					status={ video.status }
					progress={ video.progress }
				/>
				{ video.status === 'processing' && (
					<div
						className="jcore-pakkaus__progress"
						role="progressbar"
						aria-valuemin={ 0 }
						aria-valuemax={ 100 }
						aria-valuenow={ Math.round( video.progress ) }
						aria-label={ __(
							'Optimization progress',
							'jcore-pakkaus'
						) }
					>
						<span
							style={ {
								width: `${ Math.min(
									100,
									Math.max( 2, video.progress )
								) }%`,
							} }
						/>
					</div>
				) }
				{ video.message && (
					<p
						className={ cx(
							'jcore-pakkaus__message',
							video.status === 'failed' && 'is-error'
						) }
					>
						{ video.message }
					</p>
				) }
			</td>
			<td className="jcore-pakkaus__cell-size">
				<SizeInfo video={ video } />
			</td>
			<td className="jcore-pakkaus__cell-actions">
				<div className="jcore-pakkaus__actions">
					{ busyAction && <Spinner /> }
					{ ! busyAction && ! isActive && canOptimize && (
						<Button
							size="compact"
							variant={ isDone ? 'tertiary' : 'secondary' }
							icon={ isDone ? update : undefined }
							onClick={ () => onAction( video, 'optimize' ) }
						>
							{ isDone
								? __( 'Re-optimize', 'jcore-pakkaus' )
								: __( 'Optimize', 'jcore-pakkaus' ) }
						</Button>
					) }
					{ ! busyAction && video.has_backup && ! isRunning && (
						<Button
							size="compact"
							variant="tertiary"
							icon={ backup }
							onClick={ () => onAction( video, 'restore' ) }
						>
							{ __( 'Restore original', 'jcore-pakkaus' ) }
						</Button>
					) }
				</div>
			</td>
		</tr>
	);
}

/**
 * The page of videos with their status, sizes and actions.
 *
 * @param {Object}   props
 * @param {Object[]} props.items       Videos as Library::item() returns them.
 * @param {boolean}  props.isLoading   Whether a new page is loading.
 * @param {Object}   props.busy        Running action per video ID.
 * @param {boolean}  props.canOptimize Whether the service is configured.
 * @param {Function} props.onAction    Called with ( video, 'optimize'|'restore' ).
 */
export default function VideoTable( {
	items,
	isLoading,
	busy,
	canOptimize,
	onAction,
} ) {
	if ( ! items.length ) {
		return (
			<p className="jcore-pakkaus__no-results">
				{ __( 'No videos match.', 'jcore-pakkaus' ) }
			</p>
		);
	}

	return (
		<table
			className={ cx(
				'jcore-pakkaus__table',
				isLoading && 'is-loading'
			) }
		>
			<thead>
				<tr>
					<th scope="col">{ __( 'Video', 'jcore-pakkaus' ) }</th>
					<th scope="col">{ __( 'Status', 'jcore-pakkaus' ) }</th>
					<th scope="col">{ __( 'Size', 'jcore-pakkaus' ) }</th>
					<th scope="col">
						<span className="screen-reader-text">
							{ __( 'Actions', 'jcore-pakkaus' ) }
						</span>
					</th>
				</tr>
			</thead>
			<tbody>
				{ items.map( ( video ) => (
					<VideoRow
						key={ video.id }
						video={ video }
						busyAction={ busy[ video.id ] }
						canOptimize={ canOptimize }
						onAction={ onAction }
					/>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * Previous / next navigation under the table.
 *
 * @param {Object}   props
 * @param {number}   props.page       Current page.
 * @param {number}   props.totalPages Number of pages.
 * @param {number}   props.total      Number of matching videos.
 * @param {Function} props.onChange   Called with the new page.
 */
export function Pagination( { page, totalPages, total, onChange } ) {
	return (
		<div className="jcore-pakkaus__pagination">
			<span className="jcore-pakkaus__pagination-total">
				{ sprintf(
					/* translators: %s: number of videos */
					_n( '%s video', '%s videos', total, 'jcore-pakkaus' ),
					formatNumber( total )
				) }
			</span>
			{ totalPages > 1 && (
				<div className="jcore-pakkaus__pagination-pages">
					<Button
						size="compact"
						variant="tertiary"
						disabled={ page <= 1 }
						accessibleWhenDisabled
						onClick={ () => onChange( page - 1 ) }
					>
						{ __( '← Previous', 'jcore-pakkaus' ) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page, 2: number of pages */
							__( 'Page %1$s of %2$s', 'jcore-pakkaus' ),
							formatNumber( page ),
							formatNumber( totalPages )
						) }
					</span>
					<Button
						size="compact"
						variant="tertiary"
						disabled={ page >= totalPages }
						accessibleWhenDisabled
						onClick={ () => onChange( page + 1 ) }
					>
						{ __( 'Next →', 'jcore-pakkaus' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
