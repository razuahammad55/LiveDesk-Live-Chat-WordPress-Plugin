<?php
/**
 * Email notifications.
 *
 * Browser and sound notifications are driven by the dashboard JS; this class
 * handles all server-side email delivery via wp_mail() so SMTP plugins work.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Notification_Manager
 */
class Notification_Manager {

	/**
	 * Resolve admin recipients: explicit list or site admin email.
	 *
	 * @return array
	 */
	public static function admin_recipients() {
		$raw  = (string) Settings_Manager::get( 'notifications.admin_recipients', '' );
		$list = array();
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) ) {
				$list[] = $email;
			}
		}
		if ( empty( $list ) ) {
			$list[] = get_option( 'admin_email' );
		}
		/**
		 * Filter admin email recipients.
		 *
		 * @param array $list Emails.
		 */
		return array_values( array_unique( (array) apply_filters( 'livedesk_admin_recipients', $list ) ) );
	}

	/**
	 * Send raw HTML email.
	 *
	 * @param string $to      Recipient(s).
	 * @param string $subject Subject.
	 * @param string $body    HTML body (already escaped by caller).
	 * @return bool
	 */
	private static function send( $to, $subject, $body ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$wrapped = '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;max-width:560px;margin:0 auto;padding:24px;">' . $body . '</div>';
		$sent    = wp_mail( $to, $subject, $wrapped, $headers );
		if ( ! $sent ) {
			Logger::log( 'email_failed', array( 'subject' => $subject ) );
		}
		return (bool) $sent;
	}

	/**
	 * Admin email when a new visitor conversation starts (or reopens).
	 *
	 * @param object $conversation Conversation row.
	 * @param object $visitor      Visitor row.
	 * @param string $preview      First message preview.
	 * @param bool   $is_reopen    Whether this was a reopen event.
	 * @return void
	 */
	public static function notify_admin_new_conversation( $conversation, $visitor, $preview, $is_reopen = false ) {
		if ( $is_reopen && ! Settings_Manager::get( 'notifications.notify_reopen', 0 ) ) {
			return;
		}
		if ( ! $is_reopen && ! Settings_Manager::get( 'notifications.notify_new', 1 ) ) {
			return;
		}

		$link    = admin_url( 'admin.php?page=livedesk-inbox&conversation=' . (int) $conversation->id );
		$subject = $is_reopen
			? sprintf(
				/* translators: %s: visitor name */
				__( '[Live Chat] Conversation reopened by %s', 'livedesk' ),
				$visitor->name
			)
			: sprintf(
				/* translators: %s: visitor name */
				__( '[Live Chat] New conversation from %s', 'livedesk' ),
				$visitor->name
			);

		$body  = '<h2 style="margin:0 0 12px;font-size:18px;">' . esc_html( $subject ) . '</h2>';
		$body .= '<p style="margin:0 0 16px;">' . esc_html__( 'A visitor started a conversation on your site.', 'livedesk' ) . '</p>';
		$body .= '<table style="width:100%;border-collapse:collapse;margin:0 0 20px;">';
		$body .= '<tr><td style="padding:6px 0;color:#6b7280;width:110px;">' . esc_html__( 'Name', 'livedesk' ) . '</td><td style="padding:6px 0;"><strong>' . esc_html( $visitor->name ) . '</strong></td></tr>';
		$body .= '<tr><td style="padding:6px 0;color:#6b7280;">' . esc_html__( 'Email', 'livedesk' ) . '</td><td style="padding:6px 0;">' . esc_html( $visitor->email ) . '</td></tr>';
		if ( $preview ) {
			$body .= '<tr><td style="padding:6px 0;color:#6b7280;">' . esc_html__( 'Message', 'livedesk' ) . '</td><td style="padding:6px 0;">' . esc_html( $preview ) . '</td></tr>';
		}
		$body .= '</table>';
		$body .= '<a href="' . esc_url( $link ) . '" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600;">' . esc_html__( 'Open conversation', 'livedesk' ) . '</a>';

		self::send( self::admin_recipients(), $subject, $body );
	}

	/**
	 * Notify an agent that a conversation was assigned to them (optional).
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $agent_user_id   Agent user.
	 * @return void
	 */
	public static function notify_agent_assigned( $conversation_id, $agent_user_id ) {
		$user = get_userdata( (int) $agent_user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}
		$conversation = Conversation_Manager::get( $conversation_id );
		if ( ! $conversation ) {
			return;
		}
		$link    = admin_url( 'admin.php?page=livedesk-inbox&conversation=' . (int) $conversation_id );
		$subject = sprintf(
			/* translators: %s: visitor name */
			__( '[Live Chat] Conversation assigned to you: %s', 'livedesk' ),
			$conversation->visitor_name
		);
		$body  = '<p>' . esc_html__( 'A conversation has been assigned to you.', 'livedesk' ) . '</p>';
		$body .= '<p><strong>' . esc_html( $conversation->visitor_name ) . '</strong> — ' . esc_html( $conversation->visitor_email ) . '</p>';
		$body .= '<a href="' . esc_url( $link ) . '">' . esc_html__( 'Open conversation', 'livedesk' ) . '</a>';
		self::send( $user->user_email, $subject, $body );
	}

	/**
	 * Notify admins about upcoming retention deletion.
	 *
	 * @param array $conversations Conversation rows about to be deleted.
	 * @return void
	 */
	public static function notify_retention_upcoming( $conversations ) {
		if ( empty( $conversations ) ) {
			return;
		}
		$subject = sprintf(
			/* translators: %d: number of conversations */
			__( '[Live Chat] %d archived conversations will be deleted soon', 'livedesk' ),
			count( $conversations )
		);
		$body  = '<p>' . esc_html__( 'The following archived conversations are nearing the end of your retention period and will be permanently deleted:', 'livedesk' ) . '</p><ul>';
		foreach ( array_slice( $conversations, 0, 50 ) as $conversation ) {
			$body .= '<li>' . esc_html( $conversation->visitor_name . ' (' . $conversation->visitor_email . ') — ' . $conversation->ticket_ref ) . '</li>';
		}
		$body .= '</ul><p>' . esc_html__( 'Open the Live Chat inbox to review them.', 'livedesk' ) . '</p>';
		self::send( self::admin_recipients(), $subject, $body );
	}
}
