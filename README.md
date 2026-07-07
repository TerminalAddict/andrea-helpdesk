<img src="public_html/Andrea-Helpdesk.png" alt="Andrea Helpdesk" width="48" height="48">

# Andrea Helpdesk

A self-hosted, full-featured customer support helpdesk built with PHP 8.1, MySQL, and a Bootstrap 5 single-page application. No SaaS subscriptions. No per-agent fees. Your data stays on your server.

---

## Developer Documentation

| Document | Why it matters |
|---|---|
| [docs/INSTALL.md](docs/INSTALL.md) | End-to-end installation guide for VPS/SSH installs, FTP/shared-hosting installs, and the `/install/` browser wizard, including install mock screenshots and post-install checks. |
| [docs/api-spec.md](docs/api-spec.md) | Full REST API reference — every endpoint, request/response shape, required headers, auth middleware, and error codes. Essential if you're building an integration, a mobile client, or working in the backend without reading the PHP source. |
| [docs/db-schema.md](docs/db-schema.md) | Complete database schema — all tables, columns, indexes, foreign keys, and the default settings reference. Essential for understanding the data model, writing migrations, or debugging unexpected query behaviour. |
| [docs/PWA.md](docs/PWA.md) | PWA install guidance, Web Push setup, service-worker behaviour, diagnostics, and security notes. |
| [docs/chat-websocket-systemd.md](docs/chat-websocket-systemd.md) | Production systemd service and reverse-proxy guidance for the internal chat WebSocket daemon. |
| [docs/screenshots.md](docs/screenshots.md) | Annotated screenshots of every screen in the agent UI — useful for evaluating the product or understanding what each feature looks like before diving into the code. |
## Features

### Ticket Management
- **Multi-channel intake** — tickets created via email (IMAP polling), agent UI, or the customer portal
- **Ticket threading** — replies are threaded using `Message-ID`, `In-Reply-To`, and `References` headers so email conversations stay together
- **Parent / child tickets** — link related tickets in a hierarchy, displayed inline in the ticket list
- **Priorities** — Overdue, Urgent, High, Normal, Low with colour-coded badges
- **Configurable SLA escalation** — in Settings → General, tickets with no attention for a configurable number of days can be raised to **High**, then to **Overdue** after an additional configurable delay; reminder emails can go to all agents or a selected subset
- **Statuses** — New, Open, Waiting for Reply, Replied, Pending, Resolved, Closed with automatic transitions (customer reply → Waiting for Reply; agent reply → Replied; reopens closed/resolved tickets on customer reply)
- **Tags** — assign multiple tags per ticket; filter the ticket list by tag
- **Participants (CC)** — add customers as CC participants; they receive reply notifications and can respond via email or portal
- **Private notes** — internal replies visible only to agents, never to customers
- **Ticket prefix** — configurable ticket number prefix (e.g. `HD-2026-03-17-485`)
- **Atomic ticket numbering** — sequence generated with `INSERT … ON DUPLICATE KEY UPDATE` to prevent duplicates under load; each day's sequence starts at a random number between 128 and 512
- **Per-ticket email suppression** — toggle in the Ticket Info sidebar silently stops all outbound customer emails for a single ticket without affecting Slack or agent notifications; each toggle is recorded as a system event in the thread

### Email Integration
- **IMAP polling** — polls one or more mailboxes every minute via cron; handles plain text and HTML emails
- **Multi-account IMAP** — each account can have its own tag, from-address, and reply-to
- **Email quote stripping** — only the new content is stored; quoted reply history (Gmail, Outlook, Apple Mail, Yahoo) is automatically trimmed. Forwarded emails (`Fwd:`/`FW:`/`Forward:` subject prefix) are exempt — their full content is preserved since the forwarded message IS the primary content
- **Inline image handling** — CID inline images are replaced with a clear paperclip indicator so attachments are findable
- **Auto-responder** — configurable automatic acknowledgement sent to customers on new tickets
- **Outbound threading** — replies set `In-Reply-To`, `References`, and `X-Ticket-ID` headers so email clients thread correctly
- **RFC 5322 compliant** — single `Message-ID` header on all outbound mail (Gmail / strict server compatible)
- **Per-tag from-address** — replies can be sent from the address associated with a ticket's tag (e.g. `support@yourdomain.com` vs `billing@yourdomain.com`)
- **Loop prevention** — auto-reply and bulk/junk precedence headers are detected and skipped
- **Per-customer email suppression** — flag on the customer record silences all outbound emails to that customer across every ticket; combines with the per-ticket flag (either is sufficient to suppress)

