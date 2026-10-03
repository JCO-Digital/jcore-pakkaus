<?php
/** Standalone regressions using the real plugin classes and a small WordPress test double. */
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
	$ids = array_filter( array_keys( $GLOBALS['meta'] ), static function ( $id ) {
		return in_array( $GLOBALS['meta'][ $id ]['_jcore_pakkaus_status'], Processor::ACTIVE_STATUSES, true );
	} );
	usort( $ids, static function ( $a, $b ) {
		return $GLOBALS['meta'][ $a ]['_jcore_pakkaus_updated'] <=> $GLOBALS['meta'][ $b ]['_jcore_pakkaus_updated'];
	} );
	return array_slice( $ids, 0, $args['posts_per_page'] );
}
function get_option( $key, $default = null ) { return $GLOBALS['settings']; }
function update_option( $key, $value ) { $GLOBALS['settings'] = $value; return true; }
function home_url( $path ) { return 'https://example.test' . $path; }
function untrailingslashit( $text ) { return rtrim( $text, '/' ); }
function esc_url_raw( $text, $protocols = null ) { return $text; }
function sanitize_text_field( $text ) { return $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_remote_request( $url, $args ) {
	$job_id = basename( $url );
	if ( 'DELETE' === $args['method'] ) {
		$GLOBALS['deleted'][] = $job_id;
		return array( 'code' => 204, 'body' => '' );
	}
	$GLOBALS['requested'][] = $job_id;
	return call_user_func( $GLOBALS['on_get_job'], $job_id );
}
function wp_remote_get( $url, $args ) {
	if ( is_wp_error( $GLOBALS['download'] ) ) { return $GLOBALS['download']; }
	file_put_contents( $args['filename'], $GLOBALS['output_bytes'] );
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
function get_attached_file( $id ) { return $GLOBALS['original']; }
function get_post_mime_type( $id ) { return 'video/mp4'; }
function wp_get_attachment_url( $id ) { return 'https://example.test/video.mp4'; }
function wp_generate_password( ...$args ) { return 'test-random'; }
function wp_delete_file( $path ) { if ( file_exists( $path ) ) { unlink( $path ); } }
function _wp_relative_upload_path( $path ) { return basename( $path ); }
function wp_generate_attachment_metadata( $id, $path ) { return array( 'filesize' => filesize( $path ) ); }
function wp_update_attachment_metadata( $id, $metadata ) { $GLOBALS['attachment_metadata'] = $metadata; }
function do_action( $hook, ...$args ) { $GLOBALS['actions'][] = $hook; }

$plugin_dir = dirname( __DIR__ ) . '/wordpress-plugin/jcore-pakkaus/includes/';
require $plugin_dir . 'class-settings.php';
require $plugin_dir . 'class-client.php';
require $plugin_dir . 'class-processor.php';

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
