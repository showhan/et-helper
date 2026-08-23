(function () {
	'use strict';

	var config = window.acpConfig || {};
	var pages = Array.isArray(config.pages) ? config.pages.slice() : [];
	var contextActions = Array.isArray(config.contextActions) ? config.contextActions.slice() : [];
	var defaultPins = Array.isArray(config.defaultPins) ? config.defaultPins.slice() : [];
	var builtinCommands = Array.isArray(config.commands) ? config.commands.slice() : [];
	var ajaxUrl = config.ajaxUrl || '';
	var nonce = config.nonce || '';
	var searchNonce = config.searchNonce || '';
	var pinsNonce = config.pinsNonce || '';
	var clearCacheNonce = config.clearCacheNonce || '';
	var shortcutNonce = config.shortcutNonce || '';
	var accentColor = config.accentColor || '#326BFF';
	var DEFAULT_SHORTCUT = normalizeShortcut(config.defaultShortcut) || {
		mod: true,
		shift: true,
		alt: false,
		key: 'c'
	};
	var shortcut = normalizeShortcut(config.shortcut) || Object.assign({}, DEFAULT_SHORTCUT);
	var recordingShortcut = false;
	var RECENT_KEY = 'acp_recent_v1';
	var RECENT_MAX = 8;
	var EMPTY_STATE_MAX = 16;
	var pinnedList = Array.isArray(config.pinnedActions) ? config.pinnedActions.slice() : [];
	var pinsSaveTimer = null;
	var PLACEHOLDER_DEFAULT = 'Search commands…';
	var PLACEHOLDER_POSTS = 'Search posts & pages…';
	var PLACEHOLDER_PREFIX_COMMANDS = 'Filter commands…';
	var PLACEHOLDER_PREFIX_PAGES = 'Filter admin pages…';
	var PLACEHOLDER_PREFIX_POSTS = 'Search posts & pages…';
	var overlay = null;
	var input = null;
	var modeChip = null;
	var resultsEl = null;
	var emptyEl = null;
	var toastEl = null;
	var activeIndex = 0;
	var filtered = [];
	var busy = false;
	var toastTimer = null;
	var actionTimer = null;
	var pluginBusy = null;
	var commandBusy = null;
	var pendingPluginCommit = null;
	var mode = 'commands';
	var selectedPost = null;
	var postsPrefixRestore = '';
	var openResults = [];
	var openSearchTimer = null;
	var openSearchSeq = 0;
	var openSearching = false;

	function normalizeShortcut(raw) {
		if (!raw || typeof raw !== 'object') {
			return null;
		}
		var key = typeof raw.key === 'string' ? raw.key : '';
		if (!key) {
			return null;
		}
		if (1 === key.length) {
			// Allow letters, digits, and punctuation (e.g. \ / [ ]); reject whitespace.
			if (/^\s$/.test(key)) {
				return null;
			}
			if (/^[A-Za-z]$/.test(key)) {
				key = key.toLowerCase();
			}
		} else if (/^(Control|Meta|Alt|Shift|Dead)$/i.test(key)) {
			return null;
		}
		if ('escape' === key.toLowerCase() || 'esc' === key.toLowerCase()) {
			return null;
		}
		var mod = !!raw.mod;
		var alt = !!raw.alt;
		if (!mod && !alt) {
			return null;
		}
		return {
			mod: mod,
			shift: !!raw.shift,
			alt: alt,
			key: key
		};
	}

	function isMacPlatform() {
		return /Mac|iPhone|iPad|iPod/i.test(navigator.platform || '')
			|| (navigator.userAgentData && navigator.userAgentData.platform === 'macOS');
	}

	function normalizeEventKey(key) {
		if (!key) {
			return '';
		}
		if (1 === key.length && /^[A-Za-z]$/.test(key)) {
			return key.toLowerCase();
		}
		return key;
	}

	function isModifierOnlyKey(key) {
		return /^(Control|Meta|Alt|Shift|Dead)$/i.test(key || '');
	}

	function matchesShortcut(e, chord) {
		if (!e || !chord || !chord.key) {
			return false;
		}
		if (normalizeEventKey(e.key) !== normalizeEventKey(chord.key)) {
			return false;
		}
		var hasMod = !!(e.ctrlKey || e.metaKey);
		if (!!chord.mod !== hasMod) {
			return false;
		}
		if (!!chord.shift !== !!e.shiftKey) {
			return false;
		}
		if (!!chord.alt !== !!e.altKey) {
			return false;
		}
		return true;
	}

	function shortcutFromEvent(e) {
		return normalizeShortcut({
			mod: !!(e.ctrlKey || e.metaKey),
			shift: !!e.shiftKey,
			alt: !!e.altKey,
			key: 1 === String(e.key || '').length ? String(e.key).toLowerCase() : e.key
		});
	}

	function formatShortcutLabel(chord) {
		if (!chord || !chord.key) {
			return '';
		}
		var parts = [];
		if (chord.mod) {
			parts.push(isMacPlatform() ? '⌘' : 'Ctrl');
		}
		if (chord.alt) {
			parts.push(isMacPlatform() ? '⌥' : 'Alt');
		}
		if (chord.shift) {
			parts.push('Shift');
		}
		var keyLabel = chord.key;
		if (1 === keyLabel.length) {
			keyLabel = keyLabel.toUpperCase();
		}
		parts.push(keyLabel);
		return parts.join('+');
	}

	function isPluginAction(page) {
		return page && 'plugin' === page.type && page.plugin;
	}

	function isContextAction(page) {
		return page && 'context' === page.type;
	}

	function isCommandAction(page) {
		return page && 'command' === page.type && page.id;
	}

	function isPostResult(page) {
		return page && 'post' === page.type && page.postId;
	}

	function currentPostIntent() {
		if ('open' === mode) {
			return 'open';
		}
		if ('edit-divi' === mode) {
			return 'edit-divi';
		}
		if ('edit-wp' === mode) {
			return 'edit-wp';
		}
		return '';
	}

	function postIntentLabel(intent) {
		if ('open' === intent) {
			return 'Open';
		}
		if ('edit-divi' === intent) {
			return 'Edit with Divi';
		}
		if ('edit-wp' === intent) {
			return 'Edit in WordPress';
		}
		return '';
	}

	function resolvePostIntent(page) {
		if (!page) {
			return '';
		}
		if (page.intent) {
			return page.intent;
		}
		return currentPostIntent() || 'edit-wp';
	}

	function urlForPostIntent(page, intent) {
		if (!page) {
			return '';
		}
		if ('open' === intent) {
			return page.viewUrl || page.url || '';
		}
		if ('edit-divi' === intent) {
			return page.diviUrl || '';
		}
		return page.editUrl || page.url || '';
	}

	function normalizeUrl(url) {
		if (!url || typeof url !== 'string') {
			return '';
		}

		try {
			var anchor = document.createElement('a');
			anchor.href = url;

			var path = String(anchor.pathname || '');
			path = path.replace(/\/index\.php$/i, '');
			path = path.replace(/\/+$/, '');

			var query = String(anchor.search || '');
			if (query && '?' === query.charAt(0)) {
				query = query.slice(1);
			}
			if (query) {
				var pairs = query.split('&').filter(Boolean).sort();
				query = pairs.join('&');
			}

			var host = String(anchor.host || '').toLowerCase();
			var protocol = String(anchor.protocol || '').toLowerCase();
			var out = '';

			if (protocol && host) {
				out = protocol + '//' + host;
			}
			out += path.toLowerCase();
			if (query) {
				out += '?' + query.toLowerCase();
			}
			return out;
		} catch (err) {
			return String(url).toLowerCase().replace(/\/+$/, '');
		}
	}

	function pageKey(page) {
		if (!page) {
			return '';
		}
		if (isPluginAction(page)) {
			return 'plugin|' + page.plugin;
		}
		if (isCommandAction(page)) {
			return 'command|' + page.id;
		}
		if (isContextAction(page) && page.id) {
			return 'context|' + page.id;
		}
		if (isPostResult(page)) {
			return 'post|' + resolvePostIntent(page) + '|' + page.postId;
		}
		if (page.url) {
			var normalized = normalizeUrl(page.url);
			if (normalized) {
				return 'url|' + normalized;
			}
		}
		return 'title|' + String(page.title || '').toLowerCase();
	}

	function snapshotPage(page) {
		var snapshot = {
			key: pageKey(page),
			title: page.title,
			url: page.url || '',
			parent: page.parent || '',
			type: page.type || '',
			plugin: page.plugin || '',
			pluginAction: page.pluginAction || '',
			pluginName: page.pluginName || '',
			network: !!page.network,
			id: page.id || '',
			postId: page.postId || 0,
			viewUrl: page.viewUrl || '',
			editUrl: page.editUrl || '',
			diviUrl: page.diviUrl || '',
			intent: page.intent || ''
		};

		if (isPostResult(page)) {
			var intent = resolvePostIntent(page);
			var label = postIntentLabel(intent);
			var baseTitle = page.rawTitle || page.title || '';
			var prefixes = ['Open: ', 'Edit with Divi: ', 'Edit in WordPress: ', 'Open ', 'Edit with Divi ', 'Edit in WordPress '];

			for (var p = 0; p < prefixes.length; p++) {
				if (0 === baseTitle.indexOf(prefixes[p])) {
					baseTitle = baseTitle.slice(prefixes[p].length);
					break;
				}
			}

			snapshot.intent = intent;
			snapshot.rawTitle = baseTitle;
			snapshot.title = label ? (label + ': ' + baseTitle) : baseTitle;
			snapshot.url = urlForPostIntent(page, intent);
			snapshot.key = 'post|' + intent + '|' + page.postId;
		}

		return snapshot;
	}

	function loadRecent() {
		try {
			var raw = window.localStorage.getItem(RECENT_KEY);
			var parsed = raw ? JSON.parse(raw) : [];
			return Array.isArray(parsed) ? parsed : [];
		} catch (err) {
			return [];
		}
	}

	function saveRecent(list) {
		try {
			window.localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, RECENT_MAX)));
		} catch (err) {
			// Ignore quota / private mode failures.
		}
	}

	function rememberRecent(page) {
		if (!page || !page.title || isContextAction(page)) {
			return;
		}

		var key = pageKey(page);
		if (!key) {
			return;
		}

		var snapshot = snapshotPage(page);
		var recent = loadRecent().filter(function (item) {
			return item && item.key !== key;
		});
		recent.unshift(snapshot);
		saveRecent(recent);
	}

	function loadPinned() {
		return Array.isArray(pinnedList) ? pinnedList.slice() : [];
	}

	function persistPinned(list, options) {
		pinnedList = Array.isArray(list) ? list.slice() : [];
		config.pinnedActions = pinnedList;

		if (options && false === options.sync) {
			return;
		}

		schedulePinsSave();
	}

	function schedulePinsSave() {
		if (pinsSaveTimer) {
			clearTimeout(pinsSaveTimer);
		}

		pinsSaveTimer = setTimeout(function () {
			pinsSaveTimer = null;
			savePinnedToServer(pinnedList);
		}, 200);
	}

	function savePinnedToServer(list) {
		if (!ajaxUrl || !pinsNonce) {
			return;
		}

		var body = new FormData();
		body.append('action', 'acp_save_pins');
		body.append('nonce', pinsNonce);
		body.append('pins', JSON.stringify(Array.isArray(list) ? list : []));

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json().then(function (payload) {
				return { ok: response.ok, payload: payload };
			}).catch(function () {
				return { ok: false, payload: null };
			});
		}).then(function (result) {
			var payload = result.payload || {};
			if (!result.ok || !payload.success || !payload.data || !Array.isArray(payload.data.pins)) {
				return;
			}
			pinnedList = payload.data.pins;
			config.pinnedActions = pinnedList;
		}).catch(function () {
			// Keep the in-memory list; next toggle will retry.
		});
	}

	function isPinned(page) {
		var key = pageKey(page);
		if (!key) {
			return false;
		}
		var pinned = loadPinned();
		for (var i = 0; i < pinned.length; i++) {
			if (pinned[i] && pinned[i].key === key) {
				return true;
			}
		}
		return false;
	}

	function canPin(page) {
		if (!page || !page.title || isContextAction(page)) {
			return false;
		}
		if (isCommandAction(page) || isPluginAction(page) || isPostResult(page)) {
			return true;
		}
		return !!page.url;
	}

	function togglePin(page) {
		if (!canPin(page)) {
			return;
		}

		var key = pageKey(page);
		var pinned = loadPinned().filter(function (item) {
			return item && item.key;
		});
		var exists = false;
		var next = [];

		for (var i = 0; i < pinned.length; i++) {
			if (pinned[i].key === key) {
				exists = true;
				continue;
			}
			next.push(pinned[i]);
		}

		if (!exists) {
			next.unshift(snapshotPage(page));
		}

		persistPinned(next);

		if (overlay && overlay.classList.contains('is-open') && input) {
			render(input.value);
		}
	}

	function resolveStoredEntry(snapshot, fallbackParent) {
		if (!snapshot || !snapshot.key) {
			return null;
		}

		// Post pins keep their intent-specific title/url; don't replace with a bare live page.
		if ('post' === snapshot.type || snapshot.intent || snapshot.postId) {
			if (!snapshot.title) {
				return null;
			}
			return {
				title: snapshot.title,
				rawTitle: snapshot.rawTitle || '',
				url: snapshot.url || urlForPostIntent(snapshot, snapshot.intent || 'edit-wp'),
				parent: snapshot.parent || fallbackParent || '',
				type: 'post',
				plugin: '',
				pluginAction: '',
				pluginName: '',
				network: false,
				id: '',
				postId: snapshot.postId || 0,
				viewUrl: snapshot.viewUrl || '',
				editUrl: snapshot.editUrl || '',
				diviUrl: snapshot.diviUrl || '',
				intent: snapshot.intent || ''
			};
		}

		for (var i = 0; i < pages.length; i++) {
			if (pageKey(pages[i]) === snapshot.key) {
				return pages[i];
			}
		}

		// Match by normalized URL for legacy title|url pin keys and renamed links.
		if (snapshot.url && 'plugin' !== snapshot.type && 'command' !== snapshot.type && 'context' !== snapshot.type) {
			var snapUrl = normalizeUrl(snapshot.url);
			if (snapUrl) {
				for (var u = 0; u < pages.length; u++) {
					var live = pages[u];
					if (!live || !live.url || isPluginAction(live) || isCommandAction(live) || isContextAction(live) || isPostResult(live)) {
						continue;
					}
					if (normalizeUrl(live.url) === snapUrl) {
						return live;
					}
				}
			}
		}

		// Don't revive removed built-in commands (e.g. legacy Edit…) from recent storage.
		if ('command' === snapshot.type) {
			return null;
		}

		// Fall back to stored snapshot when the live index no longer has it.
		if (snapshot.title && (snapshot.url || 'plugin' === snapshot.type)) {
			return {
				title: snapshot.title,
				url: snapshot.url || '',
				parent: snapshot.parent || fallbackParent || '',
				type: snapshot.type || '',
				plugin: snapshot.plugin || '',
				pluginAction: snapshot.pluginAction || '',
				pluginName: snapshot.pluginName || '',
				network: !!snapshot.network,
				id: snapshot.id || '',
				postId: snapshot.postId || 0,
				viewUrl: snapshot.viewUrl || '',
				editUrl: snapshot.editUrl || '',
				diviUrl: snapshot.diviUrl || '',
				intent: snapshot.intent || ''
			};
		}

		return null;
	}

	function getEmptyStatePages() {
		var list = [];
		var seen = {};

		function pushUnique(page, groupParent) {
			if (!page || !page.title) {
				return;
			}
			if (!isPluginAction(page) && !isCommandAction(page) && !page.url) {
				return;
			}

			var key = pageKey(page);
			if (!key || seen[key]) {
				return;
			}
			seen[key] = true;

			var entry = {};
			for (var prop in page) {
				if (Object.prototype.hasOwnProperty.call(page, prop)) {
					entry[prop] = page[prop];
				}
			}
			if (groupParent) {
				entry.parent = groupParent;
			}
			list.push(entry);
		}

		var pinned = loadPinned();
		if (pinned.length) {
			for (var p = 0; p < pinned.length; p++) {
				var resolvedPin = resolveStoredEntry(pinned[p], '');
				if (resolvedPin) {
					// Keep original category — the pin icon marks it as pinned.
					pushUnique(resolvedPin);
				}
			}
		} else {
			for (var d = 0; d < defaultPins.length; d++) {
				var suggested = defaultPins[d];
				var liveSuggested = null;

				for (var i = 0; i < pages.length; i++) {
					if (pages[i] && pages[i].title === suggested.title && !isPluginAction(pages[i]) && !isContextAction(pages[i]) && !isCommandAction(pages[i])) {
						liveSuggested = pages[i];
						break;
					}
				}

				pushUnique(liveSuggested || suggested);
			}
		}

		for (var c = 0; c < contextActions.length; c++) {
			pushUnique(contextActions[c], 'Current');
		}

		for (var b = 0; b < builtinCommands.length; b++) {
			pushUnique(builtinCommands[b], 'Command');
		}

		var recent = loadRecent();
		for (var r = 0; r < recent.length; r++) {
			var resolvedRecent = resolveStoredEntry(recent[r], 'Recent');
			if (resolvedRecent) {
				pushUnique(resolvedRecent, 'Recent');
			}
		}

		// Fill remaining slots with admin pages so the empty state isn't sparse.
		for (var s = 0; s < pages.length && list.length < EMPTY_STATE_MAX; s++) {
			var suggestion = pages[s];
			if (!suggestion || isPluginAction(suggestion) || isCommandAction(suggestion) || isContextAction(suggestion) || isPostResult(suggestion)) {
				continue;
			}
			pushUnique(suggestion);
		}

		return list;
	}

	window.acpMergePages = function (extra) {
		if (!Array.isArray(extra) || !extra.length) {
			return;
		}

		var keyed = {};
		var all = pages.concat(extra);

		for (var i = 0; i < all.length; i++) {
			var page = all[i];
			if (!page || !page.title) {
				continue;
			}
			if (!isPluginAction(page) && !isCommandAction(page) && !page.url) {
				continue;
			}
			var key = pageKey(page);
			if (!key || keyed[key]) {
				continue;
			}
			keyed[key] = page;
		}

		pages = [];
		for (var keyedKey in keyed) {
			if (Object.prototype.hasOwnProperty.call(keyed, keyedKey)) {
				pages.push(keyed[keyedKey]);
			}
		}

		config.pages = pages;

		if (overlay && overlay.classList.contains('is-open') && input) {
			render(input.value);
		}
	};

	// Collapse any URL duplicates already present in the bootstrapped index.
	window.acpMergePages(pages.slice());

	if (Array.isArray(window.acpAdminBarPages)) {
		window.acpMergePages(window.acpAdminBarPages);
	}

	function ensureToast() {
		if (toastEl) {
			return;
		}
		toastEl = document.createElement('div');
		toastEl.id = 'acp-toast';
		document.body.appendChild(toastEl);
	}

	function showToast(message, type) {
		ensureToast();
		toastEl.textContent = message;
		toastEl.classList.remove('is-success', 'is-error');
		if ('success' === type) {
			toastEl.classList.add('is-success');
		} else if ('error' === type) {
			toastEl.classList.add('is-error');
		}
		toastEl.classList.add('is-visible');
		if (toastTimer) {
			clearTimeout(toastTimer);
		}
		toastTimer = setTimeout(function () {
			toastEl.classList.remove('is-visible');
		}, 3500);
	}

	function enterShortcutRecording() {
		recordingShortcut = true;
		if (input) {
			input.blur();
		}
		showToast('Press new shortcut… Esc to cancel. Backspace to reset.');
	}

	function exitShortcutRecording() {
		recordingShortcut = false;
	}

	function applyShortcut(next) {
		var clean = normalizeShortcut(next);
		if (!clean) {
			return null;
		}
		shortcut = clean;
		config.shortcut = clean;
		return clean;
	}

	function saveShortcutToServer(next, onDone) {
		var clean = normalizeShortcut(next);
		if (!clean) {
			if (onDone) {
				onDone(false, null);
			}
			return;
		}

		var previous = {
			mod: !!shortcut.mod,
			shift: !!shortcut.shift,
			alt: !!shortcut.alt,
			key: shortcut.key
		};
		applyShortcut(clean);

		if (!ajaxUrl || !shortcutNonce) {
			if (onDone) {
				onDone(true, clean);
			}
			return;
		}

		var body = new FormData();
		body.append('action', 'acp_save_shortcut');
		body.append('nonce', shortcutNonce);
		// Discrete fields avoid wp_unslash corrupting JSON when key is "\".
		body.append('mod', clean.mod ? '1' : '0');
		body.append('shift', clean.shift ? '1' : '0');
		body.append('alt', clean.alt ? '1' : '0');
		body.append('key', clean.key);

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (result) {
			return result.json().then(function (payload) {
				return { ok: result.ok, payload: payload };
			});
		}).then(function (result) {
			var payload = result.payload;
			if (!result.ok || !payload || !payload.success || !payload.data || !payload.data.shortcut) {
				applyShortcut(previous);
				var message = payload && payload.data && payload.data.message
					? payload.data.message
					: 'Could not save shortcut.';
				showToast(message, 'error');
				if (onDone) {
					onDone(false, null);
				}
				return;
			}
			applyShortcut(payload.data.shortcut);
			if (onDone) {
				onDone(true, shortcut);
			}
		}).catch(function () {
			applyShortcut(previous);
			showToast('Could not save shortcut.', 'error');
			if (onDone) {
				onDone(false, null);
			}
		});
	}

	function handleShortcutRecordingKeydown(e) {
		if (!recordingShortcut) {
			return false;
		}

		e.preventDefault();
		e.stopPropagation();

		if ('Escape' === e.key) {
			exitShortcutRecording();
			showToast('Shortcut unchanged.');
			if (isOpen() && input) {
				input.focus();
			}
			return true;
		}

		if ('Backspace' === e.key && !e.ctrlKey && !e.metaKey && !e.altKey && !e.shiftKey) {
			exitShortcutRecording();
			saveShortcutToServer(DEFAULT_SHORTCUT, function (ok, saved) {
				if (ok && saved) {
					showToast('Shortcut reset to ' + formatShortcutLabel(saved) + '.', 'success');
				}
				if (isOpen() && input) {
					input.focus();
				}
			});
			return true;
		}

		if (isModifierOnlyKey(e.key)) {
			return true;
		}

		var next = shortcutFromEvent(e);
		if (!next) {
			showToast('Use Ctrl/Cmd or Alt plus a key.', 'error');
			return true;
		}

		exitShortcutRecording();
		saveShortcutToServer(next, function (ok, saved) {
			if (ok && saved) {
				showToast('Shortcut set to ' + formatShortcutLabel(saved) + '.', 'success');
			}
			if (isOpen() && input) {
				input.focus();
			}
		});
		return true;
	}

	function setPluginBusy(plugin, label, type) {
		if (actionTimer) {
			clearTimeout(actionTimer);
			actionTimer = null;
		}

		if (!plugin) {
			pluginBusy = null;
		} else {
			pluginBusy = {
				plugin: plugin,
				label: label || '',
				type: type || 'pending'
			};
		}

		if (overlay && overlay.classList.contains('is-open') && input) {
			render(input.value);
		}
	}

	function commitPluginEntry(data) {
		if (!data) {
			return;
		}
		updatePluginEntry(data);
		pluginBusy = null;
		pendingPluginCommit = null;
	}

	function flushPendingPluginCommit() {
		if (actionTimer) {
			clearTimeout(actionTimer);
			actionTimer = null;
		}
		if (pendingPluginCommit) {
			commitPluginEntry(pendingPluginCommit);
		} else {
			pluginBusy = null;
		}
	}

	function syncAccentFromVb() {
		var schemeMap = {
			blue: '#326BFF',
			purple: '#7432FF',
			green: '#0ACFA0',
			orange: '#ff9232',
			red: '#ef5555'
		};

		var accent = accentColor;
		var scheme = document.documentElement.getAttribute('data-app-color-scheme');

		// Inside Visual Builder, prefer the live scheme attribute / --app-color.
		if (scheme && schemeMap[scheme]) {
			accent = schemeMap[scheme];
		} else if (scheme) {
			try {
				var live = window.getComputedStyle(document.documentElement).getPropertyValue('--app-color').trim();
				if (live) {
					accent = live;
				}
			} catch (err) {
				// Keep PHP-resolved accent.
			}
		}

		if (overlay) {
			overlay.style.setProperty('--acp-accent', accent);
		}
	}

	function createUI() {
		if (overlay) {
			return;
		}

		overlay = document.createElement('div');
		overlay.id = 'acp-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.setAttribute('aria-label', 'Commands');
		overlay.innerHTML =
			'<div id="acp-modal">' +
				'<div id="acp-search-wrap">' +
					'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
						'<circle cx="11" cy="11" r="7"></circle>' +
						'<path d="M20 20l-3.5-3.5"></path>' +
					'</svg>' +
					'<span id="acp-mode-chip" hidden></span>' +
					'<input id="acp-search" type="search" placeholder="Search commands…" autocomplete="off" spellcheck="false" />' +
				'</div>' +
				'<ul id="acp-results" role="listbox"></ul>' +
				'<div id="acp-empty" hidden>No matching commands.</div>' +
				'<div id="acp-footer">' +
					'<div class="acp-footer-row">' +
						'<span><kbd>↑</kbd> <kbd>↓</kbd></span>' +
						'<span><kbd>Enter</kbd> open</span>' +
						'<span><kbd>Ctrl</kbd>+<kbd>Enter</kbd> new tab</span>' +
						'<span><kbd>Alt</kbd>+<kbd>P</kbd> pin</span>' +
						'<span><kbd>Esc</kbd></span>' +
					'</div>' +
					'<div class="acp-footer-row acp-footer-prefixes">' +
						'<span><kbd>&gt;</kbd> commands</span>' +
						'<span><kbd>#</kbd> posts</span>' +
						'<span><kbd>@</kbd> pages</span>' +
					'</div>' +
				'</div>' +
			'</div>';

		document.body.appendChild(overlay);

		input = overlay.querySelector('#acp-search');
		modeChip = overlay.querySelector('#acp-mode-chip');
		resultsEl = overlay.querySelector('#acp-results');
		emptyEl = overlay.querySelector('#acp-empty');

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				close();
			}
		});

		input.addEventListener('input', function () {
			activeIndex = 0;
			onSearchInput();
		});

		input.addEventListener('keydown', onInputKeydown);
	}

	function parseQuery(raw) {
		var text = String(raw || '');
		var leading = text.match(/^\s*/)[0];
		var rest = text.slice(leading.length);
		var filter = '';
		var query = rest;

		if (0 === rest.indexOf('>')) {
			filter = 'commands';
			query = rest.slice(1).replace(/^\s+/, '');
		} else if (0 === rest.indexOf('#')) {
			filter = 'posts';
			query = rest.slice(1).replace(/^\s+/, '');
		} else if (0 === rest.indexOf('@')) {
			filter = 'pages';
			query = rest.slice(1).replace(/^\s+/, '');
		}

		return {
			filter: filter,
			query: query,
			raw: text
		};
	}

	function isAdminPage(page) {
		if (!page || !page.url) {
			return false;
		}
		if (isPluginAction(page) || isCommandAction(page) || isPostResult(page)) {
			return false;
		}
		return true;
	}

	function getInputPrefixFilter() {
		if (!input || isPostSearchMode() || isPostActionsMode()) {
			return '';
		}
		return parseQuery(input.value).filter;
	}

	function isPrefixPostFilter() {
		return 'posts' === getInputPrefixFilter();
	}

	function isPostActionsMode() {
		return 'post-actions' === mode && !!selectedPost;
	}

	function onSearchInput() {
		if (isPostActionsMode()) {
			activeIndex = 0;
			renderPostActions();
			return;
		}

		if (isPostSearchMode()) {
			scheduleOpenSearch(input.value);
			updatePrefixPlaceholder();
			return;
		}

		var parsed = parseQuery(input.value);
		updatePrefixPlaceholder();

		if ('posts' === parsed.filter) {
			scheduleOpenSearch(parsed.query);
			return;
		}

		render(input.value);
	}

	function updatePrefixPlaceholder() {
		if (!input || isPostSearchMode() || isPostActionsMode()) {
			return;
		}

		var filter = parseQuery(input.value).filter;
		if ('commands' === filter) {
			input.placeholder = PLACEHOLDER_PREFIX_COMMANDS;
			if (emptyEl) {
				emptyEl.textContent = 'No matching commands.';
			}
		} else if ('pages' === filter) {
			input.placeholder = PLACEHOLDER_PREFIX_PAGES;
			if (emptyEl) {
				emptyEl.textContent = 'No matching pages.';
			}
		} else if ('posts' === filter) {
			input.placeholder = PLACEHOLDER_PREFIX_POSTS;
			if (emptyEl) {
				emptyEl.textContent = 'No matching posts.';
			}
		} else {
			input.placeholder = PLACEHOLDER_DEFAULT;
			if (emptyEl) {
				emptyEl.textContent = 'No matching commands.';
			}
		}
	}

	function isPostSearchMode() {
		return 'open' === mode || 'edit-wp' === mode || 'edit-divi' === mode;
	}

	function postResultUrl(page) {
		if (!page) {
			return '';
		}
		if (page.intent) {
			return urlForPostIntent(page, page.intent);
		}
		if ('open' === mode) {
			return page.viewUrl || page.url || '';
		}
		if ('edit-divi' === mode) {
			return page.diviUrl || '';
		}
		if ('edit-wp' === mode) {
			return page.editUrl || page.url || '';
		}
		return page.editUrl || page.url || '';
	}

	function truncateChipLabel(label, maxLen) {
		label = String(label || '');
		maxLen = maxLen || 28;
		if (label.length <= maxLen) {
			return label;
		}
		return label.slice(0, Math.max(0, maxLen - 1)) + '…';
	}

	function setMode(nextMode) {
		mode = nextMode;
		if (!input) {
			return;
		}

		if (modeChip) {
			if ('open' === mode) {
				modeChip.textContent = 'Open:';
				modeChip.hidden = false;
				modeChip.classList.add('is-visible');
			} else if ('edit-divi' === mode) {
				modeChip.textContent = 'Divi:';
				modeChip.hidden = false;
				modeChip.classList.add('is-visible');
			} else if ('edit-wp' === mode) {
				modeChip.textContent = 'WordPress:';
				modeChip.hidden = false;
				modeChip.classList.add('is-visible');
			} else if ('post-actions' === mode && selectedPost) {
				modeChip.textContent = truncateChipLabel(selectedPost.title || 'Post');
				modeChip.hidden = false;
				modeChip.classList.add('is-visible');
			} else {
				modeChip.textContent = '';
				modeChip.hidden = true;
				modeChip.classList.remove('is-visible');
			}
		}

		if (isPostActionsMode()) {
			input.placeholder = 'Choose an action…';
			if (emptyEl) {
				emptyEl.textContent = 'No matching actions.';
			}
		} else if (isPostSearchMode()) {
			input.placeholder = PLACEHOLDER_POSTS;
			if (emptyEl) {
				emptyEl.textContent = 'No matching posts.';
			}
		} else {
			input.placeholder = PLACEHOLDER_DEFAULT;
			if (emptyEl) {
				emptyEl.textContent = 'No matching commands.';
			}
		}
	}

	function buildPostActionPages(post) {
		if (!post) {
			return [];
		}

		var rawTitle = post.title || '';
		var actions = [];

		function addAction(intent, title) {
			var url = urlForPostIntent(post, intent);
			if (!url) {
				return;
			}

			actions.push({
				title: title,
				rawTitle: rawTitle,
				parent: 'Command',
				type: 'post',
				postId: post.postId,
				viewUrl: post.viewUrl || '',
				editUrl: post.editUrl || '',
				diviUrl: post.diviUrl || '',
				url: post.url || '',
				intent: intent
			});
		}

		addAction('open', 'Open');
		addAction('edit-divi', 'Edit with Divi');
		addAction('edit-wp', 'Edit in WordPress');

		return actions;
	}

	function enterPostActions(post) {
		if (!post || !post.postId) {
			return;
		}

		selectedPost = post;
		postsPrefixRestore = input ? input.value : '#';
		setMode('post-actions');
		input.value = '';
		activeIndex = 0;
		renderPostActions();
		input.focus();
	}

	function exitPostActions() {
		var restore = postsPrefixRestore || '#';
		selectedPost = null;
		postsPrefixRestore = '';
		setMode('commands');
		input.value = restore;
		activeIndex = 0;
		onSearchInput();
		input.focus();
	}

	function renderPostActions() {
		if (!isPostActionsMode()) {
			return;
		}

		var actions = buildPostActionPages(selectedPost);
		var query = input ? input.value : '';

		if (normalize(query)) {
			actions = actions.filter(function (action) {
				return scoreMatch(action, query) > 0;
			});
		}

		filtered = actions;
		resultsEl.innerHTML = '';

		if (!filtered.length) {
			resultsEl.hidden = true;
			emptyEl.hidden = false;
			emptyEl.textContent = 'No matching actions.';
			return;
		}

		resultsEl.hidden = false;
		emptyEl.hidden = true;
		paintResults(query);
	}

	function enterPostMode(nextMode) {
		selectedPost = null;
		postsPrefixRestore = '';
		setMode(nextMode);
		input.value = '';
		activeIndex = 0;
		openResults = [];
		scheduleOpenSearch('');
		input.focus();
	}

	function exitPostMode() {
		if (openSearchTimer) {
			clearTimeout(openSearchTimer);
			openSearchTimer = null;
		}
		openSearchSeq += 1;
		openSearching = false;
		openResults = [];
		selectedPost = null;
		postsPrefixRestore = '';
		setMode('commands');
		input.value = '';
		activeIndex = 0;
		render('');
		input.focus();
	}

	function scheduleOpenSearch(query) {
		if (openSearchTimer) {
			clearTimeout(openSearchTimer);
		}

		openSearching = true;
		renderOpenResults(query, openResults, true);

		openSearchTimer = setTimeout(function () {
			fetchOpenResults(query);
		}, 180);
	}

	function fetchOpenResults(query) {
		if (!ajaxUrl || !searchNonce) {
			openSearching = false;
			openResults = [];
			renderOpenResults(query, openResults, false);
			return;
		}

		var seq = ++openSearchSeq;
		var body = new FormData();
		body.append('action', 'acp_search_posts');
		body.append('nonce', searchNonce);
		body.append('q', query || '');

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json().then(function (payload) {
				return { ok: response.ok, payload: payload };
			}).catch(function () {
				return { ok: false, payload: null };
			});
		}).then(function (result) {
			if (seq !== openSearchSeq || (!isPostSearchMode() && !isPrefixPostFilter())) {
				return;
			}

			openSearching = false;
			var payload = result.payload || {};
			if (!result.ok || !payload.success || !payload.data || !Array.isArray(payload.data.results)) {
				openResults = [];
				renderOpenResults(query, openResults, false);
				return;
			}

			openResults = payload.data.results;
			renderOpenResults(query, openResults, false);
		}).catch(function () {
			if (seq !== openSearchSeq || (!isPostSearchMode() && !isPrefixPostFilter())) {
				return;
			}
			openSearching = false;
			openResults = [];
			renderOpenResults(query, openResults, false);
		});
	}

	function renderOpenResults(query, results, isLoading) {
		if (!isPostSearchMode() && !isPrefixPostFilter()) {
			return;
		}

		filtered = Array.isArray(results) ? results.slice() : [];
		if ('edit-divi' === mode) {
			filtered = filtered.filter(function (item) {
				return item && item.diviUrl;
			});
		}
		resultsEl.innerHTML = '';

		if (!filtered.length) {
			resultsEl.hidden = true;
			emptyEl.hidden = false;
			emptyEl.textContent = isLoading ? 'Searching…' : 'No matching posts.';
			return;
		}

		resultsEl.hidden = false;
		emptyEl.hidden = true;
		paintResults(query);
	}

	function normalize(str) {
		return String(str || '').toLowerCase().replace(/\s+/g, ' ').trim();
	}

	function tokenizeQuery(query) {
		query = normalize(query);
		if (!query) {
			return [];
		}
		return query.split(' ').filter(Boolean);
	}

	/**
	 * Max edit distance allowed for a query token.
	 *
	 * @param {number} tokenLen
	 * @return {number}
	 */
	function maxFuzzyDistance(tokenLen) {
		if (tokenLen < 3) {
			return 0;
		}
		if (tokenLen < 6) {
			return 1;
		}
		return 2;
	}

	/**
	 * Damerau–Levenshtein distance (adjacent transposition = 1) for short strings.
	 *
	 * @param {string} a
	 * @param {string} b
	 * @param {number} maxDist Early-exit threshold.
	 * @return {number}
	 */
	function editDistance(a, b, maxDist) {
		a = String(a || '');
		b = String(b || '');

		if (a === b) {
			return 0;
		}

		var aLen = a.length;
		var bLen = b.length;
		var limit = 'number' === typeof maxDist ? maxDist : 2;

		if (Math.abs(aLen - bLen) > limit) {
			return limit + 1;
		}

		if (!aLen) {
			return bLen;
		}
		if (!bLen) {
			return aLen;
		}

		var i;
		var j;
		var matrix = [];

		for (i = 0; i <= aLen; i++) {
			matrix[i] = [i];
		}
		for (j = 0; j <= bLen; j++) {
			matrix[0][j] = j;
		}

		for (i = 1; i <= aLen; i++) {
			var rowMin = limit + 1;
			var aChar = a.charAt(i - 1);

			for (j = 1; j <= bLen; j++) {
				var bChar = b.charAt(j - 1);
				var cost = aChar === bChar ? 0 : 1;
				var dist = Math.min(
					matrix[i - 1][j] + 1,
					matrix[i][j - 1] + 1,
					matrix[i - 1][j - 1] + cost
				);

				// Adjacent transposition.
				if (i > 1 && j > 1 && aChar === b.charAt(j - 2) && a.charAt(i - 2) === bChar) {
					dist = Math.min(dist, matrix[i - 2][j - 2] + 1);
				}

				matrix[i][j] = dist;
				if (dist < rowMin) {
					rowMin = dist;
				}
			}

			if (rowMin > limit) {
				return limit + 1;
			}
		}

		return matrix[aLen][bLen];
	}

	/**
	 * Rank how well a title word matches a query token (exact, substring, fuzzy).
	 *
	 * @param {string} word
	 * @param {string} token
	 * @return {number} Rank score, or 0 if no match.
	 */
	function rankWordToken(word, token) {
		if (!word || !token) {
			return 0;
		}

		if (0 === word.indexOf(token)) {
			return 300 + Math.floor((token.length / word.length) * 100);
		}

		if (-1 !== word.indexOf(token)) {
			return 80 + Math.floor((token.length / word.length) * 40);
		}

		var maxDist = maxFuzzyDistance(token.length);
		if (!maxDist) {
			return 0;
		}

		var best = editDistance(word, token, maxDist);

		// Typo against the leading slice ("thme" ≈ "theme").
		if (word.length > token.length) {
			best = Math.min(best, editDistance(word.slice(0, token.length), token, maxDist));
			best = Math.min(best, editDistance(word.slice(0, token.length + 1), token, maxDist));
		} else if (token.length > word.length) {
			best = Math.min(best, editDistance(word, token.slice(0, word.length), maxDist));
		}

		if (best > maxDist) {
			return 0;
		}

		// Fuzzy matches rank well below exact/prefix hits.
		return Math.max(12, 55 - (best * 18) + Math.min(token.length, 8));
	}

	function getMatchRanges(text, tokens) {
		var lower = String(text || '').toLowerCase();
		var ranges = [];

		for (var t = 0; t < tokens.length; t++) {
			var token = tokens[t];
			if (!token) {
				continue;
			}

			var from = 0;
			var index = lower.indexOf(token, from);
			while (-1 !== index) {
				ranges.push({ start: index, end: index + token.length });
				from = index + token.length;
				index = lower.indexOf(token, from);
			}
		}

		if (!ranges.length) {
			return [];
		}

		ranges.sort(function (a, b) {
			return a.start - b.start || a.end - b.end;
		});

		var merged = [ranges[0]];
		for (var i = 1; i < ranges.length; i++) {
			var prev = merged[merged.length - 1];
			var curr = ranges[i];
			if (curr.start <= prev.end) {
				prev.end = Math.max(prev.end, curr.end);
			} else {
				merged.push(curr);
			}
		}

		return merged;
	}

	function appendHighlightedText(container, text, query) {
		container.textContent = '';
		text = String(text || '');
		var tokens = tokenizeQuery(query);

		if (!tokens.length || !text) {
			container.textContent = text;
			return;
		}

		var ranges = getMatchRanges(text, tokens);
		if (!ranges.length) {
			container.textContent = text;
			return;
		}

		var cursor = 0;
		for (var i = 0; i < ranges.length; i++) {
			var range = ranges[i];
			if (range.start > cursor) {
				container.appendChild(document.createTextNode(text.slice(cursor, range.start)));
			}

			var mark = document.createElement('strong');
			mark.textContent = text.slice(range.start, range.end);
			container.appendChild(mark);
			cursor = range.end;
		}

		if (cursor < text.length) {
			container.appendChild(document.createTextNode(text.slice(cursor)));
		}
	}

	function scoreMatch(page, query) {
		var title = normalize(page.title);
		var parent = normalize(page.parent);
		var pluginName = normalize(page.pluginName);
		var tokens = tokenizeQuery(query);

		if (!tokens.length) {
			return 1;
		}

		var titleWords = title.split(' ').filter(Boolean);
		if (!titleWords.length) {
			return 0;
		}

		var fullQuery = tokens.join(' ');
		if (title === fullQuery) {
			return 100000;
		}

		// Match tokens in order against title words (prefer word prefixes).
		var matchedIndexes = [];
		var searchFrom = 0;
		var score = 0;

		for (var t = 0; t < tokens.length; t++) {
			var token = tokens[t];
			var bestIndex = -1;
			var bestRank = -1;
			var bestWord = '';

			for (var w = searchFrom; w < titleWords.length; w++) {
				var word = titleWords[w];
				var rank = rankWordToken(word, token);

				if (!rank) {
					continue;
				}

				// Prefer earlier words when rank ties.
				rank -= w;

				if (rank > bestRank) {
					bestRank = rank;
					bestIndex = w;
					bestWord = word;
				}

				// First solid prefix match in order is enough; keep scanning only
				// if we have not found a prefix yet.
				if (0 === word.indexOf(token)) {
					break;
				}
			}

			if (-1 === bestIndex) {
				// Fall back: token may span parent/plugin meta, but rank much lower.
				var haystack = [title, parent, pluginName].filter(Boolean).join(' ');
				var hayWords = haystack.split(' ').filter(Boolean);
				var metaHit = false;

				for (var h = 0; h < hayWords.length; h++) {
					if (rankWordToken(hayWords[h], token)) {
						metaHit = true;
						break;
					}
				}

				if (!metaHit) {
					return 0;
				}
				score += 10;
				continue;
			}

			matchedIndexes.push(bestIndex);
			score += bestRank;
			score += Math.min(token.length, 12) * 3;
			searchFrom = bestIndex + 1;
		}

		// Reward tight word-by-word sequences.
		for (var i = 1; i < matchedIndexes.length; i++) {
			var gap = matchedIndexes[i] - matchedIndexes[i - 1];
			if (1 === gap) {
				score += 200;
			} else if (2 === gap) {
				score += 80;
			} else {
				score += Math.max(0, 40 - ((gap - 2) * 20));
			}
		}

		// Prefer matches that start earlier and titles with fewer extra words.
		if (matchedIndexes.length) {
			score -= matchedIndexes[0] * 12;
		}
		score -= Math.max(0, titleWords.length - tokens.length) * 18;
		score -= title.length;

		// Boost context / command actions slightly when searching related terms.
		if (isContextAction(page) || isCommandAction(page)) {
			score += 40;
		}

		return Math.max(score, 1);
	}

	function filterPages(rawQuery) {
		var parsed = parseQuery(rawQuery);
		var query = parsed.query;
		var filter = parsed.filter;

		if ('posts' === filter) {
			return [];
		}

		if (!normalize(query) && !filter) {
			return getEmptyStatePages();
		}

		var pool = pages;
		if ('commands' === filter) {
			pool = pages.filter(isCommandAction);
		} else if ('pages' === filter) {
			pool = pages.filter(isAdminPage);
		}

		if (!normalize(query)) {
			var listed = pool.slice().sort(function (a, b) {
				var aPinned = isPinned(a) ? 1 : 0;
				var bPinned = isPinned(b) ? 1 : 0;
				if (aPinned !== bPinned) {
					return bPinned - aPinned;
				}
				return String(a.title || '').localeCompare(String(b.title || ''));
			});
			return listed.slice(0, 'commands' === filter ? 50 : EMPTY_STATE_MAX);
		}

		var scored = [];

		for (var i = 0; i < pool.length; i++) {
			var s = scoreMatch(pool[i], query);
			if (s > 0) {
				scored.push({ page: pool[i], score: s });
			}
		}

		scored.sort(function (a, b) {
			var aPinned = isPinned(a.page) ? 1 : 0;
			var bPinned = isPinned(b.page) ? 1 : 0;
			if (aPinned !== bPinned) {
				return bPinned - aPinned;
			}
			if (b.score !== a.score) {
				return b.score - a.score;
			}
			if (a.page.title.length !== b.page.title.length) {
				return a.page.title.length - b.page.title.length;
			}
			return a.page.title.localeCompare(b.page.title);
		});

		return scored.map(function (item) {
			return item.page;
		}).slice(0, 300);
	}

	function navigateTo(url, newTab) {
		if (!url) {
			return;
		}

		if (newTab) {
			window.open(url, '_blank', 'noopener,noreferrer');
			close();
			return;
		}

		window.location.href = url;
	}

	function updatePluginEntry(data) {
		for (var i = 0; i < pages.length; i++) {
			if (isPluginAction(pages[i]) && pages[i].plugin === data.plugin) {
				pages[i].pluginAction = data.pluginAction;
				pages[i].title = data.title;
				pages[i].pluginName = data.pluginName || pages[i].pluginName;
				pages[i].network = !!data.network;
				break;
			}
		}
		config.pages = pages;
	}

	function setCommandBusy(commandId, label, type) {
		if (actionTimer) {
			clearTimeout(actionTimer);
			actionTimer = null;
		}

		if (!commandId) {
			commandBusy = null;
		} else {
			commandBusy = {
				id: commandId,
				label: label || '',
				type: type || 'pending'
			};
		}

		if (overlay && overlay.classList.contains('is-open') && input) {
			render(input.value);
		}
	}

	function clearCache() {
		if (busy || !ajaxUrl || !clearCacheNonce) {
			return;
		}

		busy = true;
		setCommandBusy('clear-cache', 'Clearing cache…', 'pending');
		showToast('Clearing cache…', 'success');

		var body = new FormData();
		body.append('action', 'acp_clear_cache');
		body.append('nonce', clearCacheNonce);

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json().then(function (payload) {
				return { ok: response.ok, payload: payload };
			}).catch(function () {
				return { ok: false, payload: null };
			});
		}).then(function (result) {
			var payload = result.payload || {};

			if (!result.ok || !payload.success) {
				busy = false;
				var err = (payload.data && payload.data.message)
					? payload.data.message
					: 'Failed to clear cache.';
				setCommandBusy('clear-cache', 'Failed: ' + err, 'error');
				showToast('Failed: ' + err, 'error');
				return;
			}

			var okMessage = (payload.data && payload.data.message) ? payload.data.message : 'Cache cleared.';
			setCommandBusy('clear-cache', okMessage, 'success');
			showToast(okMessage, 'success');
			window.location.reload();
		}).catch(function () {
			busy = false;
			var err = 'Failed to clear cache.';
			setCommandBusy('clear-cache', 'Failed: ' + err, 'error');
			showToast(err, 'error');
		});
	}

	function togglePlugin(page) {
		if (busy || !ajaxUrl) {
			return;
		}

		busy = true;
		var pendingLabel = ('activate' === page.pluginAction)
			? ('Activating ' + (page.pluginName || 'plugin') + '…')
			: ('Deactivating ' + (page.pluginName || 'plugin') + '…');
		setPluginBusy(page.plugin, pendingLabel, 'pending');

		var body = new FormData();
		body.append('action', 'acp_toggle_plugin');
		body.append('nonce', nonce);
		body.append('plugin', page.plugin);
		body.append('pluginAction', page.pluginAction);
		body.append('network', page.network ? '1' : '');

		fetch(ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json().then(function (payload) {
				return { ok: response.ok, payload: payload };
			}).catch(function () {
				return { ok: false, payload: null };
			});
		}).then(function (result) {
			busy = false;
			var payload = result.payload || {};

			if (!result.ok || !payload.success) {
				var err = (payload.data && payload.data.message)
					? payload.data.message
					: 'Plugin action failed.';
				setPluginBusy(page.plugin, 'Failed: ' + err, 'error');
				showToast('Failed: ' + err, 'error');
				return;
			}

			var okMessage = payload.data.message || 'Done.';
			setPluginBusy(page.plugin, okMessage, 'success');
			showToast(okMessage, 'success');
			window.location.reload();
		}).catch(function () {
			busy = false;
			var err = 'Plugin action failed.';
			setPluginBusy(page.plugin, 'Failed: ' + err, 'error');
			showToast(err, 'error');
		});
	}

	function categoryLabel(page) {
		if (isPluginAction(page)) {
			return 'Plugin';
		}
		if (isCommandAction(page)) {
			return 'Command';
		}
		if (isPostResult(page) && page.parent) {
			return page.parent;
		}
		if (page.parent) {
			return page.parent;
		}
		return 'Admin';
	}

	function pageBaseTitle(page) {
		if (!page) {
			return '';
		}
		if (page.rawTitle) {
			return page.rawTitle;
		}

		var title = String(page.title || '');
		var prefixes = ['Open: ', 'Edit with Divi: ', 'Edit in WordPress: ', 'Open ', 'Edit with Divi ', 'Edit in WordPress '];
		for (var i = 0; i < prefixes.length; i++) {
			if (0 === title.indexOf(prefixes[i])) {
				return title.slice(prefixes[i].length);
			}
		}
		return title;
	}

	function appendResultTitle(container, page, query) {
		container.textContent = '';

		var intent = page && page.intent ? page.intent : '';
		var label = postIntentLabel(intent);
		var baseTitle = pageBaseTitle(page);
		var text = document.createElement('span');
		text.className = 'acp-title-text';

		// Intent chip for pinned post actions (Open / Edit with Divi / Edit in WordPress).
		if (label && intent && isPinned(page)) {
			var chip = document.createElement('span');
			chip.className = 'acp-intent-chip';
			chip.textContent = label + ':';
			container.appendChild(chip);
			appendHighlightedText(text, baseTitle, query);
			container.appendChild(text);
			return;
		}

		appendHighlightedText(text, page.title || baseTitle, query);
		container.appendChild(text);
	}

	function createPinButton(page) {
		var pinned = isPinned(page);
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'acp-pin' + (pinned ? ' is-pinned' : '');
		btn.setAttribute('aria-label', pinned ? 'Unpin command' : 'Pin command');
		btn.setAttribute('title', pinned ? 'Unpin' : 'Pin');

		// Outline pin when unpinned; solid pin when pinned.
		if (pinned) {
			btn.innerHTML =
				'<svg viewBox="0 0 24 24" aria-hidden="true">' +
					'<path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"></path>' +
				'</svg>';
		} else {
			btn.innerHTML =
				'<svg viewBox="0 0 24 24" aria-hidden="true" fill="none">' +
					'<path d="M12 21s7-7.2 7-12a7 7 0 1 0-14 0c0 4.8 7 12 7 12z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>' +
					'<circle cx="12" cy="9" r="2.5" stroke-width="2"></circle>' +
				'</svg>';
		}

		btn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			togglePin(page);
		});

		btn.addEventListener('mousedown', function (e) {
			// Keep focus in the search input.
			e.preventDefault();
		});

		return btn;
	}

	function runItem(page, newTab) {
		if (!page) {
			return;
		}

		if (isCommandAction(page) && 'open-post' === page.id) {
			rememberRecent(page);
			enterPostMode('open');
			return;
		}

		if (isCommandAction(page) && 'edit-divi' === page.id) {
			rememberRecent(page);
			enterPostMode('edit-divi');
			return;
		}

		if (isCommandAction(page) && 'edit-wp' === page.id) {
			rememberRecent(page);
			enterPostMode('edit-wp');
			return;
		}

		if (isCommandAction(page) && 'clear-cache' === page.id) {
			rememberRecent(page);
			clearCache();
			return;
		}

		if (isCommandAction(page) && 'change-shortcut' === page.id) {
			rememberRecent(page);
			enterShortcutRecording();
			return;
		}

		if (isPluginAction(page)) {
			rememberRecent(page);
			togglePlugin(page);
			return;
		}

		if (isPostResult(page)) {
			if (isPrefixPostFilter() && !page.intent) {
				enterPostActions(page);
				return;
			}
			rememberRecent(page);
			navigateTo(postResultUrl(page), !!newTab);
			return;
		}

		rememberRecent(page);
		navigateTo(page.url, !!newTab);
	}

	function paintResults(query) {
		if (activeIndex >= filtered.length) {
			activeIndex = filtered.length - 1;
		}
		if (activeIndex < 0) {
			activeIndex = 0;
		}

		for (var i = 0; i < filtered.length; i++) {
			var page = filtered[i];
			var li = document.createElement('li');
			var a = document.createElement('a');
			var isBusyItem = (pluginBusy && isPluginAction(page) && page.plugin === pluginBusy.plugin)
				|| (commandBusy && isCommandAction(page) && page.id === commandBusy.id);
			var pinnable = canPin(page);
			a.href = '#';
			if (isPostResult(page)) {
				a.href = postResultUrl(page) || '#';
			} else if (!isPluginAction(page) && page.url) {
				a.href = page.url;
			} else if (isCommandAction(page) && page.url) {
				a.href = page.url;
			}
			a.setAttribute('role', 'option');
			a.className = i === activeIndex ? 'is-active' : '';
			if (isBusyItem) {
				var busyType = (pluginBusy && isPluginAction(page) && page.plugin === pluginBusy.plugin)
					? pluginBusy.type
					: (commandBusy ? commandBusy.type : '');
				if (busyType) {
					a.classList.add('is-' + busyType);
				}
			}
			if (pinnable && isPinned(page)) {
				a.classList.add('is-pinned');
			}

			var title = document.createElement('span');
			title.className = 'acp-title';
			if (isBusyItem) {
				var busyLabel = (pluginBusy && isPluginAction(page) && page.plugin === pluginBusy.plugin)
					? pluginBusy.label
					: (commandBusy ? commandBusy.label : '');
				if (busyLabel) {
					title.textContent = busyLabel;
				} else {
					appendResultTitle(title, page, query);
				}
			} else {
				appendResultTitle(title, page, query);
			}
			a.appendChild(title);

			var parent = document.createElement('span');
			parent.className = 'acp-parent';
			parent.textContent = categoryLabel(page);
			a.appendChild(parent);

			if (pinnable) {
				a.appendChild(createPinButton(page));
			}

			a.addEventListener('mouseenter', (function (index) {
				return function () {
					activeIndex = index;
					updateActive();
				};
			})(i));

			a.addEventListener('click', (function (item) {
				return function (e) {
					e.preventDefault();
					runItem(item, e.ctrlKey || e.metaKey);
				};
			})(page));

			li.appendChild(a);
			resultsEl.appendChild(li);
		}
	}

	function render(rawQuery) {
		if (isPostActionsMode()) {
			renderPostActions();
			return;
		}

		if (isPostSearchMode()) {
			renderOpenResults(rawQuery, openResults, openSearching);
			return;
		}

		var parsed = parseQuery(rawQuery);
		if ('posts' === parsed.filter) {
			renderOpenResults(parsed.query, openResults, openSearching);
			return;
		}

		filtered = filterPages(rawQuery);
		resultsEl.innerHTML = '';

		if (!filtered.length) {
			resultsEl.hidden = true;
			emptyEl.hidden = false;
			return;
		}

		resultsEl.hidden = false;
		emptyEl.hidden = true;
		paintResults(parsed.query);
	}

	function updateActive() {
		var links = resultsEl.querySelectorAll('a');
		for (var i = 0; i < links.length; i++) {
			if (i === activeIndex) {
				links[i].classList.add('is-active');
				links[i].scrollIntoView({ block: 'nearest' });
			} else {
				links[i].classList.remove('is-active');
			}
		}
	}

	function open() {
		createUI();
		syncAccentFromVb();
		flushPendingPluginCommit();
		if (openSearchTimer) {
			clearTimeout(openSearchTimer);
			openSearchTimer = null;
		}
		openSearchSeq += 1;
		openSearching = false;
		openResults = [];
		selectedPost = null;
		postsPrefixRestore = '';
		setMode('commands');
		overlay.classList.add('is-open');
		input.value = '';
		activeIndex = 0;
		render('');
		input.focus();
	}

	function close() {
		if (!overlay) {
			return;
		}
		if (recordingShortcut) {
			exitShortcutRecording();
		}
		if (openSearchTimer) {
			clearTimeout(openSearchTimer);
			openSearchTimer = null;
		}
		openSearchSeq += 1;
		openSearching = false;
		openResults = [];
		selectedPost = null;
		postsPrefixRestore = '';
		setMode('commands');
		overlay.classList.remove('is-open');
		input.blur();
		flushPendingPluginCommit();
	}

	function isOpen() {
		return overlay && overlay.classList.contains('is-open');
	}

	function goToActive(newTab) {
		if (!filtered.length) {
			return;
		}
		runItem(filtered[activeIndex], newTab);
	}

	function pinActive() {
		if (!filtered.length) {
			return;
		}
		togglePin(filtered[activeIndex]);
	}

	function handleEscape() {
		if (recordingShortcut) {
			exitShortcutRecording();
			showToast('Shortcut unchanged.');
			if (input) {
				input.focus();
			}
			return;
		}
		if (isPostActionsMode()) {
			exitPostActions();
			return;
		}
		if (isPostSearchMode()) {
			exitPostMode();
			return;
		}
		close();
	}

	function onInputKeydown(e) {
		if (recordingShortcut) {
			handleShortcutRecordingKeydown(e);
			return;
		}
		if ('ArrowDown' === e.key) {
			e.preventDefault();
			activeIndex = Math.min(activeIndex + 1, filtered.length - 1);
			updateActive();
		} else if ('ArrowUp' === e.key) {
			e.preventDefault();
			activeIndex = Math.max(activeIndex - 1, 0);
			updateActive();
		} else if ('Enter' === e.key) {
			e.preventDefault();
			goToActive(e.ctrlKey || e.metaKey);
		} else if (e.altKey && !e.ctrlKey && !e.metaKey && ('p' === e.key || 'P' === e.key)) {
			e.preventDefault();
			pinActive();
		} else if ('Backspace' === e.key && !input.value && (isPostSearchMode() || isPostActionsMode())) {
			e.preventDefault();
			if (isPostActionsMode()) {
				exitPostActions();
			} else {
				exitPostMode();
			}
		} else if ('Escape' === e.key) {
			e.preventDefault();
			handleEscape();
		}
	}

	document.addEventListener('keydown', function (e) {
		if (recordingShortcut) {
			handleShortcutRecordingKeydown(e);
			return;
		}

		if (matchesShortcut(e, shortcut)) {
			e.preventDefault();
			if (isOpen()) {
				close();
			} else {
				open();
			}
			return;
		}

		if (isOpen() && 'Escape' === e.key) {
			e.preventDefault();
			handleEscape();
		}
	});
})();
