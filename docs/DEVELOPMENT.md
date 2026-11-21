# LiveDesk — Development Log

Living record of completed work, remaining tasks and known limitations.

---

## Phase 1 — Core foundation and chat ✅

* Plugin bootstrap, constants, PSR-4-lite autoloader, activation/deactivation hooks.
* 8-table schema via `dbDelta` with a `livedesk_db_version` migration runner.
* Capability model (`livedesk_agent`, `livedesk_manage`, `livedesk_settings`).
* Visitor identification: server-validated name/email, 256-bit token (hashed at
  rest), token rotation on re-identification, 120-day inactivity expiry.
* One persistent conversation per visitor; returning visitors restore history.
* Message storage with UUID idempotency and server timestamps.
* Frontend widget: launcher, identify form, message stream, composer, optimistic
  sending, adaptive polling, reconnect/backoff, emoji, attachments, sounds.
* Basic inbox: conversation list + detail thread + reply composer.

## Phase 2 — Admin dashboard and agent system ✅

* Dashboard overview: status counters, unread, recent conversations, team panel,
  quick setup checklist, self-service availability toggle.
* Inbox: search (name/email/message), status tabs, agent filter, date range,
  pagination, unread badges, live thread updates.
* Exactly three statuses with server-validated transitions and reopen-on-reply.
* Agent management: add existing WP users, enable/disable, remove, availability,
  workload counts. Agents get the plugin pages only (no unrelated admin screens).
* Manual assignment + history; automatic assignment (round-robin / least busy)
  restricted to enabled + available agents.
* Server-side conversation privacy: agents see assigned (and optionally
  unassigned) conversations only; admins see everything.
* Internal notes (add/delete, admin-only delete) excluded from visitor payloads.
* Canned responses: admin CRUD page, agent insertion into the composer.
* Visitor information panel: name, email, device, pages, first seen, last activity.

## Phase 3 — Advanced features ✅

* Secure attachments: allow-list, real MIME sniffing, random filenames, private
  storage outside web root, signed visitor URLs, capability-checked agent URLs,
  image previews, per-file size limit.
* Notifications: admin email on new conversation / reopen (never on every
  message), optional assignment email, test-email button, agent sound + desktop
  notifications, visitor sound with on/off toggle and badge when closed.
* Business hours: per-day windows, holidays, timezone, manual override, combined
  with agent availability.
* Offline modes: queue messages or offline contact form — both stored as normal
  conversations.
* Spam/security: per-visitor and per-IP rate limits, honeypot, reCAPTCHA v2/v3,
  Turnstile, email locking, blocking.
* PDF export: dependency-free generator, full transcript with attachments list,
  notes excluded by default, permission-checked.
* Retention: hourly cron auto-archives idle conversations, emails admins before
  deletion, deletes in small batches, keeps active conversations forever.

## Phase 4 — Settings, optimisation, QA ✅

* Tabbed settings UI covering general, visibility, hours, assignment, offline,
  notifications, attachments, security, retention/privacy and real-time.
* One central sanitizer (allow-lists, clamping, email validation, unknown keys
  dropped).
* Page visibility rules with zero asset loading on excluded pages; post types and
  optional WooCommerce cart/checkout exclusion.
* Translation-ready: text domain everywhere, `languages/livedesk.pot` template.
* Responsive: full-screen widget on mobile, stacked inbox/settings on tablets.
* Empirical QA on a live WordPress instance (see `TEST-REPORT.md`).

---

## Round 1.1.0 — reported UI issues and UX additions ✅

Everything below was reported from a real screenshot and verified in the browser
after the fix (see `TEST-REPORT.md` §15).

1. `[hidden]` is now authoritative inside the widget (`.ld-widget [hidden]{display:none!important}`),
   so the composer/emoji panel cannot appear before a chat exists.
2. Scoped form-control reset under `.ld-widget .ld-panel` stops host themes from
   restyling inputs, textareas and buttons.
3. Emoji panel: `stopPropagation` + `closest()` click-outside, Escape to close,
   auto-close on insert.
4. New minimize control and visitor "End chat" (`POST /visitor/end` → archived,
   history kept, new message reopens).
