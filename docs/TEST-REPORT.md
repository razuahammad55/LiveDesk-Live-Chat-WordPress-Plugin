# LiveDesk — Test Report

**Environment used for verification**

| Item | Value |
|---|---|
| WordPress | 7.1.5 (fresh install, Twenty Twenty-Five theme) |
| PHP | 8.1.25 (code is PHP 7.4-compatible; see lint results) |
| Database | SQLite via the official SQLite Database Integration drop-in (`$wpdb` API identical to MySQL) |
| Server | PHP built-in server (single-threaded, shared-hosting-like constraints) |
| Browser | Chromium (Playwright), desktop 1280×900 / 1440×1000 and mobile 390×844 |
| Method | Live HTTP requests + real browser automation + WP-CLI-less PHP bootstrap scripts |

Every item below was executed against a running WordPress instance, not inspected
by reading code.

---

## 1. Static checks

| Check | Result |
|---|---|
| `php -l` on all 33 PHP files | ✅ no syntax errors |
| PHP 7.4 compatibility scan (no `match`, `?->`, named args, typed properties, enums, arrow-fn-only syntax, `str_contains`, attributes) | ✅ clean |
| `node --check` on both JS bundles | ✅ clean |
| `.pot` template structure (358 msgids) | ✅ no malformed blocks |
| JS runtime errors in widget + admin (console + pageerror listeners) | ✅ none |

---

## 2. Plugin lifecycle

| Test | Result |
|---|---|
| Activate plugin | ✅ 8 tables created, DB version stored, 3 caps granted to administrator, WP-Cron scheduled |
| Data counts before/after deactivation → reactivation | ✅ identical (visitors, conversations, messages, notes) |
| Deactivation clears cron only | ✅ |
| Re-activation re-schedules cron | ✅ |
| `Database::install()` re-run (idempotent upgrade) | ✅ existing rows intact |
| Simulated update (`db_version` lowered, `plugins_loaded` fired) | ✅ migration ran, version restored |

---

## 3. Visitor flow (REST + real browser)

| Test | Result |
|---|---|
| Start session with name + email | ✅ conversation `LD-000001` created |
| Token is 64 hex chars; only hash stored | ✅ |
| Send message → stored, returned with server timestamp | ✅ |
| Duplicate POST with same UUID returns **same** message ID | ✅ |
| Poll returns new messages, status, online state | ✅ |
| Browser: launcher → panel → identify → conversation view | ✅ |
| Browser: send message, optimistic bubble replaced by server row, ✓ tick shown | ✅ |
| Browser: emoji panel (39 emojis) inserts into composer | ✅ |
| Browser: image attachment preview + send, image bubble renders | ✅ |
| Browser: reload → session restored via localStorage token, history intact | ✅ |
| Browser: close/reopen panel toggles correctly | ✅ |
| Mobile (390×844): panel is full-screen (390×844) | ✅ |
| Returning visitor (same email) resumes **same** conversation | ✅ |
| Token rotated on re-identification; old token rejected | ✅ |
| Visitor reply to archived conversation reopens as `active` | ✅ |
| Visitor sends multiple messages while waiting | ✅ |
| Visitor typing → agent poll reports `visitor_typing: true` | ✅ |
| Agent typing → visitor poll reports `agent_typing: true` | ✅ |
| History pagination (`before_id`) returns older page + `has_more` | ✅ |

---

## 4. Status lifecycle

| Transition | Result |
|---|---|
| New conversation → `active` | ✅ |
| Visitor message → `active`, `admin_unread` incremented | ✅ |
| Agent reply → `waiting`, `visitor_unread` incremented | ✅ |
| Admin archives → `archived`, `archived_at` set | ✅ |
| Visitor reply to archived → `active`, `archived_at` cleared | ✅ |
| Duplicate submission does **not** double-increment unread | ✅ (fixed during testing) |

---

## 5. Admin portal

