/* Run a browser-like calendar smoke test without external dependencies. */
'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

class Node {
  constructor(tag = 'div') {
    this.tagName = tag;
    this.children = [];
    this.dataset = {};
    this.attributes = {};
    this.handlers = {};
    this.value = '';
    this.textContent = '';
    this.className = '';
    this.checked = false;
  }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  setAttribute(key, value) { this.attributes[key] = value; }
  addEventListener(name, handler) { this.handlers[name] = handler; }
  querySelectorAll() { return []; }
  get classList() { return { add: () => {} }; }
}
const ids = {};
const required = [
  'dendrila-grid', 'dendrila-period', 'dendrila-message', 'dendrila-view',
  'dendrila-month', 'dendrila-source-toggles', 'dendrila-search',
  'dendrila-prev', 'dendrila-next', 'dendrila-today',
  'dendrila-sources-all', 'dendrila-sources-none', 'dendrila-day-details'
];
for (const id of required) ids[id] = new Node();
ids['dendrila-view'].value = 'month';
const today = new Date();
const start = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), 15, 12));
const finish = new Date(start.getTime() + 30 * 60000);
const fakeEvent = {
  id: 'booking-1', source: 'bookings', title: 'Prestation test',
  status: 'pending', start: start.toISOString(), end: finish.toISOString(),
  url: 'https://example.org/wp-admin/admin.php?page=easy-appointments'
};
let requests = 0;
const config = {
  ajaxUrl: 'https://example.org/wp-admin/admin-ajax.php',
  nonce: 'testnonce',
  locale: 'fr_FR',
  timezone: 'Europe/Paris',
  weekStartsOn: 1,
  sources: { native: 'Personal events', bookings: 'Your appointments', easy_appointments: 'Easy Appointments' },
  primarySources: ['native', 'bookings'],
  i18n: { loading: 'Chargement', empty: 'Aucun événement', failed: 'Erreur', allDay: 'Toute la journée' }
};
const sandbox = {
  window: { dendrilaCalendar: config, location: { origin: 'https://example.org' } },
  document: {
    getElementById: id => ids[id] || null,
    createElement: tag => new Node(tag),
    createTextNode: txt => ({ textContent: String(txt) })
  },
  fetch: async () => {
    ++requests;
    return {
      ok: true,
      json: async () => ({ success: true, data: {
        events: [fakeEvent],
        source_counts: { bookings: 1 },
        source_warnings: {}
      } })
    };
  },
  URL, Date, Intl, AbortController, Set, Object, Number, Math, console
};
const code = fs.readFileSync(require('path').join(__dirname, '..', 'assets', 'calendar.js'), 'utf8');
vm.runInNewContext(code, sandbox, { filename: 'calendar.js', timeout: 1500 });

function textTree(node) {
  return [node.textContent || '', ...(node.children || []).map(textTree)].join(' ');
}
(async () => {
  await new Promise(resolve => setImmediate(resolve));
  assert(requests > 0, 'Initial AJAX request must be fired for native Dendrila sources');
  assert(ids['dendrila-grid'].children.length > 0, 'Calendar grid should exist');
  assert(textTree(ids['dendrila-grid']).includes('Prestation test'), 'The appointment must be drawn in the calendar');
  const gridDate = `${today.getUTCFullYear()}-${String(today.getUTCMonth() + 1).padStart(2, '0')}-15`;
  const day = ids['dendrila-grid'].children.find(node => node.dataset.day === gridDate);
  assert(day, 'The current month should expose a selectable day cell');
  const dayButton = day.children.find(node => node.tagName === 'button');
  assert(dayButton && typeof dayButton.handlers.click === 'function', 'Month view needs an accessible day picker');
  dayButton.handlers.click();
  assert(textTree(ids['dendrila-day-details']).includes('Prestation test'), 'The selected day should show its appointments');
  for (const view of ['week', 'list']) {
    ids['dendrila-view'].value = view;
    assert(typeof ids['dendrila-view'].handlers.change === 'function', 'View control must work');
    ids['dendrila-view'].handlers.change();
    await new Promise(resolve => setImmediate(resolve));
    assert(ids['dendrila-grid'].className.includes(view), 'Calendar did not switch to ' + view);
  }
  console.log('Calendar DOM smoke test: interactive month, day detail, week and list initialized');
})().catch(error => { console.error(error); process.exitCode = 1; });
