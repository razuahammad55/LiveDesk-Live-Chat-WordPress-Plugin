<?php
/**
 * Message storage, retrieval and serialization.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Message_Manager
 */
class Message_Manager {

	/**
	 * Add a message to a conversation.
	 *
	 * Role semantics:
	 *  - visitor: sender_id = visitor id, client_uuid may be used for idempotency.
	 *  - agent:   sender_id = WP user id.
	 *
	 * Side effects (status transitions, unread counters) are handled by the
	 * controllers, because they differ between agent and visitor flows.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $sender_type     visitor|agent|admin.
	 * @param int    $sender_id       Visitor ID or user ID.
	 * @param string $body            Plain text body.
	 * @param array  $args            Optional: client_uuid, is_read, created_at.
	 * @return object|\WP_Error Message row.
	 */
	public static function add( $conversation_id, $sender_type, $sender_id, $body, $args = array() ) {
		global $wpdb;

		$conversation_id = absint( $conversation_id );
		if ( ! $conversation_id ) {
			return new \WP_Error( 'livedesk_bad_conversation', __( 'Conversation not found.', 'livedesk' ), array( 'status' => 404 ) );
		}

		$body = trim( (string) $body );
		$body = Utils::clip( $body, 10000 );
		if ( '' === $body && empty( $args['allow_empty'] ) ) {
			return new \WP_Error( 'livedesk_empty_message', __( 'Please type a message first.', 'livedesk' ), array( 'status' => 400 ) );
		}

		$client_uuid = isset( $args['client_uuid'] ) ? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $args['client_uuid'] ) : '';
		$client_uuid = substr( $client_uuid, 0, 36 );

