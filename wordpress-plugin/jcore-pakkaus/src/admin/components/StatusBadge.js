import { __, sprintf } from '@wordpress/i18n';
import { STATUSES } from '../constants';
import { cx, formatPercent } from '../utils';

/**
 * Coloured badge for an optimization status.
 *
 * @param {Object} props
 * @param {string} props.status   Stored status, or `none`.
 * @param {number} props.progress Progress percentage while processing.
 */
export default function StatusBadge( { status, progress } ) {
	const { label, tone } = STATUSES[ status ] ?? STATUSES.none;
	const text =
		status === 'processing' && progress > 0
			? sprintf(
					/* translators: %s: percentage done, e.g. 42 % */
					__( 'Optimizing %s', 'jcore-pakkaus' ),
					formatPercent( progress / 100 )
			  )
			: label;

	return (
		<span className={ cx( 'jcore-pakkaus__badge', `is-${ tone }` ) }>
			{ text }
		</span>
	);
}
