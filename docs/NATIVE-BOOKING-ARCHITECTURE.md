# Dendrila Calendar — native booking architecture (0.0.4 development)

## Implemented flow

Profiles and services remain private administration content types. Every public booking references a published responsible profile and a published service. Availability and booking are explicitly enabled; activation does not invent a profile or migrate existing reservations.

`Dendrila_Calendar_Schedule` normalizes weekly hours/date overrides and per-service policies, generates available UTC slots, and revalidates acceptance. It reconciles appointments and their saved buffers across all services of a profile, active payment holds, local events and explicitly enabled connected-calendar checks. Shared and service limits use the profile timezone. Buffers may extend outside working hours, while the appointment itself must fit.

`Dendrila_Calendar_Booking::create()` is the only public confirmation path: validate input and consent → lock the request ID → lock the profile → refresh WordPress query/meta caches → recheck policy, occupancy and quota → verify lock ownership and unchanged settings → save a private booking → release the profile lock → prepare Woo payment if needed. A lost/unsupported SQL lock fails closed. The draft `booking_record()` helper is not a public confirmation API.

The public shortcode/JavaScript returns only available slots, service names, timezone and the visitor's own confirmation. Customer name/email remain private. Source exports contain the service title and time, not contact details. Native booking exports cannot be modified via the ordinary two-way local-event reconciler.

## Product ownership

For paid/product-backed appointments, WooCommerce owns product name, description, price, tax calculations and orders. The product Appointment booking tab owns profile assignment, duration, buffers and booking rules. A linked internal service is created or updated on product save. An invalid appointment configuration preserves previous rules; its own nonce is distinct from the standalone service editor. Editing links return to the owning product.

The product page replaces ordinary Add to cart with slot selection. Classic and Store API add-to-cart/checkout guards prevent purchases of linked appointment products without this flow. Normal products are unaffected. Free standalone services remain independent of WooCommerce.

## Payment lifecycle

A product-backed booking starts as `pending_payment`, with a hold up to 20 minutes. The hold precedes order creation. One real WooCommerce order and line item carry private booking/service/profile/UTC identifiers. Only a ready order can be paid through Woo's official pay-for-order page. Retries keep the original order and amount. A zero-total product also completes a real Woo order.

Order payment/status hooks re-read the order under the profile lock. Paid processing/completed orders confirm. `on-hold` keeps the slot reserved for manual payment review. Failed/cancelled orders and full refunds release it; partial refunds retain it. Pending hold expiry is enforced by both availability reads and a scheduled cleanup. The actual Woo pay action rejects expired holds before gateway selection.

Late payment after expiry rechecks availability. Cancellation or collision produces `payment_conflict` and an order note, never a silent confirmation. No automatic refund is performed. Paid customer cancellation asks for organizer contact; administrators can release the booking and manage refunds in WooCommerce.

All order access uses Woo CRUD APIs; no SQL assumes orders are posts. Real integration tests cover HPOS. This order-payment flow does not claim to inject a new payment method into Cart/Checkout Blocks. Payment gateway configuration and a real/test-gateway transaction remain storefront acceptance work.

## Administration and security

Administration, schedules and account connections require `manage_options`. Public booking uses a separate nonce, bounded requests, consent, a honeypot and hashed short-lived request throttles. Idempotency and profile locks are database locks, not transient-cache assumptions. Lock identity is checked after provider work to detect database reconnection. No third-party booking record or Woo refund is silently changed by an external event.

See [French operator guide](RESERVATIONS-HORAIRES-WOOCOMMERCE.md), [test procedure](TESTING-NATIVE-BOOKINGS.md), and [OAuth guide](UTILISATION-GOOGLE-OUTLOOK.md).

## Deliberate remaining scope

Recurring/group/round-robin booking, subscriber-owned calendar connections, a publisher-managed OAuth broker, automatic rescheduling of customer bookings from external calendars, and a custom Woo payment gateway are not implemented. Existing read-only adapters remain optional. Free-booking confirmation is on screen; no automatic email is promised by that form. WooCommerce retains its normal order notification behavior.
