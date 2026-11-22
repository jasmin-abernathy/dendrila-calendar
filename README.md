# Dendrila Calendar 🌱

**Status:** first internal test `0.0.1` — development only; not a beta or WordPress.org release.

**Dendrila Calendar is an independent, private calendar hub for WordPress.** It brings together events and bookings from compatible plugins in one administrator interface. All integrations are optional, including Oryx Bookings. Imported items stay read-only and source plugins retain control of their records and payments.

**Project repository:** https://github.com/jasmin-abernathy/dendrila-calendar

## What currently works

- Day-by-day **month, week and list** views; source filters and keyboard-operable controls.
- **Instant search** across loaded event titles, status and source names, without extra network requests.
- **Dendrila events**: create/edit your own events using a private WordPress post type (admin only).
- **Optional event sources:** Event Organiser, Events Manager and The Events Calendar (published single events only, recurrence not yet supported).
- **Optional booking sources:** Amelia, Booking Calendar, Bookly, Easy Appointments, FluentBooking, Oryx Bookings and Simply Schedule Appointments.
- **Ten optional external integrations in total**, all read-only. Provider versions and limitations are documented in [the integration audit](docs/INTEGRATIONS.md); no customer contact information or private notes are included in calendar payloads.
- **Private live calendar subscriptions**: generate a revocable HTTPS calendar URL for any selection of supported sources. Subscribe to it in a compatible calendar application and events are refreshed automatically when that application polls the feed. This is read-only, not a one-time download or a bidirectional write integration.
- **Optional adapter API**: register sources through WordPress filters without patching other plugins.
- No vendor scripts, CDN, tracking, third-party API calls, custom tables, or mandatory WooCommerce dependency.

## Requirements and installation

- WordPress 6.5+, PHP 7.4+.
- Install the `dendrila-calendar/` folder containing `dendrila-calendar.php` in `wp-content/plugins/`.
- Activate it, then visit **Dendrila Calendar** in the administrator menu.
- All third-party sources are **optional**; source checkboxes appear when active. See the [provider compatibility table](docs/INTEGRATIONS.md).
- Open **Calendar synchronization** in the Dendrila Calendar admin screen, choose which sources to include and generate a private link. Copy the URL **immediately** (the plugin stores only its SHA-256 hash). Add it in your external calendar using **Subscribe from URL** / **Add calendar by URL**, not **Import file**.
- Refresh is controlled by the destination app. The feed proposes an hourly interval; Google Calendar and other apps may refresh less frequently.
- Revoke a link in the same panel at any time, then remove the old subscription from your calendar app.
- A properly configured **HTTPS** WordPress site is required. Ensure page caching and security plugins do not strip or cache requests containing `dendrila_calendar_feed`.
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

Source IDs must match `[a-z][a-z0-9_-]{0,31}`. Source adapters must filter by the supplied UTC range and must not leak personal information. The **admin AJAX** endpoint requires an authenticated administrator and a WordPress nonce; private subscriptions use a separate token-protected endpoint. The `url` must resolve to this site's origin to be clickable in the UI.

## Design and security

- Imported sources: **read-only**. Creation/editing remains in each source's official interface.
- AJAX: capability and nonce checks, validated date bounds (maximum 93 days), source allowlist, event caps.
- All event titles inserted as `textContent` (not HTML).
- WordPress local event dates are converted to UTC for storage; the interface displays time in the configured WordPress site timezone.
- Events reach the external calendar application **only if an administrator explicitly creates and shares a private subscription link**. Feed requests are authenticated by the unguessable URL token, and the server stores only its hash.
- Calendar subscriptions include selected event titles, dates, source labels and statuses, **not customer identity/contact/payment data or administrative event URLs**. Treat links as passwords; revoke compromised links.
- Requests use live source adapters, with no WordPress cron or direct destination-API credentials. The feed supplies a rolling date window (past 30 days through 365 days ahead, up to 2,500 events).
- No cross-site tracking, SDK or external service is added to WordPress itself.
- No `Requires Plugins` header: optional integrations **must not** block plugin installation.

## Known limitations / roadmap

- No unrestricted public-facing calendar. A private subscription link grants read-only access to the explicitly selected sources; protect it like a password.
- The Events Calendar recurrence/occurrences still require dedicated version-specific support.
- Outbound subscription feeds are provided over HTTPS, with read-only iCalendar updates. **Bidirectional CalDAV or Google/Microsoft API sync is not implemented** and requires account-specific authorization plus conflict-resolution rules.
- **ICS Calendar** can be configured as a feed subscriber using the generated URL, but using the feeds already imported into ICS Calendar as an *inbound source* remains out of scope pending a safe adapter audit.
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
node --check assets/calendar-subscriptions.js
php tests/test-subscriptions.php
php tests/test-sources.php
php tests/test-integrations.php
```

License: GPL-2.0-or-later.

## Missing events in the administration calendar

After loading, Dendrila Calendar shows how many records each selected source returned for the visible period. If Easy Appointments reports `0`, check the source plugin's appointment dates and that the source checkbox is enabled. If it reports a positive count but an event is not displayed, check the selected month, search query and browser console. These are aggregate counts only; client details remain private. Assets use file modification time for cache busting while the plugin remains at version `0.0.1`.
