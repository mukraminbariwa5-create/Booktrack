/* BookTrack — small progressive enhancements. Every page works without JS. */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const csrf = () => ($('meta[name="csrf-token"]') || {}).content || '';
  const REDUCED = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.addEventListener('DOMContentLoaded', () => {
    // ---- mobile navigation
    const app = $('#app');
    $$('[data-nav-toggle]').forEach((b) => b.addEventListener('click', () => {
      if (!app) return;
      const open = app.classList.toggle('nav-open');
      $$('.menu-btn').forEach((m) => m.setAttribute('aria-expanded', open ? 'true' : 'false'));
    }));
    document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && app) app.classList.remove('nav-open'); });

    // ---- top bar gets a hairline once the page scrolls under it
    const topbar = $('[data-topbar]');
    if (topbar) {
      const onScroll = () => topbar.classList.toggle('is-scrolled', window.scrollY > 4);
      onScroll();
      window.addEventListener('scroll', onScroll, { passive: true });
    }

    // ---- flash messages: dismiss + auto hide
    $$('.flash').forEach((f, i) => armFlash(f, 5000 + i * 800));

    // ---- modals: [data-open="id"] opens <dialog id>, [data-close] closes
    $$('[data-open]').forEach((btn) => btn.addEventListener('click', (ev) => {
      const dlg = document.getElementById(btn.dataset.open);
      if (!dlg) return;
      ev.preventDefault();
      if (btn.dataset.fill) fillForm(dlg, JSON.parse(btn.dataset.fill));
      else if (btn.dataset.reset !== undefined) resetForm(dlg);
      dlg.showModal();
      // focus the first field right away (a delayed focus could steal it from
      // a field the person has already started typing in)
      const first = $('input:not([type=hidden]), select, textarea', dlg);
      if (first) first.focus();
    }));
    $$('[data-close]').forEach((btn) => btn.addEventListener('click', () => btn.closest('dialog')?.close()));
    $$('dialog.modal').forEach((dlg) => dlg.addEventListener('click', (ev) => { if (ev.target === dlg) dlg.close(); }));
    $$('dialog.modal[data-autoopen]').forEach((dlg) => dlg.showModal());

    // ---- confirmation for destructive actions: <form data-confirm="Text" data-confirm-title="..">
    const confirmDlg = $('#confirm-dialog');
    $$('form[data-confirm]').forEach((form) => form.addEventListener('submit', (ev) => {
      if (form.dataset.confirmed === '1' || !confirmDlg) return;
      ev.preventDefault();
      $('[data-confirm-title]', confirmDlg).textContent = form.dataset.confirmTitle || 'Are you sure?';
      $('[data-confirm-text]', confirmDlg).textContent = form.dataset.confirm;
      $('[data-confirm-ok]', confirmDlg).textContent = form.dataset.confirmOk || 'Confirm';
      confirmDlg.returnValue = '';
      confirmDlg.showModal();
      confirmDlg.addEventListener('close', function onClose() {
        confirmDlg.removeEventListener('close', onClose);
        if (confirmDlg.returnValue === 'ok') { form.dataset.confirmed = '1'; form.requestSubmit ? form.requestSubmit() : form.submit(); }
      });
    }));

    // ---- buttons that start slow work say so: <button data-busy="Working…">
    $$('button[data-busy]').forEach((b) => b.form && b.form.addEventListener('submit', () => {
      b.disabled = true;
      b.textContent = b.dataset.busy;
    }));

    // ---- favorites: toggle without reloading the page
    $$('form[data-favorite]').forEach((form) => form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      toggleFavorite(form, true);
    }));

    // ---- auto-submit filters (selects with data-autosubmit)
    $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form && el.form.submit()));

    // ---- cover preview on file choose
    $$('input[type=file][data-preview]').forEach((input) => input.addEventListener('change', () => {
      const img = document.getElementById(input.dataset.preview);
      const f = input.files && input.files[0];
      if (img && f && f.type.startsWith('image/')) { img.src = URL.createObjectURL(f); img.hidden = false; }
    }));

    // ---- demo sign-in links: fill the form and submit it
    $$('[data-demo-user]').forEach((b) => b.addEventListener('click', () => {
      const form = $('#username').form;
      $('#username').value = b.dataset.demoUser;
      $('#password').value = b.dataset.demoPass;
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }));

    // ---- show / hide password
    $$('[data-toggle-password]').forEach((b) => b.addEventListener('click', () => {
      const input = document.getElementById(b.getAttribute('aria-controls'));
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      b.textContent = show ? 'Hide' : 'Show';
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      input.focus();
    }));

    // ---- small inline help (e.g. "Forgot password?")
    $$('[data-toggle-help]').forEach((b) => b.addEventListener('click', () => {
      const help = document.getElementById(b.getAttribute('aria-controls'));
      const open = help.hidden;
      help.hidden = !open;
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
    }));

    // ---- move focus to the first field with an error
    const firstInvalid = $('[aria-invalid="true"]');
    if (firstInvalid) firstInvalid.focus();

    // ---- "/" jumps to the page's search box
    document.addEventListener('keydown', (ev) => {
      if (ev.key !== '/' || ev.ctrlKey || ev.metaKey || ev.target.closest('input, textarea, select, [contenteditable]')) return;
      const s = $('.search input');
      if (s) { ev.preventDefault(); s.focus(); }
    });
  });

  /** Fill a dialog's form from an object: keys match input names. */
  function fillForm(dlg, data) {
    const form = $('form', dlg);
    resetForm(dlg);
    Object.entries(data).forEach(([k, v]) => {
      let el = form.elements[k];
      if (!el) return;
      // a hidden fallback + checkbox share a name: target the checkbox
      if (el instanceof RadioNodeList) el = Array.from(el).find((n) => n.type === 'checkbox') || el[0];
      if (el.type === 'file') return;
      if (el.type === 'checkbox') el.checked = !!Number(v);
      else el.value = v ?? '';
    });
    $$('[data-when-edit]', dlg).forEach((n) => (n.hidden = false));
    $$('[data-when-create]', dlg).forEach((n) => (n.hidden = true));
    const t = $('[data-title-edit]', dlg);
    if (t) t.textContent = t.dataset.titleEdit;
    $$('[data-file-field]', dlg).forEach((ff) => setAttachment(ff, data));
  }

  function resetForm(dlg) {
    const form = $('form', dlg);
    if (!form) return;
    form.reset();
    $$('input[type=hidden]:not([name=_token]):not([name=action])', form).forEach((h) => (h.value = ''));
    $$('[data-when-edit]', dlg).forEach((n) => (n.hidden = true));
    $$('[data-when-create]', dlg).forEach((n) => (n.hidden = false));
    $$('img[data-preview-img]', dlg).forEach((i) => (i.hidden = true));
    const t = $('[data-title-edit]', dlg);
    if (t) t.textContent = t.dataset.titleCreate;
    $$('[data-file-field]', dlg).forEach((ff) => setAttachment(ff, {}));
  }

  /* ---- attachment fields (book cover / PDF): always show what is attached now,
     what a newly chosen file will do, and what "Remove" will do. */
  function setAttachment(ff, data) {
    const kind = ff.dataset.fileField;
    ff._state = {
      url: kind === 'cover' ? (data._cover_url || '') : (data._pdf_url || ''),
      size: data._pdf_size || '',
      file: null,
      objectUrl: null,
      remote: '',
    };
    const hiddenUrl = $('[data-cover-url]', ff);
    if (hiddenUrl) hiddenUrl.value = '';
    const input = $('[data-file-input]', ff);
    if (input) input.value = '';
    const rm = $('[data-file-remove]', ff);
    if (rm) rm.checked = false;
    renderAttachment(ff);
  }

  function renderAttachment(ff) {
    const st = ff._state;
    const kind = ff.dataset.fileField;
    const noun = kind === 'cover' ? 'cover' : 'PDF';
    const removing = !!($('[data-file-remove]', ff) || {}).checked;
    const status = $('[data-file-status]', ff);
    const note = $('[data-file-note]', ff);
    const pick = $('[data-file-pick-label]', ff);
    const rmWrap = $('[data-file-remove-wrap]', ff);
    const thumb = $('[data-file-thumb]', ff);
    const empty = $('[data-file-empty]', ff);
    ff.classList.toggle('is-removing', removing && !st.file);
    ff.classList.toggle('is-new', !!st.file);
    note.textContent = '';

    const hiddenUrl = $('[data-cover-url]', ff);
    if (hiddenUrl) hiddenUrl.value = st.file ? '' : (st.remote || '');
    ff.classList.toggle('is-new', !!st.file || !!st.remote);

    if (!st.file && st.remote) {
      status.textContent = 'Cover found on Open Library';
      note.textContent = (st.url ? 'It replaces the current cover' : 'It is added') + ' when you save.';
      pick.textContent = 'Upload instead';
    } else if (st.file) {
      status.textContent = kind === 'cover' ? 'New cover selected' : 'New PDF selected';
      note.textContent = st.file.name + ' (' + fmtBytes(st.file.size) + '). ' +
        (st.url ? 'It replaces the current ' + noun + ' when you save.' : 'It is added when you save.');
      pick.textContent = 'Choose another';
    } else if (st.url && removing) {
      status.textContent = kind === 'cover' ? 'Cover will be removed' : 'PDF will be removed';
      note.textContent = 'Happens when you save. Untick “Remove” to keep it.';
      pick.textContent = 'Replace';
    } else if (st.url) {
      status.textContent = kind === 'cover' ? 'Cover uploaded' : 'PDF attached';
      if (kind === 'cover') note.textContent = 'This is the cover readers see.';
      else {
        note.append((st.size ? st.size + ', ' : ''));
        const a = document.createElement('a');
        a.className = 'link'; a.href = st.url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'open it';
        note.append(a);
      }
      pick.textContent = 'Replace';
    } else {
      status.textContent = kind === 'cover' ? 'No cover yet' : 'No PDF attached';
      note.textContent = kind === 'cover' ? 'Without one, the book gets a styled cover.' : 'Members can read it online once added.';
      pick.textContent = kind === 'cover' ? 'Upload cover' : 'Upload PDF';
    }
    if (rmWrap) rmWrap.hidden = !st.url || !!st.file || !!st.remote;
    const undo = $('[data-file-undo]', ff);
    if (undo) undo.hidden = !st.file && !st.remote;

    if (thumb) {
      const src = st.objectUrl || st.remote || st.url;
      if (src) { thumb.src = src; thumb.hidden = false; } else { thumb.removeAttribute('src'); thumb.hidden = true; }
      if (empty) empty.hidden = !!src;
    }
  }

  function fmtBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return Math.round(b / 1024) + ' KB';
    return (b / 1048576).toFixed(1) + ' MB';
  }

  document.addEventListener('DOMContentLoaded', () => {
    $$('[data-file-field]').forEach((ff) => {
      // initial state comes from the server-rendered markup
      const kind = ff.dataset.fileField;
      const thumb = $('[data-file-thumb]', ff);
      const open = $('[data-file-open]', ff);
      ff._state = {
        url: ff.dataset.has === '1' ? (kind === 'cover' ? (thumb && thumb.getAttribute('src')) || '' : (open && open.getAttribute('href')) || '') : '',
        size: kind === 'pdf' && open ? (open.parentNode.textContent.split(',')[0] || '').trim() : '',
        file: null, objectUrl: null,
      };
      const input = $('[data-file-input]', ff);
      input.addEventListener('change', () => {
        const f = input.files && input.files[0];
        if (ff._state.objectUrl) URL.revokeObjectURL(ff._state.objectUrl);
        ff._state.file = f || null;
        if (f) ff._state.remote = '';
        ff._state.objectUrl = f && kind === 'cover' && f.type.startsWith('image/') ? URL.createObjectURL(f) : null;
        const rm = $('[data-file-remove]', ff);
        if (rm && f) rm.checked = false;
        renderAttachment(ff);
      });
      const rm = $('[data-file-remove]', ff);
      if (rm) rm.addEventListener('change', () => renderAttachment(ff));

      // "Find online": ask Open Library for a cover matching the form's ISBN / title / author
      const find = $('[data-cover-find]', ff);
      if (find) find.addEventListener('click', async () => {
        const form = ff.closest('form');
        const body = new FormData();
        ['title', 'author', 'isbn'].forEach((k) => body.append(k, (form.elements[k] || {}).value || ''));
        const label = find.innerHTML;
        find.disabled = true;
        find.textContent = 'Searching…';
        try {
          const res = await fetch(find.closest('[data-api-cover]') ? find.closest('[data-api-cover]').dataset.apiCover : 'api/cover.php', {
            method: 'POST', body,
            headers: { 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf(), Accept: 'application/json' },
          });
          const data = await res.json();
          if (!data.ok) throw new Error(data.error || 'No cover found.');
          if (ff._state.objectUrl) URL.revokeObjectURL(ff._state.objectUrl);
          ff._state.file = null; ff._state.objectUrl = null; ff._state.remote = data.url;
          input.value = '';
          if (rm) rm.checked = false;
          renderAttachment(ff);
        } catch (e) {
          toast(e.message, true);
        } finally {
          find.disabled = false;
          find.innerHTML = label;
        }
      });
      const undo = $('[data-file-undo]', ff);
      if (undo) undo.addEventListener('click', () => {
        input.value = '';
        if (ff._state.objectUrl) URL.revokeObjectURL(ff._state.objectUrl);
        ff._state.file = null; ff._state.objectUrl = null; ff._state.remote = '';
        renderAttachment(ff);
      });
    });
  });

  /** Toggle a favorite; the notification offers Undo, which toggles it back. */
  async function toggleFavorite(form, offerUndo) {
    const btn = $('button', form);
    const card = form.dataset.favorite === 'remove-card' ? form.closest('.book-card') : null;
    btn.disabled = true;
    try {
      const res = await fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf(), Accept: 'application/json' },
        body: new FormData(form),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Something went wrong');
      btn.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
      btn.classList.remove('is-popping'); void btn.offsetWidth; btn.classList.add('is-popping');
      const label = $('[data-fav-label]', btn);
      if (label) label.textContent = data.favorite ? 'Saved to favorites' : 'Add to favorites';
      if (card) card.classList.toggle('is-removed', !data.favorite);
      if (!offerUndo) return;
      const note = toast(data.favorite ? 'Added to your favorites' : 'Removed from favorites', false,
        { label: 'Undo', run: () => toggleFavorite(form, false) });
      // a removed card leaves the page for good once the chance to undo has passed
      if (card) note._onGone = () => { if (card.classList.contains('is-removed')) card.remove(); };
    } catch (e) {
      toast(e.message, true);
    } finally {
      btn.disabled = false;
    }
  }

  /** Show a notification. `action` adds a button, e.g. { label: 'Undo', run() {} }. */
  function toast(message, isError, action) {
    let box = $('.flashes');
    if (!box) { box = document.createElement('div'); box.className = 'flashes'; document.body.appendChild(box); }
    const el = document.createElement('div');
    el.className = 'flash' + (isError ? ' flash--error' : '');
    el.setAttribute('role', 'status');
    const text = document.createElement('span');
    text.textContent = message;
    el.appendChild(text);
    if (action) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'flash__action';
      b.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg>';
      b.append(action.label);
      b.addEventListener('click', () => { el._close(); action.run(); });
      el.appendChild(b);
    }
    box.appendChild(el);
    armFlash(el, action ? 6000 : 3200);
    return el;
  }

  // A notification closes itself when its countdown line runs out (hover pauses
  // it); with reduced motion there is no line, so a plain timer does the job.
  function armFlash(el, ms) {
    let done = false;
    const close = () => {
      if (done) return;
      done = true;
      el.classList.add('is-leaving');
      setTimeout(() => { el.remove(); if (el._onGone) el._onGone(); }, REDUCED ? 0 : 250);
    };
    el._close = close;
    el.style.setProperty('--life', ms + 'ms');
    $('.flash__close', el)?.addEventListener('click', close);
    el.addEventListener('animationend', (ev) => { if (ev.animationName === 'flash-life') close(); });
    if (REDUCED) setTimeout(close, ms);
  }
})();

