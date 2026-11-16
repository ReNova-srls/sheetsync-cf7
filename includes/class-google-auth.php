<?php
/**
 * Google Service Account authentication (JWT RS256 -> OAuth2 access token)
 * and a minimal Google Sheets API v4 client built only on the WordPress HTTP API.
 *
 * No external libraries: the JWT is signed with openssl_sign(), the token is
 * cached in a transient and every call goes through wp_remote_get()/wp_remote_post().
 *
 * @package SheetSync_CF7
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SS_CF7_Google_Auth
 */
final class SS_CF7_Google_Auth {

	/** OAuth2 scope: read/write access to spreadsheets shared with the Service Account. */
	public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

	/** Base URL of the Google Sheets API v4. */
	public const SHEETS_API = 'https://sheets.googleapis.com/v4/spreadsheets/';

	/** Hosts accepted as `token_uri`: a signed JWT is never sent to an arbitrary URL. */
	private const ALLOWED_TOKEN_HOSTS = array( 'oauth2.googleapis.com', 'accounts.google.com', 'www.googleapis.com' );

	/** Fields that must be present in the Service Account JSON. */
	private const REQUIRED_FIELDS = array( 'client_email', 'private_key', 'token_uri' );

	/** Access token cache lifetime: 50 minutes (Google tokens last 60). */
	private const TOKEN_TTL = 3000;

	/** JWT assertion lifetime (Google's maximum is 1 hour). */
	private const JWT_LIFETIME = 3600;

	/** A real Service Account key is ~2.3 KB: anything above this is rejected. */
	private const MAX_JSON_BYTES = 20480;

	/** At-rest encryption of the stored credentials. */
	private const CIPHER         = 'aes-256-gcm';
	private const PAYLOAD_PREFIX = 'v1:';

	/**
	 * HTTP timeout in seconds for Google calls.
	 *
	 * @var int
	 */
	private int $timeout = 15;

	/**
	 * Decrypted credentials, memoized for the current request only.
	 *
	 * @var array|null
	 */
	private ?array $credentials = null;

	/*
	 * -------------------------------------------------------------------------
	 * Credentials: validation & storage
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Validates a raw Service Account JSON string and returns a sanitized subset.
	 *
	 * Expects UNSLASHED input (call wp_unslash() on $_POST data before).
	 *
	 * @param string $raw Raw JSON pasted/uploaded by the admin.
	 * @return array|WP_Error Sanitized credentials or error.
	 */
	public static function parse_credentials( string $raw ): array|WP_Error {
		$raw = trim( $raw );

		// Strip the UTF-8 BOM some editors add when re-saving the file.
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}

		if ( '' === $raw ) {
			return new WP_Error( 'ss_cf7_empty_json', __( 'Paste the content of the Service Account JSON file (or upload the .json file).', 'sheetsync-cf7' ) );
		}

		if ( strlen( $raw ) > self::MAX_JSON_BYTES ) {
			return new WP_Error( 'ss_cf7_json_too_large', __( 'The JSON is too large to be a Service Account key.', 'sheetsync-cf7' ) );
		}

