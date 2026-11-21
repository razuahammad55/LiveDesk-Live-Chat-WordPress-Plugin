<?php
/**
 * Lightweight logging (only when WP_DEBUG is enabled).
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logger
 */
class Logger {

	/**
	 * Log an event when debugging is on.
	 *
	 * @param string $event Event key.
	 * @param array  $data  Context data.
	 * @return void
	 */
	public static function log( $event, $data = array() ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		$line = '[LiveDesk] ' . $event;
		if ( $data ) {
			$line .= ' ' . wp_json_encode( $data );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}
