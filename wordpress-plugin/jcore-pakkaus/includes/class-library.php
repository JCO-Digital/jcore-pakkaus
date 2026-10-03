<?php
/**
 * Queries over the video attachments in the media library.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists videos with their optimization state, and sums up the library.
 */
final class Library {

	/**
	 * Status filters the video list accepts.
	 */
	public const FILTERS = array( 'all', 'unoptimized', 'active', 'optimized', 'skipped', 'failed' );

	/**
	 * Video counts per filter and the total size before and after optimization.
	 *
	 * `new` counts the videos never sent to the optimizer, which "optimize all"
	 * picks up; restored videos count as unoptimized but are left alone.
	 *
	 * @return array{counts: array<string, int>, new: int, original_size: int, optimized_size: int}
	 */
	public static function summary(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(m.meta_value, '') AS status, COUNT(*) AS total
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
				GROUP BY status",
				Processor::META_STATUS,
				'video/%'
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stats = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.meta_value
				FROM {$wpdb->postmeta} s
				INNER JOIN {$wpdb->postmeta} st ON st.post_id = s.post_id AND st.meta_key = %s AND st.meta_value = 'optimized'
				WHERE s.meta_key = %s",
				Processor::META_STATUS,
				Processor::META_STATS
			)
		);

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
	 * A page of videos, newest first.
	 *
	 * @param string $filter   One of FILTERS.
	 * @param int    $page     1-based page number.
	 * @param int    $per_page Videos per page.
	 * @param string $search   Optional search term.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 */
	public static function query( string $filter, int $page, int $per_page, string $search = '' ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'post_mime_type' => 'video',
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
	 * IDs of the videos that have never been sent to the optimizer.
	 *
	 * @return int[]
	 */
	public static function new_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'any',
					'post_mime_type' => 'video',
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
	 * One video as the admin app shows it.
	 *
	 * @param \WP_Post|int $post Attachment.
	 *
	 * @return array<string, mixed>
	 */
	public static function item( $post ): array {
		$post     = get_post( $post );
		$id       = (int) $post->ID;
		$file     = (string) get_attached_file( $id );
		$metadata = wp_get_attachment_metadata( $id );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$stats    = get_post_meta( $id, Processor::META_STATS, true );
		$stats    = is_array( $stats ) ? $stats : array();
		$status   = Processor::status( $id );
		$thumb_id = (int) get_post_thumbnail_id( $id );

		return array(
			'id'             => $id,
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
			'has_backup'     => '' !== Processor::backup_path( $id ),
		);
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
