<?php
/**
 * Job lifecycle: submit, poll, finalize, restore.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attachment statuses (stored in the _jcore_pakkaus_status meta):
 *
 * - pending:    waiting to be (re)submitted to the service
 * - queued:     accepted by the service, waiting for a worker
 * - processing: being downloaded/transcoded by the service, or the result is being swapped in
 * - optimized:  the attachment file was replaced with the optimized version
 * - skipped:    the result was not smaller enough; the original was kept
 * - failed:     something went wrong; see _jcore_pakkaus_message
 * - restored:   the original file was restored from the backup
 */
final class Processor {

	public const META_PREFIX   = '_jcore_pakkaus_';
	public const META_STATUS   = self::META_PREFIX . 'status';
	public const META_JOB      = self::META_PREFIX . 'job_id';
	public const META_SECRET   = self::META_PREFIX . 'secret';
	public const META_PROGRESS = self::META_PREFIX . 'progress';
	public const META_MESSAGE  = self::META_PREFIX . 'message';
	public const META_ATTEMPTS = self::META_PREFIX . 'attempts';
	public const META_STATS    = self::META_PREFIX . 'stats';
	public const META_BACKUP   = self::META_PREFIX . 'backup';
	public const META_UPDATED  = self::META_PREFIX . 'updated';

	public const CRON_POLL     = 'jcore_pakkaus_poll';
	public const CRON_FINALIZE = 'jcore_pakkaus_finalize';
	private const SCHEDULE     = 'jcore_pakkaus_minute';

	public const ACTIVE_STATUSES = array( 'pending', 'queued', 'processing' );
	public const RUNNING         = array( 'queued', 'processing' );
	private const MAX_ATTEMPTS   = 5;
	private const POLL_BATCH     = 20;

