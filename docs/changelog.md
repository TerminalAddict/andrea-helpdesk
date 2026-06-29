# Changelog

All notable changes to Andrea Helpdesk are documented here.

---

## [Unreleased]

---

## [1.4.18-dev.2] — 2026-06-29

---

## [1.4.18-dev.1] — 2026-06-29

### Added
- Added admin-configurable inbound email blocking with exact sender and domain pattern support.
- Added a configurable blocked-sender response message; blocked inbound email creates/records the ticket, sends only that response, and closes the ticket without agent, Slack, in-app, or push notifications.


---

## [1.4.17] — 2026-05-14

### Added
- Added stable/development update channel support in Version & Updates, including a new `update_channel` setting and development prerelease version handling.
- Added `make dev-release` and GitHub release-package support for development tags such as `dev-v1.4.17-dev.1`.
- Added a direct update-channel selector and save button inside the Version & Updates panel.

### Changed
- Updated the in-app updater and dependency repair flow to select full release packages from the configured stable or development channel and prevent downgrades when switching from development back to stable.

### Fixed
- Fixed chat ticket linking so internal ticket references use the full ticket number format, for example `#HD-2026-03-17-485`, and shorthand references such as `#123` render as the matching full ticket number while resolving to the correct ticket route.
- Fixed installed-app version polling so `/api/version` can be checked without admin-only authentication errors.


---

## [1.4.16] — 2026-05-12

### Changed
- Stabilised installed PWA update handling by removing automatic reload-on-service-worker-activation and showing a single manual refresh prompt instead.
- Reused the app's versioned service-worker registration for browser push subscriptions so notifications cannot alternately register a different `/service-worker.js` URL and trigger repeated update loops.


---

## [1.4.15] — 2026-05-12

### Changed
- Reissued the PWA/update hotfix as a new patch release so installed PWAs and in-app updater checks detect a newer version instead of staying on the affected `1.4.14` release


---

## [1.4.14] — 2026-05-12

### Changed
- Improved installed PWA update behaviour by versioning static asset URLs, registering the service worker with cache bypass, checking `/api/version` while the app is visible, and automatically reloading when the server version changes
- Improved updater handling on hosts without Composer so it verifies the full release package is available and no longer falls back to the source archive, which cannot update PHP dependencies without `vendor/`
- Removed the repeated service-worker “new version ready” toast path; waiting workers now activate automatically and only the fallback hard-refresh prompt remains


---

## [1.4.13] — 2026-05-12

### Added
- Added the internal agent chat foundation with channel chat, direct-message threads, safe plain-text rendering, `@chat_handle` mentions, read cursors, retention metadata, and per-channel notification preferences
- Added chat REST APIs for agent channels/direct messages, HTTP cursor recovery, admin channel management, admin direct-message history viewing, retention prune preview/run, and WebSocket status/action flags
- Added a PHP chat WebSocket daemon, cron supervisor, combined `bin/cron.php` runner, PID/heartbeat/log files, and systemd/reverse-proxy documentation
- Added the `#/chat` SPA screen plus `#/admin/settings/chatservice` controls for enabling chat, creating channels, viewing service status, and setting retention/WebSocket options
- Added chat notification preference controls to `#/my-profile/settings/notifications` and integrated chat mentions/direct/channel alerts with the existing in-app and PWA push notification system
- Added configurable chat WebSocket listen host/port settings so multiple Andrea Helpdesk installs can run on the same server without port conflicts
- Added an emoji picker to the chat composer and predictable conversion from common emoticons such as `:)`, `:P`, `:O`, and `<3` into Unicode emoji before messages are stored
- Added the same common emoticon-to-emoji conversion for agent ticket replies, internal notes, edited replies, and agent-created ticket messages

### Changed
- Agent records now include an optional chat `@handle`, editable from the admin Agents screen
- Hardened the chat WebSocket transport with same-origin handshake checks, authorised typing broadcasts, stricter listen host/port validation, and safer supervisor restart handling
- Direct-message thread creation is now idempotent under concurrent requests, avoiding duplicate-key race failures while preserving one thread per agent pair
- Edited ticket replies now update their plain-text body alongside the sanitised HTML body after emoticon normalisation
- The in-app updater now enforces release bridge metadata, blocking direct upgrades from installs older than `1.4.9` to newer releases until `1.4.9` has been installed first


