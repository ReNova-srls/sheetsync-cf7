<?php
/**
 * Standalone functional tests for SheetSync for CF7 (no WordPress needed).
 * WordPress, Contact Form 7 and Google are replaced by minimal in-memory stubs.
 *
 * Usage: php tests/run.php [plugin-dir] [path/to/openssl.cnf]
 * Needs the openssl and mbstring extensions. Exit code 1 on failure.
 */

error_reporting( -1 );
ini_set( 'display_errors', '1' );
set_error_handler( function ( $no, $str, $file, $line ) {
	throw new ErrorException( $str, 0, $no, $file, $line );
} );

$plugin = rtrim( $argv[1] ?? dirname( __DIR__ ), '/\\' );

/* ---------------------------------------------------------------- WP stubs */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SS_CF7_VERSION', '1.0.0' );
define( 'SS_CF7_OPTION_CREDENTIALS', 'ss_cf7_credentials' );
define( 'SS_CF7_META_SETTINGS', '_ss_cf7_settings' );
define( 'SS_CF7_TRANSIENT_TOKEN', 'ss_cf7_access_token' );
define( 'SS_CF7_TRANSIENT_HEADERS', 'ss_cf7_hdr_' );

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

$GLOBALS['opts']       = array();
$GLOBALS['transients'] = array();
$GLOBALS['meta']       = array();
$GLOBALS['http']       = array(); // queue of fake responses.
$GLOBALS['http_log']   = array();
$GLOBALS['logs']       = array();
$GLOBALS['actions']    = array();

