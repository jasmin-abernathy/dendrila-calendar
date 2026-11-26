# Connecting external calendar accounts (0.0.4 development build)

Dendrila Calendar exports supported WordPress booking sources to external calendars. Google and Microsoft additionally support **opt-in two-way synchronization of single Dendrila local events**. Existing connections remain in export-only mode after an update.

## Google Calendar and Microsoft Outlook: OAuth

The WordPress admin flow is **Dendrila Calendar → Calendar synchronization → Google / Outlook → Connect with Google Calendar / Microsoft Outlook**. The site administrator authorizes access on the provider's official sign-in page, chooses a writable calendar, selects appointment sources, and confirms the tested connection.

The plugin already contains OAuth authorization-code + PKCE handling, encrypted server-side tokens, calendar discovery, create/update/cancel and local disconnection. Provider-side app consent can be revoked in the account settings. It intentionally does not ship with any client secret.

For a **development site**, register the OAuth app with the provider, then open **WordPress admin → Dendrila Calendar → Calendar synchronization → Google / Outlook → Configure application credentials**. Paste the client ID and client secret into the mobile-friendly administrator form and save. The secret is encrypted before it is stored in the WordPress database and never displayed again. This does not remove the requirement to create the provider application; it removes the need to edit PHP files on mobile.

Alternatively, publishers can configure these server-side constants in `wp-config.php` (above the stop editing marker):

```php
define( 'DENDRILA_CALENDAR_GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_ID' );
define( 'DENDRILA_CALENDAR_GOOGLE_CLIENT_SECRET', 'GOOGLE_CLIENT_SECRET' );
define( 'DENDRILA_CALENDAR_MICROSOFT_CLIENT_ID', 'MICROSOFT_CLIENT_ID' );
define( 'DENDRILA_CALENDAR_MICROSOFT_CLIENT_SECRET', 'MICROSOFT_CLIENT_SECRET' );
```

Only configure the provider under test; the other can remain unconfigured. **Never commit client secrets**, paste them into frontend JS, or include them in a downloadable ZIP.

Register the exact HTTPS OAuth redirect URI used by this site:

`https://YOUR-WORDPRESS-HOST/wp-admin/admin-post.php`

This URI intentionally has no query parameters, including for Microsoft personal accounts. It must match WordPress's `admin_url( 'admin-post.php' )` and be reachable over HTTPS.

- **Google:** Create an OAuth **Web application** client in Google Cloud, enable Google Calendar API, configure the consent screen and add your Google email as a test user while the app remains in Testing status. Configure the exact redirect URI above. The plugin requests calendar-list read and event write scopes. Google may require verification before broader public use.
- **Microsoft:** Register a **Web** application in Microsoft Entra, with an account type allowing personal Microsoft accounts if Outlook.com is intended; add the exact redirect URI above, and configure delegated `Calendars.ReadWrite` and `offline_access`. Depending on tenant policy, administrator consent may be necessary.

If no OAuth client is configured, the corresponding Connect button stays disabled and presents the administrator-only credential form. In public distribution, users should not need developer credentials; this manual setup is for staging and development.

For a distributable plugin where end users never create OAuth clients, use a **publisher-owned authorization service** with secure token handling and a registered redirect, implemented and independently reviewed before a public rollout. Do not distribute a shared client secret in a WordPress plugin.

## Other providers

- **Nextcloud and generic CalDAV:** Connect directly through CalDAV discovery and the account's application password or token, as supported by that server. No Google/Microsoft OAuth is involved.
- **Apple iCloud Calendar:** Connect through CalDAV with an Apple app-specific password when supported. Apple Calendar/iCloud does not expose an equivalent general-purpose Google Calendar OAuth authorization flow for third-party WordPress calendar synchronization.
- **Other calendars accepting iCalendar:** Use the private revocable HTTPS ICS subscription. This is a read-only subscription with app-controlled refresh frequency, not two-way OAuth.

## Enabling two-way events

In the calendar-selection step, check **Synchronize local events in both directions**. An existing connection has the same setting and a **Save synchronization direction** button; another consent flow is unnecessary.

This setting synchronizes single event titles, dates/times and all-day status. New events are discovered from 7 days ago to 85 days ahead; existing linked events remain tracked when moved outside that window. Local deletion removes the remote copy, and confirmed remote deletion moves an unchanged local copy to WordPress Trash. Conflicting changes are reported and neither version is overwritten. Recurring events and invitations are skipped. Bookings owned by other WordPress plugins remain outbound only.

Existing imports are private and excluded from unrelated exports/subscriptions. Disconnecting removes active account credentials; non-secret event links and the destination identity remain for duplicate-free reconnection. Regular work uses WordPress Cron, approximately every five minutes when site traffic triggers it. The source limit is 500 events per pass and is reported when reached.

Busy-time protection is a separate opt-in feature for Easy Appointments 4.x. The native public booking form and native WooCommerce checkout remain disabled.

## Validation checklist

1. Run PHP syntax checks, JavaScript syntax checks and the existing OAuth-flow and calendar tests.
2. On an HTTPS WordPress staging site, configure provider credentials without committing them.
3. Follow consent, callback, calendar selection and the temporary create/delete probe using a disposable test calendar.
4. Create, move and cancel a test booking; verify its corresponding remote event and no duplicates. Enable two-way mode only on a disposable calendar; create/edit/delete one event from each side, test an all-day event and a simultaneous-edit conflict.
5. Disconnect and confirm refresh tokens are not used again. Reconnect the same calendar and verify existing local/remote identities are reused without duplicate events. Existing events are intentionally retained on disconnect.
6. Test both Google and Microsoft separately, including expiration and token refresh.

## Provider references

- [Google Calendar conditional modification / ETags](https://developers.google.com/workspace/calendar/api/guides/version-resources)
- [Google Calendar patch semantics](https://developers.google.com/workspace/calendar/api/guides/performance)
- [Microsoft Graph event resource and transactionId](https://learn.microsoft.com/en-us/graph/api/resources/event?view=graph-rest-1.0)
- [Microsoft Graph calendarView](https://learn.microsoft.com/en-us/graph/api/calendar-list-calendarview?view=graph-rest-1.0)
