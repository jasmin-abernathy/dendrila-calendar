=== Dendrila Calendar ===
Contributors: jasmin-abernathy
Tags: calendar, events, bookings, administration
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free WordPress calendar and first-party appointment scheduling foundation with optional read-only connectors.

== Description ==

Development test version. Do not publish to WordPress.org before end-to-end testing and deployment of a publisher-managed OAuth authorization service. Google and Outlook connections work with administrator-configured OAuth applications; CalDAV direct push and private ICS subscriptions are also supported.

Dendrila Calendar is evolving into a native booking system with its own mandatory booking profiles, services and private reservation records, while retaining existing calendar and connector functionality. The native public booking form and WooCommerce payment checkout are still under development and are intentionally not enabled in this test build.

Dendrila Calendar is also an independent WordPress administration calendar that brings together native events and compatible event and booking plugins. All third-party integrations are optional and read-only. The original plugins remain responsible for reservations, payments and their data. Customer details are not included in the calendar event payload.

= Sources =

* Dendrila events: manually created private WordPress events.
* Event sources: Event Organiser, Events Manager and The Events Calendar (published single events only).
* Booking sources: Amelia, Booking Calendar, Bookly, Easy Appointments, FluentBooking, Oryx Bookings and Simply Schedule Appointments.
* Ten external sources in total, all optional and read-only.

Search loaded events locally. On first activation, the required setup assistant detects active booking plugins and lets you choose your sources and destination. No external CDN or mandatory commercial dependency. Native booking profiles and services are private WordPress content types, independent of the installed booking plugins.

= Calendar subscriptions =

Create a private, revocable subscription URL in Dendrila Calendar > Calendar synchronization. Choose the source plugins to include. Add the link in an external calendar using Subscribe from URL (not one-time file import). The calendar application controls its refresh schedule. The link is shown only once and should be kept secret. Requires HTTPS. Subscriptions provide read-only synchronization; they do not update source bookings from external calendar apps.

ICS Calendar can subscribe to the URL as a destination. Importing remote feeds that ICS Calendar itself displays is not yet supported.

== Installation ==

1. Install the Dendrila Calendar plugin directory and activate it.
2. Follow the required Configuration assistant. Supported active plugins are detected and preselected automatically.
3. Choose the appointment sources to synchronize.
4. Choose the calendar service you use (Nextcloud, iCloud, Google, Outlook or another service), or local-only mode.
5. For Nextcloud and compatible services, enter the cloud URL, account login and application password. Select a calendar from the discovered list; the setup only completes when the connection succeeds.
6. If an active booking plugin has no usable adapter, validation sends a limited, deduplicated technical compatibility report to the maintainer using WordPress email. The address is not displayed; no customer or booking data is sent.

== Native booking foundation (development) ==

* Create and publish at least one profile under Dendrila Calendar > Profiles before offering bookable services. A profile can represent a professional or another booking resource.
* Create services under Dendrila Calendar > Services. Assign a required published profile, duration, preparation/recovery buffers, optional price in cents and optional future WooCommerce product ID.
* A service without a valid active profile is **never bookable**; disabling its profile disables its bookings.
* Private booking records include both service ID and profile ID. No public reservation endpoint is enabled yet; no customer booking is falsely confirmed or charged.
* WooCommerce integration is optional and is being designed for HPOS and Cart/Checkout Blocks through official Woo APIs; listing a product ID does not imply live checkout compatibility.
* Support the developer via a small optional footer link in the administration (same destination as Dendrila Simple Edit).

== Frequently Asked Questions ==

= Does it change appointments? =

Bookings from other WordPress plugins remain read-only. Single Dendrila local events can optionally synchronize in both directions with Google/Outlook. When an administrator enables availability protection, the plugin can reject new Easy Appointments booking requests that overlap connected calendar busy times.

= Is Google/Outlook one-click setup available to all sites? =

Not yet. This development build requires Google/Microsoft OAuth apps configured by the site administrator. A secure publisher-operated OAuth broker is required before no-configuration public distribution. Never ship shared OAuth secrets inside a WordPress ZIP.

= Is it public? =

The WordPress administration calendar is restricted to administrators. Private subscription URLs can be accessed by anyone who holds the secret link; these links can be revoked.

== Changelog ==

= 0.0.4 (development: two-way local events) =
* Direct Connect buttons when provider credentials are configured; change sync direction without repeating consent.
* Opt-in two-way titles/dates/all-day synchronization for single local events; existing connections stay export-only.
* Stable identities, pagination, conflict detection with conditional writes, confirmed deletion to Trash and restoration handling.
* Skip invitations/recurrences and preserve other booking plugins as read-only sources.
* Keep non-secret mapping history across disconnection/reconnection to avoid duplicates.
* Preserve UTC offsets and daylight-saving dates; explicitly clear incompatible Google date fields on PATCH.
* Report source limits/errors; common atomic synchronization lock; French interface updates and regression tests.
* Live provider consent and real-account round-trip validation are still required before public release.

