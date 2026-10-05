import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	RangeControl,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __, sprintf } from '@wordpress/i18n';
import { getSettings, saveSettings } from '../api';
import {
	AUDIO_BITRATES,
	CODECS,
	FRAME_RATES,
	PRESETS,
	RESOLUTIONS,
} from '../constants';
import { cx, describeCrf, errorMessage, numberOptions } from '../utils';
import ConnectionDetails from './ConnectionDetails';

// Keys the form edits. The API token is handled apart: it is never sent back.
const EDITABLE = [
	'service_url',
	'auto_optimize',
	'min_file_size_mb',
	'codec',
	'crf',
	'preset',
	'max_resolution',
	'max_fps',
	'audio_bitrate',
	'remove_audio',
	'tonemap_hdr',
	'strip_metadata',
	'keep_original',
	'min_savings_percent',
];

function Section( { title, description, children } ) {
	return (
		<section className="jcore-pakkaus__section">
			<div className="jcore-pakkaus__section-intro">
				<h2>{ title }</h2>
				{ description && <p>{ description }</p> }
			</div>
			<div className="jcore-pakkaus__card jcore-pakkaus__section-body">
				{ children }
			</div>
		</section>
	);
}

function lockedHelp( constant ) {
	return sprintf(
		/* translators: %s: PHP constant name */
		__( 'Set by %s in wp-config.php.', 'jcore-pakkaus' ),
		constant
	);
}

function CodecPicker( { value, available, onChange } ) {
	return (
		<fieldset className="jcore-pakkaus__codecs">
			<legend>{ __( 'Video codec', 'jcore-pakkaus' ) }</legend>
			{ CODECS.map( ( codec ) => {
				const isUnavailable =
					available && ! available.includes( codec.value );
				return (
					<label
						key={ codec.value }
						htmlFor={ `jcore-pakkaus-codec-${ codec.value }` }
						className={ cx(
							'jcore-pakkaus__codec',
							value === codec.value && 'is-selected',
							isUnavailable && 'is-unavailable'
						) }
					>
						<input
							id={ `jcore-pakkaus-codec-${ codec.value }` }
							type="radio"
							name="jcore-pakkaus-codec"
							value={ codec.value }
							checked={ value === codec.value }
							onChange={ () => onChange( codec ) }
						/>
						<span className="jcore-pakkaus__codec-title">
							{ codec.title }
						</span>
						<span className="jcore-pakkaus__codec-description">
							{ codec.description }
						</span>
						{ isUnavailable && (
							<span className="jcore-pakkaus__codec-warning">
								{ __(
									'Not available on the connected service.',
									'jcore-pakkaus'
								) }
							</span>
						) }
					</label>
				);
			} ) }
		</fieldset>
	);
}

/**
 * The Settings tab: connection, automation and encoding options.
 *
 * @param {Object}   props
 * @param {?Object}  props.service        Response of GET /service.
 * @param {boolean}  props.isChecking     Whether a connection check is running.
 * @param {Function} props.onCheckService Re-checks the connection; resolves to the result.
 */
