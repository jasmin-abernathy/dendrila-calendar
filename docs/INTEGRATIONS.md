# Integration contract and source audit (22 November 2026)

All providers are **optional, read-only**, and executed inside the authenticated WordPress admin AJAX endpoint, with `manage_options` plus nonce verification. The main admin interface is private; optional public-facing subscription URLs require a long, revocable bearer token. The original plugin is the source of truth; no appointment mutations, mirroring tables, or customer data exports.

## Supported source archives and inspected interfaces

| Provider | Archive reviewed | Interface used | Date convention | Caveat |
| --- | --- | --- | --- | --- |
| Oryx Bookings | 1.1.3 | `BookingRepository::get_by_date_range()` | UTC | Pending on-site verification of repository deployment |
| The Events Calendar | existing prototype | WordPress `tribe_events`, UTC metadata | UTC | Recurring occurrences excluded until separately audited |
| Event Organiser | 3.12.12 | `eo_get_events`, `eo_get_the_start/end` | Zoned `DateTime` | Recurring occurrences included; max 750 per request |
| Events Manager | 7.6 | `EM_Events::get`, event `start(true)/end(true)` | UTC `DateTime` | Source's recurrence/grouping rules respected; max 750 |
| Easy Appointments | 4.0.5 | `ea_appointments`, `ea_services` | WordPress local | Status displayed as recorded, including tentative bookings |
| FluentBooking | 2.6.0 | `fcal_bookings`, `fcal_calendar_events` | UTC | Scheduling bookings only; excludes internal workflow types |
| Simply Schedule Appointments | 1.6.13.9 | `ssa_appointments`, `ssa_appointment_types` | UTC | Canceled/abandoned appointments excluded |
| Bookly | 28.8 | `bookly_appointments`, `bookly_services` | WordPress local | Provider-specific extra duration appended; no customer records |
| Amelia | 2.4.15 | `amelia_appointments`, `amelia_events_periods`, services/events | UTC | Appointment and event-period rows are distinct |
| Booking Calendar | 12.0.1 | `booking` and `bookingdates` tables | WordPress local | Displays **one all-day entry per reserved date**; timed slots deliberately not guessed |
| ICS Calendar | 12.3.0 | **Not enabled** | Depends on feed | Renders externally configured ICS feeds; do not fetch untrusted URLs or open SSRF route |

**These adapters have fixture-based tests but have *not* been tested against running production installations.** Table/API shapes could evolve with upstream versions. A disabled/broken optional source must not prevent other sources from loading. SQL errors currently produce an empty result (not a visible source-health warning), so check the plugin's own data first if a source unexpectedly appears empty.

## Privacy and security

- Queries select only appointment IDs, service/event titles, status and scheduled dates/times. No client name, customer contact, notes, addresses, payment details or IP addresses are fetched.
- All SQL uses constant plugin-owned table suffixes with `$wpdb->prefix` and bound date parameters; never accept a table name from a visitor.
- Event URL rendering only permits the current site's origin. Event titles are rendered with `textContent`; no third-party scripts or CDNs.
- Read-only providers cannot move, cancel or edit any booking. A user may follow an admin link to the original plugin, which then applies that plugin's own permissions.
- No `Requires Plugins:` header, because all integrations are optional.

## Test matrix for WordPress staging

1. Activate **only Dendrila Calendar**: the native source appears, no PHP fatal errors.
2. Enable each supported provider individually: source selector appears; booking titles and UTC-localized times match the source's dashboard.
3. Repeat on a WordPress site in a negative offset timezone and on Europe/Paris during March and October DST transitions.
4. Create an event spanning midnight and an all-day Booking Calendar allocation; verify the correct days (not an invented time).
5. Confirm uncaught exceptions/errors in one connector do not hide appointments from other connectors.
6. Review the browser network payload: no customer names, email, phone, notes or IP, and no public unauthenticated event endpoint.
7. Test keyboard operation, responsive admin layout, source select-all/select-none, and permissions with an Editor account.
8. Enable optional booking plugins separately, then disable them: no data migration and no side effects on their bookings.

This is a development integration matrix, not a certification that all combinations are working in production.

## Live subscriptions to external calendar apps

