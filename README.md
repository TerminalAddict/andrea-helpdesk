<img src="public_html/Andrea-Helpdesk.png" alt="Andrea Helpdesk" width="48" height="48">

# Andrea Helpdesk

A self-hosted, full-featured customer support helpdesk built with PHP 8.1, MySQL, and a Bootstrap 5 single-page application. No SaaS subscriptions. No per-agent fees. Your data stays on your server.

---

## Developer Documentation

| Document | Why it matters |
|---|---|
| [docs/api-spec.md](docs/api-spec.md) | Full REST API reference — every endpoint, request/response shape, required headers, auth middleware, and error codes. Essential if you're building an integration, a mobile client, or working in the backend without reading the PHP source. |
| [docs/db-schema.md](docs/db-schema.md) | Complete database schema — all tables, columns, indexes, foreign keys, and the default settings reference. Essential for understanding the data model, writing migrations, or debugging unexpected query behaviour. |
| [docs/screenshots.md](docs/screenshots.md) | Annotated screenshots of every screen in the agent UI — useful for evaluating the product or understanding what each feature looks like before diving into the code. |

---

## Features

### Ticket Management
- **Multi-channel intake** — tickets created via email (IMAP polling), agent UI, or the customer portal
- **Ticket threading** — replies are threaded using `Message-ID`, `In-Reply-To`, and `References` headers so email conversations stay together
- **Parent / child tickets** — link related tickets in a hierarchy, displayed inline in the ticket list
- **Priorities** — Urgent, High, Normal, Low with colour-coded badges
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
- **Email quote stripping** — only the new content is stored; quoted reply history (Gmail, Outlook, Apple Mail, Yahoo) is automatically trimmed
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

### Customer Portal
- **Magic link login** — customers receive a one-click login link via email; no password required
- **Password login** — customers can optionally set a portal password
- **New ticket submission** — customers can open new support tickets directly from the portal with a rich text editor
- **Ticket view** — customers see only their own tickets and can post rich text replies
- **Participant access** — CC'd participants can also view and reply to tickets they're involved in
- **Email replies** — customers can reply directly to notification emails; replies are threaded back into the ticket

### Agent Features
- **Role-based access** — `admin` and `agent` roles; admins bypass all permission checks
- **Granular permissions** — per-agent toggles for: close tickets, delete tickets, edit customers, view reports, manage knowledge base, manage tags
- **Agent assignment** — assign tickets to specific agents; filter by assigned agent
- **Rich text composer** — Quill 2.x editor (self-hosted, no CDN) in every body input: new tickets, replies, internal notes, edit ticket body, global signature, personal signature, auto-response body, and knowledge base articles; agents get a full toolbar (bold, italic, underline, lists, link, blockquote, clean), portal customers get a simplified toolbar
- **Signatures** — per-agent HTML email signature edited with the rich text editor; agents can toggle signature inclusion per reply via a checkbox in the reply composer
- **Dark / light theme** — each agent selects their own UI theme; preference is persisted in the database
- **Pagination preference** — configurable per-agent page size for ticket lists

### Knowledge Base
- **Articles and categories** — create a searchable internal/public knowledge base
- **Draft / published states** — articles can be saved as drafts before publishing
- **Rich text editor** — articles are authored with the Quill rich text editor; content is rendered safely via DOMPurify on the frontend and sanitised server-side via `Sanitizer::html()` before storage

### Notifications
- **Email notifications** — agents are notified of new tickets; customers and participants are notified of replies
- **Slack notifications** — optional webhook integration for new ticket alerts, assignments, and customer replies; configurable bot display name, icon image or emoji, and link preview behaviour
- **Global email signature** — appended to all outbound agent emails

### Reporting
- **Dashboard** — live stats: New, Waiting for Reply, Pending, and Replied ticket counts; navbar badge shows all active (non-resolved, non-closed) tickets; recent activity by agent
- **Reports** — ticket volume over time, resolution times, agent workload breakdowns

### Settings (Admin UI)
- **SMTP configuration** — host, port, encryption, credentials, from address — all managed in the UI
- **IMAP accounts** — add, edit, and test multiple inbound mailboxes; credentials encrypted at rest with AES-256-CBC
- **Company branding** — company name, logo URL, support email
- **Ticket prefix** — customise the ticket number prefix
- **Auto-responder** — enable/disable and customise the automatic acknowledgement email
- **Date format** — configurable display format
- **Slack appearance** — configurable bot display name, icon (image URL or emoji), and link preview toggle per Slack integration

