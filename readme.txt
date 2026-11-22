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
* Event sources: Event Organiser, Events Manager and The Events Calendar (published, non-recurring events).
* Booking sources: Amelia, Booking Calendar, Bookly, Easy Appointments, FluentBooking, Oryx Bookings and Simply Schedule Appointments.
* Ten external sources in total, all optional and read-only.

No external CDN, custom booking tables or mandatory dependency.

== Installation ==

1. Install the Dendrila Calendar plugin directory.
2. Activate the plugin.
3. Open Dendrila Calendar in WordPress administration.
4. Add a Dendrila event or enable optional source plugins.

== Frequently Asked Questions ==

= Does it change appointments? =

No. Imported records are strictly read-only.

= Is it public? =

No. Only users with manage_options can view the private calendar.

== Changelog ==

= 0.0.1 (development; first internal test) =
* Initial unified calendar with local events and optional, read-only source integrations.
* Added source selection controls, timezone navigation safeguards and isolated adapter regression tests.
* ICS Calendar feed integration is deferred until a secure, explicit configuration exists.
* Not yet released or tested end-to-end on a production-like WordPress installation.
