import { __, sprintf } from '@wordpress/i18n';
import { cx } from '../utils';

/**
 * Connection state of the optimizer service, as a pill in the page header.
 * Clicking it opens the settings, where the connection is configured.
 *
 * @param {Object}   props
 * @param {?Object}  props.service    Response of GET /service.
 * @param {boolean}  props.isChecking Whether a check is running.
 * @param {Function} props.onClick    Opens the settings tab.
 */
export default function ServiceStatus( { service, isChecking, onClick } ) {
	let tone = 'neutral';
	let label = __( 'Checking service…', 'jcore-pakkaus' );
	let detail = '';

	if ( service && ! service.configured ) {
		label = __( 'Not connected', 'jcore-pakkaus' );
		detail = __( 'Set up the optimizer service', 'jcore-pakkaus' );
	} else if ( service && ! service.connected ) {
		tone = 'error';
		label = __( 'Service unreachable', 'jcore-pakkaus' );
		detail = service.error ?? '';
	} else if ( service ) {
		tone = 'success';
		label = __( 'Connected', 'jcore-pakkaus' );
		detail = sprintf(
			/* translators: %s: optimizer service version */
			__( 'Optimizer %s', 'jcore-pakkaus' ),
			service.info?.version ?? ''
		);
	}

	return (
		<button
			type="button"
			className={ cx(
				'jcore-pakkaus__service',
				`is-${ tone }`,
				isChecking && 'is-checking'
			) }
			onClick={ onClick }
			title={ detail }
		>
			<span className="jcore-pakkaus__service-dot" aria-hidden="true" />
			<span className="jcore-pakkaus__service-label">{ label }</span>
			{ detail && (
				<span className="jcore-pakkaus__service-detail">
					{ detail }
				</span>
			) }
		</button>
	);
}
