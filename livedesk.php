<?php
/**
 * Plugin Name:       LiveDesk Live Chat
 * Plugin URI:        https://example.com/livedesk
 * Description:       Professional self-hosted live chat and customer support for WordPress. Persistent visitor conversations, agent inbox, attachments, canned replies, notes, PDF export, business hours and more. No external service required.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            LiveDesk
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       livedesk
 * Domain Path:       /languages
 *
 * @package LiveDesk
 */

defined( 'ABSPATH' ) || exit;

define( 'LIVEDESK_VERSION', '1.2.0' );
define( 'LIVEDESK_DB_VERSION', '1.0.0' );
define( 'LIVEDESK_FILE', __FILE__ );
define( 'LIVEDESK_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIVEDESK_URL', plugin_dir_url( __FILE__ ) );
define( 'LIVEDESK_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4-lite autoloader: \LiveDesk\Foo_Bar => includes/class-foo-bar.php
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'LiveDesk\\' ) ) {
			return;
		}
		$relative = substr( $class, 9 );
		$file     = 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		$path     = LIVEDESK_DIR . 'includes/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'LiveDesk\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LiveDesk\\Deactivator', 'deactivate' ) );

/**
 * Main plugin accessor.
 *
 * @return LiveDesk\Plugin
 */
function livedesk() {
	return LiveDesk\Plugin::instance();
}

livedesk();
