import { Button, Modal, Notice } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { external } from '@wordpress/icons';
import { cx, formatBytes, formatPercent } from '../utils';

// HTMLMediaElement.HAVE_FUTURE_DATA: enough is buffered to keep playing.
const HAVE_FUTURE_DATA = 3;
// Seconds the original may drift from the optimized video before its speed
// is nudged, and before it is seeked back in line.
const NUDGE_DRIFT = 0.04;
const SEEK_DRIFT = 0.3;

const playIcon = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<path d="M8 5.5v13l10.5-6.5z" />
	</svg>
);

const pauseIcon = (
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<path d="M7 5h3.5v14H7zm6.5 0H17v14h-3.5z" />
	</svg>
);

/**
 * Formats seconds as m:ss.
 *
 * @param {number} seconds Time in seconds.
 * @return {string} Formatted time.
 */
function formatTime( seconds ) {
	const whole = Math.floor( seconds || 0 );

	return `${ Math.floor( whole / 60 ) }:${ String( whole % 60 ).padStart(
		2,
		'0'
	) }`;
}

function Fact( { label, size, dimensions } ) {
	return (
		<div>
			<dt>{ label }</dt>
			<dd>
				{ [
					formatBytes( size ),
					dimensions &&
						`${ dimensions.width }×${ dimensions.height }`,
				]
					.filter( Boolean )
					.join( ' · ' ) }
			</dd>
		</div>
	);
}

/**
 * Plays the kept original and the optimized video in step, either with a
 * draggable divider over one frame or side by side.
 *
 * @param {Object}   props
 * @param {Object}   props.video   Video as Library::item() returns it, with a backup.
 * @param {Function} props.onClose Closes the modal.
 */
