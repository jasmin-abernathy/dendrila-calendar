=== Dendrila Calendar ===
Contributors: jasmin-abernathy
Tags: calendar, events, bookings, administration
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Calendar, appointment booking and Google/Outlook synchronization for WordPress, with optional WooCommerce payments.

== Description ==

Manage existing services directly under Services, with one edit link to the source product or native service. Use the arrows to browse the calendar by month or week, or swipe horizontally in the month grid on a phone.

Development test version. Do not publish to WordPress.org before end-to-end testing and deployment of a publisher-managed OAuth authorization service. Google and Outlook connections work with administrator-configured OAuth applications; CalDAV direct push and private ICS subscriptions are also supported.

Dendrila Calendar provides native profiles, schedules, services and a public booking form. Administrators can choose a booking calendar design (Classic, Cards, Minimal or High contrast), customize the selected design's colors, open a permanent WooCommerce booking guide, and start a bookable product directly with Products > Add a service. Weekly hours, date exceptions, timezone, notice, booking horizon, intervals, buffers and shared/service limits apply again under a database lock when reserving. Paid services are edited in a WooCommerce product tab and use WooCommerce orders and the official order-payment page.

Dendrila Calendar is also an independent WordPress administration calendar that brings together native events and compatible event and booking plugins. All third-party integrations are optional and read-only. The original plugins remain responsible for reservations, payments and their data. Customer details are not included in the calendar event payload.

= Sources =

* Personal events: manually created private WordPress events (or individual events imported via optional two-way synchronization).
* Event sources: Event Organiser, Events Manager and The Events Calendar (published single events only).
* Booking sources: Amelia, Booking Calendar, Bookly, Easy Appointments, FluentBooking, Oryx Bookings and Simply Schedule Appointments.
* Ten external sources in total, all optional and read-only.

Search loaded events locally. On first activation, the required setup assistant detects active booking plugins and lets you choose your sources and destination. No external CDN or mandatory commercial dependency. Native booking profiles and services are private WordPress content types, independent of the installed booking plugins.

= Calendar subscriptions =

Create a private, revocable subscription URL in Dendrila Calendar > Calendar synchronization. Choose the source plugins to include. Add the link in an external calendar using Subscribe from URL (not one-time file import). The calendar application controls its refresh schedule. The link is shown only once and should be kept secret. Requires HTTPS. Subscriptions provide read-only synchronization; they do not update source bookings from external calendar apps.

ICS Calendar can subscribe to the URL as a destination. Importing remote feeds that ICS Calendar itself displays is not yet supported.

== Installation ==

1. Install the Dendrila Calendar plugin directory and activate it.
2. Follow the Configuration assistant, then its booking guide (always available under Dendrila Calendar > Booking guide). Dendrila's own events and appointments are selected by default; other plugin integrations are optional.
3. Choose the appointment sources to synchronize.
4. Choose the calendar service you use (Nextcloud, iCloud, Google, Outlook or another service), or local-only mode.
5. For Nextcloud and compatible services, enter the cloud URL, account login and application password. Select a calendar from the discovered list; the setup only completes when the connection succeeds.
6. If an active booking plugin has no usable adapter, validation sends a limited, deduplicated technical compatibility report to the maintainer using WordPress email. The address is not displayed; no customer or booking data is sent.

== Native bookings and WooCommerce ==

* Choose the public booking design at Dendrila Calendar > Appearance and customize its accent, text and background colors with a live preview. Each model remembers its own palette. Override the model on a page using [dendrila_booking style="cards"].

