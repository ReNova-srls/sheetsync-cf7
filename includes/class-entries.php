<?php
/**
 * Local archive of the submissions (custom table), used by the dashboard,
 * the "retry" feature and the CSV export.
 *
 * @package SheetSync_CF7
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SS_CF7_Entries
 */
final class SS_CF7_Entries {

	public const STATUS_PENDING = 'pending';
	public const STATUS_SENT    = 'sent';
	public const STATUS_FAILED  = 'failed';

	/** Accepted values of the retention option (days, 0 = forever). */
	public const RETENTION_CHOICES = array( 0, 30, 90, 180, 365, 730 );

	/** Accepted values of the "maximum attempts" option (initial send included). */
	public const MAX_ATTEMPTS_CHOICES = array( 3, 5, 10 );

	/** A "pending" entry older than this (seconds) was interrupted (e.g. PHP timeout) and is retried. */
	public const STUCK_AFTER = 1800;

	/** Default options. */
	private const DEFAULT_OPTIONS = array(
		'store_entries'  => true,
		'retention_days' => 0,
		'auto_retry'     => true,
		'max_attempts'   => 5,
		'alert_enabled'  => true,
		'alert_email'    => '',
	);

	/**
	 * Registers hooks only.
	 */
	public function __construct() {
		// The hourly retry (SS_CF7_CRON_RETRY) is run by SS_CF7_Handler::run_auto_retry().
		add_action( SS_CF7_CRON_CLEANUP, array( $this, 'purge_expired' ) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Schema
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Full table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . SS_CF7_TABLE;
	}

	/**
	 * Installs the table when missing or outdated (e.g. on other sites of a network).
	 */
	public static function maybe_install(): void {
		if ( SS_CF7_DB_VERSION !== get_option( SS_CF7_OPTION_DB_VERSION ) ) {
			self::install();
		}
	}

	/**
	 * Creates/updates the table with dbDelta and schedules the cron events.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				form_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'pending',
				created_at datetime NOT NULL,
				sent_at datetime NULL DEFAULT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				last_attempt_at datetime NULL DEFAULT NULL,
				spreadsheet_id varchar(128) NOT NULL DEFAULT '',
				sheet_name varchar(100) NOT NULL DEFAULT '',
				fields longtext NOT NULL,
				row_data longtext NULL,
				error text NULL,
				PRIMARY KEY  (id),
				KEY form_created (form_id,created_at),
				KEY created_at (created_at),
				KEY status (status)
			) {$charset};"
		);

		update_option( SS_CF7_OPTION_DB_VERSION, SS_CF7_DB_VERSION );

		self::schedule_events();
	}

	/**
	 * Schedules the daily cleanup and the hourly retry when missing.
	 */
	public static function schedule_events(): void {
		if ( ! wp_next_scheduled( SS_CF7_CRON_CLEANUP ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SS_CF7_CRON_CLEANUP );
		}
		if ( ! wp_next_scheduled( SS_CF7_CRON_RETRY ) ) {
			wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', SS_CF7_CRON_RETRY );
		}
	}

	/*
	 * -------------------------------------------------------------------------
	 * Options
	 * -------------------------------------------------------------------------
	 */

	/**
	 * General options (archive, automatic retry, email alerts).
	 *
	 * @return array{store_entries: bool, retention_days: int, auto_retry: bool, max_attempts: int, alert_enabled: bool, alert_email: string}
	 */
	public static function options(): array {
		$saved = get_option( SS_CF7_OPTION_OPTIONS, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return self::sanitize_options( array_merge( self::DEFAULT_OPTIONS, $saved ) );
	}

	/**
	 * Sanitizes the general options. Unchecked checkboxes are absent from the
	 * posted form, so a missing boolean means "off".
	 *
	 * @param array $raw Raw options.
	 */
	public static function sanitize_options( array $raw ): array {
		$days     = isset( $raw['retention_days'] ) ? absint( $raw['retention_days'] ) : 0;
		$attempts = isset( $raw['max_attempts'] ) ? absint( $raw['max_attempts'] ) : self::DEFAULT_OPTIONS['max_attempts'];
		$emails   = $raw['alert_email'] ?? '';

		return array(
			'store_entries'  => ! empty( $raw['store_entries'] ),
			'retention_days' => in_array( $days, self::RETENTION_CHOICES, true ) ? $days : 0,
			'auto_retry'     => ! empty( $raw['auto_retry'] ),
			'max_attempts'   => in_array( $attempts, self::MAX_ATTEMPTS_CHOICES, true ) ? $attempts : self::DEFAULT_OPTIONS['max_attempts'],
			'alert_enabled'  => ! empty( $raw['alert_enabled'] ),
			'alert_email'    => self::sanitize_email_list( is_scalar( $emails ) ? (string) $emails : '' ),
		);
	}

	/**
	 * List of emails separated by commas, semicolons or spaces; invalid ones are dropped.
	 *
	 * @param string $raw Raw list.
	 * @return string Normalized list ("a@b.it, c@d.it"), max 5 addresses.
	 */
	public static function sanitize_email_list( string $raw ): string {
		$emails = array();

		foreach ( (array) preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $email ) {
			$email = sanitize_email( (string) $email );
			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $emails, true ) ) {
				$emails[] = $email;
			}
		}

		return implode( ', ', array_slice( $emails, 0, 5 ) );
	}

