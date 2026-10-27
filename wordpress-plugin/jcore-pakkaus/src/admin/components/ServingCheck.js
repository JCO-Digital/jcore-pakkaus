import { useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { check, copy } from '@wordpress/icons';
import { checkServing } from '../api';
import { NGINX_WEBP } from '../constants';
import { errorMessage } from '../utils';

/**
 * What a result of GET /images/serving means, as a notice.
 *
 * @param {Object} report Result of the check.
 * @return {{status: string, text: string}} Notice status and text.
 */
function describe( report ) {
	switch ( report.status ) {
		case 'ok':
			return {
				status: 'success',
				text: __(
					'Browsers that support WebP get the WebP versions, the rest get the originals.',
					'jcore-pakkaus'
				),
			};
		case 'no_vary':
			return {
				status: 'warning',
				text: __(
					'WebP versions are sent, but without the Vary: Accept header. Caches and CDNs in front of the site may then give WebP to browsers that cannot show it.',
					'jcore-pakkaus'
				),
			};
		case 'broken':
			return {
				status: 'error',
				text: __(
					'Browsers that do not support WebP get WebP too. Check the rules below.',
					'jcore-pakkaus'
				),
			};
		case 'not_configured':
			return {
				status: 'warning',
				text: sprintf(
					/* translators: %s: MIME type, e.g. image/jpeg */
					__(
						'The server answers with %s even for browsers that support WebP, so the WebP versions are not used yet. Add the rules below to the Nginx configuration.',
						'jcore-pakkaus'
					),
					report.webp_type || __( 'an unknown type', 'jcore-pakkaus' )
				),
			};
		case 'error':
			return {
				status: 'error',
				text: sprintf(
					/* translators: 1: URL, 2: error message */
					__( 'Could not request %1$s: %2$s', 'jcore-pakkaus' ),
					report.url,
					report.error
				),
			};
		default:
			return {
				status: 'info',
				text: __(
					'There is nothing to test with yet: optimize an image first, so it has a WebP version.',
					'jcore-pakkaus'
				),
			};
	}
}

/**
 * The web server rules that hand out the WebP versions, and a check that they work.
 *
 * @param {Object}   props
 * @param {Function} props.onOpenWebpTest Switches to the WebP test tab.
 */
export default function ServingCheck( { onOpenWebpTest } ) {
	const [ report, setReport ] = useState( null );
	const [ isChecking, setIsChecking ] = useState( false );
	const [ isCopied, setIsCopied ] = useState( false );

	const run = async () => {
		setIsChecking( true );
		try {
			setReport( await checkServing() );
		} catch ( caught ) {
			setReport( {
				status: 'error',
				url: '',
				error: errorMessage( caught ),
			} );
		}
		setIsChecking( false );
	};

	const copyRules = async () => {
		try {
			await window.navigator.clipboard.writeText( NGINX_WEBP );
			setIsCopied( true );
			setTimeout( () => setIsCopied( false ), 2000 );
		} catch {
			// Clipboard access denied; the rules are still there to select.
		}
	};

	const result = report && describe( report );

	return (
		<div className="jcore-pakkaus__serving">
			<div className="jcore-pakkaus__code">
				<pre>
					<code>{ NGINX_WEBP }</code>
				</pre>
				<Button
					size="small"
					icon={ isCopied ? check : copy }
					label={
						isCopied
							? __( 'Copied', 'jcore-pakkaus' )
							: __( 'Copy the rules', 'jcore-pakkaus' )
					}
					onClick={ copyRules }
				/>
			</div>
			<p className="jcore-pakkaus__help">
				{ __(
					'The location block must come before other locations that match images, and repeat their caching headers: add_header in a location replaces the ones it would otherwise inherit. Adjust the path if uploads live elsewhere.',
					'jcore-pakkaus'
				) }
			</p>
			<div className="jcore-pakkaus__serving-check">
				<Button
					variant="secondary"
					isBusy={ isChecking }
					disabled={ isChecking }
					accessibleWhenDisabled
					onClick={ run }
				>
					{ __( 'Check delivery', 'jcore-pakkaus' ) }
				</Button>
				<Button variant="tertiary" onClick={ onOpenWebpTest }>
					{ __( 'Test in this browser', 'jcore-pakkaus' ) }
				</Button>
			</div>
			{ result && (
				<Notice status={ result.status } isDismissible={ false }>
					{ result.text }
				</Notice>
			) }
		</div>
	);
}
