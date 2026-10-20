<?php
/**
 * REST routes for the video list and its actions.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists videos and starts or undoes their optimization.
 */
final class Videos_Controller extends Library_Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'videos';

	/**
	 * Media type the routes cover.
	 *
	 * @var string
	 */
	protected $type = 'video';
}
