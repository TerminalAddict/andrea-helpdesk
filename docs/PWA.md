# Progressive Web App And Push Notifications

Andrea Helpdesk is an online-first PWA. It can be installed on supported devices and can send browser / OS push notifications to agents who opt in.

The service worker intentionally caches static application assets only. API responses, tickets, customer data, attachments, and authentication responses are not cached.

---

## Admin Setup

1. Open `#/admin/settings/notifications`.
2. Click **Generate Keys**, or paste an existing VAPID public/private key pair.
3. Set `PUSH_VAPID_SUBJECT` to either:
   - `mailto:support@example.com`
   - `https://support.example.com`
4. Save the settings.
5. Confirm the status shows **Push notifications are configured**.

The diagnostics panel also shows:

- active push subscription count
- number of subscribed agents
- last subscription refresh time
- Web Push Composer dependency availability
- required PHP extensions: `curl`, `mbstring`, `openssl`
- OpenSSL `prime256v1` curve support
- most recent push send failure, if any

The VAPID private key is encrypted at rest and masked in the admin UI. The public key is exposed to authenticated agents so their browser can create a push subscription.

---

## Agent Setup

1. Open `#/my-profile/settings/notifications`.
2. Choose which notification types you want to receive.
3. Use **Subscribe In Your Browser** or **Enable Browser Notifications**.
4. Allow notifications when the browser prompts.
5. Use **Send Test** to confirm local browser notification support.
6. Use **Send Push Test** to confirm server-side Web Push delivery to this browser/device.

Push notifications are still governed by the agent's notification preferences. If an agent disables a notification type, Andrea Helpdesk does not create or push that notification for that agent.

---

## Installing The App

### Desktop Chrome / Edge

1. Open Andrea Helpdesk.
2. Use the browser install icon in the address bar, or open the browser menu and choose **Install Andrea Helpdesk**.
3. Launch the installed app from the desktop/app launcher.

### Android Chrome

1. Open Andrea Helpdesk in Chrome.
2. Open the Chrome menu.
3. Tap **Add to Home screen** or **Install app**.
4. Open Andrea Helpdesk from the new Home Screen icon.

Android Chrome supports Web Push for installed and normal browser contexts, subject to browser and OS notification settings.

#### Android Install Screenshots

The following screenshots show the Android Chrome install and notification permission flow.

![Android Chrome install menu](screenshots/Andrea%20Helpdesk%20install%20on%20Android.jpg)

Chrome shows the Andrea Helpdesk site menu with the install option available.

![Android install app option](screenshots/Andrea%20Helpdesk%20-%20install%20app.jpg)

The browser exposes the **Install app** action when the PWA manifest and service worker are available.

![Android install confirmation prompt](screenshots/Android%20prompt%20install%20Andrea%20Helpdesk.jpg)

Android asks the user to confirm installing Andrea Helpdesk.

![Android notification permission prompt](screenshots/Andrea%20Helpdesk%20wants%20to%20send%20you%20notifications%20-%20Android.jpg)

After an agent enables browser notifications, Android asks whether Andrea Helpdesk can send notifications.

### iPhone / iPad Safari

1. Open Andrea Helpdesk in Safari.
2. Tap the **Share** button.
3. Tap **Add to Home Screen**.
4. Open Andrea Helpdesk from the Home Screen icon.
5. Subscribe to notifications from `#/my-profile/settings/notifications`.

iOS Web Push requires the app to be installed on the Home Screen. A normal Safari tab cannot receive push notifications in the same way.

---

## Service Worker Behaviour

- Cache name is tied to the application version, so new releases create a fresh static asset cache.
- Old static caches are removed on service-worker activation.
- Static app assets are cached; API and attachment routes are always network-only.
- If the network is unavailable during a page navigation, the service worker shows a small offline page instead of a browser error.
- When a new service worker is installed, the SPA shows a refresh prompt so the user can switch to the latest cached assets.
- Notification clicks focus an existing Andrea Helpdesk window when possible, otherwise they open the linked app route.
- If the browser rotates a push subscription, the service worker attempts to re-subscribe and the authenticated SPA refreshes the server-side subscription on the next app load.

---

## Security Notes

- Push payloads are deliberately small: title, short body, internal route, tag, and icon.
- Ticket bodies, HTML content, customer records, attachments, JWTs, and secrets are not sent in push payloads.
- Push subscription endpoints are stored with a SHA-256 endpoint hash for unique lookup.
- Expired push subscriptions are removed when providers return `404` or `410`.
- Disabling browser notifications removes the current endpoint where possible, or all endpoints for the current agent if the endpoint cannot be read.
- The service worker scope is `/` so notification clicks and static app shell caching work across the SPA, but API and attachment responses are excluded from caching.

---

## FAQ

### Why does Android show both an install prompt and a notification prompt?

Installing Andrea Helpdesk and allowing notifications are separate browser/OS permissions. The install prompt adds the PWA to the device. The notification prompt grants permission for browser / OS push alerts.

### Why do I not see the install option?

Confirm the site is served over HTTPS, `manifest.webmanifest` is reachable, `service-worker.js` is reachable, and the browser supports installable PWAs. Some browsers hide the install action inside the main browser menu.

### Why do I not receive push notifications after installing?

Installing the PWA does not automatically subscribe the agent to push notifications. The agent must open `#/my-profile/settings/notifications`, enable browser notifications, and allow the browser permission prompt.

### Why does VAPID key generation say the Web Push dependency is missing?

The server is missing the Composer package used to generate VAPID keys and send Web Push messages. Run this from the application directory:

```bash
composer install --no-dev --optimize-autoloader
```

If you are on shared hosting without Composer, install from the full GitHub release package instead of the source zip. The full release package includes the `vendor/` dependencies. Current versions of the in-app updater also prefer the full release package and can repair missing PHP dependencies automatically if `vendor/` is writable by the PHP process.

If an install was already updated by an older updater that skipped `vendor/`, the VAPID key actions will try to repair dependencies automatically. The repair first uses Composer when available; if Composer is missing, it downloads the full GitHub release package for the installed version and copies `vendor/` from that package. If both repair paths fail, upload the full release package or run Composer manually from the application directory.

### Why does iOS behave differently from Android?

iOS Web Push requires the app to be added to the Home Screen and opened from the installed app icon. A normal Safari tab is not equivalent to the installed PWA for push notifications.

### Is helpdesk data available offline?

No. Andrea Helpdesk is online-first. The service worker caches static app files only; tickets, customers, attachments, API responses, and authentication data are not cached for security and freshness.
