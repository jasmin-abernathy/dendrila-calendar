=== Dendrila Calendar ===
Contributors: jasmin-abernathy
Tags: calendar, events, bookings, administration
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A private unified calendar for local events, Oryx Bookings and The Events Calendar.

== Description ==

Development version. Do not publish to WordPress.org before integration testing.

Dendrila Calendar gives WordPress administrators one calendar for multiple sources. Imported records are read-only and remain managed by the original plugins. Customer details are never included in the calendar event payload.

= Sources =

* Dendrila events: manually created private WordPress events.
* Oryx Bookings: existing bookings shown with service, status and dates.
* The Events Calendar: published, non-recurring events from UTC metadata.

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

= 0.1.0 =
* Initial development version with local events, Oryx bookings and The Events Calendar adapters.
