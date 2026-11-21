<?php
/**
 * Capabilities, roles and access helpers.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Permissions
 *
 * Agents use existing WordPress users with a dedicated, narrow capability set.
 * Admins reuse WordPress's `manage_options` plus the plugin capabilities, which
 * we grant to the administrator role on activation.
 */
class Permissions {

	const CAP_AGENT  = 'livedesk_agent';
	const CAP_MANAGE = 'livedesk_manage';
	const CAP_SETTINGS = 'livedesk_settings';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'init', array( 'LiveDesk\\Permissions', 'maybe_upgrade_caps' ), 20 );
	}

	/**
	 * Register capabilities once.
	 *
	 * @return void
	 */
	public static function add_roles_and_caps() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_AGENT );
			$admin->add_cap( self::CAP_MANAGE );
			$admin->add_cap( self::CAP_SETTINGS );
		}
		update_option( 'livedesk_caps_version', '1', false );
	}

	/**
	 * Ensure caps exist on upgrade (e.g. plugin updated without re-activation).
	 *
	 * @return void
	 */
	public static function maybe_upgrade_caps() {
		if ( get_option( 'livedesk_caps_version' ) !== '1' ) {
			self::add_roles_and_caps();
		}
	}

	/**
	 * Remove plugin caps (uninstall).
	 *
	 * @return void
	 */
	public static function remove_caps() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( self::CAP_AGENT );
			$admin->remove_cap( self::CAP_MANAGE );
			$admin->remove_cap( self::CAP_SETTINGS );
		}
	}

	/**
	 * Is current user a LiveDesk agent (includes admins)?
	 *
	 * @return bool
	 */
	public static function is_agent() {
		return is_user_logged_in() && current_user_can( self::CAP_AGENT );
	}

	/**
	 * Can the current user see all conversations (admins)?
	 *
	 * @return bool
	 */
	public static function can_see_all() {
		return current_user_can( self::CAP_MANAGE );
	}

	/**
	 * Whether the current user is an enabled row in the agents table.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_active_agent( $user_id ) {
		global $wpdb;
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return false;
		}
		foreach ( Agent_Manager::enabled_agents() as $agent ) {
			if ( (int) $agent->user_id === $user_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Can the given user access the given conversation?
	 *
	 * Rules:
	 *  - Admins (livedesk_manage) always can.
	 *  - Agents can access conversations assigned to them.
	 *  - If `agents_see_unassigned` is enabled, agents can also access unassigned conversations.
	 *
	 * @param object $conversation Conversation row.
	 * @param int    $user_id      User ID (default current user).
	 * @return bool
	 */
	public static function can_access_conversation( $conversation, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id || ! $conversation ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		if ( $user->has_cap( self::CAP_MANAGE ) ) {
			return true;
		}
		if ( ! $user->has_cap( self::CAP_AGENT ) ) {
			return false;
		}
		$assigned = (int) $conversation->agent_id;
		if ( $assigned && $assigned !== (int) $user_id ) {
			return false;
		}
		if ( ! $assigned ) {
			return (bool) Settings_Manager::get( 'assignment.agents_see_unassigned', 1 );
		}
		return true;
	}

	/**
	 * Can a reply be sent by the current user to this conversation?
	 *
	 * @param object $conversation Conversation row.
	 * @return bool
	 */
	public static function can_reply( $conversation ) {
		return self::can_access_conversation( $conversation ) && current_user_can( self::CAP_AGENT );
	}

	/**
	 * Resolve a display name for an agent user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $mode    first_name|display_name.
	 * @return string
	 */
	public static function agent_display_name( $user_id, $mode = '' ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return __( 'Support', 'livedesk' );
		}
		$mode = $mode ? $mode : Settings_Manager::get( 'general.agent_display', 'first_name' );
		if ( 'display_name' === $mode ) {
			return $user->display_name;
		}
		return $user->first_name ? $user->first_name : $user->display_name;
	}
}
