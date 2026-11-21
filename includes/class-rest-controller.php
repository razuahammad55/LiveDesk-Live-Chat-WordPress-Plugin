<?php
/**
 * REST API: visitor endpoints (token-auth) and agent endpoints (cookie auth).
 *
 * Visitors never authenticate with an email address; a 32-byte random token is
 * issued once and only its HMAC-SHA256 hash is stored. Every read/write is
 * scoped to the visitor row that owns that token.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class REST_Controller
 */
class REST_Controller {

	const NS = 'livedesk/v1';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// ---------- Visitor ----------
		register_rest_route(
			self::NS,
			'/visitor/session',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'visitor_session' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/visitor/bootstrap',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'visitor_bootstrap' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/visitor/identify',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'visitor_identify' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/visitor/poll',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'visitor_poll' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/visitor/messages',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'visitor_send_message' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/visitor/end',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'visitor_end_conversation' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/visitor/typing',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'visitor_typing' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/visitor/history',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'visitor_history' ),
				'permission_callback' => '__return_true',
			)
		);

		// ---------- Attachments ----------
		register_rest_route(
			self::NS,
			'/attachments/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'download_attachment' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'  => array( 'sanitize_callback' => 'absint' ),
					'sig' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/visitor/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'visitor_upload' ),
				'permission_callback' => '__return_true',
			)
		);

		// ---------- Agent ----------
		register_rest_route(
			self::NS,
			'/agent/poll',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'agent_poll' ),
				'permission_callback' => array( 'LiveDesk\\Security', 'require_agent' ),
			)
		);
		register_rest_route(
			self::NS,
			'/agent/conversation/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'agent_conversation' ),
				'permission_callback' => array( 'LiveDesk\\Security', 'require_agent' ),
			)
		);
		register_rest_route(
			self::NS,
			'/agent/reply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'agent_reply' ),
				'permission_callback' => array( 'LiveDesk\\Security', 'require_agent' ),
			)
		);
		register_rest_route(
			self::NS,
			'/agent/note',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'agent_note' ),
				'permission_callback' => array( 'LiveDesk\\Security', 'require_agent' ),
			)
		);
		register_rest_route(
			self::NS,
			'/agent/typing',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'agent_typing' ),
				'permission_callback' => array( 'LiveDesk\\Security', 'require_agent' ),
			)
		);
	}

	/* =====================================================================
	 * Visitor endpoints
	 * =================================================================== */

	/**
	 * Basic spam/bot gate used by public endpoints.
	 *
	 * @param array  $data     Request data.
	 * @param string $bucket   Rate bucket.
	 * @param int    $limit    Limit.
	 * @param int    $seconds  Window.
	 * @param string $identity Extra identity (token/ip).
	 * @return true|\WP_Error
	 */
	private function gate( $data, $bucket, $limit, $seconds, $identity = '' ) {
		if ( ! Security::honeypot_ok( $data ) ) {
			return new \WP_Error( 'livedesk_spam', __( 'Your request could not be processed.', 'livedesk' ), array( 'status' => 400 ) );
		}
		$captcha = Security::verify_captcha( $data );
		if ( is_wp_error( $captcha ) ) {
			return $captcha;
		}
		if ( ! Security::rate_limit( $bucket, $limit, $seconds, $identity ? $identity : Security::client_ip() ) ) {
			return new \WP_Error( 'livedesk_rate_limited', __( 'You are sending requests too quickly. Please wait a moment.', 'livedesk' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * POST /visitor/session — start (or resume) a conversation.
	 *
	 * For returning visitors, a non-expired token may be supplied to skip the
	 * form. Tokens expire after 120 days of inactivity.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function visitor_session( $request ) {
		$data  = Utils::json_params( $request );
		$page  = isset( $data['page'] ) ? esc_url_raw( Utils::clip( $data['page'], 500 ) ) : '';
		$token = isset( $data['token'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $data['token'] ) : '';

		// Try resume with an existing token.
		if ( 64 === strlen( $token ) ) {
			$visitor = Visitor_Manager::find_by_token( $token );
			if ( $visitor && '1' !== (string) $visitor->admin_blocked ) {
				$expiry = get_option( 'livedesk_token_expiry_days', 120 );
				$last   = strtotime( $visitor->last_activity . ' UTC' );
				if ( $last && ( time() - $last ) < ( (int) $expiry * DAY_IN_SECONDS ) ) {
					Visitor_Manager::touch( (int) $visitor->id, $page );
					$conversation = Conversation_Manager::ensure_for_visitor( (int) $visitor->id );
					if ( is_wp_error( $conversation ) ) {
						return $conversation;
					}
					return rest_ensure_response(
						array(
							'ok'        => true,
							'resumed'   => true,
							'resumed_by' => 'token',
							'token'     => $token,
							'visitor'   => array(
								'name'  => $visitor->name,
								'email' => $visitor->email,
							),
							'conversation' => self::conversation_payload( $conversation, $visitor, true ),
						)
					);
				}
			}
		}

		// Fresh identification — require name/email.
		$gate = $this->gate( $data, 'ld_start', (int) Settings_Manager::get( 'security.rate_starts', 10 ), HOUR_IN_SECONDS );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$name  = Security::validate_name( isset( $data['name'] ) ? $data['name'] : '' );
		$email = Security::validate_email( isset( $data['email'] ) ? $data['email'] : '' );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		if ( is_wp_error( $email ) ) {
			return $email;
		}

		/*
		 * SECURITY: an email address is NOT an authentication factor.
		 *
		 * A previous visitor is resumed only through the secret token above
		 * (stored in the browser). Typing a known email must never hand out a
		 * token for an existing conversation, otherwise anyone who can guess or
		 * obtain an email address could read that person's chat history.
		 *
		 * `security.email_history_resume` (default off) is an explicit, documented
		 * opt-in for deployments that want email-keyed history, e.g. an intranet
		 * where the email domain is already trusted.
		 */
		if ( Settings_Manager::get( 'security.email_history_resume', 0 ) ) {
			$existing = Visitor_Manager::find_by_email( $email );
			if ( $existing && '1' !== (string) $existing->admin_blocked ) {
				Visitor_Manager::update_identity( (int) $existing->id, $name, $email );
				$new_token = Visitor_Manager::rotate_token( (int) $existing->id );
				Visitor_Manager::touch( (int) $existing->id, $page );
				$visitor      = Visitor_Manager::get( (int) $existing->id );
				$conversation = Conversation_Manager::ensure_for_visitor( (int) $existing->id );
				if ( is_wp_error( $conversation ) ) {
					return $conversation;
				}
				return rest_ensure_response(
					array(
						'ok'           => true,
						'resumed'      => true,
						'resumed_by'   => 'email',
						'token'        => $new_token,
						'visitor'      => array(
							'name'  => $visitor->name,
							'email' => $visitor->email,
						),
						'conversation' => self::conversation_payload( $conversation, $visitor, true ),
					)
				);
			}
		}

		$created = Visitor_Manager::create( $name, $email, array( 'page' => $page ) );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$visitor      = $created['visitor'];
		$conversation = Conversation_Manager::ensure_for_visitor( (int) $visitor->id );
		if ( is_wp_error( $conversation ) ) {
			return $conversation;
		}

		// Automated, personalised welcome for a brand-new conversation.
		$welcome = Auto_Messages::send_welcome( $conversation, $visitor );
		if ( $welcome ) {
			// Re-read so the payload contains the welcome row.
			$conversation = Conversation_Manager::get( (int) $conversation->id );
		}

		return rest_ensure_response(
			array(
				'ok'           => true,
				'resumed'      => false,
				'token'        => $created['token'],
				'visitor'      => array(
					'name'  => $visitor->name,
					'email' => $visitor->email,
				),
				'conversation' => self::conversation_payload( $conversation, $visitor, true ),
			)
		);
	}

	/**
	 * POST /visitor/bootstrap — config + online state for the widget.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function visitor_bootstrap( $request ) {
		$data = Utils::json_params( $request );
		$token = isset( $data['token'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $data['token'] ) : '';

		$response = array(
			'ok'        => true,
			'online'    => Business_Hours::is_online(),
			'offline_mode' => Business_Hours::offline_mode(),
			'captcha'   => array(
				'provider' => Settings_Manager::get( 'security.captcha_provider', 'none' ),
				'site_key' => Settings_Manager::get( 'security.captcha_site_key', '' ),
			),
			'attachments' => array(
				'enabled' => (bool) Settings_Manager::get( 'attachments.enabled', 1 ),
				'max_mb'  => (int) Settings_Manager::get( 'attachments.max_size_mb', 10 ),
				'types'   => Attachment_Manager::allowed_extensions(),
			),
			'poll'      => array(
				'active'  => (int) Settings_Manager::get( 'realtime.poll_active', 3 ),
				'waiting' => (int) Settings_Manager::get( 'realtime.poll_waiting', 3 ),
				'idle'    => (int) Settings_Manager::get( 'realtime.poll_idle', 20 ),
			),
			'typing'    => (bool) Settings_Manager::get( 'realtime.typing_enabled', 1 ),
			'sound'     => (bool) Settings_Manager::get( 'general.sound_default', 1 ),
		);

		// Optional: attach conversation state for a returning session.
		if ( 64 === strlen( $token ) ) {
			$visitor = Visitor_Manager::find_by_token( $token );
			if ( $visitor && '1' !== (string) $visitor->admin_blocked ) {
				$conversation = Conversation_Manager::get_for_visitor( (int) $visitor->id );
				if ( $conversation ) {
					$response['conversation'] = self::conversation_payload( $conversation, $visitor, true );
				}
			}
		}
		return rest_ensure_response( $response );
	}

	/**
	 * POST /visitor/identify — update name/email on an existing session.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function visitor_identify( $request ) {
		$auth = Security::authenticate_visitor( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$data  = Utils::json_params( $request );
		$name  = Security::validate_name( isset( $data['name'] ) ? $data['name'] : '' );
		$email = Security::validate_email( isset( $data['email'] ) ? $data['email'] : '' );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		if ( is_wp_error( $email ) ) {
			return $email;
		}
		if ( Settings_Manager::get( 'security.require_email_match', 1 ) ) {
			// Identified sessions are bound to their email; changing it is refused.
			if ( strtolower( $email ) !== strtolower( $auth['visitor']->email ) ) {
				return new \WP_Error( 'livedesk_email_locked', __( 'This chat is already linked to an email address.', 'livedesk' ), array( 'status' => 403 ) );
			}
		}
		Visitor_Manager::update_identity( (int) $auth['visitor']->id, $name, $email );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /visitor/poll — new messages, typing state, conversation status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function visitor_poll( $request ) {
		$auth = Security::authenticate_visitor( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$data     = Utils::json_params( $request );
		$after_id = isset( $data['after_id'] ) ? absint( $data['after_id'] ) : 0;
		$page     = isset( $data['page'] ) ? esc_url_raw( Utils::clip( $data['page'], 500 ) ) : '';
		$mark     = ! empty( $data['mark_read'] );

		$conversation = $auth['conversation'];
		$visitor      = $auth['visitor'];

		Visitor_Manager::touch( (int) $visitor->id, $page );

		$messages = Message_Manager::query( (int) $conversation->id, $after_id, (int) Settings_Manager::get( 'realtime.page_size', 30 ) );

		if ( $mark && $messages ) {
			Conversation_Manager::mark_read_by_visitor( (int) $conversation->id );
		}

		$conversation = Conversation_Manager::get( (int) $conversation->id ); // Refresh counters.

		return rest_ensure_response(
			array(
				'ok'         => true,
				'messages'   => Message_Manager::to_array_list( $messages, true ),
				'conversation' => self::conversation_payload( $conversation, $visitor, true ),
				'agent_typing' => Visitor_Manager::is_typing_agent( (int) $conversation->id ),
				'online'     => Business_Hours::is_online(),
				'server_ts'  => time(),
			)
		);
	}

	/**
	 * POST /visitor/messages — send a visitor message.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function visitor_send_message( $request ) {
		$auth = Security::authenticate_visitor( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$data = Utils::json_params( $request );

		$gate = $this->gate(
			$data,
			'ld_msg_' . (int) $auth['visitor']->id,
			(int) Settings_Manager::get( 'security.rate_messages', 30 ),
			MINUTE_IN_SECONDS,
			'v' . (int) $auth['visitor']->id
		);
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$body = isset( $data['message'] ) ? (string) $data['message'] : '';
		$uuid = isset( $data['uuid'] ) ? (string) $data['uuid'] : '';
		$page = isset( $data['page'] ) ? esc_url_raw( Utils::clip( $data['page'], 500 ) ) : '';

		$conversation = $auth['conversation'];
		$visitor      = $auth['visitor'];

		$message = Message_Manager::add( (int) $conversation->id, 'visitor', (int) $visitor->id, $body, array( 'client_uuid' => $uuid ) );
		if ( is_wp_error( $message ) ) {
			return $message;
		}

		if ( isset( $message->_created ) && $message->_created ) {
			$this->after_visitor_message( $conversation, $visitor, $message, $page );
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => Message_Manager::to_array( $message, true ),
			)
		);
	}

	/**
	 * Shared side-effects when a visitor sends a message.
	 *
	 * - Reopens archived conversations (status -> active).
	 * - Status -> active (needs attention).
	 * - Increments admin unread counter.
	 * - Auto-assigns new/unassigned conversations when configured.
	 * - Emails admins on first message / reopen only.
	 *
	 * @param object $conversation Conversation row (pre-update).
	 * @param object $visitor      Visitor row.
	 * @param object $message      Message row.
	 * @param string $page         Current page URL.
	 * @return void
	 */
	private function after_visitor_message( $conversation, $visitor, $message, $page = '' ) {
		global $wpdb;

		$was_archived = Conversation_Manager::STATUS_ARCHIVED === $conversation->status;
		$is_first     = 0 === (int) $conversation->message_count;

		// The automated holding message goes out with the visitor's first real
		// message. Evaluated before this message bumps any counters.
		$first_visitor_message = 1 === Conversation_Manager::count_visitor_messages( (int) $conversation->id );

		if ( '' !== $page ) {
			Visitor_Manager::touch( (int) $visitor->id, $page );
		}

		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . Database::table( 'conversations' ) . " SET status = 'active', admin_unread = admin_unread + 1, archived_at = NULL WHERE id = %d",
				(int) $conversation->id
			)
		);

		if ( $first_visitor_message ) {
			Auto_Messages::send_acknowledgement( $conversation, $visitor );
		}

		// Auto-assignment for unassigned conversations.
		$fresh = Conversation_Manager::get( (int) $conversation->id );
		if ( $fresh && 0 === (int) $fresh->agent_id ) {
			Assignment_Manager::auto_assign( (int) $conversation->id );
		}

		$preview = Utils::excerpt( $message->body, 140 );

		if ( $was_archived ) {
			Notification_Manager::notify_admin_new_conversation( $conversation, $visitor, $preview, true );
		} elseif ( $is_first ) {
			Notification_Manager::notify_admin_new_conversation( $conversation, $visitor, $preview, false );
		}

		/**
		 * Fires after a visitor message was stored.
		 *
		 * @param int    $conversation_id Conversation ID.
		 * @param object $message         Message row.
		 * @param object $visitor         Visitor row.
		 */
		do_action( 'livedesk_visitor_message', (int) $conversation->id, $message, $visitor );
	}

	/**
	 * POST /visitor/end — visitor closes their own conversation.
	 *
	 * The conversation is archived (not deleted); the visitor keeps full history
	 * and any new message reopens it through the normal reopen flow.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function visitor_end_conversation( $request ) {
		$auth = Security::authenticate_visitor( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$gate = $this->gate(
			array_merge( Utils::json_params( $request ), $_POST ),
			'ld_end_' . (int) $auth['visitor']->id,
			10,
			MINUTE_IN_SECONDS,
			'v' . (int) $auth['visitor']->id
		);
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$conversation = $auth['conversation'];
		Conversation_Manager::mark_read_by_visitor( (int) $conversation->id );
		$result = Conversation_Manager::archive( (int) $conversation->id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Ending the chat ends the session: the token is void, so reopening the
		// widget starts a clean conversation instead of resurfacing history.
		Visitor_Manager::expire_token( (int) $auth['visitor']->id );

		/**
		 * Fires when a visitor ends their own conversation.
		 *
		 * @param int $conversation_id Conversation ID.
		 */
		do_action( 'livedesk_visitor_ended_conversation', (int) $conversation->id );

		$fresh = Conversation_Manager::get( (int) $conversation->id );
		return rest_ensure_response(
			array(
				'ok'           => true,
				'ended'        => true,
				'conversation' => self::conversation_payload( $fresh, $auth['visitor'], false ),
			)
		);
	}

	/**
	 * POST /visitor/typing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function visitor_typing( $request ) {
		$auth = Security::authenticate_visitor( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		Visitor_Manager::set_typing( (int) $auth['visitor']->id, (int) $auth['conversation']->id );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /visitor/history — paginated history (backwards).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function visitor_history( $request ) {
		$auth = Security::authenticate_visitor( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$data      = Utils::json_params( $request );
		$before_id = isset( $data['before_id'] ) ? absint( $data['before_id'] ) : 0;
		$limit     = (int) Settings_Manager::get( 'realtime.page_size', 30 );
		$messages  = Message_Manager::query( (int) $auth['conversation']->id, 0, $limit, $before_id );
		$has_more  = false;
		if ( $messages ) {
			$first   = $messages[0];
			$table   = Database::table( 'messages' );
			$wpdb    = $GLOBALS['wpdb'];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$has_more = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1 FROM {$table} WHERE conversation_id = %d AND id < %d LIMIT 1",
					(int) $auth['conversation']->id,
					(int) $first->id
				)
			);
		}
		return rest_ensure_response(
			array(
				'ok'       => true,
				'messages' => Message_Manager::to_array_list( $messages, true ),
				'has_more' => $has_more,
			)
		);
	}

	/**
	 * POST /visitor/upload — upload a file and attach it to a message.
	 *
	 * Creates the message (body optional) and the attachment in one call.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function visitor_upload( $request ) {
		$auth = Security::authenticate_visitor( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		if ( empty( $_FILES['file'] ) ) {
			return new \WP_Error( 'livedesk_no_file', __( 'No file was received.', 'livedesk' ), array( 'status' => 400 ) );
		}
		$gate = $this->gate(
			array_merge( Utils::json_params( $request ), $_POST ),
			'ld_up_' . (int) $auth['visitor']->id,
			15,
			MINUTE_IN_SECONDS,
			'v' . (int) $auth['visitor']->id
		);
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$conversation = $auth['conversation'];
		$visitor      = $auth['visitor'];
		$body         = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$uuid         = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';

		$message = Message_Manager::add(
			(int) $conversation->id,
			'visitor',
			(int) $visitor->id,
			$body,
			array(
				'client_uuid' => $uuid,
				'allow_empty' => true,
			)
		);
		if ( is_wp_error( $message ) ) {
			return $message;
		}

		$attachment = Attachment_Manager::handle_upload( $_FILES['file'], (int) $message->id, (int) $conversation->id );
		if ( is_wp_error( $attachment ) ) {
			// Remove the placeholder message when the upload failed.
			if ( '' === trim( (string) $message->body ) ) {
				global $wpdb;
				$wpdb->delete( Database::table( 'messages' ), array( 'id' => (int) $message->id ), array( '%d' ) );
			}
			return $attachment;
		}

		if ( isset( $message->_created ) && $message->_created ) {
			$this->after_visitor_message( $conversation, $visitor, $message );
		}

		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => Message_Manager::to_array( $message, true ),
			)
		);
	}

	/**
	 * GET /attachments/{id} — stream a file to its authorized party.
	 *
	 * Visitor: valid `sig` query arg bound to the visitor's token hash.
	 * Agent:   logged-in capability + conversation access check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return void|\WP_Error
	 */
	public function download_attachment( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );

		$attachments = Database::table( 'attachments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$attachment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$attachments} WHERE id = %d", $id ) );
		if ( ! $attachment ) {
			return new \WP_Error( 'livedesk_not_found', __( 'File not found.', 'livedesk' ), array( 'status' => 404 ) );
		}

		$authorized = false;

		// Agent path.
		if ( is_user_logged_in() && current_user_can( Permissions::CAP_AGENT ) ) {
			$conversation = Conversation_Manager::get( (int) $attachment->conversation_id );
			$authorized   = $conversation && Permissions::can_access_conversation( $conversation );
		}

		// Visitor path (signed URL).
		if ( ! $authorized ) {
			$sig = (string) $request->get_param( 'sig' );
			if ( '' !== $sig ) {
				$conversation = Conversation_Manager::get( (int) $attachment->conversation_id );
				$visitor      = $conversation ? Visitor_Manager::get( (int) $conversation->visitor_id ) : null;
				if ( $visitor ) {
					$expected = substr( hash_hmac( 'sha256', 'att|' . $attachment->id . '|' . $visitor->token_hash, wp_salt( 'auth' ) ), 0, 32 );
					$authorized = hash_equals( $expected, $sig );
				}
			}
		}

		if ( ! $authorized ) {
			return new \WP_Error( 'livedesk_forbidden', __( 'You are not allowed to download this file.', 'livedesk' ), array( 'status' => 403 ) );
		}

		Attachment_Manager::stream( $attachment );
	}

	/* =====================================================================
	 * Agent endpoints
	 * =================================================================== */

	/**
	 * GET /agent/poll — inbox summary + optionally conversation messages.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function agent_poll( $request ) {
		$conversation_id = absint( $request->get_param( 'conversation' ) );
		$after_id        = absint( $request->get_param( 'after' ) );
		$payload         = array(
			'ok'        => true,
			'counts'    => Conversation_Manager::inbox_counts(),
			'server_ts' => time(),
		);

		if ( $conversation_id ) {
			$conversation = Conversation_Manager::get( $conversation_id );
			if ( ! $conversation || ! Permissions::can_access_conversation( $conversation ) ) {
				return new \WP_Error( 'livedesk_forbidden', __( 'You are not allowed to view this conversation.', 'livedesk' ), array( 'status' => 403 ) );
			}
			$messages = Message_Manager::query( $conversation_id, $after_id, 50 );
			$payload['messages']      = Message_Manager::to_array_list( $messages, false );
			$payload['visitor_typing'] = Visitor_Manager::is_typing( $conversation_id );
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * GET /agent/conversation/{id} — full conversation payload for the UI.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function agent_conversation( $request ) {
		$id           = absint( $request->get_param( 'id' ) );
		$conversation = Conversation_Manager::get( $id );
		if ( ! $conversation ) {
			return new \WP_Error( 'livedesk_not_found', __( 'Conversation not found.', 'livedesk' ), array( 'status' => 404 ) );
		}
		if ( ! Permissions::can_access_conversation( $conversation ) ) {
			return new \WP_Error( 'livedesk_forbidden', __( 'You are not allowed to view this conversation.', 'livedesk' ), array( 'status' => 403 ) );
		}

		$data = Admin_Controller::conversation_payload( $id );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return rest_ensure_response( $data );
	}

	/**
	 * POST /agent/reply — send an agent reply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function agent_reply( $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'livedesk_bad_nonce', __( 'Your session expired. Please reload the page.', 'livedesk' ), array( 'status' => 403 ) );
		}

		$data            = Utils::json_params( $request );
		$conversation_id = isset( $data['conversation_id'] ) ? absint( $data['conversation_id'] ) : 0;
		$body            = isset( $data['message'] ) ? (string) $data['message'] : '';
		$uuid            = isset( $data['uuid'] ) ? sanitize_text_field( $data['uuid'] ) : '';

		$conversation = Conversation_Manager::get( $conversation_id );
		if ( ! $conversation ) {
			return new \WP_Error( 'livedesk_not_found', __( 'Conversation not found.', 'livedesk' ), array( 'status' => 404 ) );
		}
		if ( ! Permissions::can_reply( $conversation ) ) {
			return new \WP_Error( 'livedesk_forbidden', __( 'You are not allowed to reply to this conversation.', 'livedesk' ), array( 'status' => 403 ) );
		}

		$user  = wp_get_current_user();
		$type  = user_can( $user, Permissions::CAP_MANAGE ) && ! Agent_Manager::get_by_user( $user->ID ) ? 'admin' : 'agent';
		$reply = Message_Manager::add( $conversation_id, $type, (int) $user->ID, $body, array( 'client_uuid' => $uuid ) );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		global $wpdb;
		// Agent replied: status -> waiting; visitor unread +1.
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'UPDATE ' . Database::table( 'conversations' ) . " SET status = 'waiting', visitor_unread = visitor_unread + 1, updated_at = %s WHERE id = %d AND status != 'archived'",
				Utils::now_gmt(),
				$conversation_id
			)
		);
		Conversation_Manager::mark_read_by_agent( $conversation_id );

		/**
		 * Fires after an agent reply was stored.
		 *
		 * @param int    $conversation_id Conversation ID.
		 * @param object $message         Message row.
		 * @param int    $agent_id        User ID.
		 */
		do_action( 'livedesk_agent_reply', $conversation_id, $reply, (int) $user->ID );

		return rest_ensure_response(
			array(
				'ok'      => true,
				'message' => Message_Manager::to_array( $reply, false ),
			)
		);
	}

	/**
	 * POST /agent/note — internal note.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function agent_note( $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'livedesk_bad_nonce', __( 'Your session expired. Please reload the page.', 'livedesk' ), array( 'status' => 403 ) );
		}
		$data            = Utils::json_params( $request );
		$conversation_id = isset( $data['conversation_id'] ) ? absint( $data['conversation_id'] ) : 0;
		$content         = isset( $data['note'] ) ? (string) $data['note'] : '';

		$conversation = Conversation_Manager::get( $conversation_id );
		if ( ! $conversation || ! Permissions::can_access_conversation( $conversation ) ) {
			return new \WP_Error( 'livedesk_forbidden', __( 'You are not allowed to add notes to this conversation.', 'livedesk' ), array( 'status' => 403 ) );
		}

		$note_id = Notes_Manager::add( $conversation_id, $content );
		if ( is_wp_error( $note_id ) ) {
			return $note_id;
		}
		return rest_ensure_response(
			array(
				'ok'   => true,
				'note' => array(
					'id'         => $note_id,
					'content'    => sanitize_textarea_field( wp_unslash( $content ) ),
					'author'     => Permissions::agent_display_name( get_current_user_id(), 'display_name' ),
					'created_at' => Utils::iso( Utils::now_gmt() ),
				),
			)
		);
	}

	/**
	 * POST /agent/typing.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function agent_typing( $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'livedesk_bad_nonce', __( 'Your session expired. Please reload the page.', 'livedesk' ), array( 'status' => 403 ) );
		}
		$data            = Utils::json_params( $request );
		$conversation_id = isset( $data['conversation_id'] ) ? absint( $data['conversation_id'] ) : 0;
		$conversation    = Conversation_Manager::get( $conversation_id );
		if ( ! $conversation || ! Permissions::can_access_conversation( $conversation ) ) {
			return new \WP_Error( 'livedesk_forbidden', __( 'Not allowed.', 'livedesk' ), array( 'status' => 403 ) );
		}
		Visitor_Manager::set_agent_typing( $conversation_id, get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* =====================================================================
	 * Payload helpers
	 * =================================================================== */

	/**
	 * Conversation payload for the widget.
	 *
	 * @param object $conversation Conversation row.
	 * @param object $visitor      Visitor row.
	 * @param bool   $with_messages Include the latest page of messages.
	 * @return array
	 */
	public static function conversation_payload( $conversation, $visitor, $with_messages = true ) {
		$payload = array(
			'id'          => (int) $conversation->id,
			'ref'         => $conversation->ticket_ref,
			'status'      => $conversation->status,
			'agent_id'    => (int) $conversation->agent_id,
			'agent_name'  => $conversation->agent_id ? Permissions::agent_display_name( (int) $conversation->agent_id, 'display_name' ) : '',
			'created_at'  => Utils::iso( $conversation->created_at ),
			'last_message_at' => Utils::iso( $conversation->last_message_at ),
			'message_count'   => (int) $conversation->message_count,
			'unread'      => (int) $conversation->visitor_unread,
		);
		if ( $with_messages ) {
			$messages         = Message_Manager::query( (int) $conversation->id, 0, (int) Settings_Manager::get( 'realtime.page_size', 30 ) );
			$payload['messages'] = Message_Manager::to_array_list( $messages, true );
		}
		return $payload;
	}
}