---

## [1.4.12] — 2026-05-11

### Added
- Added a dynamic PWA manifest that uses the configured Application Name and branding icon values instead of hardcoded Andrea Helpdesk branding
- Added a `pwa_icon_url` notification setting for installs that need a PWA/mobile-safe icon separate from the browser favicon

### Changed
- Web Push payloads and local browser notifications now use the configured app name and PWA/Favicon icon fallback chain


---

## [1.4.11] — 2026-05-11

### Changed
- Web Push dependency repair now works on hosts without Composer by downloading the full GitHub release package for the installed version and copying `vendor/` from that package
- VAPID key actions now verify the Web Push classes after dependency repair and report a specific failure if `vendor/` remains unavailable or unwritable

---

## [1.4.10] — 2026-05-11

### Changed
- Added a dependency repair service used by VAPID settings actions so installs updated by the older updater can attempt to repair missing Web Push dependencies before returning an error
- VAPID key generation and VAPID setting saves now attempt a one-time Composer repair before reporting that the Web Push dependency is missing
- Updated PWA documentation to explain the transitional repair path for installs that were updated before `vendor/` was included in the updater flow

---

## [1.4.9] — 2026-05-11

### Changed
- In-app updates now prefer the full GitHub release package, copy packaged `vendor/` dependencies when present, and run Composer as a fallback so new PHP dependencies such as Web Push are installed during the update instead of failing later at runtime
- Updater preflight now checks for a PHP dependency update path and verifies `vendor/` writability because Composer/package dependency repair requires the PHP process to overwrite dependency files


---

## [1.4.8] — 2026-05-11

### Added
- Added per-agent notification preferences at `#/my-profile/settings/notifications`, including browser notification controls and checkboxes for update alerts, new tickets, assignments, replies, internal notes, SLA overdue alerts, and due-date overdue alerts
- Added online-first PWA support with `manifest.webmanifest`, install metadata, app icons, a minimal service worker, and static-asset-only caching
- Added Web Push notification delivery using VAPID keys, per-agent browser/device subscriptions, expired-subscription cleanup, and admin VAPID configuration at `#/admin/settings/notifications`
- Added an install prompt/status panel to `#/my-profile/settings/notifications`, including iOS Home Screen guidance
- Added push diagnostics for subscription counts, PHP extension support, OpenSSL curve support, last subscription refresh, and last push-send failure
- Added subscription-refresh handling so rotated browser push subscriptions are re-saved on the next authenticated app load
- Added `docs/PWA.md` with desktop, Android, and iOS install guidance plus service-worker security notes
- Added a server-side **Send Test Push To Me** action for browser/device push delivery testing
- Added an offline fallback page and a service-worker update refresh prompt
- Added separate padded maskable PWA icons for mobile launchers
- Added Android PWA install and notification-permission screenshots to the PWA and screenshot documentation

### Changed
- Reworked the notification center so the bell and Alerts Panel show current actionable notifications instead of read/unread state
- Opening a ticket now removes that agent's new-ticket, assignment, customer-reply, internal-note, and mention notifications for the ticket
- SLA and due-date overdue notifications now remain until the overdue condition is cleared, instead of being dismissed by opening the ticket
- Browser notifications now use service-worker push subscriptions when VAPID keys are configured, rather than only showing notifications while the app is open
- Service-worker static caches are now versioned with the app release so browser assets refresh reliably after deployment
- VAPID key generation and push diagnostics now report a clear missing Composer dependency message instead of exposing a PHP class-loading error

### Removed
- Removed the Mark as read / Mark all read workflow and the orange read-but-still-active notification state


---

## [1.4.7] — 2026-05-11

### Changed
- Internal note mode on ticket replies now highlights the entire reply editor area in warning yellow, making private notes visually distinct from public customer replies

---

## [1.4.6] — 2026-05-05

### Added
- Added a native self-hosted emoji picker to Quill rich-text editors without relying on the incompatible third-party `quill-emoji` plugin

