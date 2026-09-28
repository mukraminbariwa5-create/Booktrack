/* BookTrack — small progressive enhancements. Every page works without JS. */
(function () {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
  const csrf = () => ($('meta[name="csrf-token"]') || {}).content || '';

  document.addEventListener('DOMContentLoaded', () => {
    // ---- mobile navigation
    const app = $('#app');
    $$('[data-nav-toggle]').forEach((b) => b.addEventListener('click', () => app && app.classList.toggle('nav-open')));

    // ---- flash messages: dismiss + auto hide
    $$('.flash').forEach((f, i) => {
      const close = () => { f.style.transition = 'opacity .2s'; f.style.opacity = '0'; setTimeout(() => f.remove(), 200); };
      $('.flash__close', f)?.addEventListener('click', close);
      setTimeout(close, 5000 + i * 800);
    });

    // ---- modals: [data-open="id"] opens <dialog id>, [data-close] closes
    $$('[data-open]').forEach((btn) => btn.addEventListener('click', (ev) => {
      const dlg = document.getElementById(btn.dataset.open);
      if (!dlg) return;
      ev.preventDefault();
      if (btn.dataset.fill) fillForm(dlg, JSON.parse(btn.dataset.fill));
      else if (btn.dataset.reset !== undefined) resetForm(dlg);
      dlg.showModal();
      const first = $('input:not([type=hidden]), select, textarea', dlg);
      if (first) setTimeout(() => first.focus(), 30);
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

    // ---- favorites: toggle without reloading the page
    $$('form[data-favorite]').forEach((form) => form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const btn = $('button', form);
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
        const label = $('[data-fav-label]', btn);
        if (label) label.textContent = data.favorite ? 'Saved to favorites' : 'Add to favorites';
        toast(data.favorite ? 'Added to your favorites' : 'Removed from favorites');
        if (!data.favorite && form.dataset.favorite === 'remove-card') form.closest('.book-card')?.remove();
      } catch (e) {
        toast(e.message, true);
      } finally {
        btn.disabled = false;
      }
    }));

    // ---- auto-submit filters (selects with data-autosubmit)
    $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form && el.form.submit()));

    // ---- cover preview on file choose
    $$('input[type=file][data-preview]').forEach((input) => input.addEventListener('change', () => {
      const img = document.getElementById(input.dataset.preview);
      const f = input.files && input.files[0];
      if (img && f && f.type.startsWith('image/')) { img.src = URL.createObjectURL(f); img.hidden = false; }
    }));

    // ---- demo account quick-fill on the login page
    $$('[data-demo-user]').forEach((b) => b.addEventListener('click', () => {
      $('#username').value = b.dataset.demoUser;
      $('#password').value = b.dataset.demoPass;
      $('#password').focus();
    }));

    // ---- "/" focuses the search box
    document.addEventListener('keydown', (ev) => {
      if (ev.key !== '/' || ev.target.closest('input, textarea, select, [contenteditable]')) return;
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
  }

  function toast(message, isError) {
    let box = $('.flashes');
    if (!box) { box = document.createElement('div'); box.className = 'flashes'; document.body.appendChild(box); }
    const el = document.createElement('div');
    el.className = 'flash' + (isError ? ' flash--error' : '');
    el.setAttribute('role', 'status');
    el.textContent = message;
    box.appendChild(el);
    setTimeout(() => { el.style.transition = 'opacity .2s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 200); }, 3200);
  }
})();
