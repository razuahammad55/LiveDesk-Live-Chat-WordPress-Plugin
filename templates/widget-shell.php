<?php
/**
 * Floating widget shell. Filled and controlled by assets/js/widget.js.
 *
 * @package LiveDesk
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="livedesk-widget" class="ld-widget" aria-live="polite">
	<div id="ld-panel" class="ld-panel" role="dialog" aria-label="<?php esc_attr_e( 'Live chat', 'livedesk' ); ?>" hidden>
		<header class="ld-header">
			<div class="ld-header-brand">
				<span class="ld-avatar" id="ld-avatar"></span>
				<div class="ld-header-text">
					<strong id="ld-title"></strong>
					<span class="ld-status" id="ld-status"></span>
				</div>
			</div>
			<div class="ld-header-actions">
				<button type="button" class="ld-icon-btn" id="ld-sound" title="<?php esc_attr_e( 'Toggle sound', 'livedesk' ); ?>" aria-label="<?php esc_attr_e( 'Toggle sound', 'livedesk' ); ?>" aria-pressed="true">
					<svg class="ld-sound-on" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path><path d="M19.07 4.93a10 10 0 0 1 0 14.14"></path></svg>
					<svg class="ld-sound-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><line x1="23" y1="9" x2="17" y2="15"></line><line x1="17" y1="9" x2="23" y2="15"></line></svg>
				</button>
				<button type="button" class="ld-icon-btn" id="ld-minimize" title="<?php esc_attr_e( 'Minimize chat', 'livedesk' ); ?>" aria-label="<?php esc_attr_e( 'Minimize chat', 'livedesk' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"></line></svg>
				</button>
				<button type="button" class="ld-icon-btn" id="ld-close" title="<?php esc_attr_e( 'Close chat', 'livedesk' ); ?>" aria-label="<?php esc_attr_e( 'Close chat', 'livedesk' ); ?>">
					<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
				</button>
			</div>
		</header>

		<!-- Identify form -->
		<section class="ld-identify" id="ld-identify">
			<div class="ld-welcome">
				<span class="ld-logo" id="ld-logo"></span>
				<p id="ld-welcome-text"></p>
				<p class="ld-consent" id="ld-consent"></p>
			</div>
			<form id="ld-identify-form" novalidate>
				<label for="ld-name"><?php esc_html_e( 'Your name', 'livedesk' ); ?></label>
				<input type="text" id="ld-name" name="name" autocomplete="name" required maxlength="120" />
				<label for="ld-email"><?php esc_html_e( 'Your email', 'livedesk' ); ?></label>
				<input type="email" id="ld-email" name="email" autocomplete="email" required maxlength="190" />
				<input type="text" name="hp" id="ld-hp" class="ld-hp" tabindex="-1" autocomplete="off" aria-hidden="true" />
				<div class="ld-captcha" id="ld-captcha"></div>
				<button type="submit" class="ld-primary-btn" id="ld-start"><?php esc_html_e( 'Start chat', 'livedesk' ); ?></button>
			</form>
		</section>

		<!-- Conversation -->
		<section class="ld-conversation" id="ld-conversation" hidden>
			<div class="ld-end-row">
				<button type="button" class="ld-end-btn" id="ld-end-chat"><?php esc_html_e( 'End chat', 'livedesk' ); ?></button>
			</div>
			<div class="ld-ended-note" id="ld-ended-note" hidden>
				<?php esc_html_e( 'This chat has been closed by our team. You can still see the history — send a message to reopen it.', 'livedesk' ); ?>
			</div>
			<div class="ld-messages" id="ld-messages" tabindex="0" aria-live="polite"></div>
			<div class="ld-typing" id="ld-typing" hidden></div>
			<div class="ld-composer" id="ld-composer">
				<div class="ld-attachments-preview" id="ld-attachments-preview" hidden></div>
				<div class="ld-composer-row">
					<button type="button" class="ld-icon-btn" id="ld-attach" title="<?php esc_attr_e( 'Attach a file', 'livedesk' ); ?>">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>
					</button>
					<input type="file" id="ld-file" hidden />
					<button type="button" class="ld-icon-btn" id="ld-emoji" title="<?php esc_attr_e( 'Emoji', 'livedesk' ); ?>">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg>
					</button>
					<textarea id="ld-input" rows="1" maxlength="4000" placeholder="<?php esc_attr_e( 'Type your message…', 'livedesk' ); ?>"></textarea>
					<button type="button" class="ld-send-btn" id="ld-send" aria-label="<?php esc_attr_e( 'Send', 'livedesk' ); ?>">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
					</button>
				</div>
				<div class="ld-emoji-panel" id="ld-emoji-panel" hidden></div>
				<div class="ld-conn-status" id="ld-conn-status" hidden></div>
			</div>
		</section>
	</div>

	<button type="button" class="ld-launcher" id="ld-launcher" aria-label="<?php esc_attr_e( 'Open live chat', 'livedesk' ); ?>">
		<svg class="ld-launcher-open" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
		<svg class="ld-launcher-close" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
		<span class="ld-badge" id="ld-badge" hidden></span>
	</button>
</div>
