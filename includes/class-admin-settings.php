<?php
/**
 * Admin UI: plugin page (Contact → SheetSync for CF7, "Settings" tab) and the
 * "Google Sheets" panel inside the Contact Form 7 editor.
 *
 * @package SheetSync_CF7
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SS_CF7_Admin_Settings
 */
final class SS_CF7_Admin_Settings {

	/** Capability required for every action of the plugin. */
	public const CAPABILITY = 'manage_options';

	/** Nonce action shared by all AJAX calls. */
	public const AJAX_NONCE = 'ss_cf7_ajax';

	/** admin-post.php actions (also used as nonce actions). */
	private const SAVE_ACTION     = 'ss_cf7_save_credentials';
	private const DELETE_ACTION   = 'ss_cf7_delete_credentials';
	private const OPTIONS_ACTION  = 'ss_cf7_save_options';
	private const TRACKING_ACTION = 'ss_cf7_save_tracking';
	private const FORMAT_ACTION   = 'ss_cf7_save_format';

	/** Nonce action/field name of the CF7 editor panel. */
	private const FORM_NONCE = 'ss_cf7_form_nonce';

	/** Per-user transient prefix used to show a notice after a redirect. */
	private const NOTICE_TRANSIENT = 'ss_cf7_notice_';

	/** Tabs of the plugin page. */
	private const TABS = array( 'settings', 'dashboard' );

	/**
	 * Google client.
	 *
	 * @var SS_CF7_Google_Auth
	 */
	private SS_CF7_Google_Auth $auth;

	/**
	 * Submission handler (owns the per-form settings schema and column logic).
	 *
	 * @var SS_CF7_Handler
	 */
	private SS_CF7_Handler $handler;

	/**
	 * Local archive.
	 *
	 * @var SS_CF7_Entries
	 */
	private SS_CF7_Entries $entries;

	/**
	 * Dashboard tab.
	 *
	 * @var SS_CF7_Dashboard
	 */
	private SS_CF7_Dashboard $dashboard;

	/**
	 * Hook suffix of the plugin page.
	 *
	 * @var string
	 */
	private string $page_hook = '';

