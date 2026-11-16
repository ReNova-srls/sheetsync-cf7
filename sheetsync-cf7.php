<?php
/**
 * Plugin Name:       SheetSync for CF7
 * Plugin URI:        https://github.com/ReNova-srls/sheetsync-cf7
 * Description:       Sends Contact Form 7 submissions to Google Sheets in real time using your own Google Service Account (Bring Your Own Key). Lightweight, no external libraries.
 * Version:           1.4.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Requires Plugins:  contact-form-7
 * Author:            ReNova s.r.l.s.
 * Author URI:        https://github.com/ReNova-srls
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sheetsync-cf7
 * Domain Path:       /languages
 *
 * @package SheetSync_CF7
 */

/*
 * NOTE: this file intentionally uses only PHP 7-compatible syntax, so that on an
 * outdated server the requirement check below can show a friendly notice instead
 * of a parse error. All PHP 8 code lives in /includes and is loaded only after
 * the requirements have been verified.
 */

defined( 'ABSPATH' ) || exit;

define( 'SS_CF7_VERSION', '1.4.0' );
define( 'SS_CF7_FILE', __FILE__ );
define( 'SS_CF7_PATH', plugin_dir_path( __FILE__ ) );
define( 'SS_CF7_URL', plugin_dir_url( __FILE__ ) );
define( 'SS_CF7_BASENAME', plugin_basename( __FILE__ ) );
define( 'SS_CF7_MIN_PHP', '8.0' );
define( 'SS_CF7_MIN_WP', '6.0' );
define( 'SS_CF7_PAGE_SLUG', 'sheetsync-cf7' ); // Contact → SheetSync for CF7.
define( 'SS_CF7_DB_VERSION', '2' );

/*
 * Storage keys. Keep in sync with uninstall.php (which runs without this file).
 */
define( 'SS_CF7_OPTION_CREDENTIALS', 'ss_cf7_credentials' ); // Encrypted Service Account JSON.
define( 'SS_CF7_OPTION_OPTIONS', 'ss_cf7_options' );         // General options (archive, retry, alerts).
define( 'SS_CF7_OPTION_DB_VERSION', 'ss_cf7_db_version' );   // Schema version of the entries table.
define( 'SS_CF7_META_SETTINGS', '_ss_cf7_settings' );       // Per-form settings (post meta on wpcf7_contact_form).
define( 'SS_CF7_TRANSIENT_TOKEN', 'ss_cf7_access_token' );  // Cached OAuth2 access token.
define( 'SS_CF7_TRANSIENT_HEADERS', 'ss_cf7_hdr_' );        // Prefix + form ID: cached header row of the sheet.
define( 'SS_CF7_TABLE', 'ss_cf7_entries' );                 // Entries table (without the $wpdb prefix).
define( 'SS_CF7_CRON_CLEANUP', 'ss_cf7_daily_cleanup' );    // Daily retention cleanup.
define( 'SS_CF7_CRON_RETRY', 'ss_cf7_retry_failed' );      // Hourly automatic retry of failed entries.
define( 'SS_CF7_TRANSIENT_ALERT', 'ss_cf7_alert_lock' );    // Set after a failure email: limits alerts to one per hour.

/**
 * URL of the plugin page (Contact → SheetSync for CF7).
 *
 * @param string $tab  'settings' or 'dashboard' ('' = default tab).
 * @param array  $args Extra query args.
 * @return string
 */
function ss_cf7_admin_url( $tab = '', $args = array() ) {
	$query = array( 'page' => SS_CF7_PAGE_SLUG );
	if ( '' !== $tab ) {
		$query['tab'] = $tab;
	}
	return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
}

/**
 * Runs the environment checks without translating anything
 * (safe to call before `init`).
 *
 * @return string[] Codes of the unmet requirements: 'php', 'wp', 'openssl'.
 */
function ss_cf7_unmet_requirements() {
	global $wp_version;

	$unmet = array();

	if ( version_compare( PHP_VERSION, SS_CF7_MIN_PHP, '<' ) ) {
		$unmet[] = 'php';
	}
	if ( version_compare( $wp_version, SS_CF7_MIN_WP, '<' ) ) {
		$unmet[] = 'wp';
	}
	if ( ! extension_loaded( 'openssl' ) || ! function_exists( 'openssl_sign' ) ) {
		$unmet[] = 'openssl';
	}

	return $unmet;
}

/**
 * Converts requirement codes into human readable (translated) messages.
 *
 * @param string[] $codes Codes returned by ss_cf7_unmet_requirements().
 * @return string[]
 */