		$data = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'ss_cf7_invalid_json', __( 'The text is not valid JSON. Paste the whole content of the .json file downloaded from Google Cloud Console.', 'sheetsync-cf7' ) );
		}

		if ( isset( $data['type'] ) && 'service_account' !== $data['type'] ) {
			return new WP_Error( 'ss_cf7_wrong_key_type', __( 'This JSON is not a Service Account key (the "type" field must be "service_account"). Make sure you created a key for a Service Account, not an OAuth client ID.', 'sheetsync-cf7' ) );
		}

		$missing = array();
		foreach ( self::REQUIRED_FIELDS as $field ) {
			if ( empty( $data[ $field ] ) || ! is_string( $data[ $field ] ) ) {
				$missing[] = $field;
			}
		}
		if ( $missing ) {
			return new WP_Error(
				'ss_cf7_missing_fields',
				sprintf(
					/* translators: %s: comma-separated list of JSON field names. */
					__( 'Required fields missing from the JSON: %s.', 'sheetsync-cf7' ),
					implode( ', ', $missing )
				)
			);
		}

		$client_email = sanitize_email( $data['client_email'] );
		if ( ! is_email( $client_email ) ) {
			return new WP_Error( 'ss_cf7_invalid_email', __( 'The "client_email" field is not a valid email address.', 'sheetsync-cf7' ) );
		}

		$token_uri = self::validate_token_uri( $data['token_uri'] );
		if ( '' === $token_uri ) {
			return new WP_Error( 'ss_cf7_invalid_token_uri', __( 'The "token_uri" field must be an official Google HTTPS endpoint (e.g. https://oauth2.googleapis.com/token).', 'sheetsync-cf7' ) );
		}

		// json_decode() already turns "\n" escapes into newlines; also handle double-escaped keys.
		$private_key = str_replace( array( "\r\n", '\n' ), "\n", trim( $data['private_key'] ) );

		if ( ! str_contains( $private_key, 'PRIVATE KEY-----' ) ) {
			return new WP_Error( 'ss_cf7_invalid_private_key', __( 'The "private_key" field does not contain a PEM private key.', 'sheetsync-cf7' ) );
		}

		$key = openssl_pkey_get_private( $private_key );
		if ( false === $key ) {
			self::clear_openssl_errors();
			return new WP_Error( 'ss_cf7_unreadable_private_key', __( 'OpenSSL could not read the "private_key". The JSON may be truncated or modified: download a new key from Google Cloud Console.', 'sheetsync-cf7' ) );
		}

		$details = openssl_pkey_get_details( $key );
		if ( ! is_array( $details ) || OPENSSL_KEYTYPE_RSA !== ( $details['type'] ?? null ) ) {
			return new WP_Error( 'ss_cf7_not_rsa', __( 'The private key is not an RSA key, which is required by Google (RS256).', 'sheetsync-cf7' ) );
		}

		return array(
			'type'           => 'service_account',
			'project_id'     => isset( $data['project_id'] ) ? sanitize_text_field( (string) $data['project_id'] ) : '',
			'private_key_id' => isset( $data['private_key_id'] ) ? sanitize_text_field( (string) $data['private_key_id'] ) : '',
			'private_key'    => $private_key,
			'client_email'   => $client_email,
			'token_uri'      => $token_uri,
		);
	}

	/**
	 * Stores validated credentials (encrypted at rest, not autoloaded).
	 *
	 * @param array $credentials Output of parse_credentials().
	 * @return bool|WP_Error True on success.
	 */
	public function save_credentials( array $credentials ): bool|WP_Error {
		$payload = self::encrypt( (string) wp_json_encode( $credentials ) );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$stored = array(
			// Public, non-secret data kept in clear for the UI (no decryption needed).
			'client_email' => $credentials['client_email'],
			'project_id'   => $credentials['project_id'] ?? '',
			'saved_at'     => time(),
			// Secret material.
			'payload'      => $payload,
		);

		// delete + add guarantees autoload = no even on WP < 6.6.
		delete_option( SS_CF7_OPTION_CREDENTIALS );
		if ( ! add_option( SS_CF7_OPTION_CREDENTIALS, $stored, '', false ) ) {
			return new WP_Error( 'ss_cf7_save_failed', __( 'Could not save the credentials to the database.', 'sheetsync-cf7' ) );
		}

		$this->credentials = null;
		$this->flush_token();

		return true;
	}

	/**
	 * Removes the stored credentials and the cached token.
	 */
	public function delete_credentials(): void {
		delete_option( SS_CF7_OPTION_CREDENTIALS );
		$this->credentials = null;
		$this->flush_token();
	}

	/**
	 * Whether a Service Account has been saved.
	 */
	public function has_credentials(): bool {
		$stored = get_option( SS_CF7_OPTION_CREDENTIALS );
		return is_array( $stored ) && ! empty( $stored['payload'] ) && ! empty( $stored['client_email'] );
	}

	/**
	 * Non-secret information safe to print in the admin UI.
	 * The private key is never returned by any public method.
	 *
	 * @return array{connected: bool, client_email: string, project_id: string, saved_at: int}
	 */
	public function get_public_info(): array {
		$stored = get_option( SS_CF7_OPTION_CREDENTIALS );

		if ( ! is_array( $stored ) || empty( $stored['payload'] ) ) {
			return array(
				'connected'    => false,
				'client_email' => '',
				'project_id'   => '',
				'saved_at'     => 0,
			);
		}

		return array(
			'connected'    => true,
			'client_email' => sanitize_email( (string) ( $stored['client_email'] ?? '' ) ),
			'project_id'   => sanitize_text_field( (string) ( $stored['project_id'] ?? '' ) ),
			'saved_at'     => (int) ( $stored['saved_at'] ?? 0 ),
		);
	}

	/**
	 * Service Account email (the address the user must share the sheet with).
	 */
	public function get_client_email(): string {
		return $this->get_public_info()['client_email'];
	}

	/**
	 * Loads and decrypts the stored credentials.
	 *
	 * @return array|WP_Error
	 */
	private function get_credentials(): array|WP_Error {
		if ( null !== $this->credentials ) {
			return $this->credentials;
		}

		$stored = get_option( SS_CF7_OPTION_CREDENTIALS );
		if ( ! is_array( $stored ) || empty( $stored['payload'] ) ) {
			return new WP_Error( 'ss_cf7_no_credentials', __( 'No Service Account configured. Go to Contact → SheetSync for CF7 and add your JSON key.', 'sheetsync-cf7' ) );
		}

		$json = self::decrypt( (string) $stored['payload'] );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$credentials = json_decode( $json, true );
		if ( ! is_array( $credentials ) || empty( $credentials['private_key'] ) || empty( $credentials['client_email'] ) || empty( $credentials['token_uri'] ) ) {
			return new WP_Error( 'ss_cf7_corrupted_credentials', __( 'The stored credentials are corrupted. Remove them and upload the JSON key again.', 'sheetsync-cf7' ) );
		}

		$this->credentials = $credentials;
		return $credentials;
	}

	/*
	 * -------------------------------------------------------------------------
	 * OAuth2: JWT bearer flow
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Returns a valid OAuth2 access token, from cache when possible.
	 *
	 * @param bool $force_refresh Skip the cache (used by "Test connection").
	 * @return string|WP_Error
	 */
	public function get_access_token( bool $force_refresh = false ): string|WP_Error {
		if ( ! $force_refresh ) {
			$cached = get_transient( SS_CF7_TRANSIENT_TOKEN );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$credentials = $this->get_credentials();
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$jwt = $this->build_jwt( $credentials );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$response = wp_remote_post(
			$credentials['token_uri'],
			array(
				'timeout'    => $this->timeout,
				'user-agent' => self::user_agent(),
				'headers'    => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'       => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ss_cf7_http_error',
				sprintf(
					/* translators: %s: low-level HTTP error message. */
					__( 'Could not reach the Google authentication server: %s', 'sheetsync-cf7' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( 200 !== $code || empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return $this->token_error( $code, $data );
		}

		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : 3600;
		$ttl        = min( self::TOKEN_TTL, max( 60, $expires_in - 300 ) );

		set_transient( SS_CF7_TRANSIENT_TOKEN, $data['access_token'], $ttl );

		return $data['access_token'];
	}

	/**
	 * Deletes the cached access token.
	 */
	public function flush_token(): void {
		delete_transient( SS_CF7_TRANSIENT_TOKEN );
	}

	/**
	 * Forces a fresh token exchange: proves that the JSON is valid, the key is
	 * still active on Google Cloud and the server clock is correct.
	 *
	 * @return array|WP_Error Public account info on success.
	 */
	public function test_connection(): array|WP_Error {
		$token = $this->get_access_token( true );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$info = $this->get_public_info();

		return array(
			'client_email' => $info['client_email'],
			'project_id'   => $info['project_id'],
		);
	}

	/**
	 * Builds and signs (RS256) the JWT assertion for the token endpoint.
	 *
	 * @param array $credentials Decrypted credentials.
	 * @return string|WP_Error
	 */
	private function build_jwt( array $credentials ): string|WP_Error {
		// Small back-dating tolerates servers whose clock runs slightly ahead.
		$issued_at = time() - 30;

		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		if ( ! empty( $credentials['private_key_id'] ) ) {
			$header['kid'] = $credentials['private_key_id'];
		}

		$claims = array(
			'iss'   => $credentials['client_email'],
			'scope' => self::SCOPE,
			'aud'   => $credentials['token_uri'],
			'iat'   => $issued_at,
			'exp'   => $issued_at + self::JWT_LIFETIME,
		);

		$signing_input = self::base64url_encode( (string) wp_json_encode( $header, JSON_UNESCAPED_SLASHES ) )
			. '.'
			. self::base64url_encode( (string) wp_json_encode( $claims, JSON_UNESCAPED_SLASHES ) );

		$key = openssl_pkey_get_private( $credentials['private_key'] );
		if ( false === $key ) {
			self::clear_openssl_errors();
			return new WP_Error( 'ss_cf7_unreadable_private_key', __( 'OpenSSL could not read the stored private key. Remove the credentials and upload the JSON key again.', 'sheetsync-cf7' ) );
		}

		$signature = '';
		if ( ! openssl_sign( $signing_input, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			self::clear_openssl_errors();
			return new WP_Error( 'ss_cf7_sign_failed', __( 'OpenSSL failed to sign the authentication request (RS256).', 'sheetsync-cf7' ) );
		}

		return $signing_input . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Converts a token endpoint failure into a helpful message.
	 *
	 * @param int   $code HTTP status.
	 * @param array $data Decoded response body.
	 */
	private function token_error( int $code, array $data ): WP_Error {
		$error       = isset( $data['error'] ) && is_string( $data['error'] ) ? $data['error'] : '';
		$description = isset( $data['error_description'] ) && is_string( $data['error_description'] ) ? sanitize_text_field( $data['error_description'] ) : '';
		$haystack    = strtolower( $error . ' ' . $description );

		if ( str_contains( $haystack, 'invalid jwt signature' ) || str_contains( $haystack, 'account not found' ) ) {
			$message = __( 'Google rejected the key: it may have been deleted or disabled in Google Cloud Console, or the Service Account no longer exists. Create a new JSON key and upload it again.', 'sheetsync-cf7' );
		} elseif ( str_contains( $haystack, 'timeframe' ) || str_contains( $haystack, 'iat and exp' ) ) {
			$message = __( 'Google rejected the request time: the server clock seems wrong. Ask your hosting provider to synchronize it (NTP).', 'sheetsync-cf7' );
		} elseif ( 'invalid_grant' === $error || 'invalid_client' === $error || 'unauthorized_client' === $error ) {
			$message = __( 'Google rejected the Service Account credentials.', 'sheetsync-cf7' );
		} else {
			$message = __( 'Could not obtain an access token from Google.', 'sheetsync-cf7' );
		}

		$details = trim( $error . ( '' !== $description ? ' – ' . $description : '' ) );
		if ( '' !== $details ) {
			/* translators: 1: friendly message, 2: HTTP status code, 3: Google error details. */
			$message = sprintf( __( '%1$s (HTTP %2$d: %3$s)', 'sheetsync-cf7' ), $message, $code, $details );
		}

		return new WP_Error( 'ss_cf7_token_error', $message, array( 'status' => $code ) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Google Sheets API v4
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Sets the HTTP timeout (the frontend handler uses a shorter one).
	 *
	 * @param int $seconds Timeout, clamped between 3 and 30 seconds.
	 */
	public function set_timeout( int $seconds ): self {
		$this->timeout = max( 3, min( 30, $seconds ) );
		return $this;
	}

	/**
	 * Spreadsheet title and list of tabs. Useful to validate ID + tab name.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @return array|WP_Error {title: string, sheets: string[]}
	 */
	public function get_spreadsheet_info( string $spreadsheet_id ): array|WP_Error {
		$url  = self::SHEETS_API . rawurlencode( $spreadsheet_id ) . self::query( array( 'fields' => 'properties.title,sheets.properties.title' ) );
		$data = $this->request( 'GET', $url );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$sheets = array();
		foreach ( (array) ( $data['sheets'] ?? array() ) as $sheet ) {
			if ( isset( $sheet['properties']['title'] ) ) {
				$sheets[] = (string) $sheet['properties']['title'];
			}
		}

		return array(
			'title'  => (string) ( $data['properties']['title'] ?? '' ),
			'sheets' => $sheets,
		);
	}

	/**
	 * Reads a range of values.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param string $range          A1 range (use self::a1() to build it).
	 * @return array|WP_Error Rows (array of arrays). Empty array when the range is empty.
	 */
	public function get_values( string $spreadsheet_id, string $range ): array|WP_Error {
		$url = self::SHEETS_API . rawurlencode( $spreadsheet_id ) . '/values/' . rawurlencode( $range )
			. self::query(
				array(
					'majorDimension'    => 'ROWS',
					'valueRenderOption' => 'FORMATTED_VALUE',
				)
			);

		$data = $this->request( 'GET', $url );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return isset( $data['values'] ) && is_array( $data['values'] ) ? $data['values'] : array();
	}

	/**
	 * Reads row 1 of a tab (the column headers).
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param string $sheet_name     Tab name ('' = first tab).
	 * @return string[]|WP_Error Header labels, in column order (empty cells as '').
	 */
	public function get_header_row( string $spreadsheet_id, string $sheet_name ): array|WP_Error {
		$rows = $this->get_values( $spreadsheet_id, self::a1( $sheet_name, '1:1' ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return array_map( static fn( $cell ) => trim( (string) $cell ), (array) ( $rows[0] ?? array() ) );
	}

	/**
	 * Overwrites a range with the given values.
	 * Uses values:batchUpdate (POST) so that only wp_remote_get/post are needed.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param string $range          A1 range.
	 * @param array  $rows           Array of rows (each an array of scalars).
	 * @param string $input_option   'RAW' or 'USER_ENTERED'.
	 * @return array|WP_Error API response.
	 */
	public function update_values( string $spreadsheet_id, string $range, array $rows, string $input_option = 'RAW' ): array|WP_Error {
		$url = self::SHEETS_API . rawurlencode( $spreadsheet_id ) . '/values:batchUpdate';

		return $this->request(
			'POST',
			$url,
			array(
				'valueInputOption' => self::input_option( $input_option ),
				'data'             => array(
					array(
						'range'          => $range,
						'majorDimension' => 'ROWS',
						'values'         => array_values( $rows ),
					),
				),
			)
		);
	}

	/**
	 * Appends rows after the last row of the table that starts in A1.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 * @param string $sheet_name     Tab name ('' = first tab).
	 * @param array  $rows           Array of rows.
	 * @param string $input_option   'USER_ENTERED' (default) or 'RAW'.
	 * @return array|WP_Error API response.
	 */
	public function append_values( string $spreadsheet_id, string $sheet_name, array $rows, string $input_option = 'USER_ENTERED' ): array|WP_Error {
		$url = self::SHEETS_API . rawurlencode( $spreadsheet_id ) . '/values/' . rawurlencode( self::a1( $sheet_name, 'A1' ) ) . ':append'
			. self::query(
				array(
					'valueInputOption'        => self::input_option( $input_option ),
					'insertDataOption'        => 'INSERT_ROWS',
					'includeValuesInResponse' => 'false',
				)
			);

		return $this->request(
			'POST',
			$url,
			array(
				'majorDimension' => 'ROWS',
				'values'         => array_values( $rows ),
			)
		);
	}

	/**
	 * Authenticated request to the Sheets API. Retries once with a fresh token on 401.
	 *
	 * @param string     $method 'GET' or 'POST'.
	 * @param string     $url    Full URL.
	 * @param array|null $body   JSON body for POST.
	 * @param bool       $retry  Internal: allow one retry after a 401.
	 * @return array|WP_Error Decoded JSON response.
	 */
	private function request( string $method, string $url, ?array $body = null, bool $retry = true ): array|WP_Error {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = array(
			'timeout'    => $this->timeout,
			'user-agent' => self::user_agent(),
			'headers'    => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);

		if ( 'POST' === $method ) {
			$args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$args['body']                    = (string) wp_json_encode( $body ?? new stdClass() );
			$response                        = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ss_cf7_http_error',
				sprintf(
					/* translators: %s: low-level HTTP error message. */
					__( 'Could not reach the Google Sheets API: %s', 'sheetsync-cf7' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		// Token revoked/expired before the transient: refresh once and retry.
		if ( 401 === $code && $retry ) {
			$this->flush_token();
			return $this->request( $method, $url, $body, false );
		}

		if ( $code < 200 || $code >= 300 ) {
			return $this->api_error( $code, $data );
		}

		return $data;
	}

	/**
	 * Maps Sheets API errors to actionable messages.
	 *
	 * @param int   $code HTTP status.
	 * @param array $data Decoded error body.
	 */
	private function api_error( int $code, array $data ): WP_Error {
		$google_message = isset( $data['error']['message'] ) ? sanitize_text_field( (string) $data['error']['message'] ) : '';
		$google_status  = isset( $data['error']['status'] ) ? sanitize_text_field( (string) $data['error']['status'] ) : '';
		$lower          = strtolower( $google_message );

		if ( 403 === $code && ( str_contains( $lower, 'has not been used' ) || str_contains( $lower, 'is disabled' ) ) ) {
			$message = __( 'The Google Sheets API is not enabled for this Google Cloud project. Enable it in Google Cloud Console (APIs & Services → Library → Google Sheets API) and try again in a few minutes.', 'sheetsync-cf7' );
		} elseif ( 403 === $code ) {
			$message = sprintf(
				/* translators: %s: Service Account email. */
				__( 'Access denied. Open the spreadsheet in Google Sheets, click "Share" and add %s as Editor.', 'sheetsync-cf7' ),
				$this->get_client_email()
			);
		} elseif ( 404 === $code ) {
			$message = __( 'Spreadsheet not found. Check the Spreadsheet ID.', 'sheetsync-cf7' );
		} elseif ( 400 === $code && str_contains( $lower, 'unable to parse range' ) ) {
			$message = __( 'Sheet tab not found. Check the tab name (it is case-sensitive and must match exactly).', 'sheetsync-cf7' );
		} elseif ( 429 === $code ) {
			$message = __( 'Google Sheets API quota exceeded. Wait a minute and try again.', 'sheetsync-cf7' );
		} elseif ( $code >= 500 ) {
			$message = __( 'Google Sheets is temporarily unavailable. Try again later.', 'sheetsync-cf7' );
		} else {
			$message = __( 'The Google Sheets API returned an error.', 'sheetsync-cf7' );
		}

		if ( '' !== $google_message ) {
			/* translators: 1: friendly message, 2: HTTP status code, 3: Google error details. */
			$message = sprintf( __( '%1$s (HTTP %2$d: %3$s)', 'sheetsync-cf7' ), $message, $code, $google_message );
		}

		return new WP_Error(
			'ss_cf7_api_error',
			$message,
			array(
				'status'        => $code,
				'google_status' => $google_status,
			)
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Builds an A1 range with a correctly quoted tab name, e.g. 'My Tab'!A1.
	 *
	 * @param string $sheet_name Tab name ('' = first tab of the spreadsheet).
	 * @param string $cells      Cell part, e.g. 'A1' or '1:1'.
	 */
	public static function a1( string $sheet_name, string $cells ): string {
		$sheet_name = trim( $sheet_name );

		if ( '' === $sheet_name ) {
			return $cells;
		}

		return "'" . str_replace( "'", "''", $sheet_name ) . "'!" . $cells;
	}

	/**
	 * Accepts a Spreadsheet ID or a full Google Sheets URL and returns a clean ID ('' if invalid).
	 *
	 * @param string $input User input.
	 */
	public static function sanitize_spreadsheet_id( string $input ): string {
		$input = trim( wp_strip_all_tags( $input ) );

		if ( preg_match( '#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $input, $matches ) ) {
			$input = $matches[1];
		}

		return preg_match( '/^[a-zA-Z0-9_-]{20,128}$/', $input ) ? $input : '';
	}

	/**
	 * Sanitizes a tab name: strips tags and characters Google Sheets forbids, max 100 chars.
	 *
	 * @param string $name User input.
	 */
	public static function sanitize_sheet_name( string $name ): string {
		$name = sanitize_text_field( $name );
		$name = str_replace( array( '[', ']', '*', '?', '/', '\\', ':' ), '', $name );

		return trim( mb_substr( $name, 0, 100 ) );
	}

	/**
	 * Base64url encoding (RFC 7515) without padding.
	 *
	 * @param string $data Binary data.
	 */
	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Builds a RFC 3986 query string, prefixed with '?'.
	 *
	 * @param array $args Query args.
	 */
	private static function query( array $args ): string {
		return '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Whitelists the valueInputOption.
	 *
	 * @param string $option Requested option.
	 */
	private static function input_option( string $option ): string {
		return 'RAW' === $option ? 'RAW' : 'USER_ENTERED';
	}

	/**
	 * Validates token_uri against the official Google hosts.
	 *
	 * @param string $uri Raw value from the JSON.
	 * @return string Clean URI or '' when not allowed.
	 */
	private static function validate_token_uri( string $uri ): string {
		$uri   = esc_url_raw( trim( $uri ), array( 'https' ) );
		$parts = wp_parse_url( $uri );

		if ( empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) ) {
			return '';
		}

		return in_array( strtolower( $parts['host'] ), self::ALLOWED_TOKEN_HOSTS, true ) ? $uri : '';
	}

	/**
	 * Custom user agent (avoids leaking the site URL that WordPress adds by default).
	 */
	private static function user_agent(): string {
		return 'SheetSync-CF7/' . SS_CF7_VERSION . '; WordPress/' . get_bloginfo( 'version' );
	}

	/**
	 * Drains the OpenSSL error queue so stale errors don't leak into later calls.
	 */
	private static function clear_openssl_errors(): void {
		while ( false !== openssl_error_string() ) {
			// Intentionally empty.
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 * At-rest encryption (AES-256-GCM, key derived from the WordPress salts)
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Derives the 256-bit encryption key from AUTH_KEY/AUTH_SALT.
	 */
	private static function crypto_key(): string {
		return hash_hmac( 'sha256', 'sheetsync-cf7|credentials|v1', wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypts the credentials JSON.
	 *
	 * @param string $plain Plain JSON.
	 * @return string|WP_Error Prefixed base64 payload.
	 */
	private static function encrypt( string $plain ): string|WP_Error {
		if ( ! in_array( self::CIPHER, openssl_get_cipher_methods(), true ) ) {
			return new WP_Error( 'ss_cf7_cipher_unavailable', __( 'The server OpenSSL library does not support AES-256-GCM, required to store the credentials securely.', 'sheetsync-cf7' ) );
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( \Exception $e ) {
			return new WP_Error( 'ss_cf7_random_failed', __( 'Could not generate secure random bytes on this server.', 'sheetsync-cf7' ) );
		}

		$tag    = '';
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

		if ( false === $cipher || 16 !== strlen( $tag ) ) {
			self::clear_openssl_errors();
			return new WP_Error( 'ss_cf7_encrypt_failed', __( 'Could not encrypt the credentials.', 'sheetsync-cf7' ) );
		}

		return self::PAYLOAD_PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts a payload produced by encrypt().
	 *
	 * @param string $payload Prefixed base64 payload.
	 * @return string|WP_Error Plain JSON.
	 */
	private static function decrypt( string $payload ): string|WP_Error {
		$unreadable = new WP_Error( 'ss_cf7_decrypt_failed', __( 'The stored credentials can no longer be decrypted (the WordPress security keys in wp-config.php may have changed). Remove them and upload the JSON key again.', 'sheetsync-cf7' ) );

		if ( ! str_starts_with( $payload, self::PAYLOAD_PREFIX ) ) {
			return $unreadable;
		}

		$binary = base64_decode( substr( $payload, strlen( self::PAYLOAD_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $binary || strlen( $binary ) <= 28 ) {
			return $unreadable;
		}

		$iv     = substr( $binary, 0, 12 );
		$tag    = substr( $binary, 12, 16 );
		$cipher = substr( $binary, 28 );

		$plain = openssl_decrypt( $cipher, self::CIPHER, self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $plain ) {
			self::clear_openssl_errors();
			return $unreadable;
		}

		return $plain;
	}
}
