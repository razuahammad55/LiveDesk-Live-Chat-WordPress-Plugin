<?php
/**
 * Admin menus, asset loading, settings registration, PDF export handler.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Admin_Controller
 */
class Admin_Controller {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_livedesk_export_pdf', array( $this, 'export_pdf' ) );
		add_action( 'admin_head', array( $this, 'hide_unrelated_admin' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Capability required for the inbox pages.
	 *
	 * @return string
	 */
	private function cap() {
		return Permissions::CAP_AGENT;
	}

	/**
	 * Register the menu tree.
	 *
	 * @return void
	 */
	public function register_menus() {
		add_menu_page(
			__( 'Live Chat', 'livedesk' ),
			__( 'Live Chat', 'livedesk' ),
			$this->cap(),
			'livedesk',
			array( $this, 'render_dashboard' ),
			'dashicons-format-chat',
			26
		);
		add_submenu_page( 'livedesk', __( 'Dashboard', 'livedesk' ), __( 'Dashboard', 'livedesk' ), $this->cap(), 'livedesk', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'livedesk', __( 'Inbox', 'livedesk' ), __( 'Inbox', 'livedesk' ), $this->cap(), 'livedesk-inbox', array( $this, 'render_inbox' ) );

		if ( Permissions::can_see_all() ) {
			add_submenu_page( 'livedesk', __( 'Agents', 'livedesk' ), __( 'Agents', 'livedesk' ), Permissions::CAP_MANAGE, 'livedesk-agents', array( $this, 'render_agents' ) );
			add_submenu_page( 'livedesk', __( 'Canned Responses', 'livedesk' ), __( 'Canned Responses', 'livedesk' ), Permissions::CAP_MANAGE, 'livedesk-canned', array( $this, 'render_canned' ) );
			add_submenu_page( 'livedesk', __( 'Settings', 'livedesk' ), __( 'Settings', 'livedesk' ), Permissions::CAP_SETTINGS, 'livedesk-settings', array( $this, 'render_settings' ) );
		}

		// Unread bubble next to the menu title.
		add_filter( 'add_menu_classes', array( $this, 'menu_bubble' ) );
	}

	/**
	 * Add unread count bubble to the Live Chat menu.
	 *
	 * @param array $menu Menu items.
	 * @return array
	 */
	public function menu_bubble( $menu ) {
		$counts = Conversation_Manager::inbox_counts();
		$unread = (int) $counts['unread'];
		if ( $unread > 0 ) {
			foreach ( $menu as $i => $item ) {
				if ( isset( $item[2] ) && 'livedesk' === $item[2] ) {
					$menu[ $i ][0] .= ' <span class="update-plugins count-' . $unread . '"><span class="update-count">' . $unread . '</span></span>';
				}
			}
		}
		return $menu;
	}

	/**
	 * Register settings with the Settings API (sanitization centralized).
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'livedesk_settings_group',
			Settings_Manager::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'LiveDesk\\Settings_Manager', 'sanitize' ),
				'default'           => Settings_Manager::defaults(),
			)
		);
	}

	/**
	 * Load admin assets only on plugin pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'livedesk' ) ) {
			return;
		}

		wp_enqueue_style( 'livedesk-admin', LIVEDESK_URL . 'assets/css/admin.css', array(), LIVEDESK_VERSION );
		wp_enqueue_script( 'livedesk-admin', LIVEDESK_URL . 'assets/js/admin.js', array(), LIVEDESK_VERSION, true );

		$conversation_id = isset( $_GET['conversation'] ) ? absint( wp_unslash( $_GET['conversation'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification

		wp_localize_script(
			'livedesk-admin',
			'livedeskAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'livedesk_admin' ),
				'restUrl'    => esc_url_raw( rest_url( REST_Controller::NS . '/' ) ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'page'       => sanitize_key( isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : '' ), // phpcs:ignore WordPress.Security.NonceVerification
				'conversationId' => $conversation_id,
				'pollInterval'   => max( 2, (int) Settings_Manager::get( 'realtime.poll_active', 2 ) ) * 1000,
				'sound'          => (bool) Settings_Manager::get( 'notifications.agent_sound', 1 ),
				'desktopNotifications' => (bool) Settings_Manager::get( 'notifications.agent_desktop', 1 ),
				'currentUser'    => get_current_user_id(),
				'capManage'      => Permissions::can_see_all(),
				'cannedResponses' => array_map(
					function ( $row ) {
						return array(
							'id'      => (int) $row->id,
							'title'   => $row->title,
							'content' => $row->content,
						);
					},
					Canned_Responses::all()
				),
				'strings'   => array(
					'confirmDelete'   => __( 'Permanently delete this conversation? This cannot be undone. Type DELETE to confirm.', 'livedesk' ),
					'confirmAgent'    => __( 'Remove this agent? They will lose access to the chat inbox.', 'livedesk' ),
					'sending'         => __( 'Sending…', 'livedesk' ),
					'sent'            => __( 'Sent', 'livedesk' ),
					'error'           => __( 'Something went wrong. Please try again.', 'livedesk' ),
					'saved'           => __( 'Saved', 'livedesk' ),
					'newMessage'      => __( 'New message from', 'livedesk' ),
					'newConversation' => __( 'New conversation from', 'livedesk' ),
					'noteAdded'       => __( 'Note added', 'livedesk' ),
					'typing'          => __( 'Visitor is typing…', 'livedesk' ),
					'offline'         => __( 'You are set to Away', 'livedesk' ),
					'online'          => __( 'You are Available', 'livedesk' ),
					'loadMore'        => __( 'Load more', 'livedesk' ),
					'noMore'          => __( 'No more conversations', 'livedesk' ),
					'chooseFile'      => __( 'Choose file', 'livedesk' ),
					'readMore'        => __( 'Read more', 'livedesk' ),
					'auto'            => __( 'auto', 'livedesk' ),
				),
			)
		);
	}

	/**
	 * Admin notices (activation + errors).
	 *
	 * @return void
	 */
	public function admin_notices() {
		if ( get_transient( 'livedesk_activation_notice' ) ) {
			delete_transient( 'livedesk_activation_notice' );
			$agents_url = admin_url( 'admin.php?page=livedesk-agents' );
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
				esc_html__( 'LiveDesk is ready.', 'livedesk' ),
				esc_html__( 'Chat conversations will land in your Live Chat inbox. Add support agents so the widget reports your team as online.', 'livedesk' ),
				esc_url( $agents_url ),
				esc_html__( 'Manage agents →', 'livedesk' )
			);
		}
	}

	/**
	 * Agents get a clean admin: hide unrelated admin chrome for chat users
	 * who lack `manage_options`. (Hard enforcement remains server-side.)
	 *
	 * @return void
	 */
	public function hide_unrelated_admin() {
		if ( ! Permissions::is_agent() || Permissions::can_see_all() ) {
			return;
		}
		echo '<style id="livedesk-agent-admin">#wpadminbar .ab-item[href*="edit.php"],#wpadminbar .ab-item[href*="post-new.php"],#wpadminbar #wp-admin-bar-new-content,#wpadminbar #wp-admin-bar-comments,#wpadminbar #wp-admin-bar-site-name .ab-sub-wrapper{display:none!important;}</style>';
	}

	/**
	 * Render dashboard page.
	 *
	 * @return void
	 */
	public function render_dashboard() {
		if ( ! Permissions::is_agent() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'livedesk' ) );
		}
		$this->view( 'dashboard' );
	}

	/**
	 * Render inbox page.
	 *
	 * @return void
	 */
	public function render_inbox() {
		if ( ! Permissions::is_agent() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'livedesk' ) );
		}
		$this->view( 'inbox' );
	}

	/**
	 * Render agents page.
	 *
	 * @return void
	 */
	public function render_agents() {
		if ( ! Permissions::can_see_all() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'livedesk' ) );
		}
		$this->view( 'agents' );
	}

	/**
	 * Render canned responses page.
	 *
	 * @return void
	 */
	public function render_canned() {
		if ( ! Permissions::can_see_all() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'livedesk' ) );
		}
		$this->view( 'canned' );
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings() {
		if ( ! current_user_can( Permissions::CAP_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'livedesk' ) );
		}
		$this->view( 'settings' );
	}

	/**
	 * Load a view file.
	 *
	 * @param string $name View name.
	 * @return void
	 */
	private function view( $name ) {
		$file = LIVEDESK_DIR . 'admin/views/' . $name . '.php';
		if ( is_readable( $file ) ) {
			include $file;
		}
	}

	/**
	 * admin-post handler: export one conversation as PDF.
	 *
	 * @return void
	 */
	public function export_pdf() {
		if ( ! Permissions::is_agent() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'livedesk' ), '', array( 'response' => 403 ) );
		}
		$conversation_id = isset( $_GET['conversation'] ) ? absint( wp_unslash( $_GET['conversation'] ) ) : 0;
		check_admin_referer( 'livedesk_export_pdf_' . $conversation_id );

		$conversation = Conversation_Manager::get( $conversation_id );
		if ( ! $conversation ) {
			wp_die( esc_html__( 'Conversation not found.', 'livedesk' ), '', array( 'response' => 404 ) );
		}
		if ( ! Permissions::can_access_conversation( $conversation ) ) {
			wp_die( esc_html__( 'You do not have permission to export this conversation.', 'livedesk' ), '', array( 'response' => 403 ) );
		}

		$messages      = Message_Manager::query( $conversation_id, 0, 100000 );
		$include_notes = isset( $_GET['include_notes'] ) && '1' === $_GET['include_notes'];
		$notes         = $include_notes ? Notes_Manager::for_conversation( $conversation_id ) : array();

		$pdf = PDF_Exporter::render( $conversation, $messages, $notes, $include_notes );

		$filename = 'livedesk-' . ( $conversation->ticket_ref ? $conversation->ticket_ref : $conversation_id ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/**
	 * Full conversation payload used by REST + initial page render.
	 *
	 * @param int $conversation_id Conversation ID.
	 * @return array|\WP_Error
	 */
	public static function conversation_payload( $conversation_id ) {
		$conversation = Conversation_Manager::get( $conversation_id );
		if ( ! $conversation ) {
			return new \WP_Error( 'livedesk_not_found', __( 'Conversation not found.', 'livedesk' ), array( 'status' => 404 ) );
		}
		$visitor = Visitor_Manager::get( (int) $conversation->visitor_id );

		$messages = Message_Manager::query( $conversation_id, 0, (int) Settings_Manager::get( 'realtime.page_size', 30 ) );

		$agents = array();
		foreach ( Agent_Manager::all( true ) as $agent ) {
			$agents[] = array(
				'id'           => (int) $agent->user_id,
				'name'         => Permissions::agent_display_name( (int) $agent->user_id, 'display_name' ),
				'availability' => $agent->availability,
			);
		}
		// Admins are valid assignees even without an agent row.
		foreach ( get_users( array( 'capability' => Permissions::CAP_MANAGE, 'number' => 50 ) ) as $admin ) {
			$agents[] = array(
				'id'           => (int) $admin->ID,
				'name'         => $admin->display_name,
				'availability' => 'admin',
			);
		}

		$assignment_history = array();
		foreach ( Assignment_Manager::history( $conversation_id ) as $entry ) {
			$assignment_history[] = array(
				'from'   => $entry->from_agent_id ? Permissions::agent_display_name( (int) $entry->from_agent_id, 'display_name' ) : __( 'Unassigned', 'livedesk' ),
				'to'     => $entry->to_agent_id ? Permissions::agent_display_name( (int) $entry->to_agent_id, 'display_name' ) : __( 'Unassigned', 'livedesk' ),
				'mode'   => $entry->mode,
				'at'     => Utils::iso( $entry->created_at ),
			);
		}

		return array(
			'ok'           => true,
			'conversation' => array(
				'id'          => (int) $conversation->id,
				'ref'         => $conversation->ticket_ref,
				'status'      => $conversation->status,
				'agent_id'    => (int) $conversation->agent_id,
				'admin_unread' => (int) $conversation->admin_unread,
				'created_at'  => Utils::iso( $conversation->created_at ),
				'created_fmt' => Utils::format_datetime( $conversation->created_at ),
				'last_message_at_fmt' => Utils::time_ago( $conversation->last_message_at ),
			),
			'visitor'      => array(
				'id'           => (int) $conversation->visitor_id,
				'name'         => $visitor ? $visitor->name : '',
				'email'        => $visitor ? $visitor->email : '',
				'browser'      => $visitor ? $visitor->browser : '',
				'os'           => $visitor ? $visitor->os : '',
				'device'       => $visitor ? $visitor->device : '',
				'current_page' => $visitor ? $visitor->current_page : '',
				'landing_page' => $visitor ? $visitor->landing_page : '',
				'created_fmt'  => $visitor ? Utils::format_datetime( $visitor->created_at ) : '',
				'last_activity_fmt' => $visitor ? Utils::time_ago( $visitor->last_activity ) : '',
			),
			'messages'     => Message_Manager::to_array_list( $messages, false ),
			'notes'        => Notes_Manager::to_array( $conversation_id ),
			'agents'       => $agents,
			'assignments'  => $assignment_history,
			'can_manage'   => Permissions::can_see_all(),
			'export_url'   => wp_nonce_url(
				admin_url( 'admin-post.php?action=livedesk_export_pdf&conversation=' . (int) $conversation_id ),
				'livedesk_export_pdf_' . (int) $conversation_id
			),
			'visitor_typing' => Visitor_Manager::is_typing( $conversation_id ),
			'server_ts'    => time(),
		);
	}
}
