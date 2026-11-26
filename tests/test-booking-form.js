/* Public booking UI regression checks. Run with Node; no browser or provider account required. */
'use strict';
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const crypto = require('crypto').webcrypto;
const code = fs.readFileSync(path.join(__dirname, '..', 'assets', 'calendar-booking.js'), 'utf8');
const php = fs.readFileSync(path.join(__dirname, '..', 'includes', 'class-dendrila-calendar-booking-form.php'), 'utf8');
const strings = Object.fromEntries(Array.from(php.matchAll(/'([A-Za-z]+)'\s*=>\s*__\( '((?:\\.|[^'])*)', 'dendrila-calendar' \)/g), match => [match[1], match[2].replace(/\\'/g, "'")]));
const originalService = {
  service_id: 8, profile_id: 4, title: 'Consultation', profile_title: 'Studio',
  timezone: 'Europe/Paris', duration_minutes: 30, min_date: '2030-10-27', max_date: '2030-11-30',
  requires_payment: false, price_text: ''
};
const slot = { start: '2030-10-27T08:30:00Z', end: '2030-10-27T09:00:00Z', label: '09:30', offset: '+01:00' };
const otherSlot = { start: '2030-10-28T09:00:00Z', end: '2030-10-28T09:30:00Z', label: '10:00', offset: '+01:00' };
const tick = () => new Promise(resolve => setImmediate(resolve));
const response = (data, status = 200, success = true) => ({ ok: status >= 200 && status < 300, status, json: async () => ({ success, data }) });
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };

class Element {
  constructor(tag = 'div') {
    this.tagName = tag.toUpperCase(); this.children = []; this.dataset = {}; this.attributes = {};
    this.handlers = {}; this.hidden = false; this.disabled = false; this.value = ''; this.textContent = '';
    this.checked = false; this.id = ''; this.focused = false;
  }
  set innerHTML(_) { throw new Error('Booking UI must not inject HTML from a service or AJAX response'); }
  addEventListener(name, handler) { (this.handlers[name] ||= []).push(handler); }
  async fire(name, props = {}) { for (const handler of this.handlers[name] || []) await handler(Object.assign({ preventDefault() {} }, props)); }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  focus() { this.focused = true; }
  click() { this.clicked = true; }
  remove() { this.removed = true; }
}

function fixture(options = {}) {
  const timers = new Set();
  const elements = {};
  for (const name of [
    'status', 'loading', 'form', 'fields', 'service', 'date', 'slots-fieldset', 'slots', 'slot-note', 'contact',
    'submit', 'result', 'service-info', 'details', 'timezone', 'selection', 'next-step', 'name', 'email',
    'consent', 'website', 'result-title', 'result-description', 'result-details', 'result-note', 'checkout',
    'ics', 'cancel', 'cancel-confirm', 'cancel-yes', 'cancel-no'
  ]) elements[name] = new Element();
  elements.service.id = 'booking-' + Math.random().toString(16).slice(2) + '-service';
  const service = Object.assign({}, originalService, options.service);
  elements.service.value = String(service.service_id);
  elements.name.value = 'Test Visitor';
  elements.email.value = 'visitor@example.invalid';
  elements.consent.checked = true;
  elements.form.hidden = true;
  elements.result.hidden = true;
  elements['cancel-confirm'].hidden = true;
  elements.form.reportValidity = () => !!elements.name.value.trim() && /.+@.+\..+/.test(elements.email.value) && elements.consent.checked;
  const config = new Element('script');
  config.textContent = JSON.stringify({ ajaxUrl: 'https://booking.example/wp-admin/admin-ajax.php', nonce: 'public-nonce', locale: 'fr-FR', services: [service], i18n: strings });
  const root = new Element('section');
  root.querySelector = selector => selector === '[data-dendrila-booking-config]' ? config : elements[selector.match(/data-dc-(.*)\]/)[1]];
  const requests = [];
  const blobs = [];
  let handler = options.handler || ((action) => action === 'dendrila_booking_slots' ? response({ slots: [slot] }) : response({}));
  const fakeURL = class extends URL {};
  fakeURL.createObjectURL = blob => { blobs.push(blob); return 'blob:https://booking.example/test'; };
  fakeURL.revokeObjectURL = () => {};
  const window = {
    crypto: options.crypto === false ? null : crypto,
    location: { href: 'https://booking.example/reserve/', origin: 'https://booking.example' },
    setTimeout(fn, ms) { const id = setTimeout(fn, ms); timers.add(id); return id; },
    clearTimeout(id) { clearTimeout(id); timers.delete(id); },
    fetch: async (url, settings) => {
      const body = Object.fromEntries(new URLSearchParams(settings.body));
      requests.push({ url, settings, body });
      return handler(body.action, body, requests.length);
    }
  };
  const document = { readyState: 'complete', querySelectorAll: () => [root], createElement: tag => new Element(tag) };
  vm.runInNewContext(code, { window, document, URL: fakeURL, URLSearchParams, Date, Intl, AbortController, Uint8Array, TextEncoder, Blob, Map, console }, { timeout: 1000 });
  return {
    root, elements, requests, blobs, service,
    handle(fn) { handler = fn; },
    async choose(index = 0) {
      const radio = elements.slots.children[index].children[0];
      radio.checked = true;
      await radio.fire('change');
    },
    close() { for (const timer of timers) clearTimeout(timer); }
  };
}

