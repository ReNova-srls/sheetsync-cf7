<?php
/**
 * "Dashboard" tab of Contact → SheetSync for CF7: daily chart, entries list,
 * entry inspector (AJAX), resend, delete and CSV export.
 *
 * @package SheetSync_CF7
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SS_CF7_Dashboard
 */
final class SS_CF7_Dashboard {

	/** Entries per page. */
	private const PER_PAGE = 20;

	/** admin-post action (and nonce action) of the CSV export. */
	private const EXPORT_ACTION = 'ss_cf7_export';

	/** Selectable periods (days). */
	private const PERIODS = array( 7, 30, 90, 365 );

	/**
	 * Local archive.
	 *
	 * @var SS_CF7_Entries
	 */
	private SS_CF7_Entries $entries;

	/**
	 * Submission handler.
	 *
	 * @var SS_CF7_Handler
	 */
	private SS_CF7_Handler $handler;

	/**
	 * Form titles (ID => title), loaded lazily.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $form_titles = null;

	/**
	 * Registers hooks only.
	 *
	 * @param SS_CF7_Entries $entries Local archive.
	 * @param SS_CF7_Handler $handler Submission handler.
	 */
	public function __construct( SS_CF7_Entries $entries, SS_CF7_Handler $handler ) {
		$this->entries = $entries;
		$this->handler = $handler;

		add_action( 'wp_ajax_ss_cf7_get_entry', array( $this, 'ajax_get_entry' ) );
		add_action( 'wp_ajax_ss_cf7_retry_entry', array( $this, 'ajax_retry_entry' ) );
		add_action( 'wp_ajax_ss_cf7_delete_entry', array( $this, 'ajax_delete_entry' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'export_csv' ) );
	}

