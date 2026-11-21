<?php
/**
 * Admin: conversation inbox (list + detail).
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
$status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$agent_f  = isset( $_GET['agent'] ) ? sanitize_key( wp_unslash( $_GET['agent'] ) ) : 'all';
$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
$date_to  = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
$page_num = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
$current_id = isset( $_GET['conversation'] ) ? absint( wp_unslash( $_GET['conversation'] ) ) : 0;
// phpcs:enable

$per_page = 20;
$query    = Conversation_Manager::query(
	array(
		'status'    => $status,
		'agent'     => $agent_f,
		'search'    => $search,
		'date_from' => $date_from,
		'date_to'   => $date_to,
		'page'      => $page_num,
		'per_page'  => $per_page,
	)
);

$counts     = Conversation_Manager::inbox_counts();
$has_detail = false;
$detail     = null;

if ( $current_id ) {
	$detail = Admin_Controller::conversation_payload( $current_id );
	if ( ! is_wp_error( $detail ) && Permissions::can_access_conversation( Conversation_Manager::get( $current_id ) ) ) {
		$has_detail = true;
		Conversation_Manager::mark_read_by_agent( $current_id );
	} else {
		$detail = null;
	}
}

$status_tabs = array(
	''         => __( 'All', 'livedesk' ) . ' (' . ( $counts['active'] + $counts['waiting'] + $counts['archived'] ) . ')',
	'active'   => __( 'Active', 'livedesk' ) . ' (' . $counts['active'] . ')',
	'waiting'  => __( 'Waiting', 'livedesk' ) . ' (' . $counts['waiting'] . ')',
	'archived' => __( 'Archived', 'livedesk' ) . ' (' . $counts['archived'] . ')',
);

$my_agent = Agent_Manager::get_by_user( get_current_user_id() );
?>
<div class="wrap livedesk-wrap livedesk-inbox-wrap">
	<div class="ld-inbox-header">
		<h1 class="ld-page-title"><?php esc_html_e( 'Inbox', 'livedesk' ); ?></h1>
		<?php if ( $my_agent ) : ?>
			<div class="ld-toggle-group" data-availability="<?php echo esc_attr( $my_agent->availability ); ?>">
				<button type="button" class="ld-toggle-btn <?php echo 'available' === $my_agent->availability ? 'is-active' : ''; ?>" data-value="available"><?php esc_html_e( 'Available', 'livedesk' ); ?></button>
				<button type="button" class="ld-toggle-btn <?php echo 'away' === $my_agent->availability ? 'is-active' : ''; ?>" data-value="away"><?php esc_html_e( 'Away', 'livedesk' ); ?></button>
			</div>
		<?php endif; ?>
	</div>

	<form class="ld-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="livedesk-inbox" />
		<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search name, email or message…', 'livedesk' ); ?>" />
		<select name="agent">
			<option value="all" <?php selected( $agent_f, 'all' ); ?>><?php esc_html_e( 'Any agent', 'livedesk' ); ?></option>
			<option value="mine" <?php selected( $agent_f, 'mine' ); ?>><?php esc_html_e( 'Assigned to me', 'livedesk' ); ?></option>
			<option value="unassigned" <?php selected( $agent_f, 'unassigned' ); ?>><?php esc_html_e( 'Unassigned', 'livedesk' ); ?></option>
			<?php foreach ( Agent_Manager::all() as $ag ) : ?>
				<option value="<?php echo esc_attr( $ag->user_id ); ?>" <?php selected( $agent_f, (string) $ag->user_id ); ?>><?php echo esc_html( $ag->display_name ); ?></option>
			<?php endforeach; ?>
		</select>
		<label class="ld-date-label"><?php esc_html_e( 'From', 'livedesk' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" /></label>
		<label class="ld-date-label"><?php esc_html_e( 'To', 'livedesk' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" /></label>
		<button type="submit" class="ld-btn"><?php esc_html_e( 'Filter', 'livedesk' ); ?></button>
		<?php if ( $search || $date_from || $date_to || ( $agent_f && 'all' !== $agent_f ) ) : ?>
			<a class="ld-btn ld-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=livedesk-inbox' ) ); ?>"><?php esc_html_e( 'Reset', 'livedesk' ); ?></a>
		<?php endif; ?>
	</form>

	<nav class="ld-tabs">
		<?php foreach ( $status_tabs as $key => $label ) : ?>
			<?php
			$url = add_query_arg(
				array_filter(
					array(
						'page'   => 'livedesk-inbox',
						'status' => $key,
						'agent'  => 'all' !== $agent_f ? $agent_f : false,
						's'      => $search ? $search : false,
					)
				),
				admin_url( 'admin.php' )
			);
			?>
			<a class="ld-tab <?php echo $status === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>

	<div class="ld-inbox <?php echo $has_detail ? 'has-detail' : ''; ?>" id="ld-inbox" data-can-manage="<?php echo Permissions::can_see_all() ? '1' : '0'; ?>">

		<!-- List -->
		<section class="ld-list" aria-label="<?php esc_attr_e( 'Conversations', 'livedesk' ); ?>">
			<?php if ( empty( $query['rows'] ) ) : ?>
				<p class="ld-empty"><?php esc_html_e( 'No conversations match these filters.', 'livedesk' ); ?></p>
			<?php else : ?>
				<ul id="ld-conversation-list">
					<?php foreach ( $query['rows'] as $row ) : ?>
						<?php
						$link = add_query_arg(
							array(
								'page'         => 'livedesk-inbox',
								'conversation' => (int) $row->id,
								'status'       => $status ? $status : false,
								'agent'        => 'all' !== $agent_f ? $agent_f : false,
								's'            => $search ? $search : false,
							),
							admin_url( 'admin.php' )
						);
						$agent_name = $row->agent_id ? Permissions::agent_display_name( (int) $row->agent_id, 'display_name' ) : __( 'Unassigned', 'livedesk' );
						?>
						<li class="ld-conv-item <?php echo (int) $row->id === $current_id ? 'is-open' : ''; ?>" data-id="<?php echo (int) $row->id; ?>" data-unread="<?php echo (int) $row->admin_unread; ?>">
							<a href="<?php echo esc_url( $link ); ?>">
								<span class="ld-conv-top">
									<span class="ld-conv-name"><?php echo esc_html( $row->visitor_name ); ?></span>
									<span class="ld-conv-time"><?php echo esc_html( Utils::time_ago( $row->last_message_at ) ); ?></span>
								</span>
								<span class="ld-conv-preview"><?php echo esc_html( Utils::excerpt( $row->last_message, 80 ) ); ?></span>
								<span class="ld-conv-meta">
									<span class="ld-badge ld-badge-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( Conversation_Manager::status_label( $row->status ) ); ?></span>
									<span class="ld-conv-agent"><?php echo esc_html( $agent_name ); ?></span>
									<?php if ( (int) $row->admin_unread > 0 ) : ?>
										<span class="ld-unread-pill"><?php echo (int) $row->admin_unread; ?></span>
									<?php endif; ?>
								</span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php
				$total_pages = (int) ceil( $query['total'] / $per_page );
				if ( $total_pages > 1 ) :
					?>
					<div class="ld-pagination">
						<?php
						$base_args = array_filter(
							array(
								'page' => 'livedesk-inbox',
								'status' => $status,
								'agent'  => 'all' !== $agent_f ? $agent_f : false,
								's'      => $search ? $search : false,
							)
						);
						for ( $i = 1; $i <= $total_pages; $i++ ) :
							if ( $i > 1 && $i < $total_pages - 0 && abs( $i - $page_num ) > 2 ) {
								continue;
							}
							?>
							<a class="ld-page <?php echo $i === $page_num ? 'is-active' : ''; ?>"
								href="<?php echo esc_url( add_query_arg( array_merge( $base_args, array( 'paged' => $i ) ), admin_url( 'admin.php' ) ) ); ?>">
								<?php echo esc_html( $i ); ?>
							</a>
						<?php endfor; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>

		<!-- Detail -->
		<section class="ld-detail" id="ld-detail" aria-label="<?php esc_attr_e( 'Conversation', 'livedesk' ); ?>">
			<?php if ( ! $has_detail ) : ?>
				<div class="ld-detail-placeholder">
					<span class="ld-placeholder-art" aria-hidden="true">
						<svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
							<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8z"></path>
						</svg>
					</span>
					<strong><?php esc_html_e( 'Pick a conversation', 'livedesk' ); ?></strong>
					<p><?php esc_html_e( 'Choose a chat on the left to read the history and reply. New messages arrive here automatically.', 'livedesk' ); ?></p>
				</div>
			<?php else : ?>
				<?php
				$conv    = $detail['conversation'];
				$visitor = $detail['visitor'];
				?>
				<div class="ld-detail-inner" data-conversation-id="<?php echo (int) $conv['id']; ?>" data-can-manage="<?php echo $detail['can_manage'] ? '1' : '0'; ?>">

					<header class="ld-detail-head">
						<div class="ld-detail-who">
							<span class="ld-detail-avatar"><?php echo esc_html( strtoupper( substr( $visitor['name'], 0, 1 ) ) ); ?></span>
							<div>
								<strong><?php echo esc_html( $visitor['name'] ); ?></strong>
								<a class="ld-detail-email" href="mailto:<?php echo esc_attr( $visitor['email'] ); ?>"><?php echo esc_html( $visitor['email'] ); ?></a>
								<span class="ld-detail-ref"><?php echo esc_html( $conv['ref'] ); ?> · <?php echo esc_html( Conversation_Manager::status_label( $conv['status'] ) ); ?></span>
							</div>
						</div>
						<div class="ld-detail-actions">
							<select id="ld-status-select" aria-label="<?php esc_attr_e( 'Conversation status', 'livedesk' ); ?>">
								<?php foreach ( Conversation_Manager::statuses() as $st ) : ?>
									<option value="<?php echo esc_attr( $st ); ?>" <?php selected( $conv['status'], $st ); ?>><?php echo esc_html( Conversation_Manager::status_label( $st ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( $detail['can_manage'] ) : ?>
								<select id="ld-assign-select" aria-label="<?php esc_attr_e( 'Assign agent', 'livedesk' ); ?>">
									<option value="0"><?php esc_html_e( 'Unassigned', 'livedesk' ); ?></option>
									<?php foreach ( $detail['agents'] as $agent_option ) : ?>
										<option value="<?php echo (int) $agent_option['id']; ?>" <?php selected( (int) $conv['agent_id'], (int) $agent_option['id'] ); ?>>
											<?php echo esc_html( $agent_option['name'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
							<a class="ld-icon-btn ld-tooltip" href="<?php echo esc_url( $detail['export_url'] ); ?>" title="<?php esc_attr_e( 'Export PDF', 'livedesk' ); ?>">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
							</a>
							<?php if ( $detail['can_manage'] ) : ?>
								<button type="button" class="ld-icon-btn ld-danger" id="ld-delete-conversation" title="<?php esc_attr_e( 'Delete conversation', 'livedesk' ); ?>">
									<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
								</button>
							<?php endif; ?>
						</div>
					</header>

					<div class="ld-detail-body">
						<div class="ld-thread-wrap">
							<div class="ld-thread" id="ld-thread" data-last-id="<?php echo (int) ( $detail['messages'] ? end( $detail['messages'] )['id'] : 0 ); ?>">
								<?php foreach ( $detail['messages'] as $message ) : ?>
									<?php if ( 'system' === $message['sender_type'] ) : ?>
										<div class="ld-thread-system" data-id="<?php echo (int) $message['id']; ?>">
											<span class="ld-thread-system-icon" aria-hidden="true">
												<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10"></path><path d="M12 6v6l4 2"></path></svg>
											</span>
											<span><?php echo esc_html( $message['body'] ); ?></span>
											<em><?php esc_html_e( 'auto', 'livedesk' ); ?></em>
										</div>
										<?php continue; ?>
									<?php endif; ?>
									<?php $out = 'visitor' === $message['sender_type']; ?>
									<div class="ld-thread-msg <?php echo $out ? 'is-in' : 'is-out'; ?>" data-id="<?php echo (int) $message['id']; ?>">
										<div class="ld-thread-bubble">
											<?php if ( $message['body'] ) : ?>
												<div class="ld-thread-text"><?php echo nl2br( esc_html( $message['body'] ) ); ?></div>
											<?php endif; ?>
											<?php if ( ! empty( $message['attachments'] ) ) : ?>
												<div class="ld-thread-atts">
													<?php foreach ( $message['attachments'] as $att ) : ?>
														<?php if ( $att['is_image'] ) : ?>
															<a href="<?php echo esc_url( $att['url'] ); ?>" target="_blank" rel="noopener">
																<img loading="lazy" src="<?php echo esc_url( $att['url'] ); ?>" alt="<?php echo esc_attr( $att['name'] ); ?>" />
															</a>
														<?php else : ?>
															<a class="ld-file-chip" href="<?php echo esc_url( $att['url'] ); ?>" target="_blank" rel="noopener">📎 <?php echo esc_html( $att['name'] ); ?></a>
														<?php endif; ?>
													<?php endforeach; ?>
												</div>
											<?php endif; ?>
										</div>
										<div class="ld-thread-meta">
											<?php echo esc_html( $message['sender_name'] ); ?> · <?php echo esc_html( Utils::format_datetime( gmdate( 'Y-m-d H:i:s', (int) $message['created_ts'] ), 'M j, H:i' ) ); ?>
											<?php if ( ! $out ) : ?><span class="ld-tick-ok"><?php echo $message['is_read'] ? '✓✓' : '✓'; ?></span><?php endif; ?>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
							<div class="ld-composer-admin">
								<div class="ld-quick-row">
									<button type="button" class="ld-btn ld-btn-sm ld-btn-ghost ld-nowrap" id="ld-canned-btn">
										<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>
										<?php esc_html_e( 'Canned responses', 'livedesk' ); ?>
									</button>
									<div class="ld-canned-menu" id="ld-canned-menu" hidden></div>
									<span class="ld-composer-hint"><?php esc_html_e( 'Enter to send · Shift+Enter for a new line', 'livedesk' ); ?></span>
								</div>
								<textarea id="ld-reply-input" rows="3" placeholder="<?php esc_attr_e( 'Write a reply…', 'livedesk' ); ?>"></textarea>
								<div class="ld-composer-actions">
									<button type="button" class="ld-btn ld-btn-primary" id="ld-send-reply"><?php esc_html_e( 'Send reply', 'livedesk' ); ?></button>
									<button type="button" class="ld-btn" id="ld-archive-btn"><?php esc_html_e( 'Archive', 'livedesk' ); ?></button>
									<span class="ld-send-status" id="ld-send-status"></span>
									<button type="button" class="ld-btn ld-btn-sm ld-btn-ghost ld-sound-btn" id="ld-sound-toggle" title="<?php esc_attr_e( 'Toggle sound', 'livedesk' ); ?>"><?php esc_html_e( 'Sound: on', 'livedesk' ); ?></button>
								</div>
							</div>
						</div>

						<aside class="ld-side">
							<div class="ld-side-block">
								<h3><?php esc_html_e( 'Visitor', 'livedesk' ); ?></h3>
								<dl class="ld-dl">
									<dt><?php esc_html_e( 'Name', 'livedesk' ); ?></dt><dd><?php echo esc_html( $visitor['name'] ); ?></dd>
									<dt><?php esc_html_e( 'Email', 'livedesk' ); ?></dt><dd><a href="mailto:<?php echo esc_attr( $visitor['email'] ); ?>"><?php echo esc_html( $visitor['email'] ); ?></a></dd>
									<dt><?php esc_html_e( 'Device', 'livedesk' ); ?></dt><dd><?php echo esc_html( $visitor['browser'] . ' · ' . $visitor['os'] . ' · ' . $visitor['device'] ); ?></dd>
									<dt><?php esc_html_e( 'Started chat on', 'livedesk' ); ?></dt><dd><a target="_blank" rel="noopener" href="<?php echo esc_url( $visitor['landing_page'] ); ?>"><?php echo esc_html( Utils::excerpt( $visitor['landing_page'], 46 ) ); ?></a></dd>
									<dt><?php esc_html_e( 'Current page', 'livedesk' ); ?></dt><dd><a target="_blank" rel="noopener" href="<?php echo esc_url( $visitor['current_page'] ); ?>"><?php echo esc_html( Utils::excerpt( $visitor['current_page'], 46 ) ); ?></a></dd>
									<dt><?php esc_html_e( 'First seen', 'livedesk' ); ?></dt><dd><?php echo esc_html( $visitor['created_fmt'] ); ?></dd>
									<dt><?php esc_html_e( 'Last activity', 'livedesk' ); ?></dt><dd><?php echo esc_html( $visitor['last_activity_fmt'] ); ?></dd>
								</dl>
							</div>

							<div class="ld-side-block">
								<h3><?php esc_html_e( 'Internal notes', 'livedesk' ); ?> <span class="ld-private-tag"><?php esc_html_e( 'private', 'livedesk' ); ?></span></h3>
								<div class="ld-notes" id="ld-notes">
									<?php foreach ( $detail['notes'] as $note ) : ?>
										<div class="ld-note" data-note-id="<?php echo (int) $note['id']; ?>">
											<div class="ld-note-head">
												<strong><?php echo esc_html( $note['author'] ); ?></strong>
												<span><?php echo esc_html( $note['created_at'] ? Utils::format_datetime( gmdate( 'Y-m-d H:i:s', strtotime( $note['created_at'] ) ) ) : '' ); ?></span>
												<?php if ( $detail['can_manage'] ) : ?>
													<button type="button" class="ld-note-del" title="<?php esc_attr_e( 'Delete note', 'livedesk' ); ?>">×</button>
												<?php endif; ?>
											</div>
											<p><?php echo nl2br( esc_html( $note['content'] ) ); ?></p>
										</div>
									<?php endforeach; ?>
								</div>
								<textarea id="ld-note-input" rows="2" placeholder="<?php esc_attr_e( 'Add an internal note…', 'livedesk' ); ?>"></textarea>
								<button type="button" class="ld-btn ld-btn-sm" id="ld-add-note"><?php esc_html_e( 'Add note', 'livedesk' ); ?></button>
							</div>

							<?php if ( ! empty( $detail['assignments'] ) ) : ?>
								<div class="ld-side-block">
									<h3><?php esc_html_e( 'Assignment history', 'livedesk' ); ?></h3>
									<ul class="ld-history">
										<?php foreach ( $detail['assignments'] as $entry ) : ?>
											<li><?php echo esc_html( $entry['from'] . ' → ' . $entry['to'] ); ?><span class="ld-muted"><?php echo esc_html( $entry['mode'] ); ?></span></li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>
						</aside>
					</div>
				</div>
			<?php endif; ?>
		</section>
	</div>
</div>
