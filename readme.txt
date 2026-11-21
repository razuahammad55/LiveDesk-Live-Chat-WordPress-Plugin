=== LiveDesk Live Chat ===
Contributors: livedesk
Tags: live chat, customer support, chat, helpdesk, agents
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Professional self-hosted live chat and customer support for WordPress. Visitor chat widget, agent inbox, attachments, internal notes, canned replies, PDF export, business hours. No external service required.

== Description ==

LiveDesk adds a polished live chat widget to your site and a complete support
inbox inside WordPress. Visitors identify themselves once and keep a single,
persistent conversation; your team replies from a real-time inbox.

**Visitor features**

* Floating, responsive chat widget (bottom left/right, fully customisable colours and dimensions)
* Name + email identification with a secure persistent session (no re-entering details)
* Full conversation history, message timestamps and delivery ticks
* Emoji picker, file attachments with image previews, sound notifications
* Typing indicators, connection/reconnection feedback
* Works while you are offline: queued messages or an offline contact form

**Agent and admin features**

* Real-time inbox with unread counts, search, status/agent/date filters
* Three clear statuses: Active, Waiting, Archived
* Multiple agents using existing WordPress accounts, with tight capabilities
* Manual and automatic assignment (round-robin or least busy)
* Internal notes (never visible to visitors), canned responses
* Full visitor context: pages, browser, device, first/last activity
* One-click PDF transcript export, permanent deletion with confirmation
* Business hours, holidays, agent availability and manual online/offline override

**Privacy and security**

* Visitor sessions use 256-bit tokens; only hashed tokens are stored
* Every request is authorised server-side (nonces, capabilities, ownership)
* Prepared SQL, escaped output, validated and sniffed uploads stored outside the web root
* Rate limiting, honeypot and optional reCAPTCHA v2/v3 / Cloudflare Turnstile
* Configurable retention with automatic cleanup of old archived conversations

== Installation ==

1. Upload the `livedesk` folder to `/wp-content/plugins/` (or upload the ZIP under Plugins → Add New → Upload).
2. Activate the plugin through the **Plugins** screen.
3. Open **Live Chat → Settings** and adjust colours, hours and notifications.
4. Add team members under **Live Chat → Agents** (any existing WordPress user).
5. Visit your site: the widget appears in the corner. Conversations arrive in **Live Chat → Inbox**.

Optional: place the `[livedesk_chat]` shortcode on a page for an inline chat button.

== Frequently Asked Questions ==

= Does this require an external chat service? =

No. Everything — storage, real-time updates, attachments and notifications — runs
inside your own WordPress installation.

= How real-time is it? =

Updates arrive by smart polling (about every 3 seconds during an active chat,
backing off to ~20 seconds for hidden tabs). This works on any shared host.

= Can visitors see internal notes? =

Never. Notes are stored in a separate table and excluded from visitor payloads
and default PDF exports.

= Do I need to edit my theme? =

No. The widget is injected in the footer. Only the CSS/JS for the widget load, and
only on pages where it is enabled.

= What happens to old conversations? =

Archived conversations are removed after your retention period (default 180 days).
You can change or disable this in Settings → Retention. Active conversations are
never deleted automatically.

== Screenshots ==

1. Visitor chat widget
2. Support inbox with conversation thread, notes and visitor panel
3. Dashboard overview
4. Settings

== Changelog ==

= 1.2.0 =
* **Session-based chat:** the session token now lives in browser session storage, so closing the browser ends the chat. Reopening starts a fresh conversation instead of showing the previous one. A server-side lifetime (default 2 hours, configurable) is the backstop for idle sessions. Conversations remain available to agents in the inbox.
* **Close vs minimize:** the ✕ button now ends the session (archives the conversation for the team, clears the session and returns to the welcome form, with a confirmation). The — button only collapses the window and keeps the chat running.
* **Automated messages:** a personalised welcome when a chat starts ("Hi {user_name}, how can we help you today?") and a holding reply after the visitor's first message. Both are switchable and editable under Settings → Automated messages, support {user_name}, {user_email}, {site_name} and {ref}, are attributed to the site rather than to a staff member, and never count as unread for agents.
* **Agent name moved into the conversation:** replies are attributed to the person handling the chat inside the thread. The header now shows only online/offline status.
* **Premium redesign:** refined widget and admin interfaces — layered surfaces, a consistent type scale, softer depth and shadows, polished composer, badges, stat tiles, settings tabs and empty states; two-pane inbox with a proper empty state; mobile safe-area handling.
* Fixed: the "Holding reply" setting rendered as an empty textarea (`esc_textarea()` was called without `echo`), which silently blanked the saved value on the next save.

= 1.1.1 =
* **Security fix:** knowing a visitor's email address could be used to obtain a session token and read that visitor's chat history. An email address is no longer accepted as an authentication factor — a previous conversation is resumed only by the secret token stored in the visitor's browser. Existing visitors are unaffected; they resume automatically.
* New setting (**Security & spam → "Let a known email address continue its previous chat"**), default **off**, for deployments where the email domain is already trusted (e.g. an intranet). The settings screen shows an explicit warning when enabled.
* Session responses now report `resumed_by` (`token` or `email`) so the mechanism is auditable.

= 1.1.0 =
* Fixed: the message composer and emoji panel were visible before a chat started (CSS `[hidden]` override).
* Fixed: the emoji panel could not be closed (click-outside check broke on the SVG icon); Escape and outside clicks now work and it closes after inserting.
* Fixed: conversation area could overlap the widget header on narrow viewports.
* Fixed: host-theme styles leaked into widget inputs, buttons and textarea (explicit scoped reset).
* New: minimize button (collapses to the launcher and keeps the session alive).
* New: "End chat" for visitors — archives the conversation, keeps history, and a new message reopens it.
* New: animated typing indicator for both the visitor widget and the admin inbox.
* New: two-tone notification chimes for visitors on agent replies, with a persisted sound toggle and state-aware icon.
* New: unread badge + tab-title counter when replies arrive while the widget is minimized or the tab is in the background.
* Improved: default active poll interval lowered to 2s; immediate follow-up poll after sending; faster polling while minimized; admin thread polls at 2s and refreshes 1.2s after a reply.
* Improved: admin replies render optimistically (instant "sending…" bubble) and the reply box auto-grows.

= 1.0.0 =
* Initial release: widget, inbox, agents, assignment, notes, canned responses,
  attachments, PDF export, business hours, notifications, retention, settings.