| Test | Result |
|---|---|
| All five admin pages render HTTP 200 with zero PHP notices | ✅ |
| Dashboard stats: active / waiting / archived / unassigned / mine / unread | ✅ |
| Dashboard: self-service availability toggle | ✅ |
| Inbox: 13 conversations listed, status tabs, search, agent filter, date range, pagination | ✅ |
| Inbox detail: thread renders, visitor panel (name, email, device, landing/current page, first seen, last activity) | ✅ |
| Admin replies from UI → bubble appears, status auto-moves to `waiting` | ✅ |
| Internal note added from UI → appears in notes panel | ✅ |
| Canned responses menu → insertion into composer (45 chars inserted) | ✅ |
| Assignment dropdown → history recorded | ✅ |
| Settings: all 10 tabs render; save through Settings API persists values | ✅ |
| Settings saved → widget title/colour/position reflect on frontend | ✅ |
| Desktop notifications permission flow + sound toggle | ✅ (code path exercised; OS permission cannot be granted headless) |

---

## 6. Agents and permissions

| Test | Result |
|---|---|
| Existing WP subscriber added as agent | ✅ capability granted |
| Agent has `livedesk_agent` but **not** `manage_options`, `import`, `upload_files`, `edit_posts` | ✅ |
| Agent menu contains only Dashboard/Inbox | ✅ |
| Agent opening Settings page → 403 | ✅ |
| Agent opening Agents page → 403 | ✅ |
| Agent can open assigned conversation | ✅ |
| Agent cannot open another agent's conversation (no thread, no visitor email in HTML) | ✅ |
| Agent REST `GET /agent/conversation/{id}` on foreign conversation → 403 | ✅ |
| Agent REST reply on foreign conversation → 403 | ✅ |
| Agent inbox list excludes foreign conversations | ✅ |
| Admin sees all conversations | ✅ |
| Admin replies without assignment (agent_id = 0) | ✅ |
| Agent can change own availability | ✅ |
| Agent cannot assign or delete conversations (AJAX → 403) | ✅ |
| Assignment: manual set/clear, history rows | ✅ |
| Automatic assignment round-robin | ✅ |
| Automatic assignment least-busy | ✅ |
| No agents available → conversation stays unassigned, `is_online` = false | ✅ |

---

## 7. Notes and canned responses

| Test | Result |
|---|---|
| Note stored in `ld_notes`, not `ld_messages` | ✅ |
| Visitor payload never contains note text | ✅ |
| PDF export excludes notes by default | ✅ |
| PDF export includes notes with `include_notes=1` | ✅ |
| Canned response create / update / delete | ✅ |
| Starter canned responses seeded once on activation | ✅ (fixed during testing) |
| Agents can insert and edit before sending | ✅ |

---

## 8. Attachments

| Test | Result |
|---|---|
| Valid PDF upload (visitor) | ✅ stored + row + signed URL |
| `.php` upload | ✅ rejected (`livedesk_upload_type`) |
| PHP payload renamed `.jpg` (MIME sniff mismatch) | ✅ rejected (`livedesk_upload_mime`) |
| Oversize file | ✅ rejected before write (limit configurable) |
| Stored filename random, extension forced | ✅ |
| Files under `uploads/livedesk-private/` with `.htaccess` deny + `index.php` | ✅ |
| Signed visitor URL downloads file (200, correct MIME/size) | ✅ |
| URL without signature → 403 | ✅ |
| Agent-side `<img>` load in inbox (REST cookie nonce as query arg) | ✅ (fixed during testing) |
| Disallowed extension filtered out of settings | ✅ |

---

## 9. Notifications

| Test | Result |
|---|---|
| Admin email on first visitor message (single send) | ✅ attempted via `wp_mail`; delivery fails only because the sandbox has no MTA (`email_failed` logged) — SMTP plugins will deliver |
| No email for subsequent messages in the same conversation | ✅ code path + counters verified |
| Reopen notification respects `notify_reopen` (off by default) | ✅ |
| Test-email button reports failure accurately without an MTA | ✅ |
| Recipient list sanitized (invalid addresses dropped) | ✅ |
| Agent sound + desktop notification triggered on new visitor message | ✅ |
| Visitor sound on agent reply while chat open; badge when closed | ✅ |
| Sound toggle in both widget header and admin composer | ✅ |

