/* Account-first CalDAV connection. No credentials are stored in the browser. */
(() => {
  'use strict';
  const settings = window.dendrilaCalendarConnect;
  const $ = id => document.getElementById(id);
  const setup = $('dendrila-calendar-setup-form');
  if (settings && setup) {
    const sources = $('dendrila-setup-sources');
    const destination = $('dendrila-setup-destination');
    const feedback = $('dendrila-setup-feedback');
    const next = $('dendrila-setup-next');
    const back = $('dendrila-setup-back');
    destination.hidden = true;
    next.hidden = false;
    back.hidden = false;
    next.addEventListener('click', () => {
      if (!sources.querySelector('input[name="sources[]"]:checked')) {
        feedback.textContent = settings.sourcesRequired;
        return;
      }
      feedback.textContent = '';
      sources.hidden = true;
      destination.hidden = false;
      destination.querySelector('input:checked')?.focus();
    });
    back.addEventListener('click', () => {
      destination.hidden = true;
      sources.hidden = false;
      next.focus();
    });
    return;
  }
  const form = $('dendrila-direct-connect-form');
  const providerSelect = $('dendrila-connect-provider');
  if (!settings || !form || !providerSelect) return;
  const providerField = $('dendrila-direct-provider');
  const service = $('dendrila-connect-service');
  const next = $('dendrila-connect-next');
  const account = $('dendrila-connect-account');
  const unavailable = $('dendrila-oauth-unavailable');
  const help = $('dendrila-connect-provider-help');
  const serverField = $('dendrila-server-field');
  const server = $('dendrila-bridge-server');
  const username = $('dendrila-bridge-user');
  const password = $('dendrila-bridge-password');
  const result = $('dendrila-calendar-choice');
  const calendars = $('dendrila-discovered-calendar');
  const url = $('dendrila-bridge-url');
  const token = $('dendrila-discovery-token');
  const feedback = $('dendrila-discovery-feedback');
  const connect = $('dendrila-confirm-calendar');
  const test = $('dendrila-discover-calendars');
  const label = $('dendrila-bridge-label');
  const isOAuth = service => service === 'google' || service === 'microsoft';
  const text = (node, value) => { node.textContent = value; };

  let discoveryRequest;
  function show(step, focus) {
    [service, account, result, unavailable].forEach(panel => { panel.hidden = panel !== step; });
    focus.focus();
  }
  function reset() {
    discoveryRequest?.abort();
    discoveryRequest = undefined;
    test.disabled = false;
    result.hidden = true;
    token.value = '';
    url.value = '';
    calendars.replaceChildren();
    feedback.textContent = '';
  }
  function displayService() {
    const choice = providerSelect.value;
    providerField.value = choice;
    reset();
    serverField.hidden = choice === 'icloud';
    server.required = !serverField.hidden && !isOAuth(choice);
    username.required = !isOAuth(choice);
    password.required = !isOAuth(choice);
    const explanations = {
      nextcloud: settings.nextcloud,
      icloud: settings.icloud,
      caldav: settings.caldav,
      google: settings.oauth,
      microsoft: settings.oauth,
    };
    text(help, explanations[choice] || '');
    if (choice === 'nextcloud') label.value = 'Nextcloud';
    if (choice === 'icloud') label.value = 'iCloud';
    if (choice === 'caldav') label.value = 'CalDAV';
  }
  providerSelect.addEventListener('change', displayService);
  next.addEventListener('click', () => {
    if (isOAuth(providerSelect.value)) {
      show(unavailable, $('dendrila-oauth-back'));
    } else {
      show(account, serverField.hidden ? username : server);
    }
  });
  [$('dendrila-account-back'), $('dendrila-oauth-back')].forEach(button => {
    button.addEventListener('click', () => {
      reset();
      show(service, providerSelect);
    });
  });
  $('dendrila-calendar-back').addEventListener('click', () => {
    reset();
    show(account, serverField.hidden ? username : server);
  });
  [server, username, password].forEach(el => el.addEventListener('input', reset));
  calendars.addEventListener('change', () => { url.value = calendars.value; });

  test.addEventListener('click', async () => {
    reset();
    const provider = providerSelect.value;
    if (isOAuth(provider)) return;
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const request = new AbortController();
    discoveryRequest = request;
    test.disabled = true;
    text(feedback, settings.testing);
    try {
      const body = new URLSearchParams({
        action: 'dendrila_calendar_discover',
        nonce: settings.nonce,
        provider,
        server_url: serverField.hidden ? '' : server.value.trim(),
        username: username.value.trim(),
        app_password: password.value,
      });
      const response = await fetch(settings.ajaxUrl, {
        method: 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body, signal: request.signal,
      });
      const data = await response.json();
      if (discoveryRequest !== request) return;
      if (!response.ok || !data.success) throw new Error(data?.data?.message || settings.failed);
      for (const item of data.data.calendars) {
        const option = document.createElement('option');
        option.value = item.url;
        option.textContent = item.name;
        calendars.append(option);
      }
      if (!calendars.options.length) throw new Error(settings.failed);
      token.value = data.data.token;
      url.value = calendars.value;
      feedback.textContent = '';
      show(result, calendars);
    } catch (error) {
      if (discoveryRequest === request) feedback.textContent = error?.message || settings.failed;
    } finally {
      if (discoveryRequest === request) {
        discoveryRequest = undefined;
        test.disabled = false;
      }
    }
  });
  form.addEventListener('submit', event => {
    if (result.hidden || !token.value || !url.value || !form.querySelector('input[name="sources[]"]:checked')) {
      event.preventDefault();
      feedback.textContent = result.hidden ? settings.failed : settings.sourcesRequired;
    } else {
      connect.disabled = true;
    }
  });
  if (['nextcloud', 'icloud', 'caldav', 'google', 'microsoft'].includes(settings.initialService)) {
    providerSelect.value = settings.initialService;
  }
  displayService();
})();
