<?php
/**
 * Deactivation routine.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Deactivator
 */
class Deactivator {

	/**
	 * Runs on deactivation. Keeps all data; only removes scheduled events.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'livedesk_cron_maintenance' );
		flush_rewrite_rules();
	}
}