	/**
	 * Registers hooks only.
	 *
	 * @param SS_CF7_Google_Auth $auth      Google client.
	 * @param SS_CF7_Handler     $handler   Submission handler.
	 * @param SS_CF7_Entries     $entries   Local archive.
	 * @param SS_CF7_Dashboard   $dashboard Dashboard tab.
	 */
	public function __construct( SS_CF7_Google_Auth $auth, SS_CF7_Handler $handler, SS_CF7_Entries $entries, SS_CF7_Dashboard $dashboard ) {
		$this->auth      = $auth;
		$this->handler   = $handler;
		$this->entries   = $entries;
		$this->dashboard = $dashboard;

		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save_credentials' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'handle_delete_credentials' ) );
		add_action( 'admin_post_' . self::OPTIONS_ACTION, array( $this, 'handle_save_options' ) );
		add_action( 'admin_post_' . self::TRACKING_ACTION, array( $this, 'handle_save_tracking' ) );
		add_action( 'admin_post_' . self::FORMAT_ACTION, array( $this, 'handle_save_format' ) );

		add_action( 'wp_ajax_ss_cf7_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_ss_cf7_sync_headers', array( $this, 'ajax_sync_headers' ) );
		add_action( 'wp_ajax_ss_cf7_load_headers', array( $this, 'ajax_load_headers' ) );

		add_filter( 'wpcf7_editor_panels', array( $this, 'add_editor_panel' ) );
		add_action( 'wpcf7_after_save', array( $this, 'save_form_settings' ) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Menu & assets
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Adds Contact → SheetSync for CF7.
	 */
	public function register_page(): void {
		$hook = add_submenu_page(
			self::menu_parent(),
			__( 'SheetSync for CF7', 'sheetsync-cf7' ),
			__( 'SheetSync for CF7', 'sheetsync-cf7' ),
			self::CAPABILITY,
			SS_CF7_PAGE_SLUG,
			array( $this, 'render_page' )
		);

		$this->page_hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Parent menu slug: the "Contact" menu of Contact Form 7.
	 */
	public static function menu_parent(): string {
		global $menu;

		// Recent Contact Form 7 versions use "wpcf7-dashboard" for the "Contact" menu ("wpcf7" is now a sub-page);
		// older versions use "wpcf7". Settings is the fallback if neither exists.
		$slugs  = is_array( $menu ) ? array_column( $menu, 2 ) : array();
		$parent = 'options-general.php';

		foreach ( array( 'wpcf7-dashboard', 'wpcf7' ) as $slug ) {
			if ( in_array( $slug, $slugs, true ) ) {
				$parent = $slug;
				break;
			}
		}

		/**
		 * Filters the parent menu of the plugin page.
		 *
		 * @param string $parent Parent menu slug.
		 */
		return (string) apply_filters( 'ss_cf7_menu_parent', $parent );
	}

	/**
	 * Current tab of the plugin page.
	 */
	private function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, self::TABS, true ) ? $tab : 'settings';
	}

	/**
	 * Loads JS/CSS only on our page and on the CF7 form editor.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		$page          = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$is_cf7_editor = ( 'wpcf7' === $page && isset( $_GET['post'] ) ) || 'wpcf7-new' === $page;
		// phpcs:enable

		$is_our_page = '' !== $this->page_hook && $hook === $this->page_hook;

		if ( ! $is_our_page && ! $is_cf7_editor ) {
			return;
		}

		wp_enqueue_style( 'ss-cf7-admin', SS_CF7_URL . 'assets/admin.css', array(), SS_CF7_VERSION );

		if ( $is_our_page && 'dashboard' === $this->current_tab() ) {
			$this->dashboard->enqueue();
			return;
		}

		wp_enqueue_script( 'ss-cf7-admin', SS_CF7_URL . 'assets/admin.js', array(), SS_CF7_VERSION, true );

		wp_localize_script(
			'ss-cf7-admin',
			'ssCf7',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::AJAX_NONCE ),
				'i18n'    => array(
					'copy'           => __( 'Copy email', 'sheetsync-cf7' ),
					'copied'         => __( 'Copied!', 'sheetsync-cf7' ),
					'copyFailed'     => __( 'Copy failed: select the text and press Ctrl+C / Cmd+C.', 'sheetsync-cf7' ),
					'testing'        => __( 'Contacting Google…', 'sheetsync-cf7' ),
					'syncing'        => __( 'Syncing headers with Google Sheets…', 'sheetsync-cf7' ),
					'loading'        => __( 'Reading the columns of the sheet…', 'sheetsync-cf7' ),
					'requestFailed'  => __( 'The request failed. Check your connection and try again.', 'sheetsync-cf7' ),
					'invalidJson'    => __( 'This is not valid JSON. Paste the whole content of the .json key file.', 'sheetsync-cf7' ),
					/* translators: %s: comma-separated list of JSON field names. */
					'missingFields'  => __( 'Required fields missing from the JSON: %s.', 'sheetsync-cf7' ),
					'notServiceAcc'  => __( 'This JSON is not a Service Account key ("type" must be "service_account").', 'sheetsync-cf7' ),
					'fileTooLarge'   => __( 'This file is too large to be a Service Account key.', 'sheetsync-cf7' ),
					'fileReadError'  => __( 'The file could not be read.', 'sheetsync-cf7' ),
					/* translators: %s: Service Account email. */
					'fileLoaded'     => __( 'Key loaded for %s. Click "Save credentials" to store it.', 'sheetsync-cf7' ),
					'emptyJson'      => __( 'Upload the JSON key file or paste its content first.', 'sheetsync-cf7' ),
					'missingId'      => __( 'Enter the Spreadsheet ID (or paste the full spreadsheet URL) first.', 'sheetsync-cf7' ),
					'addedColumns'   => __( 'Columns added:', 'sheetsync-cf7' ),
					'orphanColumns'  => __( 'Columns in the sheet with no matching field (they will stay empty unless you map them below):', 'sheetsync-cf7' ),
					'unsavedWarning' => __( 'Remember to save the form to keep these Google Sheets settings.', 'sheetsync-cf7' ),
					/* translators: %s: form field or metadata name. */
					'autoMatch'      => __( 'Automatic → %s', 'sheetsync-cf7' ),
					'autoNone'       => __( 'Automatic (no field with this name: stays empty)', 'sheetsync-cf7' ),
					'ruleEmpty'      => __( 'Leave empty', 'sheetsync-cf7' ),
					'ruleText'       => __( 'Custom text…', 'sheetsync-cf7' ),
					'groupFields'    => __( 'Form fields', 'sheetsync-cf7' ),
					'groupMeta'      => __( 'Metadata', 'sheetsync-cf7' ),
					'groupTracking'  => __( 'Lead source (UTM / click ID)', 'sheetsync-cf7' ),
					'textPlaceholder' => __( 'e.g. Google Ads, or [your-name] – [_post_title]', 'sheetsync-cf7' ),
					/* translators: %d: number of columns. */
					'columnsLoaded'  => __( '%d columns read from the sheet. Choose what to write in each one, then save the form.', 'sheetsync-cf7' ),
				),
			)
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Plugin page
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Renders Contact → SheetSync for CF7 (tabs).
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab  = $this->current_tab();
		$tabs = array(
			'settings'  => __( 'Settings', 'sheetsync-cf7' ),
			'dashboard' => __( 'Dashboard', 'sheetsync-cf7' ),
		);
		?>
		<div class="wrap ss-cf7-wrap">
			<h1><?php esc_html_e( 'SheetSync for CF7', 'sheetsync-cf7' ); ?></h1>

			<nav class="nav-tab-wrapper ss-cf7-tabs" aria-label="<?php esc_attr_e( 'SheetSync for CF7 sections', 'sheetsync-cf7' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( ss_cf7_admin_url( $slug ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>" <?php echo $tab === $slug ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php $this->render_notice(); ?>

			<?php
			if ( 'dashboard' === $tab ) {
				$this->dashboard->render();
			} else {
				$this->render_settings_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * "Settings" tab.
	 */
	private function render_settings_tab(): void {
		$info = $this->auth->get_public_info();
		?>
		<p class="ss-cf7-intro">
			<?php esc_html_e( 'Send Contact Form 7 submissions to Google Sheets in real time using your own Google Service Account. Your key stays on your site: no third-party servers are involved.', 'sheetsync-cf7' ); ?>
		</p>
		<?php
		$this->render_guide( $info );
		$this->render_credentials_card( $info );
		$this->render_format_card();
		$this->render_archive_card();
		$this->render_tracking_card();
		$this->render_forms_card();
	}

	/**
	 * Date and time format of the values written in the sheet.
	 */
	private function render_format_card(): void {
		$options = SS_CF7_Handler::format_options();
		$sample  = gmmktime( 14, 30, 5, 3, 11, 2026 ); // 11 March 2026, 14:30:05.
		?>
		<div class="ss-cf7-card" id="ss-cf7-format">
			<h2><?php esc_html_e( 'Date and time format', 'sheetsync-cf7' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::FORMAT_ACTION ); ?>">
				<?php wp_nonce_field( self::FORMAT_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="ss-cf7-date-format"><?php esc_html_e( 'Date', 'sheetsync-cf7' ); ?></label></th>
							<td>
								<select id="ss-cf7-date-format" name="ss_cf7_format[date_format]">
									<?php foreach ( SS_CF7_Handler::DATE_FORMATS as $format ) : ?>
										<option value="<?php echo esc_attr( $format ); ?>" <?php selected( $options['date_format'], $format ); ?>><?php echo esc_html( gmdate( $format, $sample ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ss-cf7-time-format"><?php esc_html_e( 'Time', 'sheetsync-cf7' ); ?></label></th>
							<td>
								<select id="ss-cf7-time-format" name="ss_cf7_format[time_format]">
									<?php foreach ( SS_CF7_Handler::TIME_FORMATS as $format ) : ?>
										<option value="<?php echo esc_attr( $format ); ?>" <?php selected( $options['time_format'], $format ); ?>><?php echo esc_html( '' === $format ? __( 'Do not write the time', 'sheetsync-cf7' ) : gmdate( $format, $sample ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Used for the "Submission date" column (site timezone).', 'sheetsync-cf7' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Date fields', 'sheetsync-cf7' ); ?></th>
							<td>
								<label for="ss-cf7-form-dates">
									<input type="checkbox" id="ss-cf7-form-dates" name="ss_cf7_format[form_dates]" value="1" <?php checked( $options['form_dates'] ); ?>>
									<?php esc_html_e( 'Use the same date format for the date fields of the forms ([date])', 'sheetsync-cf7' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Contact Form 7 sends them as 2026-03-11.', 'sheetsync-cf7' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="ss-cf7-share-hint">
					<?php esc_html_e( 'Google Sheets turns the text into a real date (usable with the date picker, filters and formulas) only when the format matches the locale of the spreadsheet: File → Settings → Locale. With "Italy" use 11/03/2026; with "United States" use 03/11/2026. 2026-03-11 works with every locale.', 'sheetsync-cf7' ); ?>
				</p>

				<?php submit_button( __( 'Save settings', 'sheetsync-cf7' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Lead source tracking settings (UTM parameters, click IDs, channel names).
	 */
	private function render_tracking_card(): void {
		$options  = SS_CF7_Attribution::options();
		$defaults = SS_CF7_Attribution::default_labels();
		$days     = array(
			0  => __( 'Only the page where the visitor lands (nothing stored in the browser)', 'sheetsync-cf7' ),
			7  => __( '7 days', 'sheetsync-cf7' ),
			30 => __( '30 days', 'sheetsync-cf7' ),
			90 => __( '90 days', 'sheetsync-cf7' ),
		);
		?>
		<div class="ss-cf7-card" id="ss-cf7-tracking">
			<h2><?php esc_html_e( 'Lead source tracking (UTM / gclid)', 'sheetsync-cf7' ); ?></h2>
			<p><?php esc_html_e( 'Records where each lead comes from: Google Ads, Meta Ads, organic search, other websites… from the UTM parameters and the ad click IDs (gclid, fbclid, msclkid) of the page where the visitor arrived, even if the form is on another page.', 'sheetsync-cf7' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TRACKING_ACTION ); ?>">
				<?php wp_nonce_field( self::TRACKING_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Tracking', 'sheetsync-cf7' ); ?></th>
							<td>
								<label for="ss-cf7-track-enabled">
									<input type="checkbox" id="ss-cf7-track-enabled" name="ss_cf7_tracking[enabled]" value="1" <?php checked( $options['enabled'] ); ?>>
									<?php esc_html_e( 'Capture the lead source of every submission', 'sheetsync-cf7' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Adds a small script (about 2 KB, no cookies, no external requests) to the pages of the site.', 'sheetsync-cf7' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ss-cf7-track-days"><?php esc_html_e( 'Remember the source for', 'sheetsync-cf7' ); ?></label></th>
							<td>
								<select id="ss-cf7-track-days" name="ss_cf7_tracking[days]">
									<?php foreach ( $days as $value => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $options['days'], $value ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'With a duration, the campaign is kept in the browser (localStorage) so the lead is attributed even when the visitor comes back later directly.', 'sheetsync-cf7' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ss-cf7-track-model"><?php esc_html_e( 'If there are several visits', 'sheetsync-cf7' ); ?></label></th>
							<td>
								<select id="ss-cf7-track-model" name="ss_cf7_tracking[model]">
									<option value="last" <?php selected( $options['model'], 'last' ); ?>><?php esc_html_e( 'Last campaign click (recommended)', 'sheetsync-cf7' ); ?></option>
									<option value="first" <?php selected( $options['model'], 'first' ); ?>><?php esc_html_e( 'First campaign click', 'sheetsync-cf7' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Direct visits never replace a campaign.', 'sheetsync-cf7' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cookie consent', 'sheetsync-cf7' ); ?></th>
							<td>
								<label for="ss-cf7-track-consent">
									<input type="checkbox" id="ss-cf7-track-consent" name="ss_cf7_tracking[consent]" value="1" <?php checked( $options['consent'] ); ?>>
									<?php esc_html_e( 'Remember the source only with the visitor\'s marketing consent', 'sheetsync-cf7' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Uses the WP Consent API, supported by Complianz, CookieYes, Cookiebot, Real Cookie Banner and others. Without consent the source is still read from the page where the form is sent, but nothing is stored in the browser.', 'sheetsync-cf7' ); ?></p>
								<?php if ( $options['consent'] && ! SS_CF7_Attribution::consent_api_active() ) : ?>
									<p class="ss-cf7-hint is-warning"><?php esc_html_e( 'WP Consent API not detected: until you install it (with a compatible cookie banner), the source is read only from the page where the form is sent.', 'sheetsync-cf7' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Channel names', 'sheetsync-cf7' ); ?></th>
							<td>
								<p class="description"><?php esc_html_e( 'What is written in the "Lead source" column for each channel. Leave empty to use the default name.', 'sheetsync-cf7' ); ?></p>
								<table class="ss-cf7-channel-table">
									<tbody>
										<?php foreach ( SS_CF7_Attribution::CHANNELS as $channel ) : ?>
											<tr>
												<th scope="row"><label for="ss-cf7-channel-<?php echo esc_attr( $channel ); ?>"><?php echo esc_html( $defaults[ $channel ] ); ?></label></th>
												<td><input type="text" id="ss-cf7-channel-<?php echo esc_attr( $channel ); ?>" class="regular-text" name="ss_cf7_tracking[labels][<?php echo esc_attr( $channel ); ?>]" value="<?php echo esc_attr( $options['labels'][ $channel ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $defaults[ $channel ] ); ?>" maxlength="100"></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="description">
					<?php esc_html_e( 'Then, in the Google Sheets tab of each form, tick the "Lead source" columns or map a column of your sheet (e.g. "Lead type") to "Lead source". The same values are available as mail-tags for the CF7 emails and the custom texts:', 'sheetsync-cf7' ); ?>
					<code>[_ss_channel]</code> <code>[_ss_utm_source]</code> <code>[_ss_utm_medium]</code> <code>[_ss_utm_campaign]</code> <code>[_ss_utm_term]</code> <code>[_ss_gclid]</code> <code>[_ss_landing_page]</code> <code>[_ss_referrer]</code>
				</p>

				<?php submit_button( __( 'Save settings', 'sheetsync-cf7' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Collapsible quick-start guide (open until a key is configured).
	 *
	 * @param array $info Public credential info.
	 */
	private function render_guide( array $info ): void {
		$link = array(
			'a'      => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'strong' => array(),
			'em'     => array(),
			'code'   => array(),
		);
		?>
		<details class="ss-cf7-card ss-cf7-guide" <?php echo $info['connected'] ? '' : 'open'; ?>>
			<summary><?php esc_html_e( 'Quick start guide: connect Google Sheets in 4 steps', 'sheetsync-cf7' ); ?></summary>

			<ol class="ss-cf7-steps">
				<li>
					<strong><?php esc_html_e( 'Create a Google Cloud project', 'sheetsync-cf7' ); ?></strong>
					<p>
						<?php
						printf(
							wp_kses(
								/* translators: %s: Google Cloud Console URL. */
								__( 'Open the <a href="%s" target="_blank" rel="noopener noreferrer">Google Cloud Console</a> and create a new project (or select an existing one). It is free: no billing account is needed for the Sheets API.', 'sheetsync-cf7' ),
								$link
							),
							esc_url( 'https://console.cloud.google.com/projectcreate' )
						);
						?>
					</p>
				</li>
				<li>
					<strong><?php esc_html_e( 'Enable the Google Sheets API', 'sheetsync-cf7' ); ?></strong>
					<p>
						<?php
						printf(
							wp_kses(
								/* translators: %s: Google Sheets API library URL. */
								__( 'Open the <a href="%s" target="_blank" rel="noopener noreferrer">Google Sheets API page</a>, check that your project is selected at the top, and click <strong>Enable</strong>.', 'sheetsync-cf7' ),
								$link
							),
							esc_url( 'https://console.cloud.google.com/apis/library/sheets.googleapis.com' )
						);
						?>
					</p>
				</li>
				<li>
					<strong><?php esc_html_e( 'Create a Service Account and download its JSON key', 'sheetsync-cf7' ); ?></strong>
					<p>
						<?php
						printf(
							wp_kses(
								/* translators: %s: Service Accounts page URL. */
								__( 'Go to <a href="%s" target="_blank" rel="noopener noreferrer">IAM &amp; Admin → Service Accounts</a>, click <strong>Create service account</strong> and give it a name (no roles are needed). Then open it, go to the <strong>Keys</strong> tab → <strong>Add key</strong> → <strong>Create new key</strong> → <strong>JSON</strong>. A <code>.json</code> file is downloaded: upload it in the box below.', 'sheetsync-cf7' ),
								$link
							),
							esc_url( 'https://console.cloud.google.com/iam-admin/serviceaccounts' )
						);
						?>
					</p>
				</li>
				<li>
					<strong><?php esc_html_e( 'Share your spreadsheet with the Service Account', 'sheetsync-cf7' ); ?></strong>
					<p>
						<?php echo wp_kses( __( 'Open your Google Sheet, click <strong>Share</strong>, paste the Service Account email and grant <strong>Editor</strong> access. Repeat for every spreadsheet you want to use.', 'sheetsync-cf7' ), $link ); ?>
					</p>
					<?php if ( $info['connected'] ) : ?>
						<div class="ss-cf7-email-row">
							<code class="ss-cf7-email"><?php echo esc_html( $info['client_email'] ); ?></code>
							<?php $this->render_copy_button( $info['client_email'] ); ?>
						</div>
					<?php endif; ?>
				</li>
			</ol>

			<p class="ss-cf7-tip">
				<span class="dashicons dashicons-lock" aria-hidden="true"></span>
				<?php esc_html_e( 'Treat the JSON file like a password: don\'t email it or commit it to public repositories, and delete it from your computer after uploading. If your Google Workspace organization blocks key creation, ask your Workspace administrator.', 'sheetsync-cf7' ); ?>
			</p>
		</details>
		<?php
	}

	/**
	 * Credentials box: upload form when disconnected, status + actions when connected.
	 *
	 * @param array $info Public credential info.
	 */
	private function render_credentials_card( array $info ): void {
		?>
		<div class="ss-cf7-card">
			<h2><?php esc_html_e( 'Google Service Account', 'sheetsync-cf7' ); ?></h2>

			<?php if ( $info['connected'] ) : ?>

				<p>
					<span class="ss-cf7-status is-connected">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Connected', 'sheetsync-cf7' ); ?>
					</span>
				</p>

				<p><?php echo wp_kses( __( 'Share every spreadsheet you want to use with this email, as <strong>Editor</strong>:', 'sheetsync-cf7' ), array( 'strong' => array() ) ); ?></p>

				<div class="ss-cf7-email-row">
					<label for="ss-cf7-client-email" class="screen-reader-text"><?php esc_html_e( 'Service Account email', 'sheetsync-cf7' ); ?></label>
					<input type="text" id="ss-cf7-client-email" class="ss-cf7-email-input code" value="<?php echo esc_attr( $info['client_email'] ); ?>" readonly>
					<?php $this->render_copy_button( $info['client_email'] ); ?>
				</div>

				<p class="description">
					<?php
					$details = array();
					if ( '' !== $info['project_id'] ) {
						/* translators: %s: Google Cloud project ID. */
						$details[] = sprintf( __( 'Project: %s', 'sheetsync-cf7' ), $info['project_id'] );
					}
					if ( $info['saved_at'] > 0 ) {
						/* translators: %s: date and time. */
						$details[] = sprintf( __( 'Saved on %s', 'sheetsync-cf7' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $info['saved_at'] ) );
					}
					$details[] = __( 'The private key is stored encrypted and is never displayed.', 'sheetsync-cf7' );
					echo esc_html( implode( ' · ', $details ) );
					?>
				</p>

				<div class="ss-cf7-actions">
					<button type="button" class="button button-primary" id="ss-cf7-test-connection">
						<?php esc_html_e( 'Test connection', 'sheetsync-cf7' ); ?>
					</button>
					<span class="spinner" id="ss-cf7-test-spinner"></span>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ss-cf7-inline-form" data-ss-cf7-confirm="<?php echo esc_attr__( 'Remove the Service Account credentials? Your forms will stop sending data to Google Sheets until you add a new key.', 'sheetsync-cf7' ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( self::DELETE_ACTION ); ?>">
						<?php wp_nonce_field( self::DELETE_ACTION ); ?>
						<button type="submit" class="button ss-cf7-button-danger">
							<?php esc_html_e( 'Remove / Replace credentials', 'sheetsync-cf7' ); ?>
						</button>
					</form>
				</div>

				<div id="ss-cf7-test-result" class="ss-cf7-result" aria-live="polite" hidden></div>

			<?php else : ?>

				<p>
					<span class="ss-cf7-status is-disconnected">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<?php esc_html_e( 'Not connected', 'sheetsync-cf7' ); ?>
					</span>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ss-cf7-credentials-form" autocomplete="off">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
					<?php wp_nonce_field( self::SAVE_ACTION ); ?>

					<p>
						<label for="ss-cf7-json-file"><strong><?php esc_html_e( 'Upload the JSON key file', 'sheetsync-cf7' ); ?></strong></label><br>
						<input type="file" id="ss-cf7-json-file" accept=".json,application/json">
					</p>

					<p>
						<label for="ss-cf7-json"><strong><?php esc_html_e( '…or paste its content', 'sheetsync-cf7' ); ?></strong></label>
					</p>
					<textarea id="ss-cf7-json" name="ss_cf7_json" rows="10" class="large-text code ss-cf7-json" spellcheck="false" autocomplete="off" placeholder="<?php echo esc_attr( '{ "type": "service_account", "project_id": "…", "private_key": "-----BEGIN PRIVATE KEY-----…", "client_email": "…@….iam.gserviceaccount.com", … }' ); ?>"></textarea>

					<div id="ss-cf7-json-feedback" class="ss-cf7-result" aria-live="polite" hidden></div>

					<p class="description">
						<?php esc_html_e( 'The file is read in your browser and sent only to your own WordPress site. The private key is encrypted before being saved and will never be shown again.', 'sheetsync-cf7' ); ?>
					</p>

					<?php submit_button( __( 'Save credentials', 'sheetsync-cf7' ), 'primary', 'submit', true ); ?>
				</form>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Entries archive options (local copy + retention).
	 */
	private function render_archive_card(): void {
		$options = SS_CF7_Entries::options();
		$choices = array(
			0   => __( 'Forever', 'sheetsync-cf7' ),
			30  => __( '30 days', 'sheetsync-cf7' ),
			90  => __( '90 days', 'sheetsync-cf7' ),
			180 => __( '6 months', 'sheetsync-cf7' ),
			365 => __( '1 year', 'sheetsync-cf7' ),
			730 => __( '2 years', 'sheetsync-cf7' ),
		);
		?>
		<div class="ss-cf7-card">
			<h2><?php esc_html_e( 'Archive, retries and alerts', 'sheetsync-cf7' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::OPTIONS_ACTION ); ?>">
				<?php wp_nonce_field( self::OPTIONS_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Local copy', 'sheetsync-cf7' ); ?></th>
							<td>
								<label for="ss-cf7-store-entries">
									<input type="checkbox" id="ss-cf7-store-entries" name="ss_cf7_options[store_entries]" value="1" <?php checked( $options['store_entries'] ); ?>>
									<?php esc_html_e( 'Save a copy of every submission in WordPress', 'sheetsync-cf7' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Needed for the Dashboard, to resend submissions that failed and to export them as CSV. Nothing is lost even if Google Sheets is unreachable.', 'sheetsync-cf7' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ss-cf7-retention"><?php esc_html_e( 'Keep entries for', 'sheetsync-cf7' ); ?></label></th>
							<td>
								<select id="ss-cf7-retention" name="ss_cf7_options[retention_days]">
									<?php foreach ( $choices as $days => $label ) : ?>
										<option value="<?php echo esc_attr( (string) $days ); ?>" <?php selected( $options['retention_days'], $days ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Older entries are deleted automatically once a day (only from WordPress, never from Google Sheets). Entries contain personal data: keep them only as long as you need them (GDPR).', 'sheetsync-cf7' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Automatic retry', 'sheetsync-cf7' ); ?></th>
							<td>
								<label for="ss-cf7-auto-retry">
									<input type="checkbox" id="ss-cf7-auto-retry" name="ss_cf7_options[auto_retry]" value="1" <?php checked( $options['auto_retry'] ); ?>>
									<?php esc_html_e( 'Send failed submissions again automatically', 'sheetsync-cf7' ); ?>
								</label>
								<p class="ss-cf7-inline-field">
									<label for="ss-cf7-max-attempts"><?php esc_html_e( 'Maximum attempts', 'sheetsync-cf7' ); ?></label>
									<select id="ss-cf7-max-attempts" name="ss_cf7_options[max_attempts]">
										<?php foreach ( SS_CF7_Entries::MAX_ATTEMPTS_CHOICES as $attempts ) : ?>
											<option value="<?php echo esc_attr( (string) $attempts ); ?>" <?php selected( $options['max_attempts'], $attempts ); ?>><?php echo esc_html( number_format_i18n( $attempts ) ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<p class="description"><?php esc_html_e( 'Runs in the background about once an hour, waiting longer after each failure (1, 2, 4, 8 hours…), so a temporary Google outage or quota limit is recovered without losing leads. Requires the local copy.', 'sheetsync-cf7' ); ?></p>
								<?php $this->render_cron_status( $options ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Email alerts', 'sheetsync-cf7' ); ?></th>
							<td>
								<label for="ss-cf7-alert-enabled">
									<input type="checkbox" id="ss-cf7-alert-enabled" name="ss_cf7_options[alert_enabled]" value="1" <?php checked( $options['alert_enabled'] ); ?>>
									<?php esc_html_e( 'Email me when a submission cannot be sent to Google Sheets', 'sheetsync-cf7' ); ?>
								</label>
								<p class="ss-cf7-inline-field">
									<label for="ss-cf7-alert-email"><?php esc_html_e( 'Recipients', 'sheetsync-cf7' ); ?></label>
									<input type="text" id="ss-cf7-alert-email" class="regular-text" name="ss_cf7_options[alert_email]" value="<?php echo esc_attr( $options['alert_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" autocomplete="off" spellcheck="false">
								</p>
								<p class="description">
									<?php
									printf(
										/* translators: %s: site admin email. */
										esc_html__( 'Separate multiple addresses with commas. Leave empty to use the site admin email (%s).', 'sheetsync-cf7' ),
										esc_html( (string) get_option( 'admin_email' ) )
									);
									?>
									<?php esc_html_e( 'You get an email when a submission fails and, if it is retried automatically, only again when the last attempt fails. At most one email per hour; the submitted data is never included.', 'sheetsync-cf7' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="ss-cf7-actions">
					<?php submit_button( __( 'Save settings', 'sheetsync-cf7' ), 'secondary', 'submit', false ); ?>
					<button type="submit" class="button" name="ss_cf7_test_alert" value="1"><?php esc_html_e( 'Save and send a test email', 'sheetsync-cf7' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Hint under the "Automatic retry" option (next run, misconfigurations).
	 *
	 * @param array $options General options.
	 */
	private function render_cron_status( array $options ): void {
		if ( ! $options['auto_retry'] ) {
			return;
		}

		if ( ! $options['store_entries'] ) {
			echo '<p class="ss-cf7-hint is-warning">' . esc_html__( 'The local copy is disabled: there is nothing to retry. Enable "Save a copy of every submission in WordPress".', 'sheetsync-cf7' ) . '</p>';
			return;
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			echo '<p class="ss-cf7-hint is-warning">' . esc_html__( 'WP-Cron is disabled on this site (DISABLE_WP_CRON): make sure a server cron job calls wp-cron.php, otherwise the automatic retry never runs.', 'sheetsync-cf7' ) . '</p>';
			return;
		}

		$next = wp_next_scheduled( SS_CF7_CRON_RETRY );
		if ( $next ) {
			printf(
				'<p class="ss-cf7-hint">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: date and time. */
						__( 'Next check: %s.', 'sheetsync-cf7' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
					)
				)
			);
		}
	}

	/**
	 * Overview of the forms that have Google Sheets settings.
	 */
	private function render_forms_card(): void {
		$forms = get_posts(
			array(
				'post_type'        => 'wpcf7_contact_form',
				'post_status'      => 'any',
				'numberposts'      => 100,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'meta_key'         => SS_CF7_META_SETTINGS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		?>
		<div class="ss-cf7-card">
			<h2><?php esc_html_e( 'Connected forms', 'sheetsync-cf7' ); ?></h2>

			<?php if ( empty( $forms ) ) : ?>
				<p>
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL of the Contact Form 7 forms list. */
							__( 'No form configured yet. Open a form in <a href="%s">Contact → Contact Forms</a> and use the <strong>Google Sheets</strong> tab.', 'sheetsync-cf7' ),
							array(
								'a'      => array( 'href' => array() ),
								'strong' => array(),
							)
						),
						esc_url( admin_url( 'admin.php?page=wpcf7' ) )
					);
					?>
				</p>
			<?php else : ?>
				<table class="widefat striped ss-cf7-forms-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Form', 'sheetsync-cf7' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'sheetsync-cf7' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Spreadsheet', 'sheetsync-cf7' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Tab', 'sheetsync-cf7' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $forms as $form ) : ?>
							<?php
							$settings = SS_CF7_Handler::get_form_settings( (int) $form->ID );
							$edit_url = admin_url( 'admin.php?page=wpcf7&post=' . (int) $form->ID . '&action=edit' );
							?>
							<tr>
								<td><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( get_the_title( $form ) ); ?></a></td>
								<td>
									<?php if ( $settings['enabled'] && '' !== $settings['spreadsheet_id'] ) : ?>
										<span class="ss-cf7-pill is-on"><?php esc_html_e( 'Active', 'sheetsync-cf7' ); ?></span>
									<?php else : ?>
										<span class="ss-cf7-pill"><?php esc_html_e( 'Disabled', 'sheetsync-cf7' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( '' !== $settings['spreadsheet_id'] ) : ?>
										<a href="<?php echo esc_url( self::spreadsheet_url( $settings['spreadsheet_id'] ) ); ?>" target="_blank" rel="noopener noreferrer" class="code"><?php echo esc_html( self::short_id( $settings['spreadsheet_id'] ) ); ?></a>
									<?php else : ?>
										&mdash;
									<?php endif; ?>
								</td>
								<td><?php echo '' !== $settings['sheet_name'] ? esc_html( $settings['sheet_name'] ) : '<em>' . esc_html__( 'first tab', 'sheetsync-cf7' ) . '</em>'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * "Copy email" button handled by admin.js.
	 *
	 * @param string $email Text to copy.
	 */
	private function render_copy_button( string $email ): void {
		printf(
			'<button type="button" class="button ss-cf7-copy" data-ss-cf7-copy="%1$s"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> <span class="ss-cf7-copy-label">%2$s</span></button>',
			esc_attr( $email ),
			esc_html__( 'Copy email', 'sheetsync-cf7' )
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * admin-post handlers
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Validates, encrypts and stores the pasted/uploaded JSON, then tests it.
	 */
	public function handle_save_credentials(): void {
		$this->guard_admin_post( self::SAVE_ACTION );

		// Validated field by field (and the key by OpenSSL) in parse_credentials().
		$raw = isset( $_POST['ss_cf7_json'] ) && is_string( $_POST['ss_cf7_json'] ) ? wp_unslash( $_POST['ss_cf7_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$credentials = SS_CF7_Google_Auth::parse_credentials( $raw );
		unset( $raw );

		if ( is_wp_error( $credentials ) ) {
			$this->redirect_with_notice( 'error', $credentials->get_error_message() );
		}

		$saved = $this->auth->save_credentials( $credentials );
		unset( $credentials );

		if ( is_wp_error( $saved ) ) {
			$this->redirect_with_notice( 'error', $saved->get_error_message() );
		}

		$test = $this->auth->test_connection();

		if ( is_wp_error( $test ) ) {
			$this->redirect_with_notice(
				'warning',
				sprintf(
					/* translators: %s: error message. */
					__( 'Credentials saved, but the connection test failed: %s', 'sheetsync-cf7' ),
					$test->get_error_message()
				)
			);
		}

		$this->redirect_with_notice(
			'success',
			sprintf(
				/* translators: %s: Service Account email. */
				__( 'Credentials saved and connection to Google verified. Now share your spreadsheets with %s as Editor.', 'sheetsync-cf7' ),
				$test['client_email']
			)
		);
	}

	/**
	 * Removes the stored credentials.
	 */
	public function handle_delete_credentials(): void {
		$this->guard_admin_post( self::DELETE_ACTION );

		$this->auth->delete_credentials();

		$this->redirect_with_notice( 'success', __( 'Credentials removed. You can now upload a new JSON key.', 'sheetsync-cf7' ) );
	}

	/**
	 * Saves the archive options.
	 */
	public function handle_save_options(): void {
		$this->guard_admin_post( self::OPTIONS_ACTION );

		$raw = isset( $_POST['ss_cf7_options'] ) && is_array( $_POST['ss_cf7_options'] ) ? wp_unslash( $_POST['ss_cf7_options'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_options().

		SS_CF7_Entries::save_options( $raw );
		SS_CF7_Entries::schedule_events();

		if ( ! empty( $_POST['ss_cf7_test_alert'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard_admin_post().
			$to = SS_CF7_Notifier::recipients();

			if ( empty( $to ) ) {
				$this->redirect_with_notice( 'error', __( 'Settings saved, but there is no valid recipient for the test email.', 'sheetsync-cf7' ) );
			}

			if ( SS_CF7_Notifier::send_test() ) {
				$this->redirect_with_notice(
					'success',
					/* translators: %s: email addresses. */
					sprintf( __( 'Settings saved. Test email sent to %s: if it does not arrive, check the spam folder and the email settings of the site (an SMTP plugin is recommended).', 'sheetsync-cf7' ), implode( ', ', $to ) )
				);
			}

			$this->redirect_with_notice( 'error', __( 'Settings saved, but WordPress could not send the test email. Check the email settings of the site (an SMTP plugin is recommended).', 'sheetsync-cf7' ) );
		}

		$this->redirect_with_notice( 'success', __( 'Settings saved.', 'sheetsync-cf7' ) );
	}

	/**
	 * Saves the date and time format.
	 */
	public function handle_save_format(): void {
		$this->guard_admin_post( self::FORMAT_ACTION );

		$raw = isset( $_POST['ss_cf7_format'] ) && is_array( $_POST['ss_cf7_format'] ) ? wp_unslash( $_POST['ss_cf7_format'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_format_options().

		update_option( SS_CF7_Handler::FORMAT_OPTION, SS_CF7_Handler::sanitize_format_options( $raw ) );

		$this->redirect_with_notice( 'success', __( 'Settings saved.', 'sheetsync-cf7' ) );
	}

	/**
	 * Saves the lead source tracking settings.
	 */
	public function handle_save_tracking(): void {
		$this->guard_admin_post( self::TRACKING_ACTION );

		$raw = isset( $_POST['ss_cf7_tracking'] ) && is_array( $_POST['ss_cf7_tracking'] ) ? wp_unslash( $_POST['ss_cf7_tracking'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_options().

		update_option( SS_CF7_Attribution::OPTION, SS_CF7_Attribution::sanitize_options( $raw ) );

		$this->redirect_with_notice( 'success', __( 'Settings saved.', 'sheetsync-cf7' ) );
	}

	/**
	 * Capability + nonce check for admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard_admin_post( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'sheetsync-cf7' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Stores a one-shot notice for the current user and goes back to the Settings tab.
	 *
	 * @param string $type    success|warning|error|info.
	 * @param string $message Plain-text message.
	 * @param string $tab     Tab to go back to.
	 */
	public static function redirect_with_notice( string $type, string $message, string $tab = 'settings' ): void {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( ss_cf7_admin_url( $tab ) );
		exit;
	}

	/**
	 * Prints (and consumes) the pending notice, if any.
	 */
	private function render_notice(): void {
		$key    = self::NOTICE_TRANSIENT . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );

		$type = in_array( $notice['type'] ?? '', array( 'success', 'warning', 'error', 'info' ), true ) ? $notice['type'] : 'info';

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( (string) $notice['message'] )
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * AJAX
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Nonce + capability check for every AJAX endpoint of the plugin.
	 */
	public static function guard_ajax(): void {
		if ( ! check_ajax_referer( self::AJAX_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Reload the page and try again.', 'sheetsync-cf7' ) ), 403 );
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to perform this action.', 'sheetsync-cf7' ) ), 403 );
		}
	}

	/**
	 * Sends a JSON error and stops.
	 *
	 * @param string $message Plain-text message.
	 */
	public static function ajax_error( string $message ): void {
		wp_send_json_error( array( 'message' => $message ) );
	}

	/**
	 * "Test connection": forces a fresh JWT → access token exchange.
	 */
	public function ajax_test_connection(): void {
		self::guard_ajax();

		$result = $this->auth->test_connection();

		if ( is_wp_error( $result ) ) {
			self::ajax_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: Service Account email. */
					__( 'Connection successful! Google issued an access token for %s.', 'sheetsync-cf7' ),
					$result['client_email']
				),
			)
		);
	}

	/**
	 * Reads the panel values posted by admin.js (not yet saved with the form).
	 *
	 * @return array{0: WPCF7_ContactForm|null, 1: array} Form and sanitized settings.
	 */
	private function posted_panel_settings(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard_ajax(), sanitized in sanitize_form_settings().
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
		$mapping = isset( $_POST['mapping'] ) && is_string( $_POST['mapping'] ) ? json_decode( wp_unslash( $_POST['mapping'] ), true ) : array();
		$raw     = array(
			'enabled'        => true,
			'spreadsheet_id' => isset( $_POST['spreadsheet_id'] ) && is_string( $_POST['spreadsheet_id'] ) ? wp_unslash( $_POST['spreadsheet_id'] ) : '',
			'sheet_name'     => isset( $_POST['sheet_name'] ) && is_string( $_POST['sheet_name'] ) ? wp_unslash( $_POST['sheet_name'] ) : '',
			'meta'           => isset( $_POST['meta'] ) && is_array( $_POST['meta'] ) ? wp_unslash( $_POST['meta'] ) : array(),
			'exclude'        => isset( $_POST['exclude'] ) && is_array( $_POST['exclude'] ) ? wp_unslash( $_POST['exclude'] ) : array(),
			'mapping'        => is_array( $mapping ) ? $mapping : array(),
		);
		// phpcs:enable

		$form = $form_id > 0 ? wpcf7_contact_form( $form_id ) : null;

		return array( $form instanceof WPCF7_ContactForm ? $form : null, SS_CF7_Handler::sanitize_form_settings( $raw ) );
	}

	/**
	 * Checks spreadsheet access and the tab name; returns the spreadsheet info and the effective tab.
	 *
	 * @param array $settings Sanitized settings.
	 * @return array{0: array, 1: string}
	 */
	private function resolve_target( array $settings ): array {
		if ( ! $this->auth->has_credentials() ) {
			self::ajax_error( __( 'No Service Account configured. Go to Contact → SheetSync for CF7 first.', 'sheetsync-cf7' ) );
		}

		if ( '' === $settings['spreadsheet_id'] ) {
			self::ajax_error( __( 'The Spreadsheet ID is missing or not valid.', 'sheetsync-cf7' ) );
		}

		$spreadsheet = $this->auth->get_spreadsheet_info( $settings['spreadsheet_id'] );
		if ( is_wp_error( $spreadsheet ) ) {
			self::ajax_error( $spreadsheet->get_error_message() );
		}

		$sheet_name = $settings['sheet_name'];

		if ( '' === $sheet_name ) {
			$sheet_name = (string) ( $spreadsheet['sheets'][0] ?? '' );
		} elseif ( ! in_array( $sheet_name, $spreadsheet['sheets'], true ) ) {
			self::ajax_error(
				sprintf(
					/* translators: 1: tab name, 2: spreadsheet title, 3: comma-separated list of existing tabs. */
					__( 'The tab "%1$s" does not exist in "%2$s". Available tabs: %3$s. The name is case-sensitive.', 'sheetsync-cf7' ),
					$sheet_name,
					$spreadsheet['title'],
					implode( ', ', $spreadsheet['sheets'] )
				)
			);
		}

		return array( $spreadsheet, $sheet_name );
	}

	/**
	 * Header list for the mapping table: [{header, auto}].
	 *
	 * @param string[]               $headers Header row.
	 * @param WPCF7_ContactForm|null $form    Form.
	 */
	private function describe_headers( array $headers, ?WPCF7_ContactForm $form ): array {
		$fields = $form ? $this->handler->get_field_names( $form ) : array();
		$out    = array();

		foreach ( $headers as $header ) {
			if ( '' !== $header ) {
				$out[] = array(
					'header' => $header,
					'auto'   => $this->handler->describe_auto( $header, $fields ),
				);
			}
		}

		return $out;
	}

	/**
	 * "Load columns from the sheet": returns the header row for the mapping table.
	 */
	public function ajax_load_headers(): void {
		self::guard_ajax();

		[ $form, $settings ]        = $this->posted_panel_settings();
		[ $spreadsheet, $sheet_name ] = $this->resolve_target( $settings );

		$headers = $this->auth->get_header_row( $settings['spreadsheet_id'], $sheet_name );
		if ( is_wp_error( $headers ) ) {
			self::ajax_error( $headers->get_error_message() );
		}

		$described = $this->describe_headers( $headers, $form );

		if ( empty( $described ) ) {
			self::ajax_error( __( 'Row 1 of the sheet is empty. Click "Sync headers" first, or write the column names in row 1.', 'sheetsync-cf7' ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of columns. */
					__( '%d columns read from the sheet. Choose what to write in each one, then save the form.', 'sheetsync-cf7' ),
					count( $described )
				),
				'headers' => $described,
				'title'   => $spreadsheet['title'],
			)
		);
	}

	/**
	 * "Sync headers": merges the form columns into row 1 of the sheet.
	 *
	 * Existing headers are kept in place (the user may have reordered them);
	 * missing columns are appended at the end. Nothing is ever deleted.
	 */
	public function ajax_sync_headers(): void {
		self::guard_ajax();

		[ $form, $settings ] = $this->posted_panel_settings();

		if ( null === $form ) {
			self::ajax_error( __( 'Save the form at least once before syncing the headers.', 'sheetsync-cf7' ) );
		}

		[ $spreadsheet, $sheet_name ] = $this->resolve_target( $settings );

		$columns = $this->handler->get_columns( $form, $settings );
		if ( empty( $columns ) ) {
			self::ajax_error( __( 'This form has no fields that can be sent to Google Sheets.', 'sheetsync-cf7' ) );
		}

		$existing = $this->auth->get_header_row( $settings['spreadsheet_id'], $sheet_name );
		if ( is_wp_error( $existing ) ) {
			self::ajax_error( $existing->get_error_message() );
		}

		// Keep existing headers, append the missing ones (columns mapped explicitly count as present).
		$diff    = $this->handler->compare_headers( $existing, $columns, $settings['mapping'] );
		$added   = array_values( $diff['missing'] );
		$orphans = $diff['orphans'];
		$row     = array_merge( $existing, $added );

		// Write only when something changed. RAW: header text is never parsed as a formula.
		if ( ! empty( $added ) ) {
			$written = $this->auth->update_values(
				$settings['spreadsheet_id'],
				SS_CF7_Google_Auth::a1( $sheet_name, 'A1' ),
				array( array_values( $row ) ),
				'RAW'
			);

			if ( is_wp_error( $written ) ) {
				self::ajax_error( $written->get_error_message() );
			}
		}

		$this->handler->flush_header_cache( $form->id() );

		if ( empty( $added ) ) {
			$message = sprintf(
				/* translators: 1: spreadsheet title, 2: tab name. */
				__( 'Headers already up to date in "%1$s" › %2$s.', 'sheetsync-cf7' ),
				$spreadsheet['title'],
				$sheet_name
			);
		} else {
			$message = sprintf(
				/* translators: 1: number of columns, 2: spreadsheet title, 3: tab name. */
				_n( '%1$d column added to "%2$s" › %3$s.', '%1$d columns added to "%2$s" › %3$s.', count( $added ), 'sheetsync-cf7' ),
				count( $added ),
				$spreadsheet['title'],
				$sheet_name
			);
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'added'   => $added,
				'orphans' => $orphans,
				'headers' => $this->describe_headers( $row, $form ),
			)
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Contact Form 7 editor panel
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Adds the "Google Sheets" tab to the CF7 editor.
	 *
	 * @param array $panels Registered panels.
	 * @return array
	 */
	public function add_editor_panel( $panels ) {
		$panels['ss-cf7-google-sheets-panel'] = array(
			'title'    => __( 'Google Sheets', 'sheetsync-cf7' ),
			'callback' => array( $this, 'render_editor_panel' ),
		);

		return $panels;
	}

	/**
	 * Renders the panel content.
	 *
	 * @param WPCF7_ContactForm $contact_form Form being edited.
	 */
	public function render_editor_panel( $contact_form ): void {
		$form    = $contact_form instanceof WPCF7_ContactForm ? $contact_form : null;
		$form_id = $form ? (int) $form->id() : 0;

		echo '<h2>' . esc_html__( 'Google Sheets', 'sheetsync-cf7' ) . '</h2>';

		if ( ! current_user_can( self::CAPABILITY ) ) {
			echo '<p>' . esc_html__( 'Only administrators can configure the Google Sheets integration.', 'sheetsync-cf7' ) . '</p>';
			return;
		}

		$settings    = SS_CF7_Handler::get_form_settings( $form_id );
		$info        = $this->auth->get_public_info();
		$field_names = $form && $form_id > 0 ? $this->handler->get_field_names( $form ) : array();
		$columns     = $form && $form_id > 0 ? $this->handler->get_columns( $form, $settings ) : array();

		// Mapping rows for admin.js (rendered client-side).
		$mapping_rows = array();
		foreach ( $settings['mapping'] as $rule ) {
			$mapping_rows[] = array(
				'header' => $rule['header'],
				'rule'   => 'field' === $rule['type'] ? 'field:' . $rule['source'] : $rule['type'],
				'text'   => $rule['text'],
				'auto'   => $this->handler->describe_auto( $rule['header'], $field_names ),
			);
		}
		?>
		<div class="ss-cf7-panel" data-form-id="<?php echo esc_attr( (string) $form_id ); ?>">

			<?php if ( ! $info['connected'] ) : ?>
				<div class="ss-cf7-result is-warning">
					<p>
						<?php
						printf(
							wp_kses(
								/* translators: %s: plugin page URL. */
								__( 'No Google Service Account configured yet. <a href="%s">Configure it in Contact → SheetSync for CF7</a> first.', 'sheetsync-cf7' ),
								array( 'a' => array( 'href' => array() ) )
							),
							esc_url( ss_cf7_admin_url( 'settings' ) )
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<div class="ss-cf7-share-hint">
					<span><?php echo wp_kses( __( 'Share the spreadsheet as <strong>Editor</strong> with:', 'sheetsync-cf7' ), array( 'strong' => array() ) ); ?></span>
					<code class="ss-cf7-email"><?php echo esc_html( $info['client_email'] ); ?></code>
					<?php $this->render_copy_button( $info['client_email'] ); ?>
				</div>
			<?php endif; ?>

			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Google Sheets settings for this form', 'sheetsync-cf7' ); ?></legend>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Status', 'sheetsync-cf7' ); ?></th>
							<td>
								<label for="ss-cf7-enabled">
									<input type="checkbox" id="ss-cf7-enabled" name="ss_cf7[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>>
									<?php esc_html_e( 'Send the submissions of this form to Google Sheets', 'sheetsync-cf7' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="ss-cf7-spreadsheet-id"><?php esc_html_e( 'Spreadsheet ID', 'sheetsync-cf7' ); ?></label></th>
							<td>
								<input type="text" id="ss-cf7-spreadsheet-id" name="ss_cf7[spreadsheet_id]" class="large-text code" value="<?php echo esc_attr( $settings['spreadsheet_id'] ); ?>" placeholder="1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789" autocomplete="off" spellcheck="false">
								<p class="description">
									<?php
									echo wp_kses(
										__( 'Copy it from the spreadsheet URL: <code>https://docs.google.com/spreadsheets/d/<strong>SPREADSHEET_ID</strong>/edit</code>. You can also paste the full URL: the ID is extracted automatically.', 'sheetsync-cf7' ),
										array(
											'code'   => array(),
											'strong' => array(),
										)
									);
									?>
								</p>
								<?php if ( '' !== $settings['spreadsheet_id'] ) : ?>
									<p>
										<a href="<?php echo esc_url( self::spreadsheet_url( $settings['spreadsheet_id'] ) ); ?>" target="_blank" rel="noopener noreferrer">
											<?php esc_html_e( 'Open spreadsheet', 'sheetsync-cf7' ); ?>
											<span class="dashicons dashicons-external" aria-hidden="true"></span>
										</a>
									</p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="ss-cf7-sheet-name"><?php esc_html_e( 'Sheet (tab) name', 'sheetsync-cf7' ); ?></label></th>
							<td>
								<input type="text" id="ss-cf7-sheet-name" name="ss_cf7[sheet_name]" class="regular-text" value="<?php echo esc_attr( $settings['sheet_name'] ); ?>" placeholder="<?php esc_attr_e( 'Sheet1', 'sheetsync-cf7' ); ?>" autocomplete="off">
								<p class="description"><?php esc_html_e( 'Exact name of the tab at the bottom of the spreadsheet (case-sensitive). Leave empty to use the first tab.', 'sheetsync-cf7' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Extra columns', 'sheetsync-cf7' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Extra columns', 'sheetsync-cf7' ); ?></legend>
									<?php foreach ( array_diff_key( SS_CF7_Handler::meta_labels(), array_flip( SS_CF7_Handler::TRACKING_KEYS ) ) as $key => $label ) : ?>
										<label class="ss-cf7-check-option">
											<input type="checkbox" class="ss-cf7-meta" name="ss_cf7[meta][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $settings['meta'], true ) ); ?>>
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Lead source columns', 'sheetsync-cf7' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Lead source columns', 'sheetsync-cf7' ); ?></legend>
									<?php foreach ( array_intersect_key( SS_CF7_Handler::meta_labels(), array_flip( SS_CF7_Handler::TRACKING_KEYS ) ) as $key => $label ) : ?>
										<label class="ss-cf7-check-option">
											<input type="checkbox" class="ss-cf7-meta" name="ss_cf7[meta][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $settings['meta'], true ) ); ?>>
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
								<?php if ( ! SS_CF7_Attribution::options()['enabled'] ) : ?>
									<p class="ss-cf7-hint is-warning">
										<?php
										printf(
											wp_kses(
												/* translators: %s: URL of the tracking settings. */
												__( 'Lead source tracking is off: these columns stay empty. <a href="%s">Turn it on in the settings</a>.', 'sheetsync-cf7' ),
												array( 'a' => array( 'href' => array() ) )
											),
											esc_url( ss_cf7_admin_url( 'settings' ) . '#ss-cf7-tracking' )
										);
										?>
									</p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( '"Lead source" is the channel (Google Ads, Organic search…); the names can be changed in Contact → SheetSync for CF7.', 'sheetsync-cf7' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="ss-cf7-pipes"><?php esc_html_e( 'Fields with pipes (Label | Value)', 'sheetsync-cf7' ); ?></label></th>
							<td>
								<select id="ss-cf7-pipes" name="ss_cf7[pipes]">
									<option value="value" <?php selected( $settings['pipes'], 'value' ); ?>><?php esc_html_e( 'Value only (after the "|", default)', 'sheetsync-cf7' ); ?></option>
									<option value="label" <?php selected( $settings['pipes'], 'label' ); ?>><?php esc_html_e( 'Label only (the text the visitor sees)', 'sheetsync-cf7' ); ?></option>
									<option value="both" <?php selected( $settings['pipes'], 'both' ); ?>><?php esc_html_e( 'Both, as "Label | Value"', 'sheetsync-cf7' ); ?></option>
								</select>
								<p class="description"><?php echo wp_kses( __( 'For select, checkbox and radio options written as <code>Rome office|rome@example.com</code>. Fields without pipes are not affected.', 'sheetsync-cf7' ), array( 'code' => array() ) ); ?></p>
							</td>
						</tr>

						<?php if ( ! empty( $field_names ) ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Fields not to send', 'sheetsync-cf7' ); ?></th>
								<td>
									<fieldset>
										<legend class="screen-reader-text"><?php esc_html_e( 'Fields not to send', 'sheetsync-cf7' ); ?></legend>
										<?php foreach ( $field_names as $name ) : ?>
											<label class="ss-cf7-check-option">
												<input type="checkbox" class="ss-cf7-exclude" name="ss_cf7[exclude][]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $settings['exclude'], true ) ); ?>>
												<code><?php echo esc_html( $name ); ?></code>
											</label>
										<?php endforeach; ?>
									</fieldset>
									<p class="description"><?php esc_html_e( 'Checked fields are not added as columns and are not written automatically (e.g. privacy consent checkboxes). You can still map them explicitly below.', 'sheetsync-cf7' ); ?></p>
								</td>
							</tr>
						<?php endif; ?>

						<tr>
							<th scope="row"><?php esc_html_e( 'Column headers', 'sheetsync-cf7' ); ?></th>
							<td>
								<div class="ss-cf7-actions">
									<button type="button" class="button button-secondary" id="ss-cf7-sync-headers" <?php disabled( $form_id <= 0 || ! $info['connected'] ); ?>>
										<span class="dashicons dashicons-update" aria-hidden="true"></span>
										<?php esc_html_e( 'Sync headers', 'sheetsync-cf7' ); ?>
									</button>
									<span class="spinner" id="ss-cf7-sync-spinner"></span>
								</div>

								<p class="description">
									<?php esc_html_e( 'Adds the missing field names of this form (plus the extra columns) to row 1 of the sheet. Existing columns are never moved or deleted.', 'sheetsync-cf7' ); ?>
								</p>

								<?php if ( $form_id <= 0 ) : ?>
									<p class="description"><strong><?php esc_html_e( 'Save the form first to enable this button.', 'sheetsync-cf7' ); ?></strong></p>
								<?php elseif ( ! empty( $columns ) ) : ?>
									<p class="description ss-cf7-columns">
										<?php esc_html_e( 'Columns of the saved form:', 'sheetsync-cf7' ); ?>
										<?php foreach ( $columns as $label ) : ?>
											<code><?php echo esc_html( $label ); ?></code>
										<?php endforeach; ?>
									</p>
									<p class="description"><?php esc_html_e( 'Added or renamed fields? Save the form first, then sync again.', 'sheetsync-cf7' ); ?></p>
								<?php endif; ?>

								<div id="ss-cf7-sync-result" class="ss-cf7-result" aria-live="polite" hidden></div>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Column mapping', 'sheetsync-cf7' ); ?></th>
							<td>
								<div class="ss-cf7-mapping" id="ss-cf7-mapping"
									data-rows="<?php echo esc_attr( (string) wp_json_encode( $mapping_rows ) ); ?>"
									data-sources="<?php echo esc_attr( (string) wp_json_encode( $this->handler->get_sources( $form_id > 0 ? $form : null ) ) ); ?>">

									<p class="description">
										<?php esc_html_e( 'Decide what to write in each column of the sheet: a form field, a fixed text (e.g. "Google Ads" in a "Lead type" column), or nothing. "Automatic" fills the column with the field that has the same name.', 'sheetsync-cf7' ); ?>
									</p>

									<div class="ss-cf7-actions">
										<button type="button" class="button button-secondary" id="ss-cf7-load-headers" <?php disabled( ! $info['connected'] ); ?>>
											<span class="dashicons dashicons-download" aria-hidden="true"></span>
											<?php esc_html_e( 'Load columns from the sheet', 'sheetsync-cf7' ); ?>
										</button>
										<span class="spinner" id="ss-cf7-load-spinner"></span>
									</div>

									<div id="ss-cf7-mapping-result" class="ss-cf7-result" aria-live="polite" hidden></div>

									<table class="widefat striped ss-cf7-mapping-table" hidden>
										<thead>
											<tr>
												<th scope="col"><?php esc_html_e( 'Sheet column', 'sheetsync-cf7' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Value to write', 'sheetsync-cf7' ); ?></th>
											</tr>
										</thead>
										<tbody></tbody>
									</table>

									<p class="description">
										<?php
										echo wp_kses(
											__( 'Custom texts accept CF7 mail-tags, e.g. <code>[your-name]</code>, <code>[_post_title]</code>, <code>[_date]</code>, <code>[_url]</code>. The mapping is saved together with the form.', 'sheetsync-cf7' ),
											array( 'code' => array() )
										);
										?>
									</p>
								</div>
							</td>
						</tr>
					</tbody>
				</table>
			</fieldset>

			<?php wp_nonce_field( self::FORM_NONCE, self::FORM_NONCE, false ); ?>
		</div>
		<?php
	}

	/**
	 * Saves the panel fields together with the CF7 form.
	 *
	 * Runs on every CF7 save (including duplication): without our nonce
	 * in the request nothing is touched.
	 *
	 * @param WPCF7_ContactForm $contact_form Saved form.
	 */
	public function save_form_settings( $contact_form ): void {
		if ( ! $contact_form instanceof WPCF7_ContactForm ) {
			return;
		}

		$nonce = isset( $_POST[ self::FORM_NONCE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FORM_NONCE ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::FORM_NONCE ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$form_id = (int) $contact_form->id();
		if ( $form_id <= 0 ) {
			return;
		}

		// Sanitized key by key in SS_CF7_Handler::sanitize_form_settings().
		$raw = isset( $_POST['ss_cf7'] ) && is_array( $_POST['ss_cf7'] ) ? wp_unslash( $_POST['ss_cf7'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		// The mapping table is built by admin.js: if it never loaded, keep the saved mapping.
		if ( empty( $raw['mapping_ready'] ) ) {
			$raw['mapping'] = SS_CF7_Handler::get_form_settings( $form_id )['mapping'];
		}

		update_post_meta( $form_id, SS_CF7_META_SETTINGS, SS_CF7_Handler::sanitize_form_settings( $raw ) );

		// Spreadsheet or tab may have changed: re-read the header row on next submission.
		$this->handler->flush_header_cache( $form_id );
	}

	/*
	 * -------------------------------------------------------------------------
	 * Helpers
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Public URL of a spreadsheet.
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 */
	public static function spreadsheet_url( string $spreadsheet_id ): string {
		return 'https://docs.google.com/spreadsheets/d/' . rawurlencode( $spreadsheet_id ) . '/edit';
	}

	/**
	 * Shortened ID for tables (e.g. 1AbCdEf…xYz9).
	 *
	 * @param string $spreadsheet_id Spreadsheet ID.
	 */
	public static function short_id( string $spreadsheet_id ): string {
		return strlen( $spreadsheet_id ) > 16 ? substr( $spreadsheet_id, 0, 8 ) . '…' . substr( $spreadsheet_id, -4 ) : $spreadsheet_id;
	}
}
