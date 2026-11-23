# Dendrila Calendar 0.0.2 test checklist

This development build is **not a public release** and does **not** provide a hosted shared OAuth authorization service.

## Existing features
- Google/Outlook authorization and one-way appointment event sync.
- Existing Nextcloud/CalDAV and private iCalendar subscription modes remain.
- Stable per-source event identities; a synchronized event is not another booking source.

## New optional availability guard
- With at least one connected Google/Outlook account, an administrator may activate "Protect Easy Appointments reservations" on the Synchronization page.
- Before Easy Appointments accepts a new reservation, Dendrila reads remote busy periods and refuses overlapping bookings.
- The remote events are never edited, imported into the booking database or exposed to users.
- The guard is **off by default** and fails closed only when the site administrator enables it.
- This does not yet hide externally occupied times from the Easy Appointments slot picker and does not claim support for other booking engines.
- Microsoft personal Outlook uses Graph calendarView, not getSchedule.
- If a provider is unavailable, the booking cannot be accepted while protection is enabled. Check logs/provider status and retry.
- Test Europe/Paris DST transitions and booking sites' working hours before release.

## Onboarding
- Google and Microsoft accounts already configured on the staging site remain connected after upgrade; no need to repeat consent for the existing event scopes.
- The admin-created OAuth apps are still required in this development build. A publisher-operated service must be built, secured, hosted and provider-approved before public no-developer-configuration onboarding.
- Credentials are never to be committed to Git; store them in encrypted WordPress settings on staging.
- After provider consent, the destination and final create/delete probe are explicitly explained.

## Test matrix
1. Update ZIP on a staging WordPress that already uses Google and Microsoft.
2. Check both existing connections appear, no migration or credentials are lost.
3. Enable guard; create a busy Google event and attempt an overlapping Easy Appointments reservation, expect refusal.
4. Repeat with a personal Outlook event.
5. Confirm an unrelated free time can be booked.
6. Disable guard, verify Easy Appointments operates as before.
7. Disconnect one account; confirm other connections remain, and guard does not misrepresent any provider errors.
8. Check 320/375/430/768/1280px, light/dark, focus order and messages in French and English.
9. Verify no stranded "Dendrila Calendar test" events after connection tests.