/* ======================================================================
   Chat: send without reloading, poll for new messages, keep "Seen" and
   grouping right. The page works without JS (plain form post + reload).
   ====================================================================== */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const GROUP_GAP = 300; // seconds: messages closer than this join one group

  document.addEventListener('DOMContentLoaded', () => {
    // conversation search: filter the list as you type (server search still works on Enter)
    $$('[data-filter-list]').forEach((input) => {
      const list = $(input.dataset.filterList);
      const none = $('[data-filter-empty]');
      if (!list) return;
      input.addEventListener('input', () => {
        const q = input.value.trim().toLowerCase();
        let shown = 0;
        $$('li[data-filter-text]', list).forEach((li) => {
          const hit = !q || li.dataset.filterText.includes(q);
          li.hidden = !hit;
          if (hit) shown++;
        });
        if (none) none.hidden = shown > 0;
      });
    });

    const chat = $('[data-chat]');
    if (chat) initChat(chat);
  });

  function initChat(chat) {
    const scroll = $('[data-chat-scroll]', chat);
    const list = $('[data-chat-list]', chat);
    const empty = $('[data-chat-empty]', chat);
    const form = $('[data-chat-form]', chat);
    const input = $('[data-chat-input]', chat);
    const quick = $('[data-chat-quick]', chat);
    const mine = chat.dataset.mine;
    const endpoint = chat.dataset.endpoint;
    const memberId = chat.dataset.member;
    const csrf = ($('meta[name="csrf-token"]') || {}).content || '';
    let after = parseInt(chat.dataset.after || '0', 10);
    let sending = false;

    const atBottom = () => scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 80;
    const toBottom = (smooth) => { scroll.style.scrollBehavior = smooth ? 'smooth' : 'auto'; scroll.scrollTop = scroll.scrollHeight; };
    toBottom(false);
    if (window.matchMedia('(min-width: 861px)').matches) input.focus();

    // grow the textarea with its content
    const autosize = () => { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 160) + 'px'; };
    input.addEventListener('input', autosize);

    // Enter sends, Shift+Enter adds a line
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) { ev.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.submit(); }
    });

    if (quick) quick.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-quick]');
      if (!b) return;
      input.value = b.dataset.quick;
      autosize();
      input.focus();
    });

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const body = input.value.trim();
      if (!body || sending) return;
      sending = true;
      const temp = append({ id: 0, sender: mine, body, time: 'Sending…', day: todayKey(), day_label: 'Today', ts: Math.floor(Date.now() / 1000) }, true);
      input.value = ''; autosize();
      toBottom(true);
      try {
        const fd = new FormData(form);
        fd.set('body', body);
        const res = await fetch(endpoint, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-Requested-With': 'fetch', 'X-CSRF-Token': csrf } });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error || 'Message not sent');
        temp.remove();
        append(data.message);
        after = Math.max(after, data.message.id);
        markSeen(data.seen_id);
        if (quick) quick.remove();
        toBottom(true);
      } catch (e) {
        temp.classList.remove('is-pending');
        temp.classList.add('is-failed');
        $('.msg__meta', temp).textContent = 'Not sent: ' + e.message + '. Your text is back in the box.';
        input.value = body; autosize();
      } finally {
        sending = false;
      }
    });

    // poll for new messages every 5s while the tab is visible
    async function poll() {
      if (document.hidden) return;
      try {
        const url = endpoint + '?member_id=' + encodeURIComponent(memberId) + '&after=' + after;
        const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } });
        if (!res.ok) return;
        const data = await res.json();
        if (!data.ok) return;
        const stick = atBottom();
        data.messages.forEach((m) => {
          if (m.id <= after || list.querySelector('[data-id="' + m.id + '"]')) return;
          append(m);
          after = Math.max(after, m.id);
        });
        markSeen(data.seen_id);
        if (data.messages.length && stick) toBottom(true);
      } catch (e) { /* offline for a moment: try again next tick */ }
    }
    setInterval(poll, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });

    function append(m, pending) {
      if (empty) empty.hidden = true;
      const items = $$('.msg', list).filter((li) => !li.classList.contains('is-failed'));
      const last = items[items.length - 1];
      const day = m.day;
      if (!last || last.dataset.day !== day) {
        const sep = document.createElement('li');
        sep.className = 'chat__day';
        sep.dataset.day = day;
        const span = document.createElement('span');
        span.textContent = m.day_label;
        sep.appendChild(span);
        list.appendChild(sep);
      }
      const joins = last && last.dataset.sender === m.sender && last.dataset.day === day && (m.ts - parseInt(last.dataset.ts, 10)) <= GROUP_GAP;
      if (joins) last.classList.remove('is-last');

      const li = document.createElement('li');
      li.className = 'msg msg--in ' + (m.sender === mine ? 'msg--mine' : 'msg--theirs') + (joins ? '' : ' is-first') + ' is-last' + (pending ? ' is-pending' : '');
      li.dataset.id = m.id; li.dataset.sender = m.sender; li.dataset.ts = m.ts; li.dataset.day = day;
      const bubble = document.createElement('div');
      bubble.className = 'msg__bubble';
      bubble.textContent = m.body;            // never innerHTML: messages are user text
      const meta = document.createElement('div');
      meta.className = 'msg__meta';
      const time = document.createElement('time');
      if (m.iso) time.dateTime = m.iso;
      time.textContent = m.time;
      meta.appendChild(time);
      li.append(bubble, meta);
      list.appendChild(li);
      return li;
    }

    // "Seen" sits under my newest message once the other side has read it
    function markSeen(seenId) {
      $$('[data-seen]', list).forEach((n) => n.remove());
      const minesAll = $$('.msg--mine', list).filter((li) => parseInt(li.dataset.id, 10) > 0);
      const newest = minesAll[minesAll.length - 1];
      if (!newest || !seenId || parseInt(newest.dataset.id, 10) > seenId) return;
      const s = document.createElement('span');
      s.className = 'msg__seen'; s.dataset.seen = ''; s.textContent = ' · Seen';
      $('.msg__meta', newest).appendChild(s);
      newest.classList.add('is-last');
    }

    function todayKey() {
      const d = new Date();
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
  }
})();

