# Dendrila Calendar 🌱

**Status: 0.0.4, development build.** This repository contains Google/Microsoft OAuth connectors and two-way synchronization for single local events. Installation and provider export have been checked on the WordPress laboratory; the final provider round trip remains a manual validation. No OAuth secret is distributed with the plugin.

Dendrila Calendar combines appointment booking, availability management and a private WordPress administration calendar. Native services have a public booking form; paid appointments use WooCommerce products and orders. Optional external booking plugins remain read-only sources and retain ownership of their own reservations and payments.

## What is implemented

| Source or destination | Behaviour |
| --- | --- |
| Native Dendrila bookings | Shared profile availability, public booking, locked acceptance and optional WooCommerce payments |
| Dendrila local events | Administrator-only creation and editing, timed or all-day events |
| Google Calendar / Microsoft Outlook | OAuth consent, calendar selection, encrypted tokens, token refresh, outbound events and two-way local events |
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

Open **Dendrila Calendar → Synchronization**, choose Google or Outlook and use **Connect with…**. When its OAuth app is configured, this button goes directly to the provider's official consent screen. Select a writable calendar, review the synchronization direction and sources, then use **Connect and synchronize this calendar**. The connection checks its ability to create and remove a temporary event automatically.

The site owner must configure an OAuth application once. The administrator form accepts a client ID and secret and stores the secret encrypted; server-side constants are also supported. Ordinary account authorization then uses the provider's sign-in flow. An unconfigured provider remains visibly unavailable instead of claiming to be connected. See [docs/OAUTH-SETUP.md](docs/OAUTH-SETUP.md) for exact redirect URI and application setup.

New destinations propose **Synchronize local events in both directions** by default, with the setting visible and editable before confirmation. Reauthorizing a known destination preserves its previous direction. Updating the plugin does not change the direction of saved connections: existing export-only connections stay export-only until their administrator changes the setting on the connection card. That change does not require another provider consent flow.

The connection flow is for WordPress site administrators (`manage_options`); it does not create personal calendar connections for subscriber accounts. See the French [connection and manual test guide](docs/UTILISATION-GOOGLE-OUTLOOK.md).

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

## Availability and appointments

Create a **profile** for each professional or resource, enable its availability and enter its hours. Services share that profile: a booking for one service blocks overlapping appointments for its other services.

- Multiple weekly time ranges, lunch breaks and closed days.
- Date exceptions that replace the usual hours, including whole-day closures.
- A timezone per profile, with real UTC durations and daylight-saving transitions.
- Per-service minimum notice, rolling booking horizon, optional first/last dates, and start-time intervals independent of appointment duration.
- Maximum appointments per day, week and calendar month, both across a profile and for one service; zero means no quota. Weeks start on Monday in the profile timezone.
- Separate preparation/recovery buffers saved on each reservation. The appointment must fit the working hours; buffers can extend beyond them but must remain free. Adjacent reservations' buffers add together.
- Existing native bookings, active payment holds and local calendar events block overlaps. Checking every connected Google/Outlook calendar is enabled in the profile settings by default. If a configured provider cannot be checked, protected times are not offered.

Public endpoints reveal available slots, never occupied event names, guests or customer records. The form supports `[dendrila_booking]` for a service choice or `[dendrila_booking service="123"]` for one service. Visitors do not need a WordPress account. The browser displays the profile timezone and the visitor's local equivalent.

Final acceptance rechecks the slot under a SQL lock shared by **all services of the profile**, after refreshing WordPress query and metadata caches. A second lock makes repeated request IDs idempotent. Unsupported or lost database locks cause a temporary refusal, never an unprotected confirmation. The native engine requires a MySQL/MariaDB server supporting connection-scoped advisory locks; it does not claim SQLite booking support.

### WooCommerce is the editor for paid services