= 0.0.3 (development build: native booking foundation; no version bump) =
* Private first-party profile, service and reservation content types; profile mandatory before a service becomes bookable.
* Profile and service management pages with duration, price, preparation/recovery buffers and optional Woo product link.
* No live native checkout or public booking has been enabled; existing read-only integrations remain unchanged.
* Discreet optional developer coffee link at the bottom of Dendrila administrator pages, matching Simple Edit.


= 0.0.3 (development build: external busy slots displayed correctly) =
* With administrator-opted-in availability protection, public Easy Appointments slots overlapping events in connected Google/Outlook calendars are marked unavailable before selection.
* Revalidate live availability on reservation creation AND on final confirmation of a temporary reservation.
* Preserve provider/event privacy; only send slot counts to the public form. A provider outage disables offered slots while protection is enabled.
* This adapter is specifically for the Easy Appointments 4.x `ea_date_selected` frontend AJAX JSON contract; other booking plugins still require their own availability adapters.
* Version remains 0.0.3 during internal testing; this build is not ready for WordPress.org.



= 0.0.3 (development; private testing) =
* Fixed the return-to-calendar link to use the registered WordPress administration page.
* WordPress-standard, 20-pixel synchronization icon replaces the previous oversized/reused calendar graphic.
* Calendar shown first on its page, with view controls, sources and search above; secondary administration links below.
* Responsive seven-column monthly calendar on mobile, tap a day to see its events, and collapsible server fallback list.
* Reauthorizing the same Google/Outlook destination updates its connection in place and retains event mappings.
* A safe administrator-only action consolidates previously duplicated connections, preserving known remote mappings without deleting any remote events.
* Internal test build only: end-to-end provider/booking and browser responsive checks are still needed before a public release.



= 0.0.3 (development; private testing) =
* Google Calendar and Outlook OAuth account connections, with mobile-friendly administrator credential setup for testing.
* Optional server-side Google/Outlook busy-time protection for Easy Appointments, checked when a reservation is submitted (fail-closed on provider errors). The slot picker may still show occupied times.
* Guided post-authorization calendar selection and more distinct connected-account status.
* Distinct synchronization/clock icon in the WordPress menu and revised responsive settings.
* Booking-source adapters remain read-only. No external event may edit or cancel an original WordPress booking.
* OAuth shared-service onboarding is not yet deployed. Publisher verification and end-to-end tests are required before public release.


= 0.0.1 (development; first internal test) =
* Initial unified calendar with local events and optional, read-only source integrations.
* Added in-browser event search and RFC 5545 subscription feeds with UTF-8 line folding and DST-aware all-day dates.
* Added source selection controls, timezone navigation safeguards and isolated adapter regression tests.
* Added revocable, live read-only calendar subscription URLs, per-link source selection, and privacy controls.
* Removed one-off file export.
* ICS Calendar can use the URL as a destination; importing ICS Calendar's existing remote feeds still requires a separate safety audit.
* Added the required setup assistant, automatic source detection, CalDAV direct push and limited technical compatibility requests.
* Not yet released or tested end-to-end on a production-like WordPress installation.

= How can I subscribe with Google Calendar? =

Open Dendrila Calendar > Synchronization. Create a private subscription URL for your sources. In Google Calendar on the web (desktop), use Settings > Add calendar > From URL and paste it. The mobile app cannot subscribe from a URL directly. Google refreshes the calendar on its own schedule.

= Is this real-time, bidirectional Google or CalDAV synchronization? =

CalDAV remains one-way and scheduled. Direct CalDAV pushes bookings into the existing external calendar on a schedule, while read-only URL subscriptions are refreshed by the target application. Neither method writes modifications back to a WordPress booking. Google/Outlook OAuth supports opt-in two-way single local events, approximately every five minutes through WordPress Cron. Titles, dates and all-day status synchronize; recurring events, invitations and write-back to other booking plugins are excluded.

== Troubleshooting ==

Use Dendrila Calendar > Calendar view, not Local events, to find imported appointments. An independent list of the next 60 days appears under the calendar for diagnosis. Completing the setup requires a working CalDAV connection or a created calendar subscription URL; choosing an application is not a connection. Google and Outlook use direct OAuth account authorization after the site owner configures the provider application.


= Guided calendar connection (development) =

A guided screen helps choose Nextcloud, iCloud or another CalDAV service, find account calendars, select one and synchronize the selected WordPress sources. Read-only iCalendar subscriptions remain a separate fallback. Google/Outlook use the separate OAuth authorization flow, with optional two-way local events. Live provider verification remains pending.
