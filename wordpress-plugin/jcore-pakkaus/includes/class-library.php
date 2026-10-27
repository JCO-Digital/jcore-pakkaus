<?php
/**
 * Queries over the video and image attachments in the media library.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists videos or images with their optimization state, and sums up the library.
 */
final class Library {

	/**
	 * Status filters the lists accept.
	 */
	public const FILTERS = array( 'all', 'unoptimized', 'active', 'optimized', 'skipped', 'failed' );

	/**
	 * Media types the lists cover.
	 */
	public const TYPES = array( 'video', 'image' );

	/**
	 * Counts per filter and the total size before and after optimization.
	 *
	 * `new` counts the attachments never sent to the optimizer, which "optimize all"
	 * picks up; restored ones count as unoptimized but are left alone. For images,
	 * the optimized size is what browsers that take WebP download.
	 *
	 * @param string $type `video` or `image`.
	 *
	 * @return array{counts: array<string, int>, new: int, original_size: int, optimized_size: int}
	 */
	public static function summary( string $type = 'video' ): array {
		global $wpdb;

		$mime_sql = self::mime_sql( $type );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $mime_sql is built from constants.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(m.meta_value, '') AS status, COUNT(*) AS total
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'attachment' AND {$mime_sql}
				GROUP BY status",
				Processor::META_STATUS
			),
			ARRAY_A
		);

		$counts = array_fill_keys( self::FILTERS, 0 );
		$new    = 0;
		foreach ( (array) $rows as $row ) {
			$total          = (int) $row['total'];
			$counts['all'] += $total;
			$counts[ self::filter_for( $row['status'] ) ] += $total;
			if ( '' === $row['status'] ) {
				$new += $total;
			}
		}

		$stats = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.meta_value
				FROM {$wpdb->postmeta} s
				INNER JOIN {$wpdb->postmeta} st ON st.post_id = s.post_id AND st.meta_key = %s AND st.meta_value = 'optimized'
				INNER JOIN {$wpdb->posts} p ON p.ID = s.post_id
				WHERE s.meta_key = %s AND {$mime_sql}",
				Processor::META_STATUS,
				Processor::META_STATS
			)
		);
		// phpcs:enable

		$original  = 0;
		$optimized = 0;
		foreach ( (array) $stats as $value ) {
			$value = maybe_unserialize( $value );
			if ( is_array( $value ) && ! empty( $value['original_size'] ) ) {
				$original  += (int) $value['original_size'];
				$optimized += (int) $value['optimized_size'];
			}
		}

		return array(
			'counts'         => $counts,
			'new'            => $new,
			'original_size'  => $original,
			'optimized_size' => $optimized,
		);
	}

	/**
	 * A page of attachments, newest first.
	 *
	 * @param string $filter   One of FILTERS.
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Attachments per page.
	 * @param string $search   Optional search term.
	 * @param string $type     `video` or `image`.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 */
	public static function query( string $filter, int $page, int $per_page, string $search = '', string $type = 'video' ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'post_mime_type' => self::mime_types( $type ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( '' !== $search ) {
			$args['s'] = $search;
		}

		$meta_query = self::meta_query( $filter );
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		$query = new \WP_Query( $args );

		return array(
			'items'       => array_map( array( self::class, 'item' ), $query->posts ),
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * IDs of the attachments that have never been sent to the optimizer.
	 *
	 * @param string $type `video` or `image`.
	 *
	 * @return int[]
	 */
	public static function new_ids( string $type = 'video' ): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'post_mime_type' => self::mime_types( $type ),
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array(
							'key'     => Processor::META_STATUS,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'   => Processor::META_STATUS,
							'value' => '',
						),
					),
				)
			)
		);
	}

	/**
	 * One attachment as the admin app shows it.
	 *
	 * @param \WP_Post|int $post Attachment.
	 *
	 * @return array<string, mixed>
	 */
	public static function item( $post ): array {
		$post = get_post( $post );
		if ( Images::is_image( $post->ID ) ) {
			return self::image_item( $post );
		}

		$id       = (int) $post->ID;
		$file     = (string) get_attached_file( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$stats    = get_post_meta( $id, Processor::META_STATS, true );
		$stats    = is_array( $stats ) ? $stats : array();
		$status   = Processor::status( $id );
		$thumb_id = (int) get_post_thumbnail_id( $id );
		$backup   = Processor::backup_path( $id );

		return array(
			'id'             => $id,
			'kind'           => 'video',
			'title'          => '' !== $post->post_title ? $post->post_title : wp_basename( $file ),
			'filename'       => wp_basename( $file ),
			'url'            => (string) wp_get_attachment_url( $id ),
			'edit_url'       => (string) get_edit_post_link( $id, 'raw' ),
			'thumbnail'      => $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '',
			'date'           => (string) get_post_time( 'c', true, $post ),
			'filesize'       => (int) ( $metadata['filesize'] ?? 0 ),
			'width'          => (int) ( $metadata['width'] ?? 0 ),
			'height'         => (int) ( $metadata['height'] ?? 0 ),
			'duration'       => (string) ( $metadata['length_formatted'] ?? '' ),
			'status'         => '' !== $status ? $status : 'none',
			'filter'         => self::filter_for( $status ),
			'progress'       => (float) get_post_meta( $id, Processor::META_PROGRESS, true ),
			'message'        => (string) get_post_meta( $id, Processor::META_MESSAGE, true ),
			'original_size'  => (int) ( $stats['original_size'] ?? 0 ),
			'optimized_size' => (int) ( $stats['optimized_size'] ?? 0 ),
			'before'         => self::stream( $stats['input'] ?? array() ),
			'after'          => self::stream( $stats['output'] ?? array() ),
			'has_backup'     => '' !== $backup,
			'backup_url'     => $backup ? Processor::backup_url( $id ) : '',
			'backup_size'    => $backup ? (int) filesize( $backup ) : 0,
		);
	}

	/**
	 * One image as the admin app shows it.
	 *
	 * @param \WP_Post $post Attachment.
	 *
	 * @return array<string, mixed>
	 */
	private static function image_item( \WP_Post $post ): array {
		$id       = (int) $post->ID;
		$file     = (string) get_attached_file( $id );
		$url      = (string) wp_get_attachment_url( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$stats    = get_post_meta( $id, Processor::META_STATS, true );
		$stats    = is_array( $stats ) ? $stats : array();
		$records  = isset( $stats['files'] ) && is_array( $stats['files'] ) ? $stats['files'] : array();
		$status   = Processor::status( $id );
		$thumb    = wp_get_attachment_image_url( $id, 'thumbnail' );

		$formats = array();
		$full    = null;
		foreach ( $records as $record ) {
			$formats = array_merge( $formats, array_keys( (array) ( $record['formats'] ?? array() ) ) );
			if ( 'full' === ( $record['name'] ?? '' ) ) {
				$full = $record;
			}
		}

		return array(
			'id'             => $id,
			'kind'           => 'image',
			'title'          => '' !== $post->post_title ? $post->post_title : wp_basename( $file ),
			'filename'       => wp_basename( $file ),
			'url'            => $url,
			'edit_url'       => (string) get_edit_post_link( $id, 'raw' ),
			'thumbnail'      => $thumb ? (string) $thumb : $url,
			'date'           => (string) get_post_time( 'c', true, $post ),
			'filesize'       => (int) ( $metadata['filesize'] ?? ( is_file( $file ) ? filesize( $file ) : 0 ) ),
			'width'          => (int) ( $metadata['width'] ?? 0 ),
			'height'         => (int) ( $metadata['height'] ?? 0 ),
			'duration'       => '',
			'status'         => '' !== $status ? $status : 'none',
			'filter'         => self::filter_for( $status ),
			'progress'       => (float) get_post_meta( $id, Processor::META_PROGRESS, true ),
			'message'        => (string) get_post_meta( $id, Processor::META_MESSAGE, true ),
			'original_size'  => (int) ( $stats['original_size'] ?? 0 ),
			'optimized_size' => (int) ( $stats['optimized_size'] ?? 0 ),
			'before'         => null,
			'after'          => null,
			'files'          => count( $records ),
			'formats'        => array_values( array_unique( $formats ) ),
			'has_backup'     => Images::can_restore( $id ),
			'backup_url'     => '',
			'backup_size'    => 0,
			'compare'        => $full ? self::image_compare( $id, $full, $url ) : null,
		);
	}

	/**
	 * The two versions of the full-size image worth comparing, if there are two.
	 * The original is read through admin-post.php, as the server may answer the
	 * file's own URL with its WebP version.
	 *
	 * @param int    $id     Attachment ID.
	 * @param array  $record The full-size file's record.
	 * @param string $url    The attachment URL.
	 *
	 * @return array<string, array<string, mixed>>|null
	 */
	private static function image_compare( int $id, array $record, string $url ): ?array {
		$backup     = (string) ( $record['backup'] ?? '' );
		$has_backup = '' !== $backup && is_file( Uploads::path( $backup ) );
		$webp       = (int) ( $record['formats']['webp'] ?? 0 );
		$size       = (int) ( $record['size'] ?? 0 );

		$before = array(
			'label' => __( 'Original', 'jcore-pakkaus' ),
			'url'   => Admin\Media::original_url( $id ),
			'size'  => $has_backup ? (int) ( $record['original_size'] ?? 0 ) : $size,
		);
		if ( ! $has_backup && $size < (int) ( $record['original_size'] ?? 0 ) ) {
			$before['label'] = __( 'Optimized PNG', 'jcore-pakkaus' );
		}

		if ( $webp ) {
			$after = array(
				'label' => __( 'WebP', 'jcore-pakkaus' ),
				'url'   => Images::sidecar( $url, 'webp' ),
				'size'  => $webp,
			);
		} elseif ( $has_backup ) {
			$after = array(
				'label' => __( 'Optimized PNG', 'jcore-pakkaus' ),
				'url'   => $url,
				'size'  => $size,
			);
		} else {
			return null;
		}

		return array(
			'before' => $before,
			'after'  => $after,
		);
	}

	/**
	 * MIME types of a list, as WP_Query takes them.
	 *
	 * @param string $type `video` or `image`.
	 *
	 * @return string|string[]
	 */
	private static function mime_types( string $type ) {
		return 'image' === $type ? Images::MIME_TYPES : 'video';
	}

	/**
	 * SQL condition on `p.post_mime_type` selecting a list's attachments, for use
	 * inside $wpdb->prepare() (hence the doubled percent sign).
	 *
	 * @param string $type `video` or `image`.
	 *
	 * @return string
	 */
	private static function mime_sql( string $type ): string {
		if ( 'image' === $type ) {
			return "p.post_mime_type IN ('" . implode( "', '", Images::MIME_TYPES ) . "')";
		}

		return "p.post_mime_type LIKE 'video/%%'";
	}

	/**
	 * The filter a stored status falls under.
	 *
	 * @param string $status Stored status.
	 *
	 * @return string
	 */
	private static function filter_for( string $status ): string {
		if ( in_array( $status, Processor::ACTIVE_STATUSES, true ) ) {
			return 'active';
		}
		if ( in_array( $status, array( 'optimized', 'skipped', 'failed' ), true ) ) {
			return $status;
		}

		return 'unoptimized';
	}

	/**
	 * Meta query selecting the videos under a filter.
	 *
	 * @param string $filter One of FILTERS.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function meta_query( string $filter ): array {
		switch ( $filter ) {
			case 'unoptimized':
				return array(
					'relation' => 'OR',
					array(
						'key'     => Processor::META_STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Processor::META_STATUS,
						'value'   => array( '', 'restored' ),
						'compare' => 'IN',
					),
				);
			case 'active':
				return array(
					array(
						'key'     => Processor::META_STATUS,
						'value'   => Processor::ACTIVE_STATUSES,
						'compare' => 'IN',
					),
				);
			case 'optimized':
			case 'skipped':
			case 'failed':
				return array(
					array(
						'key'   => Processor::META_STATUS,
						'value' => $filter,
					),
				);
			default:
				return array();
		}
	}

	/**
	 * The parts of a probed stream worth showing.
	 *
	 * @param mixed $info Media info from the service.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function stream( $info ): ?array {
		if ( ! is_array( $info ) || empty( $info['width'] ) ) {
			return null;
		}

		return array(
			'codec'  => (string) ( $info['video_codec'] ?? '' ),
			'width'  => (int) $info['width'],
			'height' => (int) ( $info['height'] ?? 0 ),
			'fps'    => isset( $info['fps'] ) ? round( (float) $info['fps'], 2 ) : null,
			'hdr'    => ! empty( $info['hdr'] ),
		);
	}
}
