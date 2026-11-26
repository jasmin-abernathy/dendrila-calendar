/* global dendrilaCalendar */
(() => {
  'use strict';
  const config = window.dendrilaCalendar;
  if (!config) return;

  const root = document.getElementById('dendrila-grid');
  const period = document.getElementById('dendrila-period');
  const message = document.getElementById('dendrila-message');
  const viewSelect = document.getElementById('dendrila-view');
  const monthPicker = document.getElementById('dendrila-month');
  const previousButton = document.getElementById('dendrila-prev');
  const nextButton = document.getElementById('dendrila-next');
  const toggles = document.getElementById('dendrila-source-toggles');
  const search = document.getElementById('dendrila-search');
  const dayDetails = document.getElementById('dendrila-day-details');
  if (!root || !period || !message || !viewSelect || !toggles) return;

  const locale = (config.locale || 'en').replace(/_/g, '-');
  const configuredTimeZone = config.timezone || 'UTC';
  let timeZone = configuredTimeZone;
  try { new Intl.DateTimeFormat('en', { timeZone }).format(); }
  catch (_) { timeZone = 'UTC'; }
  const firstDay = Number(config.weekStartsOn) || 0;
  const sources = config.sources || {};
  const i18n = config.i18n || {};
  const dayFormatter = new Intl.DateTimeFormat('en-GB', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' });
  const monthFormatter = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', month: 'long', year: 'numeric' });
  const weekdayFormatter = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', weekday: 'short' });
  const fullDayFormatter = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long' });
  const timeFormatter = new Intl.DateTimeFormat(locale, { timeZone, hour: '2-digit', minute: '2-digit' });
  const primarySources = Object.keys(sources).filter(source => (config.primarySources || ['native', 'bookings']).includes(source));
  // Persist only source slugs (never event/customer details or credentials) so a
  // previously selected optional integration stays selected after returning.
  const storageKey = 'dendrila-calendar-sources:' + new URL(config.ajaxUrl, window.location.href).pathname;
  function restoreSources() {
    try {
      const raw = window.localStorage.getItem(storageKey);
      if (raw === null) return primarySources;
      const values = JSON.parse(raw);
      if (Array.isArray(values) && values.every(item => typeof item === 'string')) {
        return values.filter(item => Object.prototype.hasOwnProperty.call(sources, item));
      }
    } catch (_) { /* Storage may be disabled by the browser. */ }
    return primarySources;
  }
  function rememberSources(selected) {
    try { window.localStorage.setItem(storageKey, JSON.stringify(Array.from(selected))); }
    catch (_) { /* Calendar still works without localStorage. */ }
  }
  const state = { selected: today(), selectedDay: siteKey(new Date()), view: 'month', selectedSources: new Set(restoreSources()), request: null, events: [], loaded: false };

  function el(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = String(text);
    return element;
  }
  function addDays(date, days) {
    return new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate() + days, 12));
  }
  function key(date) {
    const year = date.getUTCFullYear();
    return `${year}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
  }
  function siteKey(date) {
    const parts = Object.fromEntries(dayFormatter.formatToParts(date).filter(p => p.type !== 'literal').map(p => [p.type, p.value]));
    return `${parts.year}-${parts.month}-${parts.day}`;
  }
  function today() { return new Date(`${siteKey(new Date())}T12:00:00Z`); }
  function weekStart(date) {
    return addDays(date, -((date.getUTCDay() - firstDay + 7) % 7));
  }
  function range() {
    if (state.view === 'month') {
      const start = weekStart(new Date(Date.UTC(state.selected.getUTCFullYear(), state.selected.getUTCMonth(), 1, 12)));
      const last = new Date(Date.UTC(state.selected.getUTCFullYear(), state.selected.getUTCMonth() + 1, 0, 12));
      const days = Math.ceil((Math.round((last - start) / 86400000) + 1) / 7) * 7;
      return { start, end: addDays(start, days), days };
    }
    const start = state.view === 'week' ? weekStart(state.selected) : new Date(Date.UTC(state.selected.getUTCFullYear(), state.selected.getUTCMonth(), 1, 12));
    const end = state.view === 'week' ? addDays(start, 7) : new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + 1, 1, 12));
    return { start, end, days: Math.round((end - start) / 86400000) };
  }
  function validLink(raw) {
    if (!raw) return null;
    try {
      const url = new URL(raw, window.location.origin);
      return url.origin === window.location.origin && ['https:', 'http:'].includes(url.protocol) ? url.href : null;
    } catch (_) { return null; }
  }
  function eventRow(event) {
    const url = validLink(event.url);
    const row = el(url ? 'a' : 'span', 'dendrila-event');
    if (url) row.href = url;
    const date = new Date(event.start);
    const label = event.all_day ? (i18n.allDay || 'All day') : (Number.isNaN(date.getTime()) ? '' : timeFormatter.format(date));
    row.textContent = `${label} · ${event.title}${event.status ? ` (${event.status})` : ''}`;
    row.dataset.source = event.source;
    row.title = sources[event.source] || event.source;
    return row;
  }
  function eventCoversDay(event, dateKey) {
    const start = new Date(event.start);
    const end = new Date(event.end);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) return false;
    return siteKey(start) <= dateKey && siteKey(new Date(end.getTime() - 1)) >= dateKey;
  }
  function filteredEvents() {
    const query = search ? search.value.trim().toLocaleLowerCase(locale) : '';
    if (!query) return state.events;
    return state.events.filter(event => [event.title, event.status, sources[event.source] || '']
      .some(value => String(value || '').toLocaleLowerCase(locale).includes(query)));
  }
  function renderFiltered() {
    const events = filteredEvents();
    display(events);
  }
  function display(events) {
    root.replaceChildren();
    const { start, days } = range();
    const month = state.view === 'month';
    root.className = `dendrila-grid dendrila-grid--${state.view}`;
    viewSelect.value = state.view;
    const periodUnit = state.view === 'week' ? 'Week' : 'Month';
    const prevLabel = i18n['previous' + periodUnit] || i18n.previous || 'Previous';
    const nextLabel = i18n['next' + periodUnit] || i18n.next || 'Next';
    if (previousButton) { previousButton.setAttribute('aria-label', prevLabel); previousButton.title = prevLabel; }
    if (nextButton) { nextButton.setAttribute('aria-label', nextLabel); nextButton.title = nextLabel; }
    if (monthPicker) monthPicker.value = key(state.selected).slice(0, 7);
    if (state.view === 'week') {
      period.textContent = `${fullDayFormatter.format(start)} – ${fullDayFormatter.format(addDays(start, 6))}`;
    } else {
      period.textContent = monthFormatter.format(state.selected);
    }
    if (month) {
      for (let i = 0; i < 7; i++) {
        root.append(el('div', 'dendrila-day-heading', weekdayFormatter.format(addDays(start, i))));
      }
    }
    let visible = 0;
    for (let i = 0; i < days; i++) {
      const day = addDays(start, i);
      const dayKey = key(day);
      const matches = events.filter(event => eventCoversDay(event, dayKey));
      visible += matches.length;
      if (state.view === 'list' && !matches.length) continue;
      const cell = el('section', 'dendrila-day');
      cell.setAttribute('aria-label', fullDayFormatter.format(day));
      if (dayKey === siteKey(new Date())) cell.classList.add('dendrila-day--today');
      if (month && day.getUTCMonth() !== state.selected.getUTCMonth()) cell.classList.add('dendrila-day--outside');
      cell.dataset.day = dayKey;
      if (month) {
        const dateButton = el('button', 'dendrila-day-date', day.getUTCDate());
        dateButton.type = 'button';
        dateButton.setAttribute('aria-label', fullDayFormatter.format(day));
        dateButton.setAttribute('aria-pressed', dayKey === state.selectedDay ? 'true' : 'false');
        if (dayKey === state.selectedDay) cell.classList.add('dendrila-day--selected');
        dateButton.addEventListener('click', () => {
          state.selectedDay = dayKey;
          display(events);
          const selected = root.querySelector?.('.dendrila-day--selected .dendrila-day-date');
          selected?.focus?.();
        });
        cell.append(dateButton);
        if (matches.length) {
          const count = el('span', 'dendrila-day-count', matches.length);
          count.setAttribute('aria-hidden', 'true');
          cell.append(count);
        }
      } else {
        cell.append(el('h3', 'dendrila-day-date', fullDayFormatter.format(day)));
      }
      const entries = el('ul', 'dendrila-day-events');
      const displayed = month ? matches.slice(0, 5) : matches;
      displayed.forEach(event => {
        const li = el('li');
        li.append(eventRow(event));
        entries.append(li);
      });
      if (month && matches.length > 5) {
        entries.append(el('li', 'dendrila-overflow', `+${matches.length - 5}`));
      }
      cell.append(entries);
      root.append(cell);
    }
    if (!visible && !month) root.append(el('p', 'dendrila-empty', i18n.empty || 'No events for this period.'));
    if (dayDetails) {
      dayDetails.replaceChildren();
      dayDetails.hidden = !month;
      if (month) {
        const date = new Date(state.selectedDay + 'T12:00:00Z');
        dayDetails.append(el('h3', 'dendrila-day-details-heading', fullDayFormatter.format(date)));
        const selectedEvents = events.filter(event => eventCoversDay(event, state.selectedDay));
        if (selectedEvents.length) {
          const list = el('ul', 'dendrila-day-details-events');
          selectedEvents.forEach(event => { const item = el('li'); item.append(eventRow(event)); list.append(item); });
          dayDetails.append(list);
        } else {
          dayDetails.append(el('p', 'dendrila-day-details-empty', i18n.emptyDay || 'No events for this day.'));
        }
      }
    }
  }
  async function load() {
    if (state.request) state.request.abort();
    const controller = new AbortController();
    state.request = controller;
    const r = range();
    if (state.selectedSources.size === 0) {
      message.textContent = '';
      state.events = [];
      state.loaded = true;
      renderFiltered();
      return;
    }
    message.textContent = i18n.loading || 'Loading…';
    state.events = [];
    state.loaded = false;
    // Update the displayed month immediately, even when the network later fails.
    display([]);
    const url = new URL(config.ajaxUrl);
    url.searchParams.set('action', 'dendrila_calendar_events');
    url.searchParams.set('nonce', config.nonce);
    url.searchParams.set('start', key(r.start));
    url.searchParams.set('end', key(r.end));
    url.searchParams.set('sources', Array.from(state.selectedSources).join(','));
    try {
      const response = await fetch(url.href, { credentials: 'same-origin', signal: controller.signal, cache: 'no-store' });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const payload = await response.json();
      if (!payload.success || !Array.isArray(payload.data?.events)) throw new Error('Invalid events payload');
      if (state.request !== controller) return;
      state.events = payload.data.events;
      state.loaded = true;
      renderFiltered();
      // Display per-source counts so a missing event is traceable to a provider or the UI.
      const counts = payload.data.source_counts || {};
      const warnings = payload.data.source_warnings || {};
      const stats = Object.keys(sources)
        .filter(source => state.selectedSources.has(source))
        .map(source => `${sources[source]}: ${Number(counts[source] || 0)}${warnings[source] ? ` ⚠ ${warnings[source]}` : ''}`);
      message.textContent = stats.join(' · ');
    } catch (error) {
      if (error.name === 'AbortError' || state.request !== controller) return;
      root.replaceChildren();
      state.loaded = false;
      message.textContent = `${i18n.failed || 'Unable to load events.'} (${error.message || 'Request failed'})`;
    }
  }
  const otherSources = Object.keys(sources).filter(slug => !primarySources.includes(slug));
  const optional = otherSources.length ? el('details', 'dendrila-optional-sources') : null;
  const optionalList = optional ? el('div', 'dendrila-optional-source-options') : null;
  if (optional) {
    optional.append(el('summary', '', i18n.optionalSources || 'Other booking plugins (optional)'));
    optional.append(el('p', 'description', i18n.optionalSourceHelp || 'Dendrila works without other booking plugins.'));
    optional.append(optionalList);
  }
  Object.entries(sources).forEach(([slug, title]) => {
    const label = el('label', 'dendrila-source-option');
    const checkbox = el('input');
    checkbox.type = 'checkbox';
    checkbox.checked = state.selectedSources.has(slug);
    checkbox.value = slug;
    checkbox.addEventListener('change', () => {
      if (checkbox.checked) state.selectedSources.add(slug); else state.selectedSources.delete(slug);
      rememberSources(state.selectedSources);
      load();
    });
    label.append(checkbox, document.createTextNode(' ' + title));
    if (optional && !primarySources.includes(slug)) optionalList.append(label);
    else toggles.append(label);
  });
  if (optional) toggles.append(optional);
  if (search) search.addEventListener('input', () => { if (state.loaded) renderFiltered(); });
  const setAllSources = selected => {
    // Select all/none is limited to core Dendrila sources. Legacy integrations
    // remain opt-in even when the optional details section is collapsed.
    toggles.querySelectorAll('input[type=checkbox]').forEach(box => {
      if (primarySources.includes(box.value)) box.checked = selected;
    });
    state.selectedSources = new Set(Array.from(state.selectedSources).filter(id => !primarySources.includes(id)));
    if (selected) primarySources.forEach(id => state.selectedSources.add(id));
    rememberSources(state.selectedSources);
    load();
  };
  document.getElementById('dendrila-sources-all')?.addEventListener('click', () => setAllSources(true));
  document.getElementById('dendrila-sources-none')?.addEventListener('click', () => setAllSources(false));
  previousButton.addEventListener('click', () => navigate(-1));
  nextButton.addEventListener('click', () => navigate(1));
  document.getElementById('dendrila-today').addEventListener('click', () => { state.selected = today(); state.selectedDay = key(state.selected); load(); });
  viewSelect.addEventListener('change', () => { state.view = viewSelect.value; load(); });
  // A direct month selector prevents users having to click previous/next repeatedly.
  monthPicker?.addEventListener('change', () => {
    const value = monthPicker.value;
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(value)) return;
    const [year, month] = value.split('-').map(Number);
    if (year < 100 || year > 9999) return;
    state.selected = new Date(Date.UTC(year, month - 1, 1, 12));
    state.selectedDay = key(state.selected);
    load();
  });
  // Touch navigation is limited to deliberate horizontal gestures on the month grid.
  // Never preventDefault: vertical scrolling and taps must keep their native behavior.
  let touchOrigin = null;
  let suppressTapUntil = 0;
  // A swipe may begin on a day number, but must never activate that day as a tap.
  root.addEventListener('click', event => {
    if (Date.now() < suppressTapUntil && event.target instanceof Element && event.target.closest('.dendrila-day-date')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      suppressTapUntil = 0;
    }
  }, true);
  root.addEventListener('touchstart', event => {
    touchOrigin = null;
    if (state.view !== 'month' || event.touches.length !== 1) return;
    const target = event.target;
    if (target instanceof Element && target.closest('button:not(.dendrila-day-date), a, input, select, textarea, summary, [role="button"]')) return;
    const touch = event.touches[0];
    touchOrigin = { x: touch.clientX, y: touch.clientY, at: Date.now() };
  }, { passive: true });
  root.addEventListener('touchend', event => {
    if (!touchOrigin || state.view !== 'month' || event.changedTouches.length !== 1) { touchOrigin = null; return; }
    const origin = touchOrigin;
    touchOrigin = null;
    const touch = event.changedTouches[0];
    const dx = touch.clientX - origin.x;
    const dy = touch.clientY - origin.y;
    if (Date.now() - origin.at > 1400 || Math.abs(dx) < 55 || Math.abs(dx) < Math.abs(dy) * 1.45) return;
    suppressTapUntil = Date.now() + 500;
    navigate(dx < 0 ? 1 : -1);
  }, { passive: true });
  root.addEventListener('touchcancel', () => { touchOrigin = null; }, { passive: true });

  function navigate(delta) {
    if (state.view === 'week') state.selected = addDays(state.selected, 7 * delta);
    else state.selected = new Date(Date.UTC(state.selected.getUTCFullYear(), state.selected.getUTCMonth() + delta, 15, 12));
    state.selectedDay = key(state.selected);
    load();
  }
  state.selected = today();
  load();
})();
