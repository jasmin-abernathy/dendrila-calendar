# Dendrila Calendar 🌱

**Status:** development preview `0.2.0-dev`. Not yet a WordPress.org release.

A **private, unified admin calendar** for WordPress. It collects existing data without creating duplicate booking records or replacing booking/payment engines. The calendar is intentionally read-only for imported sources.

## What currently works

- Day-by-day **month, week and list** views; source filters and keyboard-operable controls.
- **Dendrila events**: create/edit your own events using a private WordPress post type (admin only).
- **Oryx Bookings 1.1.3**: read bookings through its existing `BookingRepository::get_by_date_range()`; displays service and status, **not customer names, email addresses, phone numbers or private notes**.
- **The Events Calendar**: display published single events from the plugin's UTC event metadata. **Recurring occurrences are not yet included.**
- **Eight additional read-only integrations**: Event Organiser, Events Manager, Easy Appointments, FluentBooking, Simply Schedule Appointments, Bookly, Amelia and Booking Calendar. Source-specific versions and caveats are in [the integration audit](docs/INTEGRATIONS.md).
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
