<?php
/**
 * Visitor identity and lifecycle.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Visitor_Manager
 */
class Visitor_Manager {

	/**
	 * Find a visitor row by raw token.
	 *
	 * @param string $token Raw token.
	 * @return object|null
	 */
	public static function find_by_token( $token ) {
		global $wpdb;
		$token = preg_replace( '/[^a-f0-9]/i', '', (string) $token );
		if ( strlen( $token ) !== 64 ) {
			return null;
		}
		$table = Database::table( 'visitors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1", Security::hash_token( $token ) ) );
	}

	/**
	 * Find a visitor row by email.
	 *
	 * @param string $email Email.
	 * @return object|null
	 */
	public static function find_by_email( $email ) {
		global $wpdb;
		$table = Database::table( 'visitors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id DESC LIMIT 1", $email ) );
	}

	/**
	 * Get by ID.
	 *
	 * @param int $id Visitor ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Database::table( 'visitors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * Create a visitor and return array( visitor row, raw token ).
	 *
	 * @param string $name         Validated name.
	 * @param string $email        Validated email.
	 * @param array  $request_meta Optional meta: page, ip, ua.
	 * @return array|\WP_Error
	 */
	public static function create( $name, $email, $request_meta = array() ) {
		global $wpdb;

		$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 500 ) : '';
		$parsed = Utils::parse_user_agent( $ua );
		$now    = Utils::now_gmt();

		$token = Security::generate_token();
		$data  = array(
			'name'          => $name,
			'email'         => $email,
			'token_hash'    => Security::hash_token( $token ),
			'user_id'       => get_current_user_id(),
			'created_at'    => $now,
			'last_activity' => $now,
			'last_ip'       => Security::client_ip(),
			'browser'       => $parsed['browser'],
			'os'            => $parsed['os'],
			'device'        => $parsed['device'],
			'user_agent'    => $ua,
			'landing_page'  => isset( $request_meta['page'] ) ? esc_url_raw( Utils::clip( $request_meta['page'], 500 ) ) : '',
			'current_page'  => isset( $request_meta['page'] ) ? esc_url_raw( Utils::clip( $request_meta['page'], 500 ) ) : '',
		);

		$formats = array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
		$ok      = $wpdb->insert( Database::table( 'visitors' ), $data, $formats );
		if ( ! $ok ) {
			return new \WP_Error( 'livedesk_db_error', __( 'Could not start the chat. Please try again.', 'livedesk' ), array( 'status' => 500 ) );
		}

		$visitor = self::get( (int) $wpdb->insert_id );
		return array(
			'visitor' => $visitor,
			'token'   => $token,
		);
	}

	/**
	 * Update activity + current page.
	 *
	 * @param int    $visitor_id Visitor ID.
	 * @param string $page       Current page URL.
	 * @return void
	 */
	public static function touch( $visitor_id, $page = '' ) {
		global $wpdb;
		$data  = array( 'last_activity' => Utils::now_gmt() );
		$fmt   = array( '%s' );
		$page  = esc_url_raw( Utils::clip( (string) $page, 500 ) );
		if ( '' !== $page ) {
			$data['current_page'] = $page;
			$fmt[]                = '%s';
		}
		$wpdb->update( Database::table( 'visitors' ), $data, array( 'id' => absint( $visitor_id ) ), $fmt, array( '%d' ) );
	}

	/**
	 * Update stored contact details (used when a returning visitor re-identifies).
	 *
	 * @param int    $visitor_id Visitor ID.
	 * @param string $name       Name.
	 * @param string $email      Email.
	 * @return void
	 */
	public static function update_identity( $visitor_id, $name, $email ) {
		global $wpdb;
		$wpdb->update(
			Database::table( 'visitors' ),
			array(
				'name'  => $name,
				'email' => $email,
			),
			array( 'id' => absint( $visitor_id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Rotate the token (issuing a fresh raw token).
	 *
	 * @param int $visitor_id Visitor ID.
	 * @return string New raw token.
	 */
	public static function rotate_token( $visitor_id ) {
		global $wpdb;
		$token = Security::generate_token();
		$wpdb->update(
			Database::table( 'visitors' ),
			array( 'token_hash' => Security::hash_token( $token ) ),
			array( 'id' => absint( $visitor_id ) ),
			array( '%s' ),
			array( '%d' )
		);
		return $token;
	}

	/**
	 * Flag that the automated welcome has been delivered for this visitor.
	 *
	 * @param int $visitor_id Visitor ID.
	 * @return void
	 */
	public static function mark_welcome_sent( $visitor_id ) {
		global $wpdb;
		$wpdb->update(
			Database::table( 'visitors' ),
			array( 'welcome_sent' => '1' ),
			array( 'id' => absint( $visitor_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Invalidate a visitor's token so the session cannot be resumed.
	 *
	 * Used when a session exceeds its lifetime (server-side backstop for the
	 * browser-session storage) and when a visitor explicitly ends the chat.
	 *
	 * @param int $visitor_id Visitor ID.
	 * @return void
	 */
	public static function expire_token( $visitor_id ) {
		global $wpdb;
		$wpdb->update(
			Database::table( 'visitors' ),
			array( 'token_hash' => '' ),
			array( 'id' => absint( $visitor_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Visitor being shown typing in a conversation (agent-side).
	 *
	 * @param int $visitor_id Visitor ID.
	 * @param int $conversation_id Conversation ID.
	 * @return void
	 */
	public static function set_typing( $visitor_id, $conversation_id ) {
		if ( ! Settings_Manager::get( 'realtime.typing_enabled', 1 ) ) {
			return;
		}
		set_transient( 'ld_typing_v_' . absint( $conversation_id ), absint( $visitor_id ), 8 );
	}

	/**
	 * Whether the visitor is typing (agent view).
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool
	 */
	public static function is_typing( $conversation_id ) {
		return (bool) get_transient( 'ld_typing_v_' . absint( $conversation_id ) );
	}

	/**
	 * Record that an agent is typing in a conversation (short-lived transient).
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $user_id         Agent user ID.
	 * @return void
	 */
	public static function set_agent_typing( $conversation_id, $user_id ) {
		if ( ! Settings_Manager::get( 'realtime.typing_enabled', 1 ) ) {
			return;
		}
		set_transient( 'ld_typing_a_' . absint( $conversation_id ), absint( $user_id ), 8 );
	}

	/**
	 * Whether an agent is typing in the conversation (visitor view).
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool
	 */
	public static function is_typing_agent( $conversation_id ) {
		return (bool) get_transient( 'ld_typing_a_' . absint( $conversation_id ) );
	}

	/**
	 * Count visitors.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = Database::table( 'visitors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
