<?php
/**
 * Security helpers: hashing, client IP, rate limiting, honeypot, CAPTCHA.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Security
 */
class Security {

	/**
	 * Hash a visitor token for storage (tokens are never stored in plain text).
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function hash_token( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}

	/**
	 * Generate a 64-char hex visitor token.
	 *
	 * @return string
	 */
	public static function generate_token() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Best-effort client IP (proxies respected only for private-safe values).
	 *
	 * @return string Binary-packed representation safe for VARBINARY(45).
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$ip = filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
		return $ip;
	}

	/**
	 * Whether rate limiting should actually block (never in dev/test contexts).
	 *
	 * @return bool
	 */
	private static function rate_limiting_enabled() {
		/**
		 * Allow developers to disable rate limiting (e.g. automated tests).
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'livedesk_rate_limit_enabled', true );
	}

	/**
	 * Fixed-window rate limiter using transients.
	 *
	 * @param string $bucket  Logical bucket name.
	 * @param int    $limit   Max hits.
	 * @param int    $seconds Window length.
	 * @param string $suffix  Extra identifier (e.g. IP or visitor id).
	 * @return bool True when the request is allowed.
	 */
	public static function rate_limit( $bucket, $limit, $seconds, $suffix = '' ) {
		if ( ! self::rate_limiting_enabled() ) {
			return true;
		}
		$key  = 'ld_rl_' . md5( $bucket . '|' . $suffix );
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['expires'] ) || $data['expires'] < time() ) {
			$data = array( 'count' => 0, 'expires' => time() + $seconds );
		}
		$data['count']++;
		set_transient( $key, $data, $seconds );
		return $data['count'] <= $limit;
	}

	/**
	 * Length validation helper used across endpoints.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max chars.
	 * @return string
	 */
	public static function clip( $value, $max = 5000 ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	/**
	 * Validate a visitor-supplied name.
	 *
	 * @param string $name Raw name.
	 * @return string|\WP_Error
	 */
	public static function validate_name( $name ) {
		$name = sanitize_text_field( wp_unslash( (string) $name ) );
		$name = preg_replace( '/\s+/', ' ', trim( $name ) );
		if ( strlen( $name ) < 2 || strlen( $name ) > 120 ) {
			return new \WP_Error( 'livedesk_invalid_name', __( 'Please enter your name (2–120 characters).', 'livedesk' ), array( 'status' => 400 ) );
		}
		return $name;
	}

	/**
	 * Validate a visitor-supplied email.
	 *
	 * @param string $email Raw email.
	 * @return string|\WP_Error
	 */
	public static function validate_email( $email ) {
		$email = sanitize_email( wp_unslash( (string) $email ) );
		if ( ! is_email( $email ) || strlen( $email ) > 190 ) {
			return new \WP_Error( 'livedesk_invalid_email', __( 'Please enter a valid email address.', 'livedesk' ), array( 'status' => 400 ) );
		}
		return $email;
	}

	/**
	 * Honeypot check: bots fill hidden fields.
	 *
	 * @param array $data Request data.
	 * @return bool True when the submission looks human.
	 */
	public static function honeypot_ok( $data ) {
		if ( ! Settings_Manager::get( 'security.honeypot', 1 ) ) {
			return true;
		}
		$trap = isset( $data['hp'] ) ? (string) $data['hp'] : '';
		return '' === trim( $trap );
	}

	/**
	 * Verify configured CAPTCHA.
	 *
	 * @param array $data Request data (expects `captcha` token).
	 * @return bool|\WP_Error
	 */
	public static function verify_captcha( $data ) {
		$provider = Settings_Manager::get( 'security.captcha_provider', 'none' );
		if ( 'none' === $provider ) {
			return true;
		}
		$secret = Settings_Manager::get( 'security.captcha_secret', '' );
		if ( empty( $secret ) ) {
			// Misconfigured: do not break chat for visitors.
			return true;
		}
		$token = isset( $data['captcha'] ) ? sanitize_text_field( $data['captcha'] ) : '';
		if ( empty( $token ) ) {
			return new \WP_Error( 'livedesk_captcha_missing', __( 'Please complete the spam check.', 'livedesk' ), array( 'status' => 400 ) );
		}

		$endpoint = 'https://www.google.com/recaptcha/api/siteverify';
		$body     = array( 'secret' => $secret, 'response' => $token, 'remoteip' => self::client_ip() );
		if ( 'turnstile' === $provider ) {
			$endpoint = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
		}
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'livedesk_captcha_unreachable', __( 'Could not verify the spam check. Please try again.', 'livedesk' ), array( 'status' => 503 ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $json['success'] ) ) {
			return new \WP_Error( 'livedesk_captcha_failed', __( 'Spam check failed. Please try again.', 'livedesk' ), array( 'status' => 400 ) );
		}
		if ( 'recaptcha_v3' === $provider && isset( $json['score'] ) && (float) $json['score'] < 0.5 ) {
			return new \WP_Error( 'livedesk_captcha_score', __( 'Spam check failed. Please try again.', 'livedesk' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Verify a visitor request: token header / param against stored hash.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return array|\WP_Error array( visitor row, conversation row ) or error.
	 */
	public static function authenticate_visitor( $request ) {
		global $wpdb;
		$token = (string) $request->get_header( 'X-LiveDesk-Token' );
		if ( '' === $token && isset( $_COOKIE['livedesk_token'] ) ) {
			$token = sanitize_text_field( wp_unslash( $_COOKIE['livedesk_token'] ) );
		}
		if ( '' === $token ) {
			$token = (string) $request->get_param( 'token' );
		}
		$token = preg_replace( '/[^a-f0-9]/i', '', $token );
		if ( strlen( $token ) !== 64 ) {
			return new \WP_Error( 'livedesk_unauthenticated', __( 'This chat session is no longer valid.', 'livedesk' ), array( 'status' => 401 ) );
		}

		$visitors = Database::table( 'visitors' );
		$hash     = self::hash_token( $token );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$visitor = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$visitors} WHERE token_hash = %s LIMIT 1", $hash ) );
		if ( ! $visitor ) {
			return new \WP_Error( 'livedesk_unauthenticated', __( 'This chat session is no longer valid.', 'livedesk' ), array( 'status' => 401 ) );
		}
		if ( '1' === (string) $visitor->admin_blocked ) {
			return new \WP_Error( 'livedesk_blocked', __( 'This chat is no longer available.', 'livedesk' ), array( 'status' => 403 ) );
		}

		/*
		 * Session lifetime. A chat session is bound to the browser session: the
		 * client stores the token in sessionStorage and the server independently
		 * refuses tokens that have been idle longer than the configured window.
		 * Either side ending the session means the visitor starts fresh, so a
		 * reopened browser never reveals a previous conversation.
		 */
		$lifetime_hours = (int) Settings_Manager::get( 'general.session_lifetime_hours', 2 );
		if ( $lifetime_hours > 0 && ! empty( $visitor->last_activity ) ) {
			$last = strtotime( $visitor->last_activity . ' UTC' );
			if ( $last && ( time() - $last ) > ( $lifetime_hours * HOUR_IN_SECONDS ) ) {
				Visitor_Manager::expire_token( (int) $visitor->id );
				return new \WP_Error(
					'livedesk_session_expired',
					__( 'Your chat session has ended. Please start a new chat.', 'livedesk' ),
					array( 'status' => 401 )
				);
			}
		}

		$conversation = Conversation_Manager::get_for_visitor( (int) $visitor->id );
		if ( ! $conversation ) {
			return new \WP_Error( 'livedesk_no_conversation', __( 'No conversation found.', 'livedesk' ), array( 'status' => 404 ) );
		}

		return array(
			'visitor'      => $visitor,
			'conversation' => $conversation,
		);
	}

	/**
	 * REST permission callback for logged-in agents.
	 *
	 * @return bool|\WP_Error
	 */
	public static function require_agent() {
		if ( ! is_user_logged_in() || ! current_user_can( Permissions::CAP_AGENT ) ) {
			return new \WP_Error( 'livedesk_forbidden', __( 'You are not allowed to access live chat.', 'livedesk' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * REST permission callback for admins.
	 *
	 * @return bool|\WP_Error
	 */
	public static function require_admin() {
		if ( ! current_user_can( Permissions::CAP_MANAGE ) ) {
			return new \WP_Error( 'livedesk_forbidden', __( 'You are not allowed to perform this action.', 'livedesk' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