---

## [1.4.5] — 2026-05-04

### Added
- Added drag-and-drop attachment upload support to ticket detail reply areas while keeping the existing `Attach Files` button
- Added drag-and-drop attachment upload support to the new-ticket attachment section

---

## [1.4.4] — 2026-04-27

### Added
- Added `make reset-admin-password` / `bin/reset-admin-password.php` to interactively list admin accounts, reset the selected admin password, and revoke that admin's existing refresh-token sessions

### Changed
- The admin **Agents** screen now includes inactive agents so historical ownership remains visible and inactive accounts can be reactivated from the same page


---

## [1.4.3] — 2026-04-23

---

## [1.4.2] — 2026-04-22

### Changed
- Support form embedding now uses a dedicated `/support-form/embed` entrypoint with a per-instance allowlist of permitted origins, instead of relaxing iframe headers for the whole SPA

### Security
- Tightened the public website support-form defenses with server-side throttles per IP address and per email address, and bound the fallback signed human-verification challenge to the requester IP address
- Removed active-content attachment types (`text/html`, `image/svg+xml`, `application/octet-stream`) from the upload allowlist so uploads and inbound email attachments cannot store executable browser content as attachments


---

## [1.4.1] — 2026-04-22

### Added
- Added a public website support form at `#/login/support-form` with an embeddable iframe mode and an admin `Support Form` settings section for direct links, embed snippets, and preview
- Added reCAPTCHA v3 configuration for the public support form, with a built-in fallback human verification challenge when reCAPTCHA keys are not configured

### Changed
- Public support form submissions now create normal inbound tickets with channel `Web`, including attachments stored against the initial customer message

### Fixed
- Embedded support form mode now strips the app chrome and background for clean iframe use
- Support form attachment limits now open in a proper modal instead of a browser alert
- Resolving or closing overdue tickets now reliably resets priority back to `normal`


---

## [1.4.0] — 2026-04-21

### Added
- Bounce emails matched to existing tickets now create structured delivery-failure system events instead of normal customer replies, and unmatched bounces are ignored
- Tickets now show an outbound email delivery warning state with preserved recipient and diagnostic details

### Changed
- Outbound delivery warnings now clear automatically after a later successful customer email on the same ticket
- Overdue tickets now normalize back to `normal` priority when the due date is removed, moved into the future, or the ticket is resolved/closed

### Fixed
- Ticket detail due-date controls no longer stack duplicate event handlers and spam repeated green toast notifications

---

## [1.3.12] — 2026-04-20


---

## [1.3.11] — 2026-04-20


---

## [1.3.10] — 2026-04-18


---

## [1.3.9] — 2026-04-18


---

## [1.3.8] — 2026-04-18


---

## [1.3.7] — 2026-04-18


---

## [1.3.6] — 2026-04-17


---

## [1.3.5] — 2026-04-16

### Added
- Added `bin/install-cli.sh`, an interactive Bash installer for local installs and SSH-driven remote installs, including prerequisite checks, `.env` generation, migrations, admin seeding, asset fetch, cron setup, and final verification

### Docs
- Updated `docs/INSTALL.md` to document the new CLI bootstrap installer command and its `public_html/` document-root requirement


---

## [1.3.4] — 2026-04-15

### Fixed
- In-app updater preflight now checks overwriteability of existing files, and the updater aborts immediately on copy failures instead of silently partial-updating the install

### Docs
- Added shared-hosting and file-ownership guidance to the Version & Updates documentation and settings UI


---

## [1.3.3] — 2026-04-15

### Fixed
- Attachment API payloads now issue fresh signed download tokens when tickets and replies are loaded, restoring access to historical attachments whose previously stored 24-hour tokens had expired


---

## [1.3.2] — 2026-04-15

### Added
- Added `make release` to bump the patch version, update release metadata files, commit, and push the current branch

### Fixed
- Removed the old `public_html/test` theme-lab from the repo, deploy flow, and production server
- Collapsed repeated active notifications for the same ticket issue in `#/my-profile/notifications`
- Opening `#/my-profile/notifications` now marks the unread notification queue as read immediately
- Notification and ticket-count badges now refresh more aggressively in the background so nav counts react faster to live changes
- The notification bell attention state now avoids showing stale numeric counts when all items are read but active issues still remain