/* ======================================================================
   Motion: pointer-driven touches and a few counters. Skipped entirely for
   people who ask for reduced motion; the pages are complete without it.
   ====================================================================== */
(function () {
  'use strict';

  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  document.addEventListener('DOMContentLoaded', () => {
    if (reduced) return;

    if (finePointer) {
      // ---- covers tilt toward the pointer
      $$('.book-card__cover, [data-tilt]').forEach((el) => {
        const max = el.hasAttribute('data-tilt') ? 7 : 10;
        let frame = 0;
        el.addEventListener('pointermove', (ev) => {
          const r = el.getBoundingClientRect();
          const x = (ev.clientX - r.left) / r.width;
          const y = (ev.clientY - r.top) / r.height;
          cancelAnimationFrame(frame);
          frame = requestAnimationFrame(() => {
            el.classList.add('is-tilting');
            el.style.setProperty('--ry', ((x - 0.5) * max * 2).toFixed(2) + 'deg');
            el.style.setProperty('--rx', ((0.5 - y) * max * 2).toFixed(2) + 'deg');
            el.style.setProperty('--gx', (x * 100).toFixed(1) + '%');
            el.style.setProperty('--gy', (y * 100).toFixed(1) + '%');
          });
        });
        el.addEventListener('pointerleave', () => {
          cancelAnimationFrame(frame);
          el.classList.remove('is-tilting');
          el.style.removeProperty('--rx');
          el.style.removeProperty('--ry');
        });
      });

      // ---- soft light that follows the pointer across cards
      $$('.stat, .loan-card').forEach((el) => el.addEventListener('pointermove', (ev) => {
        const r = el.getBoundingClientRect();
        el.style.setProperty('--mx', (ev.clientX - r.left) + 'px');
        el.style.setProperty('--my', (ev.clientY - r.top) + 'px');
      }));

      // ---- the sign-in cover wall drifts slightly against the pointer
      const wallBox = document.querySelector('.auth__wall');
      const wall = wallBox && wallBox.querySelector('.wall');
      if (wall) {
        wallBox.addEventListener('pointermove', (ev) => {
          const r = wallBox.getBoundingClientRect();
          wall.style.setProperty('--px', (((ev.clientX - r.left) / r.width - 0.5) * -18).toFixed(1) + 'px');
          wall.style.setProperty('--py', (((ev.clientY - r.top) / r.height - 0.5) * -18).toFixed(1) + 'px');
        });
        wallBox.addEventListener('pointerleave', () => { wall.style.removeProperty('--px'); wall.style.removeProperty('--py'); });
      }
    }

    // ---- headline word rotator
    $$('[data-rotator]').forEach((box) => {
      const words = Array.from(box.children);
      if (words.length < 2) return;
      let i = 0;
      setInterval(() => {
        if (document.hidden) return;
        const out = words[i];
        i = (i + 1) % words.length;
        out.classList.remove('is-active');
        out.classList.add('is-leaving');
        words[i].classList.remove('is-leaving');
        words[i].classList.add('is-active');
        setTimeout(() => out.classList.remove('is-leaving'), 700);
      }, 2800);
    });

    // ---- spotlight card: advances when the active dot fills; dots jump to a book
    $$('[data-cycle]').forEach((box) => {
      const items = $$('.spotlight__item', box);
      const dots = $$('.spotlight__dot', box);
      if (items.length < 2) return;
      let i = 0;
      const show = (n) => {
        items[i].classList.remove('is-active'); items[i].setAttribute('aria-hidden', 'true');
        dots[i].classList.remove('is-active');
        i = n;
        items[i].classList.add('is-active'); items[i].removeAttribute('aria-hidden');
        dots[i].classList.add('is-active');
      };
      box.addEventListener('animationend', (ev) => { if (ev.animationName === 'dot-fill') show((i + 1) % items.length); });
      dots.forEach((d, n) => d.addEventListener('click', () => { if (n !== i) show(n); }));
    });

    // ---- headline numbers count up the first time they are seen
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver((entries) => entries.forEach((en) => {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        countUp(en.target);
      }), { threshold: 0.5 });
      $$('.stat__value').forEach((el) => { if (/^\d[\d,]*$/.test(el.textContent.trim())) io.observe(el); });
    }
  });

  function countUp(el) {
    const final = el.textContent.trim();
    const target = parseInt(final.replace(/,/g, ''), 10);
    if (!target) return;
    const duration = 1100;
    const start = performance.now();
    const step = (now) => {
      const p = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(2, -10 * p);          // ease-out expo
      el.textContent = p < 1 ? Math.round(target * eased).toLocaleString() : final;
      if (p < 1) requestAnimationFrame(step);
    };
    el.textContent = '0';
    requestAnimationFrame(step);
  }
})();

