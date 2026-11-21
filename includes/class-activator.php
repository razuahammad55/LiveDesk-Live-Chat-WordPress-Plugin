<?php
/**
 * Activation routine.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Activator
 */
class Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		Database::install();
		update_option( 'livedesk_db_version', LIVEDESK_DB_VERSION, false );

		Permissions::add_roles_and_caps();

		// Attachment storage.
		Attachment_Manager::ensure_storage();

		// Seed starter canned responses (only once, never overwrites user data).
		self::maybe_seed_canned();

		// Schedule cron events.
		if ( ! wp_next_scheduled( 'livedesk_cron_maintenance' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'livedesk_cron_maintenance' );
		}

		// Flush rewrite rules (REST endpoints use plain links; safe to flush).
		flush_rewrite_rules();

		if ( false === get_option( 'livedesk_installed_at', false ) ) {
			add_option( 'livedesk_installed_at', current_time( 'mysql', true ), '', false );
		}

		set_transient( 'livedesk_activation_notice', 1, 60 );
	}

	/**
	 * Seed an initial welcome canned response.
	 *
	 * @return void
	 */
	public static function maybe_seed_canned() {
		global $wpdb;
		$table = Database::table( 'canned' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $count > 0 ) {
			return;
		}
		$wpdb->insert(
			$table,
			array(
				'title'      => __( 'Greeting', 'livedesk' ),
				'content'    => __( 'Hi! Thanks for reaching out. How can we help you today?', 'livedesk' ),
				'created_by' => get_current_user_id(),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
		$wpdb->insert(
			$table,
			array(
				'title'      => __( 'Checking for you', 'livedesk' ),
				'content'    => __( 'Thanks for your patience — I am checking on this and will get back to you shortly.', 'livedesk' ),
				'created_by' => get_current_user_id(),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s' )
		);
	}
}
