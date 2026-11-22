'use strict';
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const ctx = { TextEncoder, Date };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../assets/calendar-ics.js'), 'utf8'), ctx);
const { generate } = ctx.DendrilaCalendarIcs;
const date = time => new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Europe/Paris', year: 'numeric', month: '2-digit', day: '2-digit'
}).format(new Date(time)).replace(/\//g, '-');
const events = [
  { id:'native-1', source:'native', title:'Assemblée, réunion; un\\deux\nété 🌱', start:'2026-11-24T09:00:00Z', end:'2026-11-24T10:00:00Z' },
  { id:'booking-2', source:'booking', title:'All day', start:'2027-03-27T23:00:00Z', end:'2027-03-28T22:00:00Z', all_day:true },
  { id:'invalid', source:'native', title:'Invalid', start:'2026-11-24T12:00:00Z', end:'2026-11-24T10:00:00Z' },
];
const ics = generate(events, date, new Date('2026-11-22T12:00:00Z'), 'example.org');
assert(ics.startsWith('BEGIN:VCALENDAR\r\nVERSION:2.0\r\n'));
assert.equal((ics.match(/BEGIN:VEVENT/g)||[]).length, 2);
assert(ics.includes('UID:dendrila-native-native-1@example.org'));
assert(ics.includes('DTSTART:20261124T090000Z\r\nDTEND:20261124T100000Z'));
assert(ics.includes('DTSTART;VALUE=DATE:20270328\r\nDTEND;VALUE=DATE:20270329'));
assert(ics.includes('SUMMARY:Assemblée\\, réunion\\; un\\\\deux\\nété 🌱'));
assert(!ics.includes('Invalid'));
assert(ics.endsWith('END:VCALENDAR\r\n'));
for (const line of ics.split('\r\n')) {
  assert(Buffer.byteLength(line, 'utf8') <= 75, 'ICS line exceeds 75 octets: ' + line);
}
assert(!ics.includes('\nBEGIN:VEVENT\n'), 'ICS must use CRLF');
console.log('ICS export verified: UTF-8 folding, escaping, DST, invalid events, no network');
