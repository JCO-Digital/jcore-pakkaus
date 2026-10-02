<?php
/**
 * WP-CLI commands.
 *
 * @package Video_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Optimize videos with the Video Optimizer service.
 */
class Vopt_CLI {

	/**
	 * Send videos to the optimizer.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to optimize.
	 *
	 * [--all]
	 * : Optimize every video in the media library that hasn't been optimized yet.
	 *
	 * [--force]
	 * : With --all, also re-optimize videos that were already optimized or skipped.
	 *
	 * ## EXAMPLES
	 *
	 *     wp video-optimizer optimize 123 456
	 *     wp video-optimizer optimize --all
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function optimize( $args, $assoc_args ) {
		if ( ! Vopt_Settings::is_configured() ) {
			WP_CLI::error( 'Configure the service URL and API token first (Settings → Video Optimizer).' );
		}

		$ids = array_map( 'absint', $args );
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			$force = WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
			$ids   = array_filter(
				$this->video_ids(),
				static function ( $id ) use ( $force ) {
					$status = Vopt_Processor::status( $id );
					return $force ? ! in_array( $status, Vopt_Processor::ACTIVE_STATUSES, true ) : '' === $status;
				}
			);
		}
		if ( ! $ids ) {
			WP_CLI::success( 'Nothing to optimize.' );
			return;
		}

		$failed = 0;
		foreach ( $ids as $id ) {
			$result = Vopt_Processor::queue( $id );
			if ( is_wp_error( $result ) ) {
				++$failed;
				WP_CLI::warning( "#{$id}: " . $result->get_error_message() );
			} else {
				WP_CLI::log( "#{$id}: queued" );
			}
		}
		WP_CLI::success( sprintf( '%d of %d videos queued. Run `wp video-optimizer status` to follow progress.', count( $ids ) - $failed, count( $ids ) ) );
	}

	/**
	 * Show the optimization status of videos.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs. Defaults to all videos.
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function status( $args, $assoc_args ) {
		$ids  = $args ? array_map( 'absint', $args ) : $this->video_ids();
		$rows = array();
		foreach ( $ids as $id ) {
			$stats  = get_post_meta( $id, Vopt_Processor::META_STATS, true );
			$rows[] = array(
				'id'        => $id,
				'file'      => basename( (string) get_attached_file( $id ) ),
				'status'    => Vopt_Processor::status( $id ) ? Vopt_Processor::status( $id ) : '-',
				'progress'  => get_post_meta( $id, Vopt_Processor::META_PROGRESS, true ),
				'original'  => is_array( $stats ) ? size_format( $stats['original_size'], 1 ) : '',
				'optimized' => is_array( $stats ) ? size_format( $stats['optimized_size'], 1 ) : '',
				'backup'    => Vopt_Processor::backup_path( $id ) ? 'yes' : '',
				'message'   => get_post_meta( $id, Vopt_Processor::META_MESSAGE, true ),
			);
		}
		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, array_keys( $rows ? $rows[0] : array( 'id' => 1 ) ) );
	}

	/**
	 * Check in-flight jobs right now instead of waiting for WP-Cron.
	 *
	 * ## OPTIONS
	 *
	 * [--wait]
	 * : Keep polling until every job has finished.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function poll( $args, $assoc_args ) {
		$wait = WP_CLI\Utils\get_flag_value( $assoc_args, 'wait', false );
		do {
			$ids = Vopt_Processor::active_attachment_ids( 100 );
			foreach ( $ids as $id ) {
				Vopt_Processor::refresh( $id );
				WP_CLI::log( sprintf( '#%d: %s %s', $id, Vopt_Processor::status( $id ), get_post_meta( $id, Vopt_Processor::META_MESSAGE, true ) ) );
			}
			if ( $wait && $ids ) {
				sleep( 5 );
			}
		} while ( $wait && $ids );
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Restore the original (backed up) file of a video.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Attachment IDs.
	 *
	 * @param array $args Positional args.
	 */
	public function restore( $args ) {
		foreach ( array_map( 'absint', $args ) as $id ) {
			$result = Vopt_Processor::restore( $id );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( "#{$id}: " . $result->get_error_message() );
			} else {
				WP_CLI::log( "#{$id}: restored" );
			}
		}
	}

	/**
	 * Check the connection to the optimizer service.
	 */
	public function test() {
		$info = Vopt_Client::info();
		if ( is_wp_error( $info ) ) {
			WP_CLI::error( $info->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Connected to optimizer service %s (%s).', $info['version'], $info['ffmpeg'] ) );
	}

	/**
	 * All video attachment IDs.
	 *
	 * @return int[]
	 */
	private function video_ids() {
		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => 'video',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}
}
