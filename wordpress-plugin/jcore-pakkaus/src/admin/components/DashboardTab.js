import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, SearchControl, Spinner } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __, _n, sprintf } from '@wordpress/i18n';
import { connection, Icon, upload, video as videoIcon } from '@wordpress/icons';
import { getVideos, optimizeAll, optimizeVideo, restoreVideo } from '../api';
import { CONFIG, FILTERS } from '../constants';
import { cx, errorMessage, formatNumber } from '../utils';
import StatCards from './StatCards';
import VideoTable, { Pagination } from './VideoTable';

const PER_PAGE = 20;
const REFRESH_INTERVAL = 5000;

function ConnectPrompt( { onOpenSettings } ) {
	return (
		<div className="jcore-pakkaus__callout">
			<span className="jcore-pakkaus__callout-icon" aria-hidden="true">
				<Icon icon={ connection } size={ 32 } />
			</span>
			<div className="jcore-pakkaus__callout-text">
				<h2>
					{ __( 'Connect your optimizer service', 'jcore-pakkaus' ) }
				</h2>
				<p>
					{ __(
						'Videos are transcoded by a self-hosted FFmpeg service. Add its URL and API token to start optimizing uploads.',
						'jcore-pakkaus'
					) }
				</p>
			</div>
			<Button variant="primary" onClick={ onOpenSettings }>
				{ __( 'Set up the connection', 'jcore-pakkaus' ) }
			</Button>
		</div>
	);
}

function BulkPrompt( { count, isBusy, onOptimize } ) {
	return (
		<div className="jcore-pakkaus__callout is-subtle">
			<div className="jcore-pakkaus__callout-text">
				<h2>
					{ sprintf(
						/* translators: %s: number of videos */
						_n(
							'%s video has never been optimized',
							'%s videos have never been optimized',
							count,
							'jcore-pakkaus'
						),
						formatNumber( count )
					) }
				</h2>
				<p>
					{ __(
						'Probably uploaded before the plugin was set up. They are sent to the service in small batches, so it is never flooded.',
						'jcore-pakkaus'
					) }
				</p>
			</div>
			<Button
				variant="primary"
				isBusy={ isBusy }
				disabled={ isBusy }
				accessibleWhenDisabled
				onClick={ onOptimize }
			>
				{ __( 'Optimize all', 'jcore-pakkaus' ) }
			</Button>
		</div>
	);
}

function EmptyLibrary() {
	return (
		<div className="jcore-pakkaus__empty">
			<Icon icon={ videoIcon } size={ 48 } />
			<h2>{ __( 'No videos yet', 'jcore-pakkaus' ) }</h2>
			<p>
				{ __(
					'Videos you upload to the media library show up here with their optimization status.',
					'jcore-pakkaus'
				) }
			</p>
			<Button
				variant="secondary"
				icon={ upload }
				href={ CONFIG.uploadUrl }
			>
				{ __( 'Upload a video', 'jcore-pakkaus' ) }
			</Button>
		</div>
	);
}

function Filters( { counts, filter, onChange } ) {
	return (
		<div
			className="jcore-pakkaus__filters"
			role="group"
			aria-label={ __( 'Filter videos by status', 'jcore-pakkaus' ) }
		>
			{ FILTERS.filter(
				( { name } ) =>
					name === 'all' || name === filter || counts[ name ] > 0
			).map( ( { name, label } ) => (
				<button
					key={ name }
					type="button"
					className={ cx(
						'jcore-pakkaus__filter',
						filter === name && 'is-selected'
					) }
					aria-pressed={ filter === name }
					onClick={ () => onChange( name ) }
				>
					{ label }
					<span className="jcore-pakkaus__filter-count">
						{ formatNumber( counts[ name ] ) }
					</span>
				</button>
			) ) }
		</div>
	);
}

/**
 * The Videos tab: library summary, then every video with its status.
 *
 * @param {Object}   props
 * @param {boolean}  props.isActive       Whether the tab is showing; it only refreshes then.
 * @param {?Object}  props.service        Response of GET /service.
 * @param {Function} props.onOpenSettings Opens the settings tab.
 */