---

## 10. Business hours and availability

| Test | Result |
|---|---|
| Monday 12:00 inside 09:00–17:00 (Asia/Dhaka) | ✅ open |
| Monday 08:59 / 17:01 | ✅ closed |
| Saturday (disabled day) | ✅ closed |
| Holiday date | ✅ closed |
| Manual override `online` / `offline` | ✅ forced |
| Online requires ≥1 enabled + available agent | ✅ all-away → offline |
| Overnight window support (end < start) | ✅ logic present and unit-exercised |

---

## 11. Retention and cleanup

| Test | Result |
|---|---|
| Hourly maintenance hook registered | ✅ |
| Auto-archive conversation idle 40 days | ✅ → `archived` |
| Recent-active conversation untouched | ✅ still `active` |
| Archived 200 days → deleted by retention (180-day default) | ✅ rows + attachments removed |
| Archival deletion disabled → data kept | ✅ |
| Upcoming-deletion admin notice | ✅ code path executed |
| Active conversations never auto-deleted | ✅ |
| Batch size 20 per run | ✅ constant |

---

## 12. Security

| Test | Result |
|---|---|
| No token → 401 | ✅ |
| Forged 64-char token → 401 | ✅ |
| Email address alone cannot fetch a conversation | ✅ 401 |
| Agent endpoint without login → 403 | ✅ |
| Agent endpoint without REST nonce → 403 | ✅ |
| AJAX without nonce → 403 | ✅ |
| AJAX as agent for admin-only action → 403 | ✅ |
| Invalid conversation status rejected | ✅ |
| Delete requires literal `DELETE` confirmation | ✅ |
| HTML in message stored and rendered as text (no script execution) | ✅ |
| Settings sanitizer: script tags stripped, `javascript:` colour rejected, enums fall back, numbers clamped, unknown keys dropped, invalid emails dropped, `php`/`exe` extension filtered | ✅ |
| Rate limit: 12 rapid session starts → 5 × 200 then 7 × 429 | ✅ |
| Rate limit: message flood → 429 | ✅ |
| Honeypot filled → `livedesk_spam` | ✅ |
| CAPTCHA disabled by default; misconfigured (no secret) fails open | ✅ |
| PDF export without nonce/login → 403/400 | ✅ |
| Conversation ID tampering by visitor → impossible (no ID accepted) | ✅ |

---

## 13. Performance characteristics observed

| Observation | Result |
|---|---|
| Widget assets load only where enabled (everywhere / selected / exclude / post-type rules verified) | ✅ 0 requests on excluded pages |
| Poll payload grows with new messages only (`after_id` cursor) | ✅ |
| Admin list poll and thread poll are separate; both pause on hidden tab | ✅ |
| Message pages limited to 30 rows | ✅ |
| Unread counts read from aggregate columns | ✅ |
| SQLite drop-in exercised the same `$wpdb->prepare()` code paths as MySQL | ✅ |

---

## 14. Bugs found and fixed during testing

1. **Double side-effects on idempotent resend** — a duplicate UUID re-incremented
   `admin_unread`. `Message_Manager::add()` now flags `_created` and controllers
   skip side-effects for duplicates.
2. **Admin views could not resolve namespaced classes** — views now declare
   `namespace LiveDesk;`.
3. **User-agent regex delimiter clash** on slashes in `Chrome/`, `Safari/`… —
   needles are now escaped with `#` delimiters.
4. **Plain-permalink REST URLs** — appending `?query` to a `rest_route=` URL
   broke polling; both JS clients now build URLs with the correct separator.
