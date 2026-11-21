<?php
/**
 * Admin: dashboard overview.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

$counts   = Conversation_Manager::inbox_counts();
$recent   = Conversation_Manager::query( array( 'per_page' => 8, 'page' => 1 ) );
$agents   = Agent_Manager::all();
$my_agent = Agent_Manager::get_by_user( get_current_user_id() );
$online   = Business_Hours::is_online();
?>
<div class="wrap livedesk-wrap">
	<h1 class="ld-page-title">
		<?php esc_html_e( 'Live Chat', 'livedesk' ); ?>
		<span class="ld-env-badge <?php echo $online ? 'is-online' : 'is-offline'; ?>">
			<?php echo $online ? esc_html__( 'Chat is online', 'livedesk' ) : esc_html__( 'Chat is offline', 'livedesk' ); ?>
		</span>
	</h1>
	<p class="ld-subtitle"><?php esc_html_e( 'Real-time conversations from your website visitors.', 'livedesk' ); ?></p>

	<?php if ( $my_agent ) : ?>
		<div class="ld-card ld-availability-card">
			<div>
				<strong><?php esc_html_e( 'My availability', 'livedesk' ); ?></strong>
				<p class="ld-muted"><?php esc_html_e( 'Set yourself Away when you cannot respond. Availability affects the online indicator and automatic assignment.', 'livedesk' ); ?></p>
			</div>
			<div class="ld-toggle-group" data-availability="<?php echo esc_attr( $my_agent->availability ); ?>">
				<button type="button" class="ld-toggle-btn <?php echo 'available' === $my_agent->availability ? 'is-active' : ''; ?>" data-value="available"><?php esc_html_e( 'Available', 'livedesk' ); ?></button>
				<button type="button" class="ld-toggle-btn <?php echo 'away' === $my_agent->availability ? 'is-active' : ''; ?>" data-value="away"><?php esc_html_e( 'Away', 'livedesk' ); ?></button>
			</div>
		</div>
	<?php endif; ?>

	<div class="ld-stat-grid">
		<a class="ld-stat" href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-inbox&status=active' ) ); ?>">
			<span class="ld-stat-num"><?php echo esc_html( $counts['active'] ); ?></span>
			<span class="ld-stat-label"><?php esc_html_e( 'Active', 'livedesk' ); ?></span>
		</a>
		<a class="ld-stat" href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-inbox&status=waiting' ) ); ?>">
			<span class="ld-stat-num"><?php echo esc_html( $counts['waiting'] ); ?></span>
			<span class="ld-stat-label"><?php esc_html_e( 'Waiting', 'livedesk' ); ?></span>
		</a>
		<a class="ld-stat" href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-inbox&status=archived' ) ); ?>">
			<span class="ld-stat-num"><?php echo esc_html( $counts['archived'] ); ?></span>
			<span class="ld-stat-label"><?php esc_html_e( 'Archived', 'livedesk' ); ?></span>
		</a>
		<a class="ld-stat" href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-inbox&agent=unassigned' ) ); ?>">
			<span class="ld-stat-num"><?php echo esc_html( $counts['unassigned'] ); ?></span>
			<span class="ld-stat-label"><?php esc_html_e( 'Unassigned', 'livedesk' ); ?></span>
		</a>
		<a class="ld-stat" href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-inbox&agent=mine' ) ); ?>">
			<span class="ld-stat-num"><?php echo esc_html( $counts['mine'] ); ?></span>
			<span class="ld-stat-label"><?php esc_html_e( 'Mine', 'livedesk' ); ?></span>
		</a>
		<a class="ld-stat ld-stat-accent" href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-inbox' ) ); ?>">
			<span class="ld-stat-num"><?php echo esc_html( $counts['unread'] ); ?></span>
			<span class="ld-stat-label"><?php esc_html_e( 'Unread messages', 'livedesk' ); ?></span>
		</a>
	</div>

	<div class="ld-two-col">
		<div class="ld-card">
			<div class="ld-card-head">
				<h2><?php esc_html_e( 'Recent conversations', 'livedesk' ); ?></h2>
				<a class="ld-btn ld-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-inbox' ) ); ?>"><?php esc_html_e( 'Open inbox', 'livedesk' ); ?></a>
			</div>
			<?php if ( empty( $recent['rows'] ) ) : ?>
				<p class="ld-empty"><?php esc_html_e( 'No conversations yet. When a visitor writes through the widget it appears here instantly.', 'livedesk' ); ?></p>
			<?php else : ?>
				<ul class="ld-recent-list">
					<?php foreach ( $recent['rows'] as $row ) : ?>
						<li>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-inbox&conversation=' . (int) $row->id ) ); ?>">
								<span class="ld-recent-name">
									<?php echo esc_html( $row->visitor_name ); ?>
									<?php if ( (int) $row->admin_unread > 0 ) : ?><span class="ld-unread-dot" title="<?php esc_attr_e( 'Unread', 'livedesk' ); ?>"></span><?php endif; ?>
								</span>
								<span class="ld-recent-preview"><?php echo esc_html( Utils::excerpt( $row->last_message, 70 ) ); ?></span>
							</a>
							<span class="ld-badge ld-badge-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( Conversation_Manager::status_label( $row->status ) ); ?></span>
							<span class="ld-recent-time"><?php echo esc_html( Utils::time_ago( $row->last_message_at ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="ld-card">
			<div class="ld-card-head">
				<h2><?php esc_html_e( 'Team', 'livedesk' ); ?></h2>
				<?php if ( Permissions::can_see_all() ) : ?>
					<a class="ld-btn ld-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-agents' ) ); ?>"><?php esc_html_e( 'Manage', 'livedesk' ); ?></a>
				<?php endif; ?>
			</div>
			<?php if ( empty( $agents ) ) : ?>
				<p class="ld-empty">
					<?php esc_html_e( 'No agents configured yet. Admins can reply to every conversation; add agents to share the workload.', 'livedesk' ); ?>
				</p>
			<?php else : ?>
				<ul class="ld-agent-list">
					<?php foreach ( $agents as $agent ) : ?>
						<li>
							<span class="ld-agent-dot <?php echo 'available' === $agent->availability && '1' === $agent->enabled ? 'is-on' : 'is-off'; ?>"></span>
							<span class="ld-agent-name"><?php echo esc_html( $agent->display_name ? $agent->display_name : $agent->user_login ); ?></span>
							<span class="ld-muted">
								<?php
								if ( '1' !== $agent->enabled ) {
									esc_html_e( 'disabled', 'livedesk' );
								} elseif ( 'available' === $agent->availability ) {
									esc_html_e( 'available', 'livedesk' );
								} else {
									esc_html_e( 'away', 'livedesk' );
								}
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<hr />
			<h3><?php esc_html_e( 'Quick setup', 'livedesk' ); ?></h3>
			<ol class="ld-checklist">
				<li><?php esc_html_e( 'The widget is live on your site footer — no theme edits required.', 'livedesk' ); ?></li>
				<li><?php esc_html_e( 'Add agents under Live Chat → Agents (existing WordPress users).', 'livedesk' ); ?></li>
				<li><?php esc_html_e( 'Set business hours and offline behaviour under Settings.', 'livedesk' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: shortcode */
						esc_html__( 'Prefer an inline button? Use the shortcode %s.', 'livedesk' ),
						'<code>[livedesk_chat]</code>'
					);
					?>
				</li>
			</ol>
		</div>
	</div>
</div>
