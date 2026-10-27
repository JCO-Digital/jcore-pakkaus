import { useCallback, useEffect, useState } from '@wordpress/element';
import { Button, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { Icon, image } from '@wordpress/icons';
import { addQueryArgs } from '@wordpress/url';
import { getServingSamples } from '../api';
import { cx, errorMessage, formatBytes, formatPercent } from '../utils';

// Accept headers of a browser that takes WebP, and of one that doesn't.
const ACCEPT_WEBP = 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8';
const ACCEPT_PLAIN = 'image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5';

const TYPE_NAMES = {
	'image/webp': 'WebP',
	'image/jpeg': 'JPEG',
	'image/png': 'PNG',
};

// Results from worst to best: the page summary is the worst one found.
const RESULTS = {
	broken: {
		tone: 'error',
		label: __( 'WebP for everyone', 'jcore-pakkaus' ),
		text: __(
			'Browsers that do not support WebP get WebP too. Check the Nginx rules in Settings.',
			'jcore-pakkaus'
		),
	},
	error: {
		tone: 'error',
		label: __( 'Did not load', 'jcore-pakkaus' ),
		text: __(
			'This browser could not load some of the images. Open them below to see what the server answers.',
			'jcore-pakkaus'
		),
	},
	not_configured: {
		tone: 'warning',
		label: __( 'Original sent', 'jcore-pakkaus' ),
		text: __(
			'This browser supports WebP but got the original files, so the WebP versions are not used yet. Add the Nginx rules in Settings.',
			'jcore-pakkaus'
		),
	},
	no_vary: {
		tone: 'warning',
		label: __( 'No Vary header', 'jcore-pakkaus' ),
		text: __(
			'WebP versions are sent, but without the Vary: Accept header. Caches and CDNs in front of the site may then give WebP to browsers that cannot show it.',
			'jcore-pakkaus'
		),
	},
	unknown: {
		tone: 'neutral',
		label: __( 'Unknown', 'jcore-pakkaus' ),
		text: __(
			'The images loaded, but this browser could not tell which file was sent. Images from another domain only show that when it allows cross-origin requests.',
			'jcore-pakkaus'
		),
	},
	ok: {
		tone: 'success',
		label: __( 'WebP sent', 'jcore-pakkaus' ),
		text: __(
			'This browser got the WebP versions, and browsers without WebP support get the originals.',
			'jcore-pakkaus'
		),
	},
};

/**
 * A URL no cache has seen yet, so the test reaches the web server.
 *
 * @param {string} url File URL.
 * @return {string} URL with a unique query argument.
 */
function fresh( url ) {
	return addQueryArgs( url, {
		'jcore-pakkaus-test': `${ Date.now() }${ Math.random() }`.replace(
			/\D/g,
			''
		),
	} );
}

/**
 * MIME type of a Content-Type header, without parameters.
 *
 * @param {?string} header Header value.
 * @return {string} MIME type, or ''.
 */
function mimeType( header ) {
	return ( header ?? '' ).split( ';' )[ 0 ].trim().toLowerCase();
}

/**
 * Loads a URL as an image, the way the site's pages do, and reads what arrived from Resource Timing:
 * Chromium reports the content type; elsewhere the body size tells the original from the WebP version.
 *
 * @param {string} url  URL to load.
 * @param {Object} file File as GET /images/serving/samples lists it.
 * @return {Promise<{loaded: boolean, type: string, bytes: number}>} What the browser got.
 */
function loadImage( url, file ) {
	return new Promise( ( resolve ) => {
		const img = new window.Image();
		img.onload = () => {
			const entry = window.performance
				?.getEntriesByName?.( img.src, 'resource' )
				.pop();
			const bytes = entry?.encodedBodySize || 0;
			let type = entry?.contentType || '';
			if ( ! type && bytes ) {
				if ( bytes === file.webp_size ) {
					type = 'image/webp';
				} else if ( bytes === file.size ) {
					type = file.mime;
				}
			}
			resolve( { loaded: img.naturalWidth > 0, type, bytes } );
		};
		img.onerror = () => resolve( { loaded: false, type: '', bytes: 0 } );
		img.src = url;
	} );
}

/**
 * Requests a URL with an Accept header and reads the response headers.
 *
 * @param {string} url    URL.
 * @param {string} accept Accept header.
 * @return {Promise<?{type: string, vary: ?boolean}>} Response details, or null when the browser may not
 *                                                     read them.
 */
async function probe( url, accept ) {
	try {
		const response = await window.fetch( fresh( url ), {
			headers: { Accept: accept },
			cache: 'no-store',
			credentials: 'same-origin',
		} );
		// Only the headers matter; don't download the image again.
		response.body?.cancel();
		if ( ! response.ok ) {
			return null;
		}
		const vary = response.headers.get( 'vary' );
		return {
			type: mimeType( response.headers.get( 'content-type' ) ),
			// Cross-origin responses hide Vary unless the server exposes it.
			vary: vary === null ? null : /(^|,)\s*accept\s*(,|$)/i.test( vary ),
		};
	} catch {
		return null;
	}
}

/**
 * Tests one file: loads it as an image, then asks for it as browsers with and without WebP support do.
 *
 * @param {Object} file File as GET /images/serving/samples lists it.
 * @return {Promise<Object>} Result.
 */
async function testFile( file ) {
	const src = fresh( file.url );
	const [ img, withWebp, withoutWebp ] = await Promise.all( [
		loadImage( src, file ),
		probe( file.url, ACCEPT_WEBP ),
		probe( file.url, ACCEPT_PLAIN ),
	] );
	const served = img.type || withWebp?.type || '';

	let status = 'unknown';
	if ( ! img.loaded ) {
		status = 'error';
	} else if ( withoutWebp?.type === 'image/webp' ) {
		status = 'broken';
	} else if ( served === 'image/webp' ) {
		status = withWebp?.vary === false ? 'no_vary' : 'ok';
	} else if ( served ) {
		status = 'not_configured';
	}

	return {
		src,
		status,
		served,
		bytes:
			img.bytes ||
			( served === 'image/webp' ? file.webp_size : file.size ),
		fallback: withoutWebp?.type ?? '',
		vary: withWebp?.vary ?? null,
	};
}

function typeName( type ) {
	return TYPE_NAMES[ type ] ?? ( type || '–' );
}

function varyText( vary ) {
	if ( vary === null ) {
		return '–';
	}
	return vary ? __( 'Yes', 'jcore-pakkaus' ) : __( 'No', 'jcore-pakkaus' );
}

function sizeName( name ) {
	return name === 'full' ? __( 'Full size', 'jcore-pakkaus' ) : name;
}

function Served( { file, result } ) {
	if ( ! result.served ) {
		return '–';
	}
	const isWebp = result.served === 'image/webp';

	return (
		<>
			<strong>{ typeName( result.served ) }</strong>{ ' ' }
			<span className="jcore-pakkaus__filesize">
				{ formatBytes( result.bytes ) }
			</span>
			{ isWebp && file.size > file.webp_size && (
				<span className="jcore-pakkaus__saving">
					{ sprintf(
						/* translators: %s: percentage saved, e.g. 42 % */
						__( '−%s', 'jcore-pakkaus' ),
						formatPercent( 1 - file.webp_size / file.size )
					) }
				</span>
			) }
		</>
	);
}

function Row( { sample, file, result } ) {
	const { tone, label } = RESULTS[ result?.status ] ?? {};

	return (
		<tr>
			<td>
				<div className="jcore-pakkaus__cell-video">
					<span className="jcore-pakkaus__thumb">
						{ result?.status !== 'error' && result?.src ? (
							<img src={ result.src } alt="" />
						) : (
							<Icon icon={ image } size={ 28 } />
						) }
					</span>
					<span className="jcore-pakkaus__video-text">
						<a
							className="jcore-pakkaus__video-title"
							href={ file.url }
							target="_blank"
							rel="noreferrer"
						>
							{ sample.title }
						</a>
						<span className="jcore-pakkaus__video-meta">
							{ sprintf(
								/* translators: 1: image size name, e.g. Full size, 2: file type, 3: file size */
								__( '%1$s · %2$s %3$s', 'jcore-pakkaus' ),
								sizeName( file.name ),
								typeName( file.mime ),
								formatBytes( file.size )
							) }
						</span>
					</span>
				</div>
			</td>
			<td className="jcore-pakkaus__cell-size">
				{ result ? <Served file={ file } result={ result } /> : '' }
			</td>
			<td>{ result ? typeName( result.fallback ) : '' }</td>
			<td>{ result && varyText( result.vary ) }</td>
			<td className="jcore-pakkaus__cell-status">
				{ result ? (
					<span
						className={ cx(
							'jcore-pakkaus__badge',
							`is-${ tone }`
						) }
					>
						{ label }
					</span>
				) : (
					<Spinner />
				) }
			</td>
		</tr>
	);
}

/**
 * Loads recently optimized images in this browser, through whatever CDN or proxy sits in front of the
 * site, and shows whether it got the WebP versions.
 *
 * @param {Object}   props
 * @param {Function} props.onOpenSettings Switches to the Settings tab.
 */
export default function WebpTest( { onOpenSettings } ) {
	const [ samples, setSamples ] = useState( null );
	const [ results, setResults ] = useState( {} );
	const [ isRunning, setIsRunning ] = useState( false );
	const [ loadError, setLoadError ] = useState( null );

	const run = useCallback( async () => {
		setIsRunning( true );
		setResults( {} );
		setLoadError( null );
		let images;
		try {
			( { images } = await getServingSamples() );
		} catch ( error ) {
			setLoadError( errorMessage( error ) );
			setIsRunning( false );
			return;
		}
		setSamples( images );
		await Promise.all(
			images.flatMap( ( sample ) =>
				sample.files.map( async ( file ) => {
					const result = await testFile( file );
					setResults( ( previous ) => ( {
						...previous,
						[ file.url ]: result,
					} ) );
				} )
			)
		);
		setIsRunning( false );
	}, [] );

	useEffect( () => {
		run();
	}, [ run ] );

	if ( loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ loadError }
			</Notice>
		);
	}

	if ( samples === null ) {
		return (
			<div className="jcore-pakkaus__loading">
				<Spinner />
			</div>
		);
	}

	if ( ! samples.length ) {
		return (
			<div className="jcore-pakkaus__empty">
				<Icon icon={ image } size={ 48 } />
				<h2>{ __( 'Nothing to test yet', 'jcore-pakkaus' ) }</h2>
				<p>
					{ __(
						'Optimize an image first, so it has a WebP version.',
						'jcore-pakkaus'
					) }
				</p>
			</div>
		);
	}

	const statuses = Object.values( results ).map( ( { status } ) => status );
	const worst = isRunning
		? null
		: Object.keys( RESULTS ).find( ( status ) =>
				statuses.includes( status )
			);
	const needsRules = [ 'broken', 'not_configured', 'no_vary' ].includes(
		worst
	);

	return (
		<div className="jcore-pakkaus__webp-test">
			<p className="jcore-pakkaus__webp-intro">
				{ __(
					'Loads recently optimized images in this browser, through any CDN or proxy in front of the site, and shows which file arrived. Each image is also requested as a browser without WebP support, which should get the original.',
					'jcore-pakkaus'
				) }
			</p>
			{ worst && (
				<Notice
					status={
						RESULTS[ worst ].tone === 'neutral'
							? 'info'
							: RESULTS[ worst ].tone
					}
					isDismissible={ false }
					actions={
						needsRules
							? [
									{
										label: __(
											'Show the Nginx rules',
											'jcore-pakkaus'
										),
										onClick: onOpenSettings,
										variant: 'link',
									},
								]
							: []
					}
				>
					{ RESULTS[ worst ].text }
				</Notice>
			) }
			<div className="jcore-pakkaus__card">
				<div className="jcore-pakkaus__toolbar">
					<strong>{ __( 'Images', 'jcore-pakkaus' ) }</strong>
					<Button
						variant="secondary"
						isBusy={ isRunning }
						disabled={ isRunning }
						accessibleWhenDisabled
						onClick={ run }
					>
						{ __( 'Test again', 'jcore-pakkaus' ) }
					</Button>
				</div>
				<table className="jcore-pakkaus__table">
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Image', 'jcore-pakkaus' ) }
							</th>
							<th scope="col">
								{ __( 'This browser got', 'jcore-pakkaus' ) }
							</th>
							<th scope="col">
								{ __(
									'Without WebP support',
									'jcore-pakkaus'
								) }
							</th>
							<th scope="col">
								{ __( 'Vary: Accept', 'jcore-pakkaus' ) }
							</th>
							<th scope="col">
								{ __( 'Result', 'jcore-pakkaus' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ samples.flatMap( ( sample ) =>
							sample.files.map( ( file ) => (
								<Row
									key={ file.url }
									sample={ sample }
									file={ file }
									result={ results[ file.url ] }
								/>
							) )
						) }
					</tbody>
				</table>
			</div>
		</div>
	);
}
