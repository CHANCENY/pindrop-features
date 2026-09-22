/* File Manager frontend. No build step, no external deps. */
(function () {
  'use strict';

  var CFG = JSON.parse(document.getElementById('fm-config').textContent);
  var root = document.getElementById('fm');
  if (!root) return;

  // ------------------------------------------------------------------
  // Small utilities (exported on window.__fmUtils for smoke testing)
  // ------------------------------------------------------------------

  function joinPath(base, name) {
    base = (base || '').replace(/^\/+|\/+$/g, '');
    return base ? base + '/' + name : name;
  }
  function parentOf(path) {
    var i = (path || '').lastIndexOf('/');
    return i === -1 ? '' : path.slice(0, i);
  }
  function baseName(path) {
    var i = (path || '').lastIndexOf('/');
    return i === -1 ? path : path.slice(i + 1);
  }
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function formatBytes(n) {
    if (n === null || n === undefined) return '\u2014';
    if (n === 0) return '0 B';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
    var v = n / Math.pow(1024, i);
    return (i === 0 ? String(n) : v.toFixed(v < 10 ? 1 : 0)) + ' ' + units[i];
  }
  function formatDate(ts) {
    if (!ts) return '\u2014';
    var d = new Date(ts * 1000);
    if (isNaN(d.getTime())) return '\u2014';
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }
  function iconFor(item) {
    if (item.is_dir) return 'fa-solid fa-folder k-folder';
    switch (item.kind) {
      case 'image': return 'fa-regular fa-image k-image';
      case 'archive': return 'fa-solid fa-file-zipper k-archive';
      case 'code': return 'fa-solid fa-file-code k-code';
      default: return 'fa-regular fa-file';
    }
  }
  function cmp(a, b, key, dir) {
    var av = a[key], bv = b[key];
    if (key === 'name') { var r = String(av).localeCompare(String(bv), undefined, { numeric: true, sensitivity: 'base' }); return dir * r; }
    if (av === null || av === undefined) av = -1;
    if (bv === null || bv === undefined) bv = -1;
    if (av < bv) return -1 * dir;
    if (av > bv) return 1 * dir;
    return 0;
  }
  function sortItems(items, key, dir) {
    var out = items.slice();
    out.sort(function (a, b) {
      if (a.is_dir !== b.is_dir) return a.is_dir ? -1 : 1;
      return cmp(a, b, key, dir);
    });
    return out;
  }
  function debounce(fn, ms) {
    var t; return function () { var a = arguments, ctx = this; clearTimeout(t); t = setTimeout(function () { fn.apply(ctx, a); }, ms); };
  }

  window.__fmUtils = { joinPath: joinPath, parentOf: parentOf, baseName: baseName, formatBytes: formatBytes, formatDate: formatDate, sortItems: sortItems, escapeHtml: escapeHtml };

  // ------------------------------------------------------------------
  // API layer
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

  function apiPost(action, payload, retry) {
    return fetch(CFG.urls.action, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': token },
      body: JSON.stringify(Object.assign({ action: action }, payload)),
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'The server sent back something unexpected.' }; }).then(function (j) {
        if (!j.ok && j.reason === 'csrf' && !retry) {
          return refreshToken().then(function () { return apiPost(action, payload, true); });
        }
        j.__status = r.status;
        return j;
      });
    });
  }

  // ------------------------------------------------------------------
  // State
  // ------------------------------------------------------------------

  var state = {
    path: CFG.startPath || '',
    items: [],
    selected: new Set(),
    lastClicked: null,
    sortKey: 'name',
    sortDir: 1,
    showHidden: !!CFG.showHidden,
    treeExpanded: new Set(['']),
    treeCache: {},
    searching: false,
    editing: null, // {path, mtime, originalContent}
  };

  // ------------------------------------------------------------------
  // DOM refs
  // ------------------------------------------------------------------

  var el = {
    rows: document.getElementById('fm-rows'),
    table: document.getElementById('fm-table'),
    empty: document.getElementById('fm-empty'),
    crumbs: document.getElementById('fm-crumbs'),
    tree: document.getElementById('fm-tree'),
    status: document.getElementById('fm-status'),
    banner: document.getElementById('fm-banner'),
    up: document.getElementById('fm-up'),
    all: document.getElementById('fm-all'),
    hidden: document.getElementById('fm-hidden'),
    searchForm: document.getElementById('fm-search'),
    searchInput: document.getElementById('fm-search-input'),
    toolbar: document.getElementById('fm-toolbar'),
    scroll: document.getElementById('fm-scroll'),
    drop: document.getElementById('fm-drop'),
    dropTarget: document.getElementById('fm-drop-target'),
    fileInput: document.getElementById('fm-file-input'),
    uploads: document.getElementById('fm-uploads'),
    toasts: document.getElementById('fm-toasts'),
    menu: document.getElementById('fm-menu'),
  };

  // ------------------------------------------------------------------
  // Toasts / banner
  // ------------------------------------------------------------------

  function toast(kind, message, detail) {
    var t = document.createElement('div');
    t.className = 'fm-toast' + (kind === 'error' ? ' is-error' : kind === 'ok' ? ' is-ok' : '');
    t.innerHTML = '<div>' + escapeHtml(message) + '</div>' + (detail ? '<small>' + escapeHtml(detail) + '</small>' : '');
    el.toasts.appendChild(t);
    setTimeout(function () { t.remove(); }, kind === 'error' ? 6000 : 3200);
  }
  function showBanner(msg) {
    if (!msg) { el.banner.hidden = true; return; }
    el.banner.hidden = false;
    el.banner.textContent = msg;
  }
  function errorMessage(j, fallback) {
    if (j && j.error) return j.error;
    if (j && j.__status === 0) return 'Could not reach the server. Check your connection.';
    return fallback || 'Something went wrong.';
  }

  // ------------------------------------------------------------------
  // Loading a folder
  // ------------------------------------------------------------------

  function load(path, opts) {
    opts = opts || {};
    state.searching = false;
    root.setAttribute('data-state', 'loading');
    return apiGet(CFG.urls.list, { path: path, hidden: state.showHidden ? '1' : '0' }).then(function (j) {
      root.setAttribute('data-state', 'ready');
      if (!j.ok) {
        toast('error', errorMessage(j, 'Could not open that folder.'));
        if (path !== '') return load('', opts);
        el.rows.innerHTML = '';
        el.empty.hidden = false;
        el.empty.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>' + escapeHtml(errorMessage(j));
        return;
      }
      state.path = j.data.path;
      state.items = j.data.items;
      state.selected.clear();
      if (!opts.keepScroll) el.scroll.scrollTop = 0;
      renderCrumbs(j.data.breadcrumbs);
      renderRows();
      renderStatus(j.data.truncated);
      updateTreeSelection();
      updateHash();
      if (j.data.truncated) toast('info', 'This folder is very large; only the first items are shown.');
    }).catch(function () {
      root.setAttribute('data-state', 'ready');
      toast('error', 'Could not reach the server.');
    });
  }

  function refresh() { return state.searching ? runSearch() : load(state.path, { keepScroll: true }); }

  // ------------------------------------------------------------------
  // Rendering: rows
  // ------------------------------------------------------------------

  function renderRows() {
    var items = sortItems(state.items, state.sortKey, state.sortDir);
    if (items.length === 0) {
      el.rows.innerHTML = '';
      el.empty.hidden = false;
      el.empty.innerHTML = '<i class="fa-regular fa-folder-open"></i>This folder is empty.';
    } else {
      el.empty.hidden = true;
      el.rows.innerHTML = items.map(rowHtml).join('');
    }
    Array.prototype.forEach.call(el.table.querySelectorAll('th[data-sort]'), function (th) {
      var k = th.getAttribute('data-sort');
      th.setAttribute('aria-sort', k === state.sortKey ? (state.sortDir === 1 ? 'ascending' : 'descending') : 'none');
    });
    updateToolbarState();
  }

  function rowHtml(item) {
    var sel = state.selected.has(item.path);
    var tags = '';
    if (item.protected) tags += '<span class="fm-tag fm-tag-lock" title="Protected: cannot be modified"><i class="fa-solid fa-lock"></i></span>';
    if (item.is_link && !item.link_ok) tags += '<span class="fm-tag fm-tag-bad" title="Broken or unsafe link">link!</span>';
    else if (item.is_link) tags += '<span class="fm-tag" title="Symbolic link">link</span>';
    var sub = item.is_link && item.link_target ? '<span class="fm-name-sub">&rarr; ' + escapeHtml(item.link_target) + '</span>' : '';
    return (
      '<tr class="fm-row' + (sel ? ' is-selected' : '') + '" data-path="' + escapeHtml(item.path) + '" data-dir="' + (item.is_dir ? '1' : '0') + '" draggable="true">' +
      '<td class="fm-c-check"><input type="checkbox" ' + (sel ? 'checked' : '') + ' aria-label="Select ' + escapeHtml(item.name) + '"></td>' +
      '<td class="fm-c-name"><span class="fm-name"><i class="fm-ico ' + iconFor(item) + '"></i>' +
      '<span><span class="fm-name-text">' + escapeHtml(item.name) + '</span>' + sub + '</span>' + tags + '</span></td>' +
      '<td class="fm-c-size">' + (item.is_dir ? '\u2014' : formatBytes(item.size)) + '</td>' +
      '<td class="fm-c-date">' + formatDate(item.mtime) + '</td>' +
      '<td class="fm-c-perm">' + (item.perms || '\u2014') + '</td>' +
      '</tr>'
    );
  }

  function renderCrumbs(crumbs) {
    el.crumbs.innerHTML = crumbs.map(function (c, i) {
      var isLast = i === crumbs.length - 1;
      return (i > 0 ? '<span class="fm-sep">/</span>' : '') +
        '<button type="button" class="fm-crumb" data-path="' + escapeHtml(c.path) + '" ' + (isLast ? 'aria-current="page"' : '') + '>' + escapeHtml(c.name) + '</button>';
    }).join('');
  }

  function renderStatus(truncated) {
    var n = state.items.length;
    var sel = state.selected.size;
    el.status.textContent = n + ' item' + (n === 1 ? '' : 's') + (sel ? ', ' + sel + ' selected' : '') + (truncated ? ' (list truncated)' : '');
  }

  // ------------------------------------------------------------------
  // Selection
  // ------------------------------------------------------------------

  function selectionPaths() { return Array.from(state.selected); }
  function selectedItems() { return state.items.filter(function (i) { return state.selected.has(i.path); }); }

  function updateToolbarState() {
    var n = state.selected.size;
    var one = n === 1;
    var items = selectedItems();
    var oneFile = one && !items[0].is_dir;
    var oneZip = one && items[0].zip;
    setNeeds('any', n > 0);
    setNeeds('one', one);
    setNeeds('one-file', oneFile);
    setNeeds('one-zip', oneZip);
    var anyProtected = items.some(function (i) { return i.protected; });
    Array.prototype.forEach.call(el.toolbar.querySelectorAll('[data-act="delete"],[data-act="rename"],[data-act="move"],[data-act="chmod"]'), function (b) {
      b.disabled = b.disabled || (n > 0 && anyProtected);
    });
    el.all.checked = n > 0 && n === state.items.length;
    el.all.indeterminate = n > 0 && n < state.items.length;
    renderStatus();
  }
  function setNeeds(kind, on) {
    Array.prototype.forEach.call(el.toolbar.querySelectorAll('[data-needs="' + kind + '"]'), function (b) { b.disabled = !on; });
  }

  function toggleSelect(path, additive, range) {
    var items = sortItems(state.items, state.sortKey, state.sortDir);
    if (range && state.lastClicked) {
      var a = items.findIndex(function (i) { return i.path === state.lastClicked; });
      var b = items.findIndex(function (i) { return i.path === path; });
      if (a !== -1 && b !== -1) {
        var lo = Math.min(a, b), hi = Math.max(a, b);
        if (!additive) state.selected.clear();
        for (var i = lo; i <= hi; i++) state.selected.add(items[i].path);
        renderRows();
        return;
      }
    }
    if (!additive) state.selected.clear();
    if (state.selected.has(path) && additive) state.selected.delete(path); else state.selected.add(path);
    state.lastClicked = path;
    renderRows();
  }

  // ------------------------------------------------------------------
  // Navigation
  // ------------------------------------------------------------------

  function open(item) {
    if (item.is_dir) { load(item.path); return; }
    if (!item.editable) { toast('info', item.kind === 'image' ? 'Opening image preview.' : 'This file type can\u2019t be edited here \u2014 downloading instead.'); if (item.kind !== 'image') { downloadItem(item); return; } }
    if (item.kind === 'image') { openPreview(item); return; }
    openEditor(item.path);
  }

  function downloadItem(item) {
    var url = CFG.urls.download + '?path=' + encodeURIComponent(item.path);
    var a = document.createElement('a');
    a.href = url; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
  }

  function updateHash() {
    try { history.replaceState(null, '', '?path=' + encodeURIComponent(state.path)); } catch (e) {}
  }

  // ------------------------------------------------------------------
  // Tree (lazy folder tree in the sidebar)
  // ------------------------------------------------------------------

  function renderTree() {
    el.tree.innerHTML = '<ul class="fm-root" id="fm-tree-root"></ul>';
    loadTreeLevel('', document.getElementById('fm-tree-root'));
  }

  function loadTreeLevel(path, ul) {
    if (state.treeCache[path]) { paintTreeLevel(path, ul, state.treeCache[path]); return Promise.resolve(); }
    return apiGet(CFG.urls.list, { path: path, hidden: state.showHidden ? '1' : '0' }).then(function (j) {
      if (!j.ok) return;
      var dirs = j.data.items.filter(function (i) { return i.is_dir && i.link_ok !== false; });
      state.treeCache[path] = dirs;
      paintTreeLevel(path, ul, dirs);
    });
  }

  function paintTreeLevel(parentPath, ul, dirs) {
    ul.innerHTML = dirs.length ? '' : '<li class="fm-tree-msg">(empty)</li>';
    dirs.forEach(function (d) {
      var li = document.createElement('li');
      li.className = 'fm-node' + (state.treeExpanded.has(d.path) ? ' is-open' : '');
      var open = state.treeExpanded.has(d.path);
      li.innerHTML =
        '<div class="fm-node-label" data-path="' + escapeHtml(d.path) + '">' +
        '<button type="button" class="fm-node-toggle" aria-label="Expand"><i class="fa-solid fa-caret-right"></i></button>' +
        '<i class="fa-solid ' + (open ? 'fa-folder-open' : 'fa-folder') + '"></i>' +
        '<span class="fm-node-name">' + escapeHtml(d.name) + '</span></div><ul></ul>';
      ul.appendChild(li);
      var childUl = li.querySelector('ul');
      if (open) loadTreeLevel(d.path, childUl);
    });
  }

  function toggleTreeNode(li, path) {
    var childUl = li.querySelector('ul');
    if (state.treeExpanded.has(path)) {
      state.treeExpanded.delete(path);
      li.classList.remove('is-open');
      childUl.innerHTML = '';
    } else {
      state.treeExpanded.add(path);
      li.classList.add('is-open');
      loadTreeLevel(path, childUl);
    }
  }

  function updateTreeSelection() {
    Array.prototype.forEach.call(el.tree.querySelectorAll('.fm-node-label'), function (n) {
      n.classList.toggle('is-current', n.getAttribute('data-path') === state.path);
    });
    // Ensure ancestors of the current path are expanded/loaded lazily as the user navigates.
    var parts = state.path ? state.path.split('/') : [];
    var acc = '';
    var chain = [''];
    parts.forEach(function (p) { acc = joinPath(acc, p); chain.push(acc); });
    chain.slice(0, -1).forEach(function (p) { state.treeExpanded.add(p); });
  }

  // ------------------------------------------------------------------
  // Search
  // ------------------------------------------------------------------

  function runSearch() {
    var q = el.searchInput.value.trim();
    if (!q) { state.searching = false; return load(state.path); }
    state.searching = true;
    root.setAttribute('data-state', 'loading');
    return apiGet(CFG.urls.search, { path: state.path, q: q, hidden: state.showHidden ? '1' : '0' }).then(function (j) {
      root.setAttribute('data-state', 'ready');
      if (!j.ok) { toast('error', errorMessage(j, 'Search failed.')); return; }
      state.items = j.data.items;
      state.selected.clear();
      el.crumbs.innerHTML = '<span class="fm-sep">Search results for \u201c' + escapeHtml(j.data.query) + '\u201d in /' + escapeHtml(state.path) + '</span>';
      renderRows();
      renderStatus(j.data.truncated);
    });
  }

  // ------------------------------------------------------------------
  // Modal system
  // ------------------------------------------------------------------

  var activeModal = null;

  function openModal(opts) {
    closeModal();
    var backdrop = document.createElement('div');
    backdrop.className = 'fmx-backdrop';
    var modal = document.createElement('div');
    modal.className = 'fmx-modal' + (opts.wide ? ' is-wide' : '') + (opts.editor ? ' is-editor' : '');
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.innerHTML =
      '<div class="fmx-head"><h2>' + escapeHtml(opts.title) + '</h2><button type="button" class="fmx-x" aria-label="Close">\u2715</button></div>' +
      '<div class="fmx-body">' + opts.body + '</div>' +
      (opts.foot ? '<div class="fmx-foot">' + opts.foot + '</div>' : '');
    backdrop.appendChild(modal);
    document.body.appendChild(backdrop);
    activeModal = backdrop;

    backdrop.addEventListener('mousedown', function (e) { if (e.target === backdrop && !opts.pinned) closeModal(); });
    modal.querySelector('.fmx-x').addEventListener('click', function () { closeModal(); });
    document.addEventListener('keydown', escHandler);

    if (opts.onMount) opts.onMount(modal);
    var f = modal.querySelector('[autofocus]') || modal.querySelector('input,textarea,button.is-primary');
    if (f) f.focus();
    if (f && f.select) try { f.select(); } catch (e) {}
    return modal;
  }
  function escHandler(e) { if (e.key === 'Escape') closeModal(); }
  function closeModal() {
    if (activeModal) { activeModal.remove(); activeModal = null; document.removeEventListener('keydown', escHandler); }
  }

  function promptModal(opts) {
    // opts: {title, label, value, submitLabel, validate(value)->error|null, onSubmit(value)}
    var modal = openModal({
      title: opts.title,
      body: '<label for="fmx-input">' + escapeHtml(opts.label) + '</label><input type="text" id="fmx-input" autofocus value="' + escapeHtml(opts.value || '') + '"><div class="fmx-err" id="fmx-err" hidden></div>',
      foot: '<button type="button" class="fmx-btn" data-close>Cancel</button><button type="button" class="fmx-btn is-primary" id="fmx-ok">' + escapeHtml(opts.submitLabel || 'Save') + '</button>',
    });
    var input = modal.querySelector('#fmx-input');
    var err = modal.querySelector('#fmx-err');
    modal.querySelector('[data-close]').addEventListener('click', closeModal);
    function submit() {
      var v = input.value.trim();
      var problem = opts.validate ? opts.validate(v) : (v ? null : 'This field is required.');
      if (problem) { err.hidden = false; err.textContent = problem; return; }
      err.hidden = true;
      opts.onSubmit(v, modal);
    }
    modal.querySelector('#fmx-ok').addEventListener('click', submit);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') submit(); });
    return modal;
  }

  function confirmModal(opts) {
    // opts: {title, body, danger, submitLabel, onConfirm}
    var modal = openModal({
      title: opts.title,
      body: opts.body,
      foot: '<button type="button" class="fmx-btn" data-close>Cancel</button><button type="button" class="fmx-btn ' + (opts.danger ? 'is-danger' : 'is-primary') + '" id="fmx-ok">' + escapeHtml(opts.submitLabel || 'Confirm') + '</button>',
    });
    modal.querySelector('[data-close]').addEventListener('click', closeModal);
    modal.querySelector('#fmx-ok').addEventListener('click', function () { opts.onConfirm(modal); });
    return modal;
  }

  function busy(modal, on) {
    Array.prototype.forEach.call(modal.querySelectorAll('button,input,textarea'), function (b) { b.disabled = on; });
  }
  function modalError(modal, msg) {
    var err = modal.querySelector('.fmx-err');
    if (!err) { err = document.createElement('div'); err.className = 'fmx-err'; modal.querySelector('.fmx-body').appendChild(err); }
    err.hidden = false; err.textContent = msg;
  }

  // ------------------------------------------------------------------
  // Actions: create / rename / delete / chmod / zip / unzip
  // ------------------------------------------------------------------

  function actNewFolder() {
    promptModal({
      title: 'New folder', label: 'Folder name', submitLabel: 'Create',
      validate: function (v) { return /[\/\\\x00-\x1f]/.test(v) ? 'Name cannot contain slashes.' : null; },
      onSubmit: function (name, modal) {
        busy(modal, true);
        apiPost('mkdir', { path: state.path, name: name }).then(function (j) {
          if (!j.ok) { busy(modal, false); modalError(modal, errorMessage(j)); return; }
          closeModal(); toast('ok', 'Folder created.'); refresh();
        });
      },
    });
  }

  function actNewFile() {
    promptModal({
      title: 'New file', label: 'File name', submitLabel: 'Create',
      validate: function (v) { return /[\/\\\x00-\x1f]/.test(v) ? 'Name cannot contain slashes.' : null; },
      onSubmit: function (name, modal) {
        busy(modal, true);
        apiPost('touch', { path: state.path, name: name }).then(function (j) {
          if (!j.ok) { busy(modal, false); modalError(modal, errorMessage(j)); return; }
          closeModal(); toast('ok', 'File created.'); refresh().then(function () { openEditor(j.data.path); });
        });
      },
    });
  }

  function actRename() {
    var item = selectedItems()[0];
    if (!item) return;
    promptModal({
      title: 'Rename', label: 'New name', value: item.name, submitLabel: 'Rename',
      validate: function (v) { return /[\/\\\x00-\x1f]/.test(v) ? 'Name cannot contain slashes.' : null; },
      onSubmit: function (name, modal) {
        busy(modal, true);
        apiPost('rename', { path: item.path, name: name }).then(function (j) {
          if (!j.ok) { busy(modal, false); modalError(modal, errorMessage(j)); return; }
          closeModal(); toast('ok', 'Renamed.'); refresh();
        });
      },
    });
  }

  function actDelete() {
    var items = selectedItems();
    if (!items.length) return;
    var list = items.slice(0, 12).map(function (i) { return '<li>' + escapeHtml(i.name) + '</li>'; }).join('');
    var more = items.length > 12 ? '<li>\u2026 and ' + (items.length - 12) + ' more</li>' : '';
    confirmModal({
      title: 'Delete ' + items.length + ' item' + (items.length === 1 ? '' : 's') + '?', danger: true, submitLabel: 'Delete',
      body: '<p>This cannot be undone.</p><ul class="fmx-list">' + list + more + '</ul>',
      onConfirm: function (modal) {
        busy(modal, true);
        apiPost('delete', { paths: items.map(function (i) { return i.path; }) }).then(function (j) {
          busy(modal, false);
          if (!j.ok) { modalError(modal, errorMessage(j)); return; }
          closeModal();
          if (j.data.errors && j.data.errors.length) toast('error', j.data.errors.length + ' item(s) could not be deleted.', j.data.errors[0].error);
          else toast('ok', 'Deleted.');
          refresh();
        });
      },
    });
  }

  function actChmod() {
    var item = selectedItems()[0];
    if (!item) return;
    var cur = (item.perms || '0644').slice(-3).split('').map(Number);
    var rows = [
      ['Owner', 0], ['Group', 1], ['Others', 2],
    ];
    var grid = '<div class="fmx-perm-grid"><span></span><span class="h">Read</span><span class="h">Write</span><span class="h">Exec</span>' +
      rows.map(function (r) {
        var bits = cur[r[1]] || 0;
        return '<label>' + r[0] + '</label>' +
          ['r', 'w', 'x'].map(function (b, i) {
            var on = (bits & (4 >> i)) !== 0;
            return '<input type="checkbox" data-r="' + r[1] + '" data-b="' + i + '" ' + (on ? 'checked' : '') + '>';
          }).join('');
      }).join('') + '</div>';
    var modal = openModal({
      title: 'Permissions \u2014 ' + item.name,
      body: grid + '<label for="fmx-oct">Octal</label><input type="text" id="fmx-oct" value="' + escapeHtml((item.perms || '0644').slice(-3)) + '"><div class="fmx-err" hidden></div>',
      foot: '<button type="button" class="fmx-btn" data-close>Cancel</button><button type="button" class="fmx-btn is-primary" id="fmx-ok">Apply</button>',
    });
    var octInput = modal.querySelector('#fmx-oct');
    function fromGrid() {
      var v = [0, 0, 0];
      Array.prototype.forEach.call(modal.querySelectorAll('input[type=checkbox]'), function (cb) {
        var r = +cb.getAttribute('data-r'), b = +cb.getAttribute('data-b');
        if (cb.checked) v[r] |= (4 >> b);
      });
      octInput.value = v.join('');
    }
    function toGrid() {
      var digits = octInput.value.replace(/[^0-7]/g, '').slice(-3).padStart(3, '0').split('').map(Number);
      Array.prototype.forEach.call(modal.querySelectorAll('input[type=checkbox]'), function (cb) {
        var r = +cb.getAttribute('data-r'), b = +cb.getAttribute('data-b');
        cb.checked = (digits[r] & (4 >> b)) !== 0;
      });
    }
    Array.prototype.forEach.call(modal.querySelectorAll('input[type=checkbox]'), function (cb) { cb.addEventListener('change', fromGrid); });
    octInput.addEventListener('input', toGrid);
    modal.querySelector('[data-close]').addEventListener('click', closeModal);
    modal.querySelector('#fmx-ok').addEventListener('click', function () {
      var mode = octInput.value.trim();
      if (!/^[0-7]{3,4}$/.test(mode)) { modalError(modal, 'Enter 3 or 4 octal digits, e.g. 644.'); return; }
      busy(modal, true);
      apiPost('chmod', { path: item.path, mode: mode }).then(function (j) {
        if (!j.ok) { busy(modal, false); modalError(modal, errorMessage(j)); return; }
        closeModal(); toast('ok', 'Permissions updated.'); refresh();
      });
    });
  }

  function actZip() {
    var items = selectedItems();
    if (!items.length) return;
    promptModal({
      title: 'Compress ' + items.length + ' item' + (items.length === 1 ? '' : 's'),
      label: 'Archive name', value: (items.length === 1 ? items[0].name.replace(/\.[^.]+$/, '') : 'archive'), submitLabel: 'Compress',
      onSubmit: function (name, modal) {
        busy(modal, true);
        apiPost('zip', { paths: items.map(function (i) { return i.path; }), dest: state.path, name: name }).then(function (j) {
          if (!j.ok) { busy(modal, false); modalError(modal, errorMessage(j)); return; }
          closeModal(); toast('ok', 'Archive created.'); refresh();
        });
      },
    });
  }

  function actUnzip() {
    var item = selectedItems()[0];
    if (!item) return;
    confirmModal({
      title: 'Extract ' + item.name, submitLabel: 'Extract',
      body: '<p>Extract into the current folder (<code>/' + escapeHtml(state.path) + '</code>).</p>' +
        '<label style="font-weight:400;display:flex;align-items:center;gap:.4rem"><input type="checkbox" id="fmx-ow"> Overwrite existing files</label>',
      onConfirm: function (modal) {
        busy(modal, true);
        var overwrite = modal.querySelector('#fmx-ow').checked;
        apiPost('unzip', { path: item.path, dest: state.path, overwrite: overwrite }).then(function (j) {
          busy(modal, false);
          if (!j.ok) { modalError(modal, errorMessage(j)); return; }
          closeModal();
          var skipped = j.data.skipped || [];
          toast('ok', 'Extracted ' + j.data.extracted + ' item(s).' + (skipped.length ? ' ' + skipped.length + ' skipped.' : ''));
          refresh();
        });
      },
    });
  }

  // ------------------------------------------------------------------
  // Move / Copy: destination folder picker (reuses the tree logic)
  // ------------------------------------------------------------------

  function actMoveCopy(kind) {
    var items = selectedItems();
    if (!items.length) return;
    var chosen = state.path;
    var modal = openModal({
      title: (kind === 'move' ? 'Move' : 'Copy') + ' ' + items.length + ' item' + (items.length === 1 ? '' : 's'),
      wide: true,
      body: '<p>Choose a destination folder:</p><div class="fmx-tree" id="fmx-tree"><ul class="fm-root" id="fmx-tree-root"></ul></div><div class="fmx-err" hidden></div>',
      foot: '<button type="button" class="fmx-btn" data-close>Cancel</button><button type="button" class="fmx-btn is-primary" id="fmx-ok">' + (kind === 'move' ? 'Move' : 'Copy') + ' here</button>',
    });
    var treeRoot = modal.querySelector('#fmx-tree-root');
    function paint(path, ul, dirs) {
      ul.innerHTML = '';
      var rootLi = null;
      if (path === '') {
        rootLi = document.createElement('li');
        rootLi.className = 'fm-node is-open';
        rootLi.innerHTML = '<div class="fm-node-label' + (chosen === '' ? ' is-current' : '') + '" data-path=""><button type="button" class="fm-node-toggle"><i class="fa-solid fa-caret-right"></i></button><i class="fa-solid fa-folder-open"></i><span class="fm-node-name">/ (root)</span></div><ul></ul>';
        ul.appendChild(rootLi);
        ul = rootLi.querySelector('ul');
      }
      dirs.forEach(function (d) {
        var moving = kind === 'move' && items.some(function (i) { return i.is_dir && (d.path === i.path || d.path.indexOf(i.path + '/') === 0); });
        var li = document.createElement('li');
        li.className = 'fm-node';
        li.innerHTML = '<div class="fm-node-label' + (chosen === d.path ? ' is-current' : '') + (moving ? '" style="opacity:.4;pointer-events:none' : '') + '" data-path="' + escapeHtml(d.path) + '"><button type="button" class="fm-node-toggle"><i class="fa-solid fa-caret-right"></i></button><i class="fa-solid fa-folder"></i><span class="fm-node-name">' + escapeHtml(d.name) + '</span></div><ul></ul>';
        ul.appendChild(li);
      });
    }
    function loadLevel(path, ul) {
      return apiGet(CFG.urls.list, { path: path, hidden: '0' }).then(function (j) {
        if (!j.ok) return;
        paint(path, ul, j.data.items.filter(function (i) { return i.is_dir && i.link_ok !== false; }));
      });
    }
    loadLevel('', treeRoot);
    treeRoot.addEventListener('click', function (e) {
      var toggle = e.target.closest('.fm-node-toggle');
      var label = e.target.closest('.fm-node-label');
      if (!label) return;
      var path = label.getAttribute('data-path');
      if (toggle) {
        var li = label.parentElement;
        var childUl = li.querySelector('ul');
        if (li.classList.contains('is-open')) { li.classList.remove('is-open'); childUl.innerHTML = ''; }
        else { li.classList.add('is-open'); loadLevel(path, childUl); }
        return;
      }
      chosen = path;
      Array.prototype.forEach.call(modal.querySelectorAll('.fm-node-label'), function (n) { n.classList.toggle('is-current', n.getAttribute('data-path') === path); });
    });
    modal.querySelector('[data-close]').addEventListener('click', closeModal);
    modal.querySelector('#fmx-ok').addEventListener('click', function () {
      busy(modal, true);
      apiPost(kind, { paths: items.map(function (i) { return i.path; }), dest: chosen }).then(function (j) {
        busy(modal, false);
        if (!j.ok) { modalError(modal, errorMessage(j)); return; }
        closeModal();
        if (j.data.errors && j.data.errors.length) toast('error', j.data.errors.length + ' item(s) could not be ' + (kind === 'move' ? 'moved' : 'copied') + '.', j.data.errors[0].error);
        else toast('ok', (kind === 'move' ? 'Moved.' : 'Copied.'));
        refresh();
      });
    });
  }

  // ------------------------------------------------------------------
  // Image preview
  // ------------------------------------------------------------------

  function openPreview(item) {
    openModal({
      title: item.name, wide: true,
      body: '<div class="fmx-preview"><img src="' + CFG.urls.download + '?path=' + encodeURIComponent(item.path) + '&inline=1" alt=""></div>',
      foot: '<button type="button" class="fmx-btn" data-close>Close</button><button type="button" class="fmx-btn is-primary" id="fmx-dl">Download</button>',
    });
    document.querySelector('.fmx-modal .fmx-foot [data-close]').addEventListener('click', closeModal);
    document.querySelector('#fmx-dl').addEventListener('click', function () { downloadItem(item); });
  }

  // ------------------------------------------------------------------
  // Editor
  // ------------------------------------------------------------------

  function openEditor(path) {
    apiGet(CFG.urls.read, { path: path }).then(function (j) {
      if (!j.ok) { toast('error', errorMessage(j, 'Could not open this file.')); return; }
      var d = j.data;
      state.editing = { path: d.path, mtime: d.mtime, original: d.content, writable: d.writable };
      var modal = openModal({
        title: d.name, editor: true, pinned: true,
        body:
          '<div class="fmx-editor-bar">' +
          (d.writable ? '' : '<span class="fmx-note"><i class="fa-solid fa-lock"></i>&nbsp; Read-only</span>') +
          '<label><input type="checkbox" id="fmx-wrap"> Wrap</label>' +
          '<div class="spacer"></div>' +
          '<span id="fmx-conflict" class="fmx-note" hidden><i class="fa-solid fa-triangle-exclamation"></i>&nbsp; Changed on disk since you opened it.</span>' +
          '</div>' +
          '<div class="fmx-editor-wrap"><div class="fmx-gutter" id="fmx-gutter">1</div><textarea class="fmx-text" id="fmx-text" spellcheck="false" ' + (d.writable ? '' : 'readonly') + '>' + escapeHtml(d.content) + '</textarea></div>' +
          '<div class="fmx-editor-foot"><span id="fmx-count"></span><span id="fmx-dirty" class="fmx-dirty" hidden>Unsaved changes</span></div>',
        foot: d.writable
          ? '<button type="button" class="fmx-btn" data-close>Close</button><button type="button" class="fmx-btn is-primary" id="fmx-save">Save <span style="opacity:.7">(Ctrl+S)</span></button>'
          : '<button type="button" class="fmx-btn is-primary" data-close>Close</button>',
        onMount: function (modal) { setupEditor(modal, d); },
      });
      modal.querySelector('.fmx-head .fmx-x').addEventListener('click', function () { closeEditorGuarded(modal); });
      var closeBtn = modal.querySelector('[data-close]');
      if (closeBtn) closeBtn.addEventListener('click', function () { closeEditorGuarded(modal); });
    });
  }

  function setupEditor(modal, d) {
    var ta = modal.querySelector('#fmx-text');
    var gutter = modal.querySelector('#fmx-gutter');
    var dirty = modal.querySelector('#fmx-dirty');
    var count = modal.querySelector('#fmx-count');
    var wrap = modal.querySelector('#fmx-wrap');

    function updateGutter() {
      var lines = ta.value.split('\n').length;
      var buf = [];
      for (var i = 1; i <= lines; i++) buf.push(i);
      gutter.textContent = buf.join('\n');
    }
    function updateMeta() {
      var isDirty = ta.value !== state.editing.original;
      dirty.hidden = !isDirty;
      count.textContent = ta.value.length.toLocaleString() + ' characters, ' + ta.value.split('\n').length.toLocaleString() + ' lines';
    }
    ta.addEventListener('input', function () { updateGutter(); updateMeta(); });
    ta.addEventListener('scroll', function () { gutter.scrollTop = ta.scrollTop; });
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Tab') { e.preventDefault(); var s = ta.selectionStart, en = ta.selectionEnd; ta.value = ta.value.slice(0, s) + '\t' + ta.value.slice(en); ta.selectionStart = ta.selectionEnd = s + 1; updateGutter(); updateMeta(); }
      if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); if (d.writable) save(); }
    });
    wrap.addEventListener('change', function () { ta.classList.toggle('is-wrap', wrap.checked); });
    updateGutter(); updateMeta();

    var saveBtn = modal.querySelector('#fmx-save');
    function save(force) {
      if (!saveBtn) return;
      saveBtn.disabled = true;
      apiPost('save', { path: state.editing.path, content: ta.value, mtime: state.editing.mtime, force: !!force }).then(function (j) {
        saveBtn.disabled = false;
        if (!j.ok) {
          if (j.reason === '' && j.__status === 409) {
            modal.querySelector('#fmx-conflict').hidden = false;
            confirmModal({
              title: 'File changed on disk', danger: true, submitLabel: 'Overwrite anyway',
              body: '<p>Someone (or something) changed this file after you opened it. Overwrite it with your version, or close without saving to keep the version on disk.</p>',
              onConfirm: function (m2) { closeModal(); save(true); },
            });
            return;
          }
          toast('error', errorMessage(j, 'Could not save.'));
          return;
        }
        state.editing.mtime = j.data.mtime;
        state.editing.original = ta.value;
        updateMeta();
        toast('ok', 'Saved.');
        refresh();
      });
    }
    if (saveBtn) saveBtn.addEventListener('click', function () { save(false); });
  }

  function closeEditorGuarded(modal) {
    var ta = modal.querySelector('#fmx-text');
    if (ta && !ta.readOnly && ta.value !== state.editing.original) {
      confirmModal({
        title: 'Discard changes?', danger: true, submitLabel: 'Discard',
        body: '<p>You have unsaved changes. Close without saving?</p>',
        onConfirm: function () { closeModal(); },
      });
      return;
    }
    closeModal();
  }

  // ------------------------------------------------------------------
  // Upload
  // ------------------------------------------------------------------

  var uploadPanel = { open: false };

  function ensureUploadPanel() {
    if (uploadPanel.open) return;
    uploadPanel.open = true;
    el.uploads.hidden = false;
    el.uploads.innerHTML = '<div class="fm-up-head"><span>Uploads</span><button type="button" id="fm-up-close">\u2715</button></div><div id="fm-up-list"></div>';
    document.getElementById('fm-up-close').addEventListener('click', function () { el.uploads.hidden = true; uploadPanel.open = false; });
  }

  function uploadFiles(fileList, destPath) {
    var files = Array.prototype.slice.call(fileList);
    if (!files.length) return;
    ensureUploadPanel();
    var list = document.getElementById('fm-up-list');
    var touched = false;

    files.forEach(function (file) {
      var row = document.createElement('div');
      row.className = 'fm-up-item';
      row.innerHTML = '<div class="fm-up-name"><span>' + escapeHtml(file.name) + '</span><span class="fm-up-state">0%</span></div><div class="fm-bar"><i></i></div>';
      list.prepend(row);
      var stateEl = row.querySelector('.fm-up-state');
      var bar = row.querySelector('.fm-bar > i');

      var xhr = new XMLHttpRequest();
      var fd = new FormData();
      fd.append('files[]', file, file.name);
      fd.append('path', destPath);
      xhr.open('POST', CFG.urls.upload, true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('X-CSRF-Token', token);
      xhr.upload.addEventListener('progress', function (e) {
        if (e.lengthComputable) { var pct = Math.round((e.loaded / e.total) * 100); bar.style.width = pct + '%'; stateEl.textContent = pct + '%'; }
      });
      xhr.addEventListener('load', function () {
        var j = {}; try { j = JSON.parse(xhr.responseText); } catch (e) {}
        if (xhr.status === 403 && j.reason === 'csrf') {
          refreshToken().then(function () { list.removeChild(row); uploadFiles([file], destPath); });
          return;
        }
        if (j.ok && j.data.done && j.data.done.length) {
          row.classList.add('is-done'); bar.style.width = '100%'; stateEl.textContent = 'Done';
          if (destPath === state.path) touched = true;
        } else {
          row.classList.add('is-error');
          var msg = (j.data && j.data.errors && j.data.errors[0] && j.data.errors[0].error) || j.error || 'Failed';
          stateEl.textContent = 'Failed';
          var errLine = document.createElement('div'); errLine.className = 'fm-up-err'; errLine.textContent = msg; row.appendChild(errLine);
        }
        if (touched) refresh();
      });
      xhr.addEventListener('error', function () { row.classList.add('is-error'); stateEl.textContent = 'Network error'; });
      xhr.send(fd);
    });
  }

  // ------------------------------------------------------------------
  // Context menu
  // ------------------------------------------------------------------

  function showMenu(x, y, entries) {
    el.menu.innerHTML = entries.map(function (e) {
      if (e === '-') return '<hr>';
      return '<button type="button" data-cmd="' + e.cmd + '"' + (e.danger ? ' class="is-danger"' : '') + (e.disabled ? ' disabled' : '') + '><i class="' + e.icon + '"></i>' + escapeHtml(e.label) + '</button>';
    }).join('');
    el.menu.hidden = false;
    var rect = el.menu.getBoundingClientRect();
    var vw = window.innerWidth, vh = window.innerHeight;
    el.menu.style.left = Math.min(x, vw - rect.width - 8) + 'px';
    el.menu.style.top = Math.min(y, vh - rect.height - 8) + 'px';
  }
  function hideMenu() { el.menu.hidden = true; }

  el.menu.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-cmd]');
    if (!btn || btn.disabled) return;
    hideMenu();
    dispatch(btn.getAttribute('data-cmd'));
  });

  function dispatch(cmd) {
    switch (cmd) {
      case 'open': var it = selectedItems()[0]; if (it) open(it); break;
      case 'download': var d = selectedItems()[0]; if (d) downloadItem(d); break;
      case 'rename': actRename(); break;
      case 'delete': actDelete(); break;
      case 'copy': actMoveCopy('copy'); break;
      case 'move': actMoveCopy('move'); break;
      case 'zip': actZip(); break;
      case 'unzip': actUnzip(); break;
      case 'chmod': actChmod(); break;
      case 'new-file': actNewFile(); break;
      case 'new-folder': actNewFolder(); break;
      case 'upload': el.fileInput.click(); break;
      case 'refresh': refresh(); break;
    }
  }

  // ------------------------------------------------------------------
  // Wiring
  // ------------------------------------------------------------------

  el.toolbar.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-act]');
    if (!btn || btn.disabled) return;
    dispatch(btn.getAttribute('data-act'));
  });

  el.rows.addEventListener('click', function (e) {
    var tr = e.target.closest('tr.fm-row');
    if (!tr) return;
    var path = tr.getAttribute('data-path');
    var item = state.items.filter(function (i) { return i.path === path; })[0];
    if (e.target.closest('input[type=checkbox]')) { toggleSelect(path, true, false); return; }
    if (e.target.closest('.fm-name')) {
      if (e.detail === 2) { if (item) open(item); return; }
      toggleSelect(path, e.ctrlKey || e.metaKey, e.shiftKey);
    }
  });
  el.rows.addEventListener('contextmenu', function (e) {
    var tr = e.target.closest('tr.fm-row');
    if (!tr) return;
    e.preventDefault();
    var path = tr.getAttribute('data-path');
    if (!state.selected.has(path)) toggleSelect(path, false, false);
    var n = state.selected.size, one = n === 1, items = selectedItems();
    showMenu(e.clientX, e.clientY, [
      { cmd: 'open', label: items[0] && items[0].is_dir ? 'Open' : 'Open / Edit', icon: 'fa-solid fa-folder-open', disabled: !one },
      { cmd: 'download', label: 'Download', icon: 'fa-solid fa-arrow-down-to-bracket', disabled: !(one && !items[0].is_dir) },
      '-',
      { cmd: 'rename', label: 'Rename', icon: 'fa-solid fa-i-cursor', disabled: !one },
      { cmd: 'copy', label: 'Copy to\u2026', icon: 'fa-regular fa-copy' },
      { cmd: 'move', label: 'Move to\u2026', icon: 'fa-solid fa-arrow-right-arrow-left' },
      { cmd: 'zip', label: 'Compress', icon: 'fa-solid fa-file-zipper' },
      { cmd: 'unzip', label: 'Extract here', icon: 'fa-solid fa-box-open', disabled: !(one && items[0].zip) },
      { cmd: 'chmod', label: 'Permissions', icon: 'fa-solid fa-lock', disabled: !one },
      '-',
      { cmd: 'delete', label: 'Delete', icon: 'fa-regular fa-trash-can', danger: true },
    ]);
  });
  el.scroll.addEventListener('contextmenu', function (e) {
    if (e.target.closest('tr.fm-row')) return;
    e.preventDefault();
    state.selected.clear(); renderRows();
    showMenu(e.clientX, e.clientY, [
      { cmd: 'new-file', label: 'New file', icon: 'fa-solid fa-file-circle-plus' },
      { cmd: 'new-folder', label: 'New folder', icon: 'fa-solid fa-folder-plus' },
      { cmd: 'upload', label: 'Upload here', icon: 'fa-solid fa-arrow-up-from-bracket' },
      '-',
      { cmd: 'refresh', label: 'Refresh', icon: 'fa-solid fa-rotate' },
    ]);
  });
  document.addEventListener('click', function (e) { if (!e.target.closest('#fm-menu')) hideMenu(); });
  document.addEventListener('scroll', hideMenu, true);

  el.all.addEventListener('change', function () {
    if (el.all.checked) state.items.forEach(function (i) { state.selected.add(i.path); });
    else state.selected.clear();
    renderRows();
  });

  Array.prototype.forEach.call(el.table.querySelectorAll('th[data-sort]'), function (th) {
    th.addEventListener('click', function () {
      var key = th.getAttribute('data-sort');
      if (state.sortKey === key) state.sortDir *= -1; else { state.sortKey = key; state.sortDir = 1; }
      renderRows();
    });
  });

  el.crumbs.addEventListener('click', function (e) {
    var b = e.target.closest('.fm-crumb');
    if (b) load(b.getAttribute('data-path'));
  });
  el.up.addEventListener('click', function () { load(parentOf(state.path)); });

  el.hidden.checked = state.showHidden;
  el.hidden.addEventListener('change', function () { state.showHidden = el.hidden.checked; state.treeCache = {}; renderTree(); refresh(); });

  el.searchForm.addEventListener('submit', function (e) { e.preventDefault(); runSearch(); });
  el.searchInput.addEventListener('input', debounce(function () { if (el.searchInput.value.trim() === '' && state.searching) load(state.path); else if (el.searchInput.value.trim()) runSearch(); }, 350));

  el.tree.addEventListener('click', function (e) {
    var toggle = e.target.closest('.fm-node-toggle');
    var label = e.target.closest('.fm-node-label');
    if (!label) return;
    var path = label.getAttribute('data-path');
    if (toggle) { toggleTreeNode(label.parentElement, path); return; }
    load(path);
  });

  el.fileInput.addEventListener('change', function () { uploadFiles(el.fileInput.files, state.path); el.fileInput.value = ''; });

  // Drag & drop upload, with folder/crumb/tree hover targeting.
  var dragCounter = 0;
  function isFileDrag(e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1; }
  root.addEventListener('dragenter', function (e) { if (!isFileDrag(e)) return; e.preventDefault(); dragCounter++; el.drop.hidden = false; el.dropTarget.textContent = '/' + state.path; });
  root.addEventListener('dragover', function (e) { if (!isFileDrag(e)) return; e.preventDefault(); });
  root.addEventListener('dragleave', function (e) { if (!isFileDrag(e)) return; dragCounter = Math.max(0, dragCounter - 1); if (dragCounter === 0) el.drop.hidden = true; });
  root.addEventListener('drop', function (e) {
    if (!isFileDrag(e)) return;
    e.preventDefault(); dragCounter = 0; el.drop.hidden = true;
    var target = state.path;
    var rowTarget = e.target.closest('tr.fm-row[data-dir="1"]');
    if (rowTarget) target = rowTarget.getAttribute('data-path');
    uploadFiles(e.dataTransfer.files, target);
  });

  // Drag rows to move (onto a folder row, a tree node, or a breadcrumb).
  el.rows.addEventListener('dragstart', function (e) {
    var tr = e.target.closest('tr.fm-row');
    if (!tr) return;
    var path = tr.getAttribute('data-path');
    if (!state.selected.has(path)) toggleSelect(path, false, false);
    e.dataTransfer.setData('application/x-fm-paths', JSON.stringify(selectionPaths()));
    e.dataTransfer.effectAllowed = 'move';
  });
  function isInternalDrag(e) { return e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'application/x-fm-paths') !== -1; }
  function wireDropTarget(container, selector, getPath) {
    container.addEventListener('dragover', function (e) {
      var t = e.target.closest(selector);
      if (!t || !isInternalDrag(e)) return;
      e.preventDefault();
      t.classList.add('fm-drop-over');
    });
    container.addEventListener('dragleave', function (e) { var t = e.target.closest(selector); if (t) t.classList.remove('fm-drop-over'); });
    container.addEventListener('drop', function (e) {
      var t = e.target.closest(selector);
      if (!t || !isInternalDrag(e)) return;
      e.preventDefault();
      t.classList.remove('fm-drop-over');
      var paths;
      try { paths = JSON.parse(e.dataTransfer.getData('application/x-fm-paths')); } catch (err) { return; }
      var dest = getPath(t);
      if (!paths || !paths.length) return;
      apiPost('move', { paths: paths, dest: dest }).then(function (j) {
        if (!j.ok) { toast('error', errorMessage(j, 'Move failed.')); return; }
        if (j.data.errors && j.data.errors.length) toast('error', j.data.errors.length + ' item(s) could not be moved.', j.data.errors[0].error);
        else toast('ok', 'Moved.');
        refresh();
      });
    });
  }
  wireDropTarget(el.rows, 'tr.fm-row[data-dir="1"]', function (t) { return t.getAttribute('data-path'); });
  wireDropTarget(el.crumbs, '.fm-crumb', function (t) { return t.getAttribute('data-path'); });
  wireDropTarget(el.tree, '.fm-node-label', function (t) { return t.getAttribute('data-path'); });

  // Keyboard shortcuts (only while no modal is open and no input is focused).
  document.addEventListener('keydown', function (e) {
    if (activeModal) return;
    var tag = (document.activeElement && document.activeElement.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    if (e.key === 'Delete' && state.selected.size) { actDelete(); return; }
    if (e.key === 'F2' && state.selected.size === 1) { actRename(); return; }
    if (e.key === 'Backspace') { load(parentOf(state.path)); return; }
    if ((e.ctrlKey || e.metaKey) && e.key === 'a') { e.preventDefault(); state.items.forEach(function (i) { state.selected.add(i.path); }); renderRows(); return; }
    if (e.key === 'Enter' && state.selected.size === 1) { var it = selectedItems()[0]; open(it); return; }
    if (e.key === 'Escape' && state.selected.size) { state.selected.clear(); renderRows(); }
  });

  window.addEventListener('beforeunload', function (e) {
    if (state.editing && activeModal) {
      var ta = document.getElementById('fmx-text');
      if (ta && !ta.readOnly && ta.value !== state.editing.original) { e.preventDefault(); e.returnValue = ''; }
    }
  });

  // ------------------------------------------------------------------
  // Boot
  // ------------------------------------------------------------------

  if (CFG.warning) showBanner(CFG.warning);
  renderTree();
  load(state.path);
})();
