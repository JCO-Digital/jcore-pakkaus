<?php
/**
 * Admin UI: settings page, media library column, row/bulk actions.
 *
 * @package Video_Optimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin integration.
 */
class Vopt_Admin {

	const PAGE = 'video-optimizer';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VOPT_FILE ), array( __CLASS__, 'plugin_links' ) );

		add_action( 'admin_post_vopt_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_post_vopt_optimize', array( __CLASS__, 'handle_optimize' ) );
		add_action( 'admin_post_vopt_restore', array( __CLASS__, 'handle_restore' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		add_filter( 'manage_media_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'media_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'attachment_fields' ), 10, 2 );
	}

	/**
	 * Settings page menu entry.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'Video Optimizer', 'video-optimizer' ),
			__( 'Video Optimizer', 'video-optimizer' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register the option.
	 */
	public static function register_settings() {
		register_setting(
			'vopt_settings_group',
			Vopt_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Vopt_Settings', 'sanitize' ),
				'default'           => Vopt_Settings::defaults(),
			)
		);
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function plugin_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ), esc_html__( 'Settings', 'video-optimizer' ) ) );
		return $links;
	}

	/**
	 * Render the settings page.
	 */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = Vopt_Settings::all();
		$name = Vopt_Settings::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Video Optimizer', 'video-optimizer' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'vopt_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Optimizer service', 'video-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vopt-service-url"><?php esc_html_e( 'Service URL', 'video-optimizer' ); ?></label></th>
						<td>
							<input type="url" id="vopt-service-url" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[service_url]" value="<?php echo esc_attr( $s['service_url'] ); ?>" placeholder="https://video-optimizer.example.com" <?php disabled( Vopt_Settings::is_constant( 'service_url' ) ); ?>>
							<?php self::constant_note( 'service_url', 'VIDEO_OPTIMIZER_SERVICE_URL' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vopt-api-token"><?php esc_html_e( 'API token', 'video-optimizer' ); ?></label></th>
						<td>
							<input type="password" id="vopt-api-token" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[api_token]" value="" autocomplete="new-password" placeholder="<?php echo $s['api_token'] ? esc_attr__( '•••••••• (saved, leave empty to keep)', 'video-optimizer' ) : ''; ?>" <?php disabled( Vopt_Settings::is_constant( 'api_token' ) ); ?>>
							<?php self::constant_note( 'api_token', 'VIDEO_OPTIMIZER_API_TOKEN' ); ?>
							<p class="description"><?php esc_html_e( 'The API_TOKEN configured on the optimizer service.', 'video-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic optimization', 'video-optimizer' ); ?></th>
						<td>
							<?php self::checkbox( 'auto_optimize', __( 'Optimize videos automatically when they are uploaded', 'video-optimizer' ), $s ); ?>
							<p>
								<label>
									<?php esc_html_e( 'Skip videos smaller than', 'video-optimizer' ); ?>
									<input type="number" min="0" step="0.1" class="small-text" name="<?php echo esc_attr( $name ); ?>[min_file_size_mb]" value="<?php echo esc_attr( $s['min_file_size_mb'] ); ?>"> MB
								</label>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Encoding', 'video-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vopt-codec"><?php esc_html_e( 'Video codec', 'video-optimizer' ); ?></label></th>
						<td>
							<select id="vopt-codec" name="<?php echo esc_attr( $name ); ?>[codec]">
								<option value="h264" <?php selected( $s['codec'], 'h264' ); ?>><?php esc_html_e( 'H.264 (plays everywhere)', 'video-optimizer' ); ?></option>
								<option value="h265" <?php selected( $s['codec'], 'h265' ); ?>><?php esc_html_e( 'H.265 / HEVC (smaller, limited browser support)', 'video-optimizer' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vopt-crf"><?php esc_html_e( 'Quality (CRF)', 'video-optimizer' ); ?></label></th>
						<td>
							<input type="number" id="vopt-crf" min="0" max="51" class="small-text" name="<?php echo esc_attr( $name ); ?>[crf]" value="<?php echo esc_attr( $s['crf'] ); ?>">
							<p class="description"><?php esc_html_e( 'Lower is better quality and larger files. 20–23 is visually transparent for H.264, 26–28 is a good web default. For H.265 use about 4–6 higher for the same quality.', 'video-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vopt-preset"><?php esc_html_e( 'Encoder speed', 'video-optimizer' ); ?></label></th>
						<td>
							<select id="vopt-preset" name="<?php echo esc_attr( $name ); ?>[preset]">
								<?php foreach ( Vopt_Settings::PRESETS as $preset ) : ?>
									<option value="<?php echo esc_attr( $preset ); ?>" <?php selected( $s['preset'], $preset ); ?>><?php echo esc_html( $preset ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Slower presets produce smaller files at the same quality but take longer.', 'video-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vopt-res"><?php esc_html_e( 'Maximum resolution', 'video-optimizer' ); ?></label></th>
						<td>
							<select id="vopt-res" name="<?php echo esc_attr( $name ); ?>[max_resolution]">
								<?php foreach ( Vopt_Settings::RESOLUTIONS as $res ) : ?>
									<option value="<?php echo esc_attr( $res ); ?>" <?php selected( (int) $s['max_resolution'], $res ); ?>>
										<?php echo $res ? esc_html( $res . 'p' ) : esc_html__( 'Keep original', 'video-optimizer' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Applied to the shorter side, so portrait videos are handled correctly. Videos are never upscaled.', 'video-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="vopt-fps"><?php esc_html_e( 'Maximum frame rate', 'video-optimizer' ); ?></label></th>
						<td>
							<input type="number" id="vopt-fps" min="0" max="240" step="any" class="small-text" name="<?php echo esc_attr( $name ); ?>[max_fps]" value="<?php echo esc_attr( $s['max_fps'] ); ?>"> fps
							<p class="description"><?php esc_html_e( '0 keeps the original frame rate.', 'video-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Audio', 'video-optimizer' ); ?></th>
						<td>
							<label>
								<?php esc_html_e( 'AAC bitrate', 'video-optimizer' ); ?>
								<input type="number" min="32" max="512" class="small-text" name="<?php echo esc_attr( $name ); ?>[audio_bitrate]" value="<?php echo esc_attr( $s['audio_bitrate'] ); ?>"> kbit/s
							</label>
							<br>
							<?php self::checkbox( 'remove_audio', __( 'Remove the audio track entirely', 'video-optimizer' ), $s ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Other', 'video-optimizer' ); ?></th>
						<td>
							<?php self::checkbox( 'tonemap_hdr', __( 'Convert HDR videos (e.g. from iPhones) to SDR so colors look right in every browser', 'video-optimizer' ), $s ); ?><br>
							<?php self::checkbox( 'strip_metadata', __( 'Strip metadata (GPS location, device info, …)', 'video-optimizer' ), $s ); ?><br>
							<?php self::checkbox( 'keep_original', __( 'Keep a backup of the original file (allows restoring, uses more disk space)', 'video-optimizer' ), $s ); ?>
							<p>
								<label>
									<?php esc_html_e( 'Only replace the original when the result is at least', 'video-optimizer' ); ?>
									<input type="number" min="0" max="100" step="any" class="small-text" name="<?php echo esc_attr( $name ); ?>[min_savings_percent]" value="<?php echo esc_attr( $s['min_savings_percent'] ); ?>">
									<?php esc_html_e( '% smaller', 'video-optimizer' ); ?>
								</label>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr>
			<h2 class="title"><?php esc_html_e( 'Connection', 'video-optimizer' ); ?></h2>
			<p><?php esc_html_e( 'Save your settings first, then check that WordPress can reach the service and the token is accepted.', 'video-optimizer' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="vopt_test_connection">
				<?php wp_nonce_field( 'vopt_test_connection' ); ?>
				<?php submit_button( __( 'Test connection', 'video-optimizer' ), 'secondary', 'submit', false ); ?>
			</form>
			<p class="description">
				<?php
				printf(
					/* translators: %s: callback URL */
					esc_html__( 'The service must be able to download files from this site and send results to %s.', 'video-optimizer' ),
					'<code>' . esc_html( rest_url( Vopt_Rest::NAMESPACE . '/callback' ) ) . '</code>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Checkbox field.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 */
	private static function checkbox( $key, $label, $s ) {
		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
			esc_attr( Vopt_Settings::OPTION ),
			esc_attr( $key ),
			checked( ! empty( $s[ $key ] ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Note shown when a setting is locked by a constant.
	 *
	 * @param string $key      Setting key.
	 * @param string $constant Constant name.
	 */
	private static function constant_note( $key, $constant ) {
		if ( Vopt_Settings::is_constant( $key ) ) {
			/* translators: %s: constant name */
			echo '<p class="description">' . sprintf( esc_html__( 'Defined by %s in wp-config.php.', 'video-optimizer' ), '<code>' . esc_html( $constant ) . '</code>' ) . '</p>';
		}
	}

	/**
	 * Test connection handler.
	 */
	public static function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'video-optimizer' ), 403 );
		}
		check_admin_referer( 'vopt_test_connection' );

		$info = Vopt_Client::info();
		if ( is_wp_error( $info ) ) {
			$notice = array(
				'type'    => 'error',
				'message' => $info->get_error_message(),
			);
		} else {
			$codecs = array_keys( array_filter( (array) $info['codecs'] ) );
			$notice = array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: service version, 2: codecs, 3: ffmpeg version */
					__( 'Connected to optimizer service %1$s (codecs: %2$s; %3$s).', 'video-optimizer' ),
					$info['version'],
					implode( ', ', $codecs ),
					$info['ffmpeg']
				),
			);
		}

		self::redirect_with_notice( admin_url( 'options-general.php?page=' . self::PAGE ), $notice );
	}

	/**
	 * Single "Optimize" action.
	 */
	public static function handle_optimize() {
		$id = self::verify_attachment_action( 'vopt_optimize' );
		if ( ! Vopt_Settings::is_configured() ) {
			$result = new WP_Error( 'vopt_not_configured', __( 'Configure the optimizer service first.', 'video-optimizer' ) );
		} else {
			$result = Vopt_Processor::queue( $id );
		}
		self::redirect_with_notice(
			wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ),
			is_wp_error( $result )
				? array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					'message' => __( 'The video was sent to the optimizer.', 'video-optimizer' ),
				)
		);
	}

	/**
	 * Single "Restore original" action.
	 */
	public static function handle_restore() {
		$id     = self::verify_attachment_action( 'vopt_restore' );
		$result = Vopt_Processor::restore( $id );
		self::redirect_with_notice(
			wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ),
			is_wp_error( $result )
				? array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					'message' => __( 'The original video was restored.', 'video-optimizer' ),
				)
		);
	}

	/**
	 * Nonce + capability check for per-attachment actions.
	 *
	 * @param string $action Action name.
	 * @return int Attachment ID.
	 */
	private static function verify_attachment_action( $action ) {
		$id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( $action . '_' . $id );
		if ( ! $id || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'video-optimizer' ), 403 );
		}
		return $id;
	}

	/**
	 * Action URL for an attachment.
	 *
	 * @param string $action Action.
	 * @param int    $id     Attachment ID.
	 * @return string
	 */
	private static function action_url( $action, $id ) {
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
	 * Store a one-off notice for the current user and redirect.
	 *
	 * @param string $url    Redirect target.
	 * @param array  $notice Notice with 'type' and 'message'.
	 */
	private static function redirect_with_notice( $url, $notice ) {
		set_transient( 'vopt_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Print queued notices.
	 */
	public static function notices() {
		$key    = 'vopt_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( $notice ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['message'] )
			);
		}

		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( 'upload', 'settings_page_' . self::PAGE ), true ) && ! Vopt_Settings::is_configured() && current_user_can( 'manage_options' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'Video Optimizer is not connected to an optimizer service yet.', 'video-optimizer' ),
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ),
				esc_html__( 'Configure it', 'video-optimizer' )
			);
		}
	}

	/**
	 * Media list column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns['vopt'] = __( 'Video optimization', 'video-optimizer' );
		return $columns;
	}

	/**
	 * Render the media list column.
	 *
	 * @param string $column Column.
	 * @param int    $id     Attachment ID.
	 */
	public static function render_column( $column, $id ) {
		if ( 'vopt' === $column && Vopt_Processor::is_video( $id ) ) {
			echo self::status_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_html().
		}
	}

	/**
	 * Row actions in the media list.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Attachment.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! Vopt_Processor::is_video( $post->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		return array_merge( $actions, self::action_links( $post->ID ) );
	}

	/**
	 * Available action links for an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	private static function action_links( $id ) {
		$links  = array();
		$status = Vopt_Processor::status( $id );
		if ( ! in_array( $status, array( 'queued', 'processing' ), true ) ) {
			$label             = in_array( $status, array( 'optimized', 'skipped' ), true ) ? __( 'Re-optimize', 'video-optimizer' ) : __( 'Optimize video', 'video-optimizer' );
			$links['vopt_opt'] = sprintf( '<a href="%s">%s</a>', esc_url( self::action_url( 'vopt_optimize', $id ) ), esc_html( $label ) );
			if ( Vopt_Processor::backup_path( $id ) ) {
				$links['vopt_restore'] = sprintf(
					'<a href="%s" onclick="return confirm(%s)">%s</a>',
					esc_url( self::action_url( 'vopt_restore', $id ) ),
					esc_attr( wp_json_encode( __( 'Replace the optimized video with the original file?', 'video-optimizer' ) ) ),
					esc_html__( 'Restore original', 'video-optimizer' )
				);
			}
		}
		return $links;
	}

	/**
	 * Bulk action entry.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$actions['vopt_optimize'] = __( 'Optimize videos', 'video-optimizer' );
		return $actions;
	}

	/**
	 * Handle the bulk action.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Attachment IDs.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect, $action, $ids ) {
		if ( 'vopt_optimize' !== $action ) {
			return $redirect;
		}
		if ( ! Vopt_Settings::is_configured() ) {
			self::redirect_with_notice(
				$redirect,
				array(
					'type'    => 'error',
					'message' => __( 'Configure the optimizer service first.', 'video-optimizer' ),
				)
			);
		}

		$queued = 0;
		$errors = array();
		foreach ( $ids as $id ) {
			if ( ! Vopt_Processor::is_video( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$result = Vopt_Processor::queue( $id );
			if ( is_wp_error( $result ) ) {
				$errors[] = get_the_title( $id ) . ': ' . $result->get_error_message();
			} else {
				++$queued;
			}
		}

		/* translators: %d: number of videos */
		$message = sprintf( _n( '%d video sent to the optimizer.', '%d videos sent to the optimizer.', $queued, 'video-optimizer' ), $queued );
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
	 * Show the status in the attachment details modal / edit screen.
	 *
	 * @param array   $fields Fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public static function attachment_fields( $fields, $post ) {
		if ( ! Vopt_Processor::is_video( $post->ID ) ) {
			return $fields;
		}
		$links = current_user_can( 'edit_post', $post->ID ) ? self::action_links( $post->ID ) : array();

		$fields['vopt_status'] = array(
			'label' => __( 'Optimization', 'video-optimizer' ),
			'input' => 'html',
			'html'  => self::status_html( $post->ID ) . ( $links ? '<p>' . implode( ' | ', $links ) . '</p>' : '' ),
		);
		return $fields;
	}

	/**
	 * Human readable, escaped status.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function status_html( $id ) {
		$status  = Vopt_Processor::status( $id );
		$message = (string) get_post_meta( $id, Vopt_Processor::META_MESSAGE, true );
		$stats   = get_post_meta( $id, Vopt_Processor::META_STATS, true );

		switch ( $status ) {
			case 'pending':
				$text = __( 'Waiting to be sent…', 'video-optimizer' );
				break;
			case 'queued':
				$text = __( 'Queued', 'video-optimizer' );
				break;
			case 'processing':
				$progress = (float) get_post_meta( $id, Vopt_Processor::META_PROGRESS, true );
				/* translators: %s: percentage */
				$text = sprintf( __( 'Optimizing… %s%%', 'video-optimizer' ), number_format_i18n( $progress, 0 ) );
				break;
			case 'optimized':
				$text = __( 'Optimized', 'video-optimizer' );
				if ( is_array( $stats ) && ! empty( $stats['original_size'] ) ) {
					$text = sprintf(
						/* translators: 1: original size, 2: optimized size, 3: percentage saved */
						__( 'Optimized: %1$s → %2$s (−%3$s%%)', 'video-optimizer' ),
						size_format( $stats['original_size'], 1 ),
						size_format( $stats['optimized_size'], 1 ),
						number_format_i18n( ( 1 - $stats['optimized_size'] / $stats['original_size'] ) * 100, 0 )
					);
				}
				break;
			case 'skipped':
				$text = __( 'Skipped', 'video-optimizer' );
				break;
			case 'failed':
				$text = __( 'Failed', 'video-optimizer' );
				break;
			case 'restored':
				$text = __( 'Original restored', 'video-optimizer' );
				break;
			default:
				$text = __( 'Not optimized', 'video-optimizer' );
		}

		$colors = array(
			'optimized' => '#00a32a',
			'failed'    => '#d63638',
			'skipped'   => '#996800',
		);
		$style  = isset( $colors[ $status ] ) ? ' style="color:' . $colors[ $status ] . '"' : '';
		$html   = '<strong' . $style . '>' . esc_html( $text ) . '</strong>';
		if ( '' !== $message ) {
			$html .= '<br><span class="description">' . esc_html( $message ) . '</span>';
		}
		return $html;
	}
}
