<?php
/**
 * Internal notes.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Notes_Manager
 *
 * Notes are private to agents/admins: they never appear in visitor payloads,
 * message queries or PDF exports.
 */
class Notes_Manager {

	/**
	 * Add a note.
	 *
	 * @param int    $conversation_id Conversation ID.
	 * @param string $content         Note body.
	 * @param int    $author_id       Author user ID.
	 * @return int|\WP_Error Note ID.
	 */
	public static function add( $conversation_id, $content, $author_id = 0 ) {
		global $wpdb;
		$content = trim( sanitize_textarea_field( wp_unslash( (string) $content ) ) );
		if ( '' === $content ) {
			return new \WP_Error( 'livedesk_empty_note', __( 'Please write a note first.', 'livedesk' ), array( 'status' => 400 ) );
		}
		$content = Utils::clip( $content, 5000 );
		$ok      = $wpdb->insert(
			Database::table( 'notes' ),
			array(
				'conversation_id' => absint( $conversation_id ),
				'author_id'       => absint( $author_id ? $author_id : get_current_user_id() ),
				'content'         => $content,
				'created_at'      => Utils::now_gmt(),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new \WP_Error( 'livedesk_db_error', __( 'The note could not be saved.', 'livedesk' ), array( 'status' => 500 ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a note.
	 *
	 * @param int $note_id        Note ID.
	 * @param int $conversation_id Conversation ID (scoping).
	 * @return bool
	 */
	public static function delete( $note_id, $conversation_id ) {
		global $wpdb;
		return (bool) $wpdb->delete(
			Database::table( 'notes' ),
			array(
				'id'              => absint( $note_id ),
				'conversation_id' => absint( $conversation_id ),
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Notes for a conversation, newest last.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array
	 */
	public static function for_conversation( $conversation_id ) {
		global $wpdb;
		$t = Database::table( 'notes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$t} WHERE conversation_id = %d ORDER BY id ASC", absint( $conversation_id ) )
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Serialize notes for AJAX.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array
	 */
	public static function to_array( $conversation_id ) {
		$out = array();
		foreach ( self::for_conversation( $conversation_id ) as $note ) {
			$out[] = array(
				'id'         => (int) $note->id,
				'content'    => (string) $note->content,
				'author'     => Permissions::agent_display_name( (int) $note->author_id, 'display_name' ),
				'created_at' => Utils::iso( (string) $note->created_at ),
				'created_ts' => (int) strtotime( (string) $note->created_at . ' UTC' ),
			);
		}
		return $out;
	}

	/**
	 * Count notes (dashboard stat).
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$t = Database::table( 'notes' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
	}
}
