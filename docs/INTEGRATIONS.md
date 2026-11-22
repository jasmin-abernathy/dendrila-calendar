# Integration contract and source audit (22 November 2026)

All providers are **optional, read-only**, and executed inside the authenticated WordPress admin AJAX endpoint, with `manage_options` plus nonce verification. They are not public calendar feeds. The original plugin is the source of truth; no appointment mutations, mirroring tables, or customer data exports.

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