function ss_cf7_requirement_messages( $codes ) {
	global $wp_version;

	$messages = array();

	foreach ( $codes as $code ) {
		switch ( $code ) {
			case 'php':
				$messages[] = sprintf(
					/* translators: 1: required PHP version, 2: current PHP version. */
					__( 'SheetSync for CF7 requires PHP %1$s or higher. Your server is running PHP %2$s.', 'sheetsync-cf7' ),
					SS_CF7_MIN_PHP,
					PHP_VERSION
				);
				break;
			case 'wp':
				$messages[] = sprintf(
					/* translators: 1: required WordPress version, 2: current WordPress version. */
					__( 'SheetSync for CF7 requires WordPress %1$s or higher. You are running WordPress %2$s.', 'sheetsync-cf7' ),
					SS_CF7_MIN_WP,
					$wp_version
				);
				break;
			case 'openssl':
				$messages[] = __( 'SheetSync for CF7 requires the PHP OpenSSL extension to sign Google authentication requests. Ask your hosting provider to enable it.', 'sheetsync-cf7' );
				break;
		}
	}

	return $messages;
}

/**
 * Activation: refuse to activate on unsupported environments.
 */
function ss_cf7_activate() {
	$unmet = ss_cf7_unmet_requirements();

	if ( empty( $unmet ) ) {
		// Entries table + cron events (other sites of a network install lazily).
		require_once SS_CF7_PATH . 'includes/class-entries.php';
		SS_CF7_Entries::install();
		return;
	}

	deactivate_plugins( SS_CF7_BASENAME );

	wp_die(
		wp_kses_post( implode( '<br>', array_map( 'esc_html', ss_cf7_requirement_messages( $unmet ) ) ) ),
		esc_html__( 'Plugin activation error', 'sheetsync-cf7' ),
		array( 'back_link' => true )
	);
}
register_activation_hook( __FILE__, 'ss_cf7_activate' );

/**
 * Deactivation: stop the scheduled cleanup (data is kept until uninstall).
 */
function ss_cf7_deactivate() {
	wp_clear_scheduled_hook( SS_CF7_CRON_CLEANUP );
	wp_clear_scheduled_hook( SS_CF7_CRON_RETRY );
}
register_deactivation_hook( __FILE__, 'ss_cf7_deactivate' );

/**
 * Loads translations.
 */
function ss_cf7_load_textdomain() {
	load_plugin_textdomain( 'sheetsync-cf7', false, dirname( SS_CF7_BASENAME ) . '/languages' );
}
add_action( 'init', 'ss_cf7_load_textdomain' );

/**
 * Bootstraps the plugin once every plugin has been loaded,
 * so we can reliably detect Contact Form 7.
 */
function ss_cf7_bootstrap() {
	$unmet = ss_cf7_unmet_requirements();

	if ( ! empty( $unmet ) ) {
		add_action(
			'admin_notices',
			function () use ( $unmet ) {
				if ( current_user_can( 'activate_plugins' ) ) {
					ss_cf7_render_error_notice( ss_cf7_requirement_messages( $unmet ) );
				}
			}
		);
		return;
	}

	if ( ! defined( 'WPCF7_VERSION' ) ) {
		add_action( 'admin_notices', 'ss_cf7_missing_cf7_notice' );
		return;
	}

	require_once SS_CF7_PATH . 'includes/class-google-auth.php';
	require_once SS_CF7_PATH . 'includes/class-entries.php';
	require_once SS_CF7_PATH . 'includes/class-cf7-handler.php';
	require_once SS_CF7_PATH . 'includes/class-notifier.php';
	require_once SS_CF7_PATH . 'includes/class-attribution.php';

	if ( is_admin() ) {
		require_once SS_CF7_PATH . 'includes/class-admin-settings.php';
		require_once SS_CF7_PATH . 'includes/class-dashboard.php';
	}

	// Creates/updates the entries table when needed (cheap autoloaded option check).
	SS_CF7_Entries::maybe_install();

	// Re-creates the cron events if something removed them (admin requests only).
	if ( is_admin() ) {
		SS_CF7_Entries::schedule_events();
	}

	SS_CF7_Plugin::instance();
}
add_action( 'plugins_loaded', 'ss_cf7_bootstrap' );

/**
 * Prints a dismissible-less error notice with one or more messages.
 *
 * @param string[] $messages Plain-text messages.
 */
function ss_cf7_render_error_notice( $messages ) {
	echo '<div class="notice notice-error">';
	foreach ( $messages as $message ) {
		echo '<p>' . esc_html( $message ) . '</p>';
	}
	echo '</div>';
}

/**
 * Admin notice shown when Contact Form 7 is missing or inactive,
 * with a one-click "Activate" or "Install" button when the user is allowed to.
 */