/* ======================================================================
   Book page extras: native share sheet where the device has one, and a
   "View more" toggle for long descriptions that are clamped on phones.
   ====================================================================== */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    // swipeable rows get arrow buttons for mouse users, shown only when the row overflows
    document.querySelectorAll('[data-row]').forEach((row) => {
      const head = row.parentElement.querySelector('.m-section__head');
      if (!head) return;
      const box = document.createElement('div');
      box.className = 'row-arrows';
      const make = (dir, label) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn--ghost btn--icon btn--sm';
        b.dataset.dir = dir;
        b.setAttribute('aria-label', label);
        b.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
        b.addEventListener('click', () => row.scrollBy({ left: dir * row.clientWidth * 0.85 }));
        return b;
      };
      const prev = make(-1, 'Scroll back');
      const next = make(1, 'Scroll forward');
      box.append(prev, next);
      head.appendChild(box);
      const update = () => {
        const max = row.scrollWidth - row.clientWidth;
        box.hidden = max <= 2;
        prev.disabled = row.scrollLeft <= 2;
        next.disabled = row.scrollLeft >= max - 2;
      };
      row.addEventListener('scroll', update, { passive: true });
      window.addEventListener('resize', update);
      update();
    });

    // stacked tables (phones): label each cell with its column heading
    document.querySelectorAll('.table--stack').forEach((table) => {
      const heads = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
      table.querySelectorAll('tbody tr').forEach((tr) => Array.from(tr.children).forEach((td, i) => {
        if (i === 0 || td.hasAttribute('data-label') || !heads[i] || td.querySelector('button, form, .actions')) return;
        td.setAttribute('data-label', heads[i]);
      }));
    });

    const share = document.querySelector('[data-share]');
    if (share && navigator.share) {
      share.hidden = false;
      share.addEventListener('click', () => {
        const title = (document.querySelector('.book-hero h1') || {}).textContent || document.title;
        navigator.share({ title, url: location.href }).catch(() => { /* dismissed */ });
      });
    }

    document.querySelectorAll('[data-clamp]').forEach((prose) => {
      if (prose.scrollHeight <= prose.clientHeight + 2) return;   // fits, or not clamped (desktop)
      const more = document.createElement('button');
      more.type = 'button';
      more.className = 'link-button prose-more';
      more.textContent = 'View more';
      more.setAttribute('aria-expanded', 'false');
      more.addEventListener('click', () => {
        const open = prose.classList.toggle('is-open');
        more.textContent = open ? 'View less' : 'View more';
        more.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
      prose.after(more);
    });
  });
})();

