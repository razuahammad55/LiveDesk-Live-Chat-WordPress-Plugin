# LiveDesk Live Chat — Architecture & Design

Version 1.0.0 · Requires WordPress 5.8+ · Requires PHP 7.4+

---

## 1. Overview

LiveDesk is a self-hosted live chat and customer-support plugin for WordPress. It
adds a visitor chat widget and a full agent inbox inside `wp-admin`. No external
service, SaaS account or paid API is required; the only optional third parties are
CAPTCHA providers (Google reCAPTCHA / Cloudflare Turnstile), which are disabled by
default.

Design goals, in priority order:

1. **Security** — visitor identity is a 256-bit random token; every request is
   authorized server-side against the row that owns the token or the agent's
   capability + conversation ownership.
2. **Shared-hosting compatibility** — plain PHP + MySQL/MariaDB, WP-Cron, AJAX
   polling. No Node process, WebSocket server, Redis or queue is needed.
3. **Correct data model** — dedicated tables with indexes and forward-only
   migrations; the plugin never drops user data on update.
4. **Maintainability** — one class per responsibility, a small bootstrap, and a
   single settings registry.

---

## 2. File structure

```
livedesk/
├── livedesk.php                     # Bootstrap: constants, autoloader, activation hooks
├── uninstall.php                    # Opt-in data removal on uninstall
├── includes/
│   ├── class-plugin.php             # Container: wires modules
│   ├── class-activator.php          # Activation: schema, caps, cron, seed canned replies
│   ├── class-deactivator.php        # Deactivation: clears cron only (data preserved)
│   ├── class-database.php           # Schema + migration runner (dbDelta)
│   ├── class-settings-manager.php    # Single options array + central sanitization
│   ├── class-permissions.php         # Caps, roles, per-conversation authorization
│   ├── class-security.php            # Token hashing, rate limiting, honeypot, CAPTCHA,
│   │                                 #   visitor authentication
│   ├── class-utils.php               # Formatting, user-agent parsing, helpers
│   ├── class-visitor-manager.php     # Visitor records, tokens, typing transients
│   ├── class-conversation-manager.php# Status lifecycle, queries, deletion
│   ├── class-message-manager.php     # Message storage, idempotency, serialization
│   ├── class-assignment-manager.php  # Manual + auto assignment (round-robin / least busy)
│   ├── class-agent-manager.php       # Agent roster, availability, workload
│   ├── class-notes-manager.php       # Private internal notes
│   ├── class-canned-responses.php    # Saved replies
│   ├── class-attachment-manager.php  # Private storage, MIME sniffing, signed URLs
│   ├── class-business-hours.php      # Availability resolution (timezone-aware)
│   ├── class-notification-manager.php# Email notifications (wp_mail, SMTP-plugin friendly)
│   ├── class-cron-manager.php        # Hourly retention + auto-archive jobs
│   ├── class-pdf-exporter.php        # Dependency-free PDF generation
│   ├── class-logger.php              # Debug logging (WP_DEBUG only)
│   ├── class-rest-controller.php     # Visitor + agent REST endpoints
│   ├── class-ajax-controller.php     # Admin AJAX actions (nonce + caps + ownership)
│   ├── class-frontend-widget.php     # Conditional asset loading, widget config
│   └── class-admin-controller.php    # Menus, assets, PDF export handler, payloads
├── admin/views/                      # dashboard, inbox, agents, canned, settings
├── assets/css/ widget.css, admin.css
├── assets/js/  widget.js, admin.js
├── templates/widget-shell.php
├── languages/livedesk.pot            # Translation template
└── docs/ARCHITECTURE.md, TEST-REPORT.md, DEVELOPMENT.md
```

**Autoloading.** A 6-line PSR-4-lite autoloader maps `LiveDesk\Foo_Bar` to
`includes/class-foo-bar.php`. No Composer dependency.

---

## 3. Database schema

Eight tables, all prefixed `{wp_prefix}ld_`. The plugin stores the schema version
in the `livedesk_db_version` option and runs `dbDelta()` whenever the code version
is newer, so upgrades add columns/tables without touching existing rows.

### `ld_visitors`
| column | type | notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| name, email | VARCHAR(190) | validated server-side |
| token_hash | CHAR(64) | HMAC-SHA256 of the token; raw token never stored |
| user_id | BIGINT | set when the visitor is a logged-in WP user |
| created_at, last_activity | DATETIME (GMT) | |
| last_ip | VARBINARY(45) | IPv6-safe |
| browser, os, device, user_agent | VARCHAR | parsed from UA, display only |
| landing_page, current_page | VARCHAR(500) | |
| admin_blocked | CHAR(1) | admin can revoke a visitor session |
| vacation_at | DATETIME NULL | reserved for token expiry tooling |

Indexes: `email`, `token_hash`, `last_activity`.

