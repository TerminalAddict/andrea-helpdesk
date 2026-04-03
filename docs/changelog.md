# Changelog

All notable changes to Andrea Helpdesk are documented here.

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
