<?php
/**
 * Media library integration: status column, row and bulk actions, attachment details.
 *
 * @package Jcore\Pakkaus
 */

namespace Jcore\Pakkaus\Admin;

use Jcore\Pakkaus\Processor;
use Jcore\Pakkaus\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows each video's optimization state where the media library lists it.
 */
final class Media {

	/**
	 * Style handle, also the build entry.
	 */
	private const HANDLE = 'jcore-pakkaus-media';

	/**
	 * Build entry the styles come from.
	 */
	private const ENTRY = 'media';

	/**
	 * Media list column key.
	 */
	private const COLUMN = 'jcore_pakkaus';

	/**
	 * Hooks the media library screens.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_jcore_pakkaus_optimize', array( self::class, 'handle_optimize' ) );
		add_action( 'admin_post_jcore_pakkaus_restore', array( self::class, 'handle_restore' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_on_screen' ) );
		add_action( 'wp_enqueue_media', array( self::class, 'enqueue_style' ) );

		add_filter( 'manage_media_columns', array( self::class, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( self::class, 'render_column' ), 10, 2 );
		add_filter( 'media_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( self::class, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'attachment_fields_to_edit', array( self::class, 'attachment_fields' ), 10, 2 );
	}

	/**
	 * Enqueues the badge styles on the media library and attachment edit screens.
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_on_screen( string $hook ): void {
		if ( in_array( $hook, array( 'upload.php', 'post.php' ), true ) ) {
			self::enqueue_style();
		}
	}

	/**
	 * Enqueues the badge styles. Also runs wherever the media modal is loaded.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		$asset_file = JCORE_PAKKAUS_PATH . 'build/' . self::ENTRY . '.asset.php';
		$style_file = JCORE_PAKKAUS_PATH . 'build/style-' . self::ENTRY . '.css';
		if ( ! is_readable( $asset_file ) || ! is_readable( $style_file ) ) {
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_style( self::HANDLE, JCORE_PAKKAUS_URL . 'build/style-' . self::ENTRY . '.css', array(), $asset['version'] );
	}

	/**
	 * Single "Optimize" action.
	 *
	 * @return void
	 */
	public static function handle_optimize(): void {
		$id = self::verify_attachment_action( 'jcore_pakkaus_optimize' );
		if ( ! Settings::is_configured() ) {
			$result = new \WP_Error( 'jcore_pakkaus_not_configured', __( 'Connect the optimizer service in the settings first.', 'jcore-pakkaus' ) );
		} else {
			$result = Processor::queue( $id );
		}

		self::redirect_with_notice(
			self::referer(),
			is_wp_error( $result )
				? array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					'message' => __( 'The video was sent to the optimizer.', 'jcore-pakkaus' ),
				)
		);
	}

	/**
	 * Single "Restore original" action.
	 *
	 * @return void
	 */
	public static function handle_restore(): void {
		$id     = self::verify_attachment_action( 'jcore_pakkaus_restore' );
		$result = Processor::restore( $id );

		self::redirect_with_notice(
			self::referer(),
			is_wp_error( $result )
				? array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					'message' => __( 'The original video was restored.', 'jcore-pakkaus' ),
				)
		);
	}

	/**
	 * Prints the queued one-off notice, and a reminder on the media library
	 * while the service is not connected.
	 *
	 * @return void
	 */
	public static function notices(): void {
		$key    = 'jcore_pakkaus_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['message'] )
			);
		}

		$screen = get_current_screen();
		if ( $screen && 'upload' === $screen->id && ! Settings::is_configured() && current_user_can( 'manage_options' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Videos are not being optimized: no optimizer service is connected yet.', 'jcore-pakkaus' ),
				esc_url( Menu::url( 'settings' ) ),
				esc_html__( 'Connect it', 'jcore-pakkaus' )
			);
		}
	}

	/**
	 * Adds the media list column.
	 *
	 * @param array<string, string> $columns Columns.
	 *
	 * @return array<string, string>
	 */
	public static function add_column( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'Optimization', 'jcore-pakkaus' );

		return $columns;
	}

	/**
	 * Renders the media list column.
	 *
	 * @param string $column Column key.
	 * @param int    $id     Attachment ID.
	 *
	 * @return void
	 */
	public static function render_column( $column, $id ): void {
		if ( self::COLUMN === $column && Processor::is_video( $id ) ) {
			echo self::status_html( (int) $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_html().
		}
	}

	/**
	 * Adds the optimize and restore links to a video's row actions.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Attachment.
	 *
	 * @return array<string, string>
	 */
	public static function row_actions( $actions, $post ): array {
		if ( ! Processor::is_video( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		return array_merge( $actions, self::action_links( $post->ID ) );
	}

	/**
	 * Adds the bulk action.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 *
	 * @return array<string, string>
	 */
	public static function bulk_actions( $actions ): array {
		$actions['jcore_pakkaus_optimize'] = __( 'Optimize videos', 'jcore-pakkaus' );

		return $actions;
	}

	/**
	 * Handles the bulk action.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Attachment IDs.
	 *
	 * @return string
	 */
	public static function handle_bulk_action( $redirect, $action, $ids ) {
		if ( 'jcore_pakkaus_optimize' !== $action ) {
			return $redirect;
		}
		if ( ! Settings::is_configured() ) {
			self::redirect_with_notice(
				$redirect,
				array(
					'type'    => 'error',
					'message' => __( 'Connect the optimizer service in the settings first.', 'jcore-pakkaus' ),
				)
			);
		}

		$queued = 0;
		$errors = array();
		foreach ( $ids as $id ) {
			if ( ! Processor::is_video( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$result = Processor::queue( $id );
			if ( is_wp_error( $result ) ) {
				$errors[] = get_the_title( $id ) . ': ' . $result->get_error_message();
			} else {
				++$queued;
			}
		}

		/* translators: %d: number of videos */
		$message = sprintf( _n( '%d video sent to the optimizer.', '%d videos sent to the optimizer.', $queued, 'jcore-pakkaus' ), $queued );
		if ( $errors ) {
			$message .= ' ' . implode( ' ', $errors );
		}

		self::redirect_with_notice(
			$redirect,
			array(
				'type'    => $errors ? 'error' : 'success',
				'message' => $message,
			)
		);

		return $redirect;
	}

	/**
	 * Shows the status in the attachment details modal and edit screen.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @param \WP_Post             $post   Attachment.
	 *
	 * @return array<string, mixed>
	 */
	public static function attachment_fields( $fields, $post ): array {
		if ( ! Processor::is_video( $post->ID ) ) {
			return $fields;
		}
		$links = current_user_can( 'edit_post', $post->ID ) ? self::action_links( $post->ID ) : array();

		$fields['jcore_pakkaus_status'] = array(
			'label' => __( 'Optimization', 'jcore-pakkaus' ),
			'input' => 'html',
			'html'  => self::status_html( $post->ID ) . ( $links ? '<p class="jcore-pakkaus-actions">' . implode( ' | ', $links ) . '</p>' : '' ),
		);

		return $fields;
	}

	/**
	 * The status badge, size change and last message of a video, escaped.
	 *
	 * @param int $id Attachment ID.
	 *
	 * @return string
	 */
	public static function status_html( int $id ): string {
		$status  = Processor::status( $id );
		$message = (string) get_post_meta( $id, Processor::META_MESSAGE, true );
		$stats   = get_post_meta( $id, Processor::META_STATS, true );
		$detail  = '';

		switch ( $status ) {
			case 'pending':
				$label = __( 'Waiting', 'jcore-pakkaus' );
				break;
			case 'queued':
				$label = __( 'Queued', 'jcore-pakkaus' );
				break;
			case 'processing':
				$progress = max( 0, min( 100, (float) get_post_meta( $id, Processor::META_PROGRESS, true ) ) );
				/* translators: %s: percentage */
				$label  = sprintf( __( 'Optimizing %s%%', 'jcore-pakkaus' ), number_format_i18n( $progress, 0 ) );
				$detail = sprintf(
					'<span class="jcore-pakkaus-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="%1$d"><span style="width:%1$d%%"></span></span>',
					(int) round( $progress )
				);
				break;
			case 'optimized':
				$label = __( 'Optimized', 'jcore-pakkaus' );
				if ( is_array( $stats ) && ! empty( $stats['original_size'] ) ) {
					$detail = sprintf(
						'<span class="jcore-pakkaus-sizes">%1$s <span aria-hidden="true">&rarr;</span><span class="screen-reader-text">%2$s</span> %3$s <strong>&minus;%4$s%%</strong></span>',
						esc_html( size_format( $stats['original_size'], 1 ) ),
						esc_html__( 'to', 'jcore-pakkaus' ),
						esc_html( size_format( $stats['optimized_size'], 1 ) ),
						esc_html( number_format_i18n( ( 1 - $stats['optimized_size'] / $stats['original_size'] ) * 100, 0 ) )
					);
				}
				break;
			case 'skipped':
				$label = __( 'Kept original', 'jcore-pakkaus' );
				break;
			case 'failed':
				$label = __( 'Failed', 'jcore-pakkaus' );
				break;
			case 'restored':
				$label = __( 'Original restored', 'jcore-pakkaus' );
				break;
			default:
				$status = 'none';
				$label  = __( 'Not optimized', 'jcore-pakkaus' );
		}

		$html = sprintf(
			'<span class="jcore-pakkaus-status jcore-pakkaus-status--%s">%s</span>',
			esc_attr( $status ),
			esc_html( $label )
		) . $detail;

		if ( '' !== $message ) {
			$html .= '<span class="jcore-pakkaus-message">' . esc_html( $message ) . '</span>';
		}

		return '<div class="jcore-pakkaus-cell">' . $html . '</div>';
	}

	/**
	 * Optimize and restore links for an attachment.
	 *
	 * @param int $id Attachment ID.
	 *
	 * @return array<string, string>
	 */
	private static function action_links( int $id ): array {
		$links  = array();
		$status = Processor::status( $id );
		if ( in_array( $status, Processor::RUNNING, true ) ) {
			return $links;
		}

		$label = in_array( $status, array( 'optimized', 'skipped' ), true ) ? __( 'Re-optimize', 'jcore-pakkaus' ) : __( 'Optimize video', 'jcore-pakkaus' );

		$links['jcore_pakkaus_optimize'] = sprintf( '<a href="%s">%s</a>', esc_url( self::action_url( 'jcore_pakkaus_optimize', $id ) ), esc_html( $label ) );

		if ( Processor::backup_path( $id ) ) {
			$links['jcore_pakkaus_restore'] = sprintf(
				'<a href="%s" onclick="return confirm(%s)">%s</a>',
				esc_url( self::action_url( 'jcore_pakkaus_restore', $id ) ),
				esc_attr( wp_json_encode( __( 'Replace the optimized video with the original file?', 'jcore-pakkaus' ) ) ),
				esc_html__( 'Restore original', 'jcore-pakkaus' )
			);
		}

		return $links;
	}

	/**
	 * Nonce and capability check for the per-attachment actions.
	 *
	 * @param string $action Action name.
	 *
	 * @return int Attachment ID.
	 */
	private static function verify_attachment_action( string $action ): int {
		$id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified on the next line.
		check_admin_referer( $action . '_' . $id );
		if ( ! $id || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'jcore-pakkaus' ), 403 );
		}

		return $id;
	}

	/**
	 * Nonce-protected admin-post URL of an action.
	 *
	 * @param string $action Action.
	 * @param int    $id     Attachment ID.
	 *
	 * @return string
	 */
	private static function action_url( string $action, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => $action,
					'attachment_id' => $id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $id
		);
	}

	/**
	 * Where an action returns to.
	 *
	 * @return string
	 */
	private static function referer(): string {
		$referer = wp_get_referer();

		return $referer ? $referer : admin_url( 'upload.php' );
	}

	/**
	 * Stores a one-off notice for the current user and redirects.
	 *
	 * @param string               $url    Redirect target.
	 * @param array<string, mixed> $notice Notice with 'type' and 'message'.
	 *
	 * @return never
	 */
	private static function redirect_with_notice( string $url, array $notice ) {
		set_transient( 'jcore_pakkaus_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( $url );
		exit;
	}
}
