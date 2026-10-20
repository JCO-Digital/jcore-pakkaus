import { Button, Modal } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { external } from '@wordpress/icons';
import { cx, formatPercent } from '../utils';
import { Fact } from './CompareModal';

/**
 * Shows an image's original next to the version browsers now get: its WebP
 * version, or the recompressed PNG. A draggable divider over one frame, or
 * side by side.
 *
 * @param {Object}   props
 * @param {Object}   props.image   Image as Library::item() returns it, with `compare`.
 * @param {Function} props.onClose Closes the modal.
 */
export default function ImageCompareModal( { image, onClose } ) {
	const { before, after } = image.compare;
	const [ mode, setMode ] = useState( 'slider' );
	const [ position, setPosition ] = useState( 50 );
	const [ dimensions, setDimensions ] = useState( {} );
	const ratio =
		image.width && image.height ? image.width / image.height : 4 / 3;
	const saving = before.size ? 1 - after.size / before.size : 0;

	const onLoad = ( key ) => ( event ) => {
		const { naturalWidth: width, naturalHeight: height } = event.target;
		setDimensions( ( current ) => ( {
			...current,
			[ key ]: width ? { width, height } : null,
		} ) );
	};

	return (
		<Modal
			className="jcore-pakkaus-compare"
			title={ sprintf(
				/* translators: %s: image title */
				__( 'Compare “%s”', 'jcore-pakkaus' ),
				image.title
			) }
			size="large"
			onRequestClose={ onClose }
		>
			<div
				className={ cx(
					'jcore-pakkaus-compare__stage',
					'is-image',
					`is-${ mode }`
				) }
				style={ {
					'--ratio': ratio,
					'--position': `${ position }%`,
				} }
			>
				<figure className="jcore-pakkaus-compare__pane is-original">
					<img
						src={ before.url }
						alt=""
						onLoad={ onLoad( 'before' ) }
					/>
					<figcaption>{ before.label }</figcaption>
				</figure>
				<figure className="jcore-pakkaus-compare__pane is-optimized">
					<img
						src={ after.url }
						alt=""
						onLoad={ onLoad( 'after' ) }
					/>
					<figcaption>{ after.label }</figcaption>
				</figure>
				{ mode === 'slider' && (
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
				<div
					className="jcore-pakkaus-compare__modes"
					role="group"
					aria-label={ __( 'Comparison view', 'jcore-pakkaus' ) }
				>
					<Button
						size="compact"
						isPressed={ mode === 'slider' }
						onClick={ () => setMode( 'slider' ) }
					>
						{ __( 'Slider', 'jcore-pakkaus' ) }
					</Button>
					<Button
						size="compact"
						isPressed={ mode === 'side' }
						onClick={ () => setMode( 'side' ) }
					>
						{ __( 'Side by side', 'jcore-pakkaus' ) }
					</Button>
				</div>
			</div>

			<div className="jcore-pakkaus-compare__footer">
				<dl className="jcore-pakkaus-compare__facts">
					<Fact
						label={ before.label }
						size={ before.size }
						dimensions={ dimensions.before }
					/>
					<Fact
						label={ after.label }
						size={ after.size }
						dimensions={ dimensions.after }
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
					href={ after.url }
					target="_blank"
					rel="noreferrer"
				>
					{ sprintf(
						/* translators: %s: version label, e.g. WebP */
						__( 'Open %s', 'jcore-pakkaus' ),
						after.label
					) }
				</Button>
			</div>
		</Modal>
	);
}
