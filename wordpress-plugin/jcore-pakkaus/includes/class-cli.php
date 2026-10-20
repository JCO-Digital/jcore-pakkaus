<?php
/**
 * WP-CLI commands.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optimize videos and images with the optimizer service.
 */
final class Cli {

	/**
	 * Send videos and images to the optimizer.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs to optimize.
	 *
	 * [--all]
	 * : Optimize every video and image in the media library that hasn't been optimized yet.
	 *
	 * [--type=<type>]
	 * : With --all, only videos or only images.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - video
	 *   - image
	 * ---
	 *
	 * [--force]
	 * : With --all, also re-optimize attachments that were already optimized or skipped.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pakkaus optimize 123 456
	 *     wp pakkaus optimize --all
	 *     wp pakkaus optimize --all --type=image
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function optimize( $args, $assoc_args ) {
		if ( ! Settings::is_configured() ) {
			WP_CLI::error( 'Configure the service URL and API token first (Settings → JCORE Pakkaus, or `wp option` / wp-config.php).' );
		}

		$ids = array_map( 'absint', $args );
		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false ) ) {
			$force = WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
			$ids   = array_filter(
				$this->attachment_ids( $assoc_args['type'] ?? 'all' ),
				static function ( $id ) use ( $force ) {
					$status = Processor::status( $id );
					return $force ? ! in_array( $status, Processor::ACTIVE_STATUSES, true ) : '' === $status;
				}
			);
		}
		if ( Conflicts::blocks_images() ) {
			$count = count( $ids );
			$ids   = array_values( array_filter( $ids, array( Processor::class, 'is_video' ) ) );
			if ( count( $ids ) < $count ) {
				WP_CLI::warning( Conflicts::message() );
			}
		}
		if ( ! $ids ) {
			WP_CLI::success( 'Nothing to optimize.' );
			return;
		}

		$failed = 0;
		foreach ( $ids as $id ) {
			$result = Processor::queue( $id );
			if ( is_wp_error( $result ) ) {
				++$failed;
				WP_CLI::warning( "#{$id}: " . $result->get_error_message() );
			} else {
				WP_CLI::log( "#{$id}: queued" );
			}
		}
		WP_CLI::success( sprintf( '%d of %d attachments queued. Run `wp pakkaus status` to follow progress.', count( $ids ) - $failed, count( $ids ) ) );
	}

	/**
	 * Show the optimization status of videos and images.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs. Defaults to every video and image.
	 *
	 * [--type=<type>]
	 * : Without IDs, only videos or only images.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - video
	 *   - image
	 * ---
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
		$ids  = $args ? array_map( 'absint', $args ) : $this->attachment_ids( $assoc_args['type'] ?? 'all' );
		$rows = array();
		foreach ( $ids as $id ) {
			$stats  = get_post_meta( $id, Processor::META_STATS, true );
			$rows[] = array(
				'id'        => $id,
				'file'      => basename( (string) get_attached_file( $id ) ),
				'status'    => Processor::status( $id ) ? Processor::status( $id ) : '-',
				'progress'  => get_post_meta( $id, Processor::META_PROGRESS, true ),
				'original'  => is_array( $stats ) ? size_format( $stats['original_size'], 1 ) : '',
				'optimized' => is_array( $stats ) ? size_format( $stats['optimized_size'], 1 ) : '',
				'backup'    => ( Images::is_image( $id ) ? Images::can_restore( $id ) : Processor::backup_path( $id ) ) ? 'yes' : '',
				'message'   => get_post_meta( $id, Processor::META_MESSAGE, true ),
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
			$ids = Processor::active_attachment_ids( 100 );
			foreach ( $ids as $id ) {
				Processor::refresh( $id );
				WP_CLI::log( sprintf( '#%d: %s %s', $id, Processor::status( $id ), get_post_meta( $id, Processor::META_MESSAGE, true ) ) );
			}
			if ( $wait && $ids ) {
				sleep( 5 );
			}
		} while ( $wait && $ids );
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Restore the original (backed up) file of a video, or delete an image's WebP versions and put back its
	 * kept original PNG files.
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
			$result = Processor::restore( $id );
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
		$info = Client::info();
		if ( is_wp_error( $info ) ) {
			WP_CLI::error( $info->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Connected to optimizer service %s (%s).', $info['version'], $info['ffmpeg'] ) );
		if ( empty( $info['images'] ) ) {
			WP_CLI::warning( 'This service version cannot optimize images; update it.' );
		}
		if ( Conflicts::blocks_images() ) {
			WP_CLI::warning( Conflicts::message() );
		}
	}

	/**
	 * Check whether the web server gives browsers that take WebP the WebP versions, and the rest the originals.
	 */
	public function serving() {
		$report = Images::check_serving();
		switch ( $report['status'] ) {
			case 'ok':
				WP_CLI::success( sprintf( 'WebP versions are served by the Accept header, with Vary: Accept (%s).', $report['url'] ) );
				break;
			case 'no_vary':
				WP_CLI::warning( sprintf( 'WebP versions are served, but without Vary: Accept, so caches may hand them to browsers without WebP support (%s).', $report['url'] ) );
				break;
			case 'untested':
				WP_CLI::warning( 'No optimized image with a WebP version to test with yet.' );
				break;
			case 'error':
				WP_CLI::error( sprintf( 'Could not request %s: %s', $report['url'], $report['error'] ) );
				break;
			case 'broken':
				WP_CLI::error( sprintf( 'Browsers without WebP support also get WebP (%s).', $report['url'] ) );
				break;
			default:
				WP_CLI::error( sprintf( 'The server does not hand out the WebP versions; it answered %s. See the Nginx instructions in the readme (%s).', $report['webp_type'], $report['url'] ) );
		}
	}

	/**
	 * Attachment IDs of the videos and images the optimizer handles.
	 *
	 * @param string $type `all`, `video` or `image`.
	 *
	 * @return int[]
	 */
	private function attachment_ids( $type = 'all' ) {
		$mime = array(
			'video' => array( 'video' ),
			'image' => Images::MIME_TYPES,
			'all'   => array_merge( array( 'video' ), Images::MIME_TYPES ),
		);

		return get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => $mime[ $type ] ?? $mime['all'],
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}
}
