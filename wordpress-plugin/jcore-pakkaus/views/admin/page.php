<?php
/**
 * Mount point for the Video Optimizer app.
 *
 * The app renders its own `.wrap` container, so this element stays bare.
 *
 * @package Jcore\Pakkaus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div id="jcore-pakkaus-app"></div>
<noscript>
	<div class="wrap">
		<p><?php esc_html_e( 'The Video Optimizer screen needs JavaScript.', 'jcore-pakkaus' ); ?></p>
	</div>
</noscript>
