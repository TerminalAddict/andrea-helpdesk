# Changelog

All notable changes to Andrea Helpdesk are documented here.

---

## [Unreleased]

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
