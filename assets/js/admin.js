/**
 * LiveDesk — admin dashboard scripts.
 *
 * Inbox live updates (polling), composer, notes, canned responses, status and
 * assignment controls, agent management, sound + desktop notifications.
 */
(function () {
	'use strict';

	var cfg = window.livedeskAdmin;
	if (!cfg) { return; }

	var state = {
		conversationId: cfg.conversationId || 0,
		lastId: 0,
		pollTimer: null,
		inFlight: false,
		sound: !!cfg.sound,
		seenMessageIds: {},
		unreadBefore: 0,
		lastTypingSent: 0,
		typingTimers: {}
	};

	function $(sel, ctx) { return (ctx || document).querySelector(sel); }
	function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
	function esc(s) {
		return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	/* ---------------- AJAX helper ---------------- */

	function ajax(action, data) {
		var body = new URLSearchParams();
		body.append('action', 'livedesk_' + action);
		body.append('nonce', cfg.nonce);
		Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (res) { return res.json(); })
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || cfg.strings.error);
				}
				return json.data;
			});
	}

	function restUrl(path, params) {
		var url = cfg.restUrl + path;
		if (params) {
			var qs = new URLSearchParams(params).toString();
			if (qs) { url += (url.indexOf('?') === -1 ? '?' : '&') + qs; }
		}
		return url;
	}

	function api(path, opts) {
		opts = opts || {};
		var headers = { 'X-WP-Nonce': cfg.restNonce };
		if (opts.json) { headers['Content-Type'] = 'application/json'; }
		return fetch(restUrl(path, opts.params), {
			method: opts.method || 'POST',
			credentials: 'same-origin',
			headers: headers,
			body: opts.json ? JSON.stringify(opts.json) : undefined
		}).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) { throw new Error(data.message || cfg.strings.error); }
				return data;
			});
		});
	}

	/* ---------------- Sound + desktop notifications ---------------- */

	var audioCtx = null;
	function beep() {
		if (!state.sound || !cfg.sound) { return; }
		try {
			var Ctx = window.AudioContext || window.webkitAudioContext;
			if (!Ctx) { return; }
			audioCtx = audioCtx || new Ctx();
			if (audioCtx.state === 'suspended') { audioCtx.resume(); }
			var osc = audioCtx.createOscillator();
			var gain = audioCtx.createGain();
			osc.type = 'sine';
			osc.frequency.setValueAtTime(932, audioCtx.currentTime);
			osc.frequency.exponentialRampToValueAtTime(1244, audioCtx.currentTime + 0.1);
			gain.gain.setValueAtTime(0.0001, audioCtx.currentTime);
			gain.gain.exponentialRampToValueAtTime(0.2, audioCtx.currentTime + 0.02);
			gain.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.3);
			osc.connect(gain); gain.connect(audioCtx.destination);
			osc.start(); osc.stop(audioCtx.currentTime + 0.32);
		} catch (e) { /* ignore */ }
	}

	function desktopNotify(title, body) {
		if (!cfg.desktopNotifications) { return; }
		if (!('Notification' in window)) { return; }
		if (Notification.permission === 'granted') {
			try { new Notification(title, { body: body, tag: 'livedesk' }); } catch (e) { /* ignore */ }
		} else if (Notification.permission === 'default') {
			Notification.requestPermission();
		}
	}

	function updateTitleCount(count) {
		var base = document.title.replace(/^\(\d+\)\s*/, '');
		document.title = count > 0 ? '(' + count + ') ' + base : base;
	}

	/* ---------------- Inbox: availability toggle ---------------- */

	$$('.ld-toggle-group').forEach(function (group) {
		group.addEventListener('click', function (e) {
			var btn = e.target.closest('.ld-toggle-btn');
			if (!btn) { return; }
			var value = btn.getAttribute('data-value');
			ajax('agent_availability', { availability: value }).then(function () {
				$$('.ld-toggle-btn', group).forEach(function (b) {
					b.classList.toggle('is-active', b === btn);
				});
			}).catch(function (err) { alert(err.message); });
		});
	});

	/* ---------------- Inbox: conversation UI ---------------- */

	var thread = $('#ld-thread');
	if (thread) {
		initConversation();
	}

	function initConversation() {
		state.lastId = parseInt(thread.getAttribute('data-last-id') || '0', 10);
		$$('.ld-thread-msg', thread).forEach(function (n) {
			state.seenMessageIds[n.getAttribute('data-id')] = true;
		});
		scrollThreadBottom();

		var input = $('#ld-reply-input');
		var sendBtn = $('#ld-send-reply');
		var statusEl = $('#ld-send-status');

		function setStatus(text, isError) {
			if (!statusEl) { return; }
			statusEl.textContent = text || '';
			statusEl.style.color = isError ? '#dc2626' : '#16a34a';
			if (text) {
				clearTimeout(statusEl._t);
				statusEl._t = setTimeout(function () { statusEl.textContent = ''; }, 4000);
			}
		}

		function send() {
			var text = input.value.trim();
			if (!text) { return; }
			var myUuid = uuidv4();
			sendBtn.disabled = true;
			setStatus(cfg.strings.sending);

			// Optimistic bubble: the agent sees their reply instantly; it is
			// replaced by the stored row when the server responds.
			var pending = appendPending(text);
			input.value = '';
			input.style.height = 'auto';

			api('agent/reply', { json: { conversation_id: state.conversationId, message: text, uuid: myUuid } })
				.then(function (res) {
					if (pending && pending.parentNode) { pending.parentNode.removeChild(pending); }
					appendMessage(res.message);
					setStatus(cfg.strings.sent);
					// Agent reply moves conversation to waiting.
					var sel = $('#ld-status-select');
					if (sel) { sel.value = 'waiting'; }
					// Pull the visitor's response sooner.
					schedulePoll(1200);
				})
				.catch(function (err) {
					if (pending && pending.parentNode) { pending.parentNode.removeChild(pending); }
					input.value = text;
					setStatus(err.message, true);
				})
				.finally(function () { sendBtn.disabled = false; input.focus(); });
		}

		/**
		 * Append a translucent "sending…" bubble while the request is in flight.
		 *
		 * @param {string} text Reply body.
		 * @return {HTMLElement|null}
		 */
		function appendPending(text) {
			var threadEl = $('#ld-thread');
			if (!threadEl) { return null; }
			var node = document.createElement('div');
			node.className = 'ld-thread-msg is-out is-pending';
			node.innerHTML =
				'<div class="ld-thread-bubble"><div class="ld-thread-text">' +
				esc(text).replace(/\n/g, '<br>') +
				'</div></div><div class="ld-thread-meta">' + esc(cfg.strings.sending) + '</div>';
			threadEl.appendChild(node);
			threadEl.scrollTop = threadEl.scrollHeight;
			return node;
		}

		if (sendBtn) { sendBtn.addEventListener('click', send); }
		if (input) {
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
			});
			input.addEventListener('input', function () {
				input.style.height = 'auto';
				input.style.height = Math.min(160, input.scrollHeight) + 'px';
				var now = Date.now();
				if (now - state.lastTypingSent < 4000) { return; }
				state.lastTypingSent = now;
				api('agent/typing', { json: { conversation_id: state.conversationId } }).catch(function () {});
			});
		}

		function appendMessage(msg) {
			if (!msg || state.seenMessageIds[msg.id]) { return; }
			state.seenMessageIds[msg.id] = true;
			var out = msg.sender_type !== 'visitor';
			var node = document.createElement('div');
			node.className = 'ld-thread-msg ' + (out ? 'is-out' : 'is-in');
			node.setAttribute('data-id', msg.id);
			var atts = '';
			(msg.attachments || []).forEach(function (att) {
				if (att.is_image) {
					atts += '<a href="' + esc(att.url) + '" target="_blank" rel="noopener"><img loading="lazy" src="' + esc(att.url) + '" alt=""></a>';
				} else {
					atts += '<a class="ld-file-chip" href="' + esc(att.url) + '" target="_blank" rel="noopener">📎 ' + esc(att.name) + '</a>';
				}
			});
			node.innerHTML =
				'<div class="ld-thread-bubble">' +
				(msg.body ? '<div class="ld-thread-text">' + esc(msg.body).replace(/\n/g, '<br>') + '</div>' : '') +
				(atts ? '<div class="ld-thread-atts">' + atts + '</div>' : '') +
				'</div>' +
				'<div class="ld-thread-meta">' + esc(msg.sender_name) + ' · ' + timeLabel(msg.created_ts) + (out ? '<span class="ld-tick-ok">✓</span>' : '') + '</div>';
			thread.appendChild(node);
			scrollThreadBottom();
			if (msg.id > state.lastId) { state.lastId = msg.id; }
		}

		function timeLabel(ts) {
			try {
				return new Date(ts * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
			} catch (e) { return ''; }
		}

		function scrollThreadBottom() {
			thread.scrollTop = thread.scrollHeight;
		}

		/* Status change */
		var statusSelect = $('#ld-status-select');
		if (statusSelect) {
			statusSelect.addEventListener('change', function () {
				ajax('conversation_status', { conversation_id: state.conversationId, status: statusSelect.value })
					.catch(function (err) { alert(err.message); });
			});
		}

		/* Archive button */
		var archiveBtn = $('#ld-archive-btn');
		if (archiveBtn) {
			archiveBtn.addEventListener('click', function () {
				ajax('conversation_status', { conversation_id: state.conversationId, status: 'archived' }).then(function () {
					if (statusSelect) { statusSelect.value = 'archived'; }
				}).catch(function (err) { alert(err.message); });
			});
		}

		/* Assignment */
		var assignSelect = $('#ld-assign-select');
		if (assignSelect) {
			assignSelect.addEventListener('change', function () {
				ajax('conversation_assign', { conversation_id: state.conversationId, agent_id: assignSelect.value })
					.catch(function (err) { alert(err.message); });
			});
		}

		/* Delete conversation */
		var deleteBtn = $('#ld-delete-conversation');
		if (deleteBtn) {
			deleteBtn.addEventListener('click', function () {
				var confirmText = window.prompt(cfg.strings.confirmDelete, '');
				if (confirmText !== 'DELETE') { return; }
				ajax('conversation_delete', { conversation_id: state.conversationId, confirm: 'DELETE' }).then(function (res) {
					window.location.href = res.redirect || window.location.href;
				}).catch(function (err) { alert(err.message); });
			});
		}

		/* Notes */
		var noteBtn = $('#ld-add-note');
		var noteInput = $('#ld-note-input');
		var notesWrap = $('#ld-notes');
		if (noteBtn && noteInput) {
			function addNote() {
				var text = noteInput.value.trim();
				if (!text) { return; }
				noteBtn.disabled = true;
				api('agent/note', { json: { conversation_id: state.conversationId, note: text } })
					.then(function (res) {
						var note = res.note;
						var el = document.createElement('div');
						el.className = 'ld-note';
						el.setAttribute('data-note-id', note.id);
						el.innerHTML = '<div class="ld-note-head"><strong>' + esc(note.author) + '</strong><span>just now</span></div><p>' + esc(note.content) + '</p>';
						notesWrap.appendChild(el);
						noteInput.value = '';
						if (cfg.capManage) { el.querySelector('.ld-note-head').insertAdjacentHTML('beforeend', '<button type="button" class="ld-note-del" title="Delete note">×</button>'); }
					})
					.catch(function (err) { alert(err.message); })
					.finally(function () { noteBtn.disabled = false; });
			}
			noteBtn.addEventListener('click', addNote);
			noteInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { addNote(); }
			});
		}
		if (notesWrap) {
			notesWrap.addEventListener('click', function (e) {
				var del = e.target.closest('.ld-note-del');
				if (!del) { return; }
				var note = del.closest('.ld-note');
				if (!note) { return; }
				ajax('note_delete', { conversation_id: state.conversationId, note_id: note.getAttribute('data-note-id') })
					.then(function () { note.parentNode.removeChild(note); })
					.catch(function (err) { alert(err.message); });
			});
		}

		/* Canned responses */
		var cannedBtn = $('#ld-canned-btn');
		var cannedMenu = $('#ld-canned-menu');
		if (cannedBtn && cannedMenu) {
			var responses = cfg.cannedResponses || [];
			if (!responses.length) {
				cannedBtn.style.display = 'none';
			} else {
				responses.forEach(function (resp) {
					var item = document.createElement('button');
					item.type = 'button';
					item.className = 'ld-canned-item';
					item.innerHTML = '<strong>' + esc(resp.title) + '</strong><span>' + esc(resp.content) + '</span>';
					item.addEventListener('click', function () {
						if (input) {
							input.value = input.value ? input.value + '\n' + resp.content : resp.content;
							input.focus();
						}
						cannedMenu.hidden = true;
					});
					cannedMenu.appendChild(item);
				});
				cannedBtn.addEventListener('click', function (e) {
					e.stopPropagation();
					cannedMenu.hidden = !cannedMenu.hidden;
				});
				document.addEventListener('click', function (e) {
					if (!cannedMenu.hidden && !cannedMenu.contains(e.target)) { cannedMenu.hidden = true; }
				});
			}
		}

		/* Sound toggle on composer */
		var soundToggle = $('#ld-sound-toggle');
		if (soundToggle) {
			function paintSound() {
				soundToggle.textContent = state.sound ? 'Sound: on' : 'Sound: off';
				soundToggle.style.opacity = state.sound ? '1' : '.55';
			}
			paintSound();
			soundToggle.addEventListener('click', function () {
				state.sound = !state.sound;
				paintSound();
			});
		}

		/* Mark conversation read on open */
		ajax('conversation_mark_read', { conversation_id: state.conversationId }).catch(function () {});

		/* Live poll for this conversation + counts */
		pollConversation();
	}

	function pollConversation() {
		if (state.inFlight) { return; }
		if (document.hidden) { schedulePoll(15000); return; }
		state.inFlight = true;
		api('agent/poll', { method: 'GET', params: { conversation: state.conversationId, after: state.lastId, _: Date.now() } })
			.then(function (data) {
				state.inFlight = false;
				if (data.messages && data.messages.length) {
					data.messages.forEach(function (msg) {
						if (!state.seenMessageIds[msg.id]) {
							appendFromPoll(msg);
							if (msg.sender_type === 'visitor') {
								beep();
								desktopNotify(cfg.strings.newMessage + ' ' + (msg.sender_name || ''), msg.body || '');
							}
						}
					});
				}
				setVisitorTyping(!!data.visitor_typing);
				if (data.counts) { updateTitleCount(data.counts.unread); }
				schedulePoll(cfg.pollInterval);
			})
			.catch(function () {
				state.inFlight = false;
				schedulePoll(Math.min(30000, cfg.pollInterval * 2));
			});
	}

	/**
	 * Animated "visitor is typing" bubble at the end of the thread.
	 *
	 * @param {boolean} active Whether the visitor is typing.
	 */
	function setVisitorTyping(active) {
		var existing = document.getElementById('ld-typing-bubble-admin');
		if (!active) {
			if (existing) { existing.parentNode.removeChild(existing); }
			return;
		}
		if (existing) { return; }
		var threadEl = $('#ld-thread');
		if (!threadEl) { return; }
		var bubble = document.createElement('div');
		bubble.id = 'ld-typing-bubble-admin';
		bubble.className = 'ld-thread-msg is-in ld-typing-msg';
		bubble.innerHTML = '<div class="ld-typing-bubble" aria-label="' + esc(cfg.strings.typing) + '"><i></i><i></i><i></i></div>';
		threadEl.appendChild(bubble);
		threadEl.scrollTop = threadEl.scrollHeight;
	}

	function appendFromPoll(msg) {
		var threadEl = $('#ld-thread');
		if (!threadEl) { return; }
		setVisitorTyping(false);
		state.seenMessageIds[msg.id] = true;
		if (msg.id > state.lastId) { state.lastId = msg.id; }

		// Automated messages arrive as soon as the visitor sends their first
		// message; render them as a notice so they are not mistaken for a reply.
		if (msg.sender_type === 'system') {
			var note = document.createElement('div');
			note.className = 'ld-thread-system';
			note.setAttribute('data-id', msg.id);
			note.innerHTML = '<span class="ld-thread-system-icon" aria-hidden="true">' +
				'<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a10 10 0 1 0 10 10"></path><path d="M12 6v6l4 2"></path></svg></span>' +
				'<span>' + esc(msg.body) + '</span><em>' + esc(cfg.strings.auto) + '</em>';
			threadEl.appendChild(note);
			threadEl.scrollTop = threadEl.scrollHeight;
			return;
		}

		var out = msg.sender_type !== 'visitor';
		var node = document.createElement('div');
		node.className = 'ld-thread-msg ' + (out ? 'is-out' : 'is-in');
		node.setAttribute('data-id', msg.id);
		node.innerHTML = '<div class="ld-thread-bubble"><div class="ld-thread-text">' + esc(msg.body).replace(/\n/g, '<br>') + '</div></div>' +
			'<div class="ld-thread-meta">' + esc(msg.sender_name) + '</div>';
		threadEl.appendChild(node);
		threadEl.scrollTop = threadEl.scrollHeight;
	}

	function schedulePoll(delay) {
		if (state.pollTimer) { clearTimeout(state.pollTimer); }
		// While the thread is open and visible, poll fast so visitor messages and
		// typing indicators feel instant; back off when the tab is hidden.
		var base = document.hidden ? 15000 : Math.min(2000, cfg.pollInterval);
		state.pollTimer = setTimeout(pollConversation, delay || base);
	}

	function uuidv4() {
		if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = Math.random() * 16 | 0;
			return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
		});
	}

	/* ---------------- Global inbox count poll (list page) ---------------- */

	if (!thread && cfg.page === 'livedesk-inbox') {
		setInterval(function () {
			if (document.hidden) { return; }
			api('agent/poll', { method: 'GET', params: { _: Date.now() } }).then(function (data) {
				if (data.counts) {
					updateTitleCount(data.counts.unread);
					if (data.counts.unread > state.unreadBefore && state.unreadBefore >= 0) {
						beep();
					}
					state.unreadBefore = data.counts.unread;
				}
			}).catch(function () {});
		}, Math.max(5000, cfg.pollInterval * 2));
	}

	/* ---------------- Agents page ---------------- */

	var addAgentBtn = $('#ld-agent-add');
	if (addAgentBtn) {
		addAgentBtn.addEventListener('click', function () {
			var sel = $('#ld-agent-candidate');
			if (!sel || !sel.value) { return; }
			addAgentBtn.disabled = true;
			ajax('agent_add', { user_id: sel.value }).then(function () { window.location.reload(); })
				.catch(function (err) { alert(err.message); addAgentBtn.disabled = false; });
		});
	}

	$$('.ld-agent-toggle').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var enabled = btn.getAttribute('data-enabled') === '1' ? 0 : 1;
			ajax('agent_toggle', { user_id: btn.getAttribute('data-user'), enabled: enabled })
				.then(function () { window.location.reload(); })
				.catch(function (err) { alert(err.message); });
		});
	});

	$$('.ld-agent-remove').forEach(function (btn) {
		btn.addEventListener('click', function () {
			if (!window.confirm(cfg.strings.confirmAgent)) { return; }
			ajax('agent_remove', { user_id: btn.getAttribute('data-user') })
				.then(function () { window.location.reload(); })
				.catch(function (err) { alert(err.message); });
		});
	});

	/* ---------------- Canned responses page ---------------- */

	var cannedSave = $('#ld-canned-save');
	if (cannedSave) {
		var idInput = $('#ld-canned-id');
		var titleInput = $('#ld-canned-title-input');
		var contentInput = $('#ld-canned-content-input');
		var cancelBtn = $('#ld-canned-cancel');
		var statusSpan = $('#ld-canned-status');

		function resetForm() {
			idInput.value = '0';
			titleInput.value = '';
			contentInput.value = '';
			cancelBtn.hidden = true;
		}

		cannedSave.addEventListener('click', function () {
			if (!titleInput.value.trim() || !contentInput.value.trim()) { return; }
			cannedSave.disabled = true;
			ajax('canned_save', { id: idInput.value, title: titleInput.value, content: contentInput.value })
				.then(function () {
					if (statusSpan) { statusSpan.textContent = cfg.strings.saved; }
					setTimeout(function () { window.location.reload(); }, 500);
				})
				.catch(function (err) { alert(err.message); cannedSave.disabled = false; });
		});

		$$('.ld-canned-edit').forEach(function (btn) {
			btn.addEventListener('click', function () {
				idInput.value = btn.getAttribute('data-id');
				titleInput.value = btn.getAttribute('data-title');
				contentInput.value = btn.getAttribute('data-content');
				cancelBtn.hidden = false;
				titleInput.focus();
			});
		});

		cancelBtn.addEventListener('click', resetForm);

		$$('.ld-canned-delete').forEach(function (btn) {
			btn.addEventListener('click', function () {
				if (!window.confirm('Delete this canned response?')) { return; }
				ajax('canned_delete', { id: btn.getAttribute('data-id') })
					.then(function () { window.location.reload(); })
					.catch(function (err) { alert(err.message); });
			});
		});
	}

	/* ---------------- Settings page ---------------- */

	var settingsTabs = $$('.ld-settings-tab');
	if (settingsTabs.length) {
		settingsTabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				settingsTabs.forEach(function (t) { t.classList.remove('is-active'); });
				$$('.ld-settings-panel').forEach(function (p) { p.classList.remove('is-active'); });
				tab.classList.add('is-active');
				var panel = $('#ld-panel-' + tab.getAttribute('data-target'));
				if (panel) { panel.classList.add('is-active'); }
				if (history.replaceState) {
					history.replaceState(null, '', '#' + tab.getAttribute('data-target'));
				}
			});
		});
		var hash = (window.location.hash || '').replace('#', '');
		if (hash) {
			var target = settingsTabs.filter(function (t) { return t.getAttribute('data-target') === hash; })[0];
			if (target) { target.click(); }
		}
	}

	var testEmail = $('#ld-test-email');
	if (testEmail) {
		testEmail.addEventListener('click', function () {
			testEmail.disabled = true;
			var statusEl = $('#ld-test-email-status');
			ajax('send_test_email', {}).then(function (res) {
				if (statusEl) { statusEl.textContent = 'Sent to ' + res.recipients.join(', '); }
			}).catch(function (err) {
				if (statusEl) { statusEl.textContent = err.message; statusEl.style.color = '#dc2626'; }
			}).finally(function () { testEmail.disabled = false; });
		});
	}
})();
