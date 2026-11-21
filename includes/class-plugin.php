<?php
/**
 * Plugin container / bootstrap.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Plugin
 *
 * Wires all modules together. Kept intentionally small: each module owns a
 * single responsibility and registers its own hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Loaded modules keyed by name.
	 *
	 * @var array<string, object>
	 */
	private $modules = array();

	/**
	 * Get instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: boot modules.
	 */
	private function __construct() {
		$this->load_textdomain();

		$this->modules['settings']    = new Settings_Manager();
		$this->modules['roles']       = new Permissions();
		$this->modules['cron']        = new Cron_Manager();
		$this->modules['rest']        = new REST_Controller();
		$this->modules['ajax']        = new AJAX_Controller();
		$this->modules['widget']      = new Frontend_Widget();
		$this->modules['admin']       = new Admin_Controller();

		add_action( 'plugins_loaded', array( $this, 'maybe_migrate' ) );
		add_action( 'init', array( $this, 'on_init' ) );
		add_filter( 'plugin_action_links_' . LIVEDESK_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	private function load_textdomain() {
		load_plugin_textdomain( 'livedesk', false, dirname( LIVEDESK_BASENAME ) . '/languages' );
	}

	/**
	 * Run DB migrations when the stored version is older than the code version.
	 *
	 * @return void
	 */
	public function maybe_migrate() {
		if ( get_option( 'livedesk_db_version' ) !== LIVEDESK_DB_VERSION ) {
			Database::install();
			update_option( 'livedesk_db_version', LIVEDESK_DB_VERSION, false );
		}
	}

	/**
	 * Late init tasks.
	 *
	 * @return void
	 */
	public function on_init() {
		// Ensure attachment storage is protected (defense in depth).
		Attachment_Manager::maybe_write_htaccess();
	}

	/**
	 * Add quick links on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=livedesk-settings' ) ) . '">' . esc_html__( 'Settings', 'livedesk' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Get a module.
	 *
	 * @param string $name Module key.
	 * @return object|null
	 */
	public function module( $name ) {
		return isset( $this->modules[ $name ] ) ? $this->modules[ $name ] : null;
	}
}
