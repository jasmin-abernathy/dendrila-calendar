/* Real PHP markup + editor JavaScript + PHP normalization, without Chromium or network. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {spawnSync} = require('node:child_process');
const php = process.env.PHP_BINARY || 'php';
const root = path.resolve(__dirname, '..');
const script = fs.readFileSync(path.join(root, 'assets/calendar-schedule-admin.js'), 'utf8');

function phpRun(args, input = '') {
  const result = spawnSync(php, args, {encoding: 'utf8', input, maxBuffer: 3 * 1024 * 1024});
  if (result.error) throw result.error;
  assert.equal(result.status, 0, result.stderr || result.stdout);
  return JSON.parse(result.stdout);
}
const phpTest = path.join(__dirname, 'test-schedule-admin.php');
const fixtures = phpRun([phpTest, '--fixtures']);
const parseHtml = String.raw`
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body><form id="schedule-form">'.file_get_contents('php://stdin').'</form></body></html>');
function node_data($node) {
  $attrs = array(); foreach ($node->attributes as $a) { $attrs[$a->name] = $a->value; }
  $children = array(); $text = '';
  foreach ($node->childNodes as $child) {
    if ($child->nodeType === XML_ELEMENT_NODE) { $children[] = node_data($child); }
    elseif ($child->nodeType === XML_TEXT_NODE) { $text .= $child->nodeValue; }
  }
  return array('tag'=>$node->nodeName,'attrs'=>$attrs,'children'=>$children,'text'=>$text);
}
echo json_encode(node_data($doc->getElementsByTagName('body')->item(0)));
`;

// Only DOM primitives are simulated. Markup and field names come from the PHP renderer.
let document;
class Element {
  constructor(data, includeNoscript = false) {
    this.tagName = data.tag;
    this.attributes = {...data.attrs};
    this.textContent = data.text || '';
    this.children = [];
    this.parentElement = null;
    this.listeners = {};
    this.checked = Object.hasOwn(this.attributes, 'checked');
    this.disabled = Object.hasOwn(this.attributes, 'disabled');
    this.required = Object.hasOwn(this.attributes, 'required');
    this.value = this.attributes.value || '';
    const children = data.tag === 'noscript' && !includeNoscript ? [] : (data.children || []);
    for (const child of children) this.appendChild(new Element(child, includeNoscript));
    if (data.tag === 'select') {
      const selected = this.children.find(option => Object.hasOwn(option.attributes, 'selected')) || this.children[0];
      this.value = selected ? selected.value : '';
    }
    if (data.tag === 'template') {
      this.content = new Element({tag: '#fragment', children: []});
      for (const child of [...this.children]) this.content.appendChild(child);
    }
    this.dataset = new Proxy({}, {
      get: (_, key) => this.attributes['data-' + String(key).replace(/[A-Z]/g, letter => '-' + letter.toLowerCase())],
      set: (_, key, value) => { this.attributes['data-' + String(key).replace(/[A-Z]/g, letter => '-' + letter.toLowerCase())] = String(value); return true; }
    });
  }
  get id() { return this.attributes.id || ''; }
  set id(value) { this.attributes.id = value; }
  get name() { return this.attributes.name || ''; }
  set name(value) { this.attributes.name = value; }
  get htmlFor() { return this.attributes.for || ''; }
  set htmlFor(value) { this.attributes.for = value; }
  get hidden() { return Object.hasOwn(this.attributes, 'hidden'); }
  set hidden(value) { if (value) this.attributes.hidden = ''; else delete this.attributes.hidden; }
  get firstElementChild() { return this.children[0] || null; }
  get nextElementSibling() { return this.parentElement ? this.parentElement.children[this.parentElement.children.indexOf(this) + 1] || null : null; }
  get previousElementSibling() { return this.parentElement ? this.parentElement.children[this.parentElement.children.indexOf(this) - 1] || null : null; }
  get classList() {
    return {
      add: name => this.classList.toggle(name, true),
      toggle: (name, on) => {
        const classes = new Set((this.attributes.class || '').split(/\s+/).filter(Boolean));
        if (on) classes.add(name); else classes.delete(name);
        this.attributes.class = [...classes].join(' ');
      }
    };
  }
  appendChild(child) { child.remove(); child.parentElement = this; this.children.push(child); return child; }
  remove() {
    if (this.parentElement) this.parentElement.children.splice(this.parentElement.children.indexOf(this), 1);
    this.parentElement = null;
  }
  contains(node) { for (let current = node; current; current = current.parentElement) if (current === this) return true; return false; }
  focus() { document.activeElement = this; }
  select() { this.selected = true; }
  addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
  matches(selector) {
    return selector.split(',').some(part => {
      part = part.trim();
      const tag = part.match(/^[a-z][a-z0-9-]*/i);
      if (tag && this.tagName !== tag[0]) return false;
      const id = part.match(/^#([\w-]+)/);
      if (id && this.id !== id[1]) return false;
      const attributes = [...part.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)];
      return (tag || id || attributes.length) && attributes.every(match => Object.hasOwn(this.attributes, match[1]) && (match[2] === undefined || this.attributes[match[1]] === match[2]));
    });
  }
  querySelectorAll(selector) {
    if (selector.includes('>')) {
      const [left, right] = selector.split('>').map(value => value.trim());
      return this.querySelectorAll(left).flatMap(node => node.children.filter(child => child.matches(right)));
    }
    const found = [];
    const visit = node => { for (const child of node.children) { if (child.matches(selector)) found.push(child); visit(child); } };
    visit(this);
    return found;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  closest(selector) { for (let current = this; current; current = current.parentElement) if (current.matches(selector)) return current; return null; }
  cloneNode(deep) {
    const serialize = node => ({tag: node.tagName, attrs: {...node.attributes}, text: node.textContent, children: deep ? node.children.map(serialize) : []});
    return new Element(serialize(this));
  }
}

