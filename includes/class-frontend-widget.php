<?php
/**
 * Front page widget: conditional asset loading and config.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

/**
 * Class Frontend_Widget
 */
class Frontend_Widget {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
		add_action( 'wp_footer', array( $this, 'render_container' ), 30 );
		add_shortcode( 'livedesk_chat', array( $this, 'shortcode' ) );
	}

	/**
	 * Whether the widget must render on the current request.
	 *
	 * @return bool
	 */
	public function should_display() {
		// Never in wp-admin, feeds, REST, or during autosave.
		if ( is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$display = true;

		$mode = Settings_Manager::get( 'visibility.mode', 'everywhere' );
		$ids  = (array) Settings_Manager::get( 'visibility.post_ids', array() );
		$types = (array) Settings_Manager::get( 'visibility.post_types', array() );

		if ( 'selected' === $mode ) {
			$display = false;
			if ( is_singular() && in_array( (int) get_queried_object_id(), $ids, true ) ) {
				$display = true;
			}
			if ( is_singular() && in_array( get_post_type(), $types, true ) ) {
				$display = true;
			}
			if ( is_archive() && in_array( get_post_type(), $types, true ) ) {
				$display = true;
			}
		} elseif ( 'exclude' === $mode ) {
			if ( is_singular() && in_array( (int) get_queried_object_id(), $ids, true ) ) {
				$display = false;
			}
			if ( is_singular() && in_array( get_post_type(), $types, true ) ) {
				$display = false;
			}
			if ( is_archive() && in_array( get_post_type(), $types, true ) ) {
				$display = false;
			}
		}

		if ( $display && Settings_Manager::get( 'visibility.wc_hide_cart_checkout', 0 ) && function_exists( 'is_cart' ) ) {
			if ( is_cart() || is_checkout() ) {
				$display = false;
			}
		}

		/**
		 * Filter whether the chat widget renders on the current request.
		 *
		 * @param bool $display Whether to display.
		 */
		return (bool) apply_filters( 'livedesk_should_display_widget', $display );
	}

	/**
	 * Load assets only where the widget displays.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->should_display() ) {
			return;
		}

		$primary = Settings_Manager::get( 'general.color_primary', '#2563eb' );
		$width   = (int) Settings_Manager::get( 'general.width', 380 );
		$height  = (int) Settings_Manager::get( 'general.height', 560 );
		$position = 'left' === Settings_Manager::get( 'general.position', 'right' ) ? 'left' : 'right';

		wp_enqueue_style( 'livedesk-widget', LIVEDESK_URL . 'assets/css/widget.css', array(), LIVEDESK_VERSION );
		wp_add_inline_style(
			'livedesk-widget',
			':root{--ld-primary:' . esc_attr( $primary ) . ';--ld-width:' . $width . 'px;--ld-height:' . $height . 'px;}'
			. '.ld-widget{--ld-side:' . ( 'left' === $position ? 'left' : 'right' ) . ';}'
		);

		wp_enqueue_script( 'livedesk-widget', LIVEDESK_URL . 'assets/js/widget.js', array(), LIVEDESK_VERSION, true );

		$config = array(
			'restUrl'    => esc_url_raw( rest_url( REST_Controller::NS . '/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'settings'   => array(
				'widgetTitle' => (string) Settings_Manager::get( 'general.widget_title', __( 'Live Chat', 'livedesk' ) ),
				'title'      => Settings_Manager::get( 'general.widget_title', __( 'Live Chat', 'livedesk' ) ),
				'welcome'    => Settings_Manager::get( 'general.welcome_message', '' ),
				'launcher'   => Settings_Manager::get( 'general.launcher_text', '' ),
				'logo'       => Settings_Manager::get( 'general.logo_url', '' ),
				'position'   => $position,
				'offlineMode' => Business_Hours::offline_mode(),
				'offlineHeading' => Settings_Manager::get( 'offline.heading', '' ),
				'offlineMessage' => Settings_Manager::get( 'offline.message', '' ),
				'offlineSuccess' => Settings_Manager::get( 'offline.success', '' ),
				'consentText' => Settings_Manager::get( 'privacy.consent_text', '' ),
				'soundDefault' => (bool) Settings_Manager::get( 'general.sound_default', 1 ),
				'pollActive'  => (int) Settings_Manager::get( 'realtime.poll_active', 2 ),
				'pollWaiting' => (int) Settings_Manager::get( 'realtime.poll_waiting', 2 ),
				'pollIdle'    => (int) Settings_Manager::get( 'realtime.poll_idle', 20 ),
				'attachments' => (bool) Settings_Manager::get( 'attachments.enabled', 1 ),
				'attachmentTypes' => implode( ',', Attachment_Manager::allowed_extensions() ),
				'maxUploadMb' => (int) Settings_Manager::get( 'attachments.max_size_mb', 10 ),
				'typing'     => (bool) Settings_Manager::get( 'realtime.typing_enabled', 1 ),
				'captcha'    => Settings_Manager::get( 'security.captcha_provider', 'none' ),
				'captchaKey' => Settings_Manager::get( 'security.captcha_site_key', '' ),
				'pageUrl'    => home_url( add_query_arg( null, null ) ),
			),
			'strings'    => array(
				'online'      => __( 'Online now', 'livedesk' ),
				'minimize'    => __( 'Minimize chat', 'livedesk' ),
				'endChat'     => __( 'End chat', 'livedesk' ),
				'confirmEnd'  => __( 'End this chat? Your conversation stays saved in your history — you can send a new message any time to reopen it.', 'livedesk' ),
				'ended'       => __( 'This chat has been closed by our team. You can still see the history — send a message to reopen it.', 'livedesk' ),
				'endSent'     => __( 'Chat ended. Thanks for reaching out!', 'livedesk' ),
				'newMessage'  => __( 'New message from support', 'livedesk' ),
				'support'     => __( 'Support team', 'livedesk' ),
				'confirmClose' => __( 'Close this chat? The conversation will end and your chat history will no longer be shown here. Our team keeps a copy for reference.', 'livedesk' ),
				'offline'     => __( 'Offline — leave a message', 'livedesk' ),
				'send'        => __( 'Send', 'livedesk' ),
				'name'        => __( 'Your name', 'livedesk' ),
				'email'       => __( 'Your email', 'livedesk' ),
				'start'       => __( 'Start chat', 'livedesk' ),
				'message'     => __( 'Type your message…', 'livedesk' ),
				'sending'     => __( 'Sending…', 'livedesk' ),
				'upload'      => __( 'Attach a file', 'livedesk' ),
				'typing'      => __( 'is typing…', 'livedesk' ),
				'agentTyping' => __( 'Support is typing…', 'livedesk' ),
				'you'         => __( 'You', 'livedesk' ),
				'reconnecting' => __( 'Reconnecting…', 'livedesk' ),
				'connected'   => __( 'Connected', 'livedesk' ),
				'error'       => __( 'Something went wrong. Please try again.', 'livedesk' ),
				'required'    => __( 'Please enter your name and a valid email.', 'livedesk' ),
				'loadEarlier' => __( 'Load earlier messages', 'livedesk' ),
				'greeting'    => __( 'Hi %s, how can we help?', 'livedesk' ),
				'soundOn'     => __( 'Sound on', 'livedesk' ),
				'soundOff'    => __( 'Sound off', 'livedesk' ),
				'close'       => __( 'Close chat', 'livedesk' ),
				'privacy'     => __( 'Your chat is stored so our team can help you.', 'livedesk' ),
			),
		);

		wp_localize_script( 'livedesk-widget', 'livedeskConfig', $config );
	}

	/**
	 * Render the widget root container.
	 *
	 * @return void
	 */
	public function render_container() {
		if ( ! $this->should_display() ) {
			return;
		}
		include LIVEDESK_DIR . 'templates/widget-shell.php';
	}

	/**
	 * [livedesk_chat] shortcode — renders the widget inline.
	 *
	 * The floating widget is suppressed on this request; the shortcode prints an
	 * inline launcher card instead.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'label' => Settings_Manager::get( 'general.launcher_text', __( 'Chat with us', 'livedesk' ) ),
			),
			$atts,
			'livedesk_chat'
		);

		if ( ! wp_script_is( 'livedesk-widget', 'enqueued' ) ) {
			$this->enqueue_assets();
		}

		$offline = ! Business_Hours::is_online();

		return sprintf(
			'<button type="button" class="livedesk-inline-launcher" data-livedesk-open="1" style="display:inline-flex;align-items:center;gap:8px;padding:12px 20px;border-radius:8px;border:0;background:%1$s;color:#fff;font-size:15px;font-weight:600;cursor:pointer;">%2$s</button>',
			esc_attr( Settings_Manager::get( 'general.color_primary', '#2563eb' ) ),
			esc_html( $offline ? Settings_Manager::get( 'offline.heading', $atts['label'] ) : $atts['label'] )
		);
	}
}
