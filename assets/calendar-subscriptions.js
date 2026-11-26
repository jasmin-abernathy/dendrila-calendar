/* Live calendar subscription management. Only admins receive this script. */
(() => {
  'use strict';
  const config = window.dendrilaCalendarSubscriptions;
  const form = document.getElementById('dendrila-subscription-form');
  const result = document.getElementById('dendrila-subscription-result');
  const list = document.getElementById('dendrila-subscription-list');
  const provider = document.getElementById('dendrila-sync-provider');
  const instructions = document.getElementById('dendrila-provider-instructions');
  if (!config || !form || !result || !list) return;

  function showProvider() {
    if (!provider || !instructions) return;
    const steps = config.steps || {};
    instructions.textContent = steps[provider.value] || steps.other || '';
  }
  provider?.addEventListener('change', showProvider);
  showProvider();

  let newId = null;
  const el = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  };
  async function post(action, params) {
    const body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', config.nonce);
    for (const [key, value] of Object.entries(params)) {
      if (Array.isArray(value)) value.forEach(item => body.append(`${key}[]`, item));
      else body.set(key, value);
    }
    const response = await fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      cache: 'no-store',
      body,
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload?.data?.message || config.failed);
    return payload.data;
  }
  function showError(error) {
    result.replaceChildren(el('p', error?.message || config.failed, 'notice notice-error inline'));
  }
  function makeRevoke(id) {
    const button = el('button', config.revoke, 'button button-small dendrila-subscription-revoke');
    button.type = 'button';
    button.dataset.subscriptionId = id;
    return button;
  }
  function showNewLink(item) {
    newId = item.id;
    const message = el('p', config.created, 'dendrila-subscription-created');
    const field = el('input');
    field.type = 'url';
    field.readOnly = true;
    field.value = item.url;
    field.setAttribute('aria-label', config.copy);
    const copy = el('button', config.copy, 'button button-secondary');
    copy.type = 'button';
    const feedback = el('span', '', 'dendrila-copy-feedback');
    copy.addEventListener('click', async () => {
      try {
        if (!navigator.clipboard?.writeText) throw new Error('Clipboard API unavailable');
        await navigator.clipboard.writeText(field.value);
        feedback.textContent = config.copied;
      } catch (_) {
        field.focus();
        field.select();
        feedback.textContent = config.copyFailed;
      }
    });
    result.replaceChildren(message, field, copy, feedback);
    // Apple Calendar supports webcal: subscription URLs; HTTPS remains available for other apps.
    if (provider?.value === 'apple') {
      const link = el('a', config.appleLink, 'button button-secondary');
      link.href = item.url.replace(/^https:/, 'webcal:');
      link.rel = 'noreferrer';
      result.append(link);
    }
    field.focus();
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    const submit = form.querySelector('[type=submit]');
    if (submit.disabled) return;
    submit.disabled = true;
    const sources = Array.from(form.querySelectorAll('input[name="sources[]"]:checked')).map(box => box.value);
    try {
      const item = await post('dendrila_calendar_create_subscription', {
        label: form.querySelector('[name=label]').value,
        sources,
      });
      showNewLink(item);
      const row = el('li');
      row.dataset.subscriptionId = item.id;
      row.append(el('span', `${item.name} — ${item.created} UTC `), makeRevoke(item.id));
      list.prepend(row);
    } catch (error) {
      showError(error);
    } finally {
      submit.disabled = false;
    }
  });

  list.addEventListener('click', async event => {
    const button = event.target.closest('button.dendrila-subscription-revoke');
    if (!button || !list.contains(button) || button.disabled) return;
    if (!window.confirm(config.revokeConfirm)) return;
    button.disabled = true;
    try {
      const item = await post('dendrila_calendar_revoke_subscription', { id: button.dataset.subscriptionId });
      button.closest('li')?.remove();
      if (newId === item.id) {
        newId = null;
        result.replaceChildren(el('p', config.revoked));
      } else {
        result.replaceChildren(el('p', config.revoked));
      }
    } catch (error) {
      showError(error);
      button.disabled = false;
    }
  });
})();
