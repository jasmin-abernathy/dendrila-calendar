=== Dendrila Calendar ===
Contributors: jasmin-abernathy
Tags: calendar, events, bookings, administration
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free unified WordPress calendar for events and bookings from optional plugins.

== Description ==

Development version. Do not publish to WordPress.org before integration testing. Optional experimental CalDAV direct push now exists; Google and Outlook direct OAuth connections are not yet available.

Dendrila Calendar is an independent WordPress administration calendar that brings together native events and compatible event and booking plugins. All third-party integrations are optional and read-only. The original plugins remain responsible for reservations, payments and their data. Customer details are not included in the calendar event payload.

= Sources =

* Dendrila events: manually created private WordPress events.
* Event sources: Event Organiser, Events Manager and The Events Calendar (published single events only).
* Booking sources: Amelia, Booking Calendar, Bookly, Easy Appointments, FluentBooking, Oryx Bookings and Simply Schedule Appointments.
* Ten external sources in total, all optional and read-only.

Search loaded events locally. On first activation, the required setup assistant detects active booking plugins and lets you choose your sources and destination. No external CDN, custom booking tables or mandatory dependency.

= Calendar subscriptions =

Create a private, revocable subscription URL in Dendrila Calendar > Calendar synchronization. Choose the source plugins to include. Add the link in an external calendar using Subscribe from URL (not one-time file import). The calendar application controls its refresh schedule. The link is shown only once and should be kept secret. Requires HTTPS. Subscriptions provide read-only synchronization; they do not update source bookings from external calendar apps.

ICS Calendar can subscribe to the URL as a destination. Importing remote feeds that ICS Calendar itself displays is not yet supported.

== Installation ==

1. Install the Dendrila Calendar plugin directory and activate it.
2. Follow the required Configuration assistant. Supported active plugins are detected and preselected automatically.
3. Choose the appointment sources to synchronize.
4. Select direct CalDAV synchronization, an iCalendar URL subscription or local-only mode.
5. For CalDAV, enter the collection URL and an application password. The setup completes when the connection succeeds.
6. If an active booking plugin has no usable adapter, validation sends a limited, deduplicated technical compatibility report to the maintainer using WordPress email. The address is not displayed; no customer or booking data is sent.

== Frequently Asked Questions ==

= Does it change appointments? =

No. Imported records are strictly read-only.

= Is it public? =

The WordPress administration calendar is restricted to administrators. Private subscription URLs can be accessed by anyone who holds the secret link; these links can be revoked.

== Changelog ==

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

No. Direct CalDAV pushes bookings into the existing external calendar on a schedule, while read-only URL subscriptions are refreshed by the target application. Neither method writes modifications back to a WordPress booking. Direct Google/Outlook OAuth synchronization has not been implemented yet.

== Troubleshooting ==

Use Dendrila Calendar > Calendar view, not Local events, to find imported appointments. An independent list of the next 60 days appears under the calendar for diagnosis. Completing the setup requires a working CalDAV connection or a created calendar subscription URL; choosing an application is not a connection. Google and Outlook direct account authorization are not yet available.
