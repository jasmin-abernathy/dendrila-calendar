# Dendrila Calendar — first-party booking architecture (0.0.3 development)

## Product decision
Dendrila Calendar becomes a native WordPress appointment manager. Existing read-only sources (Easy Appointments, The Events Calendar, Bookly and other supported plugins), ICS subscriptions and Google/Outlook/CalDAV connections remain available, but are **optional integrations**, not dependencies of the new booking engine.

No data migrations, external cancellations or WooCommerce order mutations occur on activation.

## Mandatory profiles (implemented in this branch)
- Every native booking **must** reference a valid, published booking profile AND an owned, published service.
- A profile may represent a professional, room, association or other reservable resource. It is not necessarily a WordPress user or a WooCommerce customer.
- A site's first profile must be created by an administrator. The plugin does **not** invent or silently assign one.
- Post types: \`dendrila_profile\`, \`dendrila_service\`, \`dendrila_booking\` (all nonpublic, admin-only; no REST API exposure).
- Service metadata: \`_dendrila_booking_profile_id\`, \`_dendrila_duration_minutes\`, \`_dendrila_buffer_before\`, \`_dendrila_buffer_after\`, \`_dendrila_price_cents\`, \`_dendrila_woo_product_id\`.
- Booking record schema: \`_dendrila_booking_service_id\`, \`_dendrila_booking_profile_id\`, \`_dendrila_booking_start_utc\`, \`_dendrila_booking_end_utc\`, \`_dendrila_booking_status\`.
- The admin UI and \`validate_booking()\` reject unassigned services, draft/inactive profiles, mismatched identities and mismatched duration.
- Disabling a profile prevents new bookings from being offered. Historical reservation references must remain intact for audit and cancellation handling.
- Existing calendar synchronization and setup remain usable on already configured installations even before native profile setup, to avoid breaking active sites.

## Native booking flow to implement next (NOT enabled yet)
1. Per-profile weekly availability, breaks, exceptional leave and timezone policy; services inherit the profile's hours but can narrow them.
2. Accessible Gutenberg block and shortcode \`[dendrila_booking]\` to choose service, profile and day. AJAX or REST endpoint returns privacy-safe available slots.
3. Reconcile booked native slots and Google/Outlook busy intervals; provider errors must never advertise false availability when the administrator enables strict protection.
4. Database-enforced or locked booking hold with expiry, idempotency key and atomic collision check. Do not rely on ordinary WordPress post meta queries for concurrency safety.
5. Free services can confirm without checkout; paid services use WooCommerce. Never create an unpayable "confirmed" booking or charge a customer before a hold exists.
6. Booking lifecycle: draft → held → pending_payment → confirmed → cancelled / expired / refunded; define a reconciliation policy for unexpected payment success.
7. Keep booking identities in Google/Outlook event metadata so edits, cancellations and retries do not duplicate events. Never infer that moving a remote event automatically edits the customer's booking without validation.
8. Notifications, cancellation policy, rescheduling, privacy retention, consent, calendar visibility and rate limits.

## WooCommerce (optional; preparation implemented)
- \`Dendrila_Calendar_Woo\` exposes a *read-only* optional product reference and order lookup through Woo CRUD APIs.
- No checkout hook has been registered. No payment is attempted in this version.
- Build a product/service association, a server-side hold linked to order item metadata, expiry and cancellation/reconciliation handlers.
- Support both classic checkout and Cart/Checkout Blocks through WooCommerce Store API extension points.
- Use \`wc_get_order()\` / \`wc_get_product()\` rather than direct \`wp_posts\` or \`wp_postmeta\` queries for Woo orders (HPOS).
- Only declare HPOS/Cart Checkout Blocks compatibility after tests, not on the basis of the scaffolding alone.
- Woo order status and reservation status are separate; an order cancellation/refund must follow explicit booking rules.

Official references:
- https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/
- https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/
- https://developer.woocommerce.com/docs/extensions/best-practices-extensions/compatibility

## UI & product rules
- Calendar remains first on the landing page. Date/view controls and filters remain above the grid; profile/service administration links stay below.
- New menu items are Profiles, Services, Bookings. Service editor makes profile assignment mandatory, with a creation link when no profile exists.
- Small, optional "Offrez un café au développeur" footer points to https://app.lepotager.org/soutenir/ (same link as Dendrila Simple Edit).
- Preserve the original plugin version 0.0.3 throughout testing; identify builds by commit SHA, not fake releases.

## Validation
- PHP syntax, existing adapters and translations, test-native-bookings.php.
- Test opening Dendrila on an existing site with NO profile: existing calendar and Google connections still work; native booking setup clearly requests a profile.
- Test create, publish, draft/unpublish, rename and delete profile; linked service cannot become bookable if its profile is inactive.
- Test multiple profiles and different service durations; reject profile mismatch, invalid slot and duration mismatch.
- Verify WooCommerce absent/present without fatal errors and without any payment side effects.
- Before a *public booking* release, add concurrency, refund, double-booking and browser responsive tests. The scaffold alone does not meet these acceptance criteria.