function tree(element) { return [element.textContent || '', ...element.children.map(tree)].join(' '); }

(async () => {
  const active = [];
  const make = options => { const view = fixture(options); active.push(view); return view; };
  try {
    const basic = make();
    await tick();
    assert.strictEqual(basic.elements.form.hidden, false);
    assert.strictEqual(basic.elements.date.min, '2030-10-27');
    assert.strictEqual(basic.elements.date.max, '2030-11-30');
    assert(tree(basic.elements.timezone).includes('Europe/Paris'));
    assert.strictEqual(basic.requests[0].body.nonce, 'public-nonce');
    assert.strictEqual(basic.requests[0].settings.method, 'POST');
    assert.strictEqual(basic.requests[0].settings.cache, 'no-store');
    await basic.choose();
    assert.strictEqual(basic.elements.contact.hidden, false);
    assert.strictEqual(basic.elements.submit.disabled, false);
    basic.elements.consent.checked = false;
    await basic.elements.form.fire('submit');
    assert.strictEqual(basic.requests.length, 1, 'Missing consent must not submit');
    basic.elements.consent.checked = true;

    let attempts = 0;
    const booking = { reference: 'DC-TEST-19', booking_id: 19, cancel_token: 'secret-cancel-token', start: slot.start, end: slot.end, timezone: 'Europe/Paris', service_title: '<img src=x onerror=alert(1)>' };
    basic.handle(action => {
      if (action === 'dendrila_booking_create') {
        if (++attempts === 1) throw new TypeError('Connection lost after the server may have committed');
        return response(booking);
      }
      if (action === 'dendrila_booking_cancel') return response({ status: 'cancelled' });
      return response({ slots: [slot] });
    });
    await basic.elements.form.fire('submit');
    const firstKey = basic.requests.at(-1).body.request_id;
    assert(/^[a-f0-9-]{32,36}$/.test(firstKey));
    assert.strictEqual(basic.elements.form.hidden, false, 'A lost response must leave a retry available');
    assert.strictEqual(basic.elements.status.dataset.state, 'error');
    assert.strictEqual(basic.elements.status.focused, true);
    await basic.elements.form.fire('submit');
    assert.strictEqual(basic.requests.at(-1).body.request_id, firstKey, 'A retry with identical details must reuse its idempotency key');
    assert.strictEqual(basic.requests.at(-1).body.consent, '1');
    assert.strictEqual(basic.requests.at(-1).body.website, '');
    assert.strictEqual(basic.elements.form.hidden, true);
    assert.strictEqual(basic.elements.result.hidden, false);
    assert(tree(basic.elements['result-details']).includes('<img src=x onerror=alert(1)>'), 'Server text must remain literal text');
    assert(!Object.values(basic.elements).map(tree).join(' ').includes('secret-cancel-token'), 'Cancellation token is never written into the DOM');
    await basic.elements.ics.fire('click');
    const calendar = await basic.blobs[0].text();
    assert(calendar.includes('DTSTART:20301027T083000Z'));
    assert(!calendar.includes('visitor@example.invalid'));
    assert(!calendar.includes('secret-cancel-token'));
    await basic.elements.cancel.fire('click');
    assert.strictEqual(basic.elements['cancel-confirm'].hidden, false);
    await basic.elements['cancel-no'].fire('click');
    assert.strictEqual(basic.elements['cancel-confirm'].hidden, true);
    await basic.elements.cancel.fire('click');
    await basic.elements['cancel-yes'].fire('click');
    assert.strictEqual(basic.requests.at(-1).body.token, 'secret-cancel-token');
    assert(!basic.requests.at(-1).url.includes('secret-cancel-token'));
    assert.strictEqual(basic.elements.cancel.hidden, true);
    assert.strictEqual(basic.elements.ics.hidden, true);
    assert.strictEqual(basic.elements['result-title'].textContent, strings.cancelled);

    const slow = deferred();
    let slotCalls = 0;
    const stale = make({ handler: () => ++slotCalls === 1 ? slow.promise : response({ slots: [otherSlot] }) });
    stale.elements.date.value = '2030-10-28';
    await stale.elements.date.fire('change');
    await tick();
    slow.resolve(response({ slots: [slot] }));
    await tick();
    assert.strictEqual(stale.elements.slots.children.length, 1);
    assert.strictEqual(stale.elements.slots.children[0].children[0].value, otherSlot.start, 'Late old-date response must not replace current slots');

    const conflict = make();
    await tick();
    await conflict.choose();
    conflict.handle(action => action === 'dendrila_booking_create' ? response({ message: 'That time was just taken.' }, 409, false) : response({ slots: [] }));
    await conflict.elements.form.fire('submit');
    await tick();
    assert.strictEqual(conflict.elements.slots.children.length, 0);
    assert.strictEqual(conflict.elements.submit.disabled, true);
    assert.strictEqual(conflict.elements.name.value, 'Test Visitor', 'Conflict refresh must preserve customer input');
    assert.strictEqual(conflict.elements.status.textContent, 'That time was just taken.');

    const payment = make({ service: { requires_payment: true, price_text: '45,00 €' } });
    await tick();
    await payment.choose();
    payment.handle(() => response(Object.assign({}, booking, { status: 'pending_payment', checkout_url: 'https://booking.example/checkout/order-pay/123/?key=test-order-key', hold_expires: '2030-10-27T08:20:00Z' })));
    await payment.elements.form.fire('submit');
    assert.strictEqual(payment.elements['result-title'].textContent, strings.held);
    assert.strictEqual(payment.elements.checkout.hidden, false);
    assert.strictEqual(payment.elements.ics.hidden, true, 'Unpaid reservation must never export a confirmed appointment');
    assert.strictEqual(payment.elements.cancel.hidden, false);
    assert(payment.elements['result-note'].textContent.includes('2030'));

    const waiting = make({ service: { requires_payment: true } });
    await tick();
    await waiting.choose();
    waiting.handle(() => response(Object.assign({}, booking, { status: 'payment_on_hold', checkout_url: 'https://booking.example/checkout/order-received/123/?key=test-order-key' })));
    await waiting.elements.form.fire('submit');
    assert.strictEqual(waiting.elements['result-title'].textContent, strings.paymentWaiting);
    assert.strictEqual(waiting.elements.checkout.textContent, strings.viewOrder, 'An on-hold order must not invite a second payment');
    assert.strictEqual(waiting.elements.ics.hidden, true);

    const settled = make({ service: { requires_payment: true } });
    await tick();
    await settled.choose();
    settled.handle(() => response(Object.assign({}, booking, { status: 'confirmed', checkout_url: 'https://booking.example/checkout/order-received/123/?key=test-order-key' })));
    await settled.elements.form.fire('submit');
    assert.strictEqual(settled.elements['result-title'].textContent, strings.confirmed, 'The authoritative paid status must also work for a zero-total Woo order or successful retry');
    assert.strictEqual(settled.elements.ics.hidden, false);

    const expired = make({ service: { requires_payment: true } });
    await tick();
    await expired.choose();
    expired.handle(() => response(Object.assign({}, booking, { status: 'expired' })));
    await expired.elements.form.fire('submit');
    assert.strictEqual(expired.elements['result-title'].textContent, strings.expired);
    assert.strictEqual(expired.elements.ics.hidden, true);
    assert.strictEqual(expired.elements.cancel.hidden, true);

    const unsafePayment = make({ service: { requires_payment: true } });
    await tick();
    await unsafePayment.choose();
    unsafePayment.handle(() => response(Object.assign({}, booking, { status: 'pending_payment', checkout_url: 'https://attacker.invalid/checkout' })));
    await unsafePayment.elements.form.fire('submit');
    assert.strictEqual(unsafePayment.elements.checkout.hidden, true, 'Checkout must remain on the current origin');
    assert.strictEqual(unsafePayment.elements.status.textContent, strings.checkoutFailed);
    assert.strictEqual(unsafePayment.elements.form.hidden, true, 'An already-created hold must not be presented as a failed booking');

    const double = make();
    await tick();
    await double.choose();
    const pending = deferred();
    double.handle(() => pending.promise);
    const saving = double.elements.form.fire('submit');
    await double.elements.form.fire('submit');
    assert.strictEqual(double.requests.filter(item => item.body.action === 'dendrila_booking_create').length, 1, 'A double click must create only one in-flight request');
    pending.resolve(response(booking));
    await saving;

    const changed = make();
    await tick();
    await changed.choose();
    changed.handle(() => { throw new TypeError('Offline'); });
    await changed.elements.form.fire('submit');
    const oldKey = changed.requests.at(-1).body.request_id;
    changed.elements.name.value = 'Another visitor';
    await changed.elements.form.fire('submit');
    assert.notStrictEqual(changed.requests.at(-1).body.request_id, oldKey, 'Different submitted details require a new request key');

    const unsupported = make({ crypto: false });
    assert.strictEqual(unsupported.requests.length, 0);
    assert.strictEqual(unsupported.elements.form.hidden, true);
    assert.strictEqual(unsupported.elements.status.textContent, strings.unsupported);
    console.log('Booking form: consent, dates/timezone, stale responses, safe text, idempotent retry, conflict refresh, duplicate submit, WooCommerce hold, safe checkout, ICS and cancellation passed');
  } finally { active.forEach(view => view.close()); }
})().catch(error => { console.error(error); process.exitCode = 1; });