		// Idempotency: if the same client UUID already exists in this conversation, return it.
		if ( '' !== $client_uuid ) {
			$table = Database::table( 'messages' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE conversation_id = %d AND client_uuid = %s LIMIT 1",
					$conversation_id,
					$client_uuid
				)
			);
			if ( $existing ) {
				// Duplicate submission: tell controllers not to re-run side effects.
				$existing->_created = false;
				return $existing;
			}
		}

		$now  = Utils::now_gmt();
		$data = array(
			'conversation_id' => $conversation_id,
			'sender_type'     => in_array( $sender_type, array( 'visitor', 'agent', 'admin', 'system' ), true ) ? $sender_type : 'visitor',
			'sender_id'       => absint( $sender_id ),
			'body'            => $body,
			'client_uuid'     => $client_uuid,
			'is_read'         => ( empty( $args['is_read'] ) && 'system' !== $sender_type ) ? '0' : '1',
			'created_at'      => isset( $args['created_at'] ) ? $args['created_at'] : $now,
		);

		$ok = $wpdb->insert( Database::table( 'messages' ), $data, array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' ) );
		if ( ! $ok ) {
			return new \WP_Error( 'livedesk_db_error', __( 'Your message could not be sent. Please try again.', 'livedesk' ), array( 'status' => 500 ) );
		}

		$message_id = (int) $wpdb->insert_id;
		Conversation_Manager::refresh_counters( $conversation_id );

		$created = self::get( $message_id );
		if ( $created ) {
			$created->_created = true;
		}
		return $created;
	}

	/**
	 * Get one message.
	 *
	 * @param int $id Message ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Database::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * Max message ID in a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return int
	 */
	public static function max_id( $conversation_id ) {
		global $wpdb;
		$table = Database::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$table} WHERE conversation_id = %d", absint( $conversation_id ) ) );
	}

	/**
	 * Fetch messages for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @param int $after_id        Only messages with ID > after_id.
	 * @param int $limit           Page size (newest N when fetching all).
	 * @param int $before_id       Fetch a page of messages older than this ID (pagination backwards).
	 * @return array
	 */
	public static function query( $conversation_id, $after_id = 0, $limit = 30, $before_id = 0 ) {
		global $wpdb;
		$table = Database::table( 'messages' );
		$limit = min( 100, max( 1, (int) $limit ) );

		if ( $after_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE conversation_id = %d AND id > %d ORDER BY id ASC LIMIT %d",
					absint( $conversation_id ),
					absint( $after_id ),
					$limit
				)
			);
			return is_array( $rows ) ? $rows : array();
		}

		if ( $before_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE conversation_id = %d AND id < %d ORDER BY id DESC LIMIT %d",
					absint( $conversation_id ),
					absint( $before_id ),
					$limit
				)
			);
			return is_array( $rows ) ? array_reverse( $rows ) : array();
		}

		// Latest page: fetch DESC then order ascending.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY id DESC LIMIT %d",
				absint( $conversation_id ),
				$limit
			)
		);
		return is_array( $rows ) ? array_reverse( $rows ) : array();
	}

	/**
	 * Serialize a message row for API output (agent or visitor view).
	 *
	 * @param object $message       Message row.
	 * @param bool   $visitor_view  True when serializing for the visitor.
	 * @return array
	 */
	public static function to_array( $message, $visitor_view = false ) {
		$attachments = Attachment_Manager::for_message( (int) $message->id );
		$atts        = array();
		foreach ( $attachments as $att ) {
			$atts[] = array(
				'id'       => (int) $att->id,
				'name'     => $att->original_name,
				'type'     => $att->mime_type,
				'size'     => (int) $att->file_size,
				'is_image' => 0 === strpos( (string) $att->mime_type, 'image/' ),
				'url'      => Attachment_Manager::url( $att, $visitor_view ),
			);
		}

		$sender_name = '';
		$sender_type = (string) $message->sender_type;
		if ( 'visitor' === $sender_type ) {
			$visitor     = Visitor_Manager::get( (int) $message->sender_id );
			$sender_name = $visitor ? $visitor->name : __( 'Visitor', 'livedesk' );
			if ( $visitor_view ) {
				$sender_name = __( 'You', 'livedesk' );
			}
		} elseif ( 'agent' === $sender_type || 'admin' === $sender_type ) {
			$sender_name = Permissions::agent_display_name( (int) $message->sender_id );
			if ( $visitor_view ) {
				// Visitors see the person's name inside the thread, not a generic label.
				$sender_name = $sender_name ? $sender_name : Settings_Manager::get( 'general.widget_title', __( 'Support', 'livedesk' ) );
			}
		} elseif ( 'system' === $sender_type ) {
			$sender_name = '';
		}

		return array(
			'id'           => (int) $message->id,
			'sender_type'  => $sender_type,
			'sender_id'    => (int) $message->sender_id,
			'sender_name'  => $sender_name,
			'body'         => (string) $message->body,
			'created_at'   => Utils::iso( (string) $message->created_at ),
			'created_ts'   => (int) strtotime( (string) $message->created_at . ' UTC' ),
			'is_read'      => '1' === (string) $message->is_read,
			'client_uuid'  => (string) $message->client_uuid,
			'attachments'  => $atts,
		);
	}

	/**
	 * Serialize a list of message rows.
	 *
	 * @param array $rows         Message rows.
	 * @param bool  $visitor_view Visitor-facing view.
	 * @return array
	 */
	public static function to_array_list( $rows, $visitor_view = false ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = self::to_array( $row, $visitor_view );
		}
		return $out;
	}

	/**
	 * Delete messages of a conversation (used by retention cleanup).
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return void
	 */
	public static function delete_for_conversation( $conversation_id ) {
		global $wpdb;
		$wpdb->delete( Database::table( 'messages' ), array( 'conversation_id' => absint( $conversation_id ) ), array( '%d' ) );
	}

	/**
	 * Total message count in a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return int
	 */
	public static function count( $conversation_id ) {
		global $wpdb;
		$table = Database::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE conversation_id = %d", absint( $conversation_id ) ) );
	}
}
