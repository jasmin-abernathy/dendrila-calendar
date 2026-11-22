/* global dendrilaCalendar */
(() => {
  'use strict';
  const config = window.dendrilaCalendar;
  if (!config) return;

  const root = document.getElementById('dendrila-grid');
  const period = document.getElementById('dendrila-period');
  const message = document.getElementById('dendrila-message');
  const viewSelect = document.getElementById('dendrila-view');
  const toggles = document.getElementById('dendrila-source-toggles');
  const search = document.getElementById('dendrila-search');
  if (!root || !period || !message || !viewSelect || !toggles) return;

  const locale = (config.locale || 'en').replace(/_/g, '-');
  const timeZone = config.timezone || 'UTC';
  const firstDay = Number(config.weekStartsOn) || 0;
  const sources = config.sources || {};
  const i18n = config.i18n || {};
  const state = { selected: new Date(), view: 'month', selectedSources: new Set(Object.keys(sources)), request: null, events: [], loaded: false };
  const dayFormatter = new Intl.DateTimeFormat('en-GB', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' });
  const monthFormatter = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', month: 'long', year: 'numeric' });
  const weekdayFormatter = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', weekday: 'short' });
  const fullDayFormatter = new Intl.DateTimeFormat(locale, { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long' });
  const timeFormatter = new Intl.DateTimeFormat(locale, { timeZone, hour: '2-digit', minute: '2-digit' });

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
      cell.append(el('h3', 'dendrila-day-date', month ? day.getUTCDate() : fullDayFormatter.format(day)));
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
    if (!visible) root.append(el('p', 'dendrila-empty', i18n.empty || 'No events for this period.'));
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
      // Per-source counters distinguish an empty provider from a display/filter problem.
      const counts = payload.data.source_counts || {};
      message.textContent = Object.keys(sources)
        .filter(source => state.selectedSources.has(source))
        .map(source => `${sources[source]}: ${Number(counts[source] || 0)}`)
        .join(' · ');
    } catch (error) {
      if (error.name === 'AbortError' || state.request !== controller) return;
      root.replaceChildren();
      state.loaded = false;
        message.textContent = i18n.failed || 'Unable to load events.';
    }
  }
  Object.entries(sources).forEach(([slug, title]) => {
    const label = el('label', 'dendrila-source-option');
    const checkbox = el('input');
    checkbox.type = 'checkbox';
    checkbox.checked = true;
    checkbox.value = slug;
    checkbox.addEventListener('change', () => {
      if (checkbox.checked) state.selectedSources.add(slug); else state.selectedSources.delete(slug);
      load();
    });
    label.append(checkbox, document.createTextNode(' ' + title));
    toggles.append(label);
  });
  if (search) search.addEventListener('input', () => { if (state.loaded) renderFiltered(); });
  const setAllSources = selected => {
    toggles.querySelectorAll('input[type=checkbox]').forEach(box => { box.checked = selected; });
    state.selectedSources = new Set(selected ? Object.keys(sources) : []);
    load();
  };
  document.getElementById('dendrila-sources-all')?.addEventListener('click', () => setAllSources(true));
  document.getElementById('dendrila-sources-none')?.addEventListener('click', () => setAllSources(false));
  document.getElementById('dendrila-prev').addEventListener('click', () => navigate(-1));
  document.getElementById('dendrila-next').addEventListener('click', () => navigate(1));
  document.getElementById('dendrila-today').addEventListener('click', () => { state.selected = today(); load(); });
  viewSelect.addEventListener('change', () => { state.view = viewSelect.value; load(); });
  function navigate(delta) {
    if (state.view === 'week') state.selected = addDays(state.selected, 7 * delta);
    else state.selected = new Date(Date.UTC(state.selected.getUTCFullYear(), state.selected.getUTCMonth() + delta, 15, 12));
    load();
  }
  state.selected = today();
  load();
})();
