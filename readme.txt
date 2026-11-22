=== Dendrila Calendar ===
Contributors: jasmin-abernathy
Tags: calendar, events, bookings, administration
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A private unified WordPress calendar for events and optional booking plugins.

== Description ==

Development version. Do not publish to WordPress.org before integration testing.

Dendrila Calendar gives WordPress administrators one calendar for multiple sources. Imported records are read-only and remain managed by the original plugins. Customer details are never included in the calendar event payload.

= Sources =

* Dendrila events: manually created private WordPress events.
* Oryx Bookings: existing bookings shown with service, status and dates.
* The Events Calendar: published, non-recurring events from UTC metadata.
* Event Organiser, Events Manager, Easy Appointments, FluentBooking, Simply Schedule Appointments, Bookly, Amelia and Booking Calendar: optional read-only integrations.

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

= 0.2.0 (development) =
* Added eight additional optional read-only source adapters; reviewed respective source archives.
* Added select-all/select-none source toggles, timezone calendar navigation fixes, and isolated regression tests.
* ICS Calendar feed integration is deferred until a safe consent-based local configuration exists.

= 0.1.0 =
* Initial development version with local events, Oryx bookings and The Events Calendar adapters.