Create a **simple WooCommerce product**, keep its name, description and price in the normal product fields, then open **Appointment booking**. Select a responsible profile, duration, preparation/recovery times and booking rules. The linked Dendrila service is created automatically; its edit links return to that product. Appointment products become virtual, so there is no shipping step. A disabled/draft product or an unavailable product cannot open paid booking.

The product page displays the booking form in place of ordinary Add to cart. Classic and Store API cart validation refuse an appointment product without its time-selection flow, including a product already present in an older cart. Product blocks and catalogue buttons point to that flow too. A free service can also be created directly in **Dendrila Calendar → Services**, without WooCommerce.

For a product-backed appointment:

1. Dendrila reserves the chosen slot for **20 minutes**, ending sooner if the appointment starts earlier.
2. It creates one WooCommerce order using the product's actual price, quantity one, and WooCommerce tax calculations. The legacy cents field is not a second price source.
3. The visitor follows WooCommerce's official **pay for order** URL. Payment gateways remain owned and configured by WooCommerce. This is an order-payment flow, not an injected Cart/Checkout Block payment method.
4. A successful WooCommerce payment confirms the booking. An `on-hold` order keeps the time reserved pending manual payment confirmation. Failed/cancelled orders and full refunds release the reservation; partial refunds keep it.
5. An abandoned pending-payment hold expires. Late payment is reconciled under the profile lock: an occupied or cancelled time is never falsely confirmed. The booking is marked for payment review and the order receives a note; refunds remain an explicit WooCommerce action.

Orders and items use WooCommerce CRUD APIs, including HPOS. Product/order snapshots and retries do not duplicate orders or silently reprice an existing one. A zero-total Woo product still creates a real order and follows payment-complete reconciliation. Customer cancellation of a paid appointment requests contact with the organizer; administrator cancellation releases its slot and leaves refund management in WooCommerce.

Confirmed native bookings are shown in the unified calendar and exported to Google/Outlook destinations already selected for Dendrila events; they can also be selected as a source for CalDAV and private subscriptions. Booking exports contain the service title and time, not the customer's name/email. Changing a remote event does **not** override a customer's booking or a WooCommerce payment: bidirectional editing applies to ordinary local events.

See the French [availability, products and booking guide](docs/RESERVATIONS-HORAIRES-WOOCOMMERCE.md).

### Other booking plugins

Optional Google/Outlook busy-time protection for **Easy Appointments 4.x** checks its public slot picker and final submission. Provider errors close protected slots rather than confirming an unverified booking. Other booking plugins require their own availability adapters. Local events by themselves do not block bookings in all third-party plugins.

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

Source IDs must match `[a-z][a-z0-9_-]{0,31}`. Adapters must filter by the supplied UTC bounds and avoid exposing customer data. Calendar administration AJAX requires a signed-in administrator and nonce; public booking AJAX has its own nonce, rate limit and server-side validation, and feed URLs use separate tokens. Clickable admin URLs must point to this site's origin.

## Troubleshooting and validation

**Calendar view** combines selected sources; **Local events** contains Dendrila-authored events and two-way imports. Original appointments from other plugins are only shown in Calendar view. Source counts, sanitized warnings and a server-rendered upcoming-events table help distinguish source problems from JavaScript problems. Event/style assets use file modification times for cache invalidation.

Run all PHP test files and JavaScript syntax/browser-initialization checks. [docs/RELEASE-0.0.4-TESTING.md](docs/RELEASE-0.0.4-TESTING.md) records the 0.0.4 validation and remaining real-account checks. Fixtures validate deterministic behaviour; they do not certify live Google, Microsoft, iCloud or every booking-plugin version.

Use the unit/DOM commands and the isolated WordPress + WooCommerce + MariaDB integration procedure in [docs/TESTING-NATIVE-BOOKINGS.md](docs/TESTING-NATIVE-BOOKINGS.md). Provider round-trip and live payment-gateway testing are separate manual acceptance checks; local Woo order-transition tests do not claim to have charged a payment card.

License: GPL-2.0-or-later. [Project repository](https://github.com/jasmin-abernathy/dendrila-calendar).