5. **Agent attachment `<img>` 403** — REST cookie auth needs `_wpnonce` in the
   query string for non-header requests; agent attachment URLs now include it.
6. **Missing `#ld-panel` id** — JS cached a null node (page error on open);
   the template now carries the id.
7. **"57 years ago" on empty conversations** — epoch timestamps are now shown as
   "no messages yet" and conversation ordering falls back to `created_at`.
8. **Starter canned replies never seeded** — activation now calls
   `maybe_seed_canned()`.
9. **Duplicate "Today" day separator** — optimistic bubbles are removed and
   replaced after the server reply, which made DOM-scanning unreliable. Day
   separators are now driven by a `state.lastDay` key. Verified: 1 separator for
   a 2-message thread.

---

## 15. Post-release round 1.1.0 — UI/UX fixes and additions

Reported from a production screenshot: composer visible before chat start, emoji
panel stuck open, no way to minimize or end the chat, no visitor sound, no typing
animation, and slow send/receive. All items were reproduced, fixed, and verified
in headless Chromium against the live test site.

### 15.1 Root causes and fixes

| # | Report | Root cause | Fix | Verified |
|---|---|---|---|---|
| 1 | Composer visible on the identify screen | `assets/css/widget.css` set `display:flex` / `display:grid` on `.ld-conversation` and `.ld-emoji-panel`, which beats the browser's `[hidden]{display:none}` | Added `.ld-widget [hidden] { display: none !important; }` | `#ld-composer`, `#ld-conversation`, `#ld-emoji-panel`, `#ld-end-chat` all `false` on identify; `true` only after start |
| 2 | Emoji panel would not close | The click-outside handler compared `e.target` with the button, but clicks land on the inner `<svg>`, so it closed the panel the same tick it opened | `stopPropagation()` on the toggle + `target.closest('#ld-emoji')`; added Escape; the panel also closes after inserting an emoji | open = true → Esc closes = true → re-open + insert closes = true |
| 3 | Host theme bled into the widget (green inputs/buttons in the screenshot) | Theme selectors such as `input[type=text]` and `button` outrank the plugin's class-only rules | Scoped reset under `.ld-widget .ld-panel` for `input`, `textarea`, `button` (background, border, radius, `appearance`, shadow, height) with `!important` only on the neutralising properties | Input bg = `rgb(255,255,255)`, start button = `rgb(37,99,235)` (plugin blue) on a themed test page |
| 4 | Cannot minimize | No control existed | New header button (`ld-minimize`): collapses to the launcher, keeps the session and polling alive; the ✕ button now minimizes instead of losing the panel | panel hidden = true, launcher visible = true, reopen = true |
| 5 | Cannot end the chat | No control existed | New `End chat` action → confirms → `POST /visitor/end` archives the conversation; history remains; a new message reopens it through the normal reopen flow | banner shown = true; after sending, banner hidden = true (reopened) |
| 6 | No visitor sound | The tone only fired silently (AudioContext never unlocked by a gesture) | Two-note Web Audio chime; unlocked on the first pointer/key event **anywhere on the page**; toggle persisted in `localStorage`; state-aware speaker/mute icon with `aria-pressed` | Tone path exercised with `--autoplay-policy=no-user-gesture-required`; toggle state survives reload |
| 7 | No typing animation | Only a text row, visitor side had a static dot row | Animated three-dot bubble injected at the end of the thread on both sides; removed as soon as a real message arrives | visitor→admin bubble = true (3 dots); admin→visitor bubble = true |
| 8 | Send/receive feels slow | 3s default poll, full round-trip before the bubble appeared | Optimistic bubbles on both sides (visitor already had one; admin added), immediate follow-up poll after sending, defaults 2s, 2s while minimized / 5s when collapsed, 15s hidden tab, admin thread 2s + a 1.2s kick after replying | send→render 58–65 ms; agent reply→visitor render 1.19–1.26 s |

### 15.2 Layout hardening

