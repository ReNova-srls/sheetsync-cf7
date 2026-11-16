<?php
/**
 * Email alerts when a submission cannot be sent to Google Sheets.
 *
 * Policy (keeps the inbox quiet):
 * - a failure on submission sends an alert (saying whether it will be retried);
 * - a failed automatic retry sends an alert only when it was the last attempt;
 * - a failed manual retry from the dashboard never sends one (the admin sees it);
 * - at most one alert per hour (filter `ss_cf7_alert_interval`).
 *
 * The email never contains the submitted data: only form, error and links.
 *
 * @package SheetSync_CF7
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class SS_CF7_Notifier
 */
final class SS_CF7_Notifier {

	/**
	 * Local archive.
	 *
	 * @var SS_CF7_Entries
	 */
	private SS_CF7_Entries $entries;

	/**
	 * Registers hooks only.
	 *
	 * @param SS_CF7_Entries $entries Local archive.
	 */
	public function __construct( SS_CF7_Entries $entries ) {
		$this->entries = $entries;

		add_action( 'ss_cf7_delivery_failed', array( $this, 'on_failure' ), 10, 2 );
	}

	/**
	 * Handles a failed delivery attempt.
	 *
	 * @param WP_Error $error   Error.
	 * @param array    $context {form_id, entry_id, source, attempts}.
	 * @return bool Whether an email was sent.
	 */
	public function on_failure( $error, $context ): bool {
		if ( ! $error instanceof WP_Error || ! is_array( $context ) ) {
			return false;
		}

		$options = SS_CF7_Entries::options();
		$source  = (string) ( $context['source'] ?? 'submission' );

		if ( ! $options['alert_enabled'] || 'manual' === $source ) {
			return false;
		}

		$will_retry = self::will_retry( $context, $options );

		// Intermediate automatic retries stay silent: only the last one alerts.
		if ( 'cron' === $source && $will_retry ) {
			return false;
		}

		if ( false !== get_transient( SS_CF7_TRANSIENT_ALERT ) ) {
			return false;
		}

		$message = $this->build_message( $error, $context, $options, $will_retry );
		$sent    = self::send( $options, $message['subject'], $message['body'] );

		if ( $sent ) {
			/**
			 * Minimum time between two failure alerts, in seconds.
			 *
			 * @param int $interval Default one hour.
			 */
			$interval = max( 60, (int) apply_filters( 'ss_cf7_alert_interval', HOUR_IN_SECONDS ) );
			set_transient( SS_CF7_TRANSIENT_ALERT, time(), $interval );
		}

		return $sent;
	}

	/**
	 * Whether the automatic retry will send the entry again.
	 *
	 * @param array $context Failure context.
	 * @param array $options General options.
	 */
	public static function will_retry( array $context, array $options ): bool {
		return ! empty( $context['entry_id'] )
			&& $options['store_entries']
			&& $options['auto_retry']
			&& (int) ( $context['attempts'] ?? 0 ) < $options['max_attempts'];
	}

	/**
	 * Recipients of the alerts: the configured list or the site admin email.
	 *
	 * @param array|null $options General options (default: the saved ones).
	 * @return string[]
	 */
	public static function recipients( ?array $options = null ): array {
		$options = $options ?? SS_CF7_Entries::options();
		$list    = '' !== $options['alert_email'] ? $options['alert_email'] : (string) get_option( 'admin_email' );

		return array_values( array_filter( array_map( 'trim', explode( ',', $list ) ), 'is_email' ) );
	}

	/**
	 * Sends a test alert to the configured recipients.
	 *
	 * @return bool Whether wp_mail() accepted the message.
	 */
	public static function send_test(): bool {
		$options = SS_CF7_Entries::options();

		$body = implode(
			"\n",
			array(
				__( 'This is a test email from SheetSync for CF7.', 'sheetsync-cf7' ),
				'',
				__( 'If you are reading it, the failure alerts will reach this address.', 'sheetsync-cf7' ),
				'',
				__( 'Settings:', 'sheetsync-cf7' ) . ' ' . ss_cf7_admin_url( 'settings' ),
			)
		);

		return self::send( $options, __( 'Test alert', 'sheetsync-cf7' ), $body );
	}

