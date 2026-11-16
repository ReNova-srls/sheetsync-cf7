<?php
/**
 * Contact Form 7 submission handler: per-form settings, column mapping and
 * delivery of each submission to Google Sheets.
 *
 * Delivery happens on `shutdown`, after the response has been sent to the
 * visitor whenever the server supports it (PHP-FPM / LiteSpeed), so Google API
 * latency or errors never slow down or break the form.
 *
 * @package SheetSync_CF7
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SS_CF7_Handler
 */
final class SS_CF7_Handler {

	/** Optional metadata columns, as stored in the form settings. */
	private const META_KEYS = array( 'datetime', 'page_url', 'ip', 'channel', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', 'landing_page', 'referrer' );

	/** Metadata filled by the lead source tracking (SS_CF7_Attribution::KEYS). */
	public const TRACKING_KEYS = array( 'channel', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', 'landing_page', 'referrer' );

	/** Untranslated labels: always recognised as headers, whatever the site language. */
	private const META_LABELS_EN = array(
		'datetime'     => 'Submission date',
		'page_url'     => 'Page URL',
		'ip'           => 'IP address',
		'channel'      => 'Lead source',
		'utm_source'   => 'UTM source',
		'utm_medium'   => 'UTM medium',
		'utm_campaign' => 'UTM campaign',
		'utm_term'     => 'UTM term',
		'utm_content'  => 'UTM content',
		'gclid'        => 'Google click ID',
		'fbclid'       => 'Facebook click ID',
		'msclkid'      => 'Microsoft click ID',
		'landing_page' => 'Landing page',
		'referrer'     => 'Referrer',
	);

	/** Column keys of the metadata start with "@", which is invalid in CF7 field names (no collisions). */
	public const META_PREFIX = '@';

	/** Value keys holding the resolved custom text of a mapping rule. */
	private const TEXT_PREFIX = '#text:';

	/** Mapping rule types. */
	public const RULE_TYPES = array( 'auto', 'field', 'text', 'empty' );

	/** Field types that never carry a value worth storing. */
	private const EXCLUDED_TYPES = array( 'submit', 'captchac', 'captchar', 'quiz', 'file', 'response' );

	/** Google Sheets limit per cell. */
	private const MAX_CELL_LENGTH = 50000;

	/** Max mapping rows kept per form. */
	private const MAX_MAPPING_ROWS = 200;

	/** Option holding the date/time format. */
	public const FORMAT_OPTION = 'ss_cf7_format';

	/** Date formats offered in the settings (PHP date format). */
	public const DATE_FORMATS = array( 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd.m.Y', 'd-m-Y' );

	/** Time formats offered in the settings ('' = date only). */
	public const TIME_FORMATS = array( 'H:i', 'H:i:s', 'g:i A', '' );

	/** How fields with CF7 pipes ("Label|value") are written: value (CF7 default), label or both. */
	public const PIPE_MODES = array( 'value', 'label', 'both' );

	/** Value key listing the keys whose value must reach Sheets as typed (formatted dates). */
	private const RAW_KEYS = '#raw';

	/**
	 * Google client.
	 *
	 * @var SS_CF7_Google_Auth
	 */
	private SS_CF7_Google_Auth $auth;

	/**
	 * Local archive.
	 *
	 * @var SS_CF7_Entries
	 */
	private SS_CF7_Entries $entries;

	/**
	 * Submissions waiting to be delivered on shutdown.
	 *
	 * @var array<int, array>
	 */
	private array $queue = array();

	/**
	 * Header alias map (normalized alias => column key), built lazily.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $aliases = null;

	/**
	 * Registers hooks only.
	 *
	 * @param SS_CF7_Google_Auth $auth    Google client.
	 * @param SS_CF7_Entries     $entries Local archive.
	 */
	public function __construct( SS_CF7_Google_Auth $auth, SS_CF7_Entries $entries ) {
		$this->auth    = $auth;
		$this->entries = $entries;

		add_action( 'wpcf7_mail_sent', array( $this, 'on_mail_sent' ) );
		add_action( SS_CF7_CRON_RETRY, array( $this, 'run_auto_retry' ) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Per-form settings
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Default settings of a form.
	 */
	public static function default_settings(): array {
		return array(
			'enabled'        => false,
			'spreadsheet_id' => '',
			'sheet_name'     => '',
			'meta'           => array( 'datetime' ),
			'exclude'        => array(),
			'mapping'        => array(),
			'pipes'          => 'value',
		);
	}

	/**
	 * Settings of a form (defaults when never saved).
	 *
	 * @param int $form_id CF7 form ID.
	 */
	public static function get_form_settings( int $form_id ): array {
		$saved = $form_id > 0 ? get_post_meta( $form_id, SS_CF7_META_SETTINGS, true ) : array();

		if ( ! is_array( $saved ) ) {
			return self::default_settings();
		}

		return self::sanitize_form_settings( array_merge( self::default_settings(), $saved ) );
	}

	/**
	 * Sanitizes raw (unslashed) settings coming from the editor panel or the AJAX calls.
	 *
	 * @param array $raw Raw settings.
	 */
	public static function sanitize_form_settings( array $raw ): array {
		$meta    = isset( $raw['meta'] ) && is_array( $raw['meta'] ) ? array_filter( $raw['meta'], 'is_string' ) : array();
		$exclude = isset( $raw['exclude'] ) && is_array( $raw['exclude'] ) ? array_filter( $raw['exclude'], 'is_string' ) : array();

		return array(
			'enabled'        => ! empty( $raw['enabled'] ),
			'spreadsheet_id' => SS_CF7_Google_Auth::sanitize_spreadsheet_id( isset( $raw['spreadsheet_id'] ) && is_string( $raw['spreadsheet_id'] ) ? $raw['spreadsheet_id'] : '' ),
			'sheet_name'     => SS_CF7_Google_Auth::sanitize_sheet_name( isset( $raw['sheet_name'] ) && is_string( $raw['sheet_name'] ) ? $raw['sheet_name'] : '' ),
			'meta'           => array_values( array_intersect( self::META_KEYS, $meta ) ),
			'exclude'        => array_values( array_unique( array_filter( array_map( array( self::class, 'sanitize_field_name' ), $exclude ) ) ) ),
			'mapping'        => self::sanitize_mapping( $raw['mapping'] ?? array() ),
			'pipes'          => isset( $raw['pipes'] ) && in_array( $raw['pipes'], self::PIPE_MODES, true ) ? $raw['pipes'] : 'value',
		);
	}

	/**
	 * Sanitizes the column mapping.
	 *
	 * Each row: {header, type, source, text}. Rows coming from the editor use a
	 * single "rule" select value instead: 'auto' | 'empty' | 'text' | 'field:<key>'.
	 *
	 * @param mixed $raw Raw rows.
	 * @return array<int, array{header: string, type: string, source: string, text: string}>
	 */
	public static function sanitize_mapping( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();
		$seen = array();

		foreach ( $raw as $rule ) {
			if ( ! is_array( $rule ) || count( $rows ) >= self::MAX_MAPPING_ROWS ) {
				continue;
			}

			$header = isset( $rule['header'] ) && is_string( $rule['header'] ) ? trim( mb_substr( sanitize_text_field( $rule['header'] ), 0, 200 ) ) : '';
			$norm   = self::normalize( $header );
			if ( '' === $header || isset( $seen[ $norm ] ) ) {
				continue;
			}
			$seen[ $norm ] = true;

			$type   = isset( $rule['type'] ) && is_string( $rule['type'] ) ? $rule['type'] : 'auto';
			$source = isset( $rule['source'] ) && is_string( $rule['source'] ) ? $rule['source'] : '';

			if ( isset( $rule['rule'] ) && is_string( $rule['rule'] ) ) {
				if ( str_starts_with( $rule['rule'], 'field:' ) ) {
					$type   = 'field';
					$source = substr( $rule['rule'], 6 );
				} else {
					$type = $rule['rule'];
				}
			}

			$type   = in_array( $type, self::RULE_TYPES, true ) ? $type : 'auto';
			$source = 'field' === $type ? self::sanitize_field_name( $source ) : '';
			$text   = 'text' === $type && isset( $rule['text'] ) && is_string( $rule['text'] ) ? mb_substr( sanitize_text_field( $rule['text'] ), 0, 500 ) : '';

			if ( 'field' === $type && '' === $source ) {
				$type = 'auto';
			}

			$rows[] = array(
				'header' => $header,
				'type'   => $type,
				'source' => $source,
				'text'   => $text,
			);
		}

		return $rows;
	}

	/**
	 * Date/time format settings.
	 *
	 * @return array{date_format: string, time_format: string, form_dates: bool}
	 */
	public static function format_options(): array {
		$saved = get_option( self::FORMAT_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return self::sanitize_format_options(
			array_merge(
				array(
					'date_format' => 'd/m/Y',
					'time_format' => 'H:i',
					'form_dates'  => true,
				),
				$saved
			)
		);
	}

	/**
	 * Sanitizes the date/time format settings (a missing checkbox means "off").
	 *
	 * @param array $raw Raw settings.
	 */
	public static function sanitize_format_options( array $raw ): array {
		$date = $raw['date_format'] ?? '';
		$time = $raw['time_format'] ?? 'H:i';

		return array(
			'date_format' => in_array( $date, self::DATE_FORMATS, true ) ? $date : 'd/m/Y',
			'time_format' => in_array( $time, self::TIME_FORMATS, true ) ? $time : 'H:i',
			'form_dates'  => ! empty( $raw['form_dates'] ),
		);
	}

	/**
	 * Translated labels of the metadata columns (also used as sheet headers).
	 *
	 * @return array<string, string> meta key => label.
	 */
	public static function meta_labels(): array {
		return array(
			'datetime'     => __( 'Submission date', 'sheetsync-cf7' ),
			'page_url'     => __( 'Page URL', 'sheetsync-cf7' ),
			'ip'           => __( 'IP address', 'sheetsync-cf7' ),
			'channel'      => __( 'Lead source', 'sheetsync-cf7' ),
			'utm_source'   => __( 'UTM source', 'sheetsync-cf7' ),
			'utm_medium'   => __( 'UTM medium', 'sheetsync-cf7' ),
			'utm_campaign' => __( 'UTM campaign', 'sheetsync-cf7' ),
			'utm_term'     => __( 'UTM term', 'sheetsync-cf7' ),
			'utm_content'  => __( 'UTM content', 'sheetsync-cf7' ),
			'gclid'        => __( 'Google click ID', 'sheetsync-cf7' ),
			'fbclid'       => __( 'Facebook click ID', 'sheetsync-cf7' ),
			'msclkid'      => __( 'Microsoft click ID', 'sheetsync-cf7' ),
			'landing_page' => __( 'Landing page', 'sheetsync-cf7' ),
			'referrer'     => __( 'Referrer', 'sheetsync-cf7' ),
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Columns & header mapping
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Names of the form fields that can be sent (form order, no duplicates).
	 *
	 * @param WPCF7_ContactForm $form Form.
	 * @return string[]
	 */
	public function get_field_names( WPCF7_ContactForm $form ): array {
		/**
		 * Filters the CF7 tag types that are never sent to Google Sheets.
		 *
		 * @param string[]          $types Base types.
		 * @param WPCF7_ContactForm $form  Form.
		 */
		$excluded = (array) apply_filters( 'ss_cf7_excluded_tag_types', self::EXCLUDED_TYPES, $form );

		$names = array();
		foreach ( $form->scan_form_tags() as $tag ) {
			$name = isset( $tag->name ) ? (string) $tag->name : '';
			if ( '' !== $name && ! in_array( $tag->basetype, $excluded, true ) ) {
				$names[ $name ] = $name;
			}
		}

		return array_values( $names );
	}

	/**
	 * Columns a form produces (fields minus the excluded ones, then the selected metadata).
	 *
	 * @param WPCF7_ContactForm $form     Form.
	 * @param array             $settings Form settings.
	 * @return array<string, string> column key => header label.
	 */
	public function get_columns( WPCF7_ContactForm $form, array $settings ): array {
		$columns = array();
		$exclude = $settings['exclude'] ?? array();

		foreach ( $this->get_field_names( $form ) as $name ) {
			if ( ! in_array( $name, $exclude, true ) ) {
				$columns[ $name ] = $name;
			}
		}

		$labels = self::meta_labels();
		foreach ( self::META_KEYS as $key ) {
			if ( in_array( $key, $settings['meta'] ?? array(), true ) ) {
				$columns[ self::META_PREFIX . $key ] = $labels[ $key ];
			}
		}

		/**
		 * Filters the columns of a form (key => header label).
		 *
		 * @param array             $columns  Columns.
		 * @param WPCF7_ContactForm $form     Form.
		 * @param array             $settings Form settings.
		 */
		return (array) apply_filters( 'ss_cf7_columns', $columns, $form, $settings );
	}

	/**
	 * Value sources offered in the mapping selects.
	 *
	 * @param WPCF7_ContactForm|null $form Form (null = metadata only).
	 * @return array<int, array{value: string, label: string, group: string}>
	 */
	public function get_sources( ?WPCF7_ContactForm $form ): array {
		$sources = array();

		if ( $form ) {
			foreach ( $this->get_field_names( $form ) as $name ) {
				$sources[] = array(
					'value' => 'field:' . $name,
					'label' => $name,
					'group' => 'fields',
				);
			}
		}

		foreach ( self::meta_labels() as $key => $label ) {
			$sources[] = array(
				'value' => 'field:' . self::META_PREFIX . $key,
				'label' => $label,
				'group' => in_array( $key, self::TRACKING_KEYS, true ) ? 'tracking' : 'meta',
			);
		}

		return $sources;
	}

	/**
	 * What the "Automatic" rule would put in a column (label of the matched field/metadata, '' if none).
	 *
	 * @param string   $header      Header text.
	 * @param string[] $field_names Form field names.
	 */
	public function describe_auto( string $header, array $field_names ): string {
		$key = $this->resolve_header( $header );

		if ( str_starts_with( $key, self::META_PREFIX ) ) {
			$labels = self::meta_labels();
			return $labels[ substr( $key, 1 ) ] ?? '';
		}

		foreach ( $field_names as $name ) {
			if ( self::normalize( $name ) === self::normalize( $key ) ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Maps a header cell to a column key: metadata headers are recognised by
	 * key, English label or translated label; anything else is a field name.
	 *
	 * @param string $header Header text from row 1.
	 */
	public function resolve_header( string $header ): string {
		$header = trim( $header );

		if ( null === $this->aliases ) {
			$this->aliases = array();
			$translated    = self::meta_labels();

			foreach ( self::META_KEYS as $key ) {
				$column = self::META_PREFIX . $key;
				foreach ( array( $column, $key, self::META_LABELS_EN[ $key ], $translated[ $key ] ) as $alias ) {
					$this->aliases[ self::normalize( $alias ) ] = $column;
				}
			}

			/**
			 * Filters the header aliases of the metadata columns (normalized text => column key).
			 *
			 * @param array<string, string> $aliases Aliases.
			 */
			$this->aliases = (array) apply_filters( 'ss_cf7_header_aliases', $this->aliases );
		}

		return $this->aliases[ self::normalize( $header ) ] ?? $header;
	}

	/**
	 * Compares the sheet header row with the form columns, honouring the mapping.
	 *
	 * @param string[]              $headers Header row of the sheet.
	 * @param array<string, string> $columns Output of get_columns().
	 * @param array                 $mapping Mapping rows.
	 * @return array{missing: array<string, string>, orphans: string[]}
	 *         missing: columns of the form not covered by any sheet column (key => label);
	 *         orphans: "automatic" sheet columns that match nothing (they stay empty).
	 */
	public function compare_headers( array $headers, array $columns, array $mapping = array() ): array {
		$rules   = self::mapping_index( $mapping );
		$covered = array();
		$orphans = array();

		$column_index = array();
		foreach ( array_keys( $columns ) as $key ) {
			$column_index[ self::normalize( (string) $key ) ] = true;
		}

		foreach ( $headers as $header ) {
			if ( '' === $header ) {
				continue;
			}

			$rule = $rules[ self::normalize( $header ) ] ?? null;
			$type = $rule['type'] ?? 'auto';

			if ( 'field' === $type ) {
				$covered[ self::normalize( $rule['source'] ) ] = true;
			} elseif ( 'auto' === $type ) {
				$key                                 = self::normalize( $this->resolve_header( $header ) );
				$covered[ $key ]                     = true;
				if ( ! isset( $column_index[ $key ] ) ) {
					$orphans[] = $header;
				}
			}
		}

		$missing = array();
		foreach ( $columns as $key => $label ) {
			if ( ! isset( $covered[ self::normalize( (string) $key ) ] ) ) {
				$missing[ $key ] = $label;
			}
		}

		return array(
			'missing' => $missing,
			'orphans' => $orphans,
		);
	}

	/**
	 * Deletes the cached header row of a form.
	 *
	 * @param int $form_id CF7 form ID.
	 */
	public function flush_header_cache( int $form_id ): void {
		delete_transient( SS_CF7_TRANSIENT_HEADERS . $form_id );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Submission
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Collects the submission, stores it locally and queues it for delivery on shutdown.
	 *
	 * @param WPCF7_ContactForm $contact_form Submitted form.
	 */
	public function on_mail_sent( $contact_form ): void {
		try {
			if ( ! $contact_form instanceof WPCF7_ContactForm ) {
				return;
			}

			$form_id  = (int) $contact_form->id();
			$settings = self::get_form_settings( $form_id );

			if ( ! $settings['enabled'] || '' === $settings['spreadsheet_id'] || ! $this->auth->has_credentials() ) {
				return;
			}

			$submission = WPCF7_Submission::get_instance();
			if ( ! $submission instanceof WPCF7_Submission ) {
				return;
			}

			$values = $this->collect_values( $submission, $contact_form, $settings );

			if ( empty( $this->queue ) ) {
				// After wp_ob_end_flush_all (priority 1): the output is complete when we run.
				add_action( 'shutdown', array( $this, 'process_queue' ), 100 );
			}

			$this->queue[] = array(
				'form_id'  => $form_id,
				'settings' => $settings,
				'columns'  => $this->get_columns( $contact_form, $settings ),
				'values'   => $values,
				'entry_id' => $this->entries->insert( $form_id, $settings, $values ),
				'source'   => 'submission',
				'attempts' => 0,
			);
		} catch ( \Throwable $e ) {
			// Never break the CF7 response.
			ss_cf7_log( 'Unexpected error while collecting the submission: ' . $e->getMessage() );
		}
	}

	/**
	 * Delivers the queued submissions (runs on shutdown).
	 */
	public function process_queue(): void {
		if ( empty( $this->queue ) ) {
			return;
		}

		/**
		 * Whether to send the HTTP response to the visitor before talking to Google.
		 *
		 * @param bool $finish Default true.
		 */
		if ( apply_filters( 'ss_cf7_finish_request_early', true ) ) {
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				fastcgi_finish_request();
			} elseif ( function_exists( 'litespeed_finish_request' ) ) {
				litespeed_finish_request();
			}
		}

		ignore_user_abort( true );

		// Short timeouts when the response could not be flushed early.
		$this->auth->set_timeout( 10 );

		$queue       = $this->queue;
		$this->queue = array();

		foreach ( $queue as $item ) {
			try {
				$this->deliver( $item );
			} catch ( \Throwable $e ) {
				$this->fail( new WP_Error( 'ss_cf7_unexpected', $e->getMessage() ), $item, array( 'form_id' => $item['form_id'] ) );
			}
		}
	}

	/**
	 * Hourly cron: sends again the failed entries (and the ones stuck in
	 * "pending") whose backoff has expired. See SS_CF7_Entries::next_retry_at().
	 *
	 * @return int Number of entries retried.
	 */
	public function run_auto_retry(): int {
		$options = SS_CF7_Entries::options();

		if ( ! $options['store_entries'] || ! $options['auto_retry'] || ! $this->auth->has_credentials() ) {
			return 0;
		}

		/**
		 * Maximum entries sent again per cron run.
		 *
		 * @param int $limit Default 20.
		 */
		$limit = max( 1, (int) apply_filters( 'ss_cf7_retry_batch_size', 20 ) );
		$start = microtime( true );
		$done  = 0;

		$this->auth->set_timeout( 15 );

		foreach ( $this->entries->retry_candidates( $options['max_attempts'] ) as $entry ) {
			if ( $done >= $limit || microtime( true ) - $start > 60 ) {
				break;
			}

			$due = SS_CF7_Entries::next_retry_at( $entry, $options );

			// 5 minutes of tolerance: the hourly event never runs exactly on time.
			if ( null === $due || $due > time() + 5 * MINUTE_IN_SECONDS ) {
				continue;
			}

			try {
				$this->retry_entry( $entry['id'], 'cron' );
			} catch ( \Throwable $e ) {
				ss_cf7_log( 'Unexpected error during the automatic retry: ' . $e->getMessage(), array( 'entry_id' => $entry['id'] ) );
			}
			++$done;
		}

		return $done;
	}

	/**
	 * Sends a stored entry again, with the current form settings.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $source   "manual" (dashboard button) or "cron" (automatic retry).
	 * @return array|WP_Error Delivered row on success.
	 */
	public function retry_entry( int $entry_id, string $source = 'manual' ): array|WP_Error {
		$entry = $this->entries->get( $entry_id );
		if ( null === $entry ) {
			return new WP_Error( 'ss_cf7_entry_not_found', __( 'Entry not found.', 'sheetsync-cf7' ) );
		}

		$settings = self::get_form_settings( $entry['form_id'] );

		// Form reconfigured without a spreadsheet: fall back to where the entry was meant to go.
		if ( '' === $settings['spreadsheet_id'] ) {
			$settings['spreadsheet_id'] = SS_CF7_Google_Auth::sanitize_spreadsheet_id( (string) $entry['spreadsheet_id'] );
			$settings['sheet_name']     = SS_CF7_Google_Auth::sanitize_sheet_name( (string) $entry['sheet_name'] );
		}

		if ( '' === $settings['spreadsheet_id'] ) {
			return new WP_Error( 'ss_cf7_no_spreadsheet', __( 'This form has no spreadsheet configured.', 'sheetsync-cf7' ) );
		}

		$form = function_exists( 'wpcf7_contact_form' ) ? wpcf7_contact_form( $entry['form_id'] ) : null;

		return $this->deliver(
			array(
				'form_id'  => $entry['form_id'],
				'settings' => $settings,
				'columns'  => $form instanceof WPCF7_ContactForm ? $this->get_columns( $form, $settings ) : array(),
				'values'   => $entry['fields'],
				'entry_id' => $entry_id,
				'source'   => $source,
				'attempts' => $entry['attempts'],
			)
		);
	}

	/**
	 * Reads the header row, maps the values onto it and appends the row.
	 *
	 * @param array $item Queued submission.
	 * @return array|WP_Error Delivered row (list of [header, cell]) or error.
	 */
	private function deliver( array $item ): array|WP_Error {
		$form_id  = (int) $item['form_id'];
		$settings = $item['settings'];
		$values   = $item['values'];
		$context  = array( 'form_id' => $form_id );

		$headers = $this->get_headers( $form_id, $settings );
		if ( is_wp_error( $headers ) ) {
			return $this->fail( $headers, $item, $context );
		}

		/*
		 * Brand-new (empty) sheet: write the header row automatically.
		 * Otherwise the header row is only changed by "Sync headers", so columns
		 * the user deleted are never re-added behind their back.
		 */
		$missing = $this->compare_headers( $headers, $item['columns'], $settings['mapping'] )['missing'];

		/**
		 * Whether to add missing columns to the header row on submission even when
		 * the sheet already has headers (default: only when row 1 is empty).
		 *
		 * @param bool $auto    Default false.
		 * @param int  $form_id Form ID.
		 */
		$auto_add = empty( array_filter( $headers, 'strlen' ) ) || apply_filters( 'ss_cf7_auto_add_headers', false, $form_id );

		if ( ! empty( $missing ) && $auto_add ) {
			$new_headers = array_merge( $headers, array_values( $missing ) );
			$written     = $this->auth->update_values(
				$settings['spreadsheet_id'],
				SS_CF7_Google_Auth::a1( $settings['sheet_name'], 'A1' ),
				array( $new_headers ),
				'RAW'
			);

			if ( is_wp_error( $written ) ) {
				ss_cf7_log( $written, $context + array( 'step' => 'auto_add_headers' ) );
			} else {
				$headers = $new_headers;
				$this->flush_header_cache( $form_id );
			}
		}

		$row = $this->build_row( $headers, $values, $settings );

		if ( '' === implode( '', $row ) ) {
			return $this->fail( new WP_Error( 'ss_cf7_empty_row', __( 'Nothing to send: no column of the sheet matches the form fields. Use "Sync headers" or the column mapping.', 'sheetsync-cf7' ) ), $item, $context );
		}

		$result = $this->auth->append_values( $settings['spreadsheet_id'], $settings['sheet_name'], array( $row ), 'USER_ENTERED' );

		if ( is_wp_error( $result ) ) {
			$this->flush_header_cache( $form_id );
			return $this->fail( $result, $item, $context );
		}

		$pairs = array();
		foreach ( $headers as $i => $header ) {
			$pairs[] = array( (string) $header, (string) ( $row[ $i ] ?? '' ) );
		}

		if ( ! empty( $item['entry_id'] ) ) {
			$this->entries->mark_sent( (int) $item['entry_id'], $pairs );
		}

		/**
		 * Fires after a submission has been appended to Google Sheets.
		 *
		 * @param int   $form_id  Form ID.
		 * @param array $row      Row sent to Google.
		 * @param array $response API response.
		 */
		do_action( 'ss_cf7_row_appended', $form_id, $row, $result );

		return $pairs;
	}

	/**
	 * Logs a delivery error (WP_DEBUG only), updates the archive and notifies extensions.
	 *
	 * @param WP_Error $error   Error.
	 * @param array    $item    Queued submission.
	 * @param array    $context Log context (no personal data).
	 */
	private function fail( WP_Error $error, array $item, array $context ): WP_Error {
		ss_cf7_log( $error, $context );

		if ( ! empty( $item['entry_id'] ) ) {
			$this->entries->mark_failed( (int) $item['entry_id'], $error->get_error_message() );
		}

		/**
		 * Fires when a submission could not be sent to Google Sheets
		 * (e.g. to send an alert).
		 *
		 * @param WP_Error $error   Error.
		 * @param int      $form_id Form ID.
		 * @param array    $values  Submitted values (column key => value).
		 */
		do_action( 'ss_cf7_append_failed', $error, $item['form_id'], $item['values'] );

		/**
		 * Fires when a delivery attempt fails (used by the email alerts).
		 *
		 * @param WP_Error $error   Error.
		 * @param array    $context {form_id, entry_id, source: submission|cron|manual, attempts: attempts made so far}.
		 */
		do_action(
			'ss_cf7_delivery_failed',
			$error,
			array(
				'form_id'  => (int) $item['form_id'],
				'entry_id' => (int) ( $item['entry_id'] ?? 0 ),
				'source'   => (string) ( $item['source'] ?? 'submission' ),
				'attempts' => (int) ( $item['attempts'] ?? 0 ) + 1,
			)
		);

		return $error;
	}

	/**
	 * Header row of the target tab, optionally cached.
	 *
	 * Caching is OFF by default: reading row 1 on every submission guarantees
	 * correct mapping even right after the user reorders columns. High-traffic
	 * sites can enable it with the `ss_cf7_header_cache_ttl` filter.
	 *
	 * @param int   $form_id  Form ID.
	 * @param array $settings Form settings.
	 * @return string[]|WP_Error
	 */
	private function get_headers( int $form_id, array $settings ): array|WP_Error {
		$ttl       = (int) apply_filters( 'ss_cf7_header_cache_ttl', 0, $form_id );
		$signature = md5( $settings['spreadsheet_id'] . '|' . $settings['sheet_name'] );
		$cache_key = SS_CF7_TRANSIENT_HEADERS . $form_id;

		if ( $ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ( $cached['sig'] ?? '' ) === $signature && isset( $cached['headers'] ) && is_array( $cached['headers'] ) ) {
				return $cached['headers'];
			}
		}

		$headers = $this->auth->get_header_row( $settings['spreadsheet_id'], $settings['sheet_name'] );

		if ( ! is_wp_error( $headers ) && $ttl > 0 ) {
			set_transient(
				$cache_key,
				array(
					'sig'     => $signature,
					'headers' => $headers,
				),
				$ttl
			);
		}

		return $headers;
	}

	/**
	 * Extracts and normalizes the values of a submission, including the
	 * metadata and the custom texts of the mapping (CF7 mail-tags resolved).
	 *
	 * @param WPCF7_Submission  $submission Submission.
	 * @param WPCF7_ContactForm $form       Form.
	 * @param array             $settings   Form settings.
	 * @return array<string, string> value key => value.
	 */
	private function collect_values( WPCF7_Submission $submission, WPCF7_ContactForm $form, array $settings ): array {
		$values = array();
		$raw    = array();
		$format = self::format_options();

		foreach ( (array) $submission->get_posted_data() as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name || str_starts_with( $name, '_' ) ) {
				continue;
			}
			$values[ $name ] = self::stringify( $value );
		}

		// Fields with pipes: CF7 gives us the value after "|"; the label is what the browser sent.
		if ( 'value' !== ( $settings['pipes'] ?? 'value' ) ) {
			foreach ( $form->scan_form_tags() as $tag ) {
				$name = isset( $tag->name ) ? (string) $tag->name : '';
				if ( '' !== $name && isset( $values[ $name ] ) ) {
					$piped = $this->apply_pipe_mode( $tag, $settings['pipes'] );
					if ( null !== $piped ) {
						$values[ $name ] = $piped;
					}
				}
			}
		}

		// Date fields: CF7 posts them as Y-m-d; rewrite them in the format chosen for the sheet.
		if ( $format['form_dates'] ) {
			foreach ( $form->scan_form_tags() as $tag ) {
				$name = isset( $tag->name ) ? (string) $tag->name : '';
				if ( 'date' !== ( $tag->basetype ?? '' ) || ! isset( $values[ $name ] ) ) {
					continue;
				}
				$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $values[ $name ] );
				if ( $date && $date->format( 'Y-m-d' ) === $values[ $name ] ) {
					$values[ $name ] = $date->format( $format['date_format'] );
					$raw[]           = $name;
				}
			}
		}

		// Metadata: the enabled extra columns plus any metadata used in the mapping.
		$needed = $settings['meta'];
		foreach ( $settings['mapping'] as $rule ) {
			if ( 'field' === $rule['type'] && str_starts_with( $rule['source'], self::META_PREFIX ) ) {
				$needed[] = substr( $rule['source'], 1 );
			}
		}

		if ( in_array( 'datetime', $needed, true ) ) {
			$timestamp = (int) $submission->get_meta( 'timestamp' );
			/**
			 * Filters the date format of the "Submission date" column (site timezone).
			 * Default: the format chosen in the settings.
			 *
			 * @param string $format PHP date format.
			 */
			$datetime_format                          = (string) apply_filters( 'ss_cf7_datetime_format', trim( $format['date_format'] . ' ' . $format['time_format'] ) );
			$values[ self::META_PREFIX . 'datetime' ] = wp_date( $datetime_format, $timestamp > 0 ? $timestamp : time() );
		}

		if ( in_array( 'page_url', $needed, true ) ) {
			$values[ self::META_PREFIX . 'page_url' ] = esc_url_raw( (string) $submission->get_meta( 'url' ) );
		}

		if ( in_array( 'ip', $needed, true ) ) {
			$ip                                 = (string) $submission->get_meta( 'remote_ip' );
			$values[ self::META_PREFIX . 'ip' ] = false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}

		// Lead source (UTM, click IDs, channel) posted by the frontend tracking script.
		$tracking = array_intersect( self::TRACKING_KEYS, $needed );
		if ( ! empty( $tracking ) ) {
			$attribution = SS_CF7_Attribution::current();
			foreach ( $tracking as $key ) {
				$values[ self::META_PREFIX . $key ] = $attribution[ $key ] ?? '';
			}
		}

		if ( ! empty( $raw ) ) {
			$values[ self::RAW_KEYS ] = implode( ',', $raw );
		}

		// Custom texts: resolved now, while CF7 mail-tags like [your-name] or [_post_title] are available.
		foreach ( $settings['mapping'] as $rule ) {
			if ( 'text' === $rule['type'] ) {
				$text = $rule['text'];
				if ( str_contains( $text, '[' ) && function_exists( 'wpcf7_mail_replace_tags' ) ) {
					$text = (string) wpcf7_mail_replace_tags(
						$text,
						array(
							'html'          => false,
							'exclude_blank' => false,
						)
					);
				}
				$values[ self::TEXT_PREFIX . self::normalize( $rule['header'] ) ] = trim( $text );
			}
		}

		/**
		 * Filters the values sent to Google Sheets (value key => string).
		 *
		 * @param array             $values     Values.
		 * @param WPCF7_ContactForm $form       Form.
		 * @param WPCF7_Submission  $submission Submission.
		 */
		return (array) apply_filters( 'ss_cf7_row_values', $values, $form, $submission );
	}

	/**
	 * Value of a field with pipes written as label or "Label | Value".
	 *
	 * The label is read from the raw request (what the browser sent: CF7 renders the
	 * part before "|" as the option value) and accepted only when it is one of the
	 * labels of the tag; anything else (e.g. the "free_text" option) is kept as sent.
	 * Reading the label instead of looking it up from the value avoids ambiguity when
	 * several labels share the same value.
	 *
	 * @param object $tag  CF7 form tag.
	 * @param string $mode "label" or "both".
	 * @return string|null New cell value, or null when the tag has no pipes / nothing was sent.
	 */
	private function apply_pipe_mode( $tag, string $mode ): ?string {
		$pipes = $tag->pipes ?? null;

		if ( ( defined( 'WPCF7_USE_PIPE' ) && ! WPCF7_USE_PIPE ) || ! is_object( $pipes ) || ! method_exists( $pipes, 'to_array' ) || ( method_exists( $pipes, 'zero' ) && $pipes->zero() ) ) {
			return null;
		}

		$name = (string) $tag->name;
		$sent = $_POST[ $name ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- public CF7 submission; every item is matched against the tag labels or sanitized below.
		if ( null === $sent ) {
			return null;
		}

		$pairs = array();
		foreach ( (array) $pipes->to_array() as $pair ) {
			if ( is_array( $pair ) && 2 === count( $pair ) ) {
				$key           = html_entity_decode( (string) $pair[0], ENT_QUOTES, 'UTF-8' );
				$pairs[ $key ] = array( (string) $pair[0], (string) $pair[1] );
			}
		}

		$free_text = '';
		if ( method_exists( $tag, 'has_option' ) && $tag->has_option( 'free_text' ) && isset( $_POST[ $name . '_free_text' ] ) && is_string( $_POST[ $name . '_free_text' ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$free_text = sanitize_text_field( wp_unslash( $_POST[ $name . '_free_text' ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$last_label = array_key_last( $pairs );

		$items = array();
		foreach ( (array) wp_unslash( $sent ) as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$label = html_entity_decode( (string) $item, ENT_QUOTES, 'UTF-8' );

			if ( ! isset( $pairs[ $label ] ) ) {
				$items[] = sanitize_text_field( $label );
				continue;
			}

			[ $before, $after ] = $pairs[ $label ];
			$before             = html_entity_decode( $before, ENT_QUOTES, 'UTF-8' );

			if ( 'label' === $mode || $before === $after ) {
				$text = $before;
			} else {
				$text = $before . ' | ' . $after;
			}

			// "free_text" option: the text typed by the visitor follows the last item, as in CF7.
			if ( '' !== $free_text && $label === $last_label ) {
				$text .= ' ' . $free_text;
			}

			$items[] = $text;
		}

		return self::stringify( $items );
	}

	/**
	 * Builds the row following the header row and the column mapping.
	 *
	 * @param string[] $headers  Header row.
	 * @param array    $values   value key => value.
	 * @param array    $settings Form settings (mapping, exclude).
	 * @return string[]
	 */
	private function build_row( array $headers, array $values, array $settings ): array {
		$rules   = self::mapping_index( $settings['mapping'] ?? array() );
		$exclude = array_map( array( self::class, 'normalize' ), $settings['exclude'] ?? array() );

		// Case-insensitive lookup for field names.
		$lookup = array();
		foreach ( $values as $key => $value ) {
			$lookup[ self::normalize( (string) $key ) ] = (string) $value;
		}

		// Values sent as typed: our own submission date and the formatted date fields.
		$raw = array( self::normalize( self::META_PREFIX . 'datetime' ) );
		foreach ( explode( ',', (string) ( $values[ self::RAW_KEYS ] ?? '' ) ) as $key ) {
			if ( '' !== $key ) {
				$raw[] = self::normalize( $key );
			}
		}

		$row = array();
		foreach ( $headers as $header ) {
			if ( '' === $header ) {
				$row[] = '';
				continue;
			}

			$norm = self::normalize( $header );
			$rule = $rules[ $norm ] ?? array(
				'type'   => 'auto',
				'source' => '',
				'text'   => '',
			);

			switch ( $rule['type'] ) {
				case 'empty':
					$row[] = '';
					break;

				case 'text':
					// Stored text (mail-tags resolved at submission time); plain texts also work on retries.
					$text  = $values[ self::TEXT_PREFIX . $norm ] ?? ( str_contains( $rule['text'], '[' ) ? '' : $rule['text'] );
					$row[] = self::protect( (string) $text );
					break;

				case 'field':
					$row[] = $this->cell( $rule['source'], $lookup, $raw );
					break;

				default: // auto.
					$key   = $this->resolve_header( $header );
					$row[] = in_array( self::normalize( $key ), $exclude, true ) ? '' : $this->cell( $key, $lookup, $raw );
			}
		}

		return $row;
	}

	/**
	 * One cell value for a value key.
	 *
	 * @param string $key    Value key (field name or @meta).
	 * @param array  $lookup normalized key => value.
	 * @param array  $raw    Normalized keys whose value is sent as typed.
	 */
	private function cell( string $key, array $lookup, array $raw = array() ): string {
		$norm  = self::normalize( $key );
		$value = $lookup[ $norm ] ?? '';

		// Dates formatted by us must stay real dates for Sheets (no leading-zero protection on "05/03/2026").
		return in_array( $norm, $raw, true ) ? $value : self::protect( $value );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Mapping rows indexed by normalized header.
	 *
	 * @param array $mapping Mapping rows.
	 */
	private static function mapping_index( array $mapping ): array {
		$index = array();
		foreach ( $mapping as $rule ) {
			if ( isset( $rule['header'], $rule['type'] ) ) {
				$index[ self::normalize( (string) $rule['header'] ) ] = $rule + array(
					'source' => '',
					'text'   => '',
				);
			}
		}
		return $index;
	}

	/**
	 * Keeps only the characters allowed in CF7 field names (plus "@" for metadata).
	 *
	 * @param string $name Raw name.
	 */
	private static function sanitize_field_name( string $name ): string {
		return mb_substr( (string) preg_replace( '/[^A-Za-z0-9:._@-]/', '', $name ), 0, 100 );
	}

	/**
	 * Converts a posted value to a string; arrays (checkboxes, multi-selects) are joined with ", ".
	 *
	 * @param mixed $value Posted value.
	 */
	private static function stringify( mixed $value ): string {
		if ( is_array( $value ) ) {
			$parts = array();
			array_walk_recursive(
				$value,
				static function ( $item ) use ( &$parts ) {
					if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
						$parts[] = trim( (string) $item );
					}
				}
			);
			return implode( ', ', $parts );
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Protects a value sent with USER_ENTERED:
	 * - neutralizes formula injection (=, +, -, @ at the start: a visitor could
	 *   otherwise run =IMPORTXML(...) or =HYPERLINK(...) inside your sheet);
	 * - keeps leading zeros (ZIP codes like 00184, phone numbers).
	 * A leading apostrophe forces text in Google Sheets and is not displayed.
	 *
	 * @param string $value Value.
	 */
	public static function protect( string $value ): string {
		if ( mb_strlen( $value ) > self::MAX_CELL_LENGTH ) {
			$value = mb_substr( $value, 0, self::MAX_CELL_LENGTH - 1 );
		}

		$escaped = ( '' !== $value && ( 1 === preg_match( '/^[=+\-@\t\r\n]/', $value ) || 1 === preg_match( '/^0\d/', $value ) ) )
			? "'" . $value
			: $value;

		/**
		 * Filters a cell value before it is sent to Google Sheets.
		 *
		 * @param string $escaped Protected value.
		 * @param string $value   Original value.
		 */
		return (string) apply_filters( 'ss_cf7_cell_value', $escaped, $value );
	}

	/**
	 * Normalizes a header for comparisons (trim, lowercase, single spaces).
	 *
	 * @param string $text Text.
	 */
	public static function normalize( string $text ): string {
		return (string) preg_replace( '/\s+/u', ' ', mb_strtolower( trim( $text ) ) );
	}
}