The "End chat" row initially overlapped the header on 420 px-wide viewports
because the flex column had no `min-height: 0`/`overflow` containment. The panel,
header, conversation, message list and composer now all declare explicit
`flex: 0 0 auto` / `flex: 1 1 auto` + `min-height: 0`. Verified at **360×640,
390×844, 420×900 and 1280×860**: header overlap = none, composer always inside
the panel, message list scrolls.

### 15.3 Regression after the round

* `php -l` on all 33 PHP files — clean.
* `node --check` on both bundles — clean.
* `/tmp/final_regression.sh` — **23/23 pass** (session, token, idempotency, poll,
  bootstrap, security negatives, returning visitor, attachments).
* Browser E2E — zero JS errors on both visitor and admin.

---

## 16. Security fix found while resuming the preview (1.1.1)

While bringing the demo site back up, a fresh browser session displayed messages
from a previous test run. Investigating the cause uncovered a **privacy
vulnerability** that contradicted the spec requirement *"no access by email
alone"*.

### 16.1 The flaw

`POST /livedesk/v1/visitor/session` fell back to `Visitor_Manager::find_by_email()`
when no token was supplied. That path rotated the token for the matched visitor
and returned their full conversation — so **anyone who knew or guessed a
visitor's email address could read that visitor's entire chat history**.

### 16.2 Proof of concept (before the fix)

```
POST /wp-json/livedesk/v1/visitor/session
{"name":"Random Attacker","email":"preview@example.com"}
-> resumed: True      <- a token was issued
-> conversation ref: LD-000030
-> messages exposed: 1  [agent] "Hello from the preview check!"
```

### 16.3 The fix

The email lookup is no longer part of the default path. Identity is proven only
by the 64-hex secret token (`Security::hash_token` / HMAC-SHA256, hash-only
storage). A known email with no token now creates a **new** conversation, which
keeps the visitor flow frictionless without exposing anyone else's history.

A deliberate, documented escape hatch exists for trusted environments:
`security.email_history_resume` (**default off**, checkbox + warning on the
Security tab). The stored-token resume path is unchanged for real returning
visitors.

### 16.4 Verification

| Test | Before | After |
|---|---|---|
| Same email, no token → history exposed | 1 message leaked | **0 messages, new conversation** |
| Same email, no token → token issued | yes (privilege escalation) | **no** |
| Stored token resumes same conversation | yes | **yes** (`resumed_by: token`) |
| Opt-in enabled → email resume | — | **yes** (`resumed_by: email`) |
| Opt-in restored to default | — | **off** |

Regression suite extended from 23 to **26 assertions**, with three new cases
locking the behaviour in (`email alone does not resume`, `no history leaked`,
`new conversation created`). Full run: **26/26 pass**.

`assets/screenshots/11-security-setting.png` shows the new setting and warning.

---

## 17. Round 1.2.0 — session-based chat, automated messages, premium UI

Five requested changes, each implemented and verified in a real browser.

### 17.1 Session-based conversations

**Requirement:** closing the browser ends the chat; reopening must not reveal the
previous conversation.

The session token moved from `localStorage` to `sessionStorage`, which browsers
clear when the tab/window closes. A server-side lifetime (`general.session_lifetime_hours`,
default 2, 0 disables) is the backstop for idle sessions: it rejects the token and
voids it. Conversations are never deleted — agents keep them in the inbox.

| Test (persistent browser profile, closed and relaunched) | Result |
|---|---|
| Token present after restart | **GONE** |
| Widget shows welcome form | **true** |
| Old thread rendered | **false** |
| Message sent before restart is still stored server-side | **yes** |

### 17.2 Close ends the session, minimize only collapses

| Action | Token | Panel | Conversation | Reopening shows |
|---|---|---|---|---|
| Minimize (—) | kept | hidden | untouched | same thread, composer ready |
| Close (✕) | **cleared** | hidden | **archived** | welcome form |

Close asks for confirmation and archives the conversation so the team keeps the
record. Verified: after close the token is `null`, the panel is closed, reopening
shows the identify form with no old thread, and the DB row reads
`status=archived`.