function render(kind, scripting = true) {
  document = new Element(phpRun(['-r', parseHtml], fixtures[kind]), !scripting);
  document.readyState = 'complete';
  if (scripting) vm.runInNewContext(script, {window: {dendrilaScheduleAdmin: fixtures.messages}, document}, {filename: 'calendar-schedule-admin.js', timeout: 1500});
  return document;
}
function fire(target, type = 'click') {
  assert.ok(target, 'Missing interactive control.');
  for (let current = target; current; current = current.parentElement) {
    for (const listener of current.listeners[type] || []) listener({target});
  }
}
function setRange(group, start, end, index = 0) {
  const range = group.querySelectorAll('[data-time-range]')[index];
  range.querySelector('[data-range-start]').value = start;
  range.querySelector('[data-range-end]').value = end;
}
function formData() {
  const values = new URLSearchParams();
  for (const input of document.querySelectorAll('input,select')) {
    if (!input.name || input.disabled || (input.attributes.type === 'checkbox' && !input.checked)) continue;
    values.append(input.name, input.value);
  }
  return values.toString();
}
function labelsAreAssociated() {
  const ids = document.querySelectorAll('[id]').map(node => node.id);
  assert.equal(ids.length, new Set(ids).size, 'Dynamic controls must retain unique IDs.');
  const labels = document.querySelectorAll('label');
  for (const input of document.querySelectorAll('input,select')) {
    if (input.attributes.type === 'hidden') continue;
    assert.ok(labels.some(label => label.htmlFor === input.id), `Missing label for ${input.name}.`);
  }
}