/* ======================================================================
   Theme: Night (default) or Day. Saved in a cookie so the server renders
   the right theme on the next page with no flash. Switching sweeps the new
   theme in from the button that was pressed.
   ====================================================================== */
(function () {
  'use strict';
  const root = document.documentElement;
  const current = () => (root.dataset.theme === 'light' ? 'light' : 'dark');

  function setTheme(theme, from) {
    if (theme === current()) return;
    const apply = () => {
      root.dataset.theme = theme;
      document.cookie = 'bt_theme=' + theme + '; path=/; max-age=31536000; samesite=lax';
      const meta = document.querySelector('meta[name="theme-color"]');
      if (meta) meta.content = theme === 'light' ? '#F6F3EC' : '#0E1420';
      const scheme = document.querySelector('meta[name="color-scheme"]');
      if (scheme) scheme.content = theme;
      document.querySelectorAll('[data-theme-set]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.themeSet === theme ? 'true' : 'false'));
      document.querySelectorAll('[data-theme-toggle]').forEach((b) => {
        b.setAttribute('aria-label', 'Switch to ' + (theme === 'light' ? 'dark' : 'light') + ' theme');
        b.innerHTML = theme === 'light'
          ? '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/></svg>'
          : '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
      });
    };
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!document.startViewTransition || reduced) { apply(); return; }
    if (from) {
      const r = from.getBoundingClientRect();
      root.style.setProperty('--tx', (r.left + r.width / 2) + 'px');
      root.style.setProperty('--ty', (r.top + r.height / 2) + 'px');
    }
    root.classList.add('theme-switching');
    const t = document.startViewTransition(apply);
    t.finished.finally(() => root.classList.remove('theme-switching'));
  }

  window.BookTrack = window.BookTrack || {};
  window.BookTrack.setTheme = setTheme;
  window.BookTrack.theme = current;

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-theme-set]').forEach((b) => b.addEventListener('click', (ev) => {
      ev.preventDefault();                       // the form posts only when JavaScript is off
      setTheme(b.dataset.themeSet, b);
    }));
    document.querySelectorAll('[data-theme-toggle]').forEach((b) => b.addEventListener('click', () => {
      setTheme(current() === 'light' ? 'dark' : 'light', b);
    }));
  });
})();