export default function SettingsTab( { service, isChecking, onCheckService } ) {
	const [ saved, setSaved ] = useState( null );
	const [ settings, setSettings ] = useState( null );
	const [ token, setToken ] = useState( '' );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ loadError, setLoadError ] = useState( null );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	useEffect( () => {
		getSettings()
			.then( ( result ) => {
				setSaved( result );
				setSettings( result );
			} )
			.catch( ( caught ) => setLoadError( errorMessage( caught ) ) );
	}, [] );

	const isDirty =
		!! settings &&
		( token !== '' ||
			EDITABLE.some(
				( key ) => String( settings[ key ] ) !== String( saved[ key ] )
			) );

	// Warn before leaving the page with unsaved changes.
	useEffect( () => {
		if ( ! isDirty ) {
			return;
		}
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ isDirty ] );

	if ( loadError ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ loadError }
			</Notice>
		);
	}

	if ( ! settings ) {
		return (
			<div className="jcore-pakkaus__loading">
				<Spinner />
			</div>
		);
	}

	const locked = settings.locked ?? {};
	const update = ( key, value ) =>
		setSettings( ( current ) => ( { ...current, [ key ]: value } ) );
	const toggle = ( key ) => ( checked ) => update( key, checked ? 1 : 0 );

	const save = async () => {
		const data = Object.fromEntries(
			EDITABLE.filter( ( key ) => ! locked[ key ] ).map( ( key ) => [
				key,
				settings[ key ],
			] )
		);
		if ( token && ! locked.api_token ) {
			data.api_token = token;
		}

		setIsSaving( true );
		try {
			const result = await saveSettings( data );
			setSaved( result );
			setSettings( result );
			setToken( '' );
			createSuccessNotice( __( 'Settings saved.', 'jcore-pakkaus' ), {
				type: 'snackbar',
			} );
			return true;
		} catch ( caught ) {
			createErrorNotice( errorMessage( caught ), { type: 'snackbar' } );
			return false;
		} finally {
			setIsSaving( false );
		}
	};

	const testConnection = async () => {
		if ( isDirty && ! ( await save() ) ) {
			return;
		}
		const result = await onCheckService();
		if ( result.connected ) {
			createSuccessNotice(
				__( 'Connected to the optimizer service.', 'jcore-pakkaus' ),
				{ type: 'snackbar' }
			);
		} else {
			createErrorNotice(
				result.error ??
					__(
						'Add the service URL and API token first.',
						'jcore-pakkaus'
					),
				{ type: 'snackbar' }
			);
		}
	};

	const codec = settings.codec;
	const minCrf = codec === 'h264' ? 1 : 0;
	const recommendedCrf = CODECS.find(
		( c ) => c.value === codec
	)?.recommendedCrf;

	return (
		<div className="jcore-pakkaus__settings">
			<Section
				title={ __( 'Optimizer service', 'jcore-pakkaus' ) }
				description={ __(
					'The self-hosted FFmpeg service that does the transcoding. WordPress sends it a link to each video and gets the result back.',
					'jcore-pakkaus'
				) }
			>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Service URL', 'jcore-pakkaus' ) }
					type="url"
					placeholder="https://pakkaus.prototype.bojaco.com"
					value={ settings.service_url }
					disabled={ !! locked.service_url }
					help={
						locked.service_url
							? lockedHelp( locked.service_url )
							: undefined
					}
					onChange={ ( value ) => update( 'service_url', value ) }
				/>
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'API token', 'jcore-pakkaus' ) }
					type="password"
					autoComplete="new-password"
					placeholder={
						settings.api_token_set && ! locked.api_token
							? __(
									'Saved. Leave empty to keep it.',
									'jcore-pakkaus'
								)
							: ''
					}
					value={ token }
					disabled={ !! locked.api_token }
					help={
						locked.api_token
							? lockedHelp( locked.api_token )
							: __(
									'The API_TOKEN configured on the service.',
									'jcore-pakkaus'
								)
					}
					onChange={ setToken }
				/>
				<ConnectionDetails
					service={ service }
					isChecking={ isChecking }
					isDirty={ isDirty }
					isSaving={ isSaving }
					onTest={ testConnection }
				/>
			</Section>

			<Section
				title={ __( 'Automation', 'jcore-pakkaus' ) }
				description={ __(
					'Optimize videos as they are uploaded. Existing videos can be optimized from the Videos tab or the media library.',
					'jcore-pakkaus'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Optimize new uploads', 'jcore-pakkaus' ) }
					checked={ !! settings.auto_optimize }
					onChange={ toggle( 'auto_optimize' ) }
				/>
				{ !! settings.auto_optimize && (
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						className="jcore-pakkaus__short-field"
						label={ __(
							'Skip videos smaller than (MB)',
							'jcore-pakkaus'
						) }
						type="number"
						min={ 0 }
						step={ 0.1 }
						value={ settings.min_file_size_mb }
						help={ __(
							'Small clips gain little and are left as they are.',
							'jcore-pakkaus'
						) }
						onChange={ ( value ) =>
							update( 'min_file_size_mb', value )
						}
					/>
				) }
			</Section>

			<Section
				title={ __( 'Video', 'jcore-pakkaus' ) }
				description={ __(
					'How the video track is encoded. The defaults suit most sites.',
					'jcore-pakkaus'
				) }
			>
				<CodecPicker
					value={ codec }
					available={
						service?.connected ? service.info.codecs : null
					}
					onChange={ ( next ) =>
						setSettings( ( current ) => ( {
							...current,
							codec: next.value,
							crf:
								next.value === 'h264'
									? Math.max( 1, Number( current.crf ) )
									: current.crf,
						} ) )
					}
				/>
				<RangeControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Quality (CRF)', 'jcore-pakkaus' ) }
					min={ minCrf }
					max={ 51 }
					value={ Number( settings.crf ) }
					onChange={ ( value ) =>
						update( 'crf', value ?? recommendedCrf )
					}
					help={ sprintf(
						/* translators: 1: quality description, 2: recommended value */
						__(
							'%1$s. Lower means better quality and larger files; %2$s is a good default for this codec.',
							'jcore-pakkaus'
						),
						describeCrf( Number( settings.crf ), codec ),
						recommendedCrf
					) }
				/>
				<div className="jcore-pakkaus__field-row">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Encoder speed', 'jcore-pakkaus' ) }
						value={ settings.preset }
						options={ PRESETS }
						help={ __(
							'Slower makes smaller files at the same quality, but takes longer.',
							'jcore-pakkaus'
						) }
						onChange={ ( value ) => update( 'preset', value ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Maximum resolution', 'jcore-pakkaus' ) }
						value={ String( settings.max_resolution ) }
						options={ RESOLUTIONS.map( ( option ) => ( {
							...option,
							value: String( option.value ),
						} ) ) }
						help={ __(
							'Measured on the short side, so portrait videos work. Never upscales.',
							'jcore-pakkaus'
						) }
						onChange={ ( value ) =>
							update( 'max_resolution', Number( value ) )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Maximum frame rate', 'jcore-pakkaus' ) }
						value={ String( Number( settings.max_fps ) ) }
						options={ numberOptions(
							FRAME_RATES,
							settings.max_fps,
							( fps ) =>
								fps
									? sprintf(
											/* translators: %s: frames per second */
											__( '%s fps', 'jcore-pakkaus' ),
											fps
										)
									: __( 'Keep original', 'jcore-pakkaus' )
						) }
						onChange={ ( value ) =>
							update( 'max_fps', Number( value ) )
						}
					/>
				</div>
			</Section>

			<Section
				title={ __( 'Audio', 'jcore-pakkaus' ) }
				description={ __(
					'Audio is re-encoded to AAC, or copied as is when it is already small enough.',
					'jcore-pakkaus'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Remove the audio track', 'jcore-pakkaus' ) }
					help={ __(
						'For background and decorative videos that play muted anyway.',
						'jcore-pakkaus'
					) }
					checked={ !! settings.remove_audio }
					onChange={ toggle( 'remove_audio' ) }
				/>
				{ ! settings.remove_audio && (
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						className="jcore-pakkaus__short-field"
						label={ __( 'AAC bitrate', 'jcore-pakkaus' ) }
						value={ String( Number( settings.audio_bitrate ) ) }
						options={ numberOptions(
							AUDIO_BITRATES,
							settings.audio_bitrate,
							( kbps ) =>
								sprintf(
									/* translators: %s: kilobits per second */
									__( '%s kbit/s', 'jcore-pakkaus' ),
									kbps
								)
						) }
						onChange={ ( value ) =>
							update( 'audio_bitrate', Number( value ) )
						}
					/>
				) }
			</Section>

			<Section
				title={ __( 'Files', 'jcore-pakkaus' ) }
				description={ __(
					'What happens to the file around the transcode.',
					'jcore-pakkaus'
				) }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Convert HDR to SDR', 'jcore-pakkaus' ) }
					help={ __(
						'HDR videos, like the ones iPhones record, look washed out in most browsers otherwise.',
						'jcore-pakkaus'
					) }
					checked={ !! settings.tonemap_hdr }
					onChange={ toggle( 'tonemap_hdr' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Strip metadata', 'jcore-pakkaus' ) }
					help={ __(
						'Removes GPS location, device details and similar from the file.',
						'jcore-pakkaus'
					) }
					checked={ !! settings.strip_metadata }
					onChange={ toggle( 'strip_metadata' ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Keep a backup of the original',
						'jcore-pakkaus'
					) }
					help={ __(
						'Lets you restore the original later, at the cost of disk space.',
						'jcore-pakkaus'
					) }
					checked={ !! settings.keep_original }
					onChange={ toggle( 'keep_original' ) }
				/>
				<RangeControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Minimum saving (%)', 'jcore-pakkaus' ) }
					min={ 0 }
					max={ Math.max(
						50,
						Number( settings.min_savings_percent )
					) }
					value={ Number( settings.min_savings_percent ) }
					help={ __(
						'The original is kept when the optimized file is not at least this much smaller.',
						'jcore-pakkaus'
					) }
					onChange={ ( value ) =>
						update( 'min_savings_percent', value ?? 0 )
					}
				/>
			</Section>

			<div
				className={ cx(
					'jcore-pakkaus__savebar',
					isDirty && 'is-dirty'
				) }
			>
				<span className="jcore-pakkaus__savebar-status">
					{ isDirty
						? __( 'You have unsaved changes.', 'jcore-pakkaus' )
						: __( 'All changes saved.', 'jcore-pakkaus' ) }
				</span>
				{ isDirty && (
					<Button
						variant="tertiary"
						onClick={ () => {
							setSettings( saved );
							setToken( '' );
						} }
					>
						{ __( 'Discard', 'jcore-pakkaus' ) }
					</Button>
				) }
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving || ! isDirty }
					accessibleWhenDisabled
					onClick={ save }
				>
					{ __( 'Save settings', 'jcore-pakkaus' ) }
				</Button>
			</div>
		</div>
	);
}