### Docs
- Updated release workflow documentation to require a prepared `Unreleased` changelog section before running `make release`


---

## [1.3.0] — 2026-04-16

### Added
- Added an in-app notification center with a navbar bell, unread badge, ticket/deep-link alerts, and mark-read / mark-all-read actions
- Added optional browser notification subscription per agent in `My Profile` for live browser/OS alerts while the app is open
- Added once-per-day silent admin update checks that create an in-app alert linking to `Settings → General → Version & Updates`
- Added `/my-profile/notifications` as an active notification overview so read items remain visible there until the underlying issue is actually resolved

### Fixed
- Manual ticket priority changes to `overdue` now raise the same overdue alert flow instead of silently changing state
- Tickets whose due date has passed are now automatically escalated to `overdue` during the regular SLA/background runner
- Silent admin update checks now support a configurable version metadata URL and are serialised per admin to avoid overlapping upstream fetches during concurrent sessions
- The navbar bell now acts as a live unread queue, while its badge still signals active issues that need attention even after everything has been marked read

### Docs
- Updated README, API spec, DB schema notes, screenshot text, and versioning notes to describe the notification center and background update checks

---

## [1.2.8] — 2026-04-16

### Fixed
- Reduced the route/page fade timing and removed the blur effect so navigation feels faster and less sluggish while keeping a light transition
- Replaced terminal-style monospace rendering for plain-text ticket and reply bodies with a cleaner proportional reading font
- Narrowed monospace styling back to true code and technical surfaces instead of applying it to all `<pre>` blocks globally

---

## [1.2.7] — 2026-04-16

### Fixed
- Rebuilt reporting around separate live snapshot and ranged activity endpoints so the dashboard and reports page no longer share conflicting semantics
- Changed the reports default range to the first day of the current month through today
- Replaced the old reports summary with dashboard-matching `New`, `Waiting for Reply`, `Pending`, `Replied`, and `Overdue` cards scoped to tickets with activity in range
- Replaced daily ticket volume with daily ticket activity breakdowns for created tickets, customer replies, agent replies, internal notes, and system events
- Replaced the old assigned-ticket report with agent activity metrics for assigned, created, replied, noted, resolved, and closed work in range
- Added `created_by_agent_id` tracking plus migration `018_ticket_creator_reporting.sql` so manually-created tickets can be reported correctly by agent
- Fixed the production SQL error in the activity-volume report caused by ambiguous `created_at` references

### Docs
- Updated README, API spec, screenshot notes, and DB schema docs to describe the new reports model

---

## [1.2.6] — 2026-04-16

### Security
- Sanitised agent replies, knowledge base article HTML, agent signatures, and HTML email settings server-side on write instead of trusting only the browser editor
- Tightened server-side link sanitisation to allow only `http`, `https`, `mailto`, `tel`, and relative links inside rich-text content
- Validated DB charset/collation config before using it in connection bootstrap SQL
- Ignored `X-Forwarded-For` unless `TRUST_PROXY_HEADERS=true` is explicitly enabled
- Enforced the attachment MIME allowlist during upload and IMAP attachment storage, and switched stored attachment filenames to cryptographically random prefixes
- Held the in-app updater lock until file copy, schema updates, and migrations fully complete to prevent overlapping update runs
- Replaced the shipped theme-lab fallback snapshot data with anonymised sample identities

### Docs
- Updated README, versioning notes, and theme-lab documentation to describe the new hardening and configuration options

## [1.2.5] — 2026-04-16

### Fixed
- Replaced the old Bootstrap-style top nav with the new slimmer custom navigation, grouping `Agents`, `Settings`, `Reports`, and `Tags` under `Admin`
- Added the combined `User` menu with theme switching, email display, first-name label, and a direct `My Profile` shortcut
- Polished navbar spacing, contrast, and route strip alignment, including improved readability for the selected theme button in light mode
- Split the old settings tab model into route-based screens: `/my-profile`, `/admin/settings/<section>`, and `/admin/tags`

