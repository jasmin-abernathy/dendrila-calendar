/* Export only the events already received by the authenticated calendar UI.
 * No unauthenticated feed, remote ICS fetch or third-party library is used.
 */
(function (root) {
  'use strict';
  function escapeText(value) {
    return String(value == null ? '' : value)
      .replace(/\\/g, '\\\\')
      .replace(/\r\n|\r|\n/g, '\\n')
      .replace(/,/g, '\\,')
      .replace(/;/g, '\\;');
  }
  function foldLine(line) {
    const encoder = new TextEncoder();
    let out = '';
    let count = 0;
    for (const character of String(line)) {
      const length = encoder.encode(character).length;
      if (count + length > 75) {
        out += '\r\n ';
        count = 1;
      }
      out += character;
      count += length;
    }
    return out;
  }
  function utcStamp(raw) {
    const date = new Date(raw);
    if (Number.isNaN(date.getTime())) return null;
    return date.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
  }
  function generate(events, siteDate, generatedAt, hostname) {
    if (!Array.isArray(events) || typeof siteDate !== 'function') {
      throw new TypeError('Calendar events and site date formatter are required');
    }
    const now = utcStamp(generatedAt || new Date());
    const host = String(hostname || 'localhost').toLowerCase().replace(/[^a-z0-9.-]/g, '') || 'localhost';
    const lines = [
      'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Dendrila Calendar//EN',
      'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:Dendrila Calendar',
    ];
    for (const event of events) {
      if (!event || !event.start || !event.end) continue;
      const start = new Date(event.start);
      const end = new Date(event.end);
      if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end <= start) continue;
      const source = String(event.source || 'event').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 32);
      const id = String(event.id || '').replace(/[^a-zA-Z0-9_.-]/g, '_').slice(0, 120);
      if (!id) continue;
      const uid = 'dendrila-' + source + '-' + id + '@' + host;
      const summary = escapeText(event.title || 'Event');
      lines.push('BEGIN:VEVENT', 'UID:' + uid, 'DTSTAMP:' + now);
      if (event.all_day) {
        // RFC 5545: all-day DTEND is an exclusive local calendar date.
        const first = String(siteDate(start)).replace(/-/g, '');
        const last = String(siteDate(end)).replace(/-/g, '');
        if (!/^\d{8}$/.test(first) || !/^\d{8}$/.test(last) || last <= first) {
          lines.splice(lines.length - 3, 3);
          continue;
        }
        lines.push('DTSTART;VALUE=DATE:' + first, 'DTEND;VALUE=DATE:' + last);
      } else {
        lines.push('DTSTART:' + utcStamp(start), 'DTEND:' + utcStamp(end));
      }
      lines.push('SUMMARY:' + summary, 'END:VEVENT');
    }
    lines.push('END:VCALENDAR');
    return lines.map(foldLine).join('\r\n') + '\r\n';
  }
  root.DendrilaCalendarIcs = Object.freeze({ generate });
})(typeof window !== 'undefined' ? window : globalThis);
