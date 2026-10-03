import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { check, copy } from '@wordpress/icons';
import { cx, formatNumber } from '../utils';

function CallbackUrl( { url } ) {
	const [ isCopied, setIsCopied ] = useState( false );

	const copyUrl = async () => {
		try {
			await window.navigator.clipboard.writeText( url );
			setIsCopied( true );
			setTimeout( () => setIsCopied( false ), 2000 );
		} catch {
			// Clipboard access denied; the URL is still there to select.
		}
	};

	return (
		<div className="jcore-pakkaus__callback">
			<p>
				{ __(
					'The service must be able to download files from this site and send results to:',
					'jcore-pakkaus'
				) }
			</p>
			<div className="jcore-pakkaus__callback-url">
				<code>{ url }</code>
				<Button
					size="small"
					icon={ isCopied ? check : copy }
					label={
						isCopied
							? __( 'Copied', 'jcore-pakkaus' )
							: __( 'Copy URL', 'jcore-pakkaus' )
					}
					onClick={ copyUrl }
				/>
			</div>
		</div>
	);
}

/**
 * Result of the last connection check, and the button to run one.
 *
 * @param {Object}   props
 * @param {?Object}  props.service    Response of GET /service.
 * @param {boolean}  props.isChecking Whether a check is running.
 * @param {boolean}  props.isDirty    Whether the form has unsaved changes.
 * @param {boolean}  props.isSaving   Whether the form is being saved.
 * @param {Function} props.onTest     Saves if needed, then checks.
 */
export default function ConnectionDetails( {
	service,
	isChecking,
	isDirty,
	isSaving,
	onTest,
} ) {
	let tone = 'neutral';
	let title = __( 'Checking the connection…', 'jcore-pakkaus' );
	let body = null;

	if ( service && ! service.configured ) {
		title = __( 'Not connected yet', 'jcore-pakkaus' );
		body = (
			<p>
				{ __(
					'Enter the URL and the API token of your optimizer service, then test the connection.',
					'jcore-pakkaus'
				) }
			</p>
		);
	} else if ( service && ! service.connected ) {
		tone = 'error';
		title = __( 'Could not connect', 'jcore-pakkaus' );
		body = (
			<p className="jcore-pakkaus__connection-error">{ service.error }</p>
		);
	} else if ( service ) {
		const { info } = service;
		tone = 'success';
		title = sprintf(
			/* translators: %s: optimizer service version */
			__( 'Connected to optimizer %s', 'jcore-pakkaus' ),
			info.version
		);
		body = (
			<dl className="jcore-pakkaus__facts">
				<dt>{ __( 'FFmpeg', 'jcore-pakkaus' ) }</dt>
				<dd>{ info.ffmpeg }</dd>
				<dt>{ __( 'Codecs', 'jcore-pakkaus' ) }</dt>
				<dd>
					{ info.codecs.map( ( codec ) => (
						<span key={ codec } className="jcore-pakkaus__chip">
							{ codec === 'h265' ? 'H.265' : 'H.264' }
						</span>
					) ) }
					{ info.hdr_tonemapping && (
						<span className="jcore-pakkaus__chip">
							{ __( 'HDR tonemapping', 'jcore-pakkaus' ) }
						</span>
					) }
				</dd>
				<dt>{ __( 'Queue', 'jcore-pakkaus' ) }</dt>
				<dd>
					{ sprintf(
						/* translators: 1: running jobs, 2: waiting jobs, 3: parallel workers */
						__(
							'%1$s running, %2$s waiting (%3$s at a time)',
							'jcore-pakkaus'
						),
						formatNumber( info.queue.active ),
						formatNumber( info.queue.waiting ),
						formatNumber( info.workers )
					) }
				</dd>
			</dl>
		);
	}

	return (
		<div className={ cx( 'jcore-pakkaus__connection', `is-${ tone }` ) }>
			<div className="jcore-pakkaus__connection-head">
				<span
					className="jcore-pakkaus__service-dot"
					aria-hidden="true"
				/>
				<strong>{ title }</strong>
				<Button
					variant="secondary"
					size="compact"
					isBusy={ isChecking || isSaving }
					disabled={ isChecking || isSaving }
					accessibleWhenDisabled
					onClick={ onTest }
				>
					{ isDirty
						? __( 'Save and test', 'jcore-pakkaus' )
						: __( 'Test connection', 'jcore-pakkaus' ) }
				</Button>
			</div>
			{ body }
			{ service?.callback_url && (
				<CallbackUrl url={ service.callback_url } />
			) }
		</div>
	);
}
