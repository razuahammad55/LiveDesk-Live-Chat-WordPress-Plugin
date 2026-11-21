<?php
/**
 * Shared formatting and HTTP helpers.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Utils
 */
class Utils {

	/**
	 * GMT "now" in MySQL format.
	 *
	 * @return string
	 */
	public static function now_gmt() {
		return current_time( 'mysql', true );
	}

	/**
	 * Format a stored GMT datetime for display in site timezone.
	 *
	 * @param string $gmt_mysql Datetime in GMT.
	 * @param string $format    PHP date format.
	 * @return string
	 */
	public static function format_datetime( $gmt_mysql, $format = '' ) {
		if ( empty( $gmt_mysql ) || '1970-01-01 00:00:00' === $gmt_mysql ) {
			return '—';
		}
		$format = $format ? $format : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$ts     = strtotime( $gmt_mysql . ' UTC' );
		return wp_date( $format, $ts );
	}

	/**
	 * Human "time ago" string.
	 *
	 * @param string $gmt_mysql Datetime in GMT.
	 * @return string
	 */
	public static function time_ago( $gmt_mysql ) {
		if ( empty( $gmt_mysql ) || '1970-01-01 00:00:00' === $gmt_mysql ) {
			return __( 'no messages yet', 'livedesk' );
		}
		$ts  = strtotime( $gmt_mysql . ' UTC' );
		$now = time();
		$diff = max( 0, $now - $ts );
		if ( $diff < 60 ) {
			return __( 'just now', 'livedesk' );
		}
		return sprintf(
			/* translators: %s: human time diff */
			__( '%s ago', 'livedesk' ),
			human_time_diff( $ts, $now )
		);
	}

	/**
	 * Normalize a REST/JSON payload into an array.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function json_params( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $request->get_body_params();
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Convert a GMT MySQL datetime to ISO 8601 (for JS).
	 *
	 * @param string $gmt_mysql Datetime GMT.
	 * @return string
	 */
	public static function iso( $gmt_mysql ) {
		if ( empty( $gmt_mysql ) ) {
			return '';
		}
		$ts = strtotime( $gmt_mysql . ' UTC' );
		return gmdate( 'c', $ts );
	}

	/**
	 * Parse simple user agent to browser / OS / device labels.
	 *
	 * @param string $ua User agent string.
	 * @return array{browser:string,os:string,device:string}
	 */
	public static function parse_user_agent( $ua ) {
		$ua      = (string) $ua;
		$browser = __( 'Unknown', 'livedesk' );
		$os      = __( 'Unknown', 'livedesk' );
		$device  = 'desktop';

		$browsers = array(
			'Edg/'            => 'Edge',
			'OPR/'            => 'Opera',
			'Chrome/'         => 'Chrome',
			'Firefox/'        => 'Firefox',
			'Safari/'         => 'Safari',
			'MSIE|Trident/'   => 'Internet Explorer',
		);
		foreach ( $browsers as $needle => $label ) {
			if ( preg_match( '#' . str_replace( '/', '\/', $needle ) . '#', $ua ) ) {
				$browser = $label;
				break;
			}
		}

		if ( preg_match( '/Windows NT/', $ua ) ) {
			$os = 'Windows';
		} elseif ( preg_match( '/Mac OS X|Macintosh/', $ua ) ) {
			$os = 'macOS';
		} elseif ( preg_match( '/Android/', $ua ) ) {
			$os = 'Android';
		} elseif ( preg_match( '/iPhone|iPad|iPod/', $ua ) ) {
			$os = 'iOS';
		} elseif ( preg_match( '/Linux/', $ua ) ) {
			$os = 'Linux';
		}

		if ( preg_match( '/Mobi|Android.+Mobile|iPhone|iPod/', $ua ) ) {
			$device = 'mobile';
		} elseif ( preg_match( '/iPad|Tablet/', $ua ) ) {
			$device = 'tablet';
		}

		return array(
			'browser' => self::clip( $browser, 100 ),
			'os'      => self::clip( $os, 100 ),
			'device'  => $device,
		);
	}

	/**
	 * Truncate a string for previews.
	 *
	 * @param string $text Text.
	 * @param int    $len  Length.
	 * @return string
	 */
	public static function excerpt( $text, $len = 80 ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $text ) <= $len ) {
			return $text;
		}
		return rtrim( substr( $text, 0, $len ) ) . '…';
	}

	/**
	 * Simple string clip wrapper (used by several classes).
	 *
	 * @param string $text Text.
	 * @param int    $len  Length.
	 * @return string
	 */
	public static function clip( $text, $len ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $len );
		}
		return substr( $text, 0, $len );
	}

	/**
	 * Generate a human-friendly ticket reference.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return string
	 */
	public static function ticket_ref( $conversation_id ) {
		return sprintf( 'LD-%06d', (int) $conversation_id );
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_woocommerce() {
		return class_exists( 'WooCommerce' );
	}
}
