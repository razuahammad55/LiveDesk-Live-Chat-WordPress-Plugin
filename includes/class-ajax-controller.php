<?php
/**
 * Admin-side AJAX actions (inbox actions, agent management, canned replies).
 *
 * All handlers verify: nonce (wp_ajax), capability, and conversation access
 * server-side. Error messages are generic to avoid leaking data.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class AJAX_Controller
 */
class AJAX_Controller {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		$actions = array(
			'conversation_assign',
			'conversation_status',
			'conversation_delete',
			'conversation_mark_read',
			'agent_availability',
			'agent_toggle',
			'agent_add',
			'agent_remove',
			'canned_save',
			'canned_delete',
			'note_delete',
			'send_test_email',
			'visitor_delete',
		);
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_livedesk_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * Verify AJAX request: nonce + agent capability.
	 *
	 * @param bool $admin_only Require admin capability.
	 * @return void
	 */
	private function guard( $admin_only = false ) {
		check_ajax_referer( 'livedesk_admin', 'nonce' );
		if ( ! Permissions::is_agent() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'livedesk' ) ), 403 );
		}
		if ( $admin_only && ! Permissions::can_see_all() ) {
			wp_send_json_error( array( 'message' => __( 'Only administrators can do this.', 'livedesk' ) ), 403 );
		}
	}

	/**
	 * Send a JSON success and stop.
	 *
	 * @param array $data Payload.
	 * @return void
	 */
	private function ok( $data = array() ) {
		wp_send_json_success( array_merge( array( 'ok' => true ), $data ) );
	}

	/**
	 * Send a JSON error and stop.
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return void
	 */
	private function fail( $message, $status = 400 ) {
		wp_send_json_error( array( 'message' => $message ), $status );
	}

	/**
	 * Load a conversation, enforcing access.
	 *
	 * @return object
	 */
	private function require_conversation() {
		$id           = isset( $_POST['conversation_id'] ) ? absint( wp_unslash( $_POST['conversation_id'] ) ) : 0;
		$conversation = Conversation_Manager::get( $id );
		if ( ! $conversation ) {
			$this->fail( __( 'Conversation not found.', 'livedesk' ), 404 );
		}
		if ( ! Permissions::can_access_conversation( $conversation ) ) {
			$this->fail( __( 'You are not allowed to manage this conversation.', 'livedesk' ), 403 );
		}
		return $conversation;
	}

	/**
	 * Assign / reassign / unassign a conversation.
	 *
	 * @return void
	 */
	public function handle_conversation_assign() {
		$this->guard( true );
		$conversation = $this->require_conversation();
		$agent_id     = isset( $_POST['agent_id'] ) ? absint( wp_unslash( $_POST['agent_id'] ) ) : 0;

		$result = Assignment_Manager::assign( (int) $conversation->id, $agent_id, get_current_user_id(), 'manual' );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );
		}
		$updated = Conversation_Manager::get( (int) $conversation->id );
		$this->ok(
			array(
				'agent_id'  => (int) $updated->agent_id,
				'agent_name' => $updated->agent_id ? Permissions::agent_display_name( (int) $updated->agent_id, 'display_name' ) : '',
			)
		);
	}

	/**
	 * Change status (active / waiting / archived).
	 *
	 * @return void
	 */
	public function handle_conversation_status() {
		$this->guard();
		$conversation = $this->require_conversation();
		$status       = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! in_array( $status, Conversation_Manager::statuses(), true ) ) {
			$this->fail( __( 'Invalid status.', 'livedesk' ) );
		}
		$result = Conversation_Manager::set_status( (int) $conversation->id, $status );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );
		}
		$this->ok( array( 'status' => $status ) );
	}

	/**
	 * Permanently delete a conversation (admin only, confirmation client-side).
	 *
	 * @return void
	 */
	public function handle_conversation_delete() {
		$this->guard( true );
		$conversation = $this->require_conversation();
		$confirm      = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
		if ( 'DELETE' !== $confirm ) {
			$this->fail( __( 'Type DELETE to confirm permanent deletion.', 'livedesk' ) );
		}
		$deleted = Conversation_Manager::delete( (int) $conversation->id, (bool) Settings_Manager::get( 'retention.delete_attachments', 1 ) );
		if ( ! $deleted ) {
			$this->fail( __( 'The conversation could not be deleted.', 'livedesk' ), 500 );
		}
		$this->ok( array( 'redirect' => admin_url( 'admin.php?page=livedesk-inbox' ) ) );
	}

	/**
	 * Mark conversation as read for the current agent.
	 *
	 * @return void
	 */
	public function handle_conversation_mark_read() {
		$this->guard();
		$conversation = $this->require_conversation();
		Conversation_Manager::mark_read_by_agent( (int) $conversation->id );
		$this->ok( array( 'counts' => Conversation_Manager::inbox_counts() ) );
	}

	/**
	 * Toggle the current agent's availability.
	 *
	 * @return void
	 */
	public function handle_agent_availability() {
		$this->guard();
		$agent_id     = get_current_user_id();
		$availability = isset( $_POST['availability'] ) ? sanitize_key( wp_unslash( $_POST['availability'] ) ) : '';
		if ( ! in_array( $availability, array( 'available', 'away' ), true ) ) {
			$this->fail( __( 'Invalid availability value.', 'livedesk' ) );
		}
		if ( ! Permissions::is_active_agent( $agent_id ) ) {
			$this->fail( __( 'You are not an active agent.', 'livedesk' ), 403 );
		}
		Agent_Manager::set_availability( $agent_id, $availability );
		$this->ok( array( 'availability' => $availability ) );
	}

	/**
	 * Admin: enable/disable an agent.
	 *
	 * @return void
	 */
	public function handle_agent_toggle() {
		$this->guard( true );
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$enabled = isset( $_POST['enabled'] ) ? (bool) absint( wp_unslash( $_POST['enabled'] ) ) : false;
		if ( ! Agent_Manager::get_by_user( $user_id ) ) {
			$this->fail( __( 'Agent not found.', 'livedesk' ), 404 );
		}
		Agent_Manager::set_enabled( $user_id, $enabled );
		$this->ok( array( 'enabled' => $enabled ) );
	}

	/**
	 * Admin: add an existing WP user as agent.
	 *
	 * @return void
	 */
	public function handle_agent_add() {
		$this->guard( true );
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$result  = Agent_Manager::add( $user_id );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );
		}
		$this->ok( array( 'user_id' => $user_id ) );
	}

	/**
	 * Admin: remove an agent.
	 *
	 * @return void
	 */
	public function handle_agent_remove() {
		$this->guard( true );
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		if ( $user_id === get_current_user_id() ) {
			$this->fail( __( 'You cannot remove yourself.', 'livedesk' ) );
		}
		Agent_Manager::remove( $user_id );
		$this->ok();
	}

	/**
	 * Admin: create or update a canned response.
	 *
	 * @return void
	 */
	public function handle_canned_save() {
		$this->guard( true );
		$id      = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$title   = isset( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$result = $id ? Canned_Responses::update( $id, $title, $content ) : Canned_Responses::create( $title, $content );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result->get_error_message() );
		}
		$this->ok( array( 'id' => $id ? $id : $result ) );
	}

	/**
	 * Admin: delete a canned response.
	 *
	 * @return void
	 */
	public function handle_canned_delete() {
		$this->guard( true );
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		Canned_Responses::delete( $id );
		$this->ok();
	}

	/**
	 * Delete an internal note.
	 *
	 * @return void
	 */
	public function handle_note_delete() {
		$this->guard( true );
		$conversation = $this->require_conversation();
		$note_id      = isset( $_POST['note_id'] ) ? absint( wp_unslash( $_POST['note_id'] ) ) : 0;
		Notes_Manager::delete( $note_id, (int) $conversation->id );
		$this->ok();
	}

	/**
	 * Admin: send a test email to configured recipients.
	 *
	 * @return void
	 */
	public function handle_send_test_email() {
		$this->guard( true );
		$recipients = Notification_Manager::admin_recipients();
		$body       = '<p>' . esc_html__( 'This is a test notification from LiveDesk Live Chat. Email delivery works.', 'livedesk' ) . '</p>';
		$headers    = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent       = wp_mail( $recipients, __( '[Live Chat] Test notification', 'livedesk' ), $body, $headers );
		if ( ! $sent ) {
			$this->fail( __( 'The test email could not be sent. Check your mail configuration.', 'livedesk' ), 500 );
		}
		$this->ok( array( 'recipients' => $recipients ) );
	}

	/**
	 * Privacy: permanently delete a visitor's conversation and data.
	 *
	 * @return void
	 */
	public function handle_visitor_delete() {
		global $wpdb;
		$this->guard( true );
		$conversation = $this->require_conversation();
		$visitor_id   = (int) $conversation->visitor_id;

		Conversation_Manager::delete( (int) $conversation->id, true );
		$wpdb->delete( Database::table( 'visitors' ), array( 'id' => $visitor_id ), array( '%d' ) );

		$this->ok( array( 'redirect' => admin_url( 'admin.php?page=livedesk-inbox' ) ) );
	}
}