export default function CompareModal( { video, onClose } ) {
	const originalRef = useRef();
	const optimizedRef = useRef();
	const [ mode, setMode ] = useState( 'slider' );
	const [ position, setPosition ] = useState( 50 );
	const [ isPlaying, setIsPlaying ] = useState( false );
	const [ time, setTime ] = useState( 0 );
	const [ duration, setDuration ] = useState( 0 );
	const [ dimensions, setDimensions ] = useState( {} );
	const [ originalFailed, setOriginalFailed ] = useState( false );
	const layout = originalFailed ? 'single' : mode;

	// The optimized video leads and the original follows it. Both wait while
	// either buffers, so they stay on the same frame.
	useEffect( () => {
		const lead = optimizedRef.current;
		const follow = originalFailed ? null : originalRef.current;
		const videos = [ lead, follow ].filter( Boolean );

		if ( ! isPlaying ) {
			videos.forEach( ( element ) => element.pause() );
			if ( follow ) {
				follow.currentTime = lead.currentTime;
			}
			return;
		}

		let frame;
		const tick = () => {
			const isReady = videos.every(
				( element ) =>
					element.ended || element.readyState >= HAVE_FUTURE_DATA
			);
			videos.forEach( ( element ) => {
				if ( ! isReady ) {
					element.pause();
				} else if ( element.paused && ! element.ended ) {
					element.play().catch( () => {} );
				}
			} );

			if ( isReady && follow && ! follow.ended ) {
				const drift = follow.currentTime - lead.currentTime;
				if ( Math.abs( drift ) > SEEK_DRIFT ) {
					follow.currentTime = lead.currentTime;
				} else if ( Math.abs( drift ) > NUDGE_DRIFT ) {
					follow.playbackRate = drift > 0 ? 0.95 : 1.05;
				} else {
					follow.playbackRate = 1;
				}
			}

			setTime( lead.currentTime );
			frame = window.requestAnimationFrame( tick );
		};
		tick();

		return () => window.cancelAnimationFrame( frame );
	}, [ isPlaying, originalFailed ] );

	const seek = ( value ) => {
		[ optimizedRef.current, originalRef.current ].forEach( ( element ) => {
			if ( element ) {
				element.currentTime = value;
			}
		} );
		setTime( value );
	};

	const togglePlaying = () => {
		if ( ! isPlaying && optimizedRef.current.ended ) {
			seek( 0 );
		}
		setIsPlaying( ! isPlaying );
	};

	const onMetadata = ( key ) => ( event ) => {
		const { videoWidth: width, videoHeight: height } = event.target;
		setDimensions( ( current ) => ( {
			...current,
			[ key ]: width ? { width, height } : null,
		} ) );
		if ( key === 'optimized' ) {
			setDuration( event.target.duration );
		}
	};

	const frameSize = dimensions.optimized ?? {
		width: video.width || 16,
		height: video.height || 9,
	};
	const saving = video.backup_size
		? 1 - video.filesize / video.backup_size
		: 0;

	return (
		<Modal
			className="jcore-pakkaus-compare"
			title={ sprintf(
				/* translators: %s: video title */
				__( 'Compare “%s”', 'jcore-pakkaus' ),
				video.title
			) }
			size="large"
			onRequestClose={ onClose }
		>
			{ originalFailed && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Your browser cannot play the original file, so only the optimized video is shown. Open the original to view it in another player.',
						'jcore-pakkaus'
					) }
				</Notice>
			) }

			<div
				className={ cx(
					'jcore-pakkaus-compare__stage',
					`is-${ layout }`
				) }
				style={ {
					'--ratio': frameSize.width / frameSize.height,
					'--position': `${ position }%`,
				} }
			>
				<figure className="jcore-pakkaus-compare__pane is-original">
					<video
						ref={ originalRef }
						src={ video.backup_url }
						preload="auto"
						muted
						playsInline
						onLoadedMetadata={ onMetadata( 'original' ) }
						onError={ () => setOriginalFailed( true ) }
					/>
					<figcaption>
						{ __( 'Original', 'jcore-pakkaus' ) }
					</figcaption>
				</figure>
				<figure className="jcore-pakkaus-compare__pane is-optimized">
					<video
						ref={ optimizedRef }
						src={ video.url }
						preload="auto"
						muted
						playsInline
						onLoadedMetadata={ onMetadata( 'optimized' ) }
						onEnded={ () => setIsPlaying( false ) }
					/>
					<figcaption>
						{ __( 'Optimized', 'jcore-pakkaus' ) }
					</figcaption>
				</figure>
				{ layout === 'slider' && (
					<>
						<input
							className="jcore-pakkaus-compare__handle"
							type="range"
							min={ 0 }
							max={ 100 }
							step={ 0.5 }
							value={ position }
							aria-label={ __(
								'Divider position',
								'jcore-pakkaus'
							) }
							onChange={ ( event ) =>
								setPosition( Number( event.target.value ) )
							}
						/>
						<span
							className="jcore-pakkaus-compare__divider"
							aria-hidden="true"
						/>
					</>
				) }
			</div>

			<div className="jcore-pakkaus-compare__controls">
				<Button
					size="compact"
					variant="secondary"
					icon={ isPlaying ? pauseIcon : playIcon }
					label={
						isPlaying
							? __( 'Pause', 'jcore-pakkaus' )
							: __( 'Play', 'jcore-pakkaus' )
					}
					onClick={ togglePlaying }
				/>
				<input
					className="jcore-pakkaus-compare__seek"
					type="range"
					min={ 0 }
					max={ duration || 0 }
					step="any"
					value={ Math.min( time, duration || 0 ) }
					disabled={ ! duration }
					aria-label={ __( 'Seek', 'jcore-pakkaus' ) }
					onChange={ ( event ) =>
						seek( Number( event.target.value ) )
					}
				/>
				<span className="jcore-pakkaus-compare__time">
					{ formatTime( time ) } / { formatTime( duration ) }
				</span>
				<div
					className="jcore-pakkaus-compare__modes"
					role="group"
					aria-label={ __( 'Comparison view', 'jcore-pakkaus' ) }
				>
					<Button
						size="compact"
						isPressed={ mode === 'slider' }
						disabled={ originalFailed }
						accessibleWhenDisabled
						onClick={ () => setMode( 'slider' ) }
					>
						{ __( 'Slider', 'jcore-pakkaus' ) }
					</Button>
					<Button
						size="compact"
						isPressed={ mode === 'side' }
						disabled={ originalFailed }
						accessibleWhenDisabled
						onClick={ () => setMode( 'side' ) }
					>
						{ __( 'Side by side', 'jcore-pakkaus' ) }
					</Button>
				</div>
			</div>

			<div className="jcore-pakkaus-compare__footer">
				<dl className="jcore-pakkaus-compare__facts">
					<Fact
						label={ __( 'Original', 'jcore-pakkaus' ) }
						size={ video.backup_size }
						dimensions={ dimensions.original }
					/>
					<Fact
						label={ __( 'Optimized', 'jcore-pakkaus' ) }
						size={ video.filesize }
						dimensions={ dimensions.optimized }
					/>
					{ saving > 0 && (
						<div>
							<dt>{ __( 'Saved', 'jcore-pakkaus' ) }</dt>
							<dd className="is-saving">
								{ formatPercent( saving ) }
							</dd>
						</div>
					) }
				</dl>
				<Button
					className="jcore-pakkaus-compare__open"
					variant="link"
					icon={ external }
					iconPosition="right"
					href={ video.backup_url }
					target="_blank"
					rel="noreferrer"
				>
					{ __( 'Open original', 'jcore-pakkaus' ) }
				</Button>
			</div>
		</Modal>
	);
}