	/**
	 * Loads the dashboard script.
	 */
	public function enqueue(): void {
		wp_enqueue_script( 'ss-cf7-dashboard', SS_CF7_URL . 'assets/dashboard.js', array(), SS_CF7_VERSION, true );

		wp_localize_script(
			'ss-cf7-dashboard',
			'ssCf7Dash',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( SS_CF7_Admin_Settings::AJAX_NONCE ),
				'i18n'    => array(
					'sent'          => __( 'Sent', 'sheetsync-cf7' ),
					'failed'        => __( 'Failed', 'sheetsync-cf7' ),
					'pending'       => __( 'Pending', 'sheetsync-cf7' ),
					'noData'        => __( 'No submissions in this period.', 'sheetsync-cf7' ),
					'loading'       => __( 'Loading…', 'sheetsync-cf7' ),
					'requestFailed' => __( 'The request failed. Check your connection and try again.', 'sheetsync-cf7' ),
					/* translators: %d: entry ID. */
					'entryTitle'    => __( 'Entry #%d', 'sheetsync-cf7' ),
					'date'          => __( 'Date', 'sheetsync-cf7' ),
					'form'          => __( 'Form', 'sheetsync-cf7' ),
					'status'        => __( 'Status', 'sheetsync-cf7' ),
					'attempts'      => __( 'Attempts', 'sheetsync-cf7' ),
					'autoRetry'     => __( 'Automatic retry', 'sheetsync-cf7' ),
					'sheet'         => __( 'Sheet', 'sheetsync-cf7' ),
					'sentAt'        => __( 'Sent to Google Sheets', 'sheetsync-cf7' ),
					'error'         => __( 'Last error', 'sheetsync-cf7' ),
					'formData'      => __( 'Submitted data', 'sheetsync-cf7' ),
					'sheetRow'      => __( 'Row written in the sheet', 'sheetsync-cf7' ),
					'field'         => __( 'Field', 'sheetsync-cf7' ),
					'value'         => __( 'Value', 'sheetsync-cf7' ),
					'column'        => __( 'Column', 'sheetsync-cf7' ),
					'retry'         => __( 'Send to Google Sheets again', 'sheetsync-cf7' ),
					'retrying'      => __( 'Sending…', 'sheetsync-cf7' ),
					'delete'        => __( 'Delete entry', 'sheetsync-cf7' ),
					'confirmDelete' => __( 'Delete this entry from WordPress? The row already written in Google Sheets is not touched.', 'sheetsync-cf7' ),
					'close'         => __( 'Close', 'sheetsync-cf7' ),
					'openSheet'     => __( 'Open spreadsheet', 'sheetsync-cf7' ),
					'empty'         => __( '(empty)', 'sheetsync-cf7' ),
				),
			)
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Rendering
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Filters from the query string.
	 *
	 * @return array{form_id: int, status: string, days: int, search: string, paged: int}
	 */
	private function filters( array $source ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$days   = isset( $source['days'] ) ? absint( $source['days'] ) : 30;
		$status = isset( $source['status'] ) ? sanitize_key( wp_unslash( $source['status'] ) ) : '';
		$search = isset( $source['s'] ) && is_string( $source['s'] ) ? sanitize_text_field( wp_unslash( $source['s'] ) ) : '';
		// phpcs:enable

		return array(
			'form_id' => isset( $source['form_id'] ) ? absint( $source['form_id'] ) : 0,
			'status'  => in_array( $status, array( SS_CF7_Entries::STATUS_SENT, SS_CF7_Entries::STATUS_FAILED, SS_CF7_Entries::STATUS_PENDING ), true ) ? $status : '',
			'days'    => in_array( $days, self::PERIODS, true ) ? $days : 30,
			'search'  => mb_substr( $search, 0, 100 ),
			'paged'   => isset( $source['paged'] ) ? max( 1, absint( $source['paged'] ) ) : 1,
		);
	}

	/**
	 * Renders the tab.
	 */
	public function render(): void {
		$filters = $this->filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$options = SS_CF7_Entries::options();

		// KPIs and chart ignore the status filter, so both series stay visible.
		$chart_filters = array(
			'form_id' => $filters['form_id'],
			'days'    => $filters['days'],
			'search'  => $filters['search'],
		);
		$daily         = $this->entries->daily_counts( $chart_filters );
		$totals        = array(
			SS_CF7_Entries::STATUS_SENT    => 0,
			SS_CF7_Entries::STATUS_FAILED  => 0,
			SS_CF7_Entries::STATUS_PENDING => 0,
		);
		$series        = array();

		foreach ( $daily as $day => $counts ) {
			foreach ( $totals as $status => $value ) {
				$totals[ $status ] = $value + $counts[ $status ];
			}
			$timestamp = strtotime( $day . ' 12:00:00 UTC' );
			$series[]  = array(
				'label'   => date_i18n( 'j M', $timestamp, true ),
				'full'    => date_i18n( get_option( 'date_format' ), $timestamp, true ),
				'sent'    => $counts[ SS_CF7_Entries::STATUS_SENT ],
				'failed'  => $counts[ SS_CF7_Entries::STATUS_FAILED ],
				'pending' => $counts[ SS_CF7_Entries::STATUS_PENDING ],
			);
		}

		$list = $this->entries->query( $filters, $filters['paged'], self::PER_PAGE );
		?>
		<?php if ( ! $options['store_entries'] ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: Settings tab URL. */
							__( 'The entries archive is disabled: new submissions are not stored in WordPress. <a href="%s">Enable it in the Settings tab</a>.', 'sheetsync-cf7' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_url( ss_cf7_admin_url( 'settings' ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php $this->render_filters( $filters ); ?>

		<div class="ss-cf7-kpis">
			<?php
			$this->render_kpi( __( 'Submissions', 'sheetsync-cf7' ), array_sum( $totals ), '' );
			$this->render_kpi( __( 'Sent to Google Sheets', 'sheetsync-cf7' ), $totals[ SS_CF7_Entries::STATUS_SENT ], 'sent' );
			$this->render_kpi( __( 'Failed', 'sheetsync-cf7' ), $totals[ SS_CF7_Entries::STATUS_FAILED ], 'failed' );
			$this->render_kpi( __( 'Pending', 'sheetsync-cf7' ), $totals[ SS_CF7_Entries::STATUS_PENDING ], 'pending' );
			?>
		</div>

		<div class="ss-cf7-card ss-cf7-chart-card">
			<div class="ss-cf7-chart-head">
				<h2>
					<?php
					/* translators: %d: number of days. */
					echo esc_html( sprintf( _n( 'Submissions per day, last %d day', 'Submissions per day, last %d days', $filters['days'], 'sheetsync-cf7' ), $filters['days'] ) );
					?>
				</h2>
				<ul class="ss-cf7-legend">
					<li><span class="ss-cf7-swatch is-sent" aria-hidden="true"></span><?php esc_html_e( 'Sent', 'sheetsync-cf7' ); ?></li>
					<li><span class="ss-cf7-swatch is-failed" aria-hidden="true"></span><?php esc_html_e( 'Failed', 'sheetsync-cf7' ); ?></li>
					<li><span class="ss-cf7-swatch is-pending" aria-hidden="true"></span><?php esc_html_e( 'Pending', 'sheetsync-cf7' ); ?></li>
				</ul>
			</div>

			<div class="ss-cf7-chart" id="ss-cf7-chart" data-series="<?php echo esc_attr( (string) wp_json_encode( $series ) ); ?>"></div>

			<details class="ss-cf7-chart-table">
				<summary><?php esc_html_e( 'Show data as a table', 'sheetsync-cf7' ); ?></summary>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Day', 'sheetsync-cf7' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Sent', 'sheetsync-cf7' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Failed', 'sheetsync-cf7' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Pending', 'sheetsync-cf7' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_reverse( $series ) as $point ) : ?>
							<?php if ( $point['sent'] + $point['failed'] + $point['pending'] > 0 ) : ?>
								<tr>
									<td><?php echo esc_html( $point['full'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $point['sent'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $point['failed'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $point['pending'] ) ); ?></td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		</div>

		<div class="ss-cf7-card">
			<h2>
				<?php
				/* translators: %s: number of entries. */
				echo esc_html( sprintf( _n( '%s entry', '%s entries', $list['total'], 'sheetsync-cf7' ), number_format_i18n( $list['total'] ) ) );
				?>
			</h2>

			<?php if ( empty( $list['items'] ) ) : ?>
				<p><?php esc_html_e( 'No entries match these filters.', 'sheetsync-cf7' ); ?></p>
			<?php else : ?>
				<table class="widefat striped ss-cf7-entries-table">
					<thead>
						<tr>
							<th scope="col" class="column-date"><?php esc_html_e( 'Date', 'sheetsync-cf7' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Form', 'sheetsync-cf7' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Preview', 'sheetsync-cf7' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'sheetsync-cf7' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'sheetsync-cf7' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $list['items'] as $entry ) : ?>
							<tr>
								<td class="column-date"><?php echo esc_html( $this->format_date( $entry['created_at'] ) ); ?></td>
								<td><?php echo esc_html( $this->form_title( $entry['form_id'] ) ); ?></td>
								<td class="ss-cf7-preview"><?php echo esc_html( $this->preview( $entry['fields'] ) ); ?></td>
								<td><?php $this->render_status( $entry['status'] ); ?></td>
								<td class="ss-cf7-row-actions">
									<button type="button" class="button button-small ss-cf7-entry-view" data-entry-id="<?php echo esc_attr( (string) $entry['id'] ); ?>">
										<?php esc_html_e( 'Details', 'sheetsync-cf7' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php
				$pages = (int) ceil( $list['total'] / self::PER_PAGE );
				if ( $pages > 1 ) {
					echo '<div class="tablenav"><div class="tablenav-pages">';
					echo wp_kses_post(
						(string) paginate_links(
							array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'current'   => $filters['paged'],
								'total'     => $pages,
								'prev_text' => '‹',
								'next_text' => '›',
							)
						)
					);
					echo '</div></div>';
				}
				?>
			<?php endif; ?>
		</div>

		<dialog class="ss-cf7-dialog" id="ss-cf7-entry-dialog" aria-labelledby="ss-cf7-dialog-title">
			<div class="ss-cf7-dialog-inner">
				<header class="ss-cf7-dialog-head">
					<h2 id="ss-cf7-dialog-title"></h2>
					<button type="button" class="button-link ss-cf7-dialog-close" aria-label="<?php esc_attr_e( 'Close', 'sheetsync-cf7' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</header>
				<div class="ss-cf7-dialog-body"></div>
				<div class="ss-cf7-result" id="ss-cf7-dialog-result" aria-live="polite" hidden></div>
				<footer class="ss-cf7-dialog-foot"></footer>
			</div>
		</dialog>
		<?php
	}

	/**
	 * Filter bar (GET form) + CSV export link.
	 *
	 * @param array $filters Current filters.
	 */
	private function render_filters( array $filters ): void {
		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => self::EXPORT_ACTION,
					'form_id' => $filters['form_id'],
					'status'  => $filters['status'],
					'days'    => $filters['days'],
					's'       => $filters['search'],
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ss-cf7-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( SS_CF7_PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="dashboard">

			<label class="screen-reader-text" for="ss-cf7-filter-form"><?php esc_html_e( 'Form', 'sheetsync-cf7' ); ?></label>
			<select name="form_id" id="ss-cf7-filter-form">
				<option value="0"><?php esc_html_e( 'All forms', 'sheetsync-cf7' ); ?></option>
				<?php foreach ( $this->form_titles() as $id => $title ) : ?>
					<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $filters['form_id'], $id ); ?>><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="ss-cf7-filter-days"><?php esc_html_e( 'Period', 'sheetsync-cf7' ); ?></label>
			<select name="days" id="ss-cf7-filter-days">
				<?php foreach ( self::PERIODS as $days ) : ?>
					<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $filters['days'], $days ); ?>>
						<?php
						/* translators: %d: number of days. */
						echo esc_html( sprintf( _n( 'Last %d day', 'Last %d days', $days, 'sheetsync-cf7' ), $days ) );
						?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="ss-cf7-filter-status"><?php esc_html_e( 'Status', 'sheetsync-cf7' ); ?></label>
			<select name="status" id="ss-cf7-filter-status">
				<option value=""><?php esc_html_e( 'All statuses', 'sheetsync-cf7' ); ?></option>
				<option value="sent" <?php selected( $filters['status'], 'sent' ); ?>><?php esc_html_e( 'Sent', 'sheetsync-cf7' ); ?></option>
				<option value="failed" <?php selected( $filters['status'], 'failed' ); ?>><?php esc_html_e( 'Failed', 'sheetsync-cf7' ); ?></option>
				<option value="pending" <?php selected( $filters['status'], 'pending' ); ?>><?php esc_html_e( 'Pending', 'sheetsync-cf7' ); ?></option>
			</select>

			<label class="screen-reader-text" for="ss-cf7-filter-search"><?php esc_html_e( 'Search entries', 'sheetsync-cf7' ); ?></label>
			<input type="search" name="s" id="ss-cf7-filter-search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search name, email, phone…', 'sheetsync-cf7' ); ?>">

			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'sheetsync-cf7' ); ?></button>

			<a href="<?php echo esc_url( $export_url ); ?>" class="button ss-cf7-export">
				<span class="dashicons dashicons-download" aria-hidden="true"></span>
				<?php esc_html_e( 'Export CSV', 'sheetsync-cf7' ); ?>
			</a>
		</form>
		<?php
	}

	/**
	 * One KPI tile.
	 *
	 * @param string $label  Label.
	 * @param int    $value  Value.
	 * @param string $status Status key for the colored marker ('' = none).
	 */
	private function render_kpi( string $label, int $value, string $status ): void {
		?>
		<div class="ss-cf7-kpi">
			<span class="ss-cf7-kpi-label">
				<?php if ( '' !== $status ) : ?>
					<span class="ss-cf7-swatch is-<?php echo esc_attr( $status ); ?>" aria-hidden="true"></span>
				<?php endif; ?>
				<?php echo esc_html( $label ); ?>
			</span>
			<span class="ss-cf7-kpi-value"><?php echo esc_html( number_format_i18n( $value ) ); ?></span>
		</div>
		<?php
	}

	/**
	 * Status pill (icon + label, never color alone).
	 *
	 * @param string $status Status.
	 */
	private function render_status( string $status ): void {
		$map = self::status_map();
		$def = $map[ $status ] ?? $map[ SS_CF7_Entries::STATUS_PENDING ];

		printf(
			'<span class="ss-cf7-status-pill is-%1$s"><span class="dashicons %2$s" aria-hidden="true"></span>%3$s</span>',
			esc_attr( $status ),
			esc_attr( $def['icon'] ),
			esc_html( $def['label'] )
		);
	}

	/**
	 * Labels and icons of the statuses.
	 */
	private static function status_map(): array {
		return array(
			SS_CF7_Entries::STATUS_SENT    => array(
				'label' => __( 'Sent', 'sheetsync-cf7' ),
				'icon'  => 'dashicons-yes-alt',
			),
			SS_CF7_Entries::STATUS_FAILED  => array(
				'label' => __( 'Failed', 'sheetsync-cf7' ),
				'icon'  => 'dashicons-warning',
			),
			SS_CF7_Entries::STATUS_PENDING => array(
				'label' => __( 'Pending', 'sheetsync-cf7' ),
				'icon'  => 'dashicons-clock',
			),
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * AJAX
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Entry ID from the request (after the guard).
	 */
	private function posted_entry_id(): int {
		return isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard_ajax().
	}

	/**
	 * Full detail of one entry for the inspector.
	 */
	public function ajax_get_entry(): void {
		SS_CF7_Admin_Settings::guard_ajax();

		$entry = $this->entries->get( $this->posted_entry_id() );
		if ( null === $entry ) {
			SS_CF7_Admin_Settings::ajax_error( __( 'Entry not found.', 'sheetsync-cf7' ) );
		}

		wp_send_json_success( $this->describe_entry( $entry ) );
	}

	/**
	 * Sends an entry to Google Sheets again.
	 */
	public function ajax_retry_entry(): void {
		SS_CF7_Admin_Settings::guard_ajax();

		$id     = $this->posted_entry_id();
		$result = $this->handler->retry_entry( $id );

		if ( is_wp_error( $result ) ) {
			SS_CF7_Admin_Settings::ajax_error( $result->get_error_message() );
		}

		$entry = $this->entries->get( $id );

		wp_send_json_success(
			array(
				'message' => __( 'Entry sent to Google Sheets.', 'sheetsync-cf7' ),
				'entry'   => $entry ? $this->describe_entry( $entry ) : null,
			)
		);
	}

	/**
	 * Deletes an entry (WordPress copy only).
	 */
	public function ajax_delete_entry(): void {
		SS_CF7_Admin_Settings::guard_ajax();

		if ( 1 !== $this->entries->delete( array( $this->posted_entry_id() ) ) ) {
			SS_CF7_Admin_Settings::ajax_error( __( 'Entry not found.', 'sheetsync-cf7' ) );
		}

		wp_send_json_success( array( 'message' => __( 'Entry deleted.', 'sheetsync-cf7' ) ) );
	}

	/**
	 * Entry data for the inspector (plain values, rendered with textContent).
	 *
	 * @param array $entry Decoded entry.
	 */
	private function describe_entry( array $entry ): array {
		$status = self::status_map()[ $entry['status'] ] ?? self::status_map()[ SS_CF7_Entries::STATUS_PENDING ];

		return array(
			'id'          => $entry['id'],
			'date'        => $this->format_date( $entry['created_at'] ),
			'sent_at'     => $entry['sent_at'] ? $this->format_date( $entry['sent_at'] ) : '',
			'form'        => $this->form_title( $entry['form_id'] ),
			'status'      => $entry['status'],
			'statusLabel' => $status['label'],
			'attempts'    => $entry['attempts'],
			'retryInfo'   => $this->retry_info( $entry ),
			'error'       => $entry['error'],
			'sheet'       => '' !== $entry['sheet_name'] ? $entry['sheet_name'] : __( 'first tab', 'sheetsync-cf7' ),
			'sheetUrl'    => '' !== $entry['spreadsheet_id'] ? SS_CF7_Admin_Settings::spreadsheet_url( $entry['spreadsheet_id'] ) : '',
			'fields'      => $this->labelled_fields( $entry['fields'] ),
			'row'         => array_values( array_filter( $entry['row_data'], 'is_array' ) ),
		);
	}

	/**
	 * Automatic retry status of an entry, for the inspector ('' when not relevant).
	 *
	 * @param array $entry Decoded entry.
	 */
	private function retry_info( array $entry ): string {
		if ( SS_CF7_Entries::STATUS_SENT === $entry['status'] ) {
			return '';
		}

		$options = SS_CF7_Entries::options();
		if ( ! $options['auto_retry'] ) {
			return __( 'Disabled', 'sheetsync-cf7' );
		}

		$next = SS_CF7_Entries::next_retry_at( $entry, $options );
		if ( null === $next ) {
			/* translators: %d: maximum attempts. */
			return sprintf( __( 'Stopped: all %d attempts failed', 'sheetsync-cf7' ), $options['max_attempts'] );
		}

		if ( $next <= time() ) {
			return __( 'Within the next hour', 'sheetsync-cf7' );
		}

		/* translators: %s: date and time. */
		return sprintf( __( 'Next attempt around %s', 'sheetsync-cf7' ), $this->format_date( gmdate( 'Y-m-d H:i:s', $next ) ) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * CSV export
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Streams the filtered entries as CSV.
	 */
	public function export_csv(): void {
		if ( ! current_user_can( SS_CF7_Admin_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'sheetsync-cf7' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::EXPORT_ACTION );

		$filters = $this->filters( $_GET );
		$rows    = $this->entries->export_rows( $filters );

		// Union of the field keys, in order of first appearance.
		$keys = array();
		foreach ( $rows as $entry ) {
			foreach ( array_keys( $entry['fields'] ) as $key ) {
				if ( ! str_starts_with( (string) $key, '#' ) ) {
					$keys[ $key ] = true;
				}
			}
		}
		$keys = array_keys( $keys );

		// Italian & co. Excel expect ";" when the decimal separator is ",".
		global $wp_locale;
		$delimiter = isset( $wp_locale->number_format['decimal_point'] ) && ',' === $wp_locale->number_format['decimal_point'] ? ';' : ',';
		$delimiter = (string) apply_filters( 'ss_cf7_csv_delimiter', $delimiter );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sheetsync-cf7-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for Excel.

		$labels = $this->labelled_fields( array_fill_keys( $keys, '' ) );
		fputcsv(
			$out,
			array_merge(
				array( 'ID', __( 'Date', 'sheetsync-cf7' ), __( 'Form', 'sheetsync-cf7' ), __( 'Status', 'sheetsync-cf7' ) ),
				array_column( $labels, 0 )
			),
			$delimiter,
			'"',
			''
		);

		$statuses = self::status_map();
		foreach ( $rows as $entry ) {
			$line = array(
				$entry['id'],
				$this->format_date( $entry['created_at'] ),
				$this->form_title( $entry['form_id'] ),
				$statuses[ $entry['status'] ]['label'] ?? $entry['status'],
			);
			foreach ( $keys as $key ) {
				$line[] = self::csv_cell( (string) ( $entry['fields'][ $key ] ?? '' ) );
			}
			fputcsv( $out, array_map( array( self::class, 'csv_cell' ), array_map( 'strval', $line ) ), $delimiter, '"', '' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Neutralizes spreadsheet formulas in a CSV cell (CSV injection).
	 *
	 * @param string $value Cell.
	 */
	private static function csv_cell( string $value ): string {
		return ( '' !== $value && 1 === preg_match( '/^[=+\-@\t\r]/', $value ) && ! str_starts_with( $value, "'" ) ) ? "'" . $value : $value;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Field values with readable labels (metadata translated, internal keys hidden).
	 *
	 * @param array $fields value key => value.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function labelled_fields( array $fields ): array {
		$meta = SS_CF7_Handler::meta_labels();
		$out  = array();

		foreach ( $fields as $key => $value ) {
			$key = (string) $key;
			if ( str_starts_with( $key, '#' ) ) {
				continue;
			}
			$label = str_starts_with( $key, SS_CF7_Handler::META_PREFIX ) ? ( $meta[ substr( $key, 1 ) ] ?? $key ) : $key;
			$out[] = array( $label, (string) $value );
		}

		return $out;
	}

	/**
	 * Short preview of an entry: first non-empty field values.
	 *
	 * @param array $fields value key => value.
	 */
	private function preview( array $fields ): string {
		$parts = array();
		foreach ( $fields as $key => $value ) {
			$key = (string) $key;
			if ( '' === trim( (string) $value ) || str_starts_with( $key, '#' ) || str_starts_with( $key, SS_CF7_Handler::META_PREFIX ) ) {
				continue;
			}
			$parts[] = trim( (string) $value );
			if ( count( $parts ) >= 3 ) {
				break;
			}
		}

		$text = implode( ' · ', $parts );
		return mb_strlen( $text ) > 90 ? mb_substr( $text, 0, 89 ) . '…' : $text;
	}

	/**
	 * GMT MySQL date → site date/time.
	 *
	 * @param string $gmt GMT datetime.
	 */
	private function format_date( string $gmt ): string {
		return get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * CF7 forms: ID => title.
	 */
	private function form_titles(): array {
		if ( null === $this->form_titles ) {
			$this->form_titles = array();
			$forms             = get_posts(
				array(
					'post_type'        => 'wpcf7_contact_form',
					'post_status'      => 'any',
					'numberposts'      => 200,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => false,
				)
			);
			foreach ( $forms as $form ) {
				$this->form_titles[ (int) $form->ID ] = get_the_title( $form );
			}
		}

		return $this->form_titles;
	}

	/**
	 * Title of a form (or a placeholder when deleted).
	 *
	 * @param int $form_id Form ID.
	 */
	private function form_title( int $form_id ): string {
		$titles = $this->form_titles();

		/* translators: %d: form ID. */
		return $titles[ $form_id ] ?? sprintf( __( 'Deleted form #%d', 'sheetsync-cf7' ), $form_id );
	}
}
