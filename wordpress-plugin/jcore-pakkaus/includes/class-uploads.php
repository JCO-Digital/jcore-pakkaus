<?php
/**
 * Paths kept in post meta, relative to the uploads directory.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * This plugin's post meta remembers files by their path relative to the uploads directory: kept originals,
 * the files that were optimized. Post meta is not only written by this plugin, though: an import (the
 * WordPress Importer copies any post meta a WXR file lists) can set it to anything. Before a stored path
 * is read, renamed or deleted, it has to name a file in the attachment's own folder.
 */
final class Uploads {

	/**
	 * Absolute path of a file given relative to the uploads directory.
	 *
	 * @param mixed $file Relative path.
	 * @return string Path, or '' if it is absolute, has `..` in it or is otherwise not a plain relative path.
	 */
	public static function path( $file ) {
		$file = is_string( $file ) ? str_replace( '\\', '/', $file ) : '';
		// A colon would allow drive letters and stream wrappers; WordPress strips it from uploaded file names.
		if ( '' === $file || '/' === $file[0] || false !== strpbrk( $file, ":\0" ) || in_array( '..', explode( '/', $file ), true ) ) {
			return '';
		}
		$uploads = wp_get_upload_dir();

		return path_join( $uploads['basedir'], $file );
	}

	/**
	 * Whether a stored path names a file in the same uploads folder as the attachment's own file.
	 *
	 * @param mixed $file Relative path.
	 * @param int   $id   Attachment ID.
	 * @return bool
	 */
	public static function beside_attachment( $file, $id ) {
		$attached = _wp_relative_upload_path( (string) get_attached_file( $id ) );

		return '' !== self::path( $file ) && '' !== self::path( $attached ) && dirname( $file ) === dirname( $attached );
	}
}