### Attachments
- **Upload in replies** — agents and customers can attach files to any reply
- **IMAP attachments** — files attached to inbound emails are automatically saved and linked to the ticket
- **Secure download** — all files are stored outside the web root and served via signed HMAC download tokens or JWT; direct filesystem access is impossible
- **Path traversal protection** — `realpath()` validation ensures served files stay within the storage directory
- **MIME type detection** — server-side detection via `mime_content_type()`; client-supplied MIME type is never trusted
- **Safe inline rendering** — only images, PDFs, video, and audio are served inline; HTML and SVG attachments are forced to download to prevent XSS
- **Attachment type allowlist** — uploaded and inbound attachment MIME types are validated server-side before being stored; active-content types such as HTML, SVG, and generic `application/octet-stream` uploads are rejected

### Customer Portal
- **Magic link login** — customers receive a one-click login link via email; no password required
- **Password login** — customers can optionally set a portal password
- **New ticket submission** — customers can open new support tickets directly from the portal with a rich text editor
- **Public website support form** — `#/login/support-form` provides a public support-request form with attachments; submissions create normal inbound tickets with channel `Web`, and a dedicated `/support-form/embed` page can be embedded in another website
- **Ticket view** — customers see only their own tickets and can post rich text replies
- **Participant access** — CC'd participants can also view and reply to tickets they're involved in
- **Email replies** — customers can reply directly to notification emails; replies are threaded back into the ticket

### Agent Features
- **Role-based access** — `admin` and `agent` roles; admins bypass all permission checks
- **Granular permissions** — per-agent toggles for: close tickets, delete tickets, edit customers, view reports, manage knowledge base, manage tags
- **Internal chat** — agents can use `#/chat` for channel chat and direct messages, with emoji/plain-text messages, common emoticon conversion, safe link rendering, `@chat_handle` mentions, browser/PWA notifications, read cursors, and HTTP recovery when WebSockets reconnect
- **Inactive-agent visibility** — the admin **Agents** screen shows both active and inactive agents so historical ownership remains visible and former agents can be reactivated when needed
- **Agent assignment** — assign tickets to specific agents; filter by assigned agent
- **Rich text composer** — Quill 2.x editor (self-hosted, no CDN) in every body input: new tickets, replies, internal notes, edit ticket body, global signature, personal signature, auto-response body, and knowledge base articles; agents get a full toolbar (bold, italic, underline, lists, link, blockquote, clean), portal customers get a simplified toolbar
- **@mention agents** — type `@` in the reply or internal note composer to get a live filtered dropdown of agents; selecting one inserts a styled mention chip; mentioned agents receive an email notification with a link to the ticket; self-mentions are silently ignored
- **Signatures** — per-agent HTML email signature edited with the rich text editor; agents can toggle signature inclusion per reply via a checkbox in the reply composer
- **Create customer inline** — new customers can be created directly from the Customers screen (New Customer button) or from within the Edit Ticket modal when reassigning a ticket to a customer who doesn't yet exist in the system
- **Bulk CSV import** — import customers in bulk from a CSV file (columns: `name`, `email`, `phone` optional, `company` optional); a downloadable template is provided in the UI; rows with duplicate or soft-deleted emails are skipped with a per-row reason report; 2 MB file size limit; requires `can_edit_customers` permission
- **Remember me** — agent login offers a "Remember me" checkbox; when checked, tokens are stored in `localStorage` and persist across browser sessions; when unchecked, tokens go to `sessionStorage` and are cleared when the tab closes
- **Dark / light theme** — each agent selects their own UI theme; preference is persisted in the database
- **Pagination preference** — configurable per-agent page size for ticket lists

### Knowledge Base
- **Articles and categories** — create a searchable internal/public knowledge base
- **Draft / published states** — articles can be saved as drafts before publishing
- **Rich text editor** — articles are authored with the Quill rich text editor; content is rendered safely via DOMPurify on the frontend and sanitised server-side via `Sanitizer::html()` before storage

### Notifications
- **Email notifications** — agents are notified of new tickets, customer replies, ticket assignments, and @mentions; customers and participants are notified of replies
- **Inbound email blocking** — admins can block exact senders or whole domains from **Settings → Email / SMTP**; blocked senders receive only the configured block response, the ticket is immediately closed, and no agent, Slack, in-app, or push notification is created
- **In-app notification center** — agents get a bell icon in the navbar for current actionable alerts, plus an Alerts Panel at `#/my-profile/notifications`
- **Per-agent notification preferences** — agents can choose which in-app/browser notifications they receive at `#/my-profile/settings/notifications`; update alerts are admin-only and enabled by default for admins
- **Ticket notification lifecycle** — new-ticket, assignment, customer-reply, and internal-note notifications are removed for an agent when that agent opens the ticket; SLA/due-date overdue notifications remain until the overdue condition is cleared
- **Silent admin update checks** — once per day, each admin session silently checks for a newer release in the background and raises an in-app alert that links straight to **Settings → General → Version & Updates**; overlapping checks for the same admin are serialised server-side
- **PWA / browser push notifications** — Andrea Helpdesk can be installed as an online-first PWA, uses the configured Application Name and PWA/Favicon icon for install prompts and push payloads, and lets agents opt in from `#/my-profile/settings/notifications` after an admin configures VAPID keys in `#/admin/settings/notifications`
- **Slack notifications** — optional webhook integration for new ticket alerts, assignments, and customer replies; configurable bot display name, icon image or emoji, and link preview behaviour
- **Global email signature** — appended to all outbound agent emails

