<?php
/**
 * WP-Cron jobs: retention cleanup + notification + auto archive.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Cron_Manager
 */
class Cron_Manager {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'livedesk_cron_maintenance', array( $this, 'run_maintenance' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ), 5 );
	}

	/**
	 * Schedule the event if missing (protects against disabling/reactivation).
	 *
	 * @return void
	 */
	public function maybe_schedule() {
		if ( ! wp_next_scheduled( 'livedesk_cron_maintenance' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'livedesk_cron_maintenance' );
		}
	}

	/**
	 * Hourly maintenance run.
	 *
	 * @return void
	 */
	public function run_maintenance() {
		$this->auto_archive();
		$this->retention_cleanup();
	}

	/**
	 * Archive inactive conversations after 30 days (filterable).
	 *
	 * @return void
	 */
	private function auto_archive() {
		$archived = Conversation_Manager::auto_archive_inactive();
		if ( $archived ) {
			Logger::log( 'auto_archived', array( 'count' => $archived ) );
		}
	}

	/**
	 * Delete archived conversations older than the retention window.
	 *
	 * Runs in small batches (20 per run) to stay gentle on shared hosting.
	 *
	 * @return void
	 */
	private function retention_cleanup() {
		global $wpdb;

		if ( ! Settings_Manager::get( 'retention.auto_delete', 1 ) ) {
			return;
		}
		$days = (int) Settings_Manager::get( 'retention.archived_days', 180 );
		if ( $days <= 0 ) {
			return;
		}

		$c         = Database::table( 'conversations' );
		$v         = Database::table( 'visitors' );
		$delete_at = time() - ( $days * DAY_IN_SECONDS );
		$warn_at   = $delete_at + ( (int) Settings_Manager::get( 'retention.notify_before_days', 7 ) * DAY_IN_SECONDS );

		// Upcoming deletions (notification at most once per day).
		$notify_days = (int) Settings_Manager::get( 'retention.notify_before_days', 7 );
		if ( $notify_days > 0 && ! get_transient( 'ld_retention_notified' ) ) {
			$upcoming_cutoff = gmdate( 'Y-m-d H:i:s', $warn_at );
			$soon_cutoff     = gmdate( 'Y-m-d H:i:s', $delete_at + 86400 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$upcoming = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.id, c.ticket_ref, v.name AS visitor_name, v.email AS visitor_email
					 FROM {$c} c LEFT JOIN {$v} v ON v.id = c.visitor_id
					 WHERE c.status = 'archived' AND c.archived_at IS NOT NULL AND c.archived_at > %s AND c.archived_at <= %s
					 LIMIT 100",
					$upcoming_cutoff,
					$soon_cutoff
				)
			);
			if ( $upcoming ) {
				Notification_Manager::notify_retention_upcoming( $upcoming );
			}
			set_transient( 'ld_retention_notified', 1, DAY_IN_SECONDS );
		}

		// Deletion batch.
		$cutoff = gmdate( 'Y-m-d H:i:s', $delete_at );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$c} WHERE status = 'archived' AND archived_at IS NOT NULL AND archived_at < %s LIMIT 20",
				$cutoff
			)
		);
		$delete_files = (bool) Settings_Manager::get( 'retention.delete_attachments', 1 );
		foreach ( (array) $ids as $id ) {
			Conversation_Manager::delete( (int) $id, $delete_files );
		}
		if ( $ids ) {
			Logger::log( 'retention_deleted', array( 'count' => count( $ids ) ) );
		}
	}
}
