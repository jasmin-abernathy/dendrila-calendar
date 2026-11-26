# Dendrila Calendar 🌱

**Status: 0.0.4, development build.** This repository contains working Google/Microsoft OAuth connectors and opt-in two-way synchronization for single local events. Real account consent and provider round-trip validation remain required before a public release. No OAuth secret is distributed with the plugin.

Dendrila Calendar is a private WordPress administration calendar. It combines local events and appointments from optional booking plugins, with month, week and list views, source filters, search, keyboard controls and a mobile day-details panel. Booking plugins keep ownership of their reservations, validation and payments.

## What is implemented

| Source or destination | Behaviour |
| --- | --- |
| Dendrila local events | Administrator-only creation and editing, timed or all-day events |
| Google Calendar / Microsoft Outlook | OAuth consent, calendar selection, encrypted tokens, token refresh, outbound events and opt-in two-way local events |
| Other WordPress event/booking plugins | Read-only source adapters; selected appointments can be exported, but external changes never edit the original booking |
| Nextcloud / compatible CalDAV | Calendar discovery and one-way export with encrypted application credentials |
| Private iCalendar subscription | Revocable HTTPS URL; the subscribing application controls refresh timing |

Ten optional external sources are supported: Event Organiser, Events Manager, The Events Calendar, Amelia, Booking Calendar, Bookly, Easy Appointments, FluentBooking, Oryx Bookings and Simply Schedule Appointments. Compatibility and source-specific limitations are recorded in [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md).

The plugin has no mandatory WooCommerce dependency, externally hosted JavaScript or tracking SDK. It contacts the chosen calendar provider only after configuration; compatibility reports are a separate disclosed setup action.

## Installation and first setup

1. Install the `dendrila-calendar/` folder or installable ZIP on WordPress 6.5+ with PHP 7.4+.
2. Activate the plugin and open **Dendrila Calendar → Configuration**. Available booking sources are detected automatically; inactive and unsupported plugins are identified separately.
3. Select the sources and a destination, or select local-only use.
4. For Google or Outlook, follow the OAuth flow below. For Nextcloud/CalDAV, enter the HTTPS cloud address, account login and application password, then choose a discovered calendar.
5. To subscribe through an iCalendar URL, generate a private link and use **Subscribe from URL** in the calendar application. The link is displayed once; only its hash is stored.

The unified calendar stays available while connection setup is incomplete. A destination is considered connected only after a successful provider test or creation of a private subscription link. Merely selecting Google or Outlook does not connect an account.

Unsupported active booking plugins can generate a compatibility report when the administrator confirms setup. The disclosed report includes plugin name/version, WordPress/PHP versions, site URL and adapter status, never customer records or credentials. Requests are deduplicated; failed submissions are throttled for 24 hours. The receiving address is not exposed in the interface. See the existing setup tests for this behaviour.

## Connecting Google or Microsoft Outlook

Open **Dendrila Calendar → Synchronization**, choose Google or Outlook and use **Connect with…**. When its OAuth app is configured, this button goes directly to the provider's official consent screen. Select a writable calendar, choose the appointment sources and confirm the temporary create/delete test.

The site owner must configure an OAuth application once. The administrator form accepts a client ID and secret and stores the secret encrypted; server-side constants are also supported. Ordinary account authorization then uses the provider's sign-in flow. An unconfigured provider remains visibly unavailable instead of claiming to be connected. See [docs/OAUTH-SETUP.md](docs/OAUTH-SETUP.md) for exact redirect URI and application setup.

Existing connections continue to **export only** after an update. To enable two-way operation, explicitly check **Synchronize local events in both directions** when connecting, or save that setting on the existing connection card. Changing this setting does not require another provider consent flow.

### Two-way scope and conflict handling

- Synchronizes **titles, start/end dates and times, and all-day status** for single events. Descriptions, attachments, attendee data and private notes are not copied.
- New remote events are discovered from **7 days in the past to 85 days ahead**. Previously linked events are checked by their individual IDs even if they move outside this window.
- Remote imports become private Dendrila events. They can be edited in **Local events** and appear in the unified calendar. Their origin prevents them being exported to unrelated calendars or subscription feeds.
- Local changes update the same remote event; remote changes update the local copy. Stable event identities, provider transaction IDs and saved links prevent duplicates on retries and reconnection.
- Deleting a linked local event removes its remote copy. A confirmed remote deletion moves an unchanged local copy to WordPress Trash. With WordPress Trash disabled, the deletion is reported as an error rather than permanently erasing the local event.
- A simultaneous edit on both sides is a conflict. Neither version is overwritten; the connection card lists local event IDs. Make the intended title/dates identical on both sides, then synchronize again. A concurrent provider revision rejects the write through `If-Match`.
- Restoring a previously deleted local event creates a new remote identity. A remote event missing from one listing is fetched individually before any deletion decision.
- **Recurring events and invitations/meetings are excluded**. In particular, changes do not send invitations or update attendees.
- Source adapters from other booking plugins remain **export only**. Two-way edits never bypass a booking plugin's payment or availability rules.