	/**
	 * Builds subject and body of a failure alert (no personal data).
	 *
	 * @param WP_Error $error      Error.
	 * @param array    $context    Failure context.
	 * @param array    $options    General options.
	 * @param bool     $will_retry Whether an automatic retry is planned.
	 * @return array{subject: string, body: string}
	 */
	public function build_message( WP_Error $error, array $context, array $options, bool $will_retry ): array {
		$form_id  = (int) ( $context['form_id'] ?? 0 );
		$attempts = (int) ( $context['attempts'] ?? 1 );
		$lines    = array(
			/* translators: %s: form title. */
			sprintf( __( 'A submission of the form "%s" could not be sent to Google Sheets.', 'sheetsync-cf7' ), self::form_title( $form_id ) ),
			'',
			__( 'Error:', 'sheetsync-cf7' ) . ' ' . $error->get_error_message(),
			__( 'Date:', 'sheetsync-cf7' ) . ' ' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() ),
			'',
		);

		if ( empty( $context['entry_id'] ) ) {
			$lines[] = __( 'The entries archive is disabled, so this submission cannot be sent again. Its data is still in the Contact Form 7 notification email.', 'sheetsync-cf7' );
		} elseif ( $will_retry ) {
			/* translators: 1: attempts made so far, 2: maximum attempts. */
			$lines[] = sprintf( __( 'SheetSync for CF7 will try again automatically (attempt %1$d of %2$d). You will receive another email only if the last attempt fails too.', 'sheetsync-cf7' ), $attempts, $options['max_attempts'] );
		} elseif ( $options['auto_retry'] ) {
			/* translators: %d: number of attempts. */
			$lines[] = sprintf( _n( 'Automatic retries stopped after %d attempt. Fix the problem, then send it again from the dashboard.', 'Automatic retries stopped after %d attempts. Fix the problem, then send it again from the dashboard.', $attempts, 'sheetsync-cf7' ), $attempts );
		} else {
			$lines[] = __( 'Automatic retry is disabled: fix the problem, then send it again from the dashboard.', 'sheetsync-cf7' );
		}

		if ( $options['store_entries'] ) {
			$failed = $this->entries->count_failed();
			if ( $failed > 0 ) {
				/* translators: %d: number of entries in error. */
				$lines[] = sprintf( _n( '%d submission is currently in error.', '%d submissions are currently in error.', $failed, 'sheetsync-cf7' ), $failed );
			}
			$lines[] = __( 'Dashboard:', 'sheetsync-cf7' ) . ' ' . ss_cf7_admin_url( 'dashboard', array( 'status' => SS_CF7_Entries::STATUS_FAILED ) );
		}

		$lines[] = '';
		$lines[] = __( 'Common causes: the spreadsheet is no longer shared with the Service Account, the tab or a header was renamed, the key was revoked, or Google is temporarily unavailable.', 'sheetsync-cf7' );
		$lines[] = __( 'Further failures in the next hour will not send another email.', 'sheetsync-cf7' );
		/* translators: %s: URL of the settings page. */
		$lines[] = sprintf( __( 'Change or turn off these alerts: %s', 'sheetsync-cf7' ), ss_cf7_admin_url( 'settings' ) );

		return array(
			'subject' => $will_retry
				? __( 'A submission could not be sent to Google Sheets', 'sheetsync-cf7' )
				: __( 'A submission was not sent to Google Sheets', 'sheetsync-cf7' ),
			'body'    => implode( "\n", $lines ),
		);
	}

	/**
	 * Sends a plain-text email to the alert recipients.
	 *
	 * @param array  $options General options.
	 * @param string $subject Subject (the site name is prepended).
	 * @param string $body    Plain-text body.
	 */
	private static function send( array $options, string $subject, string $body ): bool {
		$to = self::recipients( $options );
		if ( empty( $to ) ) {
			return false;
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		return (bool) wp_mail(
			$to,
			sprintf( '[%1$s] SheetSync for CF7: %2$s', $site, $subject ),
			$body,
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
	}

	/**
	 * Title of a CF7 form.
	 *
	 * @param int $form_id Form ID.
	 */
	private static function form_title( int $form_id ): string {
		$form = function_exists( 'wpcf7_contact_form' ) ? wpcf7_contact_form( $form_id ) : null;

		if ( $form instanceof WPCF7_ContactForm && '' !== (string) $form->title() ) {
			return (string) $form->title();
		}

		/* translators: %d: form ID. */
		return sprintf( __( 'Form #%d', 'sheetsync-cf7' ), $form_id );
	}
}
