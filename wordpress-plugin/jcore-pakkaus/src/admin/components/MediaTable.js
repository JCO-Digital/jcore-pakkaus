import { Button, Spinner } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { backup, Icon, seen, update } from '@wordpress/icons';
import StatusBadge from './StatusBadge';
import {
	cx,
	describeStream,
	formatBytes,
	formatPercent,
	formatNumber,
} from '../utils';

const RUNNING = [ 'queued', 'processing' ];

function Thumbnail( { item, icon } ) {
	return (
		<span className="jcore-pakkaus__thumb">
			{ item.thumbnail ? (
				<img src={ item.thumbnail } alt="" loading="lazy" />
			) : (
				<Icon icon={ icon } size={ 28 } />
			) }
			{ item.duration && (
				<span className="jcore-pakkaus__thumb-duration">
					{ item.duration }
				</span>
			) }
		</span>
	);
}

function SizeInfo( { item } ) {
	if ( item.status !== 'optimized' || ! item.original_size ) {
		return (
			<span className="jcore-pakkaus__filesize">
				{ item.filesize ? formatBytes( item.filesize ) : '–' }
			</span>
		);
	}

	const before = describeStream( item.before );
	const after = describeStream( item.after );
	const formats = item.formats ?? [];

	return (
		<>
			<div className="jcore-pakkaus__sizes">
				<span className="jcore-pakkaus__size-before">
					{ formatBytes( item.original_size ) }
				</span>
				<span className="jcore-pakkaus__arrow" aria-hidden="true">
					→
				</span>
				<span className="screen-reader-text">
					{ __( 'optimized to', 'jcore-pakkaus' ) }
				</span>
				<strong>{ formatBytes( item.optimized_size ) }</strong>
				<span className="jcore-pakkaus__saving">
					{ sprintf(
						/* translators: %s: percentage saved, e.g. 42 % */
						__( '−%s', 'jcore-pakkaus' ),
						formatPercent(
							1 - item.optimized_size / item.original_size
						)
					) }
				</span>
			</div>
			{ before && after && before !== after && (
				<div className="jcore-pakkaus__streams">
					{ before } → { after }
				</div>
			) }
			{ item.kind === 'image' && (
				<div className="jcore-pakkaus__streams">
					{ [
						formats.includes( 'webp' ) &&
							__( 'With WebP', 'jcore-pakkaus' ),
						sprintf(
							/* translators: %s: number of files (the image and its resized copies) */
							_n(
								'%s file',
								'%s files',
								item.files,
								'jcore-pakkaus'
							),
							formatNumber( item.files )
						),
					]
						.filter( Boolean )
						.join( ' · ' ) }
				</div>
			) }
		</>
	);
}

function MediaRow( {
	item,
	icon,
	busyAction,
	canOptimize,
	canRestore,
	onAction,
	onCompare,
} ) {
	const isRunning = RUNNING.includes( item.status );
	const isActive = item.filter === 'active';
	const isDone = [ 'optimized', 'skipped' ].includes( item.status );
	const canCompare =
		item.kind === 'image' ? !! item.compare : item.has_backup;
	const dimensions =
		item.width && item.height ? `${ item.width }×${ item.height }` : '';

	return (
		<tr className={ cx( 'jcore-pakkaus__row', `is-${ item.filter }` ) }>
			<td className="jcore-pakkaus__cell-video">
				<Thumbnail item={ item } icon={ icon } />
				<div className="jcore-pakkaus__video-text">
					<a
						className="jcore-pakkaus__video-title"
						href={ item.edit_url }
					>
						{ item.title }
					</a>
					<span className="jcore-pakkaus__video-meta">
						{ [ item.filename, dimensions ]
							.filter( Boolean )
							.join( ' · ' ) }
					</span>
				</div>
			</td>
			<td className="jcore-pakkaus__cell-status">
				<StatusBadge
					status={ item.status }
					progress={ item.progress }
				/>
				{ item.status === 'processing' && (
					<div
						className="jcore-pakkaus__progress"
						role="progressbar"
						aria-valuemin={ 0 }
						aria-valuemax={ 100 }
						aria-valuenow={ Math.round( item.progress ) }
						aria-label={ __(
							'Optimization progress',
							'jcore-pakkaus'
						) }
					>
						<span
							style={ {
								width: `${ Math.min(
									100,
									Math.max( 2, item.progress )
								) }%`,
							} }
						/>
					</div>
				) }
				{ item.message && (
					<p
						className={ cx(
							'jcore-pakkaus__message',
							item.status === 'failed' && 'is-error'
						) }
					>
						{ item.message }
					</p>
				) }
			</td>
			<td className="jcore-pakkaus__cell-size">
				<SizeInfo item={ item } />
			</td>
			<td className="jcore-pakkaus__cell-actions">
				<div className="jcore-pakkaus__actions">
					{ busyAction && <Spinner /> }
					{ ! busyAction && ! isActive && canOptimize && (
						<Button
							size="compact"
							variant={ isDone ? 'tertiary' : 'secondary' }
							icon={ isDone ? update : undefined }
							onClick={ () => onAction( item, 'optimize' ) }
						>
							{ isDone
								? __( 'Re-optimize', 'jcore-pakkaus' )
								: __( 'Optimize', 'jcore-pakkaus' ) }
						</Button>
					) }
					{ ! busyAction && canCompare && ! isRunning && (
						<Button
							size="compact"
							variant="tertiary"
							icon={ seen }
							onClick={ () => onCompare( item ) }
						>
							{ __( 'Compare', 'jcore-pakkaus' ) }
						</Button>
					) }
					{ ! busyAction &&
						canRestore &&
						item.has_backup &&
						! isRunning && (
							<Button
								size="compact"
								variant="tertiary"
								icon={ backup }
								onClick={ () => onAction( item, 'restore' ) }
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
 * The page of videos or images with their status, sizes and actions.
 *
 * @param {Object}   props
 * @param {Object}   props.media       Entry of MEDIA.
 * @param {Object[]} props.items       Attachments as Library::item() returns them.
 * @param {boolean}  props.isLoading   Whether a new page is loading.
 * @param {Object}   props.busy        Running action per attachment ID.
 * @param {boolean}  props.canOptimize Whether the service is configured and nothing pauses it.
 * @param {boolean}  props.canRestore  Whether originals can be restored now.
 * @param {Function} props.onAction    Called with ( item, 'optimize'|'restore' ).
 * @param {Function} props.onCompare   Called with an attachment that has two versions to compare.
 */
export default function MediaTable( {
	media,
	items,
	isLoading,
	busy,
	canOptimize,
	canRestore = true,
	onAction,
	onCompare,
} ) {
	if ( ! items.length ) {
		return <p className="jcore-pakkaus__no-results">{ media.noMatch }</p>;
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
					<th scope="col">{ media.column }</th>
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
				{ items.map( ( item ) => (
					<MediaRow
						key={ item.id }
						item={ item }
						icon={ media.icon }
						busyAction={ busy[ item.id ] }
						canOptimize={ canOptimize }
						canRestore={ canRestore }
						onAction={ onAction }
						onCompare={ onCompare }
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
 * @param {Object}   props.media      Entry of MEDIA.
 * @param {number}   props.page       Current page.
 * @param {number}   props.totalPages Number of pages.
 * @param {number}   props.total      Number of matching attachments.
 * @param {Function} props.onChange   Called with the new page.
 */
export function Pagination( { media, page, totalPages, total, onChange } ) {
	return (
		<div className="jcore-pakkaus__pagination">
			<span className="jcore-pakkaus__pagination-total">
				{ media.count( total ) }
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
