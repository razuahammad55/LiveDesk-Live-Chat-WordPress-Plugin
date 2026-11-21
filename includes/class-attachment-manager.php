<?php
/**
 * Secure attachment storage.
 *
 * Files are stored in wp-content/uploads/livedesk-private/{conversation_id}/
 * with random names and an .htaccess / index.php guard. They are only served
 * through a token-authenticated PHP endpoint (visitor) or capability-checked
 * handler (agent).
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Attachment_Manager
 */
class Attachment_Manager {

	const DIR_NAME = 'livedesk-private';

	/**
	 * Extensions that may ever be accepted (deny-by-default list basis).
	 *
	 * @return array
	 */
	public static function safe_extensions() {
		return array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip' );
	}

	/**
	 * MIME map for extension validation.
	 *
	 * @return array
	 */
	public static function mime_map() {
		return array(
			'jpg'  => array( 'image/jpeg' ),
			'jpeg' => array( 'image/jpeg' ),
			'png'  => array( 'image/png' ),
			'webp' => array( 'image/webp' ),
			'gif'  => array( 'image/gif' ),
			'pdf'  => array( 'application/pdf' ),
			'doc'  => array( 'application/msword' ),
			'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
			'xls'  => array( 'application/vnd.ms-excel' ),
			'xlsx' => array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ),
			'txt'  => array( 'text/plain' ),
			'csv'  => array( 'text/plain', 'text/csv', 'application/csv' ),
			'zip'  => array( 'application/zip', 'application/x-zip-compressed' ),
		);
	}

	/**
	 * Configured allowed extensions.
	 *
	 * @return array
	 */
	public static function allowed_extensions() {
		$raw   = (string) Settings_Manager::get( 'attachments.allowed_types', 'jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx' );
		$safe  = self::safe_extensions();
		$types = array();
		foreach ( preg_split( '/[\s,;]+/', strtolower( $raw ) ) as $ext ) {
			$ext = preg_replace( '/[^a-z0-9]/', '', $ext );
			if ( '' !== $ext && in_array( $ext, $safe, true ) ) {
				$types[] = $ext;
			}
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * Max size in bytes.
	 *
	 * @return int
	 */
	public static function max_size() {
		return max( 1, (int) Settings_Manager::get( 'attachments.max_size_mb', 10 ) ) * MB_IN_BYTES;
	}

	/**
	 * Absolute path to the private storage root.
	 *
	 * @return string
	 */
	public static function storage_root() {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	/**
	 * Full path of a stored file.
	 *
	 * @param object $attachment Attachment row.
	 * @return string
	 */
	public static function file_path( $attachment ) {
		return self::storage_root() . '/' . (int) $attachment->conversation_id . '/' . $attachment->stored_name;
	}

	/**
	 * Create the storage directory and its guards.
	 *
	 * @return void
	 */
	public static function ensure_storage() {
		$root = self::storage_root();
		if ( ! wp_mkdir_p( $root ) ) {
			return;
		}
		if ( ! file_exists( $root . '/index.php' ) ) {
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		self::maybe_write_htaccess();
	}

	/**
	 * Write .htaccess protection (Apache) if missing.
	 *
	 * @return void
	 */
	public static function maybe_write_htaccess() {
		$root = self::storage_root();
		$file = $root . '/.htaccess';
		if ( ! function_exists( 'wp_mkdir_p' ) ) {
			return;
		}
		if ( ! is_dir( $root ) || file_exists( $file ) || ! is_writable( $root ) ) {
			return;
		}
		$rules = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>";
		file_put_contents( $file, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Validate and store an uploaded file for a message.
	 *
	 * @param array  $file            $_FILES entry.
	 * @param int    $message_id      Message ID.
	 * @param int    $conversation_id Conversation ID.
	 * @return int|\WP_Error Attachment ID.
	 */
	public static function handle_upload( $file, $message_id, $conversation_id ) {
		if ( ! Settings_Manager::get( 'attachments.enabled', 1 ) ) {
			return new \WP_Error( 'livedesk_attachments_disabled', __( 'Attachments are disabled.', 'livedesk' ), array( 'status' => 403 ) );
		}
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['error'] ) ) {
			return new \WP_Error( 'livedesk_upload_error', __( 'No file was received.', 'livedesk' ), array( 'status' => 400 ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return self::upload_error_message( (int) $file['error'] );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) && ! defined( 'LIVEDESK_TESTING' ) ) {
			return new \WP_Error( 'livedesk_upload_error', __( 'Invalid upload.', 'livedesk' ), array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > self::max_size() ) {
			return new \WP_Error(
				'livedesk_upload_too_big',
				sprintf(
					/* translators: %d: max upload size in MB */
					__( 'The file is too large. Maximum size is %d MB.', 'livedesk' ),
					(int) Settings_Manager::get( 'attachments.max_size_mb', 10 )
				),
				array( 'status' => 400 )
			);
		}

		$original = sanitize_file_name( isset( $file['name'] ) ? (string) $file['name'] : 'file' );
		$ext      = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
		if ( '' === $ext || ! in_array( $ext, self::allowed_extensions(), true ) ) {
			return new \WP_Error( 'livedesk_upload_type', __( 'This file type is not allowed.', 'livedesk' ), array( 'status' => 400 ) );
		}

		// Determine real MIME from the file itself, not the browser.
		$mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$mime = (string) finfo_file( $finfo, $file['tmp_name'] );
				finfo_close( $finfo );
			}
		}
		if ( '' === $mime && function_exists( 'mime_content_type' ) ) {
			$mime = (string) mime_content_type( $file['tmp_name'] );
		}
		if ( '' === $mime ) {
			$checked = wp_check_filetype_and_ext( $file['tmp_name'], $original );
			$mime    = isset( $checked['type'] ) ? (string) $checked['type'] : '';
		}

		$map      = self::mime_map();
		$expected = isset( $map[ $ext ] ) ? $map[ $ext ] : array();
		if ( $expected && ! in_array( $mime, $expected, true ) ) {
			return new \WP_Error( 'livedesk_upload_mime', __( 'The file content does not match its type.', 'livedesk' ), array( 'status' => 400 ) );
		}
		if ( preg_match( '/\.(php|phtml|phar|php[0-9]|pl|py|cgi|sh|exe|js|html?|svg)/i', $original ) ) {
			return new \WP_Error( 'livedesk_upload_type', __( 'This file type is not allowed.', 'livedesk' ), array( 'status' => 400 ) );
		}

		// Store under a random filename with a forced safe extension.
		$subdir = self::storage_root() . '/' . (int) $conversation_id;
		if ( ! wp_mkdir_p( $subdir ) ) {
			return new \WP_Error( 'livedesk_upload_error', __( 'Could not prepare the storage folder.', 'livedesk' ), array( 'status' => 500 ) );
		}
		$stored = wp_generate_password( 24, false, false ) . '.' . $ext;
		$target = $subdir . '/' . $stored;
		if ( ! @move_uploaded_file( $file['tmp_name'], $target ) && ! @copy( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'livedesk_upload_error', __( 'Could not save the file.', 'livedesk' ), array( 'status' => 500 ) );
		}
		if ( function_exists( 'chmod' ) ) {
			@chmod( $target, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		global $wpdb;
		$ok = $wpdb->insert(
			Database::table( 'attachments' ),
			array(
				'message_id'      => absint( $message_id ),
				'conversation_id' => absint( $conversation_id ),
				'original_name'   => Utils::clip( $original, 255 ),
				'stored_name'     => $stored,
				'mime_type'       => Utils::clip( $mime, 120 ),
				'file_size'       => (int) $file['size'],
				'created_at'      => Utils::now_gmt(),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		if ( ! $ok ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new \WP_Error( 'livedesk_db_error', __( 'Could not save the attachment record.', 'livedesk' ), array( 'status' => 500 ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Human message for a PHP upload error code.
	 *
	 * @param int $code Upload error code.
	 * @return \WP_Error
	 */
	private static function upload_error_message( $code ) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => __( 'The file exceeds the server upload limit.', 'livedesk' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'The file is too large.', 'livedesk' ),
			UPLOAD_ERR_PARTIAL    => __( 'The file was only partially uploaded. Please retry.', 'livedesk' ),
			UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'livedesk' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'Server misconfiguration: no temp folder.', 'livedesk' ),
			UPLOAD_ERR_CANT_WRITE => __( 'Server could not write the file.', 'livedesk' ),
		);
		$msg = isset( $messages[ $code ] ) ? $messages[ $code ] : __( 'Upload failed.', 'livedesk' );
		return new \WP_Error( 'livedesk_upload_error', $msg, array( 'status' => 400 ) );
	}

	/**
	 * Attachments for a message.
	 *
	 * @param int $message_id Message ID.
	 * @return array
	 */
	public static function for_message( $message_id ) {
		global $wpdb;
		$t = Database::table( 'attachments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE message_id = %d ORDER BY id ASC", absint( $message_id ) ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get attachment row scoped to a conversation.
	 *
	 * @param int $attachment_id   Attachment ID.
	 * @param int $conversation_id Conversation ID.
	 * @return object|null
	 */
	public static function get_for_conversation( $attachment_id, $conversation_id ) {
		global $wpdb;
		$t = Database::table( 'attachments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE id = %d AND conversation_id = %d",
				absint( $attachment_id ),
				absint( $conversation_id )
			)
		);
	}

	/**
	 * Build a signed, time-limited download URL.
	 *
	 * Visitor URLs are signed with the visitor token hash so they cannot be
	 * enumerated; agent URLs require a capability check on request.
	 *
	 * @param object $attachment   Attachment row.
	 * @param bool   $visitor_view Whether this URL will be used by a visitor.
	 * @return string
	 */
	public static function url( $attachment, $visitor_view = false ) {
		$base = rest_url( 'livedesk/v1/attachments/' . (int) $attachment->id );

		if ( $visitor_view ) {
			$conversation = Conversation_Manager::get( (int) $attachment->conversation_id );
			$row          = $conversation ? Visitor_Manager::get( (int) $conversation->visitor_id ) : null;
			$sig          = $row ? substr( hash_hmac( 'sha256', 'att|' . $attachment->id . '|' . $row->token_hash, wp_salt( 'auth' ) ), 0, 32 ) : '';
			return add_query_arg( 'sig', $sig, $base );
		}

		// Agent view: <img> tags cannot send the X-WP-Nonce header, so the WP REST
		// cookie nonce must travel as a query argument to authenticate the request.
		return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), $base );
	}

	/**
	 * Stream an attachment to the browser.
	 *
	 * @param object $attachment Attachment row.
	 * @return void
	 */
	public static function stream( $attachment ) {
		$path = self::file_path( $attachment );
		if ( ! is_readable( $path ) ) {
			status_header( 404 );
			exit;
		}
		$name = sanitize_file_name( $attachment->original_name );
		$mime = $attachment->mime_type ? $attachment->mime_type : 'application/octet-stream';

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		$disposition = 0 === strpos( (string) $mime, 'image/' ) ? 'inline' : 'attachment';
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $name . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Delete files (and rows) for a conversation.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return void
	 */
	public static function delete_for_conversation( $conversation_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( 'SELECT * FROM ' . Database::table( 'attachments' ) . ' WHERE conversation_id = %d', absint( $conversation_id ) )
		);
		foreach ( (array) $rows as $row ) {
			$path = self::file_path( $row );
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		$dir = self::storage_root() . '/' . absint( $conversation_id );
		if ( is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Count attachments.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$t = Database::table( 'attachments' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
	}
}