/* ======================================================================
   Live search. On the Discover header search, results drop down right
   under the box as you type. On phones, the search icon in the top bar
   opens a full-screen search sheet with the same results. Books for
   everyone, members too for librarians. Enter on a highlighted result
   opens it; otherwise Enter runs the normal search.
   ====================================================================== */
(function () {
  'use strict';
  const api = document.body.dataset.searchApi;

  // A results list bound to an <input>: fetches as you type, arrow keys move,
  // Enter opens the highlighted result.
  function liveResults(input, list, opts) {
    let items = [];
    let active = -1;
    let ctrl = null;
    let timer = 0;
    let lastQuery = null;

    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(update, 120); });
    input.addEventListener('keydown', (ev) => {
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        if (!items.length) return;
        ev.preventDefault();
        const step = ev.key === 'ArrowDown' ? 1 : -1;
        select(active < 0 ? (step > 0 ? 0 : items.length - 1) : (active + step + items.length) % items.length);
      } else if (ev.key === 'Enter' && active >= 0 && items[active]) {
        ev.preventDefault();
        items[active].go();
      }
    });

    async function update(force) {
      const q = input.value.trim();
      if (q === lastQuery && !force) return;
      lastQuery = q;
      if (!q) { clear(); opts.onEmpty && opts.onEmpty(); return; }
      if (ctrl) ctrl.abort();
      ctrl = new AbortController();
      try {
        const res = await fetch(api + '?q=' + encodeURIComponent(q), { signal: ctrl.signal, headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } });
        const data = await res.json();
        if (input.value.trim() === q) render(q, data);
      } catch (e) { if (e.name !== 'AbortError') render(q, { books: [], members: [] }); }
    }

    function clear() {
      list.textContent = '';
      items = [];
      active = -1;
      input.removeAttribute('aria-activedescendant');
    }

    function render(q, data) {
      clear();
      if (data.books.length) {
        group('Books');
        data.books.forEach((b) => {
          const side = document.createElement('span');
          side.className = 'palette__side' + (b.available ? '' : ' is-out');
          side.textContent = b.available ? (b.copies > 1 ? b.available + ' of ' + b.copies + ' free' : 'Available') : 'On loan';
          add(b.cover ? img(b.cover) : colorThumb(b), b.title, [b.author, b.category].filter(Boolean).join(' · '), side, q, () => go(b.url));
        });
      }
      if (data.members && data.members.length) {
        group('Members');
        data.members.forEach((m) => {
          const g = document.createElement('span');
          g.className = 'palette__glyph palette__glyph--avatar';
          g.textContent = m.initials;
          add(g, m.name, '@' + m.username + (m.active ? '' : ' · deactivated'), null, q, () => go(m.url));
        });
      }
      if (!items.length) {
        const li = document.createElement(opts.tag || 'li');
        li.className = 'palette__empty';
        const strong = document.createElement('strong');
        strong.textContent = 'Nothing matches “' + q + '”';
        li.append(strong, 'Try a title, an author or a category.');
        list.appendChild(li);
      } else if (data.all) {
        const g = document.createElement('span');
        g.className = 'palette__glyph';
        g.innerHTML = '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>';
        add(g, 'See all results for “' + q + '”', null, null, '', () => go(data.all), 'palette__item--all');
      }
      opts.onResults && opts.onResults();
    }

    function group(label) {
      const li = document.createElement(opts.tag || 'li');
      li.className = 'palette__group';
      li.setAttribute('role', 'presentation');
      li.textContent = label;
      list.appendChild(li);
    }
    function add(lead, title, sub, side, q, go, extra) {
      const li = document.createElement(opts.tag || 'li');
      const i = items.length;
      li.className = 'palette__item' + (extra ? ' ' + extra : '');
      li.id = list.id + '-' + i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      const text = document.createElement('span');
      text.className = 'palette__text';
      const strong = document.createElement('strong');
      highlight(strong, title, q);
      text.appendChild(strong);
      if (sub) { const sp = document.createElement('span'); highlight(sp, sub, q); text.appendChild(sp); }
      li.append(lead, text, side || document.createElement('span'));
      li.addEventListener('mousemove', () => { if (active !== i) select(i); });
      li.addEventListener('mousedown', (ev) => ev.preventDefault());   // keep focus in the box
      li.addEventListener('click', go);
      list.appendChild(li);
      items.push({ el: li, go });
    }
    function select(i) {
      if (items[active]) items[active].el.setAttribute('aria-selected', 'false');
      active = i;
      items[i].el.setAttribute('aria-selected', 'true');
      input.setAttribute('aria-activedescendant', items[i].el.id);
      items[i].el.scrollIntoView({ block: 'nearest' });
    }
    function highlight(el, text, q) {
      const at = q ? text.toLowerCase().indexOf(q.toLowerCase()) : -1;
      if (at < 0) { el.textContent = text; return; }
      const mark = document.createElement('mark');
      mark.textContent = text.slice(at, at + q.length);
      el.append(text.slice(0, at), mark, text.slice(at + q.length));
    }
    function img(src) {
      const im = document.createElement('img');
      im.className = 'palette__thumb';
      im.src = src; im.alt = '';
      return im;
    }
    function colorThumb(b) {
      const d = document.createElement('span');
      d.className = 'palette__thumb palette__thumb--color';
      d.style.background = b.color;
      d.textContent = b.title.split(/\s+/).filter((w) => /^[A-Za-z0-9]/.test(w)).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
      return d;
    }
    function go(url) { opts.onPick && opts.onPick(); window.location.href = url; }

    return { update, clear, hasItems: () => list.childElementCount > 0, reset: () => { lastQuery = null; } };
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (!api) return;

    // ---- dropdown under the header search box
    document.querySelectorAll('input[data-suggest]').forEach((input) => {
      const box = document.getElementById(input.getAttribute('aria-controls'));
      const form = input.form;
      const show = (on) => {
        box.hidden = !on;
        input.setAttribute('aria-expanded', on ? 'true' : 'false');
        form.classList.toggle('is-suggesting', on);
      };
      const results = liveResults(input, box, {
        tag: 'div',
        onResults: () => show(document.activeElement === input),
        onEmpty: () => show(false),
        onPick: () => show(false),
      });
      input.addEventListener('focus', () => { if (input.value.trim()) { results.reset(); results.update(true); } });
      input.addEventListener('keydown', (ev) => {
        // Esc closes the results, a second Esc leaves the box; what was typed stays
        if (ev.key === 'Escape') {
          ev.preventDefault();
          if (!box.hidden) show(false);
          else input.blur();
        }
      });
      // close when focus or a click goes anywhere else
      input.addEventListener('blur', () => setTimeout(() => { if (document.activeElement !== input) show(false); }, 120));
      document.addEventListener('pointerdown', (ev) => { if (!form.contains(ev.target)) show(false); });
    });

    // ---- full-screen search sheet (phones: the search icon in the top bar)
    const dlg = document.getElementById('palette');
    if (dlg) {
      const input = dlg.querySelector('[data-palette-input]');
      const list = dlg.querySelector('[data-palette-list]');
      const results = liveResults(input, list, { onPick: () => dlg.close() });
      const open = () => {
        if (dlg.open) return;
        input.value = '';
        results.reset();
        results.clear();
        dlg.showModal();
        input.focus();
      };
      document.querySelectorAll('[data-palette-open]').forEach((b) => b.addEventListener('click', open));
      dlg.querySelector('[data-palette-close]').addEventListener('click', () => dlg.close());
      dlg.addEventListener('click', (ev) => { if (ev.target === dlg) dlg.close(); });
      // plain search on Enter when nothing is highlighted
      input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter' && !ev.defaultPrevented && input.value.trim()) {
          const all = list.querySelector('.palette__item--all');
          if (all) all.click();
        }
      });
    }

    // ---- Ctrl+K jumps to the page's search box (no pop-up on desktop)
    document.addEventListener('keydown', (ev) => {
      if (!(ev.ctrlKey || ev.metaKey) || ev.key.toLowerCase() !== 'k') return;
      const box = document.querySelector('.page-head .search input, .toolbar .search input');
      if (box && box.offsetParent) { ev.preventDefault(); box.focus(); box.select(); }
    });
  });
})();