### UI / UX
- **Collapsible reply editor** — the reply/note composer on ticket detail is collapsed by default; clicking **Reply** or **Internal Note** auto-expands it; a chevron toggle button collapses it again
- **Customer name links** — customer names in ticket thread reply headers link directly to the customer profile page
- **Scroll-to-top button** — fixed button appears bottom-right after scrolling 300 px; works on all screens, desktop and mobile
- **Mobile navbar auto-collapse** — the hamburger nav menu closes automatically after tapping any navigation link on mobile
- **HTML email containment** — fixed-width HTML emails (tables, images) are contained within the viewport to prevent horizontal scrolling on mobile

### Reporting
- **Dashboard** — live stats: New, Waiting for Reply, Pending, Replied, and Overdue ticket counts; dedicated overdue ticket list; navbar badge shows all active (non-resolved, non-closed) tickets; recent activity by agent
- **Reports** — month-to-date by default; summary cards match the dashboard labels but are scoped to tickets with activity in range; daily volume breaks out created tickets, customer replies, agent replies, internal notes, and system events; agent activity shows assigned, created, replied, noted, resolved, and closed counts; average time to close is based on tickets closed in range

### Settings And Profile Routes
- **SMTP configuration** — host, port, encryption, credentials, from address — all managed in the UI
- **IMAP accounts** — add, edit, test, and browse folders on multiple inbound mailboxes; username accepts both email (`user@domain.com`) and Windows domain (`DOMAIN\user`) formats; leading/trailing whitespace in host and username is stripped on save; credentials encrypted at rest with AES-256-CBC
- **Inbound email blocklist** — exact email addresses, `*@domain.com`, or bare `domain.com` entries can be configured by admins from **Settings → Email / SMTP**, along with the plain-text response sent to blocked senders
- **Company branding** — company name, logo URL, favicon URL, primary colour, and support email
- **Ticket prefix** — customise the ticket number prefix
- **Auto-responder** — enable/disable and customise the automatic acknowledgement email
- **Date format** — configurable display format
- **SLA policy** — enable/disable escalation, set the inactivity thresholds for **High** and **Overdue**, and choose whether reminder emails go to all active agents or only specific agents
- **Slack appearance** — configurable bot display name, icon (image URL or emoji), and link preview toggle per Slack integration
- **Support form** — configure public support-form reCAPTCHA v3 site and secret keys, maintain an allowlist of permitted embed origins, copy the direct URL or iframe embed snippet, and preview the embeddable support form from **Settings → Support Form**
- **Support form embedding** — `/support-form/embed` sends a `Content-Security-Policy: frame-ancestors ...` header generated from the saved allowlist of origins, while the rest of the app continues to send `X-Frame-Options: SAMEORIGIN`
- **Notification push settings** — configure or generate VAPID keys from **Settings → Notifications** so browser push subscriptions can be created securely; optionally set a dedicated PWA / notification icon, otherwise the PWA manifest and push notifications use the Branding favicon
- **Version & update channels** — the General tab shows the currently installed version, lets admins choose the `stable` or `development` update channel, and offers a **Check for Updates** button. Stable checks read public GitHub `main` metadata by default; development checks read the `development` branch. Development builds use prerelease versions such as `1.4.17-dev.1`, and installs are never downgraded when switching back to stable.
- **Bridge release enforcement** — release metadata can require a minimum installed bridge version before the in-app updater will continue; installs older than `1.4.9` must update to `1.4.9` before moving to newer releases because that release upgrades packaged `vendor/` dependency handling.
- **Application cache** — optional Redis-backed read-through cache from `#/admin/settings/cache`; diagnostics check php-redis, local Redis connectivity, cache directory writability, and show install instructions. Read caches are invalidated automatically when Andrea Helpdesk writes data.
- **Notification preferences** — `#/my-profile/settings/notifications` includes browser push subscription controls and app install guidance so each agent can enable or disable browser / OS alerts independently
- **Chat service settings** — `#/admin/settings/chatservice` enables/disables internal chat, manages channels and membership, sets retention, and controls WebSocket daemon mode/status
- **Admin password recovery** — `make reset-admin-password` lists admin accounts only, lets you choose one, resets the password interactively, and revokes that admin’s existing refresh-token sessions