	/**
	 * True while the plugin regenerates attachment metadata itself, so the upload hook doesn't fire again.
	 *
	 * @var bool
	 */
	private static $regenerating = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- only scheduled while jobs are in flight.
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 20, 3 );
		add_action( self::CRON_POLL, array( __CLASS__, 'poll' ) );
		add_action( self::CRON_FINALIZE, array( __CLASS__, 'finalize' ) );
		add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );
	}

	/**
	 * Activation: resume polling if there are unfinished jobs.
	 */
	public static function activate() {
		if ( self::active_attachment_ids( 1 ) ) {
			self::ensure_polling();
		}
	}

	/**
	 * Deactivation: stop cron events.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_POLL );
		wp_unschedule_hook( self::CRON_FINALIZE ); // Scheduled per attachment, with arguments.
	}

	/**
	 * Add a one-minute cron schedule (only used while jobs are in flight).
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (JCORE Pakkaus)', 'jcore-pakkaus' ),
		);
		return $schedules;
	}

	/**
	 * Whether the attachment is a video.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function is_video( $id ) {
		return 0 === strpos( (string) get_post_mime_type( $id ), 'video/' );
	}

	/**
	 * Current optimization status ('' if never optimized).
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function status( $id ) {
		return (string) get_post_meta( $id, self::META_STATUS, true );
	}

	/**
	 * Auto-optimize new uploads. Runs at the end of metadata generation, i.e. after the file is in place.
	 *
	 * @param array  $metadata Attachment metadata.
	 * @param int    $id       Attachment ID.
	 * @param string $context  'create' for new uploads.
	 * @return array
	 */
	public static function on_generate_metadata( $metadata, $id, $context = 'create' ) {
		if ( self::$regenerating || 'create' !== $context ) {
			return $metadata;
		}
		if ( ! Settings::get( 'auto_optimize' ) || ! Settings::is_configured() ) {
			return $metadata;
		}
		if ( ! self::is_video( $id ) || '' !== self::status( $id ) ) {
			return $metadata;
		}

		$file     = get_attached_file( $id );
		$min_size = (float) Settings::get( 'min_file_size_mb' ) * MB_IN_BYTES;
		if ( ! $file || ! file_exists( $file ) || filesize( $file ) < $min_size ) {
			return $metadata;
		}

		/**
		 * Filters whether a freshly uploaded video should be optimized automatically.
		 *
		 * @param bool $optimize Whether to optimize.
		 * @param int  $id       Attachment ID.
		 */
		if ( apply_filters( 'jcore_pakkaus_should_optimize', true, $id ) ) {
			self::queue( $id );
		}

		return $metadata;
	}

	/**
	 * Start (or restart) optimization for an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return true|\WP_Error
	 */
	public static function queue( $id ) {
		if ( ! self::is_video( $id ) ) {
			return new \WP_Error( 'jcore_pakkaus_not_video', __( 'This attachment is not a video.', 'jcore-pakkaus' ) );
		}
		if ( in_array( self::status( $id ), self::RUNNING, true ) ) {
			return new \WP_Error( 'jcore_pakkaus_in_progress', __( 'This video is already being optimized.', 'jcore-pakkaus' ) );
		}

		delete_post_meta( $id, self::META_JOB );
		update_post_meta( $id, self::META_ATTEMPTS, 0 );
		self::set_state( $id, 'pending', array( 'message' => '' ) );

		return self::submit( $id );
	}

	/**
	 * Mark an attachment for optimization without contacting the service. The
	 * poller submits pending attachments in batches, so this suits bulk actions.
	 *
	 * @param int $id Attachment ID.
	 * @return bool Whether the attachment was marked.
	 */
	public static function enqueue( $id ) {
		if ( ! self::is_video( $id ) || in_array( self::status( $id ), self::ACTIVE_STATUSES, true ) ) {
			return false;
		}

		delete_post_meta( $id, self::META_JOB );
		update_post_meta( $id, self::META_ATTEMPTS, 0 );
		self::set_state(
			$id,
			'pending',
			array(
				'progress' => 0,
				'message'  => '',
			)
		);
		self::ensure_polling();

		return true;
	}

	/**
	 * Send the job to the service.
	 *
	 * @param int $id Attachment ID.
	 * @return true|\WP_Error
	 */
	public static function submit( $id ) {
		$secret  = wp_generate_password( 40, false );
		$payload = array(
			'source_url'      => wp_get_attachment_url( $id ),
			'callback_url'    => Rest\Callback_Controller::url(),
			'callback_secret' => $secret,
			'options'         => Settings::job_options(),
			'metadata'        => array(
				'attachment_id' => (int) $id,
				'site'          => home_url( '/' ),
			),
		);

		/**
		 * Filters the job payload sent to the optimizer service.
		 *
		 * @param array $payload Payload.
		 * @param int   $id      Attachment ID.
		 */
		$payload = apply_filters( 'jcore_pakkaus_job_payload', $payload, $id );

		$job = Client::create_job( $payload );

		if ( is_wp_error( $job ) ) {
			$attempts = (int) get_post_meta( $id, self::META_ATTEMPTS, true ) + 1;
			update_post_meta( $id, self::META_ATTEMPTS, $attempts );
			$status = get_post_meta( $id, self::META_STATUS, true );
			$final  = $attempts >= self::MAX_ATTEMPTS || in_array( $job->get_error_code(), array( 'jcore_pakkaus_http_401', 'jcore_pakkaus_http_422' ), true );
			self::set_state(
				$id,
				$final ? 'failed' : ( $status ? $status : 'pending' ),
				array(
					/* translators: %s: error message */
					'message' => $final ? $job->get_error_message() : sprintf( __( 'Could not reach the optimizer service, will retry: %s', 'jcore-pakkaus' ), $job->get_error_message() ),
				)
			);
			if ( ! $final ) {
				self::ensure_polling();
			}
			return $job;
		}

		update_post_meta( $id, self::META_JOB, sanitize_text_field( $job['id'] ) );
		update_post_meta( $id, self::META_SECRET, $secret );
		self::set_state(
			$id,
			'queued',
			array(
				'progress' => 0,
				'message'  => '',
			)
		);
		self::ensure_polling();

		return true;
	}

	/**
	 * Cron: sync in-flight attachments with the service. Unschedules itself when nothing is left.
	 */
	public static function poll() {
		$ids = self::active_attachment_ids( self::POLL_BATCH );
		if ( ! $ids ) {
			wp_clear_scheduled_hook( self::CRON_POLL );
			return;
		}
		foreach ( $ids as $id ) {
			self::refresh( $id );
		}
	}

	/**
	 * Sync one attachment with the service.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function refresh( $id ) {
		if ( ! self::lock( $id ) ) {
			return;
		}
		try {
			wp_cache_delete( $id, 'post_meta' );
			$status = self::status( $id );
			if ( ! in_array( $status, self::ACTIVE_STATUSES, true ) ) {
				return;
			}
			// Advance every attempt, including network failures, so later jobs get polled.
			update_post_meta( $id, self::META_UPDATED, microtime( true ) );
			if ( 'pending' === $status ) {
				self::submit( $id );
				return;
			}
			$job_id = get_post_meta( $id, self::META_JOB, true );
			if ( ! $job_id ) {
				self::set_state( $id, 'failed', array( 'message' => __( 'Lost track of the optimization job.', 'jcore-pakkaus' ) ) );
				return;
			}
		} finally {
			self::unlock( $id );
		}

		// Fetch outside the mutex; applying the response checks the current job again.
		$job = Client::get_job( $job_id );
		if ( is_wp_error( $job ) ) {
			if ( 'jcore_pakkaus_http_404' === $job->get_error_code() && self::lock( $id ) ) {
				try {
					if ( self::is_current_job( $id, array( 'id' => $job_id ) ) ) {
						self::set_state( $id, 'failed', array( 'message' => __( 'The optimization job no longer exists on the service.', 'jcore-pakkaus' ) ) );
					}
				} finally {
					self::unlock( $id );
				}
			}
			return; // Otherwise a transient error; try again next poll.
		}

		self::apply_job( $id, $job );
	}

	/**
	 * Apply a job state reported by the service (via poll or callback).
	 *
	 * @param int   $id  Attachment ID.
	 * @param array $job Job from the service.
	 */
	public static function apply_job( $id, $job ) {
		if ( 'completed' === $job['status'] ) {
			self::finalize( $id, $job );
			return;
		}
		if ( ! self::lock( $id ) ) {
			return;
		}
		try {
			if ( self::is_current_job( $id, $job ) ) {
				self::apply_job_state( $id, $job );
			}
		} finally {
			self::unlock( $id );
		}
	}

	/**
	 * Check fresh attachment state while holding its mutex.
	 *
	 * @param int   $id  Attachment ID.
	 * @param array $job Job from the service.
	 * @return bool
	 */
	private static function is_current_job( $id, $job ) {
		wp_cache_delete( $id, 'post_meta' );
		$job_id = get_post_meta( $id, self::META_JOB, true );
		return $job_id && isset( $job['id'] ) && $job_id === $job['id']
			&& in_array( self::status( $id ), self::RUNNING, true );
	}

	/**
	 * Apply a non-completed state while already holding the attachment mutex.
	 *
	 * @param int   $id  Attachment ID.
	 * @param array $job Job from the service.
	 */
	private static function apply_job_state( $id, $job ) {
		$message = isset( $job['message'] ) ? (string) $job['message'] : '';

		switch ( $job['status'] ) {
			case 'queued':
				self::set_state( $id, 'queued', array( 'progress' => 0 ) );
				break;

			case 'downloading':
			case 'processing':
				self::set_state( $id, 'processing', array( 'progress' => (float) $job['progress'] ) );
				break;

			case 'skipped':
				self::save_stats( $id, $job, null );
				self::set_state(
					$id,
					'skipped',
					array(
						'progress' => 100,
						'message'  => $message,
					)
				);
				self::forget_job( $id );
				break;

			case 'failed':
			default:
				self::set_state( $id, 'failed', array( 'message' => $message ? $message : __( 'Optimization failed.', 'jcore-pakkaus' ) ) );
				self::forget_job( $id );
				break;
		}
	}

	/**
	 * Called by the REST callback once the signature has been verified.
	 *
	 * @param int   $id  Attachment ID.
	 * @param array $job Job from the callback body.
	 */
	public static function handle_callback( $id, $job ) {
		if ( 'completed' !== $job['status'] ) {
			self::apply_job( $id, $job );
			return;
		}

		// Downloading the result can take a while; don't make the service wait for it.
		if ( ! self::lock( $id ) ) {
			return;
		}
		try {
			if ( ! self::is_current_job( $id, $job ) ) {
				return;
			}
			self::set_state(
				$id,
				'processing',
				array(
					'progress' => 100,
					'message'  => __( 'Downloading the optimized file…', 'jcore-pakkaus' ),
				)
			);
			wp_schedule_single_event( time(), self::CRON_FINALIZE, array( (int) $id ) );
		} finally {
			self::unlock( $id );
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			add_action(
				'shutdown',
				static function () use ( $id ) {
					fastcgi_finish_request();
					self::finalize( $id );
				}
			);
		} else {
			spawn_cron();
		}
	}

	/**
	 * Download the optimized file and swap it in.
	 *
	 * @param int        $id  Attachment ID.
	 * @param array|null $job Job data if already fetched.
	 * @return true|\WP_Error|null Null when there was nothing to do.
	 */
	public static function finalize( $id, $job = null ) {
		$id = (int) $id;
		if ( ! self::lock( $id ) ) {
			return null;
		}

		try {
			wp_cache_delete( $id, 'post_meta' );
			if ( ! in_array( self::status( $id ), self::RUNNING, true ) ) {
				return null;
			}

			$job_id = get_post_meta( $id, self::META_JOB, true );
			if ( ! is_array( $job ) ) {
				$job = Client::get_job( $job_id );
				if ( is_wp_error( $job ) ) {
					self::ensure_polling();
					return $job;
				}
			}
			if ( ! $job_id || $job['id'] !== $job_id ) {
				return null;
			}
			if ( 'completed' !== $job['status'] ) {
				self::apply_job_state( $id, $job );
				return null;
			}

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- downloading a large video can outlast max_execution_time.
			}

			$result = self::replace_file( $id, $job );
			if ( is_wp_error( $result ) ) {
				if ( 'jcore_pakkaus_download_retry' === $result->get_error_code() ) {
					self::set_state(
						$id,
						'processing',
						array(
							'progress' => 100,
							/* translators: %s: error message */
							'message'  => sprintf( __( 'Could not download the optimized video, will retry: %s', 'jcore-pakkaus' ), $result->get_error_message() ),
						)
					);
					self::ensure_polling();
					return $result;
				}
				self::set_state( $id, 'failed', array( 'message' => $result->get_error_message() ) );
				self::forget_job( $id );
				return $result;
			}

			self::save_stats( $id, $job, $result );
			self::set_state(
				$id,
				'optimized',
				array(
					'progress' => 100,
					'message'  => '',
				)
			);
			self::forget_job( $id );

			/**
			 * Fires after an attachment's file was replaced with the optimized version.
			 *
			 * @param int   $id     Attachment ID.
			 * @param array $result Original/optimized sizes and paths.
			 * @param array $job    Job data from the service.
			 */
			do_action( 'jcore_pakkaus_optimized', $id, $result, $job );

			return true;
		} finally {
			self::unlock( $id );
		}
	}

	/**
	 * Replace the attachment file with the optimized output.
	 *
	 * @param int   $id  Attachment ID.
	 * @param array $job Completed job.
	 * @return array|\WP_Error
	 */
	private static function replace_file( $id, $job ) {
		$current = get_attached_file( $id );
		if ( ! $current || ! file_exists( $current ) ) {
			return new \WP_Error( 'jcore_pakkaus_missing_file', __( 'The original file no longer exists.', 'jcore-pakkaus' ) );
		}

		$dir           = dirname( $current );
		$info          = pathinfo( $current );
		$extension     = isset( $info['extension'] ) ? $info['extension'] : '';
		$original_size = filesize( $current );
		$old_mime      = get_post_mime_type( $id );
		$old_url       = wp_get_attachment_url( $id );
		$tmp           = $dir . '/.jcore-pakkaus-' . $id . '-' . wp_generate_password( 8, false ) . '.part';

		$downloaded = Client::download_output( $job['id'], $tmp );
		if ( is_wp_error( $downloaded ) ) {
			return $downloaded;
		}

		clearstatcache( true, $tmp );
		$size     = (int) filesize( $tmp );
		$expected = isset( $job['output']['size'] ) ? (int) $job['output']['size'] : 0;
		if ( ( $expected && $size !== $expected ) || ! self::looks_like_mp4( $tmp ) ) {
			wp_delete_file( $tmp );
			return new \WP_Error( 'jcore_pakkaus_download_retry', __( 'The downloaded file is incomplete or not an MP4.', 'jcore-pakkaus' ) );
		}

		$same_path = 'mp4' === strtolower( $extension );
		$target    = $same_path ? $current : $dir . '/' . wp_unique_filename( $dir, $info['filename'] . '.mp4' );

		// Keep the very first original only; re-optimizing doesn't back up an already optimized file.
		$backup        = self::backup_path( $id );
		$create_backup = Settings::get( 'keep_original' ) && ! $backup;
		if ( $create_backup ) {
			if ( $same_path ) {
				$backup = $dir . '/' . wp_unique_filename( $dir, $info['filename'] . '.original.' . $extension );
				if ( ! @rename( $current, $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic within the uploads directory.
					wp_delete_file( $tmp );
					return new \WP_Error( 'jcore_pakkaus_backup_failed', __( 'Could not back up the original file.', 'jcore-pakkaus' ) );
				}
			} else {
				$backup = $current; // The original simply stays where it is.
			}
		}

		if ( ! @rename( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic within the uploads directory.
			wp_delete_file( $tmp );
			if ( $create_backup && $same_path ) {
				@rename( $backup, $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			}
			return new \WP_Error( 'jcore_pakkaus_replace_failed', __( 'Could not move the optimized file into place.', 'jcore-pakkaus' ) );
		}

		$stat = stat( $dir );
		@chmod( $target, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same permissions core gives uploads.

		if ( $create_backup ) {
			update_post_meta(
				$id,
				self::META_BACKUP,
				array(
					'file'     => _wp_relative_upload_path( $backup ),
					'attached' => _wp_relative_upload_path( $current ),
					'mime'     => $old_mime,
				)
			);
		}

		if ( $target !== $current ) {
			update_attached_file( $id, $target );
			self::set_mime( $id, 'video/mp4' );
			if ( $backup !== $current ) {
				wp_delete_file( $current );
			}
			self::replace_url_in_content( $old_url, wp_get_attachment_url( $id ) );
		}

		self::regenerate_metadata( $id );

		return array(
			'original_size'  => (int) $original_size,
			'optimized_size' => $size,
			'file'           => _wp_relative_upload_path( $target ),
		);
	}

	/**
	 * Restore the backed-up original file.
	 *
	 * @param int $id Attachment ID.
	 * @return true|\WP_Error
	 */
	public static function restore( $id ) {
		$backup = self::backup_path( $id );
		$meta   = get_post_meta( $id, self::META_BACKUP, true );
		if ( ! $backup ) {
			return new \WP_Error( 'jcore_pakkaus_no_backup', __( 'No backup of the original file exists.', 'jcore-pakkaus' ) );
		}
		if ( in_array( self::status( $id ), self::RUNNING, true ) ) {
			return new \WP_Error( 'jcore_pakkaus_in_progress', __( 'Wait for the running optimization to finish first.', 'jcore-pakkaus' ) );
		}

		$uploads  = wp_get_upload_dir();
		$current  = get_attached_file( $id );
		$original = path_join( $uploads['basedir'], $meta['attached'] );
		$old_url  = wp_get_attachment_url( $id );

		if ( $backup !== $original && ! @rename( $backup, $original ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return new \WP_Error( 'jcore_pakkaus_restore_failed', __( 'Could not restore the original file.', 'jcore-pakkaus' ) );
		}
		if ( $current && $current !== $original ) {
			wp_delete_file( $current );
			update_attached_file( $id, $original );
			self::set_mime( $id, $meta['mime'] );
			self::replace_url_in_content( $old_url, wp_get_attachment_url( $id ) );
		}

		delete_post_meta( $id, self::META_BACKUP );
		delete_post_meta( $id, self::META_STATS );
		self::set_state(
			$id,
			'restored',
			array(
				'message'  => '',
				'progress' => 0,
			)
		);
		self::regenerate_metadata( $id );

		return true;
	}

	/**
	 * Absolute path of the backup file, if one exists.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function backup_path( $id ) {
		$meta = get_post_meta( $id, self::META_BACKUP, true );
		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return '';
		}
		$uploads = wp_get_upload_dir();
		$path    = path_join( $uploads['basedir'], $meta['file'] );
		return file_exists( $path ) ? $path : '';
	}

	/**
	 * Clean up the service job and backup file when an attachment is deleted.
	 *
	 * @param int $id Attachment ID.
	 */
	public static function on_delete_attachment( $id ) {
		if ( in_array( self::status( $id ), self::ACTIVE_STATUSES, true ) ) {
			self::forget_job( $id );
		}
		$backup = self::backup_path( $id );
		if ( $backup && get_attached_file( $id ) !== $backup ) {
			wp_delete_file( $backup );
		}
	}

	/**
	 * Attachments with in-flight jobs, least recently updated first.
	 *
	 * @param int $limit Max results.
	 * @return int[]
	 */
	public static function active_attachment_ids( $limit ) {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'meta_key'       => self::META_UPDATED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_STATUS,
						'value'   => self::ACTIVE_STATUSES,
						'compare' => 'IN',
					),
				),
			)
		);
	}

	/**
	 * Update status meta.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $status Status.
	 * @param array  $fields Optional 'progress' and 'message'.
	 */
	private static function set_state( $id, $status, $fields = array() ) {
		update_post_meta( $id, self::META_STATUS, $status );
		update_post_meta( $id, self::META_UPDATED, time() );
		if ( array_key_exists( 'progress', $fields ) ) {
			update_post_meta( $id, self::META_PROGRESS, $fields['progress'] );
		}
		if ( array_key_exists( 'message', $fields ) ) {
			update_post_meta( $id, self::META_MESSAGE, $fields['message'] );
		}
	}

	/**
	 * Store size statistics.
	 *
	 * @param int        $id     Attachment ID.
	 * @param array      $job    Job.
	 * @param array|null $result Result of replace_file().
	 */
	private static function save_stats( $id, $job, $result ) {
		$input  = isset( $job['input'] ) && is_array( $job['input'] ) ? $job['input'] : array();
		$output = isset( $job['output'] ) && is_array( $job['output'] ) ? $job['output'] : array();
		update_post_meta(
			$id,
			self::META_STATS,
			array(
				'original_size'  => $result ? $result['original_size'] : ( isset( $input['size'] ) ? (int) $input['size'] : 0 ),
				'optimized_size' => $result ? $result['optimized_size'] : ( isset( $output['size'] ) ? (int) $output['size'] : 0 ),
				'input'          => $input,
				'output'         => $output,
				'time'           => time(),
			)
		);
	}

	/**
	 * Delete the job on the service and forget its ID locally.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function forget_job( $id ) {
		$job_id = get_post_meta( $id, self::META_JOB, true );
		if ( $job_id ) {
			Client::delete_job( $job_id );
		}
		delete_post_meta( $id, self::META_JOB );
		delete_post_meta( $id, self::META_SECRET );
	}

	/**
	 * Schedule the poll event if it isn't already.
	 */
	public static function ensure_polling() {
		if ( ! wp_next_scheduled( self::CRON_POLL ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON_POLL );
		}
	}

	/**
	 * Regenerate attachment metadata (duration, dimensions, filesize, ...).
	 *
	 * @param int $id Attachment ID.
	 */
	private static function regenerate_metadata( $id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		self::$regenerating = true;
		try {
			$metadata = wp_generate_attachment_metadata( $id, get_attached_file( $id ) );
		} finally {
			self::$regenerating = false;
		}
		wp_update_attachment_metadata( $id, $metadata );
	}

	/**
	 * Update an attachment's MIME type without going through wp_update_post().
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $mime MIME type.
	 */
	private static function set_mime( $id, $mime ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $mime ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );
	}

	/**
	 * Point post content at the new file when the extension changed (e.g. .mov -> .mp4).
	 *
	 * @param string $old_url Previous URL.
	 * @param string $new_url New URL.
	 */
	private static function replace_url_in_content( $old_url, $new_url ) {
		global $wpdb;

		// Match on the path so absolute, relative and CDN-prefixed URLs are all covered.
		$old = (string) wp_parse_url( $old_url, PHP_URL_PATH );
		$new = (string) wp_parse_url( $new_url, PHP_URL_PATH );
		if ( '' === $old || $old === $new ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type NOT IN ('revision', 'attachment')",
				'%' . $wpdb->esc_like( $old ) . '%'
			)
		);
		foreach ( $post_ids as $post_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE ID = %d",
					$old,
					$new,
					$post_id
				)
			);
			clean_post_cache( (int) $post_id );
		}
		// phpcs:enable

		/**
		 * Fires after post content was updated to reference the new file URL, so other
		 * storage (page builders, custom fields, ...) can be updated too.
		 *
		 * @param string $old_url  Previous URL.
		 * @param string $new_url  New URL.
		 * @param int[]  $post_ids Updated posts.
		 */
		do_action( 'jcore_pakkaus_url_changed', $old_url, $new_url, array_map( 'intval', $post_ids ) );
	}

	/**
	 * Cheap sanity check for an ISO BMFF (MP4) file.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private static function looks_like_mp4( $path ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}
		$header = fread( $handle, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return is_string( $header ) && 12 === strlen( $header ) && 'ftyp' === substr( $header, 4, 4 );
	}

	/**
	 * Per-attachment mutex so the callback and the poller never finalize the same job twice.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	private static function lock( $id ) {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $result || '1' === (string) $result; // null: GET_LOCK unsupported (e.g. SQLite).
	}

	/**
	 * Release the mutex.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function unlock( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Lock name.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	private static function lock_name( $id ) {
		return substr( DB_NAME . '.' . $GLOBALS['wpdb']->prefix . 'jcore_pakkaus_' . $id, -64 );
	}
}
