/**
 * LiveDesk — visitor chat widget.
 *
 * Vanilla JS, no dependencies. Uses token-authenticated REST endpoints and
 * adaptive polling (active / waiting / hidden-tab intervals with backoff).
 */
(function () {
	'use strict';

	var cfg = window.livedeskConfig || null;
	if (!cfg) { return; }

	/*
	 * The chat token lives in sessionStorage, which the browser clears when the
	 * tab/window closes. That makes the conversation session-based: reopen the
	 * browser and the visitor starts a fresh chat. (The sound preference is a
	 * user setting, so it stays in localStorage.)
	 */
	var STORAGE_KEY = 'livedesk_token';
	var store = (function () {
		try {
			var t = '__ld_test';
			window.sessionStorage.setItem(t, '1');
			window.sessionStorage.removeItem(t);
			return window.sessionStorage;
		} catch (e) {
			return null; // private mode / blocked storage: fall back to memory.
		}
	}());
	var memoryStore = {};

	var state = {
		token: null,
		open: false,
		identified: false,
		conversation: null,
		messages: {},          // id -> message object
		rendered: {},          // id -> DOM node
		lastId: 0,
		oldestId: 0,
		lastDay: '',
		hasMore: false,
		online: true,
		sound: true,
		unread: 0,
		pollTimer: null,
		pollInFlight: false,
		failures: 0,
		lastTypingSent: 0,
		typingStopTimer: null,
		pendingFile: null,
		sending: false,
		seenStart: false,
		minimized: false,
		hasUnreadAgent: false,
		audioUnlocked: false,
		typingHideTimer: null,
		documentTitle: document.title
	};

	function $(id) { return document.getElementById(id); }

	var els = {};

	function cacheEls() {
		['widget', 'panel', 'launcher', 'badge', 'title', 'status', 'avatar', 'sound', 'close', 'minimize',
			'identify', 'identifyForm', 'name', 'email', 'hp', 'captcha', 'start', 'welcomeText',
			'welcome', 'consent', 'conversation', 'messages', 'typing', 'composer', 'input', 'send',
			'attach', 'file', 'emoji', 'emojiPanel', 'attachmentsPreview', 'connStatus', 'logo']
			.forEach(function (k) {
				var el = $('ld-' + k.replace(/[A-Z]/g, function (m) { return '-' + m.toLowerCase(); }));
				els[k] = el;
			});
		els.widget = $('livedesk-widget');
		els.endChatBtn = $('ld-end-chat');
		els.endedNote = $('ld-ended-note');
	}

	function esc(str) {
		return String(str == null ? '' : str)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function endpoint(path, params) {
		var url = cfg.restUrl + path;
		if (params) {
			var qs = new URLSearchParams(params).toString();
			if (qs) { url += (url.indexOf('?') === -1 ? '?' : '&') + qs; }
		}
		return url;
	}

	function api(path, opts) {
		opts = opts || {};
		var headers = { 'X-WP-Nonce': cfg.nonce };
		if (state.token) { headers['X-LiveDesk-Token'] = state.token; }
		if (opts.json) { headers['Content-Type'] = 'application/json'; }
		return fetch(endpoint(path), {
			method: opts.method || 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: opts.json ? JSON.stringify(opts.json) : opts.body
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) {
					var err = new Error(data.message || cfg.strings.error);
					err.status = res.status;
					err.code = data.code || '';
					throw err;
				}
				return data;
			});
		});
	}

	function uuid() {
		if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = Math.random() * 16 | 0;
			return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
		});
	}

	function formatTime(ts) {
		try {
			return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
		} catch (e) { return ''; }
	}

	function formatDay(ts) {
		var d = new Date(ts);
		var today = new Date();
		var yest = new Date(); yest.setDate(today.getDate() - 1);
		if (d.toDateString() === today.toDateString()) { return 'Today'; }
		if (d.toDateString() === yest.toDateString()) { return 'Yesterday'; }
		return d.toLocaleDateString([], { year: 'numeric', month: 'short', day: 'numeric' });
	}

	/* ------------------------------------------------------------------ */
	/* Sound                                                              */
	/* ------------------------------------------------------------------ */

	var audioCtx = null;

	/**
	 * Unlock Web Audio on the first user gesture, so notification sounds are
	 * allowed by browsers even when a message arrives later.
	 */
	function unlockAudio() {
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if (!Ctx) { return; }
			audioCtx = audioCtx || new Ctx();
			if (audioCtx.state === 'suspended') { audioCtx.resume(); }
			state.audioUnlocked = true;
		} catch (e) { /* ignore */ }
	}

	/**
	 * Play a short two-note chime.
	 *
	 * @param {string} kind 'agent' (incoming reply) or 'sent' (own message).
	 */
	function playTone(kind) {
		if (!state.sound) { return; }
		if (!state.audioUnlocked) { return; }
		try {
			if (!audioCtx) { return; }
			if (audioCtx.state === 'suspended') { audioCtx.resume(); }
			var now = audioCtx.currentTime;
			var notes = (kind === 'sent') ? [[660, 0], [880, 0.08]] : [[880, 0], [1174.7, 0.12]];
			notes.forEach(function (pair) {
				var osc = audioCtx.createOscillator();
				var gain = audioCtx.createGain();
				osc.type = 'sine';
				osc.frequency.setValueAtTime(pair[0], now + pair[1]);
				gain.gain.setValueAtTime(0.0001, now + pair[1]);
				gain.gain.exponentialRampToValueAtTime(0.18, now + pair[1] + 0.02);
				gain.gain.exponentialRampToValueAtTime(0.0001, now + pair[1] + 0.22);
				osc.connect(gain);
				gain.connect(audioCtx.destination);
				osc.start(now + pair[1]);
				osc.stop(now + pair[1] + 0.26);
			});
		} catch (e) { /* ignore */ }
	}

	/** Backwards-compatible alias used by older call sites. */
	function beep() { playTone('agent'); }

	function updateSoundButton() {
		if (!els.sound) { return; }
		els.sound.style.opacity = state.sound ? '1' : '.45';
		els.sound.setAttribute('aria-label', state.sound ? cfg.strings.soundOn : cfg.strings.soundOff);
		els.sound.setAttribute('title', state.sound ? cfg.strings.soundOn : cfg.strings.soundOff);
		els.sound.setAttribute('aria-pressed', state.sound ? 'true' : 'false');
		var on = els.sound.querySelector('.ld-sound-on');
		var off = els.sound.querySelector('.ld-sound-off');
		if (on && off) { on.style.display = state.sound ? '' : 'none'; off.style.display = state.sound ? 'none' : ''; }
	}

	/* ------------------------------------------------------------------ */
	/* Session-scoped storage                                             */
	/* ------------------------------------------------------------------ */

	function storeGet(key) {
		try {
			if (store) { return store.getItem(key); }
		} catch (e) { /* ignore */ }
		return Object.prototype.hasOwnProperty.call(memoryStore, key) ? memoryStore[key] : null;
	}

	function storeSet(key, value) {
		try {
			if (store) { store.setItem(key, value); return; }
		} catch (e) { /* ignore */ }
		memoryStore[key] = value;
	}

	function storeRemove(key) {
		try {
			if (store) { store.removeItem(key); }
		} catch (e) { /* ignore */ }
		delete memoryStore[key];
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Render an automated message as a centred "notice" row.
	 *
	 * @param {Object} msg Message payload.
	 */
	function renderSystemMessage(msg) {
		if (state.rendered[msg.id]) { return; }
		var row = document.createElement('div');
		row.className = 'ld-system';
		row.setAttribute('data-id', msg.id);

		var card = document.createElement('div');
		card.className = 'ld-system-card';
		var icon = document.createElement('span');
		icon.className = 'ld-system-icon';
		icon.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10"></path><path d="M12 6v6l4 2"></path></svg>';
		var text = document.createElement('p');
		text.className = 'ld-system-text';
		text.textContent = msg.body || '';
		card.appendChild(icon);
		card.appendChild(text);
		row.appendChild(card);

		var dayKey = new Date((msg.created_ts || 0) * 1000).toDateString();
		if (dayKey !== state.lastDay) {
			var day = document.createElement('div');
			day.className = 'ld-day';
			day.textContent = formatDay((msg.created_ts || 0) * 1000);
			els.messages.appendChild(day);
			state.lastDay = dayKey;
		}

		els.messages.appendChild(row);
		state.rendered[msg.id] = row;
		if (msg.id > state.lastId) { state.lastId = msg.id; }
	}

	/**
	 * Name to attribute replies to inside the thread.
	 *
	 * Falls back to the widget title when no agent has picked the chat up yet.
	 *
	 * @return {string}
	 */
	function agentName() {
		if (state.conversation && state.conversation.agent_name) {
			return state.conversation.agent_name;
		}
		return cfg.settings.widgetTitle || cfg.strings.support || '';
	}

	function renderMessage(msg, opts) {
		opts = opts || {};
		if (state.rendered[msg.id] && !opts.replaceOnly) { return; }

		// Automated messages are a centred notice, not a chat bubble.
		if (msg.sender_type === 'system') {
			renderSystemMessage(msg);
			return;
		}
		// First render in an (apparently) empty thread: clear transient nodes such
		// as a stale "Load earlier" button or a duplicated day separator.
		if (opts.initial && !state.messagesRendered) { state.messagesRendered = 0; }

		var out = msg.sender_type === 'visitor';
		var wrap = document.createElement('div');
		wrap.className = 'ld-msg ' + (out ? 'ld-msg-out' : 'ld-msg-in');
		wrap.setAttribute('data-id', msg.id);
		if (msg.client_uuid) { wrap.setAttribute('data-uuid', msg.client_uuid); }

		var bubble = document.createElement('div');
		bubble.className = 'ld-bubble';
		if (msg.body) { bubble.textContent = msg.body; }
		else { bubble.style.display = 'none'; }

		if (msg.attachments && msg.attachments.length) {
			var atts = document.createElement('div');
			atts.className = 'ld-attachments';
			msg.attachments.forEach(function (att) {
				if (att.is_image) {
					var link = document.createElement('a');
					link.href = att.url; link.target = '_blank'; link.rel = 'noopener';
					var img = document.createElement('img');
					img.className = 'ld-image-att'; img.src = att.url; img.alt = att.name; img.loading = 'lazy';
					img.onerror = function () { img.style.display = 'none'; };
					link.appendChild(img);
					atts.appendChild(link);
				} else {
					var file = document.createElement('a');
					file.className = 'ld-file-att';
					file.href = att.url; file.target = '_blank'; file.rel = 'noopener';
					file.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg> <span></span>';
					file.querySelector('span').textContent = att.name;
					atts.appendChild(file);
				}
			});
			bubble.appendChild(atts);
		}
		wrap.appendChild(bubble);

		var meta = document.createElement('div');
		meta.className = 'ld-msg-meta';
		var who = document.createElement('span');
		who.className = 'ld-msg-name';
		// Agent replies are attributed to the person handling the chat.
		who.textContent = out ? (msg.sender_name || '') : (msg.sender_name || agentName());
		var time = document.createElement('span');
		time.textContent = formatTime(msg.created_ts);
		meta.appendChild(who); meta.appendChild(time);
		if (out) {
			var tick = document.createElement('span');
			tick.className = 'ld-tick';
			tick.textContent = msg.is_read ? '✓✓' : '✓';
			meta.appendChild(tick);
		}
		wrap.appendChild(meta);

		// Day separator: deterministic, state-driven (DOM order is not reliable
		// because optimistic bubbles are removed/replaced after the server reply).
		var dayKey = new Date(msg.created_ts * 1000).toDateString();
		if (dayKey !== state.lastDay) {
			var day = document.createElement('div');
			day.className = 'ld-day';
			day.textContent = formatDay(msg.created_ts * 1000);
			els.messages.appendChild(day);
			state.lastDay = dayKey;
		}

		wrap.setAttribute('data-ts', msg.created_ts);
		els.messages.appendChild(wrap);
		state.rendered[msg.id] = wrap;
		if (msg.id > state.lastId) { state.lastId = msg.id; }
		if (!state.oldestId || msg.id < state.oldestId) { state.oldestId = msg.id; }
	}

	function renderDaySeparatorIfNeeded(ts) {
		if (!els.messages.children.length) {
			var day = document.createElement('div');
			day.className = 'ld-day';
			day.textContent = formatDay(ts * 1000);
			day.setAttribute('data-day', '1');
			els.messages.appendChild(day);
		}
	}

	function renderAllMessages(list) {
		els.messages.innerHTML = '';
		state.rendered = {};
		state.lastId = 0;
		state.oldestId = 0;
		state.lastDay = '';
		list.forEach(function (m) { renderMessage(m); });
	}

	function scrollToBottom(force) {
		var el = els.messages;
		if (!el) { return; }
		var nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
		if (force || nearBottom) { el.scrollTop = el.scrollHeight; }
	}

	function showConversation() {
		state.identified = true;
		els.identify.hidden = true;
		els.conversation.hidden = false;
		els.status.hidden = false;
		updateStatus();
		if (state.conversation && state.conversation.messages) {
			renderAllMessages(state.conversation.messages);
		}
		scrollToBottom(true);
		els.input.focus({ preventScroll: true });
	}

	function showIdentify() {
		state.identified = false;
		els.conversation.hidden = true;
		els.identify.hidden = false;
	}

	function updateStatus() {
		var online = state.online;
		var s = cfg.settings;
		if (online) {
			// Deliberately no agent name here: the person replying is named inside
			// the conversation, where it stays attached to their messages.
			els.status.textContent = cfg.strings.online;
			els.status.classList.remove('ld-offline');
		} else {
			els.status.textContent = cfg.strings.offline;
			els.status.classList.add('ld-offline');
		}
		if (!state.identified) {
			els.status.textContent = (online ? cfg.strings.online : cfg.strings.offline);
			els.status.classList.toggle('ld-offline', !online);
		}
	}

	function setBadge(count) {
		state.unread = count;
		if (!els.badge) { return; }
		if (count > 0 && !state.open) {
			els.badge.textContent = count > 9 ? '9+' : String(count);
			els.badge.hidden = false;
		} else {
			els.badge.hidden = true;
		}
	}

	function setConnectionStatus(text) {
		if (!els.connStatus) { return; }
		if (!text) { els.connStatus.hidden = true; els.connStatus.textContent = ''; return; }
		els.connStatus.hidden = false;
		els.connStatus.textContent = text;
	}

	/* ------------------------------------------------------------------ */
	/* Polling                                                            */
	/* ------------------------------------------------------------------ */

	function nextInterval() {
		if (document.hidden) { return cfg.settings.pollIdle * 1000; }
		// Minimized: the visitor is still on the page and wants to know when
		// support replies, so keep a responsive (but light) cadence.
		if (!state.open) { return state.minimized ? 5000 : cfg.settings.pollIdle * 1000; }
		// Right after the visitor sends something, keep the cadence tight so the
		// agent's reply appears promptly.
		if (state.sending || state.justSent) { return 2000; }
		if (state.conversation && state.conversation.status === 'waiting') { return cfg.settings.pollWaiting * 1000; }
		return cfg.settings.pollActive * 1000;
	}

	function schedulePoll(immediate) {
		if (state.pollTimer) { clearTimeout(state.pollTimer); }
		var delay = immediate ? 150 : nextInterval();
		if (state.failures > 0) { delay = Math.min(30000, delay * Math.pow(2, state.failures)); }
		state.pollTimer = setTimeout(poll, delay);
	}

	function poll() {
		if (!state.token || !state.identified) { schedulePoll(false); return; }
		if (state.pollInFlight) { return; }
		if (document.hidden && !state.open) { schedulePoll(false); return; }

		state.pollInFlight = true;
		api('visitor/poll', {
			json: {
				after_id: state.lastId,
				mark_read: state.open && !document.hidden,
				page: location.href
			}
		}).then(function (data) {
			state.failures = 0;
			setConnectionStatus('');
			if (typeof data.online === 'boolean' && data.online !== state.online) {
				state.online = data.online;
				updateStatus();
			}
			if (data.conversation) {
				state.conversation = Object.assign({}, state.conversation, data.conversation);
				updateStatus();
			}
			var incoming = data.messages || [];
			var newForVisitor = 0;
			incoming.forEach(function (msg) {
				if (msg.sender_type !== 'visitor') { newForVisitor++; }
				// Replace optimistic node with the server version when present.
				if (msg.client_uuid && !state.rendered[msg.id]) {
					var optimistic = els.messages.querySelector('[data-uuid="' + msg.client_uuid + '"]');
					if (optimistic && optimistic.parentNode) { optimistic.parentNode.removeChild(optimistic); }
				}
				renderMessage(msg);
			});
			if (incoming.length) {
				scrollToBottom(false);
				if (newForVisitor > 0) {
					var watchingThread = state.open && !state.minimized && !document.hidden;
					if (watchingThread) {
						// Reading the thread right now: chime only.
						playTone('agent');
					} else {
						// Panel minimized/closed or another tab: chime, badge,
						// and blink the tab title so it is impossible to miss.
						state.hasUnreadAgent = true;
						playTone('agent');
						setBadge(state.unread + newForVisitor);
						flashTitle(state.unread + newForVisitor);
					}
				}
			}
			// Typing indicator: animated bubble inside the thread.
			setAgentTyping(!!data.agent_typing && cfg.settings.typing && state.open && state.identified);
			syncEndedState();
			state.pollInFlight = false;
			schedulePoll(false);
		}).catch(function (err) {
			state.pollInFlight = false;
			if (err.status === 401 || err.status === 404) {
				handleExpiredSession();
				return;
			}
			state.failures++;
			setConnectionStatus(cfg.strings.reconnecting);
			schedulePoll(false);
		});
	}

	/**
	 * Show/hide the animated "Support is typing" bubble at the end of the thread.
	 *
	 * @param {boolean} active Whether an agent is typing.
	 */
	function setAgentTyping(active) {
		var existing = document.getElementById('ld-typing-bubble');
		if (!active) {
			if (existing) { existing.parentNode.removeChild(existing); }
			return;
		}
		if (existing) { return; }
		var bubble = document.createElement('div');
		bubble.id = 'ld-typing-bubble';
		bubble.className = 'ld-typing-bubble';
		bubble.setAttribute('aria-label', cfg.strings.agentTyping);
		bubble.innerHTML = '<i></i><i></i><i></i>';
		els.messages.appendChild(bubble);
		scrollToBottom(true);
	}

	function handleExpiredSession() {
		storeRemove(STORAGE_KEY);
		state.token = null;
		state.identified = false;
		state.conversation = null;
		showIdentify();
		els.error = els.error || null;
		setConnectionStatus('');
	}

	/* ------------------------------------------------------------------ */
	/* API flows                                                          */
	/* ------------------------------------------------------------------ */

	function startSession(payload) {
		var data = Object.assign({ page: location.href }, payload);
		els.start.disabled = true;
		return api('visitor/session', { json: data }).then(function (res) {
			state.token = res.token;
			state.online = true;
			storeSet(STORAGE_KEY, res.token);
			state.conversation = res.conversation || null;
			state.messages = {};
			showConversation();
			schedulePoll(true);
			return res;
		}).finally(function () { els.start.disabled = false; });
	}

	function resumeSession() {
		return api('visitor/session', { json: { token: state.token, page: location.href } })
			.then(function (res) {
				state.conversation = res.conversation || null;
				showConversation();
				schedulePoll(true);
			})
			.catch(function (err) {
				if (err.status === 401 || err.status === 404) {
					handleExpiredSession();
				} else {
					// Network problem: offer identify form; token stays for retry.
					showIdentify();
					els.identify.querySelector('.ld-error').textContent = cfg.strings.reconnecting;
				}
			});
	}

	function sendMessage() {
		var text = els.input.value.trim();
		if ((!text && !state.pendingFile) || state.sending || !state.token) { return; }

		state.sending = true;
		els.send.disabled = true;
		var myUuid = uuid();

		// Optimistic bubble.
		renderMessage({
			id: 'tmp-' + myUuid,
			sender_type: 'visitor',
			sender_name: cfg.strings.you,
			body: text,
			created_ts: Math.floor(Date.now() / 1000),
			is_read: false,
			client_uuid: myUuid,
			attachments: state.pendingFile ? [{
				name: state.pendingFile.name,
				is_image: state.pendingFile.type.indexOf('image/') === 0,
				url: URL.createObjectURL(state.pendingFile),
				id: 0
			}] : []
		});
		var optimistic = els.messages.querySelector('[data-uuid="' + myUuid + '"]');
		scrollToBottom(true);

		els.input.value = '';
		autoGrow();

		var done = function (sentOk) {
			state.sending = false;
			els.send.disabled = false;
			clearPendingFile();
			if (sentOk) {
				playTone('sent');
				// Follow up quickly: the agent may already be online and typing.
				state.justSent = true;
				setTimeout(function () { state.justSent = false; }, 15000);
			}
			schedulePoll(true);
		};

		if (state.pendingFile) {
			var fd = new FormData();
			fd.append('file', state.pendingFile);
			fd.append('message', text);
			fd.append('uuid', myUuid);
			fd.append('hp', '');
			api('visitor/upload', { body: fd }).then(function (res) {
				replaceOptimistic(myUuid, res.message, optimistic);
				done();
			}).catch(function (err) {
				markFailed(optimistic);
				els.input.value = text;
				autoGrow();
				done();
				if (err.message) { flashError(err.message); }
			});
		} else {
			api('visitor/messages', { json: { message: text, uuid: myUuid, hp: '', page: location.href } })
				.then(function (res) {
					replaceOptimistic(myUuid, res.message, optimistic);
					done(true);
				}).catch(function (err) {
					markFailed(optimistic);
					els.input.value = text;
					autoGrow();
					done(false);
					if (err.message) { flashError(err.message); }
				});
		}
	}

	function replaceOptimistic(myUuid, message, optimisticNode) {
		if (!message) { return; }
		if (optimisticNode && optimisticNode.parentNode) { optimisticNode.parentNode.removeChild(optimisticNode); }
		delete state.rendered['tmp-' + myUuid];
		renderMessage(message);
		scrollToBottom(true);
	}

	function markFailed(node) {
		if (!node) { return; }
		node.classList.add('ld-failed');
		var meta = node.querySelector('.ld-msg-meta');
		if (meta) {
			var flag = document.createElement('span');
			flag.textContent = '⚠';
			flag.style.color = '#ef4444';
			meta.appendChild(flag);
		}
	}

	function flashError(text) {
		setConnectionStatus(text);
		setTimeout(function () { setConnectionStatus(''); }, 5000);
	}

	/* ------------------------------------------------------------------ */
	/* Typing                                                             */
	/* ------------------------------------------------------------------ */

	function onTyping() {
		if (!cfg.settings.typing || !state.token || !state.identified) { return; }
		var now = Date.now();
		if (now - state.lastTypingSent < 4000) { return; }
		state.lastTypingSent = now;
		api('visitor/typing', { json: {} }).catch(function () {});
	}

	/* ------------------------------------------------------------------ */
	/* Attachments                                                        */
	/* ------------------------------------------------------------------ */

	function clearPendingFile() {
		state.pendingFile = null;
		els.file.value = '';
		els.attachmentsPreview.innerHTML = '';
		els.attachmentsPreview.hidden = true;
	}

	function setPendingFile(file) {
		if (!file) { return; }
		var ext = (file.name.split('.').pop() || '').toLowerCase();
		var allowed = String(cfg.settings.attachmentTypes || '').split(',').map(function (s) { return s.trim(); });
		var maxBytes = (cfg.settings.maxUploadMb || 10) * 1024 * 1024;

		if (allowed.indexOf(ext) === -1) {
			flashError('This file type is not allowed (' + allowed.join(', ') + ').');
			return;
		}
		if (file.size > maxBytes) {
			flashError('File is too large. Max ' + cfg.settings.maxUploadMb + ' MB.');
			return;
		}

		state.pendingFile = file;
		els.attachmentsPreview.innerHTML = '';
		var item = document.createElement('div');
		item.className = 'ld-preview-item';
		if (file.type.indexOf('image/') === 0) {
			var img = document.createElement('img');
			img.src = URL.createObjectURL(file);
			item.appendChild(img);
		}
		var label = document.createElement('span');
		label.textContent = file.name + ' (' + Math.ceil(file.size / 1024) + ' KB)';
		item.appendChild(label);
		var remove = document.createElement('button');
		remove.type = 'button';
		remove.textContent = '×';
		remove.setAttribute('aria-label', 'Remove file');
		remove.addEventListener('click', clearPendingFile);
		item.appendChild(remove);
		els.attachmentsPreview.appendChild(item);
		els.attachmentsPreview.hidden = false;
		els.input.focus();
	}

	/* ------------------------------------------------------------------ */
	/* Emoji                                                              */
	/* ------------------------------------------------------------------ */

	var EMOJIS = ['😀', '😄', '😊', '🙂', '😉', '😍', '🤩', '😘', '😎', '🤔', '😐', '😢', '😭', '😡', '🙏', '👋', '👍', '👎', '👌', '🙌', '💪', '🤝', '❤️', '🔥', '⭐', '✨', '🎉', '✅', '❌', '❓', '❗', '💡', '📎', '📷', '🛒', '💳', '📦', '🚚', '⏰'];

	function buildEmojiPanel() {
		EMOJIS.forEach(function (emoji) {
			var b = document.createElement('button');
			b.type = 'button';
			b.textContent = emoji;
			b.addEventListener('click', function () {
				els.input.value += emoji;
				els.emojiPanel.hidden = true;
				els.input.focus();
			});
			els.emojiPanel.appendChild(b);
		});
	}

	/* ------------------------------------------------------------------ */
	/* Composer helpers                                                   */
	/* ------------------------------------------------------------------ */

	function autoGrow() {
		var el = els.input;
		if (!el) { return; }
		el.style.height = 'auto';
		el.style.height = Math.min(110, el.scrollHeight) + 'px';
	}

	function loadEarlier() {
		if (!state.oldestId) { return; }
		api('visitor/history', { json: { before_id: state.oldestId } }).then(function (res) {
			var list = res.messages || [];
			if (!list.length) { return; }
			var firstTsBefore = state.oldestId;
			list.forEach(function (m) {
				if (!state.rendered[m.id]) {
					// Prepend (before the first message).
					var node = document.createElement('div');
					var tmp = state.rendered; // renderMessage appends; so render then move.
					renderMessage(m);
					var rendered = state.rendered[m.id];
					if (rendered && els.messages.firstChild !== rendered) {
						els.messages.insertBefore(rendered, els.messages.firstChild);
					}
					state.rendered[m.id] = rendered;
				}
			});
			state.hasMore = !!res.has_more;
			updateLoadEarlier();
		});
	}

	function updateLoadEarlier() {
		var btn = $('#ld-load-earlier');
		if (state.hasMore && !btn) {
			btn = document.createElement('button');
			btn.type = 'button';
			btn.id = 'ld-load-earlier';
			btn.className = 'ld-load-earlier';
			btn.textContent = cfg.strings.loadEarlier;
			btn.addEventListener('click', loadEarlier);
			els.messages.insertBefore(btn, els.messages.firstChild);
		} else if (!state.hasMore && btn) {
			btn.parentNode.removeChild(btn);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Open / close                                                       */
	/* ------------------------------------------------------------------ */

	function openWidget() {
		state.open = true;
		state.minimized = false;
		els.widget.classList.add('ld-open');
		els.widget.classList.remove('ld-minimized');
		if (els.minimize) {
			els.minimize.setAttribute('title', cfg.strings.minimize);
			els.minimize.setAttribute('aria-label', cfg.strings.minimize);
		}
		els.panel.hidden = false;
		setBadge(0);
		restoreTitle();
		unlockAudio();
		if (state.token) {
			if (state.identified) {
				schedulePoll(true);
			} else {
				resumeSession();
			}
		} else {
			els.name.focus({ preventScroll: true });
		}
		scrollToBottom(true);
	}

	/**
	 * Minimize: collapse the panel to the launcher but keep the session alive
	 * and polling (at the idle cadence) so nothing is missed.
	 */
	function minimizeWidget() {
		state.open = false;
		state.minimized = true;
		state.lastDay = state.lastDay; // keep thread state intact
		els.widget.classList.remove('ld-open');
		els.widget.classList.add('ld-minimized');
		els.panel.hidden = true;
		state.failures = 0;
		setConnectionStatus('');
		schedulePoll(false);
	}

	/**
	 * Close (✕) ends the chat session outright.
	 *
	 * Unlike minimize, this is destructive from the visitor's point of view: the
	 * conversation is closed for the team, the session token is discarded and
	 * the widget returns to the welcome form. Opening the chat again therefore
	 * starts a brand-new conversation instead of showing the previous one.
	 */
	function closeWidget() {
		if (!state.token) {
			// Nothing to end yet — just hide the panel.
			state.open = false;
			state.minimized = true;
			els.widget.classList.remove('ld-open');
			els.panel.hidden = true;
			schedulePoll(false);
			return;
		}
		if (!window.confirm(cfg.strings.confirmClose)) {
			return;
		}
		endSession({ notifyServer: true });
	}

	/**
	 * Tear down the current chat session locally and on the server.
	 *
	 * @param {Object} opts notifyServer Archive the conversation for the team.
	 */
	function endSession(opts) {
		opts = opts || {};
		var hadToken = !!state.token;
		var done = function () {
			storeRemove(STORAGE_KEY);
			state.token = null;
			state.identified = false;
			state.conversation = null;
			state.messages = {};
			state.rendered = {};
			state.lastId = 0;
			state.oldestId = 0;
			state.lastDay = '';
			state.unread = 0;
			state.open = false;
			state.minimized = true;
			state.justSent = false;
			if (els.messages) { els.messages.innerHTML = ''; }
			els.widget.classList.remove('ld-open');
			els.panel.hidden = true;
			setBadge(0);
			restoreTitle();
			setConnectionStatus('');
			showIdentify();
		};

		if (opts.notifyServer && hadToken) {
			api('visitor/end', { json: {} })
				.catch(function () { /* the local session ends either way */ })
				.then(done);
		} else {
			done();
		}
	}

	/** Show the "ended by team" banner when the conversation is archived. */
	function syncEndedState() {
		if (!els.endedNote) { return; }
		var archived = state.conversation && state.conversation.status === 'archived';
		els.endedNote.hidden = !archived;
	}

	/**
	 * Visitor-initiated "End chat" link.
	 *
	 * Ends the session exactly like the ✕ button: the conversation is archived
	 * for the team and the visitor returns to the welcome form.
	 */
	function endChat() {
		if (!state.token || state.sending) { return; }
		if (!window.confirm(cfg.strings.confirmEnd)) { return; }
		els.endChatBtn.disabled = true;
		endSession({ notifyServer: true });
		setTimeout(function () {
			if (els.endChatBtn) { els.endChatBtn.disabled = false; }
		}, 600);
	}

	/** Prefix the tab title with the unread count so replies are noticeable. */
	function flashTitle(count) {
		if (!count || count < 1) { return; }
		document.title = '(' + (count > 99 ? '99+' : count) + ') ' + cfg.strings.newMessage;
		state.titleFlashed = true;
	}

	function restoreTitle() {
		if (state.titleFlashed) {
			document.title = state.documentTitle;
			state.titleFlashed = false;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Init                                                               */
	/* ------------------------------------------------------------------ */

	function setupCaptcha(onReady) {
		var provider = cfg.settings.captcha;
		var key = cfg.settings.captchaKey;
		if (!provider || provider === 'none' || !key) { onReady(); return; }
		var script = document.createElement('script');
		if (provider === 'turnstile') {
			script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=ldCaptchaReady&render=explicit';
		} else {
			script.src = 'https://www.google.com/recaptcha/api.js?render=explicit&onload=ldCaptchaReady';
		}
		window.ldCaptchaReady = function () {
			var el = document.createElement('div');
			els.captcha.appendChild(el);
			try {
				if (provider === 'turnstile') {
					window.turnstile.render(el, { sitekey: key });
				} else if (provider === 'recaptcha_v3') {
					window.grecaptcha.ready(function () {
						window.grecaptcha.execute(key, { action: 'livedesk' }).then(function (token) {
							el.setAttribute('data-token', token);
						});
					});
				} else {
					window.grecaptcha.render(el, { sitekey: key });
				}
			} catch (e) { /* ignore */ }
			onReady();
		};
		script.async = true;
		document.head.appendChild(script);
	}

	function getCaptchaToken() {
		var provider = cfg.settings.captcha;
		if (!provider || provider === 'none') { return ''; }
		if (provider === 'turnstile' && window.turnstile) {
			try { return window.turnstile.getResponse() || ''; } catch (e) { return ''; }
		}
		if (provider === 'recaptcha_v3' && window.grecaptcha) {
			var el = els.captcha.querySelector('[data-token]');
			return el ? el.getAttribute('data-token') : '';
		}
		if (window.grecaptcha) {
			try { return window.grecaptcha.getResponse() || ''; } catch (e) { return ''; }
		}
		return '';
	}

	function bind() {
		els.launcher.addEventListener('click', function () {
			if (state.open) { closeWidget(); } else { openWidget(); }
			// Unlock audio on user gesture.
			try {
				var Ctx = window.AudioContext || window.webkitAudioContext;
				if (Ctx && !audioCtx) { audioCtx = new Ctx(); }
				if (audioCtx && audioCtx.state === 'suspended') { audioCtx.resume(); }
			} catch (e) { /* ignore */ }
		});

		document.querySelectorAll('[data-livedesk-open]').forEach(function (btn) {
			btn.addEventListener('click', openWidget);
		});

		els.close.addEventListener('click', closeWidget);
		if (els.minimize) { els.minimize.addEventListener('click', minimizeWidget); }
		if (els.endChatBtn) { els.endChatBtn.addEventListener('click', endChat); }

		// Any interaction unlocks audio so later incoming messages can chime.
		['pointerdown', 'keydown'].forEach(function (evt) {
			els.panel.addEventListener(evt, unlockAudio, { once: true });
		});

		els.sound.addEventListener('click', function () {
			if (!state.audioUnlocked) { unlockAudio(); }
			state.sound = !state.sound;
			try { localStorage.setItem('livedesk_sound', state.sound ? '1' : '0'); } catch (e) { /* ignore */ }
			updateSoundButton();
			if (state.sound) { playTone('sent'); }
		});

		els.identifyForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var name = els.name.value.trim();
			var email = els.email.value.trim();
			var errEl = els.identify.querySelector('.ld-error') || (function () {
				var d = document.createElement('div');
				d.className = 'ld-error';
				els.identifyForm.appendChild(d);
				return d;
			})();
			errEl.textContent = '';
			if (name.length < 2 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
				errEl.textContent = cfg.strings.required;
				return;
			}
			var payload = { name: name, email: email, hp: els.hp.value, captcha: getCaptchaToken() };
			var offlineMsgEl = $('#ld-offline-message');
			var offlineText = offlineMsgEl ? offlineMsgEl.value.trim() : '';
			startSession(payload).then(function () {
				if (offlineText) {
					els.input.value = offlineText;
					if (offlineMsgEl) { offlineMsgEl.value = ''; }
					sendMessage();
				}
			}).catch(function (err) {
				errEl.textContent = err.message || cfg.strings.error;
			});
		});

		els.send.addEventListener('click', sendMessage);
		els.input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
		});
		els.input.addEventListener('input', function () {
			autoGrow();
			onTyping();
		});

		els.attach.addEventListener('click', function () { els.file.click(); });
		els.file.addEventListener('change', function () {
			if (els.file.files && els.file.files[0]) { setPendingFile(els.file.files[0]); }
		});

		els.emoji.addEventListener('click', function (e) {
			e.stopPropagation();
			els.emojiPanel.hidden = !els.emojiPanel.hidden;
		});
		document.addEventListener('click', function (e) {
			if (!els.emojiPanel.hidden) {
				// The click target may be an inner <svg>, so compare the closest button.
				if (!els.emojiPanel.contains(e.target) && !(e.target.closest && e.target.closest('#ld-emoji'))) {
					els.emojiPanel.hidden = true;
				}
			}
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !els.emojiPanel.hidden) {
				els.emojiPanel.hidden = true;
			}
		});

		document.addEventListener('visibilitychange', function () {
			schedulePoll(!document.hidden);
		});

		els.messages.addEventListener('scroll', function () {
			// Mark visible incoming messages as read (server-side counter reset).
			if (state.open && !document.hidden) { schedulePoll(false); }
		});
	}

	function applySettings() {
		var s = cfg.settings;
		els.title.textContent = s.title || 'Live Chat';
		els.welcomeText.textContent = s.welcome || '';
		els.consent.textContent = s.consentText || cfg.strings.privacy;
		els.logo.innerHTML = s.logo ? '' : '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>';
		if (s.logo) {
			els.logo.style.backgroundImage = 'url(' + s.logo + ')';
			els.logo.style.backgroundSize = 'cover';
			els.avatar.style.backgroundImage = 'url(' + s.logo + ')';
		} else {
			els.avatar.textContent = (s.title || 'LD').substring(0, 2).toUpperCase();
		}
		if (s.position === 'left') { els.widget.classList.add('ld-left'); }
		state.sound = !!s.soundDefault;
		updateSoundButton();

		// Offline contact form: add message textarea to identify form.
		if (!state.online || cfg.settings.offlineMode === 'contact_form') {
			// We only know the state after bootstrap below; handled there.
		}
	}

	function maybeAddOfflineForm(online) {
		if (online) { return; }
		if (cfg.settings.offlineMode !== 'contact_form') { return; }
		if ($('#ld-offline-message')) { return; }
		var wrap = document.createElement('div');
		wrap.innerHTML =
			'<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 12px;margin:14px 0 4px;font-size:12.5px;color:#92400e;">' +
			'<strong style="display:block;margin-bottom:2px;">' + esc(cfg.settings.offlineHeading || '') + '</strong>' +
			esc(cfg.settings.offlineMessage || '') + '</div>' +
			'<label for="ld-offline-message" style="display:block;font-size:12.5px;font-weight:600;color:#6b7280;margin:10px 0 5px;">Message</label>' +
			'<textarea id="ld-offline-message" rows="4" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:10px;font-size:14px;font-family:inherit;"></textarea>';
		els.identifyForm.insertBefore(wrap, els.start);
	}

	function init() {
		applySettings();
		bind();
		buildEmojiPanel();
		setupCaptcha(function () {});

		try {
			state.token = storeGet(STORAGE_KEY);
			var savedSound = localStorage.getItem('livedesk_sound');
			if (savedSound !== null) { state.sound = savedSound === '1'; }
			updateSoundButton();
		} catch (e) { state.token = null; }

		// Browsers require a user gesture before audio can play. Unlock on the
		// first interaction anywhere on the page, not just inside the widget.
		['pointerdown', 'keydown', 'touchstart'].forEach(function (evt) {
			document.addEventListener(evt, unlockAudio, { once: true, passive: true });
		});
		if (!state.token) {
			showIdentify();
			// Bootstrap online state only (no session).
			api('visitor/bootstrap', { json: {} }).then(function (res) {
				state.online = !!res.online;
				updateStatus();
				maybeAddOfflineForm(state.online);
			}).catch(function () {});
			return;
		}
		resumeSession();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { cacheEls(); init(); });
	} else {
		cacheEls();
		init();
	}
})();