render('profile');
labelsAreAssociated();
const monday = document.querySelector('[data-day="1"]');
assert.equal(monday.querySelectorAll('[data-range-end]')[1].value, '00:00');
const tuesday = document.querySelector('[data-day="2"]');
assert.equal(tuesday.querySelector('[data-time-ranges]').hidden, true);
fire(tuesday.querySelector('[data-add-range]'));
setRange(tuesday, '10:00', '12:00');
fire(tuesday.querySelector('[data-add-range]'));
setRange(tuesday, '14:00', '16:00', 1);
fire(tuesday.querySelector('[data-remove-range]'));
assert.equal(tuesday.querySelector('[data-range-start]').value, '14:00');
assert.equal(tuesday.querySelector('[data-range-start]').name, 'dendrila_schedule_profile[weekly][2][0][start]');
fire(tuesday.querySelector('[data-remove-range]'));
assert.equal(tuesday.querySelector('[data-schedule-closed]').checked, true);
assert.equal(tuesday.querySelector('[data-range-start]').disabled, true);
fire(tuesday.querySelector('[data-add-range]'));
setRange(tuesday, '09:00', '10:00');

const editor = document.querySelector('[data-dendrila-schedule]');
fire(editor.querySelector('[data-add-exception]'));
const exceptions = document.querySelector('[data-exceptions]');
exceptions.children.at(-1).querySelector('[data-exception-date]').value = '2026-12-31';
fire(editor.querySelector('[data-add-exception]'));
const extra = exceptions.children.at(-1);
extra.querySelector('[data-exception-date]').value = '2027-01-02';
fire(extra.querySelector('[data-add-range]'));
setRange(extra, '10:00', '11:00');
fire(extra.querySelector('[data-add-range]'));
setRange(extra, '12:00', '13:00', 1);
fire(exceptions.children[0].querySelector('[data-remove-exception]'));
document.querySelector('#dendrila-profile-external').checked = false;
labelsAreAssociated();
const saved = phpRun([phpTest, '--submit-profile'], formData());
assert.deepEqual(saved.errors, []);
assert.equal(saved.settings.weekly['1'][1].end, '24:00');
assert.deepEqual(saved.settings.weekly['2'], [{start: '09:00', end: '10:00'}]);
assert.equal(Object.hasOwn(saved.settings.overrides, '2026-12-25'), false);
assert.deepEqual(saved.settings.overrides['2026-12-31'], []);
assert.equal(saved.settings.overrides['2027-01-02'].length, 2);
assert.equal(saved.settings.check_external, false);
vm.runInNewContext(script, {window: {dendrilaScheduleAdmin: fixtures.messages}, document}, {timeout: 1500});
assert.equal(editor.listeners.click.length, 1, 'Loading the script twice must not register duplicate handlers.');

render('rules');
labelsAreAssociated();
document.querySelector('#dendrila-rules-horizon').value = '0';
document.querySelector('#dendrila-rules-date_from').value = '2026-12-01';
document.querySelector('#dendrila-rules-date_to').value = '2026-12-31';
const service = phpRun([phpTest, '--submit-rules'], formData());
assert.deepEqual(service.errors, []);
assert.equal(service.settings.horizon_days, 0);
assert.equal(service.settings.date_to, '2026-12-31');
const shortcode = document.querySelector('#dendrila-booking-shortcode-service');
assert.equal(shortcode.value, '[dendrila_booking service="31"]');
fire(shortcode);
assert.equal(shortcode.selected, true);

render('profile', false);
labelsAreAssociated();
document.querySelector('#dendrila-profile-day-2-closed').checked = false;
document.querySelector('#dendrila-profile-day-2-range-0-start').value = '08:00';
document.querySelector('#dendrila-profile-day-2-range-0-end').value = '09:00';
const fallback = phpRun([phpTest, '--submit-profile'], formData());
assert.deepEqual(fallback.errors, []);
assert.deepEqual(fallback.settings.weekly['2'], [{start: '08:00', end: '09:00'}]);
assert.equal(fallback.settings.overrides['2026-12-26'].length, 1);
console.log('Schedule admin DOM: actual PHP markup, dynamic ranges/dates, unique labeled controls, PHP form round trips and no-JavaScript saving passed. Visual layout requires browser review.');
