<?php
/**
 * Business hours and online/offline resolution.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Business_Hours
 *
 * Availability combines four signals:
 *  1. Manual override (online / offline / auto).
 *  2. Configured business hours (days, times, holidays) using the site's
 *     timezone, or an optional explicit timezone.
 *  3. At least one enabled agent marked "available".
 *
 * The widget only says "online" when the system can actually respond.
 */
class Business_Hours {

	/**
	 * Timezone used for schedule checks.
	 *
	 * @return \DateTimeZone
	 */
	private static function timezone() {
		$tz = (string) Settings_Manager::get( 'hours.timezone', '' );
		if ( '' === $tz ) {
			$tz = wp_timezone_string();
		}
		try {
			return new \DateTimeZone( $tz );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/**
	 * Whether the schedule window is currently open.
	 *
	 * @param \DateTimeImmutable|null $now Optional point in time (for tests).
	 * @return bool
	 */
	public static function within_hours( $now = null ) {
		if ( ! Settings_Manager::get( 'hours.enabled', 0 ) ) {
			return true;
		}
		$now = $now ? $now : new \DateTimeImmutable( 'now', self::timezone() );

		// Holidays.
		$today = $now->format( 'Y-m-d' );
		foreach ( (array) Settings_Manager::get( 'hours.holidays', array() ) as $holiday ) {
			if ( 0 === strpos( (string) $holiday, $today ) ) {
				return false;
			}
		}

		$day_map  = array(
			'mon' => 'mon',
			'tue' => 'tue',
			'wed' => 'wed',
			'thu' => 'thu',
			'fri' => 'fri',
			'sat' => 'sat',
			'sun' => 'sun',
		);
		$dow      = strtolower( $now->format( 'D' ) );
		$day_key  = isset( $day_map[ $dow ] ) ? $day_map[ $dow ] : 'mon';
		$schedule = (array) Settings_Manager::get( 'hours.schedule', array() );
		if ( empty( $schedule[ $day_key ] ) || empty( $schedule[ $day_key ]['enabled'] ) ) {
			return false;
		}
		$start = isset( $schedule[ $day_key ]['start'] ) ? $schedule[ $day_key ]['start'] : '09:00';
		$end   = isset( $schedule[ $day_key ]['end'] ) ? $schedule[ $day_key ]['end'] : '17:00';

		$current = $now->format( 'H:i' );
		if ( $end < $start ) {
			// Overnight window (e.g. 22:00 → 02:00): open if after start OR before end.
			return $current >= $start || $current <= $end;
		}
		return $current >= $start && $current <= $end;
	}

	/**
	 * Whether at least one agent is available.
	 *
	 * @return bool
	 */
	public static function agents_available() {
		/**
		 * Filter whether at least one agent currently counts as available.
		 *
		 * @param bool $available Whether an available agent exists.
		 */
		return (bool) apply_filters( 'livedesk_agents_available', ! empty( Agent_Manager::available_agents() ) );
	}

	/**
	 * Whether chat is currently online.
	 *
	 * @return bool
	 */
	public static function is_online() {
		$override = Settings_Manager::get( 'hours.manual_override', 'auto' );
		if ( 'online' === $override ) {
			return true;
		}
		if ( 'offline' === $override ) {
			return false;
		}
		return self::within_hours() && self::agents_available();
	}

	/**
	 * Offline behaviour ("chat" = normal chat, "contact_form" = offline form).
	 *
	 * @return string
	 */
	public static function offline_mode() {
		return in_array( Settings_Manager::get( 'offline.mode', 'chat' ), array( 'chat', 'contact_form' ), true )
			? Settings_Manager::get( 'offline.mode', 'chat' )
			: 'chat';
	}
}
