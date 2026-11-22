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

Development version. Do not publish to WordPress.org before integration testing.

Dendrila Calendar is an independent WordPress administration calendar that brings together native events and compatible event and booking plugins. All third-party integrations are optional and read-only. The original plugins remain responsible for reservations, payments and their data. Customer details are not included in the calendar event payload.

= Sources =

* Dendrila events: manually created private WordPress events.
* Event sources: Event Organiser, Events Manager and The Events Calendar (published single events only).
* Booking sources: Amelia, Booking Calendar, Bookly, Easy Appointments, FluentBooking, Oryx Bookings and Simply Schedule Appointments.
* Ten external sources in total, all optional and read-only.

Search loaded calendar events locally. Subscribe to a revocable private feed for continuously refreshed events. No external CDN, custom booking tables or mandatory dependency.

= Calendar subscriptions =

Create a private, revocable subscription URL in Dendrila Calendar > Calendar synchronization. Choose the source plugins to include. Add the link in an external calendar using Subscribe from URL (not one-time file import). The calendar application controls its refresh schedule. The link is shown only once and should be kept secret. Requires HTTPS. Subscriptions provide read-only synchronization; they do not update source bookings from external calendar apps.

ICS Calendar can subscribe to the URL as a destination. Importing remote feeds that ICS Calendar itself displays is not yet supported.

== Installation ==

1. Install the Dendrila Calendar plugin directory.
2. Activate the plugin.
3. Open Dendrila Calendar in WordPress administration.
4. Add a Dendrila event or enable optional source plugins.
5. In Calendar synchronization, generate a private subscription link and subscribe to it in your calendar application.

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
* Not yet released or tested end-to-end on a production-like WordPress installation.

= How can I subscribe with Google Calendar? =

Open Dendrila Calendar > Synchronization. Create a private subscription URL for your sources. In Google Calendar on the web (desktop), use Settings > Add calendar > From URL and paste it. The mobile app cannot subscribe from a URL directly. Google refreshes the calendar on its own schedule.

= Is this real-time, bidirectional Google or CalDAV synchronization? =

No. This is automatic read-only iCalendar subscription. Changes to original WordPress bookings are reflected when the calendar application refreshes. Editing or moving an event in another calendar does not edit its source booking.