### `ld_conversations` — **one per visitor**
| column | type | notes |
|---|---|---|
| id | BIGINT PK | |
| visitor_id | BIGINT (indexed) | |
| status | VARCHAR(20) (indexed) | exactly `active` / `waiting` / `archived` |
| agent_id | BIGINT (indexed) | `0` = unassigned |
| admin_unread, visitor_unread | INT | maintained counters |
| visitor_last_read_message | BIGINT | |
| ticket_ref | VARCHAR(24) | e.g. `LD-000123` |
| message_count | INT | |
| archived_from | VARCHAR(20) | previous status (reopen fidelity) |
| created_at, updated_at, last_message_at, archived_at | DATETIME | |

Indexes: `visitor_id`, `status`, `agent_id`, `last_message_at`.

### `ld_messages`
`id`, `conversation_id`, `sender_type` (`visitor|agent|admin`), `sender_id`
(visitor id or WP user id), `body` (LONGTEXT), `client_uuid` (idempotency key),
`is_read`, `read_at`, `created_at`.
Indexes: composite `(conversation_id, id)` for thread reads, `client_uuid`,
`created_at`.

### `ld_attachments`
`id`, `message_id`, `conversation_id`, `original_name`, `stored_name` (random),
`mime_type` (sniffed), `file_size`, `created_at`.

### `ld_notes`
`id`, `conversation_id`, `author_id`, `content`, `created_at`. Never serialized
into visitor payloads or default PDF exports.

### `ld_canned`
`id`, `title`, `content`, `created_by`, `updated_at`.

### `ld_agents`
`id`, `user_id` (UNIQUE), `enabled`, `availability` (`available|away`), `added_at`,
`updated_at`.

### `ld_assignments`
`id`, `conversation_id`, `from_agent_id`, `to_agent_id`, `assigned_by`, `mode`
(`manual|auto`), `created_at`. Provides assignment history.

---

## 4. Real-time communication

### Transport
The default and only required transport is **AJAX/REST polling** driven by the
browser. It runs anywhere PHP can run, needs no open ports, and survives proxies
and shared hosting.

### Endpoints involved
| Direction | Endpoint | Purpose |
|---|---|---|
| Visitor → server | `POST /livedesk/v1/visitor/poll` | new messages since `after_id`, status, agent typing |
| Visitor → server | `POST /livedesk/v1/visitor/messages` | send message (idempotent by `client_uuid`) |
| Visitor → server | `POST /livedesk/v1/visitor/typing` | 8-second typing transient |
| Visitor → server | `POST /livedesk/v1/visitor/upload` | multipart attachment send |
| Agent → server | `GET /livedesk/v1/agent/poll` | unread counts + messages for the open thread |
| Agent → server | `POST /livedesk/v1/agent/reply` / `note` / `typing` | |

### Adaptive polling
Both clients schedule the next poll rather than firing a fixed timer, and every
client sets `pollInFlight` so overlapping requests cannot happen.

| Condition | Interval (default) |
|---|---|
| Widget open, conversation active | `realtime.poll_active` = 3 s |
| Widget open, status = waiting | `realtime.poll_waiting` = 3 s |
| Tab hidden **or** widget closed | `realtime.poll_idle` = 20 s |
| After sending | immediate refresh, then resume |
| Network failure | exponential backoff capped at 30 s |

The admin client pauses polling while `document.hidden` and resumes on
`visibilitychange`; the widget does the same. Because each poll returns only rows
`WHERE id > after_id`, the payload scales with the number of *new* messages, not
the history size.

### Guarantees
* **No duplicates** — every message POST carries a UUID; the server returns the
  existing row for a repeated UUID and controllers skip side-effects for
  duplicates (verified in testing).
* **Ordering** — server timestamps (`current_time('mysql', true)`) and
  monotonically increasing IDs; clients render by `id`.
* **Persistence** — messages hit MySQL before the response is sent; a network
  failure after storage still leaves the message stored and it appears on the
  next poll.
* **Typing indicators are temporary** — stored as 8-second transients, never as
  rows. A flood of keystrokes cannot grow the database.

### Future WebSocket/SSE support
`assets/js/widget.js` (`poll()` / `schedulePoll()`) and `assets/js/admin.js`
(`pollConversation()`) are the only polling-aware code paths. A future transport
can replace their scheduling without touching the REST controllers, the database
model, or the PHP classes. Nothing in the data model assumes polling.

---

## 5. Security model

Threats considered: enumeration, conversation hijacking, XSS, SQL injection, CSRF,
malicious uploads, spam floods, IDOR through parameter tampering, and
agent-to-agent data leaks.

### Visitor authentication
* 32 random bytes (`random_bytes`) → 64 hex chars issued once.
* Only `HMAC-SHA256(token, wp_salt('auth'))` is stored (`token_hash`).
* The token travels in an `X-LiveDesk-Token` header (or `livedesk_token` cookie),
  never in a URL and never as an email address.
* An email address alone can never retrieve a conversation.
* Token rotation on re-identification invalidates the previous token (verified:
  old token → 401/404).