function ss_cf7_missing_cf7_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$plugin_file = 'contact-form-7/wp-contact-form-7.php';
	$button      = '';

	if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
		$url    = wp_nonce_url(
			self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $plugin_file ) ),
			'activate-plugin_' . $plugin_file
		);
		$button = sprintf(
			'<a href="%1$s" class="button button-primary">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Activate Contact Form 7', 'sheetsync-cf7' )
		);
	} elseif ( current_user_can( 'install_plugins' ) ) {
		$url    = wp_nonce_url(
			self_admin_url( 'update.php?action=install-plugin&plugin=contact-form-7' ),
			'install-plugin_contact-form-7'
		);
		$button = sprintf(
			'<a href="%1$s" class="button button-primary">%2$s</a>',
			esc_url( $url ),
			esc_html__( 'Install Contact Form 7', 'sheetsync-cf7' )
		);
	}

	printf(
		'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p>%3$s</div>',
		esc_html__( 'SheetSync for CF7', 'sheetsync-cf7' ),
		esc_html__( 'requires Contact Form 7 to be installed and active. The plugin is currently paused.', 'sheetsync-cf7' ),
		$button ? '<p>' . $button . '</p>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	);
}

/**
 * Adds a "Settings" link in the plugins list.
 *
 * @param string[] $links Existing action links.
 * @return string[]
 */
function ss_cf7_action_links( $links ) {
	if ( defined( 'WPCF7_VERSION' ) && current_user_can( 'manage_options' ) ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( ss_cf7_admin_url( 'settings' ) ),
				esc_html__( 'Settings', 'sheetsync-cf7' )
			)
		);
	}
	return $links;
}
add_filter( 'plugin_action_links_' . SS_CF7_BASENAME, 'ss_cf7_action_links' );

/**
 * Writes a debug line to the PHP error log, only when WP_DEBUG is enabled.
 * Never pass tokens, private keys or personal data to this function.
 *
 * @param string|WP_Error $message Message or error object.
 * @param array           $context Optional non-sensitive context (form ID, HTTP status...).
 */
function ss_cf7_log( $message, $context = array() ) {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
		return;
	}

	if ( is_wp_error( $message ) ) {
		$data    = $message->get_error_data();
		$message = $message->get_error_code() . ': ' . $message->get_error_message();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			$context['http_status'] = $data['status'];
		}
	}

	$line = '[SheetSync for CF7] ' . $message;
	if ( ! empty( $context ) ) {
		$line .= ' ' . wp_json_encode( $context );
	}

	error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Main plugin container (singleton). Wires the components together.
 */
final class SS_CF7_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var SS_CF7_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Google auth + Sheets API client.
	 *
	 * @var SS_CF7_Google_Auth
	 */
	public $auth;

	/**
	 * Form submission handler.
	 *
	 * @var SS_CF7_Handler
	 */
	public $handler;

	/**
	 * Local archive of the submissions.
	 *
	 * @var SS_CF7_Entries
	 */
	public $entries;

	/**
	 * Failure email alerts.
	 *
	 * @var SS_CF7_Notifier
	 */
	public $notifier;

	/**
	 * Lead source tracking (UTM / click IDs).
	 *
	 * @var SS_CF7_Attribution
	 */
	public $attribution;

	/**
	 * Admin UI (only in wp-admin / admin-ajax).
	 *
	 * @var SS_CF7_Admin_Settings|null
	 */
	public $admin = null;

	/**
	 * Dashboard tab (only in wp-admin / admin-ajax).
	 *
	 * @var SS_CF7_Dashboard|null
	 */
	public $dashboard = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return SS_CF7_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Builds the components. Constructors only register hooks (no output, no translations).
	 */
	private function __construct() {
		$this->auth     = new SS_CF7_Google_Auth();
		$this->entries  = new SS_CF7_Entries();
		$this->handler  = new SS_CF7_Handler( $this->auth, $this->entries );
		$this->notifier    = new SS_CF7_Notifier( $this->entries );
		$this->attribution = new SS_CF7_Attribution();

		if ( is_admin() ) {
			$this->dashboard = new SS_CF7_Dashboard( $this->entries, $this->handler );
			$this->admin     = new SS_CF7_Admin_Settings( $this->auth, $this->handler, $this->entries, $this->dashboard );
		}
	}

	/**
	 * No cloning.
	 */
	private function __clone() {}
}

/**
 * Shortcut to the plugin container (available after `plugins_loaded`).
 *
 * @return SS_CF7_Plugin
 */
function ss_cf7() {
	return SS_CF7_Plugin::instance();
}
