import { __, _n, sprintf } from '@wordpress/i18n';
import { Icon, published, trendingDown, update, video } from '@wordpress/icons';
import { cx, formatBytes, formatNumber, formatPercent } from '../utils';

function Stat( { icon, label, value, note, noteTone, meter, tone, isLive } ) {
	return (
		<div className={ cx( 'jcore-pakkaus__stat', `is-${ tone }` ) }>
			<div className="jcore-pakkaus__stat-head">
				<span className="jcore-pakkaus__stat-icon" aria-hidden="true">
					<Icon icon={ icon } size={ 20 } />
				</span>
				<span className="jcore-pakkaus__stat-label">{ label }</span>
				{ isLive && (
					<span
						className="jcore-pakkaus__live"
						title={ __( 'Updating live', 'jcore-pakkaus' ) }
					/>
				) }
			</div>
			<div className="jcore-pakkaus__stat-value">{ value }</div>
			{ meter !== undefined && (
				<div className="jcore-pakkaus__meter" aria-hidden="true">
					<span
						style={ {
							width: `${ Math.round( meter * 100 ) }%`,
						} }
					/>
				</div>
			) }
			<div
				className={ cx(
					'jcore-pakkaus__stat-note',
					noteTone && `is-${ noteTone }`
				) }
			>
				{ note }
			</div>
		</div>
	);
}

/**
 * The library at a glance: how many videos, how many are done, and what it saved.
 *
 * @param {Object} props
 * @param {Object} props.summary The `summary` of GET /videos.
 */
export default function StatCards( { summary } ) {
	const { counts } = summary;
	const original = summary.original_size;
	const optimized = summary.optimized_size;
	const saved = Math.max( 0, original - optimized );
	const coverage = counts.all
		? ( counts.optimized + counts.skipped ) / counts.all
		: 0;

	return (
		<div className="jcore-pakkaus__stats">
			<Stat
				tone="neutral"
				icon={ video }
				label={ __( 'Videos', 'jcore-pakkaus' ) }
				value={ formatNumber( counts.all ) }
				note={ sprintf(
					/* translators: %s: number of videos */
					_n(
						'%s not optimized',
						'%s not optimized',
						counts.unoptimized,
						'jcore-pakkaus'
					),
					formatNumber( counts.unoptimized )
				) }
			/>
			<Stat
				tone="success"
				icon={ published }
				label={ __( 'Optimized', 'jcore-pakkaus' ) }
				value={ formatNumber( counts.optimized ) }
				meter={ coverage }
				note={ sprintf(
					/* translators: %s: percentage of the library */
					__( '%s of the library processed', 'jcore-pakkaus' ),
					formatPercent( coverage )
				) }
			/>
			<Stat
				tone="accent"
				icon={ trendingDown }
				label={ __( 'Space saved', 'jcore-pakkaus' ) }
				value={ formatBytes( saved ) }
				note={
					original
						? sprintf(
								/* translators: 1: percentage, 2: size before, 3: size after */
								__(
									'%1$s smaller, %2$s → %3$s',
									'jcore-pakkaus'
								),
								formatPercent( saved / original ),
								formatBytes( original ),
								formatBytes( optimized )
						  )
						: __( 'Nothing optimized yet', 'jcore-pakkaus' )
				}
			/>
			<Stat
				tone="info"
				icon={ update }
				label={ __( 'In progress', 'jcore-pakkaus' ) }
				value={ formatNumber( counts.active ) }
				isLive={ counts.active > 0 }
				noteTone={ counts.failed ? 'error' : undefined }
				note={
					counts.failed
						? sprintf(
								/* translators: %s: number of videos */
								_n(
									'%s failed',
									'%s failed',
									counts.failed,
									'jcore-pakkaus'
								),
								formatNumber( counts.failed )
						  )
						: __( 'No failures', 'jcore-pakkaus' )
				}
			/>
		</div>
	);
}