### Security
- **JWT authentication** — short-lived access tokens (15 min) + long-lived refresh tokens (30 days, hashed in DB)
- **Refresh token rotation** — every refresh issues a new token and revokes the old one
- **XSS protection** — dual-layer sanitisation: client-side via [DOMPurify](https://github.com/cure53/DOMPurify) before submission; server-side via `Sanitizer::html()` (DOMDocument, allowlist of safe tags/attributes, `javascript:` href blocking) before storage. Plain text fields are `htmlspecialchars()`-escaped throughout.
- **SQL injection prevention** — all queries use PDO prepared statements with parameterised placeholders
- **bcrypt passwords** — agent passwords hashed with `password_hash()` at cost 12
- **Encrypted IMAP credentials** — mailbox passwords stored AES-256-CBC encrypted, never in plaintext
- **Signed attachment tokens** — HMAC-SHA256 download tokens with 24-hour expiry

### Operations
- **Log rotation** — `imap.log` and `app.log` automatically trimmed to 3 days retention on every poll run
- **Cron overlap prevention** — `flock()` ensures only one IMAP poller runs at a time
- **Rsync deployment** — single `make deploy-production` command; vendor and storage directories excluded
- **No build step** — frontend uses Bootstrap 5, Bootstrap Icons, and jQuery loaded from local vendor files; no Node.js or bundler required

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
- Cron access for IMAP polling

---

## Installation

### Option A — Web Installer (FTP / shared hosting)

No SSH or command line required.

1. **Upload files** — FTP the entire repository to your web root (e.g. `public_html/` or `httpdocs/`). The `public_html/` subdirectory should become your document root.
2. **Upload Composer dependencies** — run `composer install --no-dev --optimize-autoloader` locally, then FTP the generated `vendor/` directory to the server alongside `public_html/`.
3. **Create a storage directory** — create a folder *outside* the web root (e.g. `../helpdesk-storage/`) and ensure it is writable by the web server. The installer will create the required subdirectories.
4. **Run the installer** — visit `https://yourdomain.com/install/` in your browser. The wizard will:
   - Check PHP version and required extensions
   - Test your database connection
   - Write `.env`, create the database schema, seed the admin account, and download frontend assets
5. **Log in** at `https://yourdomain.com/` with the admin credentials you set during installation.

> **Note:** IMAP email polling requires a cron job (`php /path/to/bin/imap-poll.php`). Many shared hosts provide a cron manager in their control panel. Without cron, new emails won't be imported automatically — agents can still create tickets and reply via the UI.

> **Security:** Delete or password-protect the `public_html/install/` directory after installation. The installer writes an `install.lock` file to prevent re-running, but removing the directory is best practice.

---

### Option B — Command Line (SSH / VPS)

```bash
# 1. Clone and install dependencies
composer install --no-dev --optimize-autoloader

# 2. Configure environment
cp .env.example .env
# Edit .env with your DB, JWT secret, storage path, and app URL

# 3. Configure Makefile deployment targets
cp Makefile.local.example Makefile.local
# Edit Makefile.local — set LOCAL_HOST, PROD_HOST, REMOTE_USER, REMOTE_PATH
# Makefile.local is gitignored and never committed

# 4. Run migrations and seed the admin account
make db-migrate
make db-seed

# 5. Download frontend vendor assets
make fetch-assets

# 6. Install the IMAP polling cron
make cron-install-production
```

Then log in at your configured `APP_URL` with the `ADMIN_EMAIL` / `ADMIN_PASSWORD` from `.env`.

SMTP, IMAP accounts, branding, and all other runtime settings are configured through the admin Settings UI — no config file editing required after initial setup.

---

## Deployment (developer workflow)

Before deploying, copy `Makefile.local.example` to `Makefile.local` and fill in your server details:

```bash
cp Makefile.local.example Makefile.local
# Edit Makefile.local — set LOCAL_HOST, PROD_HOST, REMOTE_USER, REMOTE_PATH
```

`Makefile.local` is gitignored and never committed.

```bash
make deploy-production   # rsync to production server
make deploy-local        # rsync to local dev server
```

Sensitive files (`.env`, `storage/`, `vendor/`) are excluded from rsync. The storage directory (attachments and logs) must live **outside** the web root — set `STORAGE_PATH` in `.env` accordingly.

---

## License

GPL-3.0
