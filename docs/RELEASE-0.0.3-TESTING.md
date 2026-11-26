# Dendrila Calendar 0.0.3 — verification notes

## Fixes from observed screenshots
1. WordPress sidebar icon is the built-in `dashicons-backup` at the native WordPress menu size (20 × 20 px). Do not enlarge with custom CSS.
2. Back to calendar uses the registered slug `admin.php?page=dendrila-calendar` in both synchronization and setup pages; `dendrila-calendar-view` was nonexistent and produced an access-denied message.
3. The calendar is the primary page content. Controls for month, week/list, date, source filters and search remain **above** the calendar; secondary actions are below it.
4. On mobile, monthly view remains a seven-column grid and day selection reveals event details below. Server-only appointment list is a collapsible details element; event source adapters and local event editing are unchanged.
5. On OAuth confirm, a provider/calendar already saved is reauthorized *in place*, retaining synchronization map; adding a different calendar remains allowed.
6. Older duplicate entries can be consolidated by an admin button. This only merges local known identities/sources and removes the redundant *connection configuration*. No Google/Outlook event is deleted or modified during consolidation. If two duplicate connections previously created different remote events for one source item, orphaned remote events may need manual review.

## QA matrix (staging only)
- Updating from 0.0.2 must preserve Google and Outlook tokens and sync maps.
- Reauthorize the same Google and then the same Outlook calendar: still one saved connection each.
- Manually consolidate old duplicate Google entries and verify sync identities, sources and bookings remain.
- Repeat sync and verify no new duplicate events are created.
- Test calendar layout at 320/375/430/768/1280 px, with 7-column mobile month, accessible day picker and day details, responsive source controls and fixed return links.
- Verify Google/Outlook busy-time protection and Easy Appointments unchanged when toggled off.
- Check WordPress menus at desktop and mobile sizes: the Dashicon must be standard-sized (20 × 20 px), no special icon enlarge rule.
- Do not merge PR #2 before manual browser verification. No hosted OAuth broker is included in this ZIP.


## Public Easy Appointments availability interception (follow-up)

- When the site administrator has explicitly enabled **Protect Easy Appointments reservations**, the public `ea_date_selected` AJAX response is intercepted by an early output buffer after its normal nonce check. The original Easy Appointments JSON structure is retained; overlapping slots get `count: 0` and are therefore non-selectable in its own UI.
- Dendrila makes one read-only provider availability request per day (cached for 20 seconds for the public display). It does not expose event titles. The final booking guard calls providers again without display-cache reuse.
- Remote time-window lookups use WordPress timezone including DST. Service duration and before/after buffers are read from the selected Easy Appointments service. Unsupported multi-service selections retain the provider's original UI but final server-side protection remains.
- If a provider cannot be reached and protection is enabled, slots are disabled rather than advertised as free.
- Final reservation confirmation (`easy_ea_can_update_reservation`) also checks the calendar. Do not remove the earlier reservation-creation guard.
- Test real Easy Appointments plugin version on the WordPress staging site. Output buffering is a compatibility adapter required because current EA does not expose a server-side filter for public available slots; retest after EA upgrades.
- Do not use the code on a production booking site before validating response shape and performance with a live Google busy event, a free event, an Outlook event and provider failure.
