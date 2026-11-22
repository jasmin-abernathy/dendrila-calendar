# Dendrila Calendar 🌱

**Status:** first internal test `0.0.1` — development only; not a beta or WordPress.org release.

**Dendrila Calendar is an independent, private calendar hub for WordPress.** It brings together events and bookings from compatible plugins in one administrator interface. Every integration is optional, including Oryx Bookings. Imported items remain read-only and their original plugins retain control of bookings and payments.

**Project repository:** https://github.com/jasmin-abernathy/dendrila-calendar

## What currently works

- Day-by-day **month, week and list** views; source filters and keyboard-operable controls.
- **Dendrila events**: create/edit your own events using a private WordPress post type (admin only).
- **Optional event sources**: Event Organiser, Events Manager and The Events Calendar (single published events only; recurring occurrences are not yet included).
- **Optional booking sources**: Amelia, Booking Calendar, Bookly, Easy Appointments, FluentBooking, Oryx Bookings and Simply Schedule Appointments.
- **Ten external integrations in total**, all read-only. Provider versions, supported interfaces and caveats are in [the integration audit](docs/INTEGRATIONS.md). No customer contact information or private notes are included in calendar event payloads.
- **Optional adapter API**: register sources through WordPress filters without patching other plugins.
- No vendor scripts, CDN, tracking, third-party API calls, custom tables, or mandatory WooCommerce dependency.

## Requirements and installation

- WordPress 6.5+, PHP 7.4+.
- Install the `dendrila-calendar/` folder containing `dendrila-calendar.php` in `wp-content/plugins/`.
- Activate it, then visit **Dendrila Calendar** in the administrator menu.
- All third-party sources are **optional**; source checkboxes appear when active. See the [provider compatibility table](docs/INTEGRATIONS.md).
- Calendar visibility is restricted to administrators (`manage_options`). Imported sources remain owned by their original plugins.

## Extension API

```php
add_filter( 'dendrila_calendar_sources', function ( $sources ) {
    $sources['my_source'] = 'My source';
    return $sources;
} );

add_filter( 'dendrila_calendar_source_events_my_source', function ( $events, $start_utc, $end_utc ) {
    // Query only events overlapping [$start_utc, $end_utc), both 'Y-m-d H:i:s' UTC.
    return array(
        array(
            'id' => 'my_source-12',
            'title' => 'Example',
            'start' => '2026-11-24T09:00:00Z',
            'end' => '2026-11-24T10:00:00Z',
            'status' => 'confirmed',
            'url' => admin_url( 'admin.php?page=my-source' ),
        ),
    );
}, 10, 3 );
```

Source IDs must match `[a-z][a-z0-9_-]{0,31}`. Source adapters must filter by the supplied UTC range and must not leak personal information. The endpoint requires an authenticated administrator and a WordPress nonce. The `url` must resolve to this site's origin to be clickable in the UI.

## Design and security

- Imported sources: **read-only**. Creation/editing remains in each source's official interface.
- AJAX: capability and nonce checks, validated date bounds (maximum 93 days), source allowlist, event caps.
- All event titles inserted as `textContent` (not HTML).
- WordPress local event dates are converted to UTC for storage; the interface displays time in the configured WordPress site timezone.
- No cross-site event data is sent to third parties.
- No `Requires Plugins` header: optional integrations **must not** block plugin installation.

## Known limitations / roadmap

- No public-facing calendar yet (private booking data must not be exposed).
- The Events Calendar recurrence/occurrences still require dedicated version-specific support.
- No ICS/CalDAV sync yet; **do not** add unaudited remote URL fetching.
- No drag-and-drop editing of imported bookings. This avoids bypassing source-specific validation and payment rules.
- Eight further adapters have been inspected and covered by isolated fixtures. Their behavior still requires real staging tests.
- ICS Calendar 12.3.0 has been reviewed but is **not** imported yet, because it primarily renders remote feeds and a naive URL fetch would introduce SSRF risk.
- Each provider currently limits results to at most 600–750 events per request; this is not yet paginated.
- Booking Calendar is shown as all-day allocations, not exact appointment times.
- There is no live WordPress + WooCommerce end-to-end test yet. Please test on a staging site before production use.

## Development checks

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/calendar.js
php tests/test-sources.php
php tests/test-integrations.php
```

License: GPL-2.0-or-later.