function __( $t, $d = null ) { return $t; }
function _n( $s, $p, $n, $d = null ) { return 1 === $n ? $s : $p; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_email( $e ) { return trim( $e ); }
function is_email( $e ) { return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
function esc_url_raw( $u, $p = null ) { return filter_var( $u, FILTER_VALIDATE_URL ) ? $u : ''; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_salt( $s ) { return 'test-salt-' . $s; }
function get_bloginfo( $k ) { return '6.9'; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function add_option( $k, $v, $x = '', $a = null ) { if ( isset( $GLOBALS['opts'][ $k ] ) ) { return false; } $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_post_meta( $id, $k, $s ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function apply_filters( $h, $v, ...$a ) { return $v; }
function add_action( $h, $cb, $p = 10 ) { $GLOBALS['actions'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) {}
function do_action( $h, ...$a ) { foreach ( $GLOBALS['actions'][ $h ] ?? array() as $cb ) { $cb( ...$a ); } }
$GLOBALS['mails'] = array();
function wp_mail( $to, $subject, $body, $headers = array() ) { $GLOBALS['mails'][] = compact( 'to', 'subject', 'body' ); return true; }
function wp_specialchars_decode( $s, $q = null ) { return htmlspecialchars_decode( $s, ENT_QUOTES ); }
function ss_cf7_admin_url( $tab = '', $args = array() ) { return 'https://example.com/wp-admin/admin.php?' . http_build_query( array( 'page' => 'sheetsync-cf7', 'tab' => $tab ) + $args ); }
define( 'SS_CF7_CRON_RETRY', 'ss_cf7_retry_failed' );
define( 'SS_CF7_TRANSIENT_ALERT', 'ss_cf7_alert_lock' );
function wp_date( $f, $t ) { return gmdate( $f, $t ); }
function ss_cf7_log( $m, $c = array() ) { $GLOBALS['logs'][] = is_wp_error( $m ) ? $m->get_error_message() : $m; }

function fake_http( $method, $url, $args ) {
	$GLOBALS['http_log'][] = array( 'method' => $method, 'url' => $url, 'args' => $args );
	$next = array_shift( $GLOBALS['http'] );
	if ( null === $next ) {
		throw new RuntimeException( "Unexpected HTTP call: $method $url" );
	}
	return array( 'response' => array( 'code' => $next[0] ), 'body' => json_encode( $next[1] ) );
}
function wp_remote_post( $u, $a ) { return fake_http( 'POST', $u, $a ); }
function wp_remote_get( $u, $a ) { return fake_http( 'GET', $u, $a ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

/* CF7 stubs */
class WPCF7_ContactForm {
	public function __construct( private int $id, private array $tags ) {}
	public function id() { return $this->id; }
	public function title() { return 'Modulo sopralluogo'; }
	public function scan_form_tags() { return $this->tags; }
}
class WPCF7_Submission {
	public static $instance;
	public function __construct( private array $posted, private array $meta ) {}
	public static function get_instance() { return self::$instance; }
	public function get_posted_data() { return $this->posted; }
	public function get_meta( $k ) { return $this->meta[ $k ] ?? null; }
}
function tag( $basetype, $name ) { return (object) array( 'basetype' => $basetype, 'name' => $name ); }
$GLOBALS['forms'] = array();
function wpcf7_contact_form( $id ) { return $GLOBALS['forms'][ $id ] ?? null; }
function wpcf7_mail_replace_tags( $text, $opts = array() ) {
	$posted = WPCF7_Submission::$instance ? WPCF7_Submission::$instance->get_posted_data() : array();
	return preg_replace_callback( '/\[([^\]]+)\]/', fn( $m ) => str_starts_with( $m[1], '_ss_' ) && isset( $GLOBALS['attr'] ) ? $GLOBALS['attr']->mail_tag( null, $m[1], false ) : ( '_post_title' === $m[1] ? 'Contatti' : ( is_array( $posted[ $m[1] ] ?? '' ) ? implode( ', ', $posted[ $m[1] ] ) : (string) ( $posted[ $m[1] ] ?? '' ) ) ), $text );
}

/* Minimal in-memory $wpdb covering the queries of SS_CF7_Entries used here. */
define( 'ARRAY_A', 'ARRAY_A' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'SS_CF7_TABLE', 'ss_cf7_entries' );
define( 'SS_CF7_OPTION_OPTIONS', 'ss_cf7_options' );
define( 'SS_CF7_CRON_CLEANUP', 'ss_cf7_daily_cleanup' );
function current_time( $t, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function absint( $v ) { return abs( (int) $v ); }
class Fake_WPDB {
	public $prefix = 'wp_';
	public $insert_id = 0;
	public $rows = array();
	private $prepared = array();
	public function insert( $table, $data, $formats ) {
		$id                = count( $this->rows ) + 1;
		$this->rows[ $id ] = $data + array( 'id' => $id, 'attempts' => 0, 'sent_at' => null, 'last_attempt_at' => null, 'row_data' => null, 'error' => null );
		$this->insert_id   = $id;
		return 1;
	}
	public function prepare( $q, ...$args ) {
		$args             = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
		$this->prepared[] = array( $q, $args );
		return 'PREP#' . ( count( $this->prepared ) - 1 );
	}
	private function resolve( $token ) { return $this->prepared[ (int) substr( $token, 5 ) ]; }
	public function query( $token ) {
		[ $q, $a ] = $this->resolve( $token );
		if ( str_contains( $q, 'row_data = %s' ) ) {
			$r = &$this->rows[ $a[4] ];
			$r['status'] = $a[0]; $r['sent_at'] = $a[1]; $r['last_attempt_at'] = $a[2]; $r['row_data'] = $a[3]; $r['error'] = null; $r['attempts']++;
			return 1;
		}
		if ( str_contains( $q, 'error = %s' ) ) {
			$r = &$this->rows[ $a[3] ];
			$r['status'] = $a[0]; $r['last_attempt_at'] = $a[1]; $r['error'] = $a[2]; $r['attempts']++;
			return 1;
		}
		if ( str_starts_with( $q, 'DELETE' ) ) {
			$n = 0;
			foreach ( $a as $id ) { if ( isset( $this->rows[ $id ] ) ) { unset( $this->rows[ $id ] ); $n++; } }
			return $n;
		}
		throw new RuntimeException( 'Unexpected query: ' . $q );
	}
	public function get_results( $token, $output ) {
		[ $q, $a ] = $this->resolve( $token );
		if ( ! str_contains( $q, 'COALESCE' ) ) {
			throw new RuntimeException( 'Unexpected query: ' . $q );
		}
		[ $failed, $pending, $cutoff, $max, $limit ] = $a;
		$rows = array_filter( $this->rows, fn( $r ) => ( $r['status'] === $failed || ( $r['status'] === $pending && $r['created_at'] < $cutoff ) ) && $r['attempts'] < $max );
		usort( $rows, fn( $x, $y ) => array( $x['last_attempt_at'] ?? $x['created_at'], $x['id'] ) <=> array( $y['last_attempt_at'] ?? $y['created_at'], $y['id'] ) );
		return array_slice( $rows, 0, $limit );
	}
	public function get_var( $token ) {
		[ $q, $a ] = $this->resolve( $token );
		return count( array_filter( $this->rows, fn( $r ) => $r['status'] === $a[0] ) );
	}
	public function get_row( $token, $output ) {
		[ $q, $a ] = $this->resolve( $token );
		return $this->rows[ $a[0] ] ?? null;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

require $plugin . '/includes/class-google-auth.php';
require $plugin . '/includes/class-entries.php';
require $plugin . '/includes/class-cf7-handler.php';
require $plugin . '/includes/class-notifier.php';
require $plugin . '/includes/class-attribution.php';
require $plugin . '/includes/class-admin-settings.php';

/* ---------------------------------------------------------------- mini runner */

$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label\n"; }
}
function call_private( $obj, $method, ...$args ) {
	$m = new ReflectionMethod( $obj, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invoke( is_object( $obj ) ? $obj : null, ...$args );
}

/* ---------------------------------------------------------------- fixtures */

// Windows builds of PHP may need the path of openssl.cnf as second argument.
$ssl_cfg = array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) + ( isset( $argv[2] ) ? array( 'config' => $argv[2] ) : array() );
$key     = openssl_pkey_new( $ssl_cfg );
openssl_pkey_export( $key, $pem, null, $ssl_cfg );
$public = openssl_pkey_get_details( $key )['key'];

$sa = array(
	'type'           => 'service_account',
	'project_id'     => 'demo-project',
	'private_key_id' => 'abc123',
	'private_key'    => $pem,
	'client_email'   => 'sheets@demo-project.iam.gserviceaccount.com',
	'client_id'      => '123',
	'token_uri'      => 'https://oauth2.googleapis.com/token',
);

echo "\n[parse_credentials]\n";
$ok = SS_CF7_Google_Auth::parse_credentials( "\xEF\xBB\xBF" . json_encode( $sa ) );
check( 'valid JSON (with BOM) accepted', is_array( $ok ) && $ok['client_email'] === $sa['client_email'] );
check( 'client_id not kept', is_array( $ok ) && ! isset( $ok['client_id'] ) );
check( 'empty rejected', 'ss_cf7_empty_json' === SS_CF7_Google_Auth::parse_credentials( '  ' )->get_error_code() );
check( 'garbage rejected', 'ss_cf7_invalid_json' === SS_CF7_Google_Auth::parse_credentials( '{nope' )->get_error_code() );
check( 'wrong type rejected', 'ss_cf7_wrong_key_type' === SS_CF7_Google_Auth::parse_credentials( json_encode( array( 'type' => 'authorized_user' ) + $sa ) )->get_error_code() );
$missing = $sa; unset( $missing['private_key'], $missing['token_uri'] );
$e = SS_CF7_Google_Auth::parse_credentials( json_encode( $missing ) );
check( 'missing fields listed', 'ss_cf7_missing_fields' === $e->get_error_code() && str_contains( $e->get_error_message(), 'private_key, token_uri' ) );
check( 'evil token_uri rejected', 'ss_cf7_invalid_token_uri' === SS_CF7_Google_Auth::parse_credentials( json_encode( array( 'token_uri' => 'https://evil.example/token' ) + $sa ) )->get_error_code() );
check( 'http token_uri rejected', 'ss_cf7_invalid_token_uri' === SS_CF7_Google_Auth::parse_credentials( json_encode( array( 'token_uri' => 'http://oauth2.googleapis.com/token' ) + $sa ) )->get_error_code() );
check( 'bad email rejected', 'ss_cf7_invalid_email' === SS_CF7_Google_Auth::parse_credentials( json_encode( array( 'client_email' => 'nope' ) + $sa ) )->get_error_code() );
$broken = $sa; $broken['private_key'] = "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n";
check( 'corrupted key rejected', 'ss_cf7_unreadable_private_key' === SS_CF7_Google_Auth::parse_credentials( json_encode( $broken ) )->get_error_code() );
$double = $sa; $double['private_key'] = str_replace( "\n", '\n', $pem );
check( 'double-escaped \\n key accepted', is_array( SS_CF7_Google_Auth::parse_credentials( json_encode( $double ) ) ) );
check( 'oversized rejected', 'ss_cf7_json_too_large' === SS_CF7_Google_Auth::parse_credentials( str_repeat( ' ', 10 ) . '{"a":"' . str_repeat( 'x', 21000 ) . '"}' )->get_error_code() );

echo "\n[storage + encryption]\n";
$auth = new SS_CF7_Google_Auth();
$GLOBALS['transients']['ss_cf7_access_token'] = 'stale';
check( 'save ok', true === $auth->save_credentials( $ok ) );
check( 'token flushed on save', ! isset( $GLOBALS['transients']['ss_cf7_access_token'] ) );
$stored = $GLOBALS['opts']['ss_cf7_credentials'];
check( 'private key not stored in clear', ! str_contains( serialize( $stored ), 'PRIVATE KEY' ) );
check( 'payload prefixed v1:', str_starts_with( $stored['payload'], 'v1:' ) );
check( 'public info', $auth->get_public_info()['client_email'] === $sa['client_email'] && $auth->has_credentials() );
$auth2 = new SS_CF7_Google_Auth();
$creds = call_private( $auth2, 'get_credentials' );
check( 'decrypt round-trip', is_array( $creds ) && $creds['private_key'] === $ok['private_key'] );
$tampered = $stored; $tampered['payload'] = 'v1:' . base64_encode( str_repeat( 'x', 64 ) );
$GLOBALS['opts']['ss_cf7_credentials'] = $tampered;
check( 'tampered payload -> decrypt error', 'ss_cf7_decrypt_failed' === call_private( new SS_CF7_Google_Auth(), 'get_credentials' )->get_error_code() );
$GLOBALS['opts']['ss_cf7_credentials'] = $stored;

echo "\n[JWT]\n";
$jwt   = call_private( $auth2, 'build_jwt', $creds );
$parts = explode( '.', $jwt );
$b64d  = fn( $s ) => base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) );
$hdr   = json_decode( $b64d( $parts[0] ), true );
$cl    = json_decode( $b64d( $parts[1] ), true );
check( '3 segments, no padding', 3 === count( $parts ) && ! str_contains( $jwt, '=' ) );
check( 'header RS256 + kid', 'RS256' === $hdr['alg'] && 'abc123' === $hdr['kid'] );
check( 'claims', $cl['iss'] === $sa['client_email'] && $cl['aud'] === $sa['token_uri'] && $cl['scope'] === SS_CF7_Google_Auth::SCOPE && 3600 === $cl['exp'] - $cl['iat'] && $cl['iat'] <= time() );
check( 'signature verifies with public key', 1 === openssl_verify( $parts[0] . '.' . $parts[1], $b64d( $parts[2] ), $public, OPENSSL_ALGO_SHA256 ) );

echo "\n[token + API]\n";
$GLOBALS['http'][] = array( 200, array( 'access_token' => 'tok-1', 'expires_in' => 3599 ) );
check( 'token fetched', 'tok-1' === $auth2->get_access_token() );
$req = end( $GLOBALS['http_log'] );
check( 'token request is jwt-bearer to token_uri', $req['url'] === $sa['token_uri'] && 'urn:ietf:params:oauth:grant-type:jwt-bearer' === $req['args']['body']['grant_type'] );
check( 'token cached', 'tok-1' === $auth2->get_access_token() && 1 === count( $GLOBALS['http_log'] ) );

$GLOBALS['http'][] = array( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.' ) );
$e = $auth2->get_access_token( true );
check( 'revoked key message', is_wp_error( $e ) && str_contains( $e->get_error_message(), 'deleted or disabled' ) );
$GLOBALS['http'][] = array( 400, array( 'error' => 'invalid_grant', 'error_description' => 'Invalid JWT: Token must be a short-lived token (60 minutes) and in a reasonable timeframe. Check your iat and exp values in the JWT claim.' ) );
check( 'clock skew message', str_contains( $auth2->get_access_token( true )->get_error_message(), 'clock' ) );
$GLOBALS['transients']['ss_cf7_access_token'] = 'tok-1';

// 401 -> refresh -> retry.
$GLOBALS['http'][] = array( 401, array( 'error' => array( 'message' => 'expired' ) ) );
$GLOBALS['http'][] = array( 200, array( 'access_token' => 'tok-2', 'expires_in' => 3599 ) );
$GLOBALS['http'][] = array( 200, array( 'values' => array( array( 'your-name', ' Data e ora invio ', '', 'your-email' ) ) ) );
$h = $auth2->get_header_row( 'SPREADSHEET_ID_1234567890', "Foglio di Nick's" );
check( '401 retried with new token', array( 'your-name', 'Data e ora invio', '', 'your-email' ) === $h && 'Bearer tok-2' === end( $GLOBALS['http_log'] )['args']['headers']['Authorization'] );
check( 'range quoted + encoded', str_contains( end( $GLOBALS['http_log'] )['url'], rawurlencode( "'Foglio di Nick''s'!1:1" ) ) );

$GLOBALS['http'][] = array( 403, array( 'error' => array( 'code' => 403, 'message' => 'The caller does not have permission', 'status' => 'PERMISSION_DENIED' ) ) );
check( '403 share hint', str_contains( $auth2->get_values( 'X', 'A1' )->get_error_message(), 'sheets@demo-project.iam.gserviceaccount.com as Editor' ) );
$GLOBALS['http'][] = array( 403, array( 'error' => array( 'message' => 'Google Sheets API has not been used in project 1 before or it is disabled.' ) ) );
check( '403 API disabled hint', str_contains( $auth2->get_values( 'X', 'A1' )->get_error_message(), 'not enabled' ) );
$GLOBALS['http'][] = array( 400, array( 'error' => array( 'message' => 'Unable to parse range: Foo!1:1' ) ) );
check( '400 tab not found', str_contains( $auth2->get_values( 'X', 'A1' )->get_error_message(), 'tab not found' ) );

$GLOBALS['http'][] = array( 200, array( 'updates' => array() ) );
$auth2->append_values( 'SID', 'Sheet 1', array( array( 'a' ) ) );
$r = end( $GLOBALS['http_log'] );
check( 'append URL', str_contains( $r['url'], '/values/' . rawurlencode( "'Sheet 1'!A1" ) . ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS' ) && 'POST' === $r['method'] );
$GLOBALS['http'][] = array( 200, array() );
$auth2->update_values( 'SID', 'A1', array( array( 'h' ) ), 'RAW' );
$r = end( $GLOBALS['http_log'] );
check( 'update via values:batchUpdate POST', str_ends_with( $r['url'], '/SID/values:batchUpdate' ) && 'RAW' === json_decode( $r['args']['body'], true )['valueInputOption'] );

echo "\n[helpers]\n";
check( 'id from URL', '1AbCdEfGhIjKlMnOpQrStUvWxYz012345' === SS_CF7_Google_Auth::sanitize_spreadsheet_id( 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz012345/edit#gid=0' ) );
check( 'bad id -> empty', '' === SS_CF7_Google_Auth::sanitize_spreadsheet_id( 'short' ) && '' === SS_CF7_Google_Auth::sanitize_spreadsheet_id( '../../etc/passwd-aaaaaaaaaaaaaaa' ) );
check( 'sheet name sanitized', 'Foglio 1' === SS_CF7_Google_Auth::sanitize_sheet_name( ' <b>Foglio</b> [1]* ' ) || 'Foglio 1' === SS_CF7_Google_Auth::sanitize_sheet_name( '<b>Foglio</b> 1' ) );
check( 'a1 empty sheet', 'A1' === SS_CF7_Google_Auth::a1( '', 'A1' ) );

echo "\n[settings + mapping sanitize]\n";
$entries = new SS_CF7_Entries();
$handler = new SS_CF7_Handler( $auth2, $entries );
$s = SS_CF7_Handler::sanitize_form_settings(
	array(
		'enabled'        => '1',
		'spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz012345/edit',
		'sheet_name'     => 'Foglio1',
		'meta'           => array( 'ip', 'evil', array( 'x' ), 'datetime' ),
		'exclude'        => array( 'acceptance-604', '<script>', array( 'x' ) ),
		'mapping'        => array(
			array( 'header' => 'Tipologia Lead', 'rule' => 'text', 'text' => 'Google Ads <b>x</b>' ),
			array( 'header' => 'date', 'rule' => 'field:@datetime' ),
			array( 'header' => 'Note', 'rule' => 'empty' ),
			array( 'header' => 'Origine', 'rule' => 'text', 'text' => '[Nome] da [_post_title]' ),
			array( 'header' => 'Telefono', 'rule' => 'field:Phone' ),
			array( 'header' => ' tipologia  LEAD ', 'rule' => 'empty' ), // duplicate header -> dropped.
			array( 'header' => 'X', 'rule' => 'field:' ),                // empty source -> auto.
			array( 'header' => 'Y', 'rule' => 'evil' ),                  // unknown -> auto.
			'garbage',
		),
	)
);
check( 'settings sanitized', true === $s['enabled'] && '1AbCdEfGhIjKlMnOpQrStUvWxYz012345' === $s['spreadsheet_id'] && array( 'datetime', 'ip' ) === $s['meta'] );
check( 'exclude sanitized', array( 'acceptance-604', 'script' ) === $s['exclude'] );
check( 'mapping rows', 7 === count( $s['mapping'] ) && 'Google Ads x' === $s['mapping'][0]['text'] && 'field' === $s['mapping'][1]['type'] && '@datetime' === $s['mapping'][1]['source'] );
check( 'mapping invalid rules -> auto', 'auto' === $s['mapping'][5]['type'] && 'auto' === $s['mapping'][6]['type'] );
check( 'round-trip stable', $s === SS_CF7_Handler::sanitize_form_settings( $s ) );
check( 'defaults when unsaved', false === SS_CF7_Handler::get_form_settings( 999 )['enabled'] && array() === SS_CF7_Handler::get_form_settings( 999 )['mapping'] );

echo "\n[columns + compare]\n";
// The user's real form.
$form = new WPCF7_ContactForm( 7, array( tag( 'text', 'Nome' ), tag( 'tel', 'Telefono' ), tag( 'email', 'Email' ), tag( 'select', 'Provincia' ), tag( 'text', 'altra-provincia' ), tag( 'textarea', 'your-message' ), tag( 'acceptance', 'acceptance-604' ), tag( 'submit', '' ), tag( 'file', 'cv' ) ) );
$GLOBALS['forms'][7] = $form;
$s['mapping'][4]['source'] = 'Telefono';
$s['meta'] = array( 'datetime' );
$cols = $handler->get_columns( $form, $s );
check( 'columns honour exclude', array( 'Nome', 'Telefono', 'Email', 'Provincia', 'altra-provincia', 'your-message', '@datetime' ) === array_keys( $cols ) );
$sheet = array( 'Nome', 'Telefono', 'Email', 'Provincia', 'date', 'Orario di Contatto', 'Note', 'Tipologia Lead', 'Origine' );
$diff  = $handler->compare_headers( $sheet, $cols, $s['mapping'] );
check( 'mapped @datetime counts as present', ! isset( $diff['missing']['@datetime'] ) );
check( 'missing = unmapped fields', array( 'altra-provincia', 'your-message' ) === array_keys( $diff['missing'] ) );
check( 'orphans = auto columns only', array( 'Orario di Contatto' ) === $diff['orphans'] );
check( 'describe_auto', 'Nome' === $handler->describe_auto( 'nome', $handler->get_field_names( $form ) ) && '' === $handler->describe_auto( 'Note', $handler->get_field_names( $form ) ) && 'Submission date' === $handler->describe_auto( 'Submission date', array() ) );
$src = $handler->get_sources( $form );
check( 'sources: fields + meta + tracking', 'field:Nome' === $src[0]['value'] && 'field:@referrer' === end( $src )['value'] && 'tracking' === end( $src )['group'] && 'meta' === $src[9]['group'] && 21 === count( $src ) );

echo "\n[protect + stringify]\n";
$p = fn( $v ) => SS_CF7_Handler::protect( $v );
check( 'formula neutralized', "'=IMPORTXML(\"x\")" === $p( '=IMPORTXML("x")' ) && "'+393331234567" === $p( '+393331234567' ) && "'@SUM(1)" === $p( '@SUM(1)' ) && "'-1" === $p( '-1' ) );
check( 'leading zero kept', "'00184" === $p( '00184' ) && '0' === $p( '0' ) && '184' === $p( '184' ) && 'Mario' === $p( 'Mario' ) );
$st = fn( $v ) => call_private( SS_CF7_Handler::class, 'stringify', $v );
check( 'arrays joined', 'SEO, Web, App' === $st( array( 'SEO', 'Web', '', array( 'App' ) ) ) && '' === $st( null ) && '1' === $st( true ) );

echo "\n[delivery with mapping + archive]\n";
$GLOBALS['opts']['ss_cf7_options'] = array( 'store_entries' => true, 'retention_days' => 0 );
WPCF7_Submission::$instance = new WPCF7_Submission(
	array( '_wpcf7' => '7', 'Nome' => 'Mario', 'Telefono' => '+39 333 1234567', 'Email' => 'a@b.it', 'Provincia' => array( 'Roma' ), 'altra-provincia' => '', 'your-message' => '=HYPERLINK("x")', 'acceptance-604' => '1' ),
	array( 'timestamp' => 1700000000, 'remote_ip' => '203.0.113.9', 'url' => 'https://example.com/contatti/' )
);
$GLOBALS['meta'][7]['_ss_cf7_settings'] = $s;
$GLOBALS['http_log'] = array();
$GLOBALS['logs']     = array();
$handler->on_mail_sent( $form );
$entry = $entries->get( 1 );
check( 'entry stored as pending', null !== $entry && 'pending' === $entry['status'] && 'Mario' === $entry['fields']['Nome'] );
check( 'custom text resolved at submission (mail-tags)', 'Mario da Contatti' === $entry['fields']['#text:origine'] && 'Google Ads x' === $entry['fields']['#text:tipologia lead'] );
check( 'mapped meta collected even if not a column', '14/11/2023 22:13' === $entry['fields']['@datetime'] && ! isset( $entry['fields']['@ip'] ) );

$GLOBALS['http'][] = array( 200, array( 'values' => array( $sheet ) ) ); // header row (has headers: no auto-add)
$GLOBALS['http'][] = array( 200, array( 'updates' => array() ) );      // append
$handler->process_queue();
$calls = $GLOBALS['http_log'];
check( 'no header write when sheet has headers', 2 === count( $calls ) && str_contains( $calls[1]['url'], ':append' ) );
$row = json_decode( $calls[1]['args']['body'], true )['values'][0];
check(
	'row follows mapping',
	array( 'Mario', "'+39 333 1234567", 'a@b.it', 'Roma', '14/11/2023 22:13', '', '', 'Google Ads x', 'Mario da Contatti' ) === $row
);
$entry = $entries->get( 1 );
check( 'entry marked sent with row pairs', 'sent' === $entry['status'] && 1 === $entry['attempts'] && array( 'Tipologia Lead', 'Google Ads x' ) === $entry['row_data'][7] );

// Excluded field mapped by "auto" stays empty; explicit field rule still works.
$s2            = $s;
$s2['mapping'] = array( array( 'header' => 'Privacy', 'type' => 'field', 'source' => 'acceptance-604', 'text' => '' ) );
$r             = call_private( $handler, 'build_row', array( 'acceptance-604', 'Privacy' ), array( 'acceptance-604' => '1' ), $s2 );
check( 'exclude blocks auto, explicit mapping wins', array( '', '1' ) === $r );

echo "\n[empty sheet auto-header, failure, retry]\n";
$GLOBALS['http_log'] = array();
$handler->on_mail_sent( $form );
$GLOBALS['http'][] = array( 200, array() );                              // header row: empty sheet
$GLOBALS['http'][] = array( 200, array() );                              // write headers
$GLOBALS['http'][] = array( 500, array( 'error' => array( 'message' => 'Internal error' ) ) ); // append fails
$handler->process_queue();
$calls  = $GLOBALS['http_log'];
$header = json_decode( $calls[1]['args']['body'], true )['data'][0]['values'][0];
check( 'empty sheet: header row written', array( 'Nome', 'Telefono', 'Email', 'Provincia', 'altra-provincia', 'your-message', 'Submission date' ) === $header );
$entry = $entries->get( 2 );
check( 'entry marked failed with error', 'failed' === $entry['status'] && str_contains( $entry['error'], 'temporarily unavailable' ) );
check( 'error logged, not thrown', 1 === count( $GLOBALS['logs'] ) );

$GLOBALS['http'][] = array( 200, array( 'values' => array( $header ) ) );
$GLOBALS['http'][] = array( 200, array( 'updates' => array() ) );
$res   = $handler->retry_entry( 2 );
$entry = $entries->get( 2 );
check( 'retry succeeds and updates entry', is_array( $res ) && 'sent' === $entry['status'] && 2 === $entry['attempts'] && '' === $entry['error'] );
check( 'retry of unknown entry', is_wp_error( $handler->retry_entry( 999 ) ) );
check( 'delete entry', 1 === $entries->delete( array( 2 ) ) && null === $entries->get( 2 ) );

echo "\n[archive disabled]\n";
$GLOBALS['opts']['ss_cf7_options'] = array( 'store_entries' => false );
$before = count( $GLOBALS['wpdb']->rows );
$handler->on_mail_sent( $form );
$GLOBALS['http'][] = array( 200, array( 'values' => array( $sheet ) ) );
$GLOBALS['http'][] = array( 200, array( 'updates' => array() ) );
$handler->process_queue();
check( 'nothing stored when archive disabled', count( $GLOBALS['wpdb']->rows ) === $before );
check( 'retention option sanitized', 0 === SS_CF7_Entries::sanitize_options( array( 'store_entries' => 1, 'retention_days' => 17 ) )['retention_days'] );

echo "\n[retry + alert options]\n";
unset( $GLOBALS['opts']['ss_cf7_options'] );
$o = SS_CF7_Entries::options();
check( 'defaults: retry and alerts on', true === $o['store_entries'] && true === $o['auto_retry'] && 5 === $o['max_attempts'] && true === $o['alert_enabled'] && '' === $o['alert_email'] );
$o = SS_CF7_Entries::sanitize_options( array( 'store_entries' => '1', 'max_attempts' => '7', 'alert_email' => " a@b.it; nope, c@d.it\na@b.it " ) );
check( 'unchecked boxes are off', false === $o['auto_retry'] && false === $o['alert_enabled'] );
check( 'invalid max attempts -> default', 5 === $o['max_attempts'] && 10 === SS_CF7_Entries::sanitize_options( array( 'max_attempts' => '10' ) )['max_attempts'] );
check( 'email list normalized', 'a@b.it, c@d.it' === $o['alert_email'] );
check( 'email array rejected', '' === SS_CF7_Entries::sanitize_options( array( 'alert_email' => array( 'a@b.it' ) ) )['alert_email'] );
check( 'recipients fallback to admin email', array( 'admin@example.com' ) === ( function () { $GLOBALS['opts']['admin_email'] = 'admin@example.com'; return SS_CF7_Notifier::recipients( SS_CF7_Entries::options() ); } )() );

echo "\n[backoff]\n";
$on   = array( 'store_entries' => true, 'auto_retry' => true, 'max_attempts' => 5 );
$base = '2026-01-01 10:00:00';
$t0   = strtotime( $base . ' UTC' );
$nr   = fn( $status, $attempts, $last = null, $opts = null ) => SS_CF7_Entries::next_retry_at( array( 'status' => $status, 'attempts' => $attempts, 'created_at' => $base, 'last_attempt_at' => $last ), $opts ?? $on );
check( 'after 1st failure: +1h', $t0 + 3600 === $nr( 'failed', 1, $base ) );
check( 'after 3rd failure: +4h', $t0 + 4 * 3600 === $nr( 'failed', 3, $base ) );
check( 'capped at 24h', $t0 + 24 * 3600 === $nr( 'failed', 9, $base, array( 'max_attempts' => 10 ) + $on ) );
check( 'stuck pending: created + 30 min', $t0 + 1800 === $nr( 'pending', 0 ) );
check( 'no retry when max reached / sent / disabled', null === $nr( 'failed', 5, $base ) && null === $nr( 'sent', 1, $base ) && null === $nr( 'failed', 1, $base, array( 'auto_retry' => false ) + $on ) && null === $nr( 'failed', 1, $base, array( 'store_entries' => false ) + $on ) );

echo "\n[alert on submission failure]\n";
$notifier = new SS_CF7_Notifier( $entries );
$GLOBALS['opts']['ss_cf7_options'] = array( 'store_entries' => true, 'auto_retry' => true, 'max_attempts' => 5, 'alert_enabled' => true, 'alert_email' => 'ops@example.com' );
$GLOBALS['wpdb']->rows = array();
$GLOBALS['mails']      = array();
unset( $GLOBALS['transients']['ss_cf7_alert_lock'] );
$fail_append = function () use ( $sheet ) {
	$GLOBALS['http'][] = array( 200, array( 'values' => array( $sheet ) ) );
	$GLOBALS['http'][] = array( 403, array( 'error' => array( 'status' => 'PERMISSION_DENIED', 'message' => 'denied' ) ) );
};
$ok_append = function () use ( $sheet ) {
	$GLOBALS['http'][] = array( 200, array( 'values' => array( $sheet ) ) );
	$GLOBALS['http'][] = array( 200, array( 'updates' => array() ) );
};
$handler->on_mail_sent( $form );
$fail_append();
$handler->process_queue();
$mail = $GLOBALS['mails'][0] ?? null;
check( 'one alert sent to the configured recipient', 1 === count( $GLOBALS['mails'] ) && array( 'ops@example.com' ) === $mail['to'] );
check( 'alert says it will retry', str_contains( $mail['body'], 'attempt 1 of 5' ) && str_contains( $mail['subject'], 'could not be sent' ) && str_contains( $mail['body'], 'Modulo sopralluogo' ) );
check( 'alert links the failed entries', str_contains( $mail['body'], 'tab=dashboard&status=failed' ) && str_contains( $mail['body'], '1 submission is currently in error.' ) );
check( 'alert contains no submitted data', ! str_contains( $mail['body'], 'Mario' ) && ! str_contains( $mail['body'], 'a@b.it' ) );
check( 'throttle lock set', false !== get_transient( 'ss_cf7_alert_lock' ) );
$handler->on_mail_sent( $form );
$fail_append();
$handler->process_queue();
check( 'second failure within the hour: no email', 1 === count( $GLOBALS['mails'] ) && 'failed' === $entries->get( 2 )['status'] );

echo "\n[automatic retry]\n";
$GLOBALS['http_log'] = array();
check( 'nothing due right after the failure', 0 === $handler->run_auto_retry() && array() === $GLOBALS['http_log'] );
$ago = fn( $seconds ) => gmdate( 'Y-m-d H:i:s', time() - $seconds );
$GLOBALS['wpdb']->rows[1]['last_attempt_at'] = $ago( 3700 );
$ok_append();
check( 'due entry retried', 1 === $handler->run_auto_retry() );
check( 'retried entry is sent', 'sent' === $entries->get( 1 )['status'] && 2 === $entries->get( 1 )['attempts'] && 'failed' === $entries->get( 2 )['status'] );

// Intermediate retry failure: silent even without the lock.
unset( $GLOBALS['transients']['ss_cf7_alert_lock'] );
$GLOBALS['mails'] = array();
$GLOBALS['wpdb']->rows[2]['last_attempt_at'] = $ago( 3700 );
$fail_append();
check( 'intermediate retry failure', 1 === $handler->run_auto_retry() && 2 === $entries->get( 2 )['attempts'] && array() === $GLOBALS['mails'] );

// Last attempt fails: alert.
$GLOBALS['wpdb']->rows[2]['attempts']        = 4;
$GLOBALS['wpdb']->rows[2]['last_attempt_at'] = $ago( 9 * 3600 );
$fail_append();
$handler->run_auto_retry();
check( 'last attempt failure alerts', 1 === count( $GLOBALS['mails'] ) && str_contains( $GLOBALS['mails'][0]['body'], 'stopped after 5 attempts' ) && str_contains( $GLOBALS['mails'][0]['subject'], 'was not sent' ) );
check( 'exhausted entry no longer retried', 0 === $handler->run_auto_retry() && null === SS_CF7_Entries::next_retry_at( $entries->get( 2 ) ) );

// Stuck "pending" entry (process interrupted) is picked up after 30 minutes.
$handler->on_mail_sent( $form );
$GLOBALS['wpdb']->rows[3]['created_at'] = $ago( 2400 );
$ok_append();
$queue = new ReflectionProperty( $handler, 'queue' );
if ( PHP_VERSION_ID < 80100 ) { $queue->setAccessible( true ); }
$queue->setValue( $handler, array() ); // Simulates a request that died before shutdown.
check( 'stuck pending entry retried', 1 === $handler->run_auto_retry() && 'sent' === $entries->get( 3 )['status'] );

// Manual retry failure: no email; retry disabled: cron does nothing.
unset( $GLOBALS['transients']['ss_cf7_alert_lock'] );
$GLOBALS['mails'] = array();
$GLOBALS['wpdb']->rows[2]['attempts'] = 1;
$fail_append();
check( 'manual retry failure: no email', is_wp_error( $handler->retry_entry( 2 ) ) && array() === $GLOBALS['mails'] );
$GLOBALS['opts']['ss_cf7_options']['auto_retry'] = false;
$GLOBALS['wpdb']->rows[2]['last_attempt_at'] = $ago( 99999 );
check( 'retry disabled: cron idle', 0 === $handler->run_auto_retry() );
$GLOBALS['opts']['ss_cf7_options']['auto_retry'] = true;

echo "\n[alerts: archive disabled / alerts off]\n";
$GLOBALS['opts']['ss_cf7_options']['store_entries'] = false;
$handler->on_mail_sent( $form );
$fail_append();
$handler->process_queue();
check( 'archive disabled: alert explains data is in the CF7 email', 1 === count( $GLOBALS['mails'] ) && str_contains( $GLOBALS['mails'][0]['body'], 'cannot be sent again' ) && ! str_contains( $GLOBALS['mails'][0]['body'], 'Dashboard:' ) );
unset( $GLOBALS['transients']['ss_cf7_alert_lock'] );
$GLOBALS['opts']['ss_cf7_options']['alert_enabled'] = false;
$handler->on_mail_sent( $form );
$fail_append();
$handler->process_queue();
check( 'alerts disabled: no email', 1 === count( $GLOBALS['mails'] ) );
check( 'test email', SS_CF7_Notifier::send_test() && 'Test alert' === substr( $GLOBALS['mails'][1]['subject'], -10 ) );

echo "\n[lead source: channel detection]\n";
$ch = function ( $params, $ref = '' ) {
	return SS_CF7_Attribution::detect_channel( $params + array_fill_keys( SS_CF7_Attribution::PARAMS, '' ), $ref );
};
check( 'gclid -> Google Ads', 'google_ads' === $ch( array( 'gclid' => 'Cj0KCQ' ) ) && 'google_ads' === $ch( array( 'wbraid' => 'x' ) ) );
check( 'utm google/cpc -> Google Ads', 'google_ads' === $ch( array( 'utm_source' => 'Google', 'utm_medium' => 'CPC' ) ) );
check( 'meta paid -> Meta Ads', 'meta_ads' === $ch( array( 'utm_source' => 'ig', 'utm_medium' => 'paid_social' ) ) && 'meta_ads' === $ch( array( 'utm_source' => 'facebook', 'utm_medium' => 'paid' ) ) );
check( 'msclkid / ttclid', 'microsoft_ads' === $ch( array( 'msclkid' => 'a' ) ) && 'tiktok_ads' === $ch( array( 'ttclid' => 'a' ) ) );
check( 'other paid', 'other_paid' === $ch( array( 'utm_source' => 'subito', 'utm_medium' => 'display' ) ) );
check( 'fbclid alone -> social', 'organic_social' === $ch( array( 'fbclid' => 'IwAR' ) ) );
check( 'newsletter -> email', 'email' === $ch( array( 'utm_source' => 'mailchimp', 'utm_medium' => 'email' ) ) );
check( 'utm organic -> organic search', 'organic_search' === $ch( array( 'utm_source' => 'google', 'utm_medium' => 'organic' ) ) );
check( 'unknown utm -> campaign', 'campaign' === $ch( array( 'utm_source' => 'volantino' ) ) );
check( 'referrer search / social / site', 'organic_search' === $ch( array(), 'https://www.google.it/' ) && 'organic_search' === $ch( array(), 'https://duckduckgo.com/' ) && 'organic_social' === $ch( array(), 'https://l.instagram.com/' ) && 'organic_social' === $ch( array(), 'https://t.co/abc' ) && 'referral' === $ch( array(), 'https://forum.example.org/thread' ) );
check( 'nothing -> direct', 'direct' === $ch( array() ) );

echo "\n[lead source: settings + parsing]\n";
$t = SS_CF7_Attribution::sanitize_options( array( 'enabled' => '1', 'days' => '45', 'model' => 'evil', 'labels' => array( 'google_ads' => ' Google ADS <b>', 'direct' => '', 'hack' => 'x', 'email' => array( 'x' ) ) ) );
check( 'tracking options sanitized', true === $t['enabled'] && 90 === $t['days'] && 'last' === $t['model'] && false === $t['consent'] && array( 'google_ads' => 'Google ADS' ) === $t['labels'] );
check( 'tracking off by default', false === SS_CF7_Attribution::options()['enabled'] && true === SS_CF7_Attribution::options()['consent'] );
$GLOBALS['opts']['ss_cf7_tracking'] = array( 'enabled' => true, 'labels' => array( 'google_ads' => 'Google ADS' ) );
$a = SS_CF7_Attribution::parse( array( 'p' => array( 'utm_source' => 'google', 'utm_campaign' => str_repeat( 'x', 400 ), 'gbraid' => 'GB1', 'evil' => 'x', 'utm_term' => array( 'x' ) ), 'landing' => 'https://muffa.example/sopralluogo/?email=a@b.it#x', 'ref' => 'javascript:alert(1)' ) );
check( 'gbraid used as Google click ID, custom channel name', 'GB1' === $a['gclid'] && 'Google ADS' === $a['channel'] );
check( 'values truncated / non-strings dropped', 300 === strlen( $a['utm_campaign'] ) && '' === $a['utm_term'] && ! isset( $a['evil'] ) );
check( 'landing without query, bad referrer dropped', 'https://muffa.example/sopralluogo/' === $a['landing_page'] && '' === $a['referrer'] );

$post = array( 'v' => 1, 'p' => array( 'gclid' => 'Cj0K', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => "Muffa O'Neill" ), 'landing' => 'https://muffa.example/', 'ref' => 'https://www.google.com/' );
$_POST['_ss_cf7_attribution'] = addslashes( json_encode( $post ) );
SS_CF7_Attribution::reset();
$cur = SS_CF7_Attribution::current();
check( 'posted attribution read (slashed by WP)', 'Google ADS' === $cur['channel'] && "Muffa O'Neill" === $cur['utm_campaign'] && 'Cj0K' === $cur['gclid'] );
$attr = new SS_CF7_Attribution();
$GLOBALS['attr'] = $attr;
check( 'mail-tags', 'Google ADS' === $attr->mail_tag( null, '_ss_channel', false ) && 'Muffa O&#039;Neill' === $attr->mail_tag( null, '_ss_utm_campaign', true ) && 'keep' === $attr->mail_tag( 'keep', '_ss_nope', false ) && 'keep' === $attr->mail_tag( 'keep', '_date', false ) );
$_POST['_ss_cf7_attribution'] = '{broken';
SS_CF7_Attribution::reset();
check( 'invalid JSON -> empty', '' === SS_CF7_Attribution::current()['channel'] );
$_POST['_ss_cf7_attribution'] = addslashes( json_encode( $post ) );
$GLOBALS['opts']['ss_cf7_tracking']['enabled'] = false;
SS_CF7_Attribution::reset();
check( 'tracking disabled -> ignored', '' === SS_CF7_Attribution::current()['gclid'] );
$GLOBALS['opts']['ss_cf7_tracking']['enabled'] = true;
SS_CF7_Attribution::reset();

echo "\n[lead source: columns]\n";
check( 'header aliases', '@utm_source' === $handler->resolve_header( 'utm_source' ) && '@channel' === $handler->resolve_header( 'Lead Source' ) && '@gclid' === $handler->resolve_header( 'GCLID' ) );
$GLOBALS['opts']['ss_cf7_options'] = array( 'store_entries' => true, 'auto_retry' => true, 'max_attempts' => 5, 'alert_enabled' => false );
$s3            = $s;
$s3['meta']    = array( 'datetime', 'utm_campaign' );
$s3['mapping'] = array( array( 'header' => 'Tipologia Lead', 'type' => 'field', 'source' => '@channel', 'text' => '' ), array( 'header' => 'Note', 'type' => 'text', 'source' => '', 'text' => 'Campagna: [_ss_utm_campaign]' ) );
$GLOBALS['meta'][7]['_ss_cf7_settings'] = $s3;
$GLOBALS['http_log'] = array();
$handler->on_mail_sent( $form );
$sheet2            = array( 'Nome', 'Tipologia Lead', 'UTM campaign', 'gclid', 'Note' );
$GLOBALS['http'][] = array( 200, array( 'values' => array( $sheet2 ) ) );
$GLOBALS['http'][] = array( 200, array( 'updates' => array() ) );
$handler->process_queue();
$row = json_decode( end( $GLOBALS['http_log'] )['args']['body'], true )['values'][0];
check( 'row: channel mapped, UTM column, mail-tag in custom text, gclid not enabled', array( 'Mario', 'Google ADS', "Muffa O'Neill", '', "Campagna: Muffa O'Neill" ) === $row );

echo "\n[date format]\n";
$f = SS_CF7_Handler::format_options();
check( 'format defaults: 11/03/2026 14:30, form dates on', 'd/m/Y' === $f['date_format'] && 'H:i' === $f['time_format'] && true === $f['form_dates'] );
check( 'format sanitized', array( 'date_format' => 'd/m/Y', 'time_format' => '', 'form_dates' => false ) === SS_CF7_Handler::sanitize_format_options( array( 'date_format' => 'Y-m-d; DROP', 'time_format' => '' ) ) );
$dform = new WPCF7_ContactForm( 8, array( tag( 'text', 'Nome' ), tag( 'date', 'data-sopralluogo' ), tag( 'text', 'cap' ) ) );
$GLOBALS['forms'][8] = $dform;
$GLOBALS['meta'][8]['_ss_cf7_settings'] = SS_CF7_Handler::sanitize_form_settings( array( 'enabled' => 1, 'spreadsheet_id' => '1AbCdEfGhIjKlMnOpQrStUvWxYz012345', 'meta' => array( 'datetime' ) ) );
WPCF7_Submission::$instance = new WPCF7_Submission( array( 'Nome' => 'Anna', 'data-sopralluogo' => '2026-03-05', 'cap' => '00184' ), array( 'timestamp' => 1773239405 ) );
$send = function ( $headers ) use ( $handler, $dform ) {
	$GLOBALS['http_log'] = array();
	$handler->on_mail_sent( $dform );
	$GLOBALS['http'][] = array( 200, array( 'values' => array( $headers ) ) );
	$GLOBALS['http'][] = array( 200, array( 'updates' => array() ) );
	$handler->process_queue();
	return json_decode( end( $GLOBALS['http_log'] )['args']['body'], true )['values'][0];
};
$row = $send( array( 'Nome', 'data-sopralluogo', 'cap', 'Submission date' ) );
check( 'date field and submission date formatted, not escaped; ZIP still protected', array( 'Anna', '05/03/2026', "'00184", '11/03/2026 14:30' ) === $row );
$GLOBALS['opts']['ss_cf7_format'] = array( 'date_format' => 'Y-m-d', 'time_format' => 'H:i:s', 'form_dates' => false );
$row = $send( array( 'data-sopralluogo', 'Submission date' ) );
check( 'other format, form dates untouched', array( '2026-03-05', '2026-03-11 14:30:05' ) === $row );
$GLOBALS['opts']['ss_cf7_format'] = array( 'date_format' => 'd/m/Y', 'time_format' => '', 'form_dates' => true );
WPCF7_Submission::$instance = new WPCF7_Submission( array( 'data-sopralluogo' => '05/03/2026' ), array( 'timestamp' => 1773239405 ) );
$row = $send( array( 'data-sopralluogo', 'Submission date' ) );
check( 'date only; non-ISO date field left as typed (protected)', array( "'05/03/2026", '11/03/2026' ) === $row );

echo "\n[CF7 pipes]\n";
class Fake_Pipes {
	public function __construct( private array $pairs ) {}
	public function zero() { return empty( $this->pairs ); }
	public function to_array() { return $this->pairs; }
}
function pipe_tag( $basetype, $name, $pairs, $options = array() ) {
	return new class( $basetype, $name, $pairs, $options ) {
		public $pipes;
		public function __construct( public $basetype, public $name, $pairs, private $options ) { $this->pipes = new Fake_Pipes( $pairs ); }
		public function has_option( $o ) { return in_array( $o, $this->options, true ); }
	};
}
$pform = new WPCF7_ContactForm( 9, array( tag( 'text', 'Nome' ), pipe_tag( 'select', 'ufficio', array( array( 'Ufficio Roma', 'info@x.it' ), array( 'Ufficio Milano', 'info@x.it' ) ) ), pipe_tag( 'checkbox', 'servizi', array( array( 'Muffa', 'm' ), array( 'Umidità', 'u' ), array( 'Altro', 'altro' ) ), array( 'free_text' ) ), pipe_tag( 'radio', 'fonte', array() ) ) );
$GLOBALS['forms'][9] = $pform;
WPCF7_Submission::$instance = new WPCF7_Submission( array( 'Nome' => 'Anna', 'ufficio' => 'info@x.it', 'servizi' => array( 'm', 'altro Bagno' ), 'fonte' => 'Google' ), array( 'timestamp' => 1773239405 ) );
$_POST['ufficio']           = 'Ufficio Milano';
$_POST['servizi']           = array( 'Muffa', 'Altro' );
$_POST['servizi_free_text'] = 'Bagno';
$_POST['fonte']             = 'Google';
$psend = function ( $mode ) use ( $handler, $pform ) {
	$GLOBALS['meta'][9]['_ss_cf7_settings'] = SS_CF7_Handler::sanitize_form_settings( array( 'enabled' => 1, 'spreadsheet_id' => '1AbCdEfGhIjKlMnOpQrStUvWxYz012345', 'pipes' => $mode ) );
	$GLOBALS['http_log'] = array();
	$handler->on_mail_sent( $pform );
	$GLOBALS['http'][] = array( 200, array( 'values' => array( array( 'Nome', 'ufficio', 'servizi', 'fonte' ) ) ) );
	$GLOBALS['http'][] = array( 200, array( 'updates' => array() ) );
	$handler->process_queue();
	return json_decode( end( $GLOBALS['http_log'] )['args']['body'], true )['values'][0];
};
check( 'pipes: value (default, unchanged)', array( 'Anna', 'info@x.it', 'm, altro Bagno', 'Google' ) === $psend( 'value' ) );
check( 'pipes: label (shared value resolved, free text kept)', array( 'Anna', 'Ufficio Milano', 'Muffa, Altro Bagno', 'Google' ) === $psend( 'label' ) );
check( 'pipes: both', array( 'Anna', 'Ufficio Milano | info@x.it', 'Muffa | m, Altro | altro Bagno', 'Google' ) === $psend( 'both' ) );
$_POST['ufficio'] = '<b>Hack</b>';
check( 'pipes: unknown label sanitized', 'Hack' === $psend( 'label' )[1] );
check( 'pipes setting sanitized', 'value' === SS_CF7_Handler::sanitize_form_settings( array( 'pipes' => 'evil' ) )['pipes'] && 'value' === SS_CF7_Handler::get_form_settings( 12345 )['pipes'] );
unset( $_POST['ufficio'], $_POST['servizi'], $_POST['servizi_free_text'], $_POST['fonte'] );

echo "\n[menu parent]\n";
$GLOBALS['menu'] = array( array( 'Dashboard', 'read', 'index.php' ), array( 'Contact', 'wpcf7_read_contact_forms', 'wpcf7-dashboard' ) );
check( 'CF7 6.1+: wpcf7-dashboard', 'wpcf7-dashboard' === SS_CF7_Admin_Settings::menu_parent() );
$GLOBALS['menu'] = array( array( 'Contact', 'wpcf7_read_contact_forms', 'wpcf7' ) );
check( 'older CF7: wpcf7', 'wpcf7' === SS_CF7_Admin_Settings::menu_parent() );
$GLOBALS['menu'] = array();
check( 'fallback: Settings', 'options-general.php' === SS_CF7_Admin_Settings::menu_parent() );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );

