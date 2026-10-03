import { useCallback, useEffect, useState } from '@wordpress/element';
import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';
import { Icon, video } from '@wordpress/icons';
import { getService } from './api';
import { cx, errorMessage } from './utils';
import ServiceStatus from './components/ServiceStatus';
import DashboardTab from './components/DashboardTab';
import SettingsTab from './components/SettingsTab';

const TABS = [
	{ name: 'videos', title: __( 'Videos', 'jcore-pakkaus' ) },
	{ name: 'settings', title: __( 'Settings', 'jcore-pakkaus' ) },
];

function getTabFromUrl() {
	const tab = new URLSearchParams( window.location.search ).get( 'tab' );
	return TABS.some( ( { name } ) => name === tab ) ? tab : TABS[ 0 ].name;
}

function setTabInUrl( tabName ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'tab', tabName );
	window.history.replaceState( null, '', url.toString() );
}

function Snackbars() {
	const notices = useSelect(
		( select ) => select( noticesStore ).getNotices(),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );

	return (
		<SnackbarList
			className="jcore-pakkaus__snackbars"
			notices={ notices.filter(
				( notice ) => notice.type === 'snackbar'
			) }
			onRemove={ removeNotice }
		/>
	);
}

export default function App() {
	const [ tab, setTab ] = useState( getTabFromUrl );
	// Tabs stay mounted once visited, so unsaved settings survive a tab switch.
	const [ visited, setVisited ] = useState( () => new Set( [ tab ] ) );
	const [ service, setService ] = useState( null );
	const [ isChecking, setIsChecking ] = useState( true );

	const checkService = useCallback( async () => {
		setIsChecking( true );
		let result;
		try {
			result = await getService();
		} catch ( error ) {
			result = {
				configured: true,
				connected: false,
				error: errorMessage( error ),
			};
		}
		setService( result );
		setIsChecking( false );
		return result;
	}, [] );

	useEffect( () => {
		checkService();
	}, [ checkService ] );

	const selectTab = ( name ) => {
		setTab( name );
		setTabInUrl( name );
		setVisited( ( previous ) => new Set( previous ).add( name ) );
	};

	return (
		<div className="wrap jcore-pakkaus">
			<header className="jcore-pakkaus__header">
				<div className="jcore-pakkaus__brand">
					<span className="jcore-pakkaus__logo" aria-hidden="true">
						<Icon icon={ video } size={ 28 } />
					</span>
					<div>
						<h1>{ __( 'Video Optimizer', 'jcore-pakkaus' ) }</h1>
						<p className="jcore-pakkaus__tagline">
							{ __(
								'Smaller, faster-loading videos, straight from the media library.',
								'jcore-pakkaus'
							) }
						</p>
					</div>
				</div>
				<ServiceStatus
					service={ service }
					isChecking={ isChecking }
					onClick={ () => selectTab( 'settings' ) }
				/>
			</header>
			<hr className="wp-header-end" />

			<div
				className="jcore-pakkaus__tabs"
				role="tablist"
				aria-label={ __( 'Video Optimizer sections', 'jcore-pakkaus' ) }
			>
				{ TABS.map( ( { name, title } ) => (
					<button
						key={ name }
						type="button"
						role="tab"
						id={ `jcore-pakkaus-tab-${ name }` }
						aria-controls={ `jcore-pakkaus-panel-${ name }` }
						aria-selected={ tab === name }
						className={ cx(
							'jcore-pakkaus__tab',
							tab === name && 'is-active'
						) }
						onClick={ () => selectTab( name ) }
					>
						{ title }
					</button>
				) ) }
			</div>

			{ TABS.filter( ( { name } ) => visited.has( name ) ).map(
				( { name } ) => (
					<div
						key={ name }
						role="tabpanel"
						id={ `jcore-pakkaus-panel-${ name }` }
						aria-labelledby={ `jcore-pakkaus-tab-${ name }` }
						className="jcore-pakkaus__panel"
						hidden={ tab !== name }
					>
						{ name === 'videos' ? (
							<DashboardTab
								isActive={ tab === name }
								service={ service }
								onOpenSettings={ () => selectTab( 'settings' ) }
							/>
						) : (
							<SettingsTab
								service={ service }
								isChecking={ isChecking }
								onCheckService={ checkService }
							/>
						) }
					</div>
				)
			) }

			<Snackbars />
		</div>
	);
}
