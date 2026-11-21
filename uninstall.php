<?php
/**
 * Uninstall handler.
 *
 * Runs only when the plugin is deleted from the Plugins screen. Data is kept
 * by default; administrators can opt in to full removal by defining
 * LIVEDESK_REMOVE_DATA_ON_UNINSTALL true in wp-config.php.
 *
 * @package LiveDesk
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove plugin data.
 *
 * @return void
 */
function livedesk_uninstall_cleanup() {
	global $wpdb;

	// Options.
	$options = array(
		'livedesk_settings',
		'livedesk_db_version',
		'livedesk_caps_version',
		'livedesk_installed_at',
		'livedesk_rr_cursor',
		'livedesk_token_expiry_days',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Transients used by the plugin.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ld\_%' OR option_name LIKE '_transient_timeout_ld\_%'"
	);

	// Capabilities.
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		$admin->remove_cap( 'livedesk_agent' );
		$admin->remove_cap( 'livedesk_manage' );
		$admin->remove_cap( 'livedesk_settings' );
	}
	$agents = get_users( array( 'capability' => 'livedesk_agent', 'fields' => array( 'ID' ) ) );
	foreach ( (array) $agents as $agent ) {
		$user = get_userdata( (int) $agent->ID );
		if ( $user ) {
			$user->remove_cap( 'livedesk_agent' );
		}
	}

	// Tables.
	foreach ( array( 'assignments', 'canned', 'agents', 'notes', 'attachments', 'messages', 'conversations', 'visitors' ) as $name ) {
		$table = $wpdb->prefix . 'ld_' . $name;
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	// Attachment files.
	$uploads = wp_get_upload_dir();
	$root    = trailingslashit( $uploads['basedir'] ) . 'livedesk-private';
	if ( is_dir( $root ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} else {
				@unlink( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		@rmdir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	// Cron.
	wp_clear_scheduled_hook( 'livedesk_cron_maintenance' );
}

$livedesk_remove = defined( 'LIVEDESK_REMOVE_DATA_ON_UNINSTALL' ) && LIVEDESK_REMOVE_DATA_ON_UNINSTALL;

/**
 * Allow administrators to opt in to full removal via a filter too.
 */
$livedesk_remove = (bool) apply_filters( 'livedesk_remove_data_on_uninstall', $livedesk_remove );

if ( $livedesk_remove ) {
	livedesk_uninstall_cleanup();
}