---

## [1.2.4] — 2026-04-16

### Fixed
- Improved dark-mode button contrast for primary and success actions so `Create New Ticket`, `New Article`, and IMAP `Poll Now` remain readable
- Prevented visited-link styling from leaking into anchor buttons in the terminal theme

---

## [1.2.3] — 2026-04-16

### Fixed
- Moved the dashboard `Recently Updated` widget onto its own row beneath `Overdue Tickets` and `My Assigned Tickets` so the table has enough horizontal space

---

## [1.2.2] — 2026-04-16

### Fixed
- Hardened route-mounted modal handling by detaching view modals to `document.body`, resolving greyed-out edit modals in Agents and IMAP Polling
- Removed hover-induced horizontal scrolling from the Tickets list by dropping row translation on hover
- Tightened Settings tab and content spacing and refined dashboard toolbar padding in the terminal theme

---

## [1.2.1] — 2026-04-15

### Added
- Configurable inactivity-based SLA escalation in Settings → General, with escalation from normal/high to **High** and then **Overdue**
- Dashboard overdue metric and dedicated overdue ticket list
- Overdue ticket highlighting in ticket lists and a prominent overdue assignee callout on ticket detail
- `last_attention_at`, `sla_high_notified_at`, and `sla_overdue_notified_at` ticket fields plus migration `017_sla_escalation.sql`

### Fixed
- SLA reminder recipient validation now enforces that “specific agents” must actually have selected recipients
- SLA reminder sends are now claimed atomically to reduce duplicate notifications under overlapping runners
- `bin/migrate.php` now applies numbered migrations and fails hard on migration errors instead of continuing silently
- Deploy command renamed to `make deploy`, with docs updated to match

---

## [1.1.8] — 2026-04-14

### Fixed
- Hardened inbound and rendered email HTML to strip `<style>`, `<link>`, `<meta>`, and `<base>` tags so embedded email CSS can no longer leak into the app chrome
- Existing stored HTML replies are now sanitised again at render time in both the agent ticket view and the customer portal thread

---

## [1.1.7] — 2026-04-14

### Added
- Customer list (`/#/customers`) now supports sortable columns for every visible field, with sorting preserved across pagination

---

## [1.1.6] — 2026-04-14

### Fixed
- Incorrect login credentials for both agents and portal customers now return `Wrong Username and/or Password` instead of the generic session-expired message
- Reply, note, and new-ticket forms now support selecting attachments in multiple passes and removing queued files before submit

---

## [1.1.5] — 2026-04-08

### Fixed
- Added migration `016_due_dates_repair.sql` to ensure `due_at`, `due_end`, `due_all_day` columns are present on installs where `015_due_dates.sql` was falsely recorded as applied by the pre-1.1.3 updater
- Corrected PDO error-code check in migration runner (`errorInfo[1]` instead of `getCode()` which returns a SQLSTATE string, not the MySQL numeric code)
- Due date fields (`due_at`, `due_end`, `due_all_day`) now accepted on `POST /api/tickets` (new ticket form)

---

## [1.1.4] — 2026-04-08

### Fixed
- In-app updater now correctly applies SQL migrations that start with a `--` comment line (the comment-filter was incorrectly skipping the entire statement, so `015_due_dates.sql` was silently not applied on installs updated via the in-app updater)
- Updater now treats MySQL "duplicate column/key" errors as a soft success, so manually-applied migrations don't block future update runs

---

## [1.1.3] — 2026-04-08

### Fixed
- In-app updater fix (same as 1.1.4 — intermediate release)

---

## [1.1.2] — 2026-04-08

### Fixed
- DB migration `015_due_dates.sql` not applied automatically on existing installs — columns must be added via `php bin/migrate.php` or by running the migration manually

---

## [1.1.0] — 2026-04-08

