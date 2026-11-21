<?php
/**
 * Admin: canned responses management.
 *
 * @package LiveDesk
 */

namespace LiveDesk;

defined( 'ABSPATH' ) || exit;

$responses = Canned_Responses::all();
?>
<div class="wrap livedesk-wrap">
	<h1 class="ld-page-title"><?php esc_html_e( 'Canned responses', 'livedesk' ); ?></h1>
	<p class="ld-subtitle"><?php esc_html_e( 'Saved replies agents can drop into the composer and edit before sending.', 'livedesk' ); ?></p>

	<div class="ld-two-col ld-two-col-wide">
		<div class="ld-card">
			<h2><?php esc_html_e( 'Saved replies', 'livedesk' ); ?></h2>
			<?php if ( empty( $responses ) ) : ?>
				<p class="ld-empty"><?php esc_html_e( 'No canned responses yet. Create your first one on the right.', 'livedesk' ); ?></p>
			<?php else : ?>
				<table class="ld-table widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'livedesk' ); ?></th>
							<th><?php esc_html_e( 'Content', 'livedesk' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'livedesk' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'livedesk' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $responses as $response ) : ?>
							<tr data-id="<?php echo (int) $response->id; ?>">
								<td><strong class="ld-canned-title"><?php echo esc_html( $response->title ); ?></strong></td>
								<td class="ld-canned-content"><?php echo esc_html( Utils::excerpt( $response->content, 110 ) ); ?></td>
								<td><?php echo esc_html( Utils::format_datetime( $response->updated_at, get_option( 'date_format' ) ) ); ?></td>
								<td class="ld-actions-cell">
									<button type="button" class="ld-btn ld-btn-sm ld-canned-edit"
										data-id="<?php echo (int) $response->id; ?>"
										data-title="<?php echo esc_attr( $response->title ); ?>"
										data-content="<?php echo esc_attr( $response->content ); ?>"><?php esc_html_e( 'Edit', 'livedesk' ); ?></button>
									<button type="button" class="ld-btn ld-btn-sm ld-btn-danger ld-canned-delete" data-id="<?php echo (int) $response->id; ?>"><?php esc_html_e( 'Delete', 'livedesk' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="ld-card">
			<h2 id="ld-canned-form-title"><?php esc_html_e( 'New canned response', 'livedesk' ); ?></h2>
			<input type="hidden" id="ld-canned-id" value="0" />
			<label class="ld-field">
				<span><?php esc_html_e( 'Title', 'livedesk' ); ?></span>
				<input type="text" id="ld-canned-title-input" maxlength="190" placeholder="<?php esc_attr_e( 'e.g. Refund policy', 'livedesk' ); ?>" />
			</label>
			<label class="ld-field">
				<span><?php esc_html_e( 'Content', 'livedesk' ); ?></span>
				<textarea id="ld-canned-content-input" rows="6" maxlength="5000" placeholder="<?php esc_attr_e( 'The reply text…', 'livedesk' ); ?>"></textarea>
			</label>
			<div class="ld-composer-actions">
				<button type="button" class="ld-btn ld-btn-primary" id="ld-canned-save"><?php esc_html_e( 'Save response', 'livedesk' ); ?></button>
				<button type="button" class="ld-btn" id="ld-canned-cancel" hidden><?php esc_html_e( 'Cancel', 'livedesk' ); ?></button>
				<span class="ld-send-status" id="ld-canned-status"></span>
			</div>
		</div>
	</div>
</div>