	/**
	 * Saves the archive options.
	 *
	 * @param array $raw Raw options.
	 */
	public static function save_options( array $raw ): void {
		update_option( SS_CF7_OPTION_OPTIONS, self::sanitize_options( $raw ) );
	}

	/**
	 * Whether submissions are stored locally.
	 */
	public function is_enabled(): bool {
		return self::options()['store_entries'];
	}

	/*
	 * -------------------------------------------------------------------------
	 * Write
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Stores a new submission (status "pending").
	 *
	 * @param int   $form_id  Form ID.
	 * @param array $settings Form settings.
	 * @param array $values   column key => value.
	 * @return int Entry ID (0 on failure or when the archive is disabled).
	 */
	public function insert( int $form_id, array $settings, array $values ): int {
		global $wpdb;

		if ( ! $this->is_enabled() ) {
			return 0;
		}

		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'form_id'        => $form_id,
				'status'         => self::STATUS_PENDING,
				'created_at'     => current_time( 'mysql', true ),
				'spreadsheet_id' => (string) ( $settings['spreadsheet_id'] ?? '' ),
				'sheet_name'     => (string) ( $settings['sheet_name'] ?? '' ),
				// Unescaped so that the dashboard search also finds accented text.
				'fields'         => (string) wp_json_encode( $values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Marks an entry as delivered.
	 *
	 * @param int   $id  Entry ID.
	 * @param array $row Row as written: list of [header, cell].
	 */
	public function mark_sent( int $id, array $row ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET status = %s, sent_at = %s, last_attempt_at = %s, attempts = attempts + 1, row_data = %s, error = NULL WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::STATUS_SENT,
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				(string) wp_json_encode( $row ),
				$id
			)
		);
	}