### Added
- **Due dates** on tickets — set a start date/time, optional end date (for multi-day), or all-day flag; stored in `due_at`, `due_end`, `due_all_day` columns
- **Calendar view** (`#/calendar`) — browsable monthly grid showing all tickets with due dates, colour-coded by priority; overdue tickets highlighted in red; click any event to open the ticket
- **iCal subscription** — each agent gets a personal HMAC-secured subscription URL; calendar apps (Outlook, Google Calendar, Apple Calendar) receive automatic reminders 1 day and 1 hour before each due date; ticket URL embedded in each calendar entry
- Due date sidebar card on ticket detail — inline form with all-day toggle, start/end date pickers, edit and clear actions; overdue indicator shown in red
- `GET /api/calendar/events` — tickets with due dates for the in-app calendar
- `GET /api/calendar/token` — generate personal iCal subscription token
- `GET /api/calendar/ical` — iCal feed authenticated by HMAC token (no JWT, for calendar app compatibility)
- DB migration `015_due_dates.sql`
- Open all ticket body links in a new tab (`target="_blank"`) — applied via JS at display time and enforced in `Sanitizer::html()`
- `bin/fix-link-targets.php` — one-time script to retroactively add `target="_blank"` to all existing reply HTML in the database

---

## [1.0.2] — 2026-04-03

### Added

- **In-app updater** — Settings → General now includes a one-click updater; when a newer version is detected, an **Update Now** button opens a preflight checklist modal that verifies PHP ZipArchive extension, HTTP download capability, write permissions on all key directories, temp directory, and available disk space; each failed check shows specific fix instructions; if all checks pass, the updater downloads the latest zip from GitHub, extracts it, copies new files over the installation (preserving `.env`, `storage/`, and `vendor/`), runs the database schema update, applies any new migrations, and clears the opcode cache
- **Migration tracking** — a `schema_migrations` database table (auto-created on first update run) tracks which numbered migration files have been applied, preventing double-application on repeated updates
- **Concurrent update protection** — `flock()` on a temp lock file ensures only one update process can run at a time

### Docs

- Updated `docs/version.md` with in-app updater workflow and migration tracking details
- Updated `docs/api-spec.md` with full `GET /api/update/preflight` and `POST /api/update/run` endpoint documentation
- Updated `README.md` and `docs/screenshots.md` with updater description

---

## [1.0.1] — 2026-04-03

### Added

- **Versioning system** — `version.json` in the repository root is the authoritative version record; `GET /api/version` returns the installed version; `GET /api/version/latest` proxies a fetch to the GitHub `main` branch server-side so browsers avoid cross-origin restrictions
- **Update check in Settings** — Settings → General tab now shows a **Version & Updates** card with the installed version number and a **Check for Updates** button; compares installed vs latest semver and reports inline whether an update is available

### Docs

- Added `docs/version.md` explaining the versioning workflow
- Added `docs/changelog.md` (this file)
- Updated README, CLAUDE.md, api-spec.md, and screenshots.md with versioning and tag dropdown details

---

## [1.0.0] — 2026-04-03

Initial versioned release. Covers all features built to this point.

### Features

- **@mention agents** — type `@` in the reply/note composer to search and insert agent mentions; mentioned agents receive an email notification
- **Bulk CSV customer import** — upload a CSV (name, email, phone, company) to create customers in bulk; duplicate emails are skipped and reported; 2 MB file size limit; CSV template download included
- **Tag dropdown on ticket detail** — tags sidebar now shows a dropdown of existing tags rather than a free-text input; only tags not already applied are shown; hides when all tags are applied
- **Customer name links** — customer names in ticket reply headers link directly to the customer profile page
- **Collapsible reply composer** — reply/note editor is collapsed by default and expands on click
- **Scroll-to-top button** — fixed bottom-right button appears after 300 px of scroll
- **Mobile navbar auto-collapse** — navbar collapses automatically after navigating on small screens
- **HTML email containment** — inbound HTML email content is constrained to prevent horizontal overflow
- **Versioning & update check** — `version.json` in the repo root; Settings → General shows installed version and a Check for Updates button that compares against the GitHub `main` branch

### Security

- Dual-layer XSS sanitisation: DOMPurify client-side, `Sanitizer::html()` (DOMDocument allowlist) server-side
- All SQL via prepared statements throughout
- Mention chip class attribute validated server-side with strict regex before processing
- CSV import validates email format and checks for duplicates including soft-deleted records
