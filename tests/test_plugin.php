<?php
/** Standalone regressions using the real plugin classes and a small WordPress test double. */
use Jcore\Pakkaus\Conflicts;
use Jcore\Pakkaus\Images;
use Jcore\Pakkaus\Processor;
use Jcore\Pakkaus\Settings;

error_reporting( E_ALL );
$test_dir = sys_get_temp_dir() . '/jcore-pakkaus-tests-' . bin2hex( random_bytes( 6 ) );
mkdir( $test_dir . '/wp-admin/includes', 0777, true );
foreach ( array( 'file', 'media', 'image' ) as $include ) {
	file_put_contents( $test_dir . '/wp-admin/includes/' . $include . '.php', '<?php' );
}
define( 'ABSPATH', $test_dir . '/' );
define( 'DB_NAME', 'tests' );
define( 'JCORE_PAKKAUS_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message, $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

class Test_DB {
	public $prefix = 'wp_';
	public $available = true;
	public $held = false;
	public function prepare( $sql, ...$args ) { return $sql; }
	public function get_var( $sql ) {
		check( ! $this->held, 'Mutex must not be acquired recursively' );
		if ( ! $this->available ) { return '0'; }
		$this->held = true;
		return '1';
	}
	public function query( $sql ) {
		check( $this->held, 'Mutex must be held when released' );
		$this->held = false;
	}
}
$wpdb = new Test_DB();

function check( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function __( $text, $domain = null ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_cache_delete( $id, $group ) { unset( $GLOBALS['cache'][ $id ] ); }
function get_post_meta( $id, $key, $single = true ) {
	if ( ! isset( $GLOBALS['cache'][ $id ] ) ) {
		$GLOBALS['cache'][ $id ] = isset( $GLOBALS['meta'][ $id ] ) ? $GLOBALS['meta'][ $id ] : array();
	}
	return isset( $GLOBALS['cache'][ $id ][ $key ] ) ? $GLOBALS['cache'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['meta'][ $id ][ $key ] = $value;
	wp_cache_delete( $id, 'post_meta' );
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['meta'][ $id ][ $key ] );
	wp_cache_delete( $id, 'post_meta' );
}
function get_posts( $args ) {
	$ids = array_filter( array_keys( $GLOBALS['meta'] ), static function ( $id ) use ( $args ) {
		$mime = get_post_mime_type( $id );
		$type = isset( $args['post_mime_type'] ) ? $args['post_mime_type'] : '';
		if ( ( is_array( $type ) && ! in_array( $mime, $type, true ) ) || ( is_string( $type ) && '' !== $type && 0 !== strpos( $mime, $type ) ) ) {
			return false;
		}
		return in_array( $GLOBALS['meta'][ $id ]['_jcore_pakkaus_status'], Processor::ACTIVE_STATUSES, true );
	} );
	usort( $ids, static function ( $a, $b ) {
		return $GLOBALS['meta'][ $a ]['_jcore_pakkaus_updated'] <=> $GLOBALS['meta'][ $b ]['_jcore_pakkaus_updated'];
	} );
	return array_slice( $ids, 0, $args['posts_per_page'] );
}
function get_option( $key, $default = null ) { return 'active_plugins' === $key ? $GLOBALS['active_plugins'] : $GLOBALS['settings']; }
function is_multisite() { return false; }
function _n( $single, $plural, $number, $domain = null ) { return 1 === $number ? $single : $plural; }
function wp_sprintf( $pattern, $items ) { return implode( ' and ', $items ); }
function update_option( $key, $value ) { $GLOBALS['settings'] = $value; return true; }
function home_url( $path ) { return 'https://example.test' . $path; }
function untrailingslashit( $text ) { return rtrim( $text, '/' ); }
function esc_url_raw( $text, $protocols = null ) { return $text; }
function sanitize_text_field( $text ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function apply_filters( $hook, $value ) { return $value; }
function wp_remote_request( $url, $args ) {
	$job_id = basename( $url );
	if ( isset( $args['body'] ) ) {
		$GLOBALS['bodies'][ $job_id ] = json_decode( $args['body'], true );
	}
	if ( 'DELETE' === $args['method'] ) {
		$GLOBALS['deleted'][] = $job_id;
		return array( 'code' => 204, 'body' => '' );
	}
	$GLOBALS['requested'][] = $job_id;
	return call_user_func( $GLOBALS['on_get_job'], $job_id );
}
function wp_remote_get( $url, $args ) {
	if ( is_wp_error( $GLOBALS['download'] ) ) { return $GLOBALS['download']; }
	$bytes = $GLOBALS['output_bytes'];
	if ( preg_match( '#/files/(\d+)/(\w+)$#', $url, $match ) ) {
		$key = $match[1] . '/' . $match[2];
		if ( isset( $GLOBALS['fail_download'][ $key ] ) ) { return array( 'code' => $GLOBALS['fail_download'][ $key ], 'body' => '' ); }
		$bytes = $GLOBALS['image_outputs'][ $key ];
	}
	file_put_contents( $args['filename'], $bytes );
	return array( 'code' => $GLOBALS['download'], 'body' => '' );
}
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_response_message( $response ) { return 'HTTP error'; }
function wp_next_scheduled( $hook ) { return isset( $GLOBALS['scheduled'][ $hook ] ) ? 1 : false; }
function wp_schedule_event( $time, $schedule, $hook ) { $GLOBALS['scheduled'][ $hook ] = true; }
function wp_schedule_single_event( $time, $hook, $args ) { $GLOBALS['scheduled'][ $hook ] = true; }
function wp_clear_scheduled_hook( $hook ) { unset( $GLOBALS['scheduled'][ $hook ] ); }
function spawn_cron() {}
function get_attached_file( $id ) { return isset( $GLOBALS['attached'][ $id ] ) ? $GLOBALS['attached'][ $id ] : $GLOBALS['original']; }
function get_post_mime_type( $id ) { return isset( $GLOBALS['mime'][ $id ] ) ? $GLOBALS['mime'][ $id ] : 'video/mp4'; }
function wp_get_attachment_url( $id ) { return isset( $GLOBALS['urls'][ $id ] ) ? $GLOBALS['urls'][ $id ] : 'https://example.test/video.mp4'; }
function wp_get_attachment_metadata( $id ) { return isset( $GLOBALS['metadata_by_id'][ $id ] ) ? $GLOBALS['metadata_by_id'][ $id ] : array(); }
function wp_get_upload_dir() { return array( 'basedir' => $GLOBALS['test_dir'], 'baseurl' => 'https://example.test/wp-content/uploads' ); }
function path_join( $base, $path ) { return '/' === $path[0] ? $path : rtrim( $base, '/' ) . '/' . $path; }
function wp_basename( $path ) { return basename( $path ); }
function wp_unique_filename( $dir, $name ) { return file_exists( $dir . '/' . $name ) ? 'copy-' . $name : $name; }
function wp_generate_password( ...$args ) { return bin2hex( random_bytes( 4 ) ); }
function wp_delete_file( $path ) { if ( file_exists( $path ) ) { unlink( $path ); } }
function _wp_relative_upload_path( $path ) { return basename( $path ); }
function wp_generate_attachment_metadata( $id, $path ) { return array( 'filesize' => filesize( $path ) ); }
function wp_update_attachment_metadata( $id, $metadata ) { $GLOBALS['attachment_metadata'] = $metadata; $GLOBALS['metadata_by_id'][ $id ] = $metadata; }
function do_action( $hook, ...$args ) { $GLOBALS['actions'][] = $hook; }

$plugin_dir = dirname( __DIR__ ) . '/wordpress-plugin/jcore-pakkaus/includes/';
require $plugin_dir . 'class-settings.php';
require $plugin_dir . 'class-client.php';
require $plugin_dir . 'class-processor.php';
require $plugin_dir . 'class-images.php';
require $plugin_dir . 'class-conflicts.php';
eval( 'namespace Jcore\\Pakkaus\\Rest; final class Callback_Controller { public static function url() { return "https://example.test/wp-json/jcore-pakkaus/v1/callback"; } }' );

const PNG_HEAD = "\x89PNG\r\n\x1a\n";
function webp_bytes( $payload ) { return 'RIFF' . pack( 'V', 4 + strlen( $payload ) ) . 'WEBP' . $payload; }

/** An optimizing PNG attachment with a thumbnail, and the completed job for it. */
function image_fixture() {
	$dir = $GLOBALS['test_dir'];
	$GLOBALS['mime'][5] = 'image/png';
	$GLOBALS['attached'][5] = $dir . '/logo.png';
	$GLOBALS['urls'][5] = 'https://example.test/wp-content/uploads/logo.png';
	file_put_contents( $dir . '/logo.png', PNG_HEAD . str_repeat( 'large png ', 50 ) );
	file_put_contents( $dir . '/logo-150x150.png', PNG_HEAD . str_repeat( 'thumb ', 20 ) );
	$GLOBALS['metadata_by_id'][5] = array(
		'file'     => 'logo.png',
		'filesize' => filesize( $dir . '/logo.png' ),
		'sizes'    => array(
			'thumbnail' => array( 'file' => 'logo-150x150.png', 'filesize' => filesize( $dir . '/logo-150x150.png' ) ),
			'medium'    => array( 'file' => 'logo-150x150.png' ),
			'modern'    => array( 'file' => 'logo-300x300.webp' ),
			'missing'   => array( 'file' => 'logo-1024x1024.png' ),
		),
	);
	$GLOBALS['image_outputs'] = array( '0/optimized' => PNG_HEAD . 'small', '0/webp' => webp_bytes( 'full' ), '1/webp' => webp_bytes( 'thumb' ) );
	$GLOBALS['meta'][5] = array( '_jcore_pakkaus_status' => 'processing', '_jcore_pakkaus_job_id' => 'img-1', '_jcore_pakkaus_updated' => 1, '_jcore_pakkaus_files' => Images::files( 5 ) );
	$size = static function ( $key ) { return array( 'size' => strlen( $GLOBALS['image_outputs'][ $key ] ) ); };
	return array( 'id' => 'img-1', 'status' => 'completed', 'input' => array( 'size' => 1 ), 'output' => array( 'size' => 1, 'files' => array(
		array( 'name' => 'full', 'variants' => array( 'optimized' => $size( '0/optimized' ), 'webp' => $size( '0/webp' ) ) ),
		array( 'name' => 'thumbnail', 'variants' => array( 'webp' => $size( '1/webp' ), 'avif' => array( 'size' => 3 ) ) ),
	) ) );
}

function reset_fixture() {
	$GLOBALS['meta'] = array( 1 => array( '_jcore_pakkaus_status' => 'processing', '_jcore_pakkaus_job_id' => 'job-1', '_jcore_pakkaus_secret' => 'secret', '_jcore_pakkaus_updated' => 1 ) );
	$GLOBALS['cache'] = array();
	$GLOBALS['deleted'] = array();
	$GLOBALS['requested'] = array();
	$GLOBALS['scheduled'] = array();
	$GLOBALS['actions'] = array();
	$GLOBALS['settings'] = array( 'service_url' => 'https://optimizer.test', 'api_token' => 'token' );
	$GLOBALS['original'] = $GLOBALS['test_dir'] . '/video.mp4';
	$GLOBALS['original_bytes'] = str_repeat( 'original', 100 );
	file_put_contents( $GLOBALS['original'], $GLOBALS['original_bytes'] );
	$GLOBALS['output_bytes'] = pack( 'N', 24 ) . 'ftypisom' . str_repeat( 'video', 20 );
	$GLOBALS['download'] = 200;
	$GLOBALS['job'] = array( 'id' => 'job-1', 'status' => 'completed', 'input' => array(), 'output' => array( 'size' => strlen( $GLOBALS['output_bytes'] ) ) );
	$GLOBALS['on_get_job'] = static function ( $job_id ) {
		return array( 'code' => 200, 'body' => json_encode( $GLOBALS['job'] ) );
	};
	foreach ( array( 'mime', 'attached', 'urls', 'metadata_by_id', 'bodies', 'fail_download', 'image_outputs', 'active_plugins' ) as $key ) {
		$GLOBALS[ $key ] = array();
	}
	foreach ( glob( $GLOBALS['test_dir'] . '/{,.}*.{png,webp,part}', GLOB_BRACE ) as $file ) {
		unlink( $file );
	}
}

$tests = array(
	'stale poll cannot regress finalized attachment or delete a newer job' => static function () {
		foreach ( array( 'processing', 'failed', 'skipped' ) as $status ) {
			reset_fixture();
			$GLOBALS['on_get_job'] = static function ( $job_id ) use ( $status ) {
				// Another request changes the database; this request still has cached metadata.
				$GLOBALS['meta'][1]['_jcore_pakkaus_status'] = 'optimized';
				unset( $GLOBALS['meta'][1]['_jcore_pakkaus_job_id'] );
				return array( 'code' => 200, 'body' => json_encode( array( 'id' => $job_id, 'status' => $status, 'progress' => 99 ) ) );
			};
			Processor::refresh( 1 );
			check( 'optimized' === Processor::status( 1 ), 'Finalized status regressed' );
			check( ! $GLOBALS['deleted'], 'A stale response deleted a job' );
		}
		reset_fixture();
		$GLOBALS['on_get_job'] = static function ( $job_id ) {
			$GLOBALS['meta'][1]['_jcore_pakkaus_job_id'] = 'job-new';
			return array( 'code' => 200, 'body' => json_encode( array( 'id' => $job_id, 'status' => 'failed' ) ) );
		};
		Processor::refresh( 1 );
		check( 'job-new' === get_post_meta( 1, '_jcore_pakkaus_job_id', true ), 'New job was forgotten' );
		check( ! $GLOBALS['deleted'], 'New job was deleted' );
	},
	'stale 404 cannot fail a finalized attachment' => static function () {
		$GLOBALS['on_get_job'] = static function ( $job_id ) {
			$GLOBALS['meta'][1]['_jcore_pakkaus_status'] = 'optimized';
			unset( $GLOBALS['meta'][1]['_jcore_pakkaus_job_id'] );
			return array( 'code' => 404, 'body' => '' );
		};
		Processor::refresh( 1 );
		check( 'optimized' === Processor::status( 1 ), 'Stale 404 regressed status' );
	},
	'duplicate completed callback cannot reopen a finalized attachment' => static function () {
		Processor::status( 1 ); // Cache the old state before the other request finalizes.
		$GLOBALS['meta'][1]['_jcore_pakkaus_status'] = 'optimized';
		unset( $GLOBALS['meta'][1]['_jcore_pakkaus_job_id'] );
		Processor::handle_callback( 1, $GLOBALS['job'] );
		check( 'optimized' === Processor::status( 1 ), 'Duplicate callback reopened attachment' );
		check( ! $GLOBALS['scheduled'], 'Duplicate callback scheduled finalization' );
	},
	'polling errors do not starve job 21' => static function () {
		for ( $id = 1; $id <= 21; ++$id ) {
			$GLOBALS['meta'][$id] = array( '_jcore_pakkaus_status' => 'processing', '_jcore_pakkaus_job_id' => 'job-' . $id, '_jcore_pakkaus_updated' => 1 );
		}
		$GLOBALS['on_get_job'] = static function () { return new WP_Error( 'http_request_failed', 'Temporary error' ); };
		Processor::poll();
		Processor::poll();
		check( in_array( 'job-21', $GLOBALS['requested'], true ), 'Job 21 was starved' );
	},
	'download failures retry and a subsequent success swaps exactly once' => static function () {
		foreach ( array( new WP_Error( 'http_request_failed', 'Temporary error' ), 408, 409, 425, 429, 503 ) as $failure ) {
			reset_fixture();
			$GLOBALS['download'] = $failure;
			$result = Processor::finalize( 1, $GLOBALS['job'] );
			check( is_wp_error( $result ), 'Expected download error' );
			check( 'processing' === Processor::status( 1 ), 'Retry became terminal' );
			check( 'job-1' === get_post_meta( 1, '_jcore_pakkaus_job_id', true ), 'Job ID was discarded' );
			check( ! $GLOBALS['deleted'], 'Completed job deleted before retry' );
			check( isset( $GLOBALS['scheduled']['jcore_pakkaus_poll'] ), 'Retry polling not scheduled' );
			check( file_get_contents( $GLOBALS['original'] ) === $GLOBALS['original_bytes'], 'Original changed on error' );
			$GLOBALS['download'] = 200;
			check( true === Processor::finalize( 1, $GLOBALS['job'] ), 'Retry did not succeed' );
			check( 'optimized' === Processor::status( 1 ), 'Success not recorded' );
			check( array( 'job-1' ) === $GLOBALS['deleted'], 'Success did not clean up exactly once' );
			check( file_get_contents( $GLOBALS['original'] ) === $GLOBALS['output_bytes'], 'Output not installed' );
			Processor::finalize( 1, $GLOBALS['job'] );
			check( array( 'jcore_pakkaus_optimized' ) === $GLOBALS['actions'], 'Finalization repeated' );
		}
	},
	'incomplete download retains the job for retry' => static function () {
		$GLOBALS['job']['output']['size']++;
		$result = Processor::finalize( 1, $GLOBALS['job'] );
		check( 'jcore_pakkaus_download_retry' === $result->get_error_code(), 'Incomplete output was not retryable' );
		check( ! $GLOBALS['deleted'], 'Incomplete output discarded the job' );
		check( file_get_contents( $GLOBALS['original'] ) === $GLOBALS['original_bytes'], 'Incomplete output replaced original' );
	},
	'permanent download failure still terminates' => static function () {
		$GLOBALS['download'] = 404;
		Processor::finalize( 1, $GLOBALS['job'] );
		check( 'failed' === Processor::status( 1 ), 'Permanent failure retried' );
		check( array( 'job-1' ) === $GLOBALS['deleted'], 'Permanent failure did not clean up' );
	},
	'noncompleted finalization uses its existing mutex' => static function () {
		Processor::finalize( 1, array( 'id' => 'job-1', 'status' => 'skipped', 'input' => array(), 'output' => array() ) );
		check( 'skipped' === Processor::status( 1 ), 'Skipped status not applied' );
		check( array( 'job-1' ) === $GLOBALS['deleted'], 'Skipped job not cleaned up' );
	},
	'unavailable mutex prevents applying job state' => static function () {
		$GLOBALS['wpdb']->available = false;
		try {
			Processor::apply_job( 1, array( 'id' => 'job-1', 'status' => 'failed' ) );
			check( 'processing' === Processor::status( 1 ), 'State changed without mutex' );
		} finally {
			$GLOBALS['wpdb']->available = true;
		}
	},
	'WordPress clamps H.264 CRF zero and preserves H.265 zero' => static function () {
		$h264 = Settings::sanitize( array( 'codec' => 'h264', 'crf' => 0 ) );
		$h265 = Settings::sanitize( array( 'codec' => 'h265', 'crf' => 0 ) );
		check( 1 === $h264['crf'], 'H.264 accepted zero' );
		check( 0 === $h265['crf'], 'H.265 zero changed' );
		$GLOBALS['settings']['crf'] = 0;
		$missing = Settings::sanitize( array( 'codec' => 'h264' ) );
		check( 1 === $missing['crf'], 'Invalid saved zero survived a missing form field' );
	},
	'partial settings updates keep the other settings and the saved token' => static function () {
		$GLOBALS['settings'] = array( 'service_url' => 'https://optimizer.test', 'api_token' => 'token', 'crf' => 30, 'keep_original' => 1, 'codec' => 'h265' );
		$saved = Settings::update( array( 'preset' => 'slow', 'api_token' => '', 'unknown' => 'x' ) );
		check( 'slow' === $saved['preset'], 'Changed key not saved' );
		check( 30 === $saved['crf'] && 1 === $saved['keep_original'] && 'h265' === $saved['codec'], 'Partial update reset other settings' );
		check( 'token' === $saved['api_token'], 'Empty token replaced the saved one' );
		check( ! isset( $saved['unknown'] ), 'Unknown key stored' );
		$client = Settings::for_client();
		check( '' === $client['api_token'] && true === $client['api_token_set'], 'Token sent to the browser' );
	},
	'bulk enqueue marks idle videos pending and leaves running ones alone' => static function () {
		$GLOBALS['meta'][2] = array( '_jcore_pakkaus_status' => 'optimized', '_jcore_pakkaus_job_id' => 'old', '_jcore_pakkaus_updated' => 1 );
		check( true === Processor::enqueue( 2 ), 'Idle video not enqueued' );
		check( 'pending' === Processor::status( 2 ), 'Enqueued video not pending' );
		check( '' === get_post_meta( 2, '_jcore_pakkaus_job_id', true ), 'Stale job ID kept' );
		check( isset( $GLOBALS['scheduled']['jcore_pakkaus_poll'] ), 'Polling not scheduled' );
		check( false === Processor::enqueue( 1 ), 'Running video re-enqueued' );
		check( 'job-1' === get_post_meta( 1, '_jcore_pakkaus_job_id', true ), 'Running job disturbed' );
	},
	'image files are the attached file and each resized JPEG or PNG once' => static function () {
		image_fixture();
		$files = Images::files( 5 );
		check( array( 'full', 'thumbnail' ) === array_column( $files, 'name' ), 'Wrong files: ' . json_encode( array_column( $files, 'name' ) ) );
		check( 'https://example.test/wp-content/uploads/logo-150x150.png' === $files[1]['url'], 'Wrong resized URL' );
		check( filesize( $GLOBALS['test_dir'] . '/logo.png' ) === $files[0]['size'], 'Size not recorded' );
	},
	'image submission sends every file and remembers them' => static function () {
		image_fixture();
		$GLOBALS['meta'][5] = array( '_jcore_pakkaus_status' => 'pending', '_jcore_pakkaus_updated' => 1 );
		$GLOBALS['on_get_job'] = static function () { return array( 'code' => 201, 'body' => json_encode( array( 'id' => 'img-9' ) ) ); };
		check( true === Processor::submit( 5 ), 'Submission failed' );
		$body = $GLOBALS['bodies']['images'];
		check( array( 'full', 'thumbnail' ) === array_column( $body['files'], 'name' ), 'Files not sent' );
		check( array( 'webp' ) === $body['options']['formats'] && 'lossy' === $body['options']['png'], 'Default image options not sent' );
		check( ! isset( $body['source_url'] ), 'Video fields sent with an image job' );
		check( 'queued' === Processor::status( 5 ) && 'img-9' === get_post_meta( 5, '_jcore_pakkaus_job_id', true ), 'Job not recorded' );
		check( 2 === count( get_post_meta( 5, '_jcore_pakkaus_files', true ) ), 'Sent files not remembered' );
	},
	'image results install as webp sidecars and a smaller png with a backup' => static function () {
		$job = image_fixture();
		$dir = $GLOBALS['test_dir'];
		$original = file_get_contents( $dir . '/logo.png' );
		$GLOBALS['settings']['keep_original'] = 1;
		check( true === Processor::finalize( 5, $job ), 'Install failed' );
		check( 'optimized' === Processor::status( 5 ), 'Not marked optimized' );
		check( PNG_HEAD . 'small' === file_get_contents( $dir . '/logo.png' ), 'PNG not replaced' );
		check( $original === file_get_contents( $dir . '/logo.original.png' ), 'Original not kept' );
		check( webp_bytes( 'full' ) === file_get_contents( $dir . '/logo.png.webp' ), 'Full-size WebP missing' );
		check( webp_bytes( 'thumb' ) === file_get_contents( $dir . '/logo-150x150.png.webp' ), 'Thumbnail WebP missing' );
		check( ! file_exists( $dir . '/logo-150x150.png.avif' ), 'A format the plugin does not know was installed' );
		check( ! glob( $dir . '/.*.part' ), 'Temporary files left behind' );
		$stats = get_post_meta( 5, '_jcore_pakkaus_stats', true );
		check( strlen( $original ) === $stats['files'][0]['original_size'] && 'logo.original.png' === $stats['files'][0]['backup'], 'Backup not recorded' );
		check( strlen( webp_bytes( 'full' ) ) === $stats['files'][0]['formats']['webp'], 'WebP size not recorded' );
		check( ! isset( $stats['output']['files'] ), 'Raw job output stored' );
		check( strlen( PNG_HEAD . 'small' ) === $GLOBALS['metadata_by_id'][5]['filesize'], 'Metadata file size not updated' );
		check( '' === get_post_meta( 5, '_jcore_pakkaus_files', true ), 'Sent files not forgotten' );
		check( array( 'img-1' ) === $GLOBALS['deleted'], 'Job not cleaned up' );
	},
	'one failed image download installs nothing and retries' => static function () {
		$job = image_fixture();
		$dir = $GLOBALS['test_dir'];
		$original = file_get_contents( $dir . '/logo.png' );
		$GLOBALS['fail_download']['1/webp'] = 503;
		$result = Processor::finalize( 5, $job );
		check( 'jcore_pakkaus_download_retry' === $result->get_error_code(), 'Failure not retryable' );
		check( 'processing' === Processor::status( 5 ) && ! $GLOBALS['deleted'], 'Retry became terminal' );
		check( $original === file_get_contents( $dir . '/logo.png' ) && ! file_exists( $dir . '/logo.png.webp' ), 'Partial install' );
		check( ! glob( $dir . '/.*.part' ), 'Temporary files left behind' );
		unset( $GLOBALS['fail_download']['1/webp'] );
		check( true === Processor::finalize( 5, $job ), 'Retry did not succeed' );
		check( file_exists( $dir . '/logo-150x150.png.webp' ), 'Retry did not install' );
	},
	'a file changed during optimization is left alone' => static function () {
		$job = image_fixture();
		$dir = $GLOBALS['test_dir'];
		file_put_contents( $dir . '/logo-150x150.png', PNG_HEAD . 'edited in the meantime' );
		check( true === Processor::finalize( 5, $job ), 'Install failed' );
		check( ! file_exists( $dir . '/logo-150x150.png.webp' ), 'WebP of an outdated file installed' );
		check( file_exists( $dir . '/logo.png.webp' ), 'Unchanged file not installed' );
		$stats = get_post_meta( 5, '_jcore_pakkaus_stats', true );
		check( false !== strpos( $stats['files'][1]['message'], 'changed' ), 'Change not reported' );
	},
	'restoring an image deletes its webp versions and puts the png back' => static function () {
		$job = image_fixture();
		$dir = $GLOBALS['test_dir'];
		$original = file_get_contents( $dir . '/logo.png' );
		$GLOBALS['settings']['keep_original'] = 1;
		Processor::finalize( 5, $job );
		check( Images::can_restore( 5 ), 'Restore not offered' );
		check( true === Processor::restore( 5 ), 'Restore failed' );
		check( 'restored' === Processor::status( 5 ), 'Not marked restored' );
		check( $original === file_get_contents( $dir . '/logo.png' ) && ! file_exists( $dir . '/logo.original.png' ), 'PNG not put back' );
		check( ! file_exists( $dir . '/logo.png.webp' ) && ! file_exists( $dir . '/logo-150x150.png.webp' ), 'WebP versions left' );
		check( strlen( $original ) === $GLOBALS['metadata_by_id'][5]['filesize'], 'Metadata file size not restored' );
	},
	'deleting an image file deletes its webp version' => static function () {
		$file = $GLOBALS['test_dir'] . '/photo.png';
		file_put_contents( $file . '.webp', 'webp' );
		check( $file === Images::on_delete_file( $file ), 'Path not passed through' );
		check( ! file_exists( $file . '.webp' ), 'WebP version left behind' );
	},
	'rewritten image files drop stale webp versions and queue the image again' => static function () {
		$job = image_fixture();
		$dir = $GLOBALS['test_dir'];
		Processor::finalize( 5, $job );
		$metadata = $GLOBALS['metadata_by_id'][5];
		check( $metadata === Images::on_update_metadata( $metadata, 5 ), 'Metadata changed' );
		check( 'optimized' === Processor::status( 5 ) && file_exists( $dir . '/logo-150x150.png.webp' ), 'Unchanged files treated as changed' );
		file_put_contents( $dir . '/logo-150x150.png', PNG_HEAD . 'regenerated thumbnail' );
		Images::on_update_metadata( $metadata, 5 );
		check( ! file_exists( $dir . '/logo-150x150.png.webp' ), 'Stale WebP kept' );
		check( file_exists( $dir . '/logo.png.webp' ), 'Current WebP deleted' );
		check( 'pending' === Processor::status( 5 ), 'Image not queued again' );
	},
	'other image optimizers are recognised by their plugin directory' => static function () {
		check( ! Conflicts::blocks_images() && '' === Conflicts::message(), 'Paused without conflicts' );
		$GLOBALS['active_plugins'] = array( 'akismet/akismet.php', 'ewww-image-optimizer/ewww-image-optimizer.php', 'wp-smushit/wp-smush.php' );
		check( array( 'ewww-image-optimizer' => 'EWWW Image Optimizer', 'wp-smushit' => 'Smush' ) === Conflicts::active(), 'Wrong plugins: ' . json_encode( Conflicts::active() ) );
		check( false !== strpos( Conflicts::message(), 'EWWW Image Optimizer and Smush are active' ), 'Message does not name them' );
	},
	'an active image optimizer pauses everything for images but not for videos' => static function () {
		$job = image_fixture();
		$dir = $GLOBALS['test_dir'];
		$original = file_get_contents( $dir . '/logo.png' );
		$GLOBALS['active_plugins'] = array( 'ewww-image-optimizer/ewww-image-optimizer.php' );
		check( null === Processor::finalize( 5, $job ), 'Results installed while paused' );
		Processor::handle_callback( 5, $job );
		Processor::refresh( 5 );
		check( $original === file_get_contents( $dir . '/logo.png' ) && ! file_exists( $dir . '/logo.png.webp' ), 'Files touched while paused' );
		check( 'processing' === Processor::status( 5 ) && ! $GLOBALS['deleted'] && ! $GLOBALS['scheduled'], 'Job state touched while paused' );
		check( ! in_array( 'img-1', $GLOBALS['requested'], true ), 'Service asked about an image while paused' );
		check( 'jcore_pakkaus_conflict' === Processor::queue( 5 )->get_error_code(), 'Image queued while paused' );
		check( 'jcore_pakkaus_conflict' === Processor::restore( 5 )->get_error_code(), 'Image restored while paused' );
		$GLOBALS['meta'][5]['_jcore_pakkaus_status'] = 'optimized';
		check( false === Processor::enqueue( 5 ), 'Image enqueued while paused' );
		file_put_contents( $dir . '/logo.png.webp', 'theirs' );
		Images::on_delete_file( $dir . '/logo.png' );
		check( file_exists( $dir . '/logo.png.webp' ), "Another plugin's WebP file deleted" );
		Processor::refresh( 1 );
		check( in_array( 'job-1', $GLOBALS['requested'], true ), 'Videos paused too' );
	},
	'paused image jobs keep the poller scheduled and resume when the plugin is gone' => static function () {
		image_fixture();
		$GLOBALS['meta'][1]['_jcore_pakkaus_status'] = 'optimized';
		$GLOBALS['meta'][5] = array( '_jcore_pakkaus_status' => 'pending', '_jcore_pakkaus_updated' => 1 );
		$GLOBALS['active_plugins'] = array( 'imagify/imagify.php' );
		$GLOBALS['scheduled']['jcore_pakkaus_poll'] = true;
		Processor::poll();
		check( ! $GLOBALS['bodies'] && 'pending' === Processor::status( 5 ), 'Image submitted while paused' );
		check( isset( $GLOBALS['scheduled']['jcore_pakkaus_poll'] ), 'Polling stopped while an image waits' );
		$GLOBALS['active_plugins'] = array();
		$GLOBALS['on_get_job'] = static function () { return array( 'code' => 201, 'body' => json_encode( array( 'id' => 'img-2' ) ) ); };
		Processor::poll();
		check( isset( $GLOBALS['bodies']['images'] ) && 'queued' === Processor::status( 5 ), 'Image not resumed' );
	},
	'image settings are validated' => static function () {
		$clean = Settings::sanitize( array( 'image_png' => 'extreme', 'image_webp_quality' => 500, 'image_png_quality' => 1, 'image_webp' => 0 ) );
		check( 'lossy' === $clean['image_png'] && 100 === $clean['image_webp_quality'] && 10 === $clean['image_png_quality'], 'Image settings not clamped' );
		$GLOBALS['settings'] = array_merge( $GLOBALS['settings'], $clean );
		check( array() === Settings::image_options()['formats'] && Settings::images_enabled(), 'WebP toggle not applied' );
	},
);

try {
	foreach ( $tests as $name => $test ) {
		reset_fixture();
		$test();
		check( ! $wpdb->held, 'Mutex leaked: ' . $name );
		echo 'PASS: ' . $name . PHP_EOL;
	}
	echo count( $tests ) . ' plugin regressions passed.' . PHP_EOL;
} finally {
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $test_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $files as $file ) {
		if ( $file->isDir() ) { rmdir( $file->getPathname() ); } else { unlink( $file->getPathname() ); }
	}
	rmdir( $test_dir );
}