Dendrila Calendar generates a **private, revocable HTTPS subscription URL**. It is not a file download and not a bidirectional sync API. When Google Calendar, Apple Calendar, Outlook, Nextcloud Calendar or ICS Calendar subscribes to a URL, the receiving application decides when it fetches new data; the feed advertises a 1-hour refresh hint but cannot force a refresh. This functionality is built on the same ten read-only adapters listed above, and can select any subset of the source plugins installed on the site.

Security: 256-bit random URL token; only a SHA-256 fingerprint stored in non-autoloaded WordPress options; optional sources selected per link; revocation; HTTPS-only creation; no customer identity, customer contact, payment details or admin edit URLs exported. The calendar includes event title, date/time, source and status, which may themselves be sensitive; treat the URL as a password. If a link is disclosed, revoke it. The feed endpoint sends no-store, no-index and no-referrer headers; exclude the query parameter `dendrila_calendar_feed` from page cache/redirect rules.

The feed is generated on demand, without cron. It covers 30 days in the past through 365 days ahead (maximum 2,500 events; source adapters may enforce smaller caps), using 85-day windows with stable, deduplicated event UIDs and the WordPress site timezone for all-day events. Published updates/deletions become visible on the next destination-side refresh. Test that the provider's query range and access restrictions behave correctly on a WordPress staging site. No two-way changes are sent to third-party plugins.

ICS Calendar 12.3.0 is an **outbound destination**: paste the private URL into its ICS Feed URL field. Inbound feeds configured on ICS Calendar would require separate source discovery, safe remote access controls and caching; do not assume remote URLs are trusted.

## Outbound calendar destinations

- **CalDAV (direct push, experimental):** supports collection PROPFIND and iCalendar resource PUT / DELETE with an app-specific password, encrypted at rest. Selected sources are checked periodically; stable IDs avoid duplicates. Edits update the same event. Missing records are not blindly deleted; explicit canceled statuses can be removed. No customer PII is sent. Requires staging verification with actual CalDAV backends.
- **Google Calendar and Microsoft Outlook:** separate OAuth 2.0 destination connectors are not yet implemented. Do not confuse the existing ICS subscription instructions with direct OAuth synchronization.
- **Any iCalendar-compatible app:** the earlier, read-only subscription URL remains available as a fallback. Its refresh cadence is decided by the external app.

## Auto-detection, setup and compatibility escalation

On first activation, the administrator must complete a **Configuration** assistant. It uses WordPress plugin metadata plus actual activation state to detect the audited plugins. Installed-but-inactive plugins are never imported or reported as failures. An active plugin whose required PHP API is missing is marked unavailable. ICS Calendar remains unsupported as an **import source** (it may still subscribe to an existing Dendrila feed). Previously unseen, active booking plugins can also be detected by limited appointment/booking metadata keywords, but are **never queried** without an adapter audit.

Saving the assistant triggers a rate-limited technical compatibility request via WordPress `wp_mail()` for each active unsupported or unavailable connector. The email contains only plugin metadata, the failure reason, WordPress/PHP versions and site URL. No appointment details, customer information or authentication data are transmitted. The notification destination exists only in server-side PHP and is not rendered in the UI. Users can reconfigure the sources and destination later. Direct CalDAV setup is completed only after a successful destination connection.


## Simplified account-first calendar connection (23 November 2026)

- The administrator selects a named service, not a CalDAV endpoint. For Nextcloud, enter the ordinary cloud URL, an account username and application-specific password; Dendrila discovers the DAV principal, calendar home and calendars using authenticated PROPFIND, then offers a calendar selector.
- Discovery uses HTTPS only, refuses unsafe/private URLs and cross-origin redirects, and binds the selected calendar to a ten-minute, user-scoped token. Credentials do not appear in AJAX responses, logs or support requests. Server must provide PHP DOM/XML.
- iCloud is behind its own restricted CalDAV hostname allowlist, but remains unverified with a real Apple account. Google and Microsoft are separate OAuth API integrations, **not supported directly**; their UI must never simulate a successful connection.
- Direct connection and subscription appear in **separate** admin flows to avoid presenting a settings sheet as an installation wizard. WP-Cron runs synchronization after a connection; production testing must confirm remote write permissions, non-duplication, updates, and errors on a live target.
