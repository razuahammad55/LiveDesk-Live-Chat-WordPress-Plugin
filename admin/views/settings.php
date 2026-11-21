<?php
/**
 * Admin: settings page (tabbed single form; Settings API handles saving).
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

$s        = Settings_Manager::all();
$option   = Settings_Manager::OPTION;
$days     = array(
	'mon' => __( 'Monday', 'livedesk' ),
	'tue' => __( 'Tuesday', 'livedesk' ),
	'wed' => __( 'Wednesday', 'livedesk' ),
	'thu' => __( 'Thursday', 'livedesk' ),
	'fri' => __( 'Friday', 'livedesk' ),
	'sat' => __( 'Saturday', 'livedesk' ),
	'sun' => __( 'Sunday', 'livedesk' ),
);
$post_types = get_post_types( array( 'public' => true ), 'objects' );
$tabs       = array(
	'general'       => __( 'General', 'livedesk' ),
	'messages'      => __( 'Automated messages', 'livedesk' ),
	'visibility'    => __( 'Visibility', 'livedesk' ),
	'hours'         => __( 'Business hours', 'livedesk' ),
	'assignment'    => __( 'Agents & assignment', 'livedesk' ),
	'offline'       => __( 'Offline behaviour', 'livedesk' ),
	'notifications' => __( 'Notifications', 'livedesk' ),
	'attachments'   => __( 'Attachments', 'livedesk' ),
	'security'      => __( 'Security & spam', 'livedesk' ),
	'retention'     => __( 'Retention & privacy', 'livedesk' ),
	'realtime'      => __( 'Real-time', 'livedesk' ),
);
?>
<div class="wrap livedesk-wrap">
	<h1 class="ld-page-title"><?php esc_html_e( 'Settings', 'livedesk' ); ?></h1>

	<?php settings_errors(); ?>

	<form method="post" action="options.php" class="ld-settings-form">
		<?php settings_fields( 'livedesk_settings_group' ); ?>

		<nav class="ld-settings-tabs">
			<?php foreach ( $tabs as $key => $label ) : ?>
				<button type="button" class="ld-settings-tab <?php echo 'general' === $key ? 'is-active' : ''; ?>" data-target="<?php echo esc_attr( $key ); ?>">
					<?php echo esc_html( $label ); ?>
				</button>
			<?php endforeach; ?>
		</nav>

		<!-- GENERAL -->
		<section class="ld-settings-panel is-active" id="ld-panel-general">
			<h2><?php esc_html_e( 'Widget appearance', 'livedesk' ); ?></h2>
			<label class="ld-field">
				<span><?php esc_html_e( 'Widget title', 'livedesk' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $option ); ?>[general][widget_title]" value="<?php echo esc_attr( $s['general']['widget_title'] ); ?>" />
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Welcome message', 'livedesk' ); ?></span>
				<textarea rows="3" name="<?php echo esc_attr( $option ); ?>[general][welcome_message]"><?php echo esc_textarea( $s['general']['welcome_message'] ); ?></textarea>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Logo URL (optional)', 'livedesk' ); ?></span>
				<input type="url" name="<?php echo esc_attr( $option ); ?>[general][logo_url]" value="<?php echo esc_attr( $s['general']['logo_url'] ); ?>" placeholder="https://" />
				<small><?php esc_html_e( 'Use the media library URL. Leave empty to show an icon.', 'livedesk' ); ?></small>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Launcher label (inline shortcode)', 'livedesk' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $option ); ?>[general][launcher_text]" value="<?php echo esc_attr( $s['general']['launcher_text'] ); ?>" />
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Launcher position', 'livedesk' ); ?></span>
				<select name="<?php echo esc_attr( $option ); ?>[general][position]">
					<option value="right" <?php selected( $s['general']['position'], 'right' ); ?>><?php esc_html_e( 'Bottom right', 'livedesk' ); ?></option>
					<option value="left" <?php selected( $s['general']['position'], 'left' ); ?>><?php esc_html_e( 'Bottom left', 'livedesk' ); ?></option>
				</select>
			</label>
			<div class="ld-field-row">
				<label class="ld-field">
					<span><?php esc_html_e( 'Primary color', 'livedesk' ); ?></span>
					<input type="color" name="<?php echo esc_attr( $option ); ?>[general][color_primary]" value="<?php echo esc_attr( $s['general']['color_primary'] ); ?>" />
				</label>
				<label class="ld-field">
					<span><?php esc_html_e( 'Window width (px)', 'livedesk' ); ?></span>
					<input type="number" min="300" max="600" name="<?php echo esc_attr( $option ); ?>[general][width]" value="<?php echo esc_attr( $s['general']['width'] ); ?>" />
				</label>
				<label class="ld-field">
					<span><?php esc_html_e( 'Window height (px)', 'livedesk' ); ?></span>
					<input type="number" min="400" max="900" name="<?php echo esc_attr( $option ); ?>[general][height]" value="<?php echo esc_attr( $s['general']['height'] ); ?>" />
				</label>
			</div>
			<label class="ld-field">
				<span><?php esc_html_e( 'Agent display name in the chat', 'livedesk' ); ?></span>
				<select name="<?php echo esc_attr( $option ); ?>[general][agent_display]">
					<option value="first_name" <?php selected( $s['general']['agent_display'], 'first_name' ); ?>><?php esc_html_e( 'First name', 'livedesk' ); ?></option>
					<option value="display_name" <?php selected( $s['general']['agent_display'], 'display_name' ); ?>><?php esc_html_e( 'Full display name', 'livedesk' ); ?></option>
				</select>
				<small><?php esc_html_e( 'Shown next to each reply inside the conversation.', 'livedesk' ); ?></small>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Session lifetime (hours)', 'livedesk' ); ?></span>
				<input type="number" min="0" max="72" name="<?php echo esc_attr( $option ); ?>[general][session_lifetime_hours]" value="<?php echo esc_attr( $s['general']['session_lifetime_hours'] ); ?>" />
				<small><?php esc_html_e( 'A chat ends when the visitor closes the browser. This is the safety net for idle sessions; use 0 to disable. History stays available to agents.', 'livedesk' ); ?></small>
			</label>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[general][sound_default]" value="1" <?php checked( $s['general']['sound_default'], 1 ); ?> />
				<span><?php esc_html_e( 'Visitor notifications sound on by default', 'livedesk' ); ?></span>
			</label>
			<p class="ld-muted"><?php esc_html_e( 'Inline placement: use the shortcode [livedesk_chat] on any page or post.', 'livedesk' ); ?></p>
		</section>

		<!-- AUTOMATED MESSAGES -->
		<section class="ld-settings-panel" id="ld-panel-messages">
			<h2><?php esc_html_e( 'Automated messages', 'livedesk' ); ?></h2>
			<p class="ld-muted"><?php esc_html_e( 'Placeholders: {user_name}, {user_email}, {site_name}, {ref}. Automated messages are attributed to the site, not to a staff member, and never count as unread for agents.', 'livedesk' ); ?></p>

			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[general][welcome_enabled]" value="1" <?php checked( $s['general']['welcome_enabled'], 1 ); ?> />
				<span><?php esc_html_e( 'Send a welcome message when a visitor starts a chat', 'livedesk' ); ?></span>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Welcome message', 'livedesk' ); ?></span>
				<textarea rows="2" name="<?php echo esc_attr( $option ); ?>[general][welcome_chat_message]"><?php echo esc_textarea( $s['general']['welcome_chat_message'] ); ?></textarea>
			</label>

			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[general][ack_enabled]" value="1" <?php checked( $s['general']['ack_enabled'], 1 ); ?> />
				<span><?php esc_html_e( 'Send a holding reply after the visitor\'s first message', 'livedesk' ); ?></span>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Holding reply', 'livedesk' ); ?></span>
				<textarea rows="3" name="<?php echo esc_attr( $option ); ?>[general][ack_message]"><?php echo esc_textarea( $s['general']['ack_message'] ); ?></textarea>
			</label>
		</section>

		<!-- VISIBILITY -->
		<section class="ld-settings-panel" id="ld-panel-visibility">
			<h2><?php esc_html_e( 'Where the widget appears', 'livedesk' ); ?></h2>
			<label class="ld-field">
				<span><?php esc_html_e( 'Visibility mode', 'livedesk' ); ?></span>
				<select name="<?php echo esc_attr( $option ); ?>[visibility][mode]">
					<option value="everywhere" <?php selected( $s['visibility']['mode'], 'everywhere' ); ?>><?php esc_html_e( 'Everywhere', 'livedesk' ); ?></option>
					<option value="selected" <?php selected( $s['visibility']['mode'], 'selected' ); ?>><?php esc_html_e( 'Only on selected pages / post types', 'livedesk' ); ?></option>
					<option value="exclude" <?php selected( $s['visibility']['mode'], 'exclude' ); ?>><?php esc_html_e( 'Everywhere except selected pages / post types', 'livedesk' ); ?></option>
				</select>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Page / post IDs (comma-separated)', 'livedesk' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $option ); ?>[visibility][post_ids]" value="<?php echo esc_attr( implode( ',', (array) $s['visibility']['post_ids'] ) ); ?>" placeholder="12, 34, 56" />
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Post types', 'livedesk' ); ?></span>
				<select multiple name="<?php echo esc_attr( $option ); ?>[visibility][post_types][]" size="6">
					<?php foreach ( $post_types as $pt ) : ?>
						<option value="<?php echo esc_attr( $pt->name ); ?>" <?php echo in_array( $pt->name, (array) $s['visibility']['post_types'], true ) ? 'selected' : ''; ?>>
							<?php echo esc_html( $pt->labels->singular_name . ' (' . $pt->name . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<small><?php esc_html_e( 'Hold Ctrl/Cmd to select several.', 'livedesk' ); ?></small>
			</label>
			<?php if ( Utils::is_woocommerce() ) : ?>
				<label class="ld-check">
					<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[visibility][wc_hide_cart_checkout]" value="1" <?php checked( $s['visibility']['wc_hide_cart_checkout'], 1 ); ?> />
					<span><?php esc_html_e( 'Hide widget on WooCommerce cart and checkout pages', 'livedesk' ); ?></span>
				</label>
			<?php endif; ?>
			<p class="ld-muted"><?php esc_html_e( 'Widget assets are not loaded at all on pages where the widget is hidden.', 'livedesk' ); ?></p>
		</section>

		<!-- HOURS -->
		<section class="ld-settings-panel" id="ld-panel-hours">
			<h2><?php esc_html_e( 'Business hours', 'livedesk' ); ?></h2>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[hours][enabled]" value="1" <?php checked( $s['hours']['enabled'], 1 ); ?> />
				<span><?php esc_html_e( 'Enable business hours for the online indicator', 'livedesk' ); ?></span>
			</label>
			<p class="ld-muted">
				<?php
				printf(
					/* translators: %s: timezone name */
					esc_html__( 'Current site timezone: %s', 'livedesk' ),
					'<code>' . esc_html( wp_timezone_string() ) . '</code>'
				);
				?>
			</p>
			<table class="ld-hours-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Day', 'livedesk' ); ?></th>
						<th><?php esc_html_e( 'Open', 'livedesk' ); ?></th>
						<th><?php esc_html_e( 'From', 'livedesk' ); ?></th>
						<th><?php esc_html_e( 'To', 'livedesk' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $days as $key => $label ) : ?>
						<?php $day = $s['hours']['schedule'][ $key ]; ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[hours][schedule][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $day['enabled'], 1 ); ?> /></td>
							<td><input type="time" name="<?php echo esc_attr( $option ); ?>[hours][schedule][<?php echo esc_attr( $key ); ?>][start]" value="<?php echo esc_attr( $day['start'] ); ?>" /></td>
							<td><input type="time" name="<?php echo esc_attr( $option ); ?>[hours][schedule][<?php echo esc_attr( $key ); ?>][end]" value="<?php echo esc_attr( $day['end'] ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<label class="ld-field">
				<span><?php esc_html_e( 'Holidays / special closures', 'livedesk' ); ?></span>
				<textarea rows="4" name="<?php echo esc_attr( $option ); ?>[hours][holidays]" placeholder="2026-12-24&#10;2026-12-31"><?php echo esc_textarea( implode( "\n", (array) $s['hours']['holidays'] ) ); ?></textarea>
				<small><?php esc_html_e( 'One date per line: YYYY-MM-DD (optionally followed by | Label).', 'livedesk' ); ?></small>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Manual override', 'livedesk' ); ?></span>
				<select name="<?php echo esc_attr( $option ); ?>[hours][manual_override]">
					<option value="auto" <?php selected( $s['hours']['manual_override'], 'auto' ); ?>><?php esc_html_e( 'Automatic (hours + available agents)', 'livedesk' ); ?></option>
					<option value="online" <?php selected( $s['hours']['manual_override'], 'online' ); ?>><?php esc_html_e( 'Force online', 'livedesk' ); ?></option>
					<option value="offline" <?php selected( $s['hours']['manual_override'], 'offline' ); ?>><?php esc_html_e( 'Force offline', 'livedesk' ); ?></option>
				</select>
			</label>
			<p class="ld-muted"><?php esc_html_e( '"Online" requires both the schedule window and at least one agent marked Available. Being logged into WordPress is not enough.', 'livedesk' ); ?></p>
		</section>

		<!-- ASSIGNMENT -->
		<section class="ld-settings-panel" id="ld-panel-assignment">
			<h2><?php esc_html_e( 'Assignment', 'livedesk' ); ?></h2>
			<label class="ld-field">
				<span><?php esc_html_e( 'Mode', 'livedesk' ); ?></span>
				<select name="<?php echo esc_attr( $option ); ?>[assignment][mode]">
					<option value="manual" <?php selected( $s['assignment']['mode'], 'manual' ); ?>><?php esc_html_e( 'Manual', 'livedesk' ); ?></option>
					<option value="auto" <?php selected( $s['assignment']['mode'], 'auto' ); ?>><?php esc_html_e( 'Automatic', 'livedesk' ); ?></option>
				</select>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Automatic strategy', 'livedesk' ); ?></span>
				<select name="<?php echo esc_attr( $option ); ?>[assignment][strategy]">
					<option value="round_robin" <?php selected( $s['assignment']['strategy'], 'round_robin' ); ?>><?php esc_html_e( 'Round robin', 'livedesk' ); ?></option>
					<option value="least_busy" <?php selected( $s['assignment']['strategy'], 'least_busy' ); ?>><?php esc_html_e( 'Least busy', 'livedesk' ); ?></option>
				</select>
			</label>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[assignment][agents_see_unassigned]" value="1" <?php checked( $s['assignment']['agents_see_unassigned'], 1 ); ?> />
				<span><?php esc_html_e( 'Agents can see unassigned conversations', 'livedesk' ); ?></span>
			</label>
			<p class="ld-muted"><?php esc_html_e( 'Admins always see and reply to every conversation. Agents only see their own assignments (and unassigned when enabled).', 'livedesk' ); ?></p>
		</section>

		<!-- OFFLINE -->
		<section class="ld-settings-panel" id="ld-panel-offline">
			<h2><?php esc_html_e( 'When chat is offline', 'livedesk' ); ?></h2>
			<label class="ld-field">
				<span><?php esc_html_e( 'Behaviour', 'livedesk' ); ?></span>
				<select name="<?php echo esc_attr( $option ); ?>[offline][mode]">
					<option value="chat" <?php selected( $s['offline']['mode'], 'chat' ); ?>><?php esc_html_e( 'Normal chat (messages are queued)', 'livedesk' ); ?></option>
					<option value="contact_form" <?php selected( $s['offline']['mode'], 'contact_form' ); ?>><?php esc_html_e( 'Offline contact form', 'livedesk' ); ?></option>
				</select>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Offline heading', 'livedesk' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $option ); ?>[offline][heading]" value="<?php echo esc_attr( $s['offline']['heading'] ); ?>" />
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Offline message', 'livedesk' ); ?></span>
				<textarea rows="3" name="<?php echo esc_attr( $option ); ?>[offline][message]"><?php echo esc_textarea( $s['offline']['message'] ); ?></textarea>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Success message (contact form)', 'livedesk' ); ?></span>
				<textarea rows="2" name="<?php echo esc_attr( $option ); ?>[offline][success]"><?php echo esc_textarea( $s['offline']['success'] ); ?></textarea>
			</label>
			<p class="ld-muted"><?php esc_html_e( 'Both modes create conversations in the same inbox — nothing is lost when you are away.', 'livedesk' ); ?></p>
		</section>

		<!-- NOTIFICATIONS -->
		<section class="ld-settings-panel" id="ld-panel-notifications">
			<h2><?php esc_html_e( 'Email notifications', 'livedesk' ); ?></h2>
			<label class="ld-field">
				<span><?php esc_html_e( 'Admin recipients (comma-separated)', 'livedesk' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $option ); ?>[notifications][admin_recipients]" value="<?php echo esc_attr( $s['notifications']['admin_recipients'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
				<small><?php esc_html_e( 'Leave empty to use the site admin email. Emails only send on new conversations and reopens — never on every visitor message.', 'livedesk' ); ?></small>
			</label>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[notifications][notify_new]" value="1" <?php checked( $s['notifications']['notify_new'], 1 ); ?> />
				<span><?php esc_html_e( 'Email admins when a new conversation starts', 'livedesk' ); ?></span>
			</label>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[notifications][notify_reopen]" value="1" <?php checked( $s['notifications']['notify_reopen'], 1 ); ?> />
				<span><?php esc_html_e( 'Email admins when an archived conversation is reopened', 'livedesk' ); ?></span>
			</label>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[notifications][notify_assignment]" value="1" <?php checked( $s['notifications']['notify_assignment'], 1 ); ?> />
				<span><?php esc_html_e( 'Email the agent when a conversation is auto-assigned', 'livedesk' ); ?></span>
			</label>
			<h2><?php esc_html_e( 'Agent alerts', 'livedesk' ); ?></h2>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[notifications][agent_sound]" value="1" <?php checked( $s['notifications']['agent_sound'], 1 ); ?> />
				<span><?php esc_html_e( 'Play a sound for new visitor messages', 'livedesk' ); ?></span>
			</label>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[notifications][agent_desktop]" value="1" <?php checked( $s['notifications']['agent_desktop'], 1 ); ?> />
				<span><?php esc_html_e( 'Allow browser desktop notifications (agents can grant permission)', 'livedesk' ); ?></span>
			</label>
			<button type="button" class="ld-btn" id="ld-test-email"><?php esc_html_e( 'Send test email', 'livedesk' ); ?></button>
			<span class="ld-send-status" id="ld-test-email-status"></span>
		</section>

		<!-- ATTACHMENTS -->
		<section class="ld-settings-panel" id="ld-panel-attachments">
			<h2><?php esc_html_e( 'File attachments', 'livedesk' ); ?></h2>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[attachments][enabled]" value="1" <?php checked( $s['attachments']['enabled'], 1 ); ?> />
				<span><?php esc_html_e( 'Allow visitors and agents to send attachments', 'livedesk' ); ?></span>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Maximum file size (MB)', 'livedesk' ); ?></span>
				<input type="number" min="1" max="50" name="<?php echo esc_attr( $option ); ?>[attachments][max_size_mb]" value="<?php echo esc_attr( $s['attachments']['max_size_mb'] ); ?>" />
				<small>
					<?php
					$max_upload = wp_max_upload_size();
					printf(
						/* translators: %s: server limit */
						esc_html__( 'Your server currently allows uploads up to %s.', 'livedesk' ),
						esc_html( size_format( $max_upload ) )
					);
					?>
				</small>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Allowed extensions', 'livedesk' ); ?></span>
				<input type="text" name="<?php echo esc_attr( $option ); ?>[attachments][allowed_types]" value="<?php echo esc_attr( $s['attachments']['allowed_types'] ); ?>" />
				<small><?php esc_html_e( 'Comma-separated. Executable and script types are always rejected.', 'livedesk' ); ?></small>
			</label>
		</section>

		<!-- SECURITY -->
		<section class="ld-settings-panel" id="ld-panel-security">
			<h2><?php esc_html_e( 'Spam protection', 'livedesk' ); ?></h2>
			<label class="ld-field">
				<span><?php esc_html_e( 'Message rate limit (per minute, per visitor)', 'livedesk' ); ?></span>
				<input type="number" min="1" max="240" name="<?php echo esc_attr( $option ); ?>[security][rate_messages]" value="<?php echo esc_attr( $s['security']['rate_messages'] ); ?>" />
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'New session limit (per hour, per IP)', 'livedesk' ); ?></span>
				<input type="number" min="1" max="240" name="<?php echo esc_attr( $option ); ?>[security][rate_starts]" value="<?php echo esc_attr( $s['security']['rate_starts'] ); ?>" />
			</label>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[security][honeypot]" value="1" <?php checked( $s['security']['honeypot'], 1 ); ?> />
				<span><?php esc_html_e( 'Enable honeypot field', 'livedesk' ); ?></span>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'CAPTCHA provider', 'livedesk' ); ?></span>
				<select name="<?php echo esc_attr( $option ); ?>[security][captcha_provider]">
					<option value="none" <?php selected( $s['security']['captcha_provider'], 'none' ); ?>><?php esc_html_e( 'None', 'livedesk' ); ?></option>
					<option value="recaptcha_v2" <?php selected( $s['security']['captcha_provider'], 'recaptcha_v2' ); ?>>Google reCAPTCHA v2</option>
					<option value="recaptcha_v3" <?php selected( $s['security']['captcha_provider'], 'recaptcha_v3' ); ?>>Google reCAPTCHA v3</option>
					<option value="turnstile" <?php selected( $s['security']['captcha_provider'], 'turnstile' ); ?>>Cloudflare Turnstile</option>
				</select>
			</label>
			<div class="ld-field-row">
				<label class="ld-field">
					<span><?php esc_html_e( 'Site key', 'livedesk' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $option ); ?>[security][captcha_site_key]" value="<?php echo esc_attr( $s['security']['captcha_site_key'] ); ?>" />
				</label>
				<label class="ld-field">
					<span><?php esc_html_e( 'Secret key', 'livedesk' ); ?></span>
					<input type="password" name="<?php echo esc_attr( $option ); ?>[security][captcha_secret]" value="<?php echo esc_attr( $s['security']['captcha_secret'] ); ?>" autocomplete="new-password" />
				</label>
			</div>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[security][require_email_match]" value="1" <?php checked( $s['security']['require_email_match'], 1 ); ?> />
				<span><?php esc_html_e( 'Lock identified chats to their email address', 'livedesk' ); ?></span>
			</label>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[security][email_history_resume]" value="1" <?php checked( $s['security']['email_history_resume'], 1 ); ?> />
				<span><?php esc_html_e( 'Let a known email address continue its previous chat', 'livedesk' ); ?></span>
			</label>
			<p class="ld-muted ld-warning">
				<strong><?php esc_html_e( 'Security notice:', 'livedesk' ); ?></strong>
				<?php esc_html_e( 'An email address is not a password. Enabling the option above lets anyone who knows a visitor\'s email address read that visitor\'s chat history. Leave it off unless your visitors are already authenticated elsewhere (for example an intranet).', 'livedesk' ); ?>
			</p>
			<p class="ld-muted"><?php esc_html_e( 'Visitor sessions are secured with 256-bit random tokens; only hashes are stored. CAPTCHA tokens are verified server-side against the provider.', 'livedesk' ); ?></p>
		</section>

		<!-- RETENTION / PRIVACY -->
		<section class="ld-settings-panel" id="ld-panel-retention">
			<h2><?php esc_html_e( 'Retention', 'livedesk' ); ?></h2>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[retention][auto_delete]" value="1" <?php checked( $s['retention']['auto_delete'], 1 ); ?> />
				<span><?php esc_html_e( 'Automatically delete archived conversations', 'livedesk' ); ?></span>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Delete archived conversations after (days)', 'livedesk' ); ?></span>
				<input type="number" min="1" max="3650" name="<?php echo esc_attr( $option ); ?>[retention][archived_days]" value="<?php echo esc_attr( $s['retention']['archived_days'] ); ?>" />
				<small><?php esc_html_e( 'Default: 180 days (6 months). Active conversations are never auto-deleted.', 'livedesk' ); ?></small>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Warn admins before deletion (days before)', 'livedesk' ); ?></span>
				<input type="number" min="0" max="90" name="<?php echo esc_attr( $option ); ?>[retention][notify_before_days]" value="<?php echo esc_attr( $s['retention']['notify_before_days'] ); ?>" />
			</label>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[retention][delete_attachments]" value="1" <?php checked( $s['retention']['delete_attachments'], 1 ); ?> />
				<span><?php esc_html_e( 'Also delete attachment files when conversations are deleted', 'livedesk' ); ?></span>
			</label>
			<h2><?php esc_html_e( 'Privacy', 'livedesk' ); ?></h2>
			<label class="ld-field">
				<span><?php esc_html_e( 'Consent note shown in the widget (optional)', 'livedesk' ); ?></span>
				<textarea rows="2" name="<?php echo esc_attr( $option ); ?>[privacy][consent_text]"><?php echo esc_textarea( $s['privacy']['consent_text'] ); ?></textarea>
			</label>
			<p class="ld-muted"><?php esc_html_e( 'Conversation data is exported via each conversation\'s PDF export and can be permanently deleted from the inbox. Visitor chat identifiers expire automatically after 120 days of inactivity.', 'livedesk' ); ?></p>
		</section>

		<!-- REALTIME -->
		<section class="ld-settings-panel" id="ld-panel-realtime">
			<h2><?php esc_html_e( 'Polling intervals', 'livedesk' ); ?></h2>
			<label class="ld-field">
				<span><?php esc_html_e( 'Active chat (seconds)', 'livedesk' ); ?></span>
				<input type="number" min="1" max="30" name="<?php echo esc_attr( $option ); ?>[realtime][poll_active]" value="<?php echo esc_attr( $s['realtime']['poll_active'] ); ?>" />
				<small><?php esc_html_e( 'Recommended: 3. Lower is faster but uses more server resources.', 'livedesk' ); ?></small>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Waiting for agent (seconds)', 'livedesk' ); ?></span>
				<input type="number" min="1" max="30" name="<?php echo esc_attr( $option ); ?>[realtime][poll_waiting]" value="<?php echo esc_attr( $s['realtime']['poll_waiting'] ); ?>" />
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Idle / background tab (seconds)', 'livedesk' ); ?></span>
				<input type="number" min="5" max="120" name="<?php echo esc_attr( $option ); ?>[realtime][poll_idle]" value="<?php echo esc_attr( $s['realtime']['poll_idle'] ); ?>" />
				<small><?php esc_html_e( 'Used when the tab is hidden or the widget is closed. Saves shared-hosting resources.', 'livedesk' ); ?></small>
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Messages per page', 'livedesk' ); ?></span>
				<input type="number" min="10" max="100" name="<?php echo esc_attr( $option ); ?>[realtime][page_size]" value="<?php echo esc_attr( $s['realtime']['page_size'] ); ?>" />
			</label>
			<label class="ld-check">
				<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[realtime][typing_enabled]" value="1" <?php checked( $s['realtime']['typing_enabled'], 1 ); ?> />
				<span><?php esc_html_e( 'Enable typing indicators', 'livedesk' ); ?></span>
			</label>
			<p class="ld-muted"><?php esc_html_e( 'LiveDesk uses AJAX polling so it works on any shared host. The transport is isolated in the polling layer so a WebSocket/SSE bridge can be added later without changing the data model.', 'livedesk' ); ?></p>
		</section>

		<div class="ld-settings-footer">
			<?php submit_button( __( 'Save settings', 'livedesk' ), 'primary', 'submit', false ); ?>
			<p class="ld-muted"><?php esc_html_e( 'All scheduled tasks (retention cleanup) run via WP-Cron on an hourly schedule.', 'livedesk' ); ?></p>
		</div>
	</form>
</div>
