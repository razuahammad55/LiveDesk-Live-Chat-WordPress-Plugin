<?php
/**
 * Database schema and migrations.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Database
 */
class Database {

	/**
	 * Table name helper. Suffix is a logical name: visitors, conversations,
	 * messages, attachments, notes, canned, agents, assignments.
	 *
	 * @param string $name Logical table name.
	 * @return string Full prefixed table name.
	 */
	public static function table( $name ) {
		global $wpdb;
		$allowed = array( 'visitors', 'conversations', 'messages', 'attachments', 'notes', 'canned', 'agents', 'assignments' );
		if ( ! in_array( $name, $allowed, true ) ) {
			return $wpdb->prefix . 'ld_' . $name;
		}
		return $wpdb->prefix . 'ld_' . $name;
	}

	/**
	 * Create or upgrade schema using dbDelta (safe on update, never destroys data).
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$t       = self::table( 'visitors' );
		$c       = self::table( 'conversations' );
		$m       = self::table( 'messages' );
		$att     = self::table( 'attachments' );
		$n       = self::table( 'notes' );
		$can     = self::table( 'canned' );
		$ag      = self::table( 'agents' );
		$asg     = self::table( 'assignments' );

		$sql = array();

		$sql[] = "CREATE TABLE {$t} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			token_hash CHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			welcome_sent CHAR(1) NOT NULL DEFAULT '0',
			admin_blocked CHAR(1) NOT NULL DEFAULT '0',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_activity DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_ip VARBINARY(45) NOT NULL DEFAULT '',
			browser VARCHAR(100) NOT NULL DEFAULT '',
			os VARCHAR(100) NOT NULL DEFAULT '',
			device VARCHAR(20) NOT NULL DEFAULT '',
			user_agent VARCHAR(500) NOT NULL DEFAULT '',
			landing_page VARCHAR(500) NOT NULL DEFAULT '',
			current_page VARCHAR(500) NOT NULL DEFAULT '',
			vacation_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY token_hash (token_hash),
			KEY last_activity (last_activity)
		) {$charset};";

		$sql[] = "CREATE TABLE {$c} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			visitor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			admin_unread INT UNSIGNED NOT NULL DEFAULT 0,
			visitor_unread INT UNSIGNED NOT NULL DEFAULT 0,
			visitor_last_read_message BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ticket_ref VARCHAR(24) NOT NULL DEFAULT '',
			message_count INT UNSIGNED NOT NULL DEFAULT 0,
			archived_from VARCHAR(20) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			last_message_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			archived_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY visitor_id (visitor_id),
			KEY status (status),
			KEY agent_id (agent_id),
			KEY last_message_at (last_message_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$m} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sender_type VARCHAR(10) NOT NULL DEFAULT 'visitor',
			sender_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			body LONGTEXT NOT NULL,
			client_uuid VARCHAR(36) NOT NULL DEFAULT '',
			is_read CHAR(1) NOT NULL DEFAULT '0',
			read_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY conv_created (conversation_id, id),
			KEY client_uuid (client_uuid),
			KEY created_at (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$att} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			original_name VARCHAR(255) NOT NULL DEFAULT '',
			stored_name VARCHAR(255) NOT NULL DEFAULT '',
			mime_type VARCHAR(120) NOT NULL DEFAULT '',
			file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY message_id (message_id),
			KEY conversation_id (conversation_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$n} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			content TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$can} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(190) NOT NULL DEFAULT '',
			content LONGTEXT NOT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY updated_at (updated_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$ag} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			enabled CHAR(1) NOT NULL DEFAULT '1',
			availability VARCHAR(20) NOT NULL DEFAULT 'available',
			added_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY enabled (enabled)
		) {$charset};";

		$sql[] = "CREATE TABLE {$asg} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			from_agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			to_agent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			assigned_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			mode VARCHAR(20) NOT NULL DEFAULT 'manual',
			created_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00',
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY created_at (created_at)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		if ( false === get_option( 'livedesk_settings', false ) ) {
			add_option( 'livedesk_settings', Settings_Manager::defaults(), '', false );
		}
		add_option( 'livedesk_db_version', LIVEDESK_DB_VERSION, '', false );
	}

	/**
	 * Drop everything (uninstall only).
	 *
	 * @return void
	 */
	public static function drop_all() {
		global $wpdb;
		foreach ( array( 'assignments', 'canned', 'agents', 'notes', 'attachments', 'messages', 'conversations', 'visitors' ) as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