	/**
	 * Marks an entry as failed.
	 *
	 * @param int    $id    Entry ID.
	 * @param string $error Error message.
	 */
	public function mark_failed( int $id, string $error ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET status = %s, last_attempt_at = %s, attempts = attempts + 1, error = %s WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::STATUS_FAILED,
				current_time( 'mysql', true ),
				mb_substr( $error, 0, 2000 ),
				$id
			)
		);
	}

	/**
	 * Deletes entries.
	 *
	 * @param int[] $ids Entry IDs.
	 * @return int Deleted rows.
	 */
	public function delete( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE id IN ({$placeholders})", $ids ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Deletes the entries older than the retention period (daily cron).
	 *
	 * @return int Deleted rows.
	 */
	public function purge_expired(): int {
		global $wpdb;

		$days = self::options()['retention_days'];
		if ( $days <= 0 ) {
			return 0;
		}

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE created_at < %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			)
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Read
	 * -------------------------------------------------------------------------
	 */

	/**
	 * One entry, with decoded JSON columns.
	 *
	 * @param int $id Entry ID.
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return $row ? self::decode( $row ) : null;
	}

	/**
	 * Entries the automatic retry may send again: failed entries and entries
	 * stuck in "pending", with fewer than $max_attempts attempts. Oldest attempt first.
	 *
	 * @param int $max_attempts Maximum attempts (initial send included).
	 * @param int $limit        Maximum rows.
	 * @return array[]
	 */
	public function retry_candidates( int $max_attempts, int $limit = 100 ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE ( status = %s OR ( status = %s AND created_at < %s ) ) AND attempts < %d ORDER BY COALESCE(last_attempt_at, created_at) ASC, id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				self::STATUS_FAILED,
				self::STATUS_PENDING,
				gmdate( 'Y-m-d H:i:s', time() - self::STUCK_AFTER ),
				$max_attempts,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( self::class, 'decode' ), (array) $rows );
	}

	/**
	 * When the automatic retry will send an entry again.
	 *
	 * Backoff after the N-th failed attempt: 2^(N-1) hours (1, 2, 4, 8... max 24),
	 * so with 5 attempts a temporary outage is covered for about 15 hours.
	 *
	 * @param array      $entry   Decoded entry.
	 * @param array|null $options General options (default: the saved ones).
	 * @return int|null Unix timestamp, or null when no automatic retry is planned.
	 */
	public static function next_retry_at( array $entry, ?array $options = null ): ?int {
		$options  = $options ?? self::options();
		$attempts = (int) ( $entry['attempts'] ?? 0 );
		$status   = (string) ( $entry['status'] ?? '' );

		if ( ! $options['store_entries'] || ! $options['auto_retry'] || $attempts >= $options['max_attempts'] ) {
			return null;
		}
		if ( self::STATUS_FAILED !== $status && self::STATUS_PENDING !== $status ) {
			return null;
		}

		$last = (string) ( $entry['last_attempt_at'] ?? '' );
		$base = strtotime( ( '' !== $last ? $last : (string) ( $entry['created_at'] ?? '' ) ) . ' UTC' );
		if ( false === $base ) {
			return null;
		}

		return $base + ( 0 === $attempts ? self::STUCK_AFTER : min( 2 ** ( $attempts - 1 ), 24 ) * HOUR_IN_SECONDS );
	}

	/**
	 * Number of entries currently in error.
	 */
	public function count_failed(): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', self::STATUS_FAILED ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Paginated list.
	 *
	 * @param array $filters  See where().
	 * @param int   $page     1-based page.
	 * @param int   $per_page Items per page.
	 * @return array{items: array[], total: int}
	 */
	public function query( array $filters, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		[ $where, $params ] = $this->where( $filters );

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$params ? $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}", $params ) : 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where}" // phpcs:ignore WordPress.DB.PreparedSQL
		);

		$offset = max( 0, ( $page - 1 ) * $per_page );
		$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		return array(
			'items' => array_map( array( self::class, 'decode' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * All entries matching the filters (CSV export), newest first.
	 *
	 * @param array $filters See where().
	 * @param int   $limit   Safety cap.
	 * @return array[]
	 */
	public function export_rows( array $filters, int $limit = 10000 ): array {
		return $this->query( $filters, 1, $limit )['items'];
	}

	/**
	 * Submissions per day and status, in the site timezone, for the last N days.
	 *
	 * @param array $filters Filters (form_id, days...).
	 * @return array<string, array{sent: int, failed: int, pending: int}> 'Y-m-d' => counts, oldest first.
	 */
	public function daily_counts( array $filters ): array {
		global $wpdb;

		$days   = max( 1, (int) ( $filters['days'] ?? 30 ) );
		$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );

		// Empty series covering every day of the period (site timezone).
		$series = array();
		$today  = (int) floor( ( time() + $offset ) / DAY_IN_SECONDS ) * DAY_IN_SECONDS;
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$series[ gmdate( 'Y-m-d', $today - $i * DAY_IN_SECONDS ) ] = array(
				self::STATUS_SENT    => 0,
				self::STATUS_FAILED  => 0,
				self::STATUS_PENDING => 0,
			);
		}

		$filters['days']    = $days;
		[ $where, $params ] = $this->where( $filters );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS day, status, COUNT(*) AS total FROM ' . self::table() . " WHERE {$where} GROUP BY day, status", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( array( $offset ), $params )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $series[ $row['day'] ][ $row['status'] ] ) ) {
				$series[ $row['day'] ][ $row['status'] ] = (int) $row['total'];
			}
		}

		return $series;
	}

	/**
	 * Builds the WHERE clause.
	 *
	 * @param array $filters {form_id?: int, status?: string, days?: int, search?: string}.
	 * @return array{0: string, 1: array}
	 */
	private function where( array $filters ): array {
		global $wpdb;

		$sql    = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['form_id'] ) ) {
			$sql[]    = 'form_id = %d';
			$params[] = (int) $filters['form_id'];
		}

		if ( ! empty( $filters['status'] ) && in_array( $filters['status'], array( self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_PENDING ), true ) ) {
			$sql[]    = 'status = %s';
			$params[] = $filters['status'];
		}

		if ( ! empty( $filters['days'] ) ) {
			// Day boundaries in the site timezone, converted back to GMT.
			$offset   = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
			$today    = (int) floor( ( time() + $offset ) / DAY_IN_SECONDS ) * DAY_IN_SECONDS;
			$sql[]    = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d H:i:s', $today - ( (int) $filters['days'] - 1 ) * DAY_IN_SECONDS - $offset );
		}

		if ( isset( $filters['search'] ) && '' !== $filters['search'] ) {
			$sql[]    = 'fields LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%';
		}

		return array( implode( ' AND ', $sql ), $params );
	}

	/**
	 * Decodes the JSON columns of a DB row.
	 *
	 * @param array $row DB row.
	 */
	private static function decode( array $row ): array {
		$fields = json_decode( (string) ( $row['fields'] ?? '' ), true );
		$data   = json_decode( (string) ( $row['row_data'] ?? '' ), true );

		$row['id']       = (int) $row['id'];
		$row['form_id']  = (int) $row['form_id'];
		$row['attempts'] = (int) $row['attempts'];
		$row['fields']   = is_array( $fields ) ? $fields : array();
		$row['row_data'] = is_array( $data ) ? $data : array();
		$row['error']    = (string) ( $row['error'] ?? '' );

		return $row;
	}
}
