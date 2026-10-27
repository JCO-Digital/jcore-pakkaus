<?php
/**
 * Image attachments: which files get optimized, putting the results in place, and keeping the WebP
 * versions in step with the files they were made from.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebP versions are saved next to each file with the format appended (photo.jpg → photo.jpg.webp), and the
 * web server picks one by the request's Accept header, so browsers without WebP support get the original.
 * PNG files can also be recompressed in place.
 *
 * Per-file results are kept in the stats meta under `files`, each with:
 * - name:          size name, `full` for the attached file
 * - file:          path relative to the uploads directory
 * - original_size: size before optimization (of the backup, if one was kept)
 * - size, mtime:   the file as installed, to notice when something else rewrites it
 * - formats:       format => size of each version saved next to it
 * - backup:        relative path of the kept original PNG, or ''
 * - message:       why the file was left alone, if it was
 */
final class Images {

	/**
	 * MIME types the optimizer accepts.
	 */
	public const MIME_TYPES = array( 'image/jpeg', 'image/png' );

	/**
	 * Formats saved next to each file as <file>.<format>.
	 */
	public const FORMATS = array( 'webp' );

	/**
	 * Accept header of a browser that takes WebP, and of one that doesn't.
	 */
	private const ACCEPT_WEBP  = 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8';
	private const ACCEPT_PLAIN = 'image/png,image/svg+xml,image/*;q=0.8,*/*;q=0.5';