* Create and publish a profile under Dendrila Calendar > Profiles, then enable availability and enter its weekly hours, exceptions and timezone.
* For a paid appointment, use Products > Add a service (near Add new product), or edit an existing simple WooCommerce product and open Appointment booking. Keep name, description and price in the normal Woo fields; choose a responsible profile, duration, buffers and booking rules. Dendrila maintains the linked service automatically and makes the product virtual.
* The product page offers time selection instead of ordinary Add to cart. Direct cart purchases without a selected appointment are blocked, including through the Store API.
* Free services can also be created directly under Dendrila Calendar > Services. Add [dendrila_booking] to a page, or use the service-specific shortcode shown by its editor.
* Shared profile locks, fresh database reads and idempotency prevent concurrent bookings and duplicate orders. Profile and service day/week/month limits are enforced at confirmation.
* Payment holds last up to 20 minutes. WooCommerce payment confirms; manual on-hold payment retains the slot; failure/cancellation/full refund releases it. Partial refunds retain the appointment. Late payment conflicts are marked for manual resolution, never falsely confirmed.
* Woo order CRUD APIs support HPOS. The official pay-for-order flow is used; no separate payment method is injected into Cart/Checkout Blocks.
* Confirmed reservations export service title/time without customer contact details. Bidirectional external editing remains limited to ordinary Dendrila local events and does not bypass appointment/payment validation.
* The native booking engine requires MySQL/MariaDB advisory locks. It refuses a booking if locking or enabled provider availability checks fail.
* See docs/RESERVATIONS-HORAIRES-WOOCOMMERCE.md and docs/TESTING-NATIVE-BOOKINGS.md in the repository.

== Frequently Asked Questions ==

= Does it change appointments? =

Bookings from other WordPress plugins remain read-only. Single Dendrila local events can optionally synchronize in both directions with Google/Outlook. When an administrator enables availability protection, the plugin can reject new Easy Appointments booking requests that overlap connected calendar busy times.

= Is Google/Outlook one-click setup available to all sites? =

Not yet. This development build requires Google/Microsoft OAuth apps configured by the site administrator. A secure publisher-operated OAuth broker is required before no-configuration public distribution. Never ship shared OAuth secrets inside a WordPress ZIP.

= Is it public? =

The WordPress administration calendar is restricted to administrators. Enabled booking forms are public and do not require a WordPress account; reservation records and contact details remain private. Private subscription URLs can be accessed by anyone who holds the secret link; these links can be revoked.

== Changelog ==

= 0.0.6 (development: Google/Outlook two-way query encoding) =
* Test UX without version bump: renamed the native booking source to Your appointments (Vos rendez-vous in French), clarified personal events, and moved third-party integrations behind optional sections.
* Four booking designs now support individually saved accent, foreground, background and highlight colors, with live previews, strict hex validation, contrast checks and a reset button.
* Simplified service creation: one primary WooCommerce product entry, with free native services as an optional secondary path; existing links are preserved.
* Correct RFC3986 encoding of Google events.list timeMin/timeMax and Google nextPageToken; offset +00:00 was parsed as a space by the provider, producing HTTP_400 on calendar reads.
* Apply the same encoding to Microsoft calendarView start/end dates and query fields, while leaving Graph nextLink URLs unchanged.
* Keep all 0.0.5 booking designs, WooCommerce service shortcut, setup guide, event mappings and existing connection credentials intact.
* Real provider read/import round trip still needs verification with a dedicated test calendar.


= 0.0.5 (development: booking designs and guided WooCommerce setup) =
* Four booking-form designs (Classic, Cards, Minimal and High contrast) under Dendrila Calendar > Appearance, now with individually saved color palettes and live previews; optional per-shortcode style override.
* Step-by-step booking guide linked from initial setup and permanently available under Dendrila Calendar > Booking guide.
* Products > Add a service now sits immediately after Add new product; a new-service product starts with booking settings enabled and a clear panel-opening button.
* Existing products can be enabled from Product data > Appointment booking without creating a duplicate product.
* Includes the previous Google/Outlook diagnostic codes and timezone-aware Google export; real two-way provider round trip remains to be verified.

= 0.0.4 (development: native availability and WooCommerce appointments) =
* Weekly schedules, overrides, timezone/DST, booking notice/horizon, start intervals, buffers and shared/service limits.
* Public slot selection, private confirmation, cancellation and calendar download.
* Profile and request SQL locks, refreshed WordPress caches and real concurrent reservation tests.
* WooCommerce product appointment fields, automatic service association, slot-first product pages and cart guards.
* WooCommerce/HPOS orders, expiring holds, payment reconciliation, partial/full refunds and late-payment conflict handling.
* Two-way proposed for new OAuth destinations; existing/reconnected destinations keep their stored direction.
* The provider round trip and real payment-gateway acceptance remain manual checks.

= 0.0.4 (earlier development: two-way local events) =
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
