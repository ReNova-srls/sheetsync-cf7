<?php
/**
 * Uninstall routine for SheetSync for CF7.
 *
 * Runs only when the plugin is deleted from the Plugins screen. The main plugin
 * file is NOT loaded here, so storage keys are intentionally hard-coded and must
 * match the constants defined in sheetsync-cf7.php.
 *
 * @package SheetSync_CF7
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes every option, transient and post meta created by the plugin on the current site.
 */
function ss_cf7_uninstall_site() {
	global $wpdb;

	$meta_key = '_ss_cf7_settings';

	// 1) Per-form header-cache transients. Deleted through the API so that
	// persistent object caches (Redis, Memcached...) are purged as well.
	$form_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
			$meta_key
		)
	);

	foreach ( (array) $form_ids as $form_id ) {
		delete_transient( 'ss_cf7_hdr_' . absint( $form_id ) );
	}

	// 2) Encrypted Service Account credentials and general options.
	delete_option( 'ss_cf7_credentials' );
	delete_option( 'ss_cf7_options' );
	delete_option( 'ss_cf7_tracking' );
	delete_option( 'ss_cf7_format' );
	delete_option( 'ss_cf7_db_version' );

	// 2b) Entries archive (local copy of the submissions) and its cron events.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ss_cf7_entries' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	wp_clear_scheduled_hook( 'ss_cf7_daily_cleanup' );
	wp_clear_scheduled_hook( 'ss_cf7_retry_failed' );

	// 3) Cached OAuth2 access token and alert throttle.
	delete_transient( 'ss_cf7_access_token' );
	delete_transient( 'ss_cf7_alert_lock' );

	// 4) Per-form settings stored on Contact Form 7 forms.
	delete_post_meta_by_key( $meta_key );

	// 5) Safety net: any leftover transient with our prefix in the options table.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_ss_cf7_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ss_cf7_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	$ss_cf7_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ss_cf7_site_ids as $ss_cf7_site_id ) {
		switch_to_blog( $ss_cf7_site_id );
		ss_cf7_uninstall_site();
		restore_current_blog();
	}
} else {
	ss_cf7_uninstall_site();
}