export default function DashboardTab( { isActive, service, onOpenSettings } ) {
	const [ filter, setFilter ] = useState( 'all' );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ busy, setBusy ] = useState( {} );
	const [ isBulkBusy, setIsBulkBusy ] = useState( false );
	const latestRequest = useRef( 0 );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	const load = useCallback(
		async ( { quiet = false } = {} ) => {
			const request = ++latestRequest.current;
			if ( ! quiet ) {
				setIsLoading( true );
			}
			try {
				const result = await getVideos( {
					status: filter,
					page,
					per_page: PER_PAGE,
					search: query,
				} );
				if ( request !== latestRequest.current ) {
					return;
				}
				setData( result );
				setError( null );
				if ( page > 1 && page > result.total_pages ) {
					setPage( Math.max( 1, result.total_pages ) );
				}
			} catch ( caught ) {
				if ( request === latestRequest.current ) {
					setError( errorMessage( caught ) );
				}
			} finally {
				if ( request === latestRequest.current ) {
					setIsLoading( false );
				}
			}
		},
		[ filter, page, query ]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	// Search as the user types, without a request per keystroke.
	useEffect( () => {
		const timer = setTimeout( () => {
			setQuery( search.trim() );
			setPage( 1 );
		}, 300 );
		return () => clearTimeout( timer );
	}, [ search ] );

	// Follow running jobs while the tab is open and visible.
	const activeCount = data?.summary.counts.active ?? 0;
	useEffect( () => {
		if ( ! isActive || ! activeCount ) {
			return;
		}
		const timer = setInterval( () => {
			if ( ! document.hidden ) {
				load( { quiet: true } );
			}
		}, REFRESH_INTERVAL );
		return () => clearInterval( timer );
	}, [ isActive, activeCount, load ] );

	const runAction = async ( video, action ) => {
		if (
			action === 'restore' &&
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'Replace the optimized video with the original file?',
					'jcore-pakkaus'
				)
			)
		) {
			return;
		}

		setBusy( ( current ) => ( { ...current, [ video.id ]: action } ) );
		try {
			if ( action === 'restore' ) {
				await restoreVideo( video.id );
				createSuccessNotice(
					sprintf(
						/* translators: %s: video title */
						__( 'Restored the original of “%s”.', 'jcore-pakkaus' ),
						video.title
					),
					{ type: 'snackbar' }
				);
			} else {
				await optimizeVideo( video.id );
				createSuccessNotice(
					sprintf(
						/* translators: %s: video title */
						__(
							'“%s” was sent to the optimizer.',
							'jcore-pakkaus'
						),
						video.title
					),
					{ type: 'snackbar' }
				);
			}
		} catch ( caught ) {
			createErrorNotice( errorMessage( caught ), { type: 'snackbar' } );
		}
		setBusy( ( { [ video.id ]: done, ...rest } ) => rest );
		load( { quiet: true } );
	};

	const runOptimizeAll = async () => {
		setIsBulkBusy( true );
		try {
			const { queued } = await optimizeAll();
			createSuccessNotice(
				sprintf(
					/* translators: %s: number of videos */
					_n(
						'%s video queued for optimization.',
						'%s videos queued for optimization.',
						queued,
						'jcore-pakkaus'
					),
					formatNumber( queued )
				),
				{ type: 'snackbar' }
			);
		} catch ( caught ) {
			createErrorNotice( errorMessage( caught ), { type: 'snackbar' } );
		}
		setIsBulkBusy( false );
		load( { quiet: true } );
	};

	if ( error && ! data ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( ! data ) {
		return (
			<div className="jcore-pakkaus__loading">
				<Spinner />
			</div>
		);
	}

	const { summary } = data;
	// Until the service check answers, assume it is set up.
	const isConfigured = service?.configured ?? true;

	return (
		<div className="jcore-pakkaus__dashboard">
			{ ! isConfigured && (
				<ConnectPrompt onOpenSettings={ onOpenSettings } />
			) }

			{ isConfigured && service && ! service.connected && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: error message */
						__(
							'The optimizer service cannot be reached: %s',
							'jcore-pakkaus'
						),
						service.error
					) }
				</Notice>
			) }

			{ summary.counts.all === 0 ? (
				<EmptyLibrary />
			) : (
				<>
					<StatCards summary={ summary } />

					{ isConfigured && summary.new > 0 && (
						<BulkPrompt
							count={ summary.new }
							isBusy={ isBulkBusy }
							onOptimize={ runOptimizeAll }
						/>
					) }

					<div className="jcore-pakkaus__card jcore-pakkaus__library">
						<div className="jcore-pakkaus__toolbar">
							<Filters
								counts={ summary.counts }
								filter={ filter }
								onChange={ ( name ) => {
									setFilter( name );
									setPage( 1 );
								} }
							/>
							<SearchControl
								__nextHasNoMarginBottom
								className="jcore-pakkaus__search"
								label={ __( 'Search videos', 'jcore-pakkaus' ) }
								placeholder={ __(
									'Search videos',
									'jcore-pakkaus'
								) }
								value={ search }
								onChange={ setSearch }
							/>
						</div>

						{ error && (
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						) }

						<VideoTable
							items={ data.items }
							isLoading={ isLoading }
							busy={ busy }
							canOptimize={ isConfigured }
							onAction={ runAction }
						/>

						<Pagination
							page={ page }
							totalPages={ data.total_pages }
							total={ data.total }
							onChange={ setPage }
						/>
					</div>
				</>
			) }
		</div>
	);
}