Route structure:
- `/admin/settings/general`
- `/admin/settings/branding`
- `/admin/settings/email`
- `/admin/settings/autoresponse`
- `/admin/settings/imap`
- `/admin/settings/slack`
- `/admin/settings/cache`
- `/admin/settings/notifications`
- `/admin/settings/chatservice`
- `/admin/settings/support-form`
- `/admin/tags`
- `/my-profile`
- `/my-profile/notifications`
- `/my-profile/settings/notifications`

### Security
- **JWT authentication** — short-lived access tokens (15 min) + long-lived refresh tokens (30 days, hashed in DB)
- **Refresh token rotation** — every refresh issues a new token and revokes the old one
- **XSS protection** — dual-layer sanitisation: client-side via [DOMPurify](https://github.com/cure53/DOMPurify) before submission; server-side via `Sanitizer::html()` (DOMDocument, allowlist of safe tags/attributes, and strict `http/https/mailto/tel` or relative-link enforcement) before storage. Replies, knowledge base articles, agent signatures, and HTML email settings are sanitised on write. Plain text fields are `htmlspecialchars()`-escaped throughout.
- **Chat transport hardening** — chat WebSocket connections require active-agent JWT authentication, same-origin browser handshakes, and per-event membership checks before channel/direct typing, read, or message broadcasts.
- **Support-form abuse controls** — the public website support form supports reCAPTCHA v3 when configured, falls back to a signed human-verification challenge when not configured, and enforces server-side submission throttles per IP address and per email address
- **SQL injection prevention** — all queries use PDO prepared statements with parameterised placeholders
- **Config hardening** — DB charset/collation values are validated before interpolation, and proxy IP headers are ignored unless `TRUST_PROXY_HEADERS=true`
- **bcrypt passwords** — agent passwords hashed with `password_hash()` at cost 12
- **Encrypted IMAP credentials** — mailbox passwords stored AES-256-CBC encrypted, never in plaintext
- **Signed attachment tokens** — HMAC-SHA256 download tokens with 24-hour expiry

### Operations
- **Background cron runner** — `bin/cron.php` runs IMAP/SLA polling, chat WebSocket supervision, and daily chat retention pruning from one cron entry
- **Log rotation** — `imap.log` and `app.log` automatically trimmed to 3 days retention during background runs
- **Cron overlap prevention** — locks prevent overlapping IMAP poller and chat supervisor runs
- **Rsync deployment** — single `make deploy` command; vendor and storage directories excluded; remote `composer install` and `php bin/migrate.php` run automatically
- **Safe in-app updates** — the updater lock remains held through file copy, schema update, and migrations so concurrent update runs cannot overlap mid-upgrade
- **No build step** — frontend uses Bootstrap 5, Bootstrap Icons, and jQuery loaded from local vendor files; no Node.js or bundler required
- **Versioning** — `version.json` in the repository root is the authoritative version record; see [docs/version.md](docs/version.md) and [docs/changelog.md](docs/changelog.md)

---

## Tech Stack

| Layer | Technology |
|---|---|
| Language | PHP 8.1 |
| Database | MySQL 8 |
| Frontend | Bootstrap 5.3, Bootstrap Icons, jQuery 4, Quill 2.0 (rich text), vanilla JS SPA |
| Auth | Firebase JWT (HS256) |
| Email | PHPMailer, PHP `imap_*` extension |
| HTML sanitisation | DOMPurify 3.2 (client), `Sanitizer::html()` via PHP DOMDocument (server) |
| Dependency management | Composer |

---

## Requirements

- PHP 8.1+ with extensions: `pdo_mysql`, `imap`, `mbstring`, `openssl`
- MySQL 8.0+
- Apache with `mod_rewrite` (or Nginx equivalent)
- Cron access for the Andrea background runner (`bin/cron.php`)

---

## Installation
See [docs/INSTALL.md](docs/INSTALL.md) for the full installation guide, including:

- command-line install on a VPS or dedicated server
- FTP / shared-hosting upload flow
- the `/install/` browser wizard
- install mock screenshots
- shared-hosting notes and post-install checklist

---

## Deployment (developer workflow)

Before deploying, copy `Makefile.local.example` to `Makefile.local` and fill in your server details:

```bash
cp Makefile.local.example Makefile.local
# Edit Makefile.local — set LOCAL_HOST, PROD_HOST, REMOTE_USER, REMOTE_PATH
```

`Makefile.local` is gitignored and never committed.

```bash
make deploy   # rsync to production, install composer deps, run DB migrations
```

Sensitive files (`.env`, `storage/`, `vendor/`) are excluded from rsync. The storage directory (attachments and logs) must live **outside** the web root — set `STORAGE_PATH` in `.env` accordingly.

---

## License

GPL-3.0
