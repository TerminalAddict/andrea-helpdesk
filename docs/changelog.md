# Changelog

All notable changes to Andrea Helpdesk are documented here.

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
