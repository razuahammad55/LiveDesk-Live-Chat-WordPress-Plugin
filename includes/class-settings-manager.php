<?php
/**
 * Settings registry.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Settings_Manager
 *
 * All plugin settings live in a single option (array) so one autoloaded
 * option powers the whole plugin. Sanitization is centralized here.
 */
class Settings_Manager {

	const OPTION = 'livedesk_settings';

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'general'       => array(
				'widget_title'      => __( 'Live Chat', 'livedesk' ),
				'welcome_message'   => __( 'Hi there! 👋 Send us a message and our team will get back to you as soon as possible.', 'livedesk' ),
				'logo_url'          => '',
				'color_primary'     => '#2563eb',
				'color_secondary'   => '#ffffff',
				'position'          => 'right',
				'width'             => 380,
				'height'            => 560,
				'launcher_text'     => __( 'Chat with us', 'livedesk' ),
				'agent_display'     => 'first_name',
				'sound_default'     => 1,
				// A chat session is bound to the browser session; this is the
				// server-side backstop (minutes of idleness).
				'session_lifetime_hours' => 2,
				'welcome_enabled'    => 1,
				'welcome_chat_message' => __( 'Hi {user_name}, how can we help you today?', 'livedesk' ),
				'ack_enabled'        => 1,
				'ack_message'        => __( 'Thanks for reaching out! An agent will be with you shortly. Please wait while we connect you.', 'livedesk' ),
			),
			'visibility'    => array(
				'mode'              => 'everywhere', // everywhere|selected|exclude.
				'post_ids'          => array(),
				'post_types'        => array(),
				'wc_hide_cart_checkout' => 0,
			),
			'hours'         => array(
				'enabled'           => 0,
				'timezone'          => '',
				'schedule'          => array(
					'mon' => array( 'enabled' => 1, 'start' => '09:00', 'end' => '17:00' ),
					'tue' => array( 'enabled' => 1, 'start' => '09:00', 'end' => '17:00' ),
					'wed' => array( 'enabled' => 1, 'start' => '09:00', 'end' => '17:00' ),
					'thu' => array( 'enabled' => 1, 'start' => '09:00', 'end' => '17:00' ),
					'fri' => array( 'enabled' => 1, 'start' => '09:00', 'end' => '17:00' ),
					'sat' => array( 'enabled' => 0, 'start' => '09:00', 'end' => '17:00' ),
					'sun' => array( 'enabled' => 0, 'start' => '09:00', 'end' => '17:00' ),
				),
				'holidays'          => array(), // List of 'YYYY-MM-DD|Label'.
				'manual_override'   => 'auto',   // auto|online|offline.
			),
			'assignment'    => array(
				'mode'              => 'manual', // manual|auto.
				'strategy'          => 'round_robin', // round_robin|least_busy.
				'auto_auto_reply'   => 1,
				'agents_see_unassigned' => 1,
			),
			'offline'       => array(
				'mode'              => 'chat', // chat|contact_form.
				'heading'           => __( 'We are currently offline', 'livedesk' ),
				'message'           => __( 'Leave us a message and we will reply as soon as we are back.', 'livedesk' ),
				'success'           => __( 'Thanks! Your message has been received. We will get back to you soon.', 'livedesk' ),
			),
			'notifications' => array(
				'admin_recipients'  => '',
				'notify_new'        => 1,
				'notify_reopen'     => 0,
				'notify_assignment' => 0,
				'agent_sound'       => 1,
				'agent_desktop'     => 1,
			),
			'attachments'   => array(
				'enabled'           => 1,
				'max_size_mb'       => 10,
				'allowed_types'     => 'jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx',
			),
			'security'      => array(
				'rate_messages'     => 30,  // per minute per visitor.
				'rate_starts'       => 10,  // new visitors per hour per IP.
				'honeypot'          => 1,
				'captcha_provider'  => 'none', // none|recaptcha_v2|recaptcha_v3|turnstile.
				'captcha_site_key'  => '',
				'captcha_secret'    => '',
				'require_email_match' => 1,
				// Email is not an auth factor: leave OFF so a known address never
				// unlocks another person's history.
				'email_history_resume' => 0,
			),
			'retention'     => array(
				'auto_delete'       => 1,
				'archived_days'     => 180,
				'notify_before_days' => 7,
				'delete_attachments' => 1,
			),
			'realtime'      => array(
				'poll_active'       => 2,
				'poll_idle'         => 20,
				'poll_waiting'      => 2,
				'typing_enabled'    => 1,
				'page_size'         => 30,
			),
			'privacy'       => array(
				'consent_text'      => '',
			),
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			if ( ! is_array( $stored ) ) {
				$stored = array();
			}
			$settings = self::merge_deep( self::defaults(), $stored );
			/**
			 * Filter all plugin settings.
			 *
			 * @param array $settings Settings array.
			 */
			self::$cache = apply_filters( 'livedesk_settings', $settings );
		}
		return self::$cache;
	}

	/**
	 * Get one setting using dot notation, e.g. get('general.position').
	 *
	 * @param string $path    Dot path.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $path, $default = null ) {
		$settings = self::all();
		$keys     = explode( '.', $path );
		$value    = $settings;
		foreach ( $keys as $key ) {
			if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
				return $default;
			}
			$value = $value[ $key ];
		}
		return $value;
	}

	/**
	 * Deep merge stored settings over defaults (defaults win for missing keys).
	 *
	 * @param array $defaults Defaults.
	 * @param array $stored   Stored values.
	 * @return array
	 */
	private static function merge_deep( $defaults, $stored ) {
		foreach ( $stored as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			if ( is_array( $defaults[ $key ] ) && is_array( $value ) ) {
				if ( self::is_list( $defaults[ $key ] ) || self::is_list( $value ) ) {
					$defaults[ $key ] = $value;
				} else {
					$defaults[ $key ] = self::merge_deep( $defaults[ $key ], $value );
				}
			} else {
				$defaults[ $key ] = $value;
			}
		}
		return $defaults;
	}

	/**
	 * Whether an array is a list (numeric keys).
	 *
	 * @param array $arr Array.
	 * @return bool
	 */
	private static function is_list( $arr ) {
		if ( array() === $arr ) {
			return true;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * Reset static cache (used after saving).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Sanitize settings on save. Unknown keys are dropped, defaults preserved.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		// General.
		$g = isset( $input['general'] ) && is_array( $input['general'] ) ? $input['general'] : array();
		$clean['general'] = array(
			'widget_title'    => sanitize_text_field( isset( $g['widget_title'] ) ? $g['widget_title'] : $defaults['general']['widget_title'] ),
			'welcome_message' => sanitize_textarea_field( isset( $g['welcome_message'] ) ? $g['welcome_message'] : $defaults['general']['welcome_message'] ),
			'logo_url'        => esc_url_raw( isset( $g['logo_url'] ) ? $g['logo_url'] : '' ),
			'color_primary'   => self::sanitize_hex( isset( $g['color_primary'] ) ? $g['color_primary'] : '', $defaults['general']['color_primary'] ),
			'color_secondary' => self::sanitize_hex( isset( $g['color_secondary'] ) ? $g['color_secondary'] : '', $defaults['general']['color_secondary'] ),
			'position'        => in_array( isset( $g['position'] ) ? $g['position'] : '', array( 'left', 'right' ), true ) ? $g['position'] : 'right',
			'width'           => min( 600, max( 300, absint( isset( $g['width'] ) ? $g['width'] : 380 ) ) ),
			'height'          => min( 900, max( 400, absint( isset( $g['height'] ) ? $g['height'] : 560 ) ) ),
			'launcher_text'   => sanitize_text_field( isset( $g['launcher_text'] ) ? $g['launcher_text'] : '' ),
			'agent_display'   => in_array( isset( $g['agent_display'] ) ? $g['agent_display'] : '', array( 'first_name', 'display_name' ), true ) ? $g['agent_display'] : 'first_name',
			'session_lifetime_hours' => min( 72, max( 0, absint( isset( $g['session_lifetime_hours'] ) ? $g['session_lifetime_hours'] : 2 ) ) ),
			'welcome_enabled' => empty( $g['welcome_enabled'] ) ? 0 : 1,
			'welcome_chat_message' => sanitize_textarea_field( isset( $g['welcome_chat_message'] ) ? $g['welcome_chat_message'] : $defaults['general']['welcome_chat_message'] ),
			'ack_enabled'     => empty( $g['ack_enabled'] ) ? 0 : 1,
			'ack_message'     => sanitize_textarea_field( isset( $g['ack_message'] ) ? $g['ack_message'] : $defaults['general']['ack_message'] ),
			'sound_default'   => empty( $g['sound_default'] ) ? 0 : 1,
		);

		// Visibility.
		$v = isset( $input['visibility'] ) && is_array( $input['visibility'] ) ? $input['visibility'] : array();
		$ids = array();
		if ( isset( $v['post_ids'] ) ) {
			$ids = is_array( $v['post_ids'] ) ? $v['post_ids'] : explode( ',', (string) $v['post_ids'] );
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		}
		$types = array();
		if ( isset( $v['post_types'] ) ) {
			$types = is_array( $v['post_types'] ) ? $v['post_types'] : array();
			$types = array_values( array_filter( array_map( 'sanitize_key', $types ) ) );
		}
		$clean['visibility'] = array(
			'mode'                  => in_array( isset( $v['mode'] ) ? $v['mode'] : '', array( 'everywhere', 'selected', 'exclude' ), true ) ? $v['mode'] : 'everywhere',
			'post_ids'              => $ids,
			'post_types'            => $types,
			'wc_hide_cart_checkout' => empty( $v['wc_hide_cart_checkout'] ) ? 0 : 1,
		);

		// Hours.
		$h = isset( $input['hours'] ) && is_array( $input['hours'] ) ? $input['hours'] : array();
		$schedule = array();
		foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $day ) {
			$schedule[ $day ] = array(
				'enabled' => ! empty( $h['schedule'][ $day ]['enabled'] ) ? 1 : 0,
				'start'   => self::sanitize_time( isset( $h['schedule'][ $day ]['start'] ) ? $h['schedule'][ $day ]['start'] : '09:00' ),
				'end'     => self::sanitize_time( isset( $h['schedule'][ $day ]['end'] ) ? $h['schedule'][ $day ]['end'] : '17:00' ),
			);
		}
		$holidays = array();
		if ( isset( $h['holidays'] ) ) {
			$lines = is_array( $h['holidays'] ) ? $h['holidays'] : preg_split( '/\r\n|\r|\n/', (string) $h['holidays'] );
			foreach ( $lines as $line ) {
				$line = sanitize_text_field( trim( $line ) );
				if ( '' !== $line && preg_match( '/^\d{4}-\d{2}-\d{2}/', $line ) ) {
					$holidays[] = $line;
				}
			}
		}
		$clean['hours'] = array(
			'enabled'         => empty( $h['enabled'] ) ? 0 : 1,
			'timezone'        => isset( $h['timezone'] ) ? sanitize_text_field( $h['timezone'] ) : '',
			'schedule'        => $schedule,
			'holidays'        => $holidays,
			'manual_override' => in_array( isset( $h['manual_override'] ) ? $h['manual_override'] : '', array( 'auto', 'online', 'offline' ), true ) ? $h['manual_override'] : 'auto',
		);

		// Assignment.
		$a = isset( $input['assignment'] ) && is_array( $input['assignment'] ) ? $input['assignment'] : array();
		$clean['assignment'] = array(
			'mode'                  => in_array( isset( $a['mode'] ) ? $a['mode'] : '', array( 'manual', 'auto' ), true ) ? $a['mode'] : 'manual',
			'strategy'              => in_array( isset( $a['strategy'] ) ? $a['strategy'] : '', array( 'round_robin', 'least_busy' ), true ) ? $a['strategy'] : 'round_robin',
			'auto_auto_reply'       => empty( $a['auto_auto_reply'] ) ? 0 : 1,
			'agents_see_unassigned' => empty( $a['agents_see_unassigned'] ) ? 0 : 1,
		);

		// Offline.
		$o = isset( $input['offline'] ) && is_array( $input['offline'] ) ? $input['offline'] : array();
		$clean['offline'] = array(
			'mode'    => in_array( isset( $o['mode'] ) ? $o['mode'] : '', array( 'chat', 'contact_form' ), true ) ? $o['mode'] : 'chat',
			'heading' => sanitize_text_field( isset( $o['heading'] ) ? $o['heading'] : $defaults['offline']['heading'] ),
			'message' => sanitize_textarea_field( isset( $o['message'] ) ? $o['message'] : $defaults['offline']['message'] ),
			'success' => sanitize_textarea_field( isset( $o['success'] ) ? $o['success'] : $defaults['offline']['success'] ),
		);

		// Notifications.
		$n = isset( $input['notifications'] ) && is_array( $input['notifications'] ) ? $input['notifications'] : array();
		$recipients = array();
		if ( ! empty( $n['admin_recipients'] ) ) {
			foreach ( preg_split( '/[\s,;]+/', (string) $n['admin_recipients'] ) as $email ) {
				$email = sanitize_email( $email );
				if ( is_email( $email ) ) {
					$recipients[] = $email;
				}
			}
		}
		$clean['notifications'] = array(
			'admin_recipients'  => implode( ',', array_unique( $recipients ) ),
			'notify_new'        => empty( $n['notify_new'] ) ? 0 : 1,
			'notify_reopen'     => empty( $n['notify_reopen'] ) ? 0 : 1,
			'notify_assignment' => empty( $n['notify_assignment'] ) ? 0 : 1,
			'agent_sound'       => empty( $n['agent_sound'] ) ? 0 : 1,
			'agent_desktop'     => empty( $n['agent_desktop'] ) ? 0 : 1,
		);

		// Attachments.
		$at = isset( $input['attachments'] ) && is_array( $input['attachments'] ) ? $input['attachments'] : array();
		$types_raw = isset( $at['allowed_types'] ) ? (string) $at['allowed_types'] : $defaults['attachments']['allowed_types'];
		$types     = array();
		foreach ( preg_split( '/[\s,;]+/', strtolower( $types_raw ) ) as $ext ) {
			$ext = preg_replace( '/[^a-z0-9]/', '', $ext );
			if ( '' !== $ext && in_array( $ext, Attachment_Manager::safe_extensions(), true ) ) {
				$types[] = $ext;
			}
		}
		$clean['attachments'] = array(
			'enabled'       => empty( $at['enabled'] ) ? 0 : 1,
			'max_size_mb'   => min( 50, max( 1, absint( isset( $at['max_size_mb'] ) ? $at['max_size_mb'] : 10 ) ) ),
			'allowed_types' => implode( ',', array_unique( $types ) ),
		);

		// Security.
		$sec = isset( $input['security'] ) && is_array( $input['security'] ) ? $input['security'] : array();
		$clean['security'] = array(
			'rate_messages'       => min( 240, max( 1, absint( isset( $sec['rate_messages'] ) ? $sec['rate_messages'] : 30 ) ) ),
			'rate_starts'         => min( 240, max( 1, absint( isset( $sec['rate_starts'] ) ? $sec['rate_starts'] : 10 ) ) ),
			'honeypot'            => empty( $sec['honeypot'] ) ? 0 : 1,
			'captcha_provider'    => in_array( isset( $sec['captcha_provider'] ) ? $sec['captcha_provider'] : '', array( 'none', 'recaptcha_v2', 'recaptcha_v3', 'turnstile' ), true ) ? $sec['captcha_provider'] : 'none',
			'captcha_site_key'    => sanitize_text_field( isset( $sec['captcha_site_key'] ) ? $sec['captcha_site_key'] : '' ),
			'captcha_secret'      => sanitize_text_field( isset( $sec['captcha_secret'] ) ? $sec['captcha_secret'] : '' ),
			'require_email_match' => empty( $sec['require_email_match'] ) ? 0 : 1,
			'email_history_resume' => empty( $sec['email_history_resume'] ) ? 0 : 1,
		);

		// Retention.
		$r = isset( $input['retention'] ) && is_array( $input['retention'] ) ? $input['retention'] : array();
		$clean['retention'] = array(
			'auto_delete'        => empty( $r['auto_delete'] ) ? 0 : 1,
			'archived_days'      => min( 3650, max( 1, absint( isset( $r['archived_days'] ) ? $r['archived_days'] : 180 ) ) ),
			'notify_before_days' => min( 90, max( 0, absint( isset( $r['notify_before_days'] ) ? $r['notify_before_days'] : 7 ) ) ),
			'delete_attachments' => empty( $r['delete_attachments'] ) ? 0 : 1,
		);

		// Realtime.
		$rt = isset( $input['realtime'] ) && is_array( $input['realtime'] ) ? $input['realtime'] : array();
		$clean['realtime'] = array(
			'poll_active'    => min( 30, max( 1, absint( isset( $rt['poll_active'] ) ? $rt['poll_active'] : 3 ) ) ),
			'poll_idle'      => min( 120, max( 5, absint( isset( $rt['poll_idle'] ) ? $rt['poll_idle'] : 20 ) ) ),
			'poll_waiting'   => min( 30, max( 1, absint( isset( $rt['poll_waiting'] ) ? $rt['poll_waiting'] : 3 ) ) ),
			'typing_enabled' => empty( $rt['typing_enabled'] ) ? 0 : 1,
			'page_size'      => min( 100, max( 10, absint( isset( $rt['page_size'] ) ? $rt['page_size'] : 30 ) ) ),
		);

		// Privacy.
		$p = isset( $input['privacy'] ) && is_array( $input['privacy'] ) ? $input['privacy'] : array();
		$clean['privacy'] = array(
			'consent_text' => sanitize_textarea_field( isset( $p['consent_text'] ) ? $p['consent_text'] : '' ),
		);

		return $clean;
	}

	/**
	 * Sanitize a hex color.
	 *
	 * @param string $value    Raw value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function sanitize_hex( $value, $fallback ) {
		$value = is_string( $value ) ? $value : '';
		return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $value ) ? $value : $fallback;
	}

	/**
	 * Sanitize "HH:MM" time.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function sanitize_time( $value ) {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? $value : '09:00';
	}
}