Synchronization runs approximately every five minutes through WordPress Cron when the site receives traffic, or manually with **Synchronize now**. It is not an instantaneous webhook service. A real server cron can provide regular execution for a low-traffic site.

A source export is limited to 500 events per pass, and local-source discovery to 500 events per date window. A visible warning reports a reached limit; high-volume calendars can therefore have new events omitted. Existing two-way links are still reconciled independently of that source slice. Remote listings are paginated up to 100 pages of 100 events; a failed or incomplete inventory aborts the two-way pass before changes.

Disconnecting removes the active credentials and stops future synchronization. Existing local/remote events remain. Non-secret destination identities and links are retained so reconnecting the same calendar does not duplicate events. Provider-side app consent can also be revoked in the Google/Microsoft account settings.

## Other calendar methods

### CalDAV

Nextcloud and compatible services use HTTPS DAV discovery (account principal, calendar home, calendar collections). A short-lived, user-bound selection token prevents changing the chosen calendar to an undiscovered destination. PHP DOM/XML is required. Credentials are encrypted; private-network URLs, untrusted redirects and HTTP downgrades are rejected.

CalDAV is **one-way**: creates/updates selected WordPress events, and removes previously exported events on an explicit cancellation status. An event simply missing from a source query is not deleted. iCloud uses an app-specific password and an official-host allowlist; real-account compatibility still needs verification.

### Private iCalendar URL

Subscription feeds are read only, live when polled, and token-protected. They include selected event titles, dates, source labels and statuses, without customer contact/payment information or administrative edit links. Treat the URL as a password and revoke it when needed. The feed covers 30 days in the past to 365 days ahead, up to 2,500 events.

Google's **From URL** subscription setup is available on the web; refresh timing is controlled by Google. Other applications have their own refresh policies. Importing a downloaded file is a one-time operation and does not create a subscription. ICS Calendar can subscribe to a generated URL; ingesting arbitrary feeds already displayed by ICS Calendar remains unsupported.

## Availability and native booking foundation

Optional Google/Outlook busy-time protection for **Easy Appointments 4.x** checks both the public slot picker and final reservation submission. Provider errors close protected slots rather than confirming an unverified booking. Other booking plugins require their own availability adapters. Local events by themselves do not block bookings in all third-party plugins.

The private native booking foundation includes profiles, services and reservation records. A published profile is required for a service; services have a duration, buffers, an optional price and an optional future WooCommerce product reference. A public native booking form and native WooCommerce checkout are **not enabled** in this build. Entering a product ID does not establish payment compatibility.

## Security and privacy

Administration and connection mutations require WordPress capabilities and nonces. OAuth uses authorization code + PKCE, a short-lived one-time state bound to the signed-in administrator, encrypted server-side access/refresh tokens, and a query-free HTTPS callback. Provider requests have a fixed host/path allowlist, no redirects, and TLS verification. Token-bearing requests cannot follow an arbitrary pagination URL.

Two-way and outbound jobs share an atomic per-connection lock. A connection changed or removed during synchronization stops further work. Private imported events are excluded from outbound subscriptions and other destination connectors to prevent data loops. Titles are rendered through text nodes/escaped HTML. Date fields are stored in UTC, with all-day calendar dates preserved across daylight-saving changes.

## Extension API

```php
add_filter( 'dendrila_calendar_sources', function ( $sources ) {
    $sources['my_source'] = 'My source';
    return $sources;
} );
add_filter( 'dendrila_calendar_source_events_my_source', function ( $events, $start_utc, $end_utc ) {
    // Query only events overlapping [$start_utc, $end_utc), both Y-m-d H:i:s UTC.
    return array( array(
        'id' => 'my_source-12', 'title' => 'Example',
        'start' => '2026-11-24T09:00:00Z', 'end' => '2026-11-24T10:00:00Z',
        'status' => 'confirmed', 'url' => admin_url( 'admin.php?page=my-source' ),
    ) );
}, 10, 3 );
```

Source IDs must match `[a-z][a-z0-9_-]{0,31}`. Adapters must filter by the supplied UTC bounds and avoid exposing customer data. Admin AJAX requires a signed-in administrator and nonce; feed URLs use separate tokens. Clickable admin URLs must point to this site's origin.

## Troubleshooting and validation

**Calendar view** combines selected sources; **Local events** contains Dendrila-authored events and two-way imports. Original appointments from other plugins are only shown in Calendar view. Source counts, sanitized warnings and a server-rendered upcoming-events table help distinguish source problems from JavaScript problems. Event/style assets use file modification times for cache invalidation.

Run all PHP test files and JavaScript syntax/browser-initialization checks. [docs/RELEASE-0.0.4-TESTING.md](docs/RELEASE-0.0.4-TESTING.md) records the 0.0.4 validation and remaining real-account checks. Fixtures validate deterministic behaviour; they do not certify live Google, Microsoft, iCloud or every booking-plugin version.

```sh
find . -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*.php; do php "$test" || exit 1; done
node --check assets/calendar.js
node --check assets/calendar-subscriptions.js
node --check assets/calendar-connect.js
node tests/test-calendar-init.js
```

License: GPL-2.0-or-later. [Project repository](https://github.com/jasmin-abernathy/dendrila-calendar).