5. Animated typing bubbles on both sides, driven by the existing
   `visitor_typing` / `agent_typing` flags in the poll payload.
6. Visitor notification chime (Web Audio, no asset files), persisted toggle,
   unread badge and tab-title counter.
7. Latency: 2 s default poll, immediate follow-up poll after send, 5 s while
   minimized, 15 s in a hidden tab; admin inbox polls at 2 s with a 1.2 s kick
   after replying. Admin replies now render an optimistic "sending…" bubble.
8. Layout: explicit flex containment (`min-height:0`, `flex:0 0 auto`) so the
   thread never pushes the composer or overlaps the header on narrow screens.

**New REST route:** `POST livedesk/v1/visitor/end`.
**New strings:** 8 (minimize, end chat, confirm, ended, endSent, newMessage, …) —
`languages/livedesk.pot` regenerated to 364 msgids.

## Round 1.1.1 — security fix ✅

**Vulnerability:** `visitor/session` treated a known email address as proof of
identity, so a guessable address yielded a token and the matching chat history.
This broke the "no access by email alone" requirement.

**Change:** the email fallback is removed from the default path. Resumption
requires the secret token stored in the visitor's browser. Admins who genuinely
want email-keyed history (e.g. an intranet where every visitor is already
authenticated) can enable `security.email_history_resume` — default **off**, with
an explicit warning in the settings UI.

**Tests:** regression suite is now 26 assertions (was 23); the three new ones
cover the fix. All pass.

## Round 1.2.0 — session chat, automated messages, premium UI ✅

1. **Session storage** — the chat token lives in `sessionStorage`; closing the
   browser ends the session. `Security::authenticate_visitor()` enforces a
   server-side lifetime (`general.session_lifetime_hours`, default 2, 0 = off)
   and voids the token when exceeded.
2. **Close vs minimize** — `#ld-close` calls `endSession()` (archives the
   conversation, clears the token, returns to the welcome form);
   `#ld-minimize` only collapses the panel. `POST /visitor/end` also expires the
   token now, so it cannot be replayed.
3. **`Auto_Messages`** (new class) — `send_welcome()` and
   `send_acknowledgement()`. Rows use `sender_type = 'system'`; `Message_Manager`
   accepts that type, skips unread accounting for it, and `to_array()` returns an
   empty sender name. Idempotent via the `welcome_sent` visitor flag and a
   deterministic `client_uuid`.
4. **Agent naming** — `conversation_payload()` supplies `agent_name`;
   `widget.js` renders it on agent bubbles and the header no longer appends it.
5. **Design layer** — appended `PREMIUM DESIGN LAYER` (widget.css) and
   `PREMIUM ADMIN THEME` (admin.css) sections on top of the base styles. New
   tokens are additive; existing class contracts are untouched.
6. **Fixed** — missing `echo` before `esc_textarea()` in the settings view.

New settings: `general.session_lifetime_hours`, `general.welcome_enabled`,
`general.welcome_chat_message`, `general.ack_enabled`, `general.ack_message`.
Settings tabs: **11** (added *Automated messages*).

## Known limitations / deliberate choices

* **Transport** — polling only. WebSockets/SSE are architected for but not
  bundled, because they cannot run on ordinary shared hosting. Swap-in points are
  documented in `ARCHITECTURE.md` §4.
* **PDF fonts** — built-in PDF writer uses Helvetica/cp1252. Emoji and
  non-Western scripts are transliterated or replaced with `?`. The exporter
  interface is isolated so a bundled CJK font library can be added later.
* **WooCommerce** — only visibility exclusion is integrated (no order-context
  panel). The plugin remains fully functional without WooCommerce.
* **Canned response deletion** is admin-only by design.
* **Attachments in PDFs** are listed by filename (not embedded) to keep exports
  small and generation fast.

## Future ideas (not requested, not started)

* WebSocket/SSE gateway plugin adapter.
* Conversation tags and departments.
* Saved inbox views.
* REST endpoints for a mobile PWA agent client.
* WP-CLI commands for bulk maintenance.
