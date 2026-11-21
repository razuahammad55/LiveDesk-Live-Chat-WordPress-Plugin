<?php
/**
 * Conversation assignment (manual and automatic).
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Assignment_Manager
 */
class Assignment_Manager {

	/**
	 * Assign (or reassign) a conversation to a user, with history logging.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param int    $agent_user_id  Target user ID (0 to unassign).
	 * @param int    $assigned_by    Acting user ID.
	 * @param string $mode           manual|auto.
	 * @return bool|\WP_Error
	 */
	public static function assign( $conversation_id, $agent_user_id, $assigned_by = 0, $mode = 'manual' ) {
		global $wpdb;
		$conversation = Conversation_Manager::get( $conversation_id );
		if ( ! $conversation ) {
			return new \WP_Error( 'livedesk_not_found', __( 'Conversation not found.', 'livedesk' ), array( 'status' => 404 ) );
		}
		$agent_user_id = absint( $agent_user_id );
		if ( $agent_user_id ) {
			$user = get_userdata( $agent_user_id );
			if ( ! $user || ! $user->has_cap( Permissions::CAP_AGENT ) ) {
				return new \WP_Error( 'livedesk_bad_agent', __( 'That user is not a LiveDesk agent.', 'livedesk' ), array( 'status' => 400 ) );
			}
		}

		$from = (int) $conversation->agent_id;

		$wpdb->update(
			Database::table( 'conversations' ),
			array(
				'agent_id'   => $agent_user_id,
				'updated_at' => Utils::now_gmt(),
			),
			array( 'id' => absint( $conversation_id ) ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		$wpdb->insert(
			Database::table( 'assignments' ),
			array(
				'conversation_id' => absint( $conversation_id ),
				'from_agent_id'   => $from,
				'to_agent_id'     => $agent_user_id,
				'assigned_by'     => absint( $assigned_by ),
				'mode'            => in_array( $mode, array( 'auto', 'manual' ), true ) ? $mode : 'manual',
				'created_at'      => Utils::now_gmt(),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		/**
		 * Fires after assignment changes.
		 *
		 * @param int $conversation_id Conversation ID.
		 * @param int $agent_user_id   New agent (0 = none).
		 * @param int $from            Previous agent.
		 */
		do_action( 'livedesk_conversation_assigned', $conversation_id, $agent_user_id, $from );

		return true;
	}

	/**
	 * Automatically pick an agent for a conversation.
	 *
	 * Strategy:
	 *  - round_robin: rotates through enabled+available agents using an option cursor.
	 *  - least_busy:  chooses the available agent with the fewest open conversations.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return int|false Assigned user ID or false when nobody is available.
	 */
	public static function auto_assign( $conversation_id ) {
		if ( 'auto' !== Settings_Manager::get( 'assignment.mode', 'manual' ) ) {
			return false;
		}
		$agents = Agent_Manager::available_agents();
		if ( empty( $agents ) ) {
			return false;
		}

		$strategy = Settings_Manager::get( 'assignment.strategy', 'round_robin' );
		$target   = null;

		if ( 'least_busy' === $strategy ) {
			$best = PHP_INT_MAX;
			foreach ( $agents as $agent ) {
				$load = Agent_Manager::workload( (int) $agent->user_id );
				if ( $load['open'] < $best ) {
					$best   = $load['open'];
					$target = (int) $agent->user_id;
				}
			}
		} else {
			$cursor = (int) get_option( 'livedesk_rr_cursor', 0 );
			$count  = count( $agents );
			$index  = $cursor % $count;
			$target = (int) $agents[ $index ]->user_id;
			update_option( 'livedesk_rr_cursor', ( $cursor + 1 ) % $count, false );
		}

		if ( $target ) {
			self::assign( $conversation_id, $target, 0, 'auto' );
			if ( Settings_Manager::get( 'notifications.notify_assignment', 0 ) ) {
				Notification_Manager::notify_agent_assigned( $conversation_id, $target );
			}
			return $target;
		}
		return false;
	}

	/**
	 * Assignment history for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array
	 */
	public static function history( $conversation_id ) {
		global $wpdb;
		$t = Database::table( 'assignments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE conversation_id = %d ORDER BY id DESC LIMIT 50", absint( $conversation_id ) )
		);
		return is_array( $rows ) ? $rows : array();
	}
}
