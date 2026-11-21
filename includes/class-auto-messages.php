<?php
/**
 * Automated (system) chat messages.
 *
 * Two messages are sent automatically so a visitor is never left in silence:
 *
 *  1. A personalised welcome the moment a visitor identifies
 *     ("Hi {name}, how can we help you today?").
 *  2. A holding acknowledgement after the visitor's first message
 *     ("Thanks for reaching out! An agent will be with you shortly.").
 *
 * Both are stored as `sender_type = 'system'` rows. That choice matters:
 *  - they are attributed to the site rather than to a staff member, so no agent
 *    can be blamed for them;
 *  - `admin_unread` is not incremented, so the inbox badge keeps counting only
 *    messages that need a human reply;
 *  - the admin thread renders them as a centred notice instead of a bubble.
 *
 * Every message is switchable and editable from Settings → General, so no
 * wording is hard-coded into a template.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Auto_Messages
 */
class Auto_Messages {

	/**
	 * Sender type used for automated rows.
	 */
	const SENDER = 'system';

	/**
	 * Send the personalised welcome once per conversation.
	 *
	 * Idempotent in two ways: the template is skipped when disabled or empty,
	 * and the visitor row's `welcome_sent` flag makes a repeated identify call
	 * (for example a resumed session) a no-op.
	 *
	 * @param object $conversation Conversation row.
	 * @param object $visitor      Visitor row.
	 * @return object|null Message row when one was created.
	 */
	public static function send_welcome( $conversation, $visitor ) {
		if ( ! Settings_Manager::get( 'general.welcome_enabled', 1 ) ) {
			return null;
		}

		$template = (string) Settings_Manager::get( 'general.welcome_chat_message', '' );
		if ( '' === trim( $template ) ) {
			return null;
		}

		// Only ever one welcome per visitor.
		if ( '1' === (string) $visitor->welcome_sent ) {
			return null;
		}

		$body = self::render( $template, $visitor, $conversation );
		if ( '' === $body ) {
			return null;
		}

		$message = Message_Manager::add(
			(int) $conversation->id,
			self::SENDER,
			0,
			$body,
			array( 'client_uuid' => 'welcome-' . (int) $conversation->id )
		);

		if ( is_wp_error( $message ) ) {
			Logger::log( 'auto_welcome_failed', array( 'error' => $message->get_error_code() ) );
			return null;
		}

		Visitor_Manager::mark_welcome_sent( (int) $visitor->id );

		return $message;
	}

	/**
	 * Acknowledge the visitor's first message in a conversation.
	 *
	 * Called from the visitor message flow, so it only fires for a genuine new
	 * message — never for duplicate submissions, agent replies or system rows.
	 *
	 * @param object $conversation Conversation row.
	 * @param object $visitor      Visitor row.
	 * @return object|null Message row when one was created.
	 */
	public static function send_acknowledgement( $conversation, $visitor ) {
		if ( ! Settings_Manager::get( 'general.ack_enabled', 1 ) ) {
			return null;
		}

		$template = (string) Settings_Manager::get( 'general.ack_message', '' );
		if ( '' === trim( $template ) ) {
			return null;
		}

		$body = self::render( $template, $visitor, $conversation );
		if ( '' === $body ) {
			return null;
		}

		$message = Message_Manager::add(
			(int) $conversation->id,
			self::SENDER,
			0,
			$body,
			array( 'client_uuid' => 'ack-' . (int) $conversation->id )
		);

		if ( is_wp_error( $message ) ) {
			Logger::log( 'auto_ack_failed', array( 'error' => $message->get_error_code() ) );
			return null;
		}

		return $message;
	}

	/**
	 * Replace placeholders in a template.
	 *
	 * Supported: {user_name}, {user_email}, {site_name}.
	 * The visitor is not logged in, so WordPress' own blogname is used; the
	 * filter allows a white-label override.
	 *
	 * @param string $template     Raw template.
	 * @param object $visitor      Visitor row.
	 * @param object $conversation Conversation row.
	 * @return string
	 */
	private static function render( $template, $visitor, $conversation ) {
		$name = isset( $visitor->name ) ? trim( (string) $visitor->name ) : '';
		if ( '' === $name ) {
			$name = __( 'there', 'livedesk' );
		}

		$replacements = array(
			'{user_name}'  => $name,
			'{user_email}' => isset( $visitor->email ) ? (string) $visitor->email : '',
			'{site_name}'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{ref}'        => isset( $conversation->ticket_ref ) ? (string) $conversation->ticket_ref : '',
		);

		/**
		 * Filters the placeholder map used for automated messages.
		 *
		 * @param array  $replacements Placeholder => value.
		 * @param object $visitor      Visitor row.
		 */
		$replacements = apply_filters( 'livedesk_auto_message_placeholders', $replacements, $visitor );

		$body = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );

		return trim( Utils::clip( $body, 1000 ) );
	}
}
