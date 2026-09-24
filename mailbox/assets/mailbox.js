/* Mailbox frontend. No build step, no external deps. */
(function () {
  'use strict';

  var CFG = JSON.parse(document.getElementById('mb-config').textContent);
  var root = document.getElementById('mb');
  if (!root) return;

  // ------------------------------------------------------------------
  // Utilities
  // ------------------------------------------------------------------

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function formatListDate(iso) {
    if (!iso) return '';
    var d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d.getTime())) return '';
    var now = new Date();
    var sameDay = d.toDateString() === now.toDateString();
    var pad = function (n) { return String(n).padStart(2, '0'); };
    if (sameDay) return pad(d.getHours()) + ':' + pad(d.getMinutes());
    var sameYear = d.getFullYear() === now.getFullYear();
    return sameYear ? (d.getMonth() + 1) + '/' + d.getDate() : (d.getMonth() + 1) + '/' + d.getDate() + '/' + d.getFullYear();
  }
  function formatFullDate(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString();
  }
  function formatBytes(n) {
    if (n === null || n === undefined) return '';
    if (n < 1024) return n + ' B';
    var units = ['KB', 'MB', 'GB'];
    var v = n / 1024, i = 0;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return v.toFixed(v < 10 ? 1 : 0) + ' ' + units[i];
  }
  function parseAddresses(raw) {
    return (raw || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
  }
  function nameOf(addr) { return (addr && (addr.name || addr.email)) || '(unknown)'; }
  function folderIcon(f) {
    if (f.role === 'sent') return 'fa-solid fa-paper-plane';
    if (f.role === 'drafts') return 'fa-regular fa-file-lines';
    if (f.role === 'trash') return 'fa-regular fa-trash-can';
    if (f.role === 'junk') return 'fa-solid fa-triangle-exclamation';
    if (f.role === 'archive') return 'fa-solid fa-box-archive';
    if (f.raw === 'INBOX') return 'fa-solid fa-inbox';
    return 'fa-regular fa-folder';
  }

  window.__mbUtils = { formatListDate: formatListDate, formatBytes: formatBytes, parseAddresses: parseAddresses, escapeHtml: escapeHtml };

  // ------------------------------------------------------------------
  // API layer (mirrors the file-manager plugin's token-retry pattern)
  // ------------------------------------------------------------------

  var token = CFG.token;
  var tokenRefreshing = null;
  function refreshToken() {
    if (tokenRefreshing) return tokenRefreshing;
    tokenRefreshing = fetch(CFG.urls.token, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) token = j.data.token; tokenRefreshing = null; return token; })
      .catch(function () { tokenRefreshing = null; return token; });
    return tokenRefreshing;
  }
  function apiGet(url, params) {
    var qs = new URLSearchParams(params || {}).toString();
    return fetch(url + (qs ? '?' + qs : ''), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'The server sent back something unexpected.' }; }).then(function (j) { j.__status = r.status; return j; }); });
  }
  function apiPost(url, payload, retry) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
      body: JSON.stringify(payload),
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'The server sent back something unexpected.' }; }).then(function (j) {
        if (!j.ok && j.reason === 'csrf' && !retry) return refreshToken().then(function () { return apiPost(url, payload, true); });
        j.__status = r.status;
        return j;
      });
    });
  }
  function apiAction(action, payload) { return apiPost(CFG.urls.action, Object.assign({ action: action }, payload)); }

  // ------------------------------------------------------------------
  // State
  // ------------------------------------------------------------------

  var state = {
    folders: [], folder: 'INBOX', trashFolder: null,
    items: [], total: 0, page: 1, perPage: 50, query: '', unseenOnly: false, searching: false,
    selected: new Set(), current: null, currentData: null,
  };

  var el = {
    folders: document.getElementById('mb-folders'),
    accountEmail: document.getElementById('mb-account-email'),
    list: document.getElementById('mb-list'),
    listEmpty: document.getElementById('mb-list-empty'),
    listScroll: document.getElementById('mb-list-scroll'),
    selectAll: document.getElementById('mb-select-all'),
    bulk: document.getElementById('mb-bulk-actions'),
    searchForm: document.getElementById('mb-search'),
    searchInput: document.getElementById('mb-search-input'),
    pager: document.getElementById('mb-pager'),
    pageLabel: document.getElementById('mb-page-label'),
    pagePrev: document.getElementById('mb-page-prev'),
    pageNext: document.getElementById('mb-page-next'),
    readingPane: document.getElementById('mb-reading-pane'),
    readingEmpty: document.getElementById('mb-reading-empty'),
    message: document.getElementById('mb-message'),
    menu: document.getElementById('mb-menu'),
    toasts: document.getElementById('mb-toasts'),
    compose: document.getElementById('mb-compose'),
    refresh: document.getElementById('mb-refresh'),
  };

  function toast(kind, message, detail) {
    var t = document.createElement('div');
    t.className = 'mb-toast' + (kind === 'error' ? ' is-error' : kind === 'ok' ? ' is-ok' : '');
    t.innerHTML = '<div>' + escapeHtml(message) + '</div>' + (detail ? '<small>' + escapeHtml(detail) + '</small>' : '');
    el.toasts.appendChild(t);
    setTimeout(function () { t.remove(); }, kind === 'error' ? 6000 : 3200);
  }
  function errorMessage(j, fallback) { return (j && j.error) || fallback || 'Something went wrong.'; }

  // ------------------------------------------------------------------
  // Folders
  // ------------------------------------------------------------------

  function loadFolders() {
    return apiGet(CFG.urls.folders).then(function (j) {
      if (!j.ok) { toast('error', errorMessage(j, 'Could not load folders.')); return; }
      state.folders = j.data;
      var trash = j.data.filter(function (f) { return f.role === 'trash'; })[0];
      state.trashFolder = trash ? trash.raw : null;
      renderFolders();
    });
  }

  function renderFolders() {
    el.folders.innerHTML = state.folders.map(function (f) {
      return '<button type="button" class="mb-folder' + (f.raw === state.folder ? ' is-current' : '') + '" data-raw="' + escapeHtml(f.raw) + '">' +
        '<i class="mb-ficon ' + folderIcon(f) + '"></i><span class="mb-folder-name">' + escapeHtml(f.name) + '</span>' +
        (f.unread ? '<span class="mb-folder-unread">' + f.unread + '</span>' : '') + '</button>';
    }).join('');
    el.accountEmail.textContent = CFG.accountEmail || '';
  }

  function selectFolder(raw) {
    if (state.folder === raw) return;
    state.folder = raw; state.page = 1; state.query = ''; state.searching = false;
    el.searchInput.value = '';
    state.current = null; state.currentData = null;
    renderReadingEmpty();
    Array.prototype.forEach.call(el.folders.querySelectorAll('.mb-folder'), function (b) { b.classList.toggle('is-current', b.getAttribute('data-raw') === raw); });
    root.classList.remove('is-reading');
    loadMessages();
  }

  // ------------------------------------------------------------------
  // Message list
  // ------------------------------------------------------------------

  function loadMessages() {
    root.setAttribute('data-state', 'loading');
    return apiGet(CFG.urls.messages, { folder: state.folder, page: state.page, q: state.query, unseen: state.unseenOnly ? '1' : '0' }).then(function (j) {
      root.setAttribute('data-state', 'ready');
      if (!j.ok) { toast('error', errorMessage(j, 'Could not load messages.')); return; }
      state.items = j.data.items; state.total = j.data.total; state.perPage = j.data.perPage;
      state.selected.clear();
      renderList();
      loadFolders(); // unread counts may have changed
    }).catch(function () { root.setAttribute('data-state', 'ready'); toast('error', 'Could not reach the server.'); });
  }

  function renderList() {
    if (state.items.length === 0) {
      el.list.innerHTML = '';
      el.listEmpty.hidden = false;
      el.listEmpty.innerHTML = '<i class="fa-regular fa-envelope-open"></i>' + (state.query ? 'No messages match your search.' : 'This folder is empty.');
    } else {
      el.listEmpty.hidden = true;
      el.list.innerHTML = state.items.map(rowHtml).join('');
    }
    var pages = Math.max(1, Math.ceil(state.total / state.perPage));
    el.pager.hidden = pages <= 1;
    el.pageLabel.textContent = 'Page ' + state.page + ' of ' + pages;
    el.pagePrev.disabled = state.page <= 1;
    el.pageNext.disabled = state.page >= pages;
    updateBulkUi();
  }

  function rowHtml(item) {
    var sel = state.selected.has(item.uid);
    var cur = state.current === item.uid;
    return '<li class="mb-row' + (item.seen ? '' : ' is-unread') + (sel ? ' is-selected' : '') + (cur ? ' is-current' : '') + '" data-uid="' + item.uid + '">' +
      '<input type="checkbox" ' + (sel ? 'checked' : '') + ' aria-label="Select">' +
      '<button type="button" class="mb-star-btn' + (item.flagged ? ' is-flagged' : '') + '" data-star="' + item.uid + '" aria-label="Flag"><i class="fa-' + (item.flagged ? 'solid' : 'regular') + ' fa-star"></i></button>' +
      '<div class="mb-row-main"><div class="mb-row-top"><span class="mb-row-from">' + escapeHtml(nameOf(item.from)) + '</span><span class="mb-row-date">' + escapeHtml(formatListDate(item.date)) + '</span></div>' +
      '<div class="mb-row-subject">' + escapeHtml(item.subject) + (item.snippet ? ' <span class="mb-row-snippet">&ndash; ' + escapeHtml(item.snippet) + '</span>' : '') + '</div>' +
      '<div class="mb-row-icons">' + (item.hasAttachments ? '<i class="fa-solid fa-paperclip" title="Has attachments"></i>' : '') + (item.answered ? '<i class="fa-solid fa-reply" title="Replied"></i>' : '') + '</div></div></li>';
  }

  function updateBulkUi() {
    var n = state.selected.size;
    el.bulk.hidden = n === 0;
    el.searchForm.hidden = n !== 0;
    el.selectAll.checked = n > 0 && n === state.items.length;
    el.selectAll.indeterminate = n > 0 && n < state.items.length;
  }

  // ------------------------------------------------------------------
  // Reading pane
  // ------------------------------------------------------------------

  function renderReadingEmpty() {
    el.readingEmpty.hidden = false;
    el.message.hidden = true;
    el.message.innerHTML = '';
  }

  function openMessage(uid) {
    state.current = uid;
    Array.prototype.forEach.call(el.list.querySelectorAll('.mb-row'), function (r) { r.classList.toggle('is-current', +r.getAttribute('data-uid') === uid); });
    root.classList.add('is-reading');
    apiGet(CFG.urls.message, { folder: state.folder, uid: uid }).then(function (j) {
      if (!j.ok) { toast('error', errorMessage(j, 'Could not open this message.')); return; }
      state.currentData = j.data;
      var row = state.items.filter(function (i) { return i.uid === uid; })[0];
      if (row) { row.seen = true; renderRowInPlace(uid); }
      renderReading(j.data);
    });
  }

  function renderRowInPlace(uid) {
    var li = el.list.querySelector('.mb-row[data-uid="' + uid + '"]');
    var item = state.items.filter(function (i) { return i.uid === uid; })[0];
    if (li && item) li.outerHTML = rowHtml(item);
  }

  function renderReading(d) {
    el.readingEmpty.hidden = true;
    el.message.hidden = false;
    var atts = (d.attachments || []).map(function (a) {
      return '<a class="mb-att-chip" href="' + CFG.urls.attachment + '?folder=' + encodeURIComponent(d.folder) + '&uid=' + d.uid + '&index=' + a.index + '"><i class="fa-regular fa-file"></i><span>' + escapeHtml(a.filename) + '</span><small>' + formatBytes(a.size) + '</small></a>';
    }).join('');
    el.message.innerHTML =
      '<div class="mb-msg-head"><h2 class="mb-msg-subject">' + escapeHtml(d.subject || '(no subject)') + '</h2>' +
      '<div class="mb-msg-meta"><div class="mb-msg-from"><b>' + escapeHtml(nameOf(d.from)) + '</b> <span class="mb-email">&lt;' + escapeHtml((d.from && d.from.email) || '') + '&gt;</span>' +
      '<div class="mb-msg-to">to ' + escapeHtml((d.to || []).map(nameOf).join(', ') || '\u2014') + '</div></div>' +
      '<div class="mb-msg-date">' + escapeHtml(formatFullDate(d.date)) + '</div></div>' +
      '<div class="mb-msg-actions">' +
      '<button type="button" class="mb-btn" data-act="reply"><i class="fa-solid fa-reply"></i> Reply</button>' +
      '<button type="button" class="mb-btn" data-act="reply-all"><i class="fa-solid fa-reply-all"></i> Reply all</button>' +
      '<button type="button" class="mb-btn" data-act="forward"><i class="fa-solid fa-share"></i> Forward</button>' +
      '<button type="button" class="mb-btn" data-act="move"><i class="fa-solid fa-arrow-right-arrow-left"></i> Move</button>' +
      '<button type="button" class="mb-btn is-danger" data-act="delete"><i class="fa-regular fa-trash-can"></i> Delete</button>' +
      '</div></div>' +
      (atts ? '<div class="mb-attachments">' + atts + '</div>' : '') +
      '<div class="mb-msg-body' + (d.body.type === 'text' ? ' is-plain' : '') + '">' + (d.body.type === 'html' ? sanitizeHtml(d.body.content) : escapeHtml(d.body.content)) + '</div>';
  }

  /** Strips script/event-handler/javascript: vectors from message HTML before injecting it. */
  function sanitizeHtml(html) {
    var doc = new DOMParser().parseFromString(html, 'text/html');
    Array.prototype.forEach.call(doc.querySelectorAll('script,style,iframe,object,embed,link,meta,base,form'), function (n) { n.remove(); });
    Array.prototype.forEach.call(doc.querySelectorAll('*'), function (n) {
      Array.prototype.slice.call(n.attributes).forEach(function (a) {
        var name = a.name.toLowerCase();
        if (name.indexOf('on') === 0 || (['href', 'src', 'action'].indexOf(name) !== -1 && /^\s*javascript:/i.test(a.value))) n.removeAttribute(a.name);
      });
      if (n.tagName === 'A') { n.setAttribute('target', '_blank'); n.setAttribute('rel', 'noopener noreferrer'); }
    });
    return doc.body.innerHTML;
  }

  // ------------------------------------------------------------------
  // Modal system (compact version of the file-manager plugin's pattern)
  // ------------------------------------------------------------------

  var activeModal = null;
  function openModal(opts) {
    closeModal();
    var backdrop = document.createElement('div');
    backdrop.className = 'mbx-backdrop';
    var modal = document.createElement('div');
    modal.className = 'mbx-modal' + (opts.compose ? ' is-compose' : '');
    modal.innerHTML = '<div class="mbx-head"><h2>' + escapeHtml(opts.title) + '</h2><button type="button" class="mbx-x">\u2715</button></div>' +
      '<div class="mbx-body">' + opts.body + '</div>' + (opts.foot ? '<div class="mbx-foot">' + opts.foot + '</div>' : '');
    backdrop.appendChild(modal);
    document.body.appendChild(backdrop);
    activeModal = backdrop;
    backdrop.addEventListener('mousedown', function (e) { if (e.target === backdrop && !opts.pinned) closeModal(); });
    modal.querySelector('.mbx-x').addEventListener('click', function () { if (opts.onClose) opts.onClose(); else closeModal(); });
    document.addEventListener('keydown', escHandler);
    if (opts.onMount) opts.onMount(modal);
    var f = modal.querySelector('input,textarea');
    if (f) f.focus();
    return modal;
  }
  function escHandler(e) { if (e.key === 'Escape') closeModal(); }
  function closeModal() { if (activeModal) { activeModal.remove(); activeModal = null; document.removeEventListener('keydown', escHandler); } }
  function busy(modal, on) { Array.prototype.forEach.call(modal.querySelectorAll('button,input,textarea'), function (b) { b.disabled = on; }); }
  function modalError(modal, msg) {
    var err = modal.querySelector('.mbx-err');
    if (!err) { err = document.createElement('div'); err.className = 'mbx-err'; modal.querySelector('.mbx-body').appendChild(err); }
    err.hidden = false; err.textContent = msg;
  }

  function promptModal(title, label, value, onSubmit) {
    var modal = openModal({
      title: title, body: '<label>' + escapeHtml(label) + '</label><input type="text" id="mbx-input" value="' + escapeHtml(value || '') + '"><div class="mbx-err" hidden></div>',
      foot: '<button type="button" class="mbx-btn" data-close>Cancel</button><button type="button" class="mbx-btn is-primary" id="mbx-ok">Save</button>',
    });
    modal.querySelector('[data-close]').addEventListener('click', closeModal);
    var input = modal.querySelector('#mbx-input');
    function submit() { var v = input.value.trim(); if (!v) { modalError(modal, 'This field is required.'); return; } onSubmit(v, modal); }
    modal.querySelector('#mbx-ok').addEventListener('click', submit);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') submit(); });
    return modal;
  }
  function confirmModal(title, body, danger, submitLabel, onConfirm) {
    var modal = openModal({
      title: title, body: body,
      foot: '<button type="button" class="mbx-btn" data-close>Cancel</button><button type="button" class="mbx-btn ' + (danger ? 'is-danger' : 'is-primary') + '" id="mbx-ok">' + escapeHtml(submitLabel || 'Confirm') + '</button>',
    });
    modal.querySelector('[data-close]').addEventListener('click', closeModal);
    modal.querySelector('#mbx-ok').addEventListener('click', function () { onConfirm(modal); });
    return modal;
  }

  function folderPickerModal(title, exclude, onPick) {
    var choices = state.folders.filter(function (f) { return f.raw !== exclude; });
    var modal = openModal({
      title: title,
      body: '<div class="mbx-tree">' + choices.map(function (f) { return '<button type="button" data-raw="' + escapeHtml(f.raw) + '"><i class="' + folderIcon(f) + '"></i> ' + escapeHtml(f.name) + '</button>'; }).join('') + '</div>',
    });
    modal.querySelector('.mbx-tree').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-raw]');
      if (b) { onPick(b.getAttribute('data-raw')); closeModal(); }
    });
    return modal;
  }

  // ------------------------------------------------------------------
  // Actions: flags / move / copy / delete / folders
  // ------------------------------------------------------------------

  function selectedUidsOrCurrent() {
    if (state.selected.size) return Array.from(state.selected);
    return state.current ? [state.current] : [];
  }

  function toggleFlag(uid, on) {
    apiAction(on ? 'flag.set' : 'flag.unset', { folder: state.folder, uids: [uid], flag: 'flagged' }).then(function (j) {
      if (!j.ok) { toast('error', errorMessage(j)); return; }
      var item = state.items.filter(function (i) { return i.uid === uid; })[0];
      if (item) { item.flagged = on; renderRowInPlace(uid); }
    });
  }

  function bulkMarkRead(read) {
    var uids = selectedUidsOrCurrent();
    if (!uids.length) return;
    apiAction(read ? 'flag.set' : 'flag.unset', { folder: state.folder, uids: uids, flag: 'seen' }).then(function (j) {
      if (!j.ok) { toast('error', errorMessage(j)); return; }
      loadMessages();
    });
  }

  function bulkDelete() {
    var uids = selectedUidsOrCurrent();
    if (!uids.length) return;
    var permanent = state.folder === state.trashFolder;
    confirmModal(permanent ? 'Delete permanently?' : 'Delete ' + uids.length + ' message(s)?', permanent ? '<p>This cannot be undone.</p>' : '<p>Moved to Trash.</p>', true, 'Delete', function (modal) {
      busy(modal, true);
      apiAction('delete', { folder: state.folder, uids: uids }).then(function (j) {
        busy(modal, false);
        if (!j.ok) { modalError(modal, errorMessage(j)); return; }
        closeModal();
        toast('ok', 'Deleted.');
        if (state.current && uids.indexOf(state.current) !== -1) { state.current = null; state.currentData = null; renderReadingEmpty(); root.classList.remove('is-reading'); }
        loadMessages();
      });
    });
  }

  function bulkMove() {
    var uids = selectedUidsOrCurrent();
    if (!uids.length) return;
    folderPickerModal('Move ' + uids.length + ' message(s) to\u2026', state.folder, function (dest) {
      apiAction('move', { folder: state.folder, uids: uids, dest: dest }).then(function (j) {
        if (!j.ok) { toast('error', errorMessage(j)); return; }
        toast('ok', 'Moved.');
        if (state.current && uids.indexOf(state.current) !== -1) { state.current = null; state.currentData = null; renderReadingEmpty(); root.classList.remove('is-reading'); }
        loadMessages();
      });
    });
  }

  function newFolder() {
    promptModal('New folder', 'Folder name', '', function (name, modal) {
      busy(modal, true);
      apiAction('folder.create', { name: name }).then(function (j) {
        busy(modal, false);
        if (!j.ok) { modalError(modal, errorMessage(j)); return; }
        closeModal(); toast('ok', 'Folder created.'); loadFolders();
      });
    });
  }

  // ------------------------------------------------------------------
  // Compose / reply / forward
  // ------------------------------------------------------------------

  function composeModal(opts) {
    opts = opts || {};
    var attachedFiles = [];
    var forwardAttachments = opts.forwardAttachments || [];
    var modal = openModal({
      title: opts.title || 'New message', compose: true, pinned: true,
      body:
        '<div class="mbx-compose-field"><label>To</label><input type="text" id="mbx-to" value="' + escapeHtml(opts.to || '') + '"><button type="button" class="mbx-cc-toggle" id="mbx-cc-toggle">Cc/Bcc</button></div>' +
        '<div id="mbx-cc-row" class="mbx-compose-field" ' + (opts.cc ? '' : 'hidden') + '><label>Cc</label><input type="text" id="mbx-cc" value="' + escapeHtml(opts.cc || '') + '"></div>' +
        '<div id="mbx-bcc-row" class="mbx-compose-field" hidden><label>Bcc</label><input type="text" id="mbx-bcc"></div>' +
        '<div class="mbx-compose-field"><label>Subject</label><input type="text" id="mbx-subject" value="' + escapeHtml(opts.subject || '') + '"></div>' +
        '<textarea class="mbx-compose-body" id="mbx-body">' + escapeHtml(opts.body || '') + '</textarea>' +
        '<div class="mbx-compose-atts" id="mbx-atts">' + forwardAttachments.map(function (a, i) { return '<span class="mbx-att-tag" data-fwd="' + i + '"><i class="fa-regular fa-file"></i>' + escapeHtml(a.filename) + '<button type="button" data-remove-fwd="' + i + '">\u2715</button></span>'; }).join('') + '</div>' +
        '<div class="mbx-err" hidden></div>',
      foot:
        '<div class="mbx-compose-toolbar" style="margin-right:auto"><label class="mbx-btn" style="cursor:pointer"><i class="fa-solid fa-paperclip"></i> <input type="file" id="mbx-file" multiple hidden></label></div>' +
        '<button type="button" class="mbx-btn" data-close>Discard</button><button type="button" class="mbx-btn is-primary" id="mbx-send">Send</button>',
    });

    modal.querySelector('[data-close]').addEventListener('click', function () {
      var ta = modal.querySelector('#mbx-body');
      if (ta.value.trim() !== (opts.body || '').trim()) {
        confirmModal('Discard this message?', '<p>Your draft will be lost.</p>', true, 'Discard', function () { closeModal(); });
      } else closeModal();
    });
    modal.querySelector('#mbx-cc-toggle').addEventListener('click', function () {
      modal.querySelector('#mbx-cc-row').hidden = false;
      modal.querySelector('#mbx-bcc-row').hidden = false;
    });
    modal.querySelector('#mbx-file').addEventListener('change', function (e) {
      attachedFiles = attachedFiles.concat(Array.prototype.slice.call(e.target.files));
      renderAttTags();
    });
    function renderAttTags() {
      var wrap = modal.querySelector('#mbx-atts');
      wrap.innerHTML = forwardAttachments.map(function (a, i) {
        return a === null ? '' : '<span class="mbx-att-tag"><i class="fa-regular fa-file"></i>' + escapeHtml(a.filename) + '<button type="button" data-remove-fwd="' + i + '">\u2715</button></span>';
      }).join('') + attachedFiles.map(function (f, i) {
        return '<span class="mbx-att-tag"><i class="fa-solid fa-paperclip"></i>' + escapeHtml(f.name) + '<button type="button" data-remove-file="' + i + '">\u2715</button></span>';
      }).join('');
    }
    modal.querySelector('#mbx-atts').addEventListener('click', function (e) {
      var rf = e.target.closest('[data-remove-fwd]'); var rl = e.target.closest('[data-remove-file]');
      if (rf) { forwardAttachments[+rf.getAttribute('data-remove-fwd')] = null; renderAttTags(); }
      if (rl) { attachedFiles.splice(+rl.getAttribute('data-remove-file'), 1); renderAttTags(); }
    });

    modal.querySelector('#mbx-send').addEventListener('click', function () {
      var to = parseAddresses(modal.querySelector('#mbx-to').value);
      if (!to.length) { modalError(modal, 'Enter at least one recipient.'); return; }
      var payload = {
        to: to, cc: parseAddresses(modal.querySelector('#mbx-cc') ? modal.querySelector('#mbx-cc').value : ''),
        bcc: parseAddresses(modal.querySelector('#mbx-bcc') ? modal.querySelector('#mbx-bcc').value : ''),
        subject: modal.querySelector('#mbx-subject').value, bodyText: modal.querySelector('#mbx-body').value,
        inReplyTo: opts.inReplyTo || null, references: opts.references || [],
        forwardAttachments: forwardAttachments.filter(Boolean).map(function (a) { return { folder: a.folder, uid: a.uid, index: a.index }; }),
      };
      busy(modal, true);
      var req;
      if (attachedFiles.length) {
        var fd = new FormData();
        fd.append('payload', JSON.stringify(payload));
        attachedFiles.forEach(function (f) { fd.append('files[]', f, f.name); });
        req = fetch(CFG.urls.send, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token }, body: fd })
          .then(function (r) { return r.json(); });
      } else {
        req = apiPost(CFG.urls.send, payload);
      }
      req.then(function (j) {
        busy(modal, false);
        if (!j.ok) { modalError(modal, errorMessage(j, 'Could not send.')); return; }
        closeModal(); toast('ok', 'Sent.');
        if (state.folder === CFG.accountEmail) loadMessages();
      });
    });
    return modal;
  }

  function quoteBody(d) {
    var lines = (d.body.type === 'text' ? d.body.content : (d.body.content || '').replace(/<[^>]+>/g, '')).split('\n');
    return '\n\n' + nameOf(d.from) + ' wrote on ' + formatFullDate(d.date) + ':\n' + lines.map(function (l) { return '> ' + l; }).join('\n');
  }

  function replyTo(d, all) {
    var to = (d.from && d.from.email) ? d.from.email : '';
    var cc = all ? (d.to || []).concat(d.cc || []).map(function (a) { return a.email; }).filter(function (e) { return e && e !== to; }).join(', ') : '';
    composeModal({
      title: all ? 'Reply all' : 'Reply', to: to, cc: cc,
      subject: /^re:/i.test(d.subject || '') ? d.subject : 'Re: ' + (d.subject || ''),
      body: quoteBody(d), inReplyTo: d.messageId, references: (d.references || []).concat(d.messageId ? [d.messageId] : []),
    });
  }

  function forwardMessage(d) {
    var fwdAtts = (d.attachments || []).map(function (a) { return { folder: d.folder, uid: d.uid, index: a.index, filename: a.filename }; });
    composeModal({
      title: 'Forward', subject: /^fwd:/i.test(d.subject || '') ? d.subject : 'Fwd: ' + (d.subject || ''),
      body: quoteBody(d), forwardAttachments: fwdAtts,
    });
  }

  // ------------------------------------------------------------------
  // Wiring
  // ------------------------------------------------------------------

  el.folders.addEventListener('click', function (e) {
    var b = e.target.closest('.mb-folder');
    if (b) selectFolder(b.getAttribute('data-raw'));
  });

  el.list.addEventListener('click', function (e) {
    var li = e.target.closest('.mb-row');
    if (!li) return;
    var uid = +li.getAttribute('data-uid');
    var star = e.target.closest('.mb-star-btn');
    if (star) { toggleFlag(uid, !star.classList.contains('is-flagged')); return; }
    if (e.target.closest('input[type=checkbox]')) {
      if (state.selected.has(uid)) state.selected.delete(uid); else state.selected.add(uid);
      li.classList.toggle('is-selected', state.selected.has(uid));
      updateBulkUi();
      return;
    }
    openMessage(uid);
  });
  el.list.addEventListener('contextmenu', function (e) {
    var li = e.target.closest('.mb-row');
    if (!li) return;
    e.preventDefault();
    var uid = +li.getAttribute('data-uid');
    if (!state.selected.has(uid)) { state.selected.clear(); state.selected.add(uid); renderList(); }
    showMenu(e.clientX, e.clientY, [
      { cmd: 'read', label: 'Mark read', icon: 'fa-regular fa-envelope-open' },
      { cmd: 'unread', label: 'Mark unread', icon: 'fa-regular fa-envelope' },
      { cmd: 'move', label: 'Move to\u2026', icon: 'fa-solid fa-arrow-right-arrow-left' },
      '-',
      { cmd: 'delete', label: 'Delete', icon: 'fa-regular fa-trash-can', danger: true },
    ]);
  });
  function showMenu(x, y, entries) {
    el.menu.innerHTML = entries.map(function (e) { return e === '-' ? '<hr>' : '<button type="button" data-cmd="' + e.cmd + '"' + (e.danger ? ' class="is-danger"' : '') + '><i class="' + e.icon + '"></i>' + escapeHtml(e.label) + '</button>'; }).join('');
    el.menu.hidden = false;
    var r = el.menu.getBoundingClientRect();
    el.menu.style.left = Math.min(x, window.innerWidth - r.width - 8) + 'px';
    el.menu.style.top = Math.min(y, window.innerHeight - r.height - 8) + 'px';
  }
  document.addEventListener('click', function (e) { if (!e.target.closest('#mb-menu')) el.menu.hidden = true; });
  el.menu.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-cmd]');
    if (!b) return;
    el.menu.hidden = true;
    switch (b.getAttribute('data-cmd')) {
      case 'read': bulkMarkRead(true); break;
      case 'unread': bulkMarkRead(false); break;
      case 'move': bulkMove(); break;
      case 'delete': bulkDelete(); break;
    }
  });

  el.selectAll.addEventListener('change', function () {
    if (el.selectAll.checked) state.items.forEach(function (i) { state.selected.add(i.uid); });
    else state.selected.clear();
    renderList();
  });
  el.bulk.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-act]');
    if (!b) return;
    switch (b.getAttribute('data-act')) {
      case 'bulk-flag': selectedUidsOrCurrent().forEach(function (u) { toggleFlag(u, true); }); break;
      case 'bulk-read': bulkMarkRead(true); break;
      case 'bulk-unread': bulkMarkRead(false); break;
      case 'bulk-move': bulkMove(); break;
      case 'bulk-delete': bulkDelete(); break;
    }
  });

  el.searchForm.addEventListener('submit', function (e) { e.preventDefault(); state.query = el.searchInput.value; state.page = 1; loadMessages(); });
  el.pagePrev.addEventListener('click', function () { if (state.page > 1) { state.page--; loadMessages(); } });
  el.pageNext.addEventListener('click', function () { state.page++; loadMessages(); });
  el.refresh.addEventListener('click', function () { loadMessages(); loadFolders(); });
  el.compose.addEventListener('click', function () { composeModal({}); });

  el.message.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-act]');
    if (!b || !state.currentData) return;
    switch (b.getAttribute('data-act')) {
      case 'reply': replyTo(state.currentData, false); break;
      case 'reply-all': replyTo(state.currentData, true); break;
      case 'forward': forwardMessage(state.currentData); break;
      case 'move': state.selected = new Set([state.current]); bulkMove(); break;
      case 'delete': state.selected = new Set([state.current]); bulkDelete(); break;
    }
  });

  document.addEventListener('keydown', function (e) {
    if (activeModal) return;
    var tag = (document.activeElement && document.activeElement.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    if (e.key === 'Delete' && (state.selected.size || state.current)) bulkDelete();
    if (e.key === 'Escape' && root.classList.contains('is-reading')) { root.classList.remove('is-reading'); }
  });

  // ------------------------------------------------------------------
  // Boot
  // ------------------------------------------------------------------

  loadFolders().then(loadMessages);
  window.__mbNewFolder = newFolder; // exposed for a future "new folder" button / context menu hook
})();
