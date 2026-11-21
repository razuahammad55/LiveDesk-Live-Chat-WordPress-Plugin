<?php
/**
 * Agent roster and availability.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Agent_Manager
 */
class Agent_Manager {

	/**
	 * All agent rows joined with user data.
	 *
	 * @param bool $enabled_only Only enabled agents.
	 * @return array
	 */
	public static function all( $enabled_only = false ) {
		global $wpdb;
		$t      = Database::table( 'agents' );
		$sql    = "SELECT a.*, u.display_name, u.user_email, u.user_login FROM {$t} a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id";
		$params = array();
		if ( $enabled_only ) {
			$sql .= ' WHERE a.enabled = %s';
			$params[] = '1';
		}
		$sql .= ' ORDER BY a.id ASC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Enabled agents that are marked available.
	 *
	 * @return array
	 */
	public static function enabled_agents() {
		global $wpdb;
		$t = Database::table( 'agents' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE enabled = %s", '1' ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Available agents (enabled + availability=available + user exists).
	 *
	 * @return array
	 */
	public static function available_agents() {
		$agents = array();
		foreach ( self::enabled_agents() as $agent ) {
			if ( 'available' !== $agent->availability ) {
				continue;
			}
			if ( ! get_userdata( (int) $agent->user_id ) ) {
				continue;
			}
			$agents[] = $agent;
		}
		return $agents;
	}

	/**
	 * Get agent row by user ID.
	 *
	 * @param int $user_id User ID.
	 * @return object|null
	 */
	public static function get_by_user( $user_id ) {
		global $wpdb;
		$t = Database::table( 'agents' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d", absint( $user_id ) ) );
	}

	/**
	 * Add an existing WP user as agent (grants cap).
	 *
	 * @param int $user_id User ID.
	 * @return true|\WP_Error
	 */
	public static function add( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'livedesk_bad_user', __( 'User not found.', 'livedesk' ), array( 'status' => 404 ) );
		}
		if ( ! user_can( $user, 'edit_posts' ) && ! user_can( $user, 'read' ) ) {
			return new \WP_Error( 'livedesk_bad_user', __( 'This user cannot be used as an agent.', 'livedesk' ), array( 'status' => 400 ) );
		}
		$user->add_cap( Permissions::CAP_AGENT );

		$existing = self::get_by_user( $user_id );
		$now      = Utils::now_gmt();
		if ( $existing ) {
			$wpdb->update(
				Database::table( 'agents' ),
				array(
					'enabled'    => '1',
					'updated_at' => $now,
				),
				array( 'user_id' => $user_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return true;
		}
		$wpdb->insert(
			Database::table( 'agents' ),
			array(
				'user_id'      => $user_id,
				'enabled'      => '1',
				'availability' => 'available',
				'added_at'     => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
		return true;
	}

	/**
	 * Remove the agent row (does not delete the WP user; caps removed).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function remove( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		$wpdb->delete( Database::table( 'agents' ), array( 'user_id' => $user_id ), array( '%d' ) );
		$user = get_userdata( $user_id );
		if ( $user && ! $user->has_cap( 'manage_options' ) ) {
			$user->remove_cap( Permissions::CAP_AGENT );
		}
		// Unassign open conversations.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . Database::table( 'conversations' ) . " SET agent_id = 0 WHERE agent_id = %d AND status != 'archived'",
				$user_id
			)
		);
		return true;
	}

	/**
	 * Enable/disable agent.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $enabled Enabled flag.
	 * @return bool
	 */
	public static function set_enabled( $user_id, $enabled ) {
		global $wpdb;
		$wpdb->update(
			Database::table( 'agents' ),
			array(
				'enabled'    => $enabled ? '1' : '0',
				'updated_at' => Utils::now_gmt(),
			),
			array( 'user_id' => absint( $user_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * Set availability.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $availability available|away.
	 * @return bool
	 */
	public static function set_availability( $user_id, $availability ) {
		global $wpdb;
		$availability = in_array( $availability, array( 'available', 'away' ), true ) ? $availability : 'away';
		$wpdb->update(
			Database::table( 'agents' ),
			array(
				'availability' => $availability,
				'updated_at'   => Utils::now_gmt(),
			),
			array( 'user_id' => absint( $user_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * Capacity snapshot for assignment strategies.
	 *
	 * @param int $user_id Agent user ID.
	 * @return array
	 */
	public static function workload( $user_id ) {
		global $wpdb;
		$c = Database::table( 'conversations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$open = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$c} WHERE agent_id = %d AND status != 'archived'",
				absint( $user_id )
			)
		);
		return array( 'open' => $open );
	}
}
