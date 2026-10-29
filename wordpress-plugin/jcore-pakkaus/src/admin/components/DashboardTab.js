import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, SearchControl, Spinner } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __, sprintf } from '@wordpress/i18n';
import { connection, Icon, upload } from '@wordpress/icons';
import { getItems, optimizeAll, optimizeItem, restoreItem } from '../api';
import { CONFIG, FILTERS } from '../constants';
import { cx, errorMessage, formatNumber } from '../utils';
import CompareModal from './CompareModal';
import ImageCompareModal from './ImageCompareModal';
import StatCards from './StatCards';
import MediaTable, { Pagination } from './MediaTable';

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
						'Videos and images are optimized by a self-hosted service. Add its URL and API token to start optimizing uploads.',
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

function BulkPrompt( { media, count, isBusy, onOptimize } ) {
	return (
		<div className="jcore-pakkaus__callout is-subtle">
			<div className="jcore-pakkaus__callout-text">
				<h2>{ media.neverOptimized( count ) }</h2>
				<p>{ media.bulkText }</p>
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

function EmptyLibrary( { media } ) {
	return (
		<div className="jcore-pakkaus__empty">
			<Icon icon={ media.icon } size={ 48 } />
			<h2>{ media.emptyTitle }</h2>
			<p>{ media.emptyText }</p>
			<Button
				variant="secondary"
				icon={ upload }
				href={ CONFIG.uploadUrl }
			>
				{ media.upload }
			</Button>
		</div>
	);
}

function Filters( { media, counts, filter, onChange } ) {
	return (
		<div
			className="jcore-pakkaus__filters"
			role="group"
			aria-label={ media.filters }
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
 * The Videos or Images tab: library summary, then every attachment with its status.
 *
 * @param {Object}   props
 * @param {Object}   props.media          Entry of MEDIA: which list, and its wording.
 * @param {boolean}  props.isActive       Whether the tab is showing; it only refreshes then.
 * @param {?Object}  props.service        Response of GET /service.
 * @param {Function} props.onOpenSettings Opens the settings tab.
 */
export default function DashboardTab( {
	media,
	isActive,
	service,
	onOpenSettings,
} ) {
	const [ filter, setFilter ] = useState( 'all' );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ busy, setBusy ] = useState( {} );
	const [ isBulkBusy, setIsBulkBusy ] = useState( false );
	const [ comparing, setComparing ] = useState( null );
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
				const result = await getItems( media.type, {
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
		[ media.type, filter, page, query ]
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

	const runAction = async ( item, action ) => {
		if (
			action === 'restore' &&
			// eslint-disable-next-line no-alert
			! window.confirm( media.restoreConfirm )
		) {
			return;
		}

		setBusy( ( current ) => ( { ...current, [ item.id ]: action } ) );
		try {
			if ( action === 'restore' ) {
				await restoreItem( media.type, item.id );
				createSuccessNotice(
					sprintf(
						/* translators: %s: video or image title */
						__( 'Restored the original of “%s”.', 'jcore-pakkaus' ),
						item.title
					),
					{ type: 'snackbar' }
				);
			} else {
				await optimizeItem( media.type, item.id );
				createSuccessNotice(
					sprintf(
						/* translators: %s: video or image title */
						__(
							'“%s” was sent to the optimizer.',
							'jcore-pakkaus'
						),
						item.title
					),
					{ type: 'snackbar' }
				);
			}
		} catch ( caught ) {
			createErrorNotice( errorMessage( caught ), { type: 'snackbar' } );
		}
		setBusy( ( { [ item.id ]: done, ...rest } ) => rest );
		load( { quiet: true } );
	};

	const runOptimizeAll = async () => {
		setIsBulkBusy( true );
		try {
			// A large library is queued over several requests, so none runs into a timeout.
			let total = 0;
			let more = true;
			while ( more ) {
				const result = await optimizeAll( media.type );
				total += result.queued;
				more = result.more;
				if ( more ) {
					load( { quiet: true } );
				}
			}
			createSuccessNotice( media.queued( total ), {
				type: 'snackbar',
			} );
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
	// Another image optimizer is active; images are left alone (the app shows why).
	const isPaused = !! data.paused;

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
				<EmptyLibrary media={ media } />
			) : (
				<>
					<StatCards media={ media } summary={ summary } />

					{ isConfigured && ! isPaused && summary.new > 0 && (
						<BulkPrompt
							media={ media }
							count={ summary.new }
							isBusy={ isBulkBusy }
							onOptimize={ runOptimizeAll }
						/>
					) }

					<div className="jcore-pakkaus__card jcore-pakkaus__library">
						<div className="jcore-pakkaus__toolbar">
							<Filters
								media={ media }
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
								label={ media.search }
								placeholder={ media.search }
								value={ search }
								onChange={ setSearch }
							/>
						</div>

						{ error && (
							<Notice status="error" isDismissible={ false }>
								{ error }
							</Notice>
						) }

						<MediaTable
							media={ media }
							items={ data.items }
							isLoading={ isLoading }
							busy={ busy }
							canOptimize={ isConfigured && ! isPaused }
							canRestore={ ! isPaused }
							onAction={ runAction }
							onCompare={ setComparing }
						/>

						<Pagination
							media={ media }
							page={ page }
							totalPages={ data.total_pages }
							total={ data.total }
							onChange={ setPage }
						/>
					</div>
				</>
			) }

			{ comparing?.kind === 'image' && (
				<ImageCompareModal
					image={ comparing }
					onClose={ () => setComparing( null ) }
				/>
			) }
			{ comparing && comparing.kind !== 'image' && (
				<CompareModal
					video={ comparing }
					onClose={ () => setComparing( null ) }
				/>
			) }
		</div>
	);
}
