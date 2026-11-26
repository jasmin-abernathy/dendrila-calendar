/* Public booking UI. The server owns prices, availability and every reservation. */
(function () {
    'use strict';

    function init(root) {
        if (root.dataset.dcReady) return;
        root.dataset.dcReady = '1';
        const find = name => root.querySelector('[data-dc-' + name + ']');
        const status = find('status');
        const loading = find('loading');
        let config;
        try { config = JSON.parse(root.querySelector('[data-dendrila-booking-config]').textContent); }
        catch (_) { loading.textContent = 'The booking form could not load. Please reload this page.'; return; }
        const t = (key, value) => String(config.i18n[key] || key).replace('%s', () => String(value === undefined ? '' : value));
        const message = (text, error, focus) => {
            status.textContent = text;
            status.dataset.state = error ? 'error' : 'info';
            if (focus && text) status.focus();
        };
        if (!window.fetch || !window.crypto || !window.crypto.getRandomValues || typeof AbortController === 'undefined') {
            loading.hidden = true;
            message(t('unsupported'), true, false);
            return;
        }
        const form = find('form');
        const fields = find('fields');
        const serviceInput = find('service');
        const dateInput = find('date');
        const slotFieldset = find('slots-fieldset');
        const slotsNode = find('slots');
        const slotNote = find('slot-note');
        const contact = find('contact');
        const submit = find('submit');
        const resultNode = find('result');
        const services = new Map((config.services || []).map(service => [String(service.service_id), service]));
        let service = null;
        let selected = null;
        let requestNumber = 0;
        let slotController = null;
        let submitting = false;
        let requestKey = '';
        let requestSignature = '';
        let receipt = null;
        let cancelling = false;
        let browserZone = '';
        try { browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (_) { /* Host timezone still shown. */ }

        function node(tag, text, className) {
            const element = document.createElement(tag);
            if (text !== undefined) element.textContent = text;
            if (className) element.className = className;
            return element;
        }

        function formatDate(value, timezone, compact) {
            const date = new Date(value);
            if (!Number.isFinite(date.getTime())) return String(value || '');
            try {
                return new Intl.DateTimeFormat(config.locale || undefined, {
                    timeZone: timezone || 'UTC', year: compact ? undefined : 'numeric',
                    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
                }).format(date);
            } catch (_) { return date.toISOString().replace('T', ' ').replace('.000Z', ' UTC'); }
        }

        function createKey() {
            if (typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
        }

        function resetSlots() {
            selected = null;
            slotsNode.replaceChildren();
            slotsNode.setAttribute('aria-busy', 'false');
            contact.hidden = true;
            submit.disabled = true;
            find('selection').textContent = '';
        }

        async function request(action, values, signal) {
            const body = new URLSearchParams(Object.assign({ action: action, nonce: config.nonce }, values));
            let response;
            try {
                response = await window.fetch(config.ajaxUrl, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: body.toString(), signal: signal
                });
            } catch (error) { error.network = true; throw error; }
            let payload;
            try { payload = await response.json(); }
            catch (_) {
                const error = new Error(t('network'));
                error.network = true;
                throw error;
            }
            if (!response.ok || !payload || payload.success !== true) {
                const detail = payload && payload.data;
                const error = new Error(detail && typeof detail.message === 'string' ? detail.message : t('failed'));
                error.status = response.status;
                error.code = detail && typeof detail.code === 'string' ? detail.code : '';
                throw error;
            }
            return payload.data || {};
        }

        function validSlot(slot) {
            return slot && typeof slot.start === 'string' && typeof slot.end === 'string' &&
                /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/.test(slot.start) &&
                /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/.test(slot.end) &&
                Number.isFinite(Date.parse(slot.start)) && Date.parse(slot.end) > Date.parse(slot.start);
        }

        function renderSlots(slots) {
            slots.forEach(slot => {
                const label = node('label', undefined, 'dc-booking-slot');
                const radio = node('input');
                radio.type = 'radio';
                radio.name = serviceInput.id + '-slot';
                radio.value = slot.start;
                radio.required = true;
                const content = node('span', undefined, 'dc-booking-slot-content');
                content.append(node('span', String(slot.label || formatDate(slot.start, service.timezone, true)), 'dc-booking-slot-time'));
                if (/^[+-]\d{2}:\d{2}$/.test(slot.offset || '')) {
                    content.append(node('span', 'UTC' + slot.offset, 'dc-booking-slot-offset'));
                }
                if (browserZone && browserZone !== service.timezone) {
                    content.append(node('span', t('yourTime', formatDate(slot.start, browserZone, true)), 'dc-booking-slot-local'));
                }
                radio.addEventListener('change', () => {
                    if (!radio.checked || submitting) return;
                    selected = slot;
                    contact.hidden = false;
                    submit.disabled = false;
                    find('selection').textContent = t('selected', formatDate(slot.start, service.timezone, false)) + ' · ' + service.timezone;
                    message('', false, false);
                });
                label.append(radio, content);
                slotsNode.append(label);
            });
        }

        async function loadSlots(keepStatus) {
            const sequence = ++requestNumber;
            if (slotController) slotController.abort();
            slotController = null;
            resetSlots();
            slotFieldset.hidden = !service;
            slotFieldset.disabled = true;
            if (!service) return;
            const date = dateInput.value;
            if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || (dateInput.min && date < dateInput.min) || (dateInput.max && date > dateInput.max)) {
                slotNote.textContent = t('chooseDate');
                if (!keepStatus) message(t('chooseDate'), false, false);
                return;
            }
            slotController = new AbortController();
            const controller = slotController;
            const timeout = window.setTimeout(() => controller.abort(), 30000);
            slotsNode.setAttribute('aria-busy', 'true');
            slotNote.textContent = t('loading');
            if (!keepStatus) message(t('loading'), false, false);
            try {
                const data = await request('dendrila_booking_slots', { service_id: service.service_id, date: date }, controller.signal);
                if (sequence !== requestNumber) return;
                if (typeof data.timezone === 'string' && data.timezone) {
                    try {
                        new Intl.DateTimeFormat(config.locale || undefined, { timeZone: data.timezone });
                        service = Object.assign({}, service, { timezone: data.timezone });
                        showTimezone();
                    } catch (_) { /* Keep the validated timezone if the response is malformed. */ }
                }
                const slots = Array.isArray(data.slots) ? data.slots.filter(validSlot) : [];
                // Availability response is display-only; service rules still come from the public service definition.
                renderSlots(slots);
                slotNote.textContent = t(slots.length ? 'chooseSlot' : 'empty');
                if (!keepStatus) message(t(slots.length ? 'chooseSlot' : 'empty'), false, false);
            } catch (error) {
                if (sequence !== requestNumber) return;
                slotNote.textContent = t('slotsFailed');
                message(error.network || error.name === 'AbortError' ? t('slotsFailed') : error.message, true, true);
            } finally {
                window.clearTimeout(timeout);
                if (sequence === requestNumber) {
                    slotFieldset.disabled = false;
                    slotsNode.setAttribute('aria-busy', 'false');
                }
            }
        }

        function changeService() {
            service = services.get(serviceInput.value) || null;
            find('service-info').hidden = !service;
            dateInput.disabled = !service;
            if (!service) {
                loadSlots(false);
                message(t('chooseService'), false, false);
                return;
            }
            dateInput.min = /^\d{4}-\d{2}-\d{2}$/.test(service.min_date) ? service.min_date : '';
            dateInput.max = /^\d{4}-\d{2}-\d{2}$/.test(service.max_date) ? service.max_date : '';
            if (!dateInput.value || dateInput.value < dateInput.min || (dateInput.max && dateInput.value > dateInput.max)) dateInput.value = dateInput.min;
            find('details').textContent = [service.profile_title, t('minutes', service.duration_minutes), service.price_text].filter(Boolean).join(' · ');
            showTimezone();
            submit.textContent = t(service.requires_payment ? 'reserve' : 'confirm');
            find('next-step').textContent = t(service.requires_payment ? 'paymentNote' : 'confirmNote');
            loadSlots(false);
        }

        function showTimezone() {
            find('timezone').textContent = t('timezone', service.timezone) + (browserZone && browserZone !== service.timezone ? ' ' + t('localTimezone', browserZone) : '');
        }

        function safeCheckout(value) {
            try {
                const url = new URL(value, window.location.href);
                return value && /^https?:$/.test(url.protocol) && url.origin === window.location.origin && !url.username && !url.password ? url.href : '';
            } catch (_) { return ''; }
        }

        function showReceipt(data) {
            receipt = Object.assign({}, data);
            const closed = data.status === 'cancelled' || data.status === 'expired';
            const paymentWaiting = data.status === 'payment_on_hold';
            const paidPending = !closed && (data.status === 'pending_payment' || (data.status !== 'confirmed' && (!!data.checkout_url || !!service.requires_payment)));
            form.hidden = true;
            resultNode.hidden = false;
            message('', false, false);
            find('result-title').textContent = t(closed ? data.status : (paymentWaiting ? 'paymentWaiting' : (paidPending ? 'held' : 'confirmed')));
            find('result-description').textContent = t(closed ? data.status + 'Description' : (paymentWaiting ? 'paymentWaitingDescription' : (paidPending ? 'heldDescription' : 'confirmedDescription')));
            const details = find('result-details');
            details.replaceChildren();
            const entry = (key, value) => {
                if (!value) return;
                details.append(node('dt', t(key)), node('dd', String(value)));
            };
            entry('reference', data.reference);
            entry('service', data.service_title || service.title);
            entry('profile', service.profile_title);
            entry('when', formatDate(data.start || selected.start, data.timezone || service.timezone, false) + ' – ' + formatDate(data.end || selected.end, data.timezone || service.timezone, true));
            entry('timezoneLabel', data.timezone || service.timezone);
            entry('price', service.price_text);
            find('result-note').textContent = closed ? '' : t('cancelNote');
            const checkout = find('checkout');
            checkout.hidden = true;
            if (paidPending) {
                const checkoutUrl = safeCheckout(data.checkout_url);
                if (checkoutUrl) {
                    checkout.href = checkoutUrl;
                    checkout.textContent = t(paymentWaiting ? 'viewOrder' : 'checkout');
                    checkout.hidden = false;
                    if (!paymentWaiting && data.hold_expires && Number.isFinite(Date.parse(data.hold_expires))) {
                        find('result-note').textContent = t('heldUntil', formatDate(data.hold_expires, data.timezone || service.timezone, false)) + ' ' + t('cancelNote');
                    }
                } else {
                    message(t('checkoutFailed'), true, false);
                }
            }
            find('ics').hidden = closed || paidPending || !validSlot(data) || !data.reference || typeof TextEncoder === 'undefined' || typeof URL.createObjectURL !== 'function';
            find('cancel').hidden = closed || !(Number(data.booking_id) > 0 && typeof data.cancel_token === 'string' && data.cancel_token);
            find('result-title').focus();
        }

        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (submitting || !service || !selected || !form.reportValidity()) return;
            const values = {
                service_id: service.service_id, start: selected.start,
                name: find('name').value.trim(), email: find('email').value.trim(),
                consent: find('consent').checked ? '1' : '0', website: find('website').value
            };
            const signature = JSON.stringify(values);
            try {
                if (requestSignature !== signature || !requestKey) {
                    requestKey = createKey();
                    requestSignature = signature;
                }
            } catch (_) { message(t('unsupported'), true, true); return; }
            values.request_id = requestKey;
            submitting = true;
            fields.disabled = true;
            form.setAttribute('aria-busy', 'true');
            message(t('sending'), false, false);
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 30000);
            let refreshSlots = false;
            try {
                const data = await request('dendrila_booking_create', values, controller.signal);
                if (!validSlot(data) || !Number.isSafeInteger(Number(data.booking_id)) || Number(data.booking_id) < 1 || typeof data.reference !== 'string' || !data.reference) {
                    const error = new Error(t('network'));
                    error.network = true;
                    throw error;
                }
                showReceipt(data);
            } catch (error) {
                message(error.network || error.name === 'AbortError' ? t('network') : error.message, true, true);
                refreshSlots = error.status === 409 || error.code === 'dendrila_hold_expired' || error.code === 'dendrila_request_reused';
                if (error.code === 'dendrila_hold_expired' || error.code === 'dendrila_request_reused') {
                    requestKey = '';
                    requestSignature = '';
                }
            } finally {
                window.clearTimeout(timeout);
                submitting = false;
                fields.disabled = false;
                form.setAttribute('aria-busy', 'false');
                if (refreshSlots) loadSlots(true);
            }
        });

        find('cancel').addEventListener('click', () => {
            find('cancel-confirm').hidden = false;
            find('cancel-yes').focus();
        });
        find('cancel-no').addEventListener('click', () => {
            if (cancelling) return;
            find('cancel-confirm').hidden = true;
            find('cancel').focus();
        });
        find('cancel-yes').addEventListener('click', async () => {
            if (cancelling || !receipt) return;
            cancelling = true;
            find('cancel-yes').disabled = true;
            find('cancel-no').disabled = true;
            find('cancel').disabled = true;
            message(t('cancelling'), false, false);
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 30000);
            try {
                const data = await request('dendrila_booking_cancel', { booking_id: receipt.booking_id, token: receipt.cancel_token }, controller.signal);
                if (data.status !== 'cancelled') throw new Error(t('failed'));
                receipt.cancel_token = '';
                find('cancel-confirm').hidden = true;
                find('cancel').hidden = true;
                find('ics').hidden = true;
                find('checkout').hidden = true;
                find('result-title').textContent = t('cancelled');
                find('result-description').textContent = t('cancelledDescription');
                find('result-note').textContent = service.requires_payment ? t('cancelledNote') : '';
                message('', false, false);
                find('result-title').focus();
            } catch (error) {
                message(error.network || error.name === 'AbortError' ? t('failed') : error.message, true, true);
            } finally {
                window.clearTimeout(timeout);
                cancelling = false;
                find('cancel-yes').disabled = false;
                find('cancel-no').disabled = false;
                find('cancel').disabled = false;
            }
        });

        function escapeIcs(value) {
            return String(value || '').replace(/\\/g, '\\\\').replace(/\r?\n/g, '\\n').replace(/;/g, '\\;').replace(/,/g, '\\,');
        }
        function foldIcs(line) {
            let output = '';
            let count = 0;
            const encoder = new TextEncoder();
            for (const character of line) {
                const bytes = encoder.encode(character).length;
                if (count + bytes > 75) { output += '\r\n '; count = 1; }
                output += character;
                count += bytes;
            }
            return output;
        }
        find('ics').addEventListener('click', () => {
            if (!receipt || !validSlot(receipt) || !receipt.reference) return;
            const utc = value => new Date(value).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
            const lines = [
                'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Dendrila//Booking//EN', 'CALSCALE:GREGORIAN',
                'BEGIN:VEVENT', 'UID:' + String(receipt.reference).replace(/[^a-z0-9-]/gi, '') + '@dendrila',
                'DTSTAMP:' + utc(Date.now()), 'DTSTART:' + utc(receipt.start), 'DTEND:' + utc(receipt.end),
                'SUMMARY:' + escapeIcs(receipt.service_title || service.title),
                'DESCRIPTION:' + escapeIcs(t('reference') + ': ' + receipt.reference),
                'STATUS:CONFIRMED', 'END:VEVENT', 'END:VCALENDAR'
            ];
            const blob = new Blob([lines.map(foldIcs).join('\r\n') + '\r\n'], { type: 'text/calendar;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = node('a');
            link.href = url;
            link.download = 'dendrila-' + String(receipt.reference).replace(/[^a-z0-9-]/gi, '') + '.ics';
            link.hidden = true;
            root.append(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        });

        serviceInput.addEventListener('change', changeService);
        dateInput.addEventListener('change', () => loadSlots(false));
        loading.hidden = true;
        form.hidden = false;
        changeService();
    }

    function boot() { document.querySelectorAll('[data-dendrila-booking]').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
