<?php
/**
 * Admin: agent management.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

$agents       = Agent_Manager::all();
$agent_users  = wp_list_pluck( $agents, 'user_id' );
$candidates   = get_users(
	array(
		'number'   => 200,
		'orderby'  => 'display_name',
		'exclude'  => array_map( 'intval', $agent_users ),
	)
);
?>
<div class="wrap livedesk-wrap">
	<h1 class="ld-page-title"><?php esc_html_e( 'Agents', 'livedesk' ); ?></h1>
	<p class="ld-subtitle"><?php esc_html_e( 'Agents use their existing WordPress accounts and can only access the chat inbox — not other admin areas.', 'livedesk' ); ?></p>

	<div class="ld-two-col ld-two-col-wide">
		<div class="ld-card">
			<h2><?php esc_html_e( 'Current agents', 'livedesk' ); ?></h2>
			<?php if ( empty( $agents ) ) : ?>
				<p class="ld-empty"><?php esc_html_e( 'No agents yet. Add a user below to share the inbox.', 'livedesk' ); ?></p>
			<?php else : ?>
				<table class="ld-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Agent', 'livedesk' ); ?></th>
							<th><?php esc_html_e( 'Status', 'livedesk' ); ?></th>
							<th><?php esc_html_e( 'Open conversations', 'livedesk' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'livedesk' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $agents as $agent ) : ?>
							<?php $workload = Agent_Manager::workload( (int) $agent->user_id ); ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $agent->display_name ? $agent->display_name : $agent->user_login ); ?></strong><br />
									<span class="ld-muted"><?php echo esc_html( $agent->user_email ); ?></span>
								</td>
								<td>
									<span class="ld-status-badge <?php echo '1' === $agent->enabled ? 'is-on' : 'is-off'; ?>">
										<?php echo '1' === $agent->enabled ? esc_html__( 'Enabled', 'livedesk' ) : esc_html__( 'Disabled', 'livedesk' ); ?>
									</span>
									<br />
									<span class="ld-muted"><?php echo 'available' === $agent->availability ? esc_html__( 'Available', 'livedesk' ) : esc_html__( 'Away', 'livedesk' ); ?></span>
								</td>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-inbox&agent=' . (int) $agent->user_id ) ); ?>"><?php echo (int) $workload['open']; ?></a></td>
								<td class="ld-actions-cell">
									<button type="button" class="ld-btn ld-btn-sm ld-agent-toggle" data-user="<?php echo (int) $agent->user_id; ?>" data-enabled="<?php echo '1' === $agent->enabled ? '1' : '0'; ?>">
										<?php echo '1' === $agent->enabled ? esc_html__( 'Disable', 'livedesk' ) : esc_html__( 'Enable', 'livedesk' ); ?>
									</button>
									<button type="button" class="ld-btn ld-btn-sm ld-btn-danger ld-agent-remove" data-user="<?php echo (int) $agent->user_id; ?>"><?php esc_html_e( 'Remove', 'livedesk' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="ld-card">
			<h2><?php esc_html_e( 'Add an agent', 'livedesk' ); ?></h2>
			<p class="ld-muted"><?php esc_html_e( 'Select any existing WordPress user. They will receive the LiveDesk agent capability.', 'livedesk' ); ?></p>
			<?php if ( empty( $candidates ) ) : ?>
				<p class="ld-empty"><?php esc_html_e( 'Every eligible user is already an agent. Create a WordPress user first if you need one.', 'livedesk' ); ?></p>
			<?php else : ?>
				<div class="ld-add-agent-row">
					<select id="ld-agent-candidate" class="ld-select-wide">
						<?php foreach ( $candidates as $candidate ) : ?>
							<option value="<?php echo (int) $candidate->ID; ?>">
								<?php echo esc_html( $candidate->display_name . ' · ' . $candidate->user_email ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="ld-btn ld-btn-primary" id="ld-agent-add"><?php esc_html_e( 'Add agent', 'livedesk' ); ?></button>
				</div>
				<p class="ld-muted ld-small"><?php esc_html_e( 'Tip: use the Users → Add New screen to invite a colleague, then add them here. Set their role to Subscriber and add them as a LiveDesk agent — they will only see the chat inbox.', 'livedesk' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="ld-card">
			<h2><?php esc_html_e( 'Assignment rules', 'livedesk' ); ?></h2>
			<p class="ld-muted">
				<?php
				printf(
					/* translators: 1: assignment mode, 2: strategy */
					esc_html__( 'Current mode: %1$s · strategy: %2$s', 'livedesk' ),
					esc_html( 'auto' === Settings_Manager::get( 'assignment.mode' ) ? __( 'Automatic', 'livedesk' ) : __( 'Manual', 'livedesk' ) ),
					esc_html( Settings_Manager::get( 'assignment.strategy' ) )
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-settings#assignment' ) ); ?>"><?php esc_html_e( 'Change', 'livedesk' ); ?></a>
			</p>
			<p class="ld-muted"><?php esc_html_e( 'Automatic assignment only assigns to agents that are enabled and marked Available. Admins can always reply without assignment.', 'livedesk' ); ?></p>
		</div>
	</div>
</div>