	/**
	 * True while this class updates attachment metadata itself, so its own hook leaves the update alone.
	 *
	 * @var bool
	 */
	private static $updating = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'wp_delete_file', array( self::class, 'on_delete_file' ) );
		add_filter( 'wp_update_attachment_metadata', array( self::class, 'on_update_metadata' ), 20, 2 );
	}

	/**
	 * Whether the attachment is a JPEG or PNG image.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function is_image( $id ) {
		return in_array( (string) get_post_mime_type( $id ), self::MIME_TYPES, true );
	}

	/**
	 * Path of a format's version of a file.
	 *
	 * @param string $path   Image path.
	 * @param string $format Format, e.g. `webp`.
	 * @return string
	 */
	public static function sidecar( $path, $format ) {
		return $path . '.' . $format;
	}

	/**
	 * The attachment's JPEG and PNG files: the attached file and every resized copy WordPress made. The
	 * unscaled original of a big upload is left out, as it is never shown on the site.
	 *
	 * @param int $id Attachment ID.
	 * @return array<int, array{name: string, file: string, url: string, size: int, mtime: int}>
	 */
	public static function files( $id ) {
		$path = (string) get_attached_file( $id );
		$url  = (string) wp_get_attachment_url( $id );
		if ( '' === $path || '' === $url ) {
			return array();
		}

		$metadata   = wp_get_attachment_metadata( $id );
		$sizes      = is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		$candidates = array( 'full' => wp_basename( $path ) );
		foreach ( $sizes as $name => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$candidates[ (string) $name ] = wp_basename( (string) $size['file'] );
			}
		}

		$files = array();
		$seen  = array();
		foreach ( $candidates as $name => $basename ) {
			$file = dirname( $path ) . '/' . $basename;
			// Several sizes can share a file.
			if ( isset( $seen[ $basename ] ) || ! self::is_image_file( $file ) || ! is_file( $file ) ) {
				continue;
			}
			$seen[ $basename ] = true;
			clearstatcache( true, $file );
			$files[] = array(
				'name'  => (string) $name,
				'file'  => _wp_relative_upload_path( $file ),
				'url'   => 'full' === $name ? $url : dirname( $url ) . '/' . $basename,
				'size'  => (int) filesize( $file ),
				'mtime' => (int) filemtime( $file ),
			);
		}

		return $files;
	}

	/**
	 * Download an image job's results and put them in place: WebP versions next to the files, smaller PNG
	 * files over the originals. Everything is downloaded before anything is moved, so a failed download
	 * leaves the files as they were and can be retried.
	 *
	 * @param int   $id  Attachment ID.
	 * @param array $job Completed job.
	 * @return array|\WP_Error Sizes before and after, and the per-file records.
	 */
	public static function install( $id, $job ) {
		$sent    = get_post_meta( $id, Processor::META_FILES, true );
		$results = isset( $job['output']['files'] ) && is_array( $job['output']['files'] ) ? array_values( $job['output']['files'] ) : array();
		if ( ! is_array( $sent ) || ! $sent || count( $sent ) !== count( $results ) ) {
			return new \WP_Error( 'jcore_pakkaus_files_mismatch', __( 'The optimized files do not match the files that were sent.', 'jcore-pakkaus' ) );
		}

		$previous = self::records( $id );
		$records  = array();
		$staged   = array();
		foreach ( array_values( $sent ) as $index => $file ) {
			$result = is_array( $results[ $index ] ) ? $results[ $index ] : array();
			if ( ( $result['name'] ?? null ) !== $file['name'] || ! self::is_own_file( $file['file'] ?? '', $id ) ) {
				self::discard( $staged );
				return new \WP_Error( 'jcore_pakkaus_files_mismatch', __( 'The optimized files do not match the files that were sent.', 'jcore-pakkaus' ) );
			}

			$path   = Uploads::path( $file['file'] );
			$before = $previous[ $file['file'] ] ?? array();
			$backup = isset( $before['backup'] ) && '' !== $before['backup'] && file_exists( Uploads::path( $before['backup'] ) ) ? $before['backup'] : '';
			$record = array(
				'name'          => (string) $file['name'],
				'file'          => (string) $file['file'],
				'original_size' => $backup ? (int) $before['original_size'] : (int) $file['size'],
				'size'          => (int) $file['size'],
				'mtime'         => (int) $file['mtime'],
				'formats'       => array(),
				'backup'        => $backup,
				'message'       => (string) ( $result['error'] ?? ( $result['message'] ?? '' ) ),
			);

			clearstatcache( true, $path );
			if ( ! is_file( $path ) || filesize( $path ) !== (int) $file['size'] || filemtime( $path ) !== (int) $file['mtime'] ) {
				$record['message'] = __( 'The file changed while it was being optimized. Optimize the image again.', 'jcore-pakkaus' );
				$records[]         = $record;
				continue;
			}

			$is_png   = 'png' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$variants = isset( $result['variants'] ) && is_array( $result['variants'] ) ? $result['variants'] : array();
			foreach ( $variants as $variant => $info ) {
				$variant = (string) $variant;
				// Only PNG files are recompressed in place; anything else the service offers is ignored.
				if ( ! in_array( $variant, self::FORMATS, true ) && ! ( 'optimized' === $variant && $is_png ) ) {
					continue;
				}

				$tmp        = dirname( $path ) . '/.jcore-pakkaus-' . $id . '-' . wp_generate_password( 8, false ) . '.part';
				$downloaded = Client::download_image_output( $job['id'], $index, $variant, $tmp );
				if ( is_wp_error( $downloaded ) ) {
					self::discard( $staged );
					return $downloaded;
				}

				clearstatcache( true, $tmp );
				$size     = (int) filesize( $tmp );
				$expected = is_array( $info ) && isset( $info['size'] ) ? (int) $info['size'] : 0;
				if ( ( $expected && $size !== $expected ) || ! self::looks_like( $tmp, 'optimized' === $variant ? 'png' : $variant ) ) {
					wp_delete_file( $tmp );
					self::discard( $staged );
					return new \WP_Error( 'jcore_pakkaus_download_retry', __( 'A downloaded image is incomplete or not in the expected format.', 'jcore-pakkaus' ) );
				}

				$staged[] = array(
					'tmp'     => $tmp,
					'path'    => $path,
					'variant' => $variant,
					'size'    => $size,
					'record'  => count( $records ),
				);
			}

			$record['done'] = true;
			$records[]      = $record;
		}

		foreach ( $staged as $item ) {
			$records[ $item['record'] ] = self::put_in_place( $item, $records[ $item['record'] ] );
		}

		foreach ( $records as $index => $record ) {
			$path = Uploads::path( $record['file'] );
			if ( ! empty( $record['done'] ) ) {
				// Versions an earlier run made that this one didn't would show an older file.
				foreach ( array_diff( self::FORMATS, array_keys( $record['formats'] ) ) as $format ) {
					self::delete( self::sidecar( $path, $format ) );
				}
			}
			unset( $record['done'] );
			clearstatcache( true, $path );
			if ( is_file( $path ) ) {
				$record['size']  = (int) filesize( $path );
				$record['mtime'] = (int) filemtime( $path );
			}
			$records[ $index ] = $record;
		}

		self::update_filesizes( $id, $records );

		return array(
			'original_size'  => (int) array_sum( array_column( $records, 'original_size' ) ),
			'optimized_size' => (int) array_sum( array_map( array( self::class, 'served_size' ), $records ) ),
			'files'          => $records,
		);
	}

	/**
	 * Move one downloaded result into place.
	 *
	 * @param array $item   Staged download.
	 * @param array $record The file's record.
	 * @return array The updated record.
	 */
	private static function put_in_place( $item, $record ) {
		$made_backup = false;
		if ( 'optimized' === $item['variant'] ) {
			$target = $item['path'];
			// Keep the very first original only; re-optimizing doesn't back up an already optimized file.
			if ( Settings::get( 'keep_original' ) && '' === $record['backup'] ) {
				$info   = pathinfo( $item['path'] );
				$backup = $info['dirname'] . '/' . wp_unique_filename( $info['dirname'], $info['filename'] . '.original.' . $info['extension'] );
				if ( ! @rename( $item['path'], $backup ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic within the uploads directory.
					wp_delete_file( $item['tmp'] );
					$record['message'] = __( 'Could not back up the original file.', 'jcore-pakkaus' );
					return $record;
				}
				$record['backup'] = _wp_relative_upload_path( $backup );
				$made_backup      = true;
			}
		} else {
			$target = self::sidecar( $item['path'], $item['variant'] );
		}

		if ( ! @rename( $item['tmp'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic within the uploads directory.
			wp_delete_file( $item['tmp'] );
			if ( $made_backup ) {
				@rename( Uploads::path( $record['backup'] ), $item['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				$record['backup'] = '';
			}
			$record['message'] = __( 'Could not move an optimized file into place.', 'jcore-pakkaus' );
			return $record;
		}

		$stat = stat( dirname( $target ) );
		@chmod( $target, $stat['mode'] & 0000666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same permissions core gives uploads.

		if ( 'optimized' === $item['variant'] ) {
			$record['size'] = $item['size'];
		} else {
			$record['formats'][ $item['variant'] ] = $item['size'];
		}

		return $record;
	}

	/**
	 * Delete the WebP versions and put backed-up originals back.
	 *
	 * @param int $id Attachment ID.
	 * @return true|\WP_Error
	 */
	public static function restore( $id ) {
		$records = self::records( $id );
		foreach ( $records as $file => $record ) {
			$path = Uploads::path( $file );
			foreach ( self::FORMATS as $format ) {
				self::delete( self::sidecar( $path, $format ) );
			}
			if ( '' !== $record['backup'] ) {
				$backup = Uploads::path( $record['backup'] );
				if ( file_exists( $backup ) && ! @rename( $backup, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic within the uploads directory.
					return new \WP_Error( 'jcore_pakkaus_restore_failed', __( 'Could not restore the original file.', 'jcore-pakkaus' ) );
				}
			}
			clearstatcache( true, $path );
			$records[ $file ]['size'] = is_file( $path ) ? (int) filesize( $path ) : 0;
		}

		self::update_filesizes( $id, $records );

		return true;
	}

	/**
	 * The original full-size file: the kept backup if there is one, else the attached file.
	 *
	 * @param int $id Attachment ID.
	 * @return string Path, or '' if the file is gone.
	 */
	public static function original_path( $id ) {
		$attached = (string) get_attached_file( $id );
		foreach ( self::records( $id ) as $file => $record ) {
			if ( '' !== $record['backup'] && Uploads::path( $file ) === $attached && is_file( Uploads::path( $record['backup'] ) ) ) {
				return Uploads::path( $record['backup'] );
			}
		}

		return is_file( $attached ) ? $attached : '';
	}

	/**
	 * Whether restoring would change anything: there are WebP versions or kept originals.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function can_restore( $id ) {
		foreach ( self::records( $id ) as $record ) {
			if ( $record['formats'] || '' !== $record['backup'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Delete the kept originals, when the attachment is deleted. WordPress deletes the files it knows
	 * about itself, and their WebP versions go with them through on_delete_file().
	 *
	 * @param int $id Attachment ID.
	 */
	public static function delete_backups( $id ) {
		foreach ( self::records( $id ) as $record ) {
			if ( '' !== $record['backup'] ) {
				self::delete( Uploads::path( $record['backup'] ) );
			}
		}
	}

	/**
	 * Whatever deletes an image file also deletes its WebP version: deleting attachments, the image
	 * editor overwriting files, regenerating thumbnails.
	 *
	 * @param string $file Path about to be deleted.
	 * @return string
	 */
	public static function on_delete_file( $file ) {
		// Other image optimizers name their WebP files the same way; leave those to them.
		if ( is_string( $file ) && self::is_image_file( $file ) && ! Conflicts::blocks_images() ) {
			foreach ( self::FORMATS as $format ) {
				self::delete( self::sidecar( $file, $format ) );
			}
		}

		return $file;
	}

	/**
	 * When something rewrites an optimized image's files (the image editor, regenerating thumbnails), drop
	 * the WebP versions that no longer match, and optimize the image again if new uploads are optimized.
	 *
	 * @param mixed $data Attachment metadata about to be saved.
	 * @param int   $id   Attachment ID.
	 * @return mixed
	 */
	public static function on_update_metadata( $data, $id ) {
		$id = (int) $id;
		if ( self::$updating || ! is_array( $data ) || 'optimized' !== Processor::status( $id ) || ! self::is_image( $id ) || Conflicts::blocks_images() ) {
			return $data;
		}
		$records = self::records( $id );
		if ( ! $records ) {
			return $data;
		}

		$changed = false;
		$known   = array();
		foreach ( $records as $file => $record ) {
			$path                          = Uploads::path( $file );
			$known[ wp_basename( $file ) ] = true;
			clearstatcache( true, $path );
			if ( is_file( $path ) && filesize( $path ) === $record['size'] && filemtime( $path ) === $record['mtime'] ) {
				continue;
			}
			$changed = true;
			foreach ( self::FORMATS as $format ) {
				self::delete( self::sidecar( $path, $format ) );
			}
		}

		// Files the metadata lists that were never optimized, such as a size added since.
		$attached = (string) ( $data['file'] ?? '' );
		$full     = Uploads::path( $attached );
		$dir      = '' !== $full ? dirname( $full ) : dirname( (string) get_attached_file( $id ) );
		$current  = array( wp_basename( $attached ) );
		foreach ( (array) ( $data['sizes'] ?? array() ) as $size ) {
			$current[] = wp_basename( (string) ( $size['file'] ?? '' ) );
		}
		foreach ( array_filter( $current ) as $basename ) {
			if ( ! isset( $known[ $basename ] ) && self::is_image_file( $basename ) && is_file( $dir . '/' . $basename ) ) {
				$changed = true;
			}
		}

		if ( $changed && Settings::get( 'image_auto_optimize' ) && Settings::images_enabled() && Settings::is_configured() ) {
			Processor::enqueue( $id );
		}

		return $data;
	}

	/**
	 * Ask the web server for an optimized image the way browsers do, with and without WebP in the Accept
	 * header, to see whether it hands out the WebP versions as the docs describe.
	 *
	 * @return array{status: string, url: string, webp_type: string, fallback_type: string, vary: bool, error: string}
	 */
	public static function check_serving() {
		$report = array(
			'status'        => 'untested',
			'url'           => '',
			'webp_type'     => '',
			'fallback_type' => '',
			'vary'          => false,
			'error'         => '',
		);

		$id = self::sample_ids( 1 )[0] ?? 0;
		if ( ! $id ) {
			return $report;
		}
		$report['url'] = (string) wp_get_attachment_url( $id );

		$webp     = self::fetch( $report['url'], self::ACCEPT_WEBP );
		$fallback = self::fetch( $report['url'], self::ACCEPT_PLAIN );
		foreach ( array( $webp, $fallback ) as $response ) {
			if ( is_wp_error( $response ) ) {
				$report['status'] = 'error';
				$report['error']  = $response->get_error_message();
				return $report;
			}
		}

		$report['webp_type']     = self::content_type( $webp );
		$report['fallback_type'] = self::content_type( $fallback );
		$report['vary']          = false !== stripos( (string) wp_remote_retrieve_header( $webp, 'vary' ), 'accept' );

		if ( 'image/webp' === $report['fallback_type'] ) {
			$report['status'] = 'broken';
		} elseif ( 'image/webp' !== $report['webp_type'] ) {
			$report['status'] = 'not_configured';
		} else {
			$report['status'] = $report['vary'] ? 'ok' : 'no_vary';
		}

		return $report;
	}

	/**
	 * Recently optimized images for the admin page to load in the browser, to see what the web server sends
	 * a real browser. Each lists its attached file and one resized copy (`medium` when it has a WebP
	 * version) with the size of the file and of its WebP version, so the page can tell which one arrived.
	 *
	 * @param int $limit Most images to return.
	 * @return array<int, array{id: int, title: string, files: array<int, array{name: string, url: string, mime: string, size: int, webp_size: int}>}>
	 */
	public static function serving_samples( $limit = 6 ) {
		$samples = array();
		foreach ( self::sample_ids( $limit ) as $id ) {
			$files = array();
			foreach ( self::files( $id ) as $file ) {
				$path = Uploads::path( $file['file'] );
				$webp = '' !== $path ? self::sidecar( $path, 'webp' ) : '';
				if ( '' === $webp || ! is_file( $webp ) ) {
					continue;
				}
				$files[ $file['name'] ] = array(
					'name'      => $file['name'],
					'url'       => $file['url'],
					'mime'      => (string) wp_check_filetype( $path )['type'],
					'size'      => $file['size'],
					'webp_size' => (int) filesize( $webp ),
				);
			}
			$full = $files['full'] ?? null;
			unset( $files['full'] );
			$files = array_values( array_filter( array( $full, $files['medium'] ?? reset( $files ) ) ) );
			if ( ! $files ) {
				continue;
			}

			$title     = (string) get_post_field( 'post_title', $id, 'raw' );
			$samples[] = array(
				'id'    => $id,
				'title' => '' !== $title ? $title : wp_basename( (string) get_attached_file( $id ) ),
				'files' => $files,
			);
		}

		return $samples;
	}

	/**
	 * Optimized images with a WebP version of their attached file, newest first.
	 *
	 * @param int $limit Most IDs to return.
	 * @return int[] Attachment IDs.
	 */
	private static function sample_ids( $limit ) {
		$ids   = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_mime_type' => self::MIME_TYPES,
				'fields'         => 'ids',
				'posts_per_page' => max( 20, 2 * $limit ),
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => Processor::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'optimized', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$found = array();
		foreach ( $ids as $id ) {
			if ( file_exists( self::sidecar( (string) get_attached_file( $id ), 'webp' ) ) ) {
				$found[] = (int) $id;
				if ( count( $found ) >= $limit ) {
					break;
				}
			}
		}

		return $found;
	}

	/**
	 * Request a URL on this site with an Accept header.
	 *
	 * @param string $url    URL.
	 * @param string $accept Accept header.
	 * @return array|\WP_Error
	 */
	private static function fetch( $url, $accept ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 2,
				'limit_response_size' => 1024,
				'headers'             => array(
					'Accept'        => $accept,
					'Cache-Control' => 'no-cache',
				),
				// Loopback requests, like Site Health's.
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
			)
		);
	}

	/**
	 * MIME type of a response, without parameters.
	 *
	 * @param array $response Response.
	 * @return string
	 */
	private static function content_type( $response ) {
		$type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		return strtolower( trim( explode( ';', $type )[0] ) );
	}

	/**
	 * Per-file records of the last optimization, keyed by relative path.
	 *
	 * @param int $id Attachment ID.
	 * @return array<string, array<string, mixed>>
	 */
	private static function records( $id ) {
		$stats   = get_post_meta( $id, Processor::META_STATS, true );
		$records = array();
		foreach ( is_array( $stats ) && isset( $stats['files'] ) && is_array( $stats['files'] ) ? $stats['files'] : array() as $record ) {
			// Records naming anything but the attachment's own image files are ignored; see Uploads.
			if ( ! is_array( $record ) || ! self::is_own_file( $record['file'] ?? '', $id ) ) {
				continue;
			}
			$record = array_merge(
				array(
					'original_size' => 0,
					'size'          => 0,
					'mtime'         => 0,
					'formats'       => array(),
					'backup'        => '',
				),
				$record
			);
			// Kept originals are always named <name>.original.png, next to the file.
			$backup                     = $record['backup'];
			$record['backup']           = is_string( $backup ) && preg_match( '/\.original(-\d+)?\.png$/i', $backup ) && self::is_own_file( $backup, $id ) ? $backup : '';
			$records[ $record['file'] ] = $record;
		}

		return $records;
	}

	/**
	 * Whether a stored path names a JPEG or PNG file in the attachment's own folder.
	 *
	 * @param mixed $file Path relative to the uploads directory.
	 * @param int   $id   Attachment ID.
	 * @return bool
	 */
	private static function is_own_file( $file, $id ) {
		return is_string( $file ) && self::is_image_file( $file ) && Uploads::beside_attachment( $file, $id );
	}

	/**
	 * What a browser that takes the new formats downloads for a file.
	 *
	 * @param array $record File record.
	 * @return int
	 */
	public static function served_size( $record ) {
		return (int) min( array_merge( array( (int) $record['size'] ), array_map( 'intval', (array) $record['formats'] ) ) );
	}

	/**
	 * Keep the attachment metadata's file sizes right after PNG files were replaced.
	 *
	 * @param int   $id      Attachment ID.
	 * @param array $records File records.
	 */
	private static function update_filesizes( $id, $records ) {
		$metadata = wp_get_attachment_metadata( $id );
		if ( ! is_array( $metadata ) ) {
			return;
		}

		$sizes = array();
		foreach ( $records as $record ) {
			$sizes[ wp_basename( $record['file'] ) ] = (int) $record['size'];
		}

		$changed = false;
		$full    = wp_basename( (string) get_attached_file( $id ) );
		if ( isset( $sizes[ $full ] ) && ( $metadata['filesize'] ?? null ) !== $sizes[ $full ] ) {
			$metadata['filesize'] = $sizes[ $full ];
			$changed              = true;
		}
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $name => $size ) {
			$file = (string) ( $size['file'] ?? '' );
			if ( isset( $sizes[ $file ] ) && ( $size['filesize'] ?? null ) !== $sizes[ $file ] ) {
				$metadata['sizes'][ $name ]['filesize'] = $sizes[ $file ];
				$changed                                = true;
			}
		}

		if ( $changed ) {
			self::$updating = true;
			try {
				wp_update_attachment_metadata( $id, $metadata );
			} finally {
				self::$updating = false;
			}
		}
	}

	/**
	 * Whether a path names a JPEG or PNG file.
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	private static function is_image_file( $file ) {
		return 1 === preg_match( '/\.(jpe?g|jpe|png)$/i', $file );
	}

	/**
	 * Delete a file if it exists.
	 *
	 * @param string $path Path.
	 */
	private static function delete( $path ) {
		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Delete staged downloads.
	 *
	 * @param array $staged Staged downloads.
	 */
	private static function discard( $staged ) {
		foreach ( $staged as $item ) {
			wp_delete_file( $item['tmp'] );
		}
	}

	/**
	 * Cheap sanity check of a downloaded file's signature.
	 *
	 * @param string $path   File path.
	 * @param string $format `png` or `webp`.
	 * @return bool
	 */
	private static function looks_like( $path, $format ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}
		$header = (string) fread( $handle, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( 'png' === $format ) {
			return "\x89PNG\r\n\x1a\n" === substr( $header, 0, 8 );
		}
		if ( 'webp' === $format ) {
			return 'RIFF' === substr( $header, 0, 4 ) && 'WEBP' === substr( $header, 8, 4 );
		}

		return false;
	}
}
