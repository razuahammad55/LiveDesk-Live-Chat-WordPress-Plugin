<?php
/**
 * Conversations: status lifecycle, queries, assignment integration.
 *
 * @package LiveDesk
 *
 * Status model (exactly three states):
 *   active   — needs attention (new visitor message / new conversation)
 *   waiting  — an agent replied, waiting on visitor
 *   archived — closed; visitor reply reopens it as active
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Conversation_Manager
 */
class Conversation_Manager {

	const STATUS_ACTIVE   = 'active';
	const STATUS_WAITING  = 'waiting';
	const STATUS_ARCHIVED = 'archived';

	/**
	 * Valid statuses.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array( self::STATUS_ACTIVE, self::STATUS_WAITING, self::STATUS_ARCHIVED );
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			self::STATUS_ACTIVE   => __( 'Active', 'livedesk' ),
			self::STATUS_WAITING  => __( 'Waiting', 'livedesk' ),
			self::STATUS_ARCHIVED => __( 'Archived', 'livedesk' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
	}

	/**
	 * Get conversation by ID.
	 *
	 * @param int $id Conversation ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$c = Database::table( 'conversations' );
		$v = Database::table( 'visitors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.*, v.name AS visitor_name, v.email AS visitor_email
				 FROM {$c} c LEFT JOIN {$v} v ON v.id = c.visitor_id
				 WHERE c.id = %d",
				absint( $id )
			)
		);
	}

	/**
	 * Get the persistent conversation for a visitor (one per visitor).
	 *
	 * @param int $visitor_id Visitor ID.
	 * @return object|null
	 */
	public static function get_for_visitor( $visitor_id ) {
		global $wpdb;
		$c = Database::table( 'conversations' );
		$v = Database::table( 'visitors' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.*, v.name AS visitor_name, v.email AS visitor_email
				 FROM {$c} c LEFT JOIN {$v} v ON v.id = c.visitor_id
				 WHERE c.visitor_id = %d
				 ORDER BY c.id DESC LIMIT 1",
				absint( $visitor_id )
			)
		);
	}

	/**
	 * Create an empty conversation for a visitor.
	 *
	 * New conversations do not get a message; the visitor's first message fills
	 * it. A synthetic "conversation started" record is not needed — `created_at`
	 * carries that information.
	 *
	 * @param int $visitor_id Visitor ID.
	 * @return object|\WP_Error
	 */
	public static function create( $visitor_id ) {
		global $wpdb;
		$now  = Utils::now_gmt();
		$data = array(
			'visitor_id'      => absint( $visitor_id ),
			'status'          => self::STATUS_ACTIVE,
			'created_at'      => $now,
			'updated_at'      => $now,
			'last_message_at' => '1970-01-01 00:00:00',
		);
		$ok = $wpdb->insert( Database::table( 'conversations' ), $data, array( '%d', '%s', '%s', '%s', '%s' ) );
		if ( ! $ok ) {
			return new \WP_Error( 'livedesk_db_error', __( 'Could not start the chat. Please try again.', 'livedesk' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		$wpdb->update(
			Database::table( 'conversations' ),
			array( 'ticket_ref' => Utils::ticket_ref( $id ) ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
		return self::get( $id );
	}

	/**
	 * Ensure a conversation exists for a visitor.
	 *
	 * @param int $visitor_id Visitor ID.
	 * @return object|\WP_Error
	 */
	public static function ensure_for_visitor( $visitor_id ) {
		$existing = self::get_for_visitor( $visitor_id );
		if ( $existing ) {
			return $existing;
		}
		return self::create( $visitor_id );
	}

	/**
	 * Recompute derived fields for a conversation after message operations.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return void
	 */
	public static function refresh_counters( $conversation_id ) {
		global $wpdb;
		$m = Database::table( 'messages' );
		$c = Database::table( 'conversations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS cnt, MAX(created_at) AS last_at FROM {$m} WHERE conversation_id = %d",
				absint( $conversation_id )
			)
		);
		$wpdb->update(
			$c,
			array(
				'message_count'   => $row ? (int) $row->cnt : 0,
				'last_message_at' => $row && $row->last_at ? $row->last_at : '1970-01-01 00:00:00',
				'updated_at'      => Utils::now_gmt(),
			),
			array( 'id' => absint( $conversation_id ) ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Number of messages a visitor actually wrote (system notes excluded).
	 *
	 * Used to decide whether a conversation is "new" for the automated
	 * acknowledgement, without counting the welcome message.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return int
	 */
	public static function count_visitor_messages( $conversation_id ) {
		global $wpdb;
		$m = Database::table( 'messages' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$m} WHERE conversation_id = %d AND sender_type = 'visitor'",
				absint( $conversation_id )
			)
		);
	}

	/**
	 * Set the status of a conversation.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $status          One of the three statuses.
	 * @return bool|\WP_Error
	 */
	public static function set_status( $conversation_id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return new \WP_Error( 'livedesk_bad_status', __( 'Invalid conversation status.', 'livedesk' ), array( 'status' => 400 ) );
		}
		$conversation = self::get( $conversation_id );
		if ( ! $conversation ) {
			return new \WP_Error( 'livedesk_not_found', __( 'Conversation not found.', 'livedesk' ), array( 'status' => 404 ) );
		}
		$data = array(
			'status'      => $status,
			'updated_at'  => Utils::now_gmt(),
			'archived_at' => self::STATUS_ARCHIVED === $status ? Utils::now_gmt() : null,
		);
		$wpdb->update(
			Database::table( 'conversations' ),
			$data,
			array( 'id' => $conversation->id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		/**
		 * Fires after a conversation status changed.
		 *
		 * @param int    $conversation_id Conversation ID.
		 * @param string $status          New status.
		 * @param object $conversation    Conversation row.
		 */
		do_action( 'livedesk_conversation_status_changed', $conversation_id, $status, $conversation );
		return true;
	}

	/**
	 * Archive a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool|\WP_Error
	 */
	public static function archive( $conversation_id ) {
		return self::set_status( $conversation_id, self::STATUS_ARCHIVED );
	}

	/**
	 * Reopen an archived conversation as active.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return bool|\WP_Error
	 */
	public static function reopen( $conversation_id ) {
		return self::set_status( $conversation_id, self::STATUS_ACTIVE );
	}

	/**
	 * Permanently delete a conversation and all related records.
	 *
	 * @param int  $conversation_id  Conversation ID.
	 * @param bool $delete_attachments Also remove attachment files.
	 * @return bool
	 */
	public static function delete( $conversation_id, $delete_attachments = true ) {
		global $wpdb;
		$conversation_id = absint( $conversation_id );
		$conversation    = self::get( $conversation_id );
		if ( ! $conversation ) {
			return false;
		}
		if ( $delete_attachments ) {
			Attachment_Manager::delete_for_conversation( $conversation_id );
		}
		$wpdb->delete( Database::table( 'messages' ), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
		$wpdb->delete( Database::table( 'notes' ), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
		$wpdb->delete( Database::table( 'assignments' ), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
		$wpdb->delete( Database::table( 'attachments' ), array( 'conversation_id' => $conversation_id ), array( '%d' ) );
		$wpdb->delete( Database::table( 'conversations' ), array( 'id' => $conversation_id ), array( '%d' ) );

		/**
		 * Fires after a conversation was permanently deleted.
		 *
		 * @param int    $conversation_id Conversation ID.
		 * @param object $conversation    Conversation row (pre-delete).
		 */
		do_action( 'livedesk_conversation_deleted', $conversation_id, $conversation );
		return true;
	}

	/**
	 * Query conversations for the inbox with filters and pagination.
	 *
	 * @param array $args Query args.
	 * @return array{rows:array,total:int}
	 */
	public static function query( $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'status'     => '',      // '' | active | waiting | archived.
				'agent'      => 'all',   // all | unassigned | mine | <user id>.
				'search'     => '',
				'date_from'  => '',
				'date_to'    => '',
				'page'       => 1,
				'per_page'   => 20,
				'orderby'    => 'last_message_at',
				'order'      => 'DESC',
				'user_id'    => get_current_user_id(),
				'can_all'    => Permissions::can_see_all(),
			)
		);

		$c = Database::table( 'conversations' );
		$v = Database::table( 'visitors' );
		$m = Database::table( 'messages' );

		$where  = array( '1=1' );
		$params = array();

		if ( in_array( $args['status'], self::statuses(), true ) ) {
			$where[]  = 'c.status = %s';
			$params[] = $args['status'];
		}

		if ( 'unassigned' === $args['agent'] ) {
			$where[] = 'c.agent_id = 0';
		} elseif ( 'mine' === $args['agent'] ) {
			$where[]  = 'c.agent_id = %d';
			$params[] = (int) $args['user_id'];
		} elseif ( is_numeric( $args['agent'] ) && (int) $args['agent'] > 0 ) {
			$where[]  = 'c.agent_id = %d';
			$params[] = (int) $args['agent'];
		}

		if ( $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '( v.name LIKE %s OR v.email LIKE %s OR EXISTS ( SELECT 1 FROM ' . $m . ' sm WHERE sm.conversation_id = c.id AND sm.body LIKE %s ) )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( $args['date_from'] ) {
			$where[]  = 'c.last_message_at >= %s';
			$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( $args['date_from'] ) );
		}
		if ( $args['date_to'] ) {
			$where[]  = 'c.last_message_at <= %s';
			$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( $args['date_to'] ) );
		}

		// Agent scoping (server-side enforcement, never trust the client).
		if ( ! $args['can_all'] ) {
			if ( Settings_Manager::get( 'assignment.agents_see_unassigned', 1 ) ) {
				$where[]  = '( c.agent_id = %d OR c.agent_id = 0 )';
				$params[] = (int) $args['user_id'];
			} else {
				$where[]  = 'c.agent_id = %d';
				$params[] = (int) $args['user_id'];
			}
		}

		$orderby_map = array(
			'last_message_at' => 'c.last_message_at',
			'created_at'      => 'c.created_at',
			'status'          => 'c.status',
			'id'              => 'c.id',
		);
		$orderby_map['recent'] = 'COALESCE(NULLIF(c.last_message_at, \'1970-01-01 00:00:00\'), c.created_at)';
		$orderby     = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : $orderby_map['recent'];
		$order       = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$per_page    = min( 100, max( 1, (int) $args['per_page'] ) );
		$page        = max( 1, (int) $args['page'] );
		$offset      = ( $page - 1 ) * $per_page;

		$where_sql = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$c} c LEFT JOIN {$v} v ON v.id = c.visitor_id WHERE {$where_sql}";
		$list_sql  = "SELECT c.*, v.name AS visitor_name, v.email AS visitor_email, v.current_page AS visitor_page,
			( SELECT CASE WHEN a.id IS NULL THEN '' ELSE CONCAT(a.body) END FROM {$m} a WHERE a.conversation_id = c.id ORDER BY a.id DESC LIMIT 1 ) AS last_message
			FROM {$c} c LEFT JOIN {$v} v ON v.id = c.visitor_id
			WHERE {$where_sql} ORDER BY {$orderby} {$order}, c.id DESC LIMIT %d OFFSET %d";

		$count_params = $params;
		$list_params  = array_merge( $params, array( $per_page, $offset ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$total = $count_params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) )
			: (int) $wpdb->get_var( $count_sql );
		$rows  = $list_params
			? $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) )
			: $wpdb->get_results( $list_sql );
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Count conversations by status.
	 *
	 * @param string $status Status key.
	 * @return int
	 */
	public static function count_by_status( $status ) {
		global $wpdb;
		$c = Database::table( 'conversations' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$c} WHERE status = %s", $status ) );
	}

	/**
	 * Counts visible to the current user (agents see scoped numbers).
	 *
	 * @return array
	 */
	public static function inbox_counts() {
		global $wpdb;
		$c   = Database::table( 'conversations' );
		$me  = get_current_user_id();
		$all = Permissions::can_see_all();

		$scope_sql   = '';
		$scope_param = array();
		if ( ! $all ) {
			if ( Settings_Manager::get( 'assignment.agents_see_unassigned', 1 ) ) {
				$scope_sql   = ' AND ( agent_id = %d OR agent_id = 0 )';
				$scope_param = array( $me );
			} else {
				$scope_sql   = ' AND agent_id = %d';
				$scope_param = array( $me );
			}
		}

		$counts = array(
			'active'     => 0,
			'waiting'    => 0,
			'archived'   => 0,
			'unassigned' => 0,
			'mine'       => 0,
			'unread'     => 0,
		);
		foreach ( array( 'active', 'waiting', 'archived' ) as $status ) {
			$sql = "SELECT COUNT(*) FROM {$c} WHERE status = %s" . $scope_sql;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$counts[ $status ] = (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $status ), $scope_param ) ) );
		}

		$sql = "SELECT COUNT(*) FROM {$c} WHERE agent_id = 0 AND status != 'archived'" . $scope_sql;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts['unassigned'] = $all ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE agent_id = 0 AND status != 'archived'" ) : 0;

		$sql = "SELECT COUNT(*) FROM {$c} WHERE agent_id = %d AND status != 'archived'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts['mine'] = (int) $wpdb->get_var( $wpdb->prepare( $sql, $me ) );

		$sql = "SELECT COALESCE(SUM(admin_unread),0) FROM {$c} WHERE 1=1" . $scope_sql;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts['unread'] = $scope_param ? (int) $wpdb->get_var( $wpdb->prepare( $sql, $scope_param ) ) : (int) $wpdb->get_var( $sql );

		return $counts;
	}

	/**
	 * Mark all outgoing (visitor) messages as read by agents and reset counter.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return void
	 */
	public static function mark_read_by_agent( $conversation_id ) {
		global $wpdb;
		$m = Database::table( 'messages' );
		$wpdb->update(
			$m,
			array(
				'is_read' => '1',
				'read_at' => Utils::now_gmt(),
			),
			array(
				'conversation_id' => absint( $conversation_id ),
				'sender_type'     => 'visitor',
				'is_read'         => '0',
			),
			array( '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);
		$wpdb->update(
			Database::table( 'conversations' ),
			array( 'admin_unread' => 0 ),
			array( 'id' => absint( $conversation_id ) ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Mark all agent messages as read by the visitor and reset counter.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return void
	 */
	public static function mark_read_by_visitor( $conversation_id ) {
		global $wpdb;
		$m = Database::table( 'messages' );
		$wpdb->update(
			$m,
			array(
				'is_read' => '1',
				'read_at' => Utils::now_gmt(),
			),
			array(
				'conversation_id' => absint( $conversation_id ),
				'sender_type'     => array( 'agent', 'admin' ),
				'is_read'         => '0',
			),
			array( '%s', '%s' ),
			array( '%d', '%s', '%s' )
		);
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"UPDATE " . Database::table( 'conversations' ) . " SET visitor_unread = 0, visitor_last_read_message = %d WHERE id = %d",
				Message_Manager::max_id( $conversation_id ),
				absint( $conversation_id )
			)
		);
	}

	/**
	 * Archive conversations that have been inactive for N days.
	 *
	 * @return int Number archived.
	 */
	public static function auto_archive_inactive() {
		global $wpdb;
		$days = (int) apply_filters( 'livedesk_auto_archive_days', 30 );
		if ( $days <= 0 ) {
			return 0;
		}
		$c     = Database::table( 'conversations' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$c} WHERE status IN ('active','waiting') AND last_message_at != '1970-01-01 00:00:00' AND last_message_at < %s",
				$cutoff
			)
		);
		foreach ( $ids as $id ) {
			self::archive( (int) $id );
		}
		return count( $ids );
	}
}
