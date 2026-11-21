<?php
/**
 * Canned responses (saved replies).
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Canned_Responses
 */
class Canned_Responses {

	/**
	 * List all canned responses.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$t = Database::table( 'canned' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY title ASC" );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get one.
	 *
	 * @param int $id Response ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = Database::table( 'canned' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", absint( $id ) ) );
	}

	/**
	 * Create.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return int|\WP_Error
	 */
	public static function create( $title, $content ) {
		global $wpdb;
		$title   = sanitize_text_field( wp_unslash( (string) $title ) );
		$content = trim( sanitize_textarea_field( wp_unslash( (string) $content ) ) );
		if ( '' === $title || '' === $content ) {
			return new \WP_Error( 'livedesk_invalid', __( 'Both a title and content are required.', 'livedesk' ), array( 'status' => 400 ) );
		}
		$ok = $wpdb->insert(
			Database::table( 'canned' ),
			array(
				'title'      => Utils::clip( $title, 190 ),
				'content'    => Utils::clip( $content, 5000 ),
				'created_by' => get_current_user_id(),
				'updated_at' => Utils::now_gmt(),
			),
			array( '%s', '%s', '%d', '%s' )
		);
		if ( ! $ok ) {
			return new \WP_Error( 'livedesk_db_error', __( 'The response could not be saved.', 'livedesk' ), array( 'status' => 500 ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update.
	 *
	 * @param int    $id      Response ID.
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return bool|\WP_Error
	 */
	public static function update( $id, $title, $content ) {
		global $wpdb;
		$title   = sanitize_text_field( wp_unslash( (string) $title ) );
		$content = trim( sanitize_textarea_field( wp_unslash( (string) $content ) ) );
		if ( '' === $title || '' === $content ) {
			return new \WP_Error( 'livedesk_invalid', __( 'Both a title and content are required.', 'livedesk' ), array( 'status' => 400 ) );
		}
		$wpdb->update(
			Database::table( 'canned' ),
			array(
				'title'      => Utils::clip( $title, 190 ),
				'content'    => Utils::clip( $content, 5000 ),
				'updated_at' => Utils::now_gmt(),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * Delete.
	 *
	 * @param int $id Response ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( Database::table( 'canned' ), array( 'id' => absint( $id ) ), array( '%d' ) );
	}
}
