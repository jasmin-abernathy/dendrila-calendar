/* Exercise the actual connection script without a browser or provider account. */
'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const code = fs.readFileSync(path.join(__dirname, '..', 'assets', 'calendar-connect.js'), 'utf8');

function load(initial) {
  const modes = { new: '1', export: '0', both: '1', archivedExport: '0', archivedBoth: '1', legacy: '0' };
  const handlers = {};
  const select = {
    value: initial,
    get selectedOptions() { return [{ dataset: { twoWay: modes[this.value] } }]; },
    addEventListener(type, handler) { handlers[type] = handler; }
  };
  const checkbox = { checked: false };
  const marker = { value: '' };
  const nodes = {
    'dendrila-oauth-calendar': select,
    'dendrila-oauth-two-way': checkbox,
    'dendrila-oauth-mode-calendar': marker
  };
  // The OAuth form must initialize even without the CalDAV form or localization.
  vm.runInNewContext(code, {
    window: {}, document: { getElementById: id => nodes[id] || null }
  }, { filename: 'calendar-connect.js', timeout: 1000 });
  return { select, checkbox, marker, choose(value) { select.value = value; handlers.change(); } };
}

const view = load('new');
assert.strictEqual(view.checkbox.checked, true, 'New calendar should propose two-way synchronization');
assert.strictEqual(view.marker.value, 'new');
for (const [destination, expected] of [
  ['export', false], ['both', true], ['archivedExport', false], ['archivedBoth', true], ['legacy', false], ['new', true]
]) {
  view.choose(destination);
  assert.strictEqual(view.checkbox.checked, expected, `Saved direction must follow ${destination}`);
  assert.strictEqual(view.marker.value, destination, 'Submitted checkbox must identify its destination');
}
view.checkbox.checked = false;
assert.strictEqual(view.marker.value, 'new', 'Unchecking must remain an explicit choice for this calendar');
view.choose('export');
view.checkbox.checked = true;
assert.strictEqual(view.marker.value, 'export', 'Enabling two-way must remain an explicit choice for this calendar');
assert.strictEqual(load('export').checkbox.checked, false, 'Existing export-only calendar must initially remain unchecked');
assert.strictEqual(load('both').checkbox.checked, true, 'Existing two-way calendar must initially remain checked');
vm.runInNewContext(code, { window: {}, document: { getElementById: () => null } }, { timeout: 1000 });
console.log('OAuth selection: initial defaults, destination changes, archived modes and editable checkbox passed');