* Tokens expire after 120 days of inactivity (filterable).
* Admins can block a visitor session (`admin_blocked`).

### Authorization on every request
* Visitor endpoints resolve the visitor **from the token** and then load *that
  visitor's* conversation. There is no client-supplied conversation ID on the
  visitor side at all, so IDOR is structurally impossible.
* Agent REST endpoints verify the WP cookie nonce, the `livedesk_agent`
  capability, and `Permissions::can_access_conversation()` (admins: all;
  agents: assigned or — configurable — unassigned). Enforcement happens in PHP,
  not in the UI.
* AJAX handlers use `check_ajax_referer('livedesk_admin')`, capability checks,
  and the same ownership helper.
* PDF export is a nonce-protected `admin-post.php` action with the same access
  check.

### Input/output
* All SQL goes through `$wpdb->prepare()`; identifiers are never user input.
* All output is escaped (`esc_html`, `esc_attr`, `esc_url`, `esc_textarea`) or
  inserted via `textContent` in JavaScript. Message bodies are plain text.
* Settings pass through one central sanitizer (allow-lists for enums, hex
  validation for colors, clamping for numbers, `sanitize_email` for recipients).

### Uploads
* Extension allow-list (admin-configurable, hard-capped to a safe master list).
* Real MIME sniffing (`finfo`/`mime_content_type`), never the browser's claim;
  extension↔MIME must match.
* Double-extension/executable patterns are rejected.
* Random server-side filename, forced safe extension, `chmod 0640`.
* Files live in `uploads/livedesk-private/` with an `.htaccess` deny rule and an
  `index.php` guard, and are **never** web-accessible directly.
* Downloads stream through PHP with a per-file HMAC signature (visitor) or a
  capability + ownership check (agent).

### Spam
* Fixed-window rate limiting per visitor and per IP (configurable).
* Honeypot field (filled → rejected).
* Optional reCAPTCHA v2/v3 or Turnstile — tokens verified server-side; failures
  never lock out visitors if the provider is misconfigured.
* A misconfigured CAPTCHA (empty secret) fails open so support is not broken.

---

## 6. Status lifecycle

```
        first visitor message
  (new) ─────────────────────▶ active
                                 │  ▲
                    agent reply  │  │  visitor reply
                                 ▼  │
                              waiting
                                 │
                    agent archives│
                                 ▼
                              archived ──visitor reply──▶ active
```

Exactly three statuses. Visitors cannot see or set the status; agents and admins
change it through a server-validated AJAX action. Visitor replies always force
`active` and clear `archived_at`, so a returning visitor can always continue the
same conversation.

---

## 7. Availability and offline behaviour

`Business_Hours::is_online()` returns true only when **all** conditions hold:

1. Manual override is not `offline` (and nothing else if override is `online`).
2. The current time falls inside the configured weekly schedule for the site's
   timezone (or the explicit timezone setting), and today is not a holiday.
3. At least one **enabled** agent is marked **available**.

Being logged into WordPress does not matter. When offline, the widget shows the
configured heading/message and either continues accepting messages (queue mode)
or presents the offline contact form. Both modes write to the same conversation
record, so nothing is lost.

---

## 8. Retention and cleanup

Hourly `livedesk_cron_maintenance`:

1. Archives conversations with no activity for 30 days (filterable).
2. Emails admins about archived conversations approaching deletion (once per day).
3. Permanently deletes archived conversations older than the retention window
   (default 180 days / 6 months), 20 per run, including attachment files when
   configured.

Active conversations are never deleted by cron. Uninstall removes data only when
the administrator opts in (`LIVEDESK_REMOVE_DATA_ON_UNINSTALL` or the
`livedesk_remove_data_on_uninstall` filter).

---

## 9. Performance notes

* One autoloaded options row powers all configuration.
* Frontend CSS/JS load **only** on pages where the widget displays.
* Messages are paged (default 30) and fetched by `id > after_id`; full history is
  never loaded for a poll.
* Unread counters are aggregate columns, not `COUNT(*)` scans per poll.
* Composite indexes cover every hot query.
* The admin poll returns counts in a single request; the thread poll is separate
  and only runs while a conversation is open.
* PDF generation is on-demand and streamed, never stored.

---

## 10. Deliberate scope decisions

| Requested | Decision |
|---|---|
| WebSocket transport | Not required. Polling is the default; the design keeps the transport swappable (see §4). A WebSocket server cannot run on ordinary shared hosting, so requiring it would break the primary compatibility goal. |
| PDF library | A minimal built-in PDF writer (Helvetica, WinAnsi, automatic page wrapping) avoids shipping a 10+ MB dependency. Non-Latin text is transliterated; for CJK-heavy sites an optional bundled library can be added later without changing the exporter interface. |
| CAPTCHA | Optional, off by default; server-side verification included. |
| WooCommerce | Only a visibility toggle on cart/checkout is provided (plugin stays independent). Deeper integration is a future extension. |