### 17.3 Automated messages

Stored as `sender_type = 'system'`, so they are attributed to the site (never to
a staff member), render as a centred notice rather than a bubble, and do not
increment `admin_unread`.

| Test | Result |
|---|---|
| Welcome uses the visitor's name | `Hi Amara Ahmed, how can we help you today?` |
| Acknowledgement fires on the first visitor message | present |
| Second visitor message does not re-acknowledge | still exactly 2 system messages |
| Duplicate submit (same UUID) does not duplicate | 4 messages total, unchanged |
| Both are switchable + editable | Settings → Automated messages |
| Placeholders | `{user_name}`, `{user_email}`, `{site_name}`, `{ref}` |

### 17.4 Agent name inside the conversation

The header shows online/offline only. Agent replies are attributed inside the
thread. Verified: header text `"Online now"`, thread name `"Agent"` (the WP
user's display name).

### 17.5 Premium UI

A design layer was added on top of the existing stylesheets rather than replacing
them, so behaviour and layout contracts are unchanged.

Widget: layered header with sheen, refined type scale, softer shadows, a
composer that lifts on focus, centred day pills, notice cards for automated
messages, state-aware icon buttons, mobile safe-area padding.

Admin: consistent design tokens across every screen, stat tiles with an accent
rail, refined badges and buttons, two-pane inbox that keeps its shape when
nothing is selected (with a real empty state), soft thread surface, `AUTO` chips
on system messages, and a tidied settings tab bar.

Verified at **390×844 (mobile), 430×900, 460×900 and 1460×1000** — zero JS
errors, zero console errors, no layout overlap.

### 17.6 Bug found and fixed in this round

**`esc_textarea()` called without `echo`** on the Holding reply field
(`admin/views/settings.php`). The textarea always rendered empty, so the next
save persisted an empty string — silent data loss of a default value. Fixed by
echoing the escaped value; a scan confirmed no other `esc_*()` call in the
plugin is missing its `echo`. Verified by round-tripping a real save through the
browser.

### 17.7 Regression

Suite extended from 26 to **35 assertions** (automated messages ×5, session ends
×3, welcome/no-prior-history ×2). Full run: **35/35 pass**. Previous browser
suites (typing indicators, optimistic replies, unread badge, latency) still pass;
send→render 65 ms, agent reply→visitor 1.49 s.

---

## 18. Not verifiable in this sandbox (environment limits)

| Item | Reason / mitigation |
|---|---|
| Real email delivery | No MTA in the sandbox; `wp_mail()` failures are logged. Code uses `wp_mail()` with HTML headers so SMTP plugins work unchanged. |
| MySQL/MariaDB specifically | SQLite drop-in used the identical `$wpdb` API and prepared statements; schema is standard `dbDelta` with MySQL types. |
| CAPTCHA round-trip | Requires real Google/Cloudflare keys; verification code path (including v3 score threshold and fail-open) is implemented and server-side. |
| OS-level desktop notifications | Headless browser cannot grant the permission; permission-request flow is implemented. |
| Emoji glyph rendering | Sandbox lacked emoji fonts (boxes in the reporter's screenshot were from an unusual Linux desktop too). Installing `fonts-noto-color-emoji` here made every glyph render, which confirms the widget uses standard Unicode. The font stack lists Segoe UI Emoji, Apple Color Emoji, Noto Color Emoji and Android Emoji, so Windows/macOS/iOS/Android render natively. Composer controls use inline SVG so no icon depends on an emoji font. |
| Audible confirmation of the chime | Headless Chromium can be launched with `--autoplay-policy=no-user-gesture-required`, which proves the Web Audio graph is built and started, but the sandbox has no audio sink. The on-real-device path (first gesture → `AudioContext.resume()`) is the documented one. |
| Large-scale load | Single-threaded PHP server; query plans were reviewed and indexed instead. |
