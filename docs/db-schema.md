# Database Schema Reference

Andrea Helpdesk uses MySQL (InnoDB, utf8mb4_unicode_ci throughout). All tables use `CREATE TABLE IF NOT EXISTS` so the schema can be re-applied safely. The authoritative source is `database/schema.sql`; run `make db-migrate` to apply it.

---

## Table of Contents

1. [agents](#1-agents)
2. [customers](#2-customers)
3. [ticket_number_sequences](#3-ticket_number_sequences)
4. [tickets](#4-tickets)
5. [ticket_participants](#5-ticket_participants)
6. [replies](#6-replies)
7. [attachments](#7-attachments)
8. [tags](#8-tags)
9. [ticket_tag_map](#9-ticket_tag_map)
10. [ticket_relations](#10-ticket_relations)
11. [imap_accounts](#11-imap_accounts)
12. [settings](#12-settings)
13. [knowledge_base_categories](#13-knowledge_base_categories)
14. [knowledge_base_articles](#14-knowledge_base_articles)
15. [refresh_tokens](#15-refresh_tokens)
16. [audit_log](#16-audit_log)
17. [Default Settings Reference](#17-default-settings-reference)
18. [Entity Relationship Summary](#18-entity-relationship-summary)
19. [Indexes and Performance Notes](#19-indexes-and-performance-notes)

---

## 1. agents

Stores staff members who log in to the helpdesk to manage tickets.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `name` | VARCHAR(120) | NO | | Display name |
| `email` | VARCHAR(255) | NO | | Login email (unique) |
| `password_hash` | VARCHAR(255) | NO | | bcrypt hash of password |
| `role` | ENUM('admin','agent') | NO | 'agent' | `admin` bypasses all permission checks; `agent` is subject to per-row permission flags |
| `can_close_tickets` | TINYINT(1) | NO | 1 | Permission to close/resolve tickets |
| `can_delete_tickets` | TINYINT(1) | NO | 0 | Permission to soft-delete tickets |
| `can_edit_customers` | TINYINT(1) | NO | 0 | Permission to update customer records |
| `can_view_reports` | TINYINT(1) | NO | 0 | Permission to access the Reports section |
| `can_manage_kb` | TINYINT(1) | NO | 0 | Permission to create/edit/delete knowledge base articles |
| `can_manage_tags` | TINYINT(1) | NO | 0 | Permission to create/delete tags |
| `signature` | TEXT | YES | NULL | Per-agent HTML email signature (appended to outbound replies, overrides global signature) |
| `page_size` | TINYINT UNSIGNED | NO | 20 | Preferred rows per page for ticket lists and dashboard blocks (10 / 20 / 50) |
| `theme` | VARCHAR(20) | NO | 'light' | UI theme preference: `light` or `dark` |
| `is_active` | TINYINT(1) | NO | 1 | Soft-disable without deleting; inactive agents cannot log in |
| `last_login_at` | DATETIME | YES | NULL | Updated on successful login |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | |

**Unique keys:** `uq_agents_email` on `email`

**Indexes:** `idx_agents_active` on `is_active` (used when listing assignable agents)

**Notes:**
- Passwords are hashed with `password_hash()` (bcrypt, PHP default cost). The column is wide enough for argon2 if upgraded.
- `admin` role agents are not subject to any of the `can_*` permission checks anywhere in the middleware or service layer.
- `signature` may contain arbitrary HTML. It is stored as-is and rendered in the compose UI; agents are trusted to supply valid HTML.

---

## 2. customers

People who submit support requests. Customers do not have accounts in the traditional sense — they access their tickets via the customer portal using either a password or a magic-link token.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `name` | VARCHAR(120) | NO | | Display name |
| `email` | VARCHAR(255) | NO | | Primary email (unique, case-insensitive lookup) |
| `phone` | VARCHAR(40) | YES | NULL | Optional phone number |
| `company` | VARCHAR(120) | YES | NULL | Optional company/organisation name |
| `notes` | TEXT | YES | NULL | Internal agent-only notes (not visible to customer) |
| `portal_password_hash` | VARCHAR(255) | YES | NULL | bcrypt hash of customer portal password. NULL means password login is not set up for this customer |
| `portal_token` | VARCHAR(64) | YES | NULL | SHA-256 hex hash of the one-time magic-link token. Raw token is sent in email; only the hash is stored |
| `portal_token_expires` | DATETIME | YES | NULL | Expiry timestamp for the magic-link token (typically 1 hour after generation) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | DATETIME | YES | NULL | Soft-delete timestamp; NULL means active |

**Unique keys:** `uq_customers_email` on `email`

**Indexes:** `idx_customers_deleted` on `deleted_at`

**Notes:**
- Soft-deleted customers (`deleted_at IS NOT NULL`) are excluded from all normal queries but their tickets and replies remain intact.
- The magic-link flow: `AuthController` generates `bin2hex(random_bytes(32))`, stores `hash('sha256', $token)` in `portal_token`, and emails the raw token. `PortalAuthController` receives the raw token, hashes it, and queries by hash. This prevents token exposure if the DB is dumped.
- Customers can have both a password and a magic-link token simultaneously.

---

## 3. ticket_number_sequences

Provides a collision-free counter for generating ticket numbers in the format `PREFIX-YYYY-MM-DD-NNNN`.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `date_key` | CHAR(10) | NO | | Date string `YYYY-MM-DD` (primary key) |
| `last_seq` | INT UNSIGNED | NO | 0 | Last sequence number used on this date |

**Primary key:** `date_key`

**Notes:**
- One row per calendar day, created automatically on first ticket of that day.
- `TicketRepository::generateTicketNumber()` uses an atomic `INSERT ... ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1)` pattern. `LAST_INSERT_ID()` is then read in the same connection to get the just-assigned sequence number, avoiding race conditions between concurrent requests.
- Sequence numbers are zero-padded to 4 digits: `HD-2026-03-17-0001`. If daily volume exceeds 9999 tickets the format simply extends to 5+ digits.

---

## 4. tickets

Core entity. Each row is one support ticket.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `ticket_number` | VARCHAR(40) | NO | | Human-readable unique identifier, e.g. `HD-2026-03-17-0001` |
| `subject` | VARCHAR(255) | NO | | Ticket subject line |
| `status` | ENUM('open','pending','resolved','closed') | NO | 'open' | Current status |
| `priority` | ENUM('low','normal','high','urgent') | NO | 'normal' | Priority level |
| `channel` | ENUM('email','web','phone','portal') | NO | 'email' | How the ticket was created |
| `customer_id` | INT UNSIGNED | NO | | FK → customers.id. The primary customer for this ticket |
| `assigned_agent_id` | INT UNSIGNED | YES | NULL | FK → agents.id. NULL means unassigned |
| `original_message_id` | VARCHAR(512) | YES | NULL | `Message-ID` header of the first inbound email (used for email threading) |
| `last_message_id` | VARCHAR(512) | YES | NULL | `Message-ID` of the most recent outbound email (used as `In-Reply-To` on next reply) |
| `reply_to_address` | VARCHAR(255) | YES | NULL | Custom reply-to address for this ticket (overrides global setting) |
| `parent_ticket_id` | INT UNSIGNED | YES | NULL | FK → tickets.id. Set when this ticket is a child/sub-ticket of another |
| `merged_into_id` | INT UNSIGNED | YES | NULL | FK → tickets.id. Set on the losing ticket when two tickets are merged; the winning ticket's ID goes here |
| `first_response_at` | DATETIME | YES | NULL | Timestamp of first agent reply. Used for SLA response-time reporting |
| `closed_at` | DATETIME | YES | NULL | Timestamp when status was last set to `closed` |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | DATETIME | YES | NULL | Soft-delete timestamp |

**Unique keys:** `uq_tickets_number` on `ticket_number`

**Indexes:**

| Index | Columns | Purpose |
|-------|---------|---------|
| `idx_tickets_customer` | `customer_id` | Ticket list filtered by customer |
| `idx_tickets_agent` | `assigned_agent_id` | Ticket list filtered by assignee |
| `idx_tickets_status` | `status` | Ticket list filtered by status |
| `idx_tickets_created` | `created_at` | Chronological ordering |
| `idx_tickets_deleted` | `deleted_at` | Exclude soft-deleted in all queries |
| `idx_tickets_deleted_status` | `(deleted_at, status)` | Main ticket list filter (status + not deleted) |
| `idx_tickets_deleted_agent` | `(deleted_at, assigned_agent_id)` | Agent-filtered ticket list |
| `idx_tickets_original_msg` | `original_message_id(191)` | Email threading lookup by Message-ID |

**Foreign keys:**

| Constraint | Column | References | On Delete |
|------------|--------|-----------|-----------|
| `fk_tickets_customer` | `customer_id` | `customers(id)` | RESTRICT |
| `fk_tickets_agent` | `assigned_agent_id` | `agents(id)` | SET NULL |
| `fk_tickets_parent` | `parent_ticket_id` | `tickets(id)` | SET NULL |
| `fk_tickets_merged` | `merged_into_id` | `tickets(id)` | SET NULL |

**Status lifecycle:**

```
open → pending (waiting for customer response)
     → resolved (agent considers it done, awaiting confirmation)
     → closed (finished)
pending → open (customer replies)
resolved → open (customer replies or agent re-opens)
closed → open (can be re-opened)
```

**Notes:**
- Soft-deleted tickets (`deleted_at IS NOT NULL`) are hidden from all normal queries. The API exposes a `GET /api/tickets?include_deleted=1` option for admins.
- Merged tickets retain all their replies and attachments; `merged_into_id` links the losing ticket to the canonical one.
- `channel = 'email'` is set by the IMAP poller; `'web'` and `'portal'` are set when agents/customers create tickets via the UI.

---

## 5. ticket_participants

CC recipients on a ticket — additional email addresses that receive notifications alongside the primary customer.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `ticket_id` | INT UNSIGNED | NO | | FK → tickets.id |
| `email` | VARCHAR(255) | NO | | Participant email address |
| `name` | VARCHAR(120) | YES | NULL | Display name (may be NULL if extracted from email header only) |
| `role` | ENUM('to','cc','bcc') | NO | 'cc' | Recipient role on outbound emails |
| `customer_id` | INT UNSIGNED | YES | NULL | FK → customers.id. Populated if this email matches an existing customer record |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | |

**Unique keys:** `uq_ticket_participant` on `(ticket_id, email)` — one row per email address per ticket

**Foreign keys:**

| Constraint | Column | References | On Delete |
|------------|--------|-----------|-----------|
| `fk_participants_ticket` | `ticket_id` | `tickets(id)` | CASCADE |
| `fk_participants_customer` | `customer_id` | `customers(id)` | SET NULL |

**Notes:**
- The customer portal auth logic also checks this table: a customer JWT grants access to a ticket if `customer_id` matches either `tickets.customer_id` or a `ticket_participants.customer_id` row.
- Participants are added by agents from the ticket detail view or extracted from `CC:` / `To:` headers when an email is imported.

---

## 6. replies

Every message in a ticket thread — inbound emails, outbound agent replies, internal notes, and system events.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `ticket_id` | INT UNSIGNED | NO | | FK → tickets.id |
| `author_type` | ENUM('agent','customer','system') | NO | | Who wrote this reply |
| `agent_id` | INT UNSIGNED | YES | NULL | FK → agents.id. Set when `author_type = 'agent'` |
| `customer_id` | INT UNSIGNED | YES | NULL | FK → customers.id. Set when `author_type = 'customer'` |
| `body_html` | MEDIUMTEXT | NO | | HTML body of the reply (sanitised before storage) |
| `body_text` | MEDIUMTEXT | YES | NULL | Plain-text version (stored for email threading and fallback) |
| `is_private` | TINYINT(1) | NO | 0 | `1` = internal note visible only to agents, never emailed to customer |
| `direction` | ENUM('inbound','outbound') | NO | | `inbound` = received from customer/email; `outbound` = sent by agent/system |
| `raw_message_id` | VARCHAR(512) | YES | NULL | `Message-ID` header of this email (NULL for web-only replies) |
| `in_reply_to` | VARCHAR(512) | YES | NULL | `In-Reply-To` header of this email |
| `email_sent_at` | DATETIME | YES | NULL | Timestamp when the outbound email was dispatched (NULL until actually sent) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | |

**Indexes:**

| Index | Columns | Purpose |
|-------|---------|---------|
| `idx_replies_ticket` | `ticket_id` | Fetch all replies for a ticket |
| `idx_replies_message_id` | `raw_message_id(191)` | Email threading: match inbound `In-Reply-To` against stored `raw_message_id` |

**Foreign keys:**

| Constraint | Column | References | On Delete |
|------------|--------|-----------|-----------|
| `fk_replies_ticket` | `ticket_id` | `tickets(id)` | CASCADE |
| `fk_replies_agent` | `agent_id` | `agents(id)` | SET NULL |
| `fk_replies_customer` | `customer_id` | `customers(id)` | SET NULL |

**Notes:**
- `system` replies are used for status-change events (e.g. "Ticket closed by agent") that appear in the thread timeline but are never emailed.
- Private (`is_private = 1`) replies are excluded from customer portal API responses. They are visible to all agents.
- `first_response_at` on the parent ticket is set to the `created_at` of the first reply where `author_type = 'agent'` and `is_private = 0`.

---

## 7. attachments

Files uploaded to a ticket or reply, stored on disk outside the web root.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `ticket_id` | INT UNSIGNED | NO | | FK → tickets.id |
| `reply_id` | INT UNSIGNED | YES | NULL | FK → replies.id. NULL for ticket-level attachments not linked to a specific reply |
| `filename` | VARCHAR(255) | NO | | Original filename as shown to the user (may contain Unicode) |
| `stored_path` | VARCHAR(512) | NO | | Path relative to `STORAGE_PATH/attachments/`, e.g. `42/abc123_report.pdf` |
| `mime_type` | VARCHAR(100) | NO | | MIME type detected server-side via `mime_content_type()` |
| `size_bytes` | INT UNSIGNED | NO | | File size in bytes |
| `download_token` | VARCHAR(255) | YES | NULL | HMAC-SHA256 signed token for unauthenticated download URLs (time-limited) |
| `uploaded_by_agent_id` | INT UNSIGNED | YES | NULL | FK → agents.id. Set when an agent uploaded the file |
| `uploaded_by_customer_id` | INT UNSIGNED | YES | NULL | FK → customers.id. Set when a customer uploaded the file |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | |

**Foreign keys:**

| Constraint | Column | References | On Delete |
|------------|--------|-----------|-----------|
| `fk_attachments_ticket` | `ticket_id` | `tickets(id)` | CASCADE |
| `fk_attachments_reply` | `reply_id` | `replies(id)` | SET NULL |
| `fk_attachments_agent` | `uploaded_by_agent_id` | `agents(id)` | SET NULL |
| `fk_attachments_customer` | `uploaded_by_customer_id` | `customers(id)` | SET NULL |

**Notes:**
- Physical files live at `{STORAGE_PATH}/attachments/{ticket_id}/{unique_filename}`.
- `mime_type` is always determined server-side using `mime_content_type()` after the file is saved to disk — the sender's `Content-Type` is ignored to prevent MIME-type spoofing.
- `public_html/attachment.php` serves files. It verifies either the `download_token` (HMAC) or a valid agent/customer JWT before calling `readfile()`. Path traversal is prevented by `realpath()` comparison against the attachments root.
- Inline display is only permitted for a safe whitelist of MIME types (`image/jpeg`, `image/png`, `image/gif`, `image/webp`, `application/pdf`, `text/plain`, `text/csv`, `video/mp4`, `video/webm`, `audio/mpeg`, `audio/wav`, `audio/ogg`). Everything else gets `Content-Disposition: attachment`. `text/html` and `image/svg+xml` are intentionally excluded to prevent stored XSS.

---

## 8. tags

A flat list of labels that can be applied to tickets.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `name` | VARCHAR(60) | NO | | Tag display name (unique, case-sensitive) |

**Unique keys:** `uq_tags_name` on `name`

---

## 9. ticket_tag_map

Many-to-many join between tickets and tags.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `ticket_id` | INT UNSIGNED | NO | | FK → tickets.id (composite PK) |
| `tag_id` | INT UNSIGNED | NO | | FK → tags.id (composite PK) |

**Primary key:** `(ticket_id, tag_id)`

**Foreign keys:**

| Constraint | Column | References | On Delete |
|------------|--------|-----------|-----------|
| `fk_ttm_ticket` | `ticket_id` | `tickets(id)` | CASCADE |
| `fk_ttm_tag` | `tag_id` | `tags(id)` | CASCADE |

---

## 10. ticket_relations

Symmetric many-to-many link between two tickets (used for "related tickets" without implying hierarchy or merge).

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `ticket_a_id` | INT UNSIGNED | NO | | FK → tickets.id (composite PK, always the lower ID) |
| `ticket_b_id` | INT UNSIGNED | NO | | FK → tickets.id (composite PK, always the higher ID) |

**Primary key:** `(ticket_a_id, ticket_b_id)`

**Check constraint:** `chk_rel_no_self` — `ticket_a_id != ticket_b_id` (prevents self-relation)

**Foreign keys:**

| Constraint | Column | References | On Delete |
|------------|--------|-----------|-----------|
| `fk_rel_a` | `ticket_a_id` | `tickets(id)` | CASCADE |
| `fk_rel_b` | `ticket_b_id` | `tickets(id)` | CASCADE |

**Notes:**
- The service layer always inserts with the lower ticket ID as `ticket_a_id` to maintain the composite PK uniqueness (prevents duplicate rows with IDs swapped).
- To fetch all related tickets for ticket X, query `WHERE ticket_a_id = X OR ticket_b_id = X`.

---

## 11. imap_accounts

Configuration for inbound email accounts polled by `bin/imap-poll.php`.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `name` | VARCHAR(100) | NO | | Human-readable label for this account |
| `host` | VARCHAR(255) | NO | | IMAP server hostname |
| `port` | SMALLINT UNSIGNED | NO | 993 | IMAP port (993 = SSL, 143 = STARTTLS) |
| `encryption` | ENUM('ssl','tls','none') | NO | 'ssl' | Connection encryption method |
| `username` | VARCHAR(255) | NO | | IMAP login username (usually the email address) |
| `from_address` | VARCHAR(255) | YES | NULL | Override From address for outgoing emails; if NULL/empty, `username` is used |
| `password` | TEXT | YES | NULL | AES-256-CBC encrypted password (encrypted using `JWT_SECRET` from `.env`) |
| `folder` | VARCHAR(100) | NO | 'INBOX' | IMAP folder to poll |
| `delete_after_import` | TINYINT(1) | NO | 0 | If 1, messages are deleted from the server after successful import |
| `tag_id` | INT UNSIGNED | YES | NULL | Automatically apply this tag to tickets created from this account |
| `is_enabled` | TINYINT(1) | NO | 1 | Disabled accounts are skipped by the poller |
| `last_connected_at` | DATETIME | YES | NULL | Last time a connection was successfully established |
| `last_poll_at` | DATETIME | YES | NULL | Last time the folder was polled (updated every run, even when no emails arrive) |
| `last_poll_count` | INT UNSIGNED | NO | 0 | Number of messages imported in the last poll run |
| `last_import_at` | DATETIME | YES | NULL | Last time at least one email was actually imported. Only updated when `last_poll_count > 0`, so this accurately reflects the most recent inbound email |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | |

**Notes:**
- `password` is encrypted at rest using `SettingsService::encrypt()` (AES-256-CBC, key derived from `JWT_SECRET`). It is never returned in plain text via the API.
- The legacy `settings` table also has `imap_*` keys for a single account. Multi-account support uses this table.
- `bin/imap-poll.php` uses PHP's native `imap_*` extension. It uses `flock()` on a lock file to prevent overlapping runs.

---

## 12. settings

Runtime-configurable key/value store for application settings that don't require a deploy to change.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `key_name` | VARCHAR(100) | NO | | Setting key (primary key) |
| `value` | TEXT | YES | NULL | Setting value (stored as string regardless of `type`) |
| `type` | ENUM('string','integer','boolean','json') | NO | 'string' | Hint for how to cast the value when reading |
| `group_name` | VARCHAR(60) | NO | 'general' | Logical grouping for the settings UI (`general`, `branding`, `email`, `imap`, `slack`) |
| `label` | VARCHAR(120) | NO | | Human-readable label shown in the settings UI |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | Last modification time |

**Primary key:** `key_name`

**Notes:**
- Values with `type = 'boolean'` are stored as `'0'` or `'1'`.
- Values with `type = 'integer'` are stored as numeric strings.
- Values with `type = 'json'` are stored as JSON strings.
- Sensitive values (`smtp_password`, `imap_password`) are AES-256-CBC encrypted. `SettingsService` transparently encrypts on write and decrypts on read.
- The INSERT in `schema.sql` uses `ON DUPLICATE KEY UPDATE label = VALUES(label)` so re-running the migration preserves customised values while updating label text.
- See [Default Settings Reference](#17-default-settings-reference) for the full list of keys.

---

## 13. knowledge_base_categories

Top-level categories for grouping knowledge base articles.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `name` | VARCHAR(120) | NO | | Display name |
| `slug` | VARCHAR(120) | NO | | URL-safe identifier (unique) |
| `sort_order` | INT | NO | 0 | Display order (ascending); ties resolved by `name` |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | |

**Unique keys:** `uq_kbc_slug` on `slug`

---

## 14. knowledge_base_articles

Individual knowledge base articles.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `category_id` | INT UNSIGNED | YES | NULL | FK → knowledge_base_categories.id. NULL = uncategorised |
| `title` | VARCHAR(255) | NO | | Article title |
| `slug` | VARCHAR(255) | NO | | URL-safe identifier, used in portal route `#/kb/:slug` (unique) |
| `body_html` | MEDIUMTEXT | NO | | Article body as HTML |
| `is_published` | TINYINT(1) | NO | 0 | `1` = visible in customer portal; `0` = draft (agents only) |
| `author_agent_id` | INT UNSIGNED | YES | NULL | FK → agents.id. The agent who created the article |
| `view_count` | INT UNSIGNED | NO | 0 | Incremented each time the article is viewed (portal or agent UI) |
| `source_ticket_id` | INT UNSIGNED | YES | NULL | FK → tickets.id. Set when an article was promoted from a ticket |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | |
| `updated_at` | DATETIME | NO | CURRENT_TIMESTAMP ON UPDATE | |
| `deleted_at` | DATETIME | YES | NULL | Soft-delete timestamp |

**Unique keys:** `uq_kba_slug` on `slug`

**Indexes:** `ft_kba_search` — FULLTEXT index on `(title, body_html)` — used by the agent dashboard search and portal search

**Foreign keys:**

| Constraint | Column | References | On Delete |
|------------|--------|-----------|-----------|
| `fk_kba_category` | `category_id` | `knowledge_base_categories(id)` | SET NULL |
| `fk_kba_author` | `author_agent_id` | `agents(id)` | SET NULL |
| `fk_kba_ticket` | `source_ticket_id` | `tickets(id)` | SET NULL |

**Notes:**
- The FULLTEXT index enables `MATCH(title, body_html) AGAINST(? IN BOOLEAN MODE)` queries for article search.
- Only published, non-deleted articles are returned by portal-facing API endpoints. Agents can see drafts.
- `slug` is used directly in the SPA route: `#/kb/{slug}` (customer portal) and `#/knowledge-base/{slug}` (agent UI).

---

## 15. refresh_tokens

JWT refresh tokens used for session persistence and token rotation.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | Primary key |
| `token_hash` | VARCHAR(64) | NO | | SHA-256 hex hash of the raw refresh token. The raw token is sent to the client; only the hash is stored |
| `agent_id` | INT UNSIGNED | YES | NULL | FK → agents.id. Set for agent sessions |
| `customer_id` | INT UNSIGNED | YES | NULL | FK → customers.id. Set for customer portal sessions |
| `expires_at` | DATETIME | NO | | Token expiry (typically 30 days from creation) |
| `revoked` | TINYINT(1) | NO | 0 | Set to 1 when the token has been used (one-time rotation) or explicitly revoked (logout) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | |

**Unique keys:** `uq_rt_hash` on `token_hash`

**Indexes:** `idx_rt_agent` on `agent_id`

**Foreign keys:**

| Constraint | Column | References | On Delete |
|------------|--------|-----------|-----------|
| `fk_rt_agent` | `agent_id` | `agents(id)` | CASCADE |
| `fk_rt_customer` | `customer_id` | `customers(id)` | CASCADE |

**Notes:**
- Access tokens (JWTs) are short-lived (15 minutes). Refresh tokens live in this table for 30 days.
- Token rotation: each use of a refresh token revokes the old one (`revoked = 1`) and issues a new one. If a revoked token is presented, the session is considered compromised and all tokens for that user are revoked.
- Exactly one of `agent_id` / `customer_id` will be non-NULL per row.
- Expired and revoked tokens are not automatically cleaned up; a periodic cron or maintenance script is needed for housekeeping (not currently implemented).

---

## 16. audit_log

Immutable log of significant actions performed in the system.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | Primary key (BIGINT for high volume) |
| `actor_type` | ENUM('agent','customer','system') | NO | | Who performed the action |
| `actor_id` | INT UNSIGNED | YES | NULL | ID of the agent or customer (NULL for system actions) |
| `action` | VARCHAR(80) | NO | | Action identifier, e.g. `ticket.created`, `ticket.status_changed`, `agent.login` |
| `subject_type` | VARCHAR(40) | NO | | Type of entity acted upon, e.g. `ticket`, `customer`, `agent` |
| `subject_id` | INT UNSIGNED | NO | | ID of the entity acted upon |
| `payload` | JSON | YES | NULL | Additional context as a JSON object (e.g. `{"from": "open", "to": "closed"}`) |
| `ip_address` | VARCHAR(45) | YES | NULL | IPv4 or IPv6 address of the client (45 chars covers full IPv6) |
| `created_at` | DATETIME | NO | CURRENT_TIMESTAMP | |

**Indexes:**

| Index | Columns | Purpose |
|-------|---------|---------|
| `idx_audit_subject` | `(subject_type, subject_id)` | Fetch history for a specific entity |
| `idx_audit_actor` | `(actor_type, actor_id)` | Fetch all actions by a specific actor |
| `idx_audit_created` | `created_at` | Chronological queries and pruning |

**Notes:**
- Rows are never updated or deleted (append-only by design).
- `payload` is flexible JSON — shape varies by `action`. Common patterns:
  - `ticket.status_changed`: `{"from": "open", "to": "closed"}`
  - `ticket.assigned`: `{"agent_id": 3, "agent_name": "Jane"}`
  - `agent.login`: `{}` (IP recorded separately)
- BIGINT primary key is used because audit tables grow large in busy systems.

---

## 17. Default Settings Reference

The following settings are seeded by `schema.sql`. Values shown are the defaults; all can be changed at runtime via the Settings UI (`#/settings`).

### General

| Key | Default | Type | Description |
|-----|---------|------|-------------|
| `ticket_prefix` | `HD` | string | Prefix for ticket numbers (e.g. `HD-2026-03-17-0001`) |
| `timezone` | `Pacific/Auckland` | string | PHP timezone string for date display |
| `date_format` | `d/m/Y H:i` | string | PHP `date()` format string |
| `imap_poll_mode` | `cron` | string | `cron` = external crontab; any other value reserved for future webhook/long-poll modes |

### Branding

| Key | Default | Type | Description |
|-----|---------|------|-------------|
| `company_name` | `Andrea Helpdesk` | string | Displayed in page title, emails, and portal |
| `logo_url` | `` | string | Absolute URL to logo image shown in the navbar |
| `favicon_url` | `` | string | Absolute URL to favicon (must start with `https://` or `http://`) |
| `primary_color` | `#0d6efd` | string | Bootstrap primary colour override (CSS hex) |
| `accent_color` | `#6610f2` | string | Accent colour (currently unused; reserved for theming) |
| `custom_css` | `` | string | Raw CSS injected into `<style>` in the SPA shell |

### Email (SMTP)

| Key | Default | Type | Description |
|-----|---------|------|-------------|
| `smtp_host` | `` | string | Outbound SMTP server hostname |
| `smtp_port` | `587` | integer | SMTP port |
| `smtp_username` | `` | string | SMTP auth username |
| `smtp_password` | `` | string | SMTP password (AES-256-CBC encrypted at rest) |
| `smtp_from_address` | `` | string | From email address for outbound mail |
| `smtp_from_name` | `Andrea Helpdesk` | string | From display name |
| `smtp_encryption` | `tls` | string | `tls`, `ssl`, or `none` |
| `reply_to_address` | `` | string | Global Reply-To address (optional) |
| `global_signature` | `<p>--<br>Andrea Helpdesk</p>` | string | Default HTML signature appended to all outbound emails unless overridden per-agent |
| `auto_response_enabled` | `1` | boolean | Whether to send an automatic acknowledgement on new tickets |
| `auto_response_subject` | `Re: {{subject}} [{{ticket_number}}]` | string | Auto-response subject template |
| `auto_response_body` | *(HTML template)* | string | Auto-response body HTML template. Placeholders: `{{customer_name}}`, `{{ticket_number}}`, `{{subject}}`, `{{global_signature}}` |

### IMAP (legacy single-account)

| Key | Default | Type | Description |
|-----|---------|------|-------------|
| `imap_host` | `` | string | IMAP server hostname |
| `imap_port` | `993` | integer | IMAP port |
| `imap_username` | `` | string | IMAP login username |
| `imap_password` | `` | string | IMAP password (AES-256-CBC encrypted at rest) |
| `imap_folder` | `INBOX` | string | Folder to poll |
| `imap_encryption` | `ssl` | string | `ssl`, `tls`, or `none` |
| `imap_delete_after_import` | `0` | boolean | Delete messages from server after import |

### Slack

| Key | Default | Type | Description |
|-----|---------|------|-------------|
| `slack_enabled` | `0` | boolean | Master switch for Slack notifications |
| `slack_webhook_url` | `` | string | Incoming Webhook URL from Slack App configuration |
| `slack_channel` | `#helpdesk` | string | Channel name (overrides the webhook's default channel) |
| `slack_on_new_ticket` | `1` | boolean | Notify when a new ticket is created |
| `slack_on_assign` | `1` | boolean | Notify when a ticket is assigned to an agent |

---

## 18. Entity Relationship Summary

```
agents ──────────────────────────────────────────────────────────────────────────┐
  │ (assigned_agent_id)                                                           │
  │ (uploaded_by_agent_id)                                                        │
  │ (author_agent_id — KB)                                                        │
  │                                                                               │
customers ────────────────────────┐                                               │
  │ (customer_id)                 │                                               │
  │ (customer_id — participants)  │                                               │
  │                               │                                               │
  ▼                               ▼                                               ▼
tickets ◄──── ticket_participants  ──► customers           attachments ◄──── agents
  │                                                             │
  │◄── ticket_tag_map ──► tags                                  │◄── customers
  │◄── ticket_relations (self)                                  │
  │◄── replies ──────────────────────────────────► agents
  │         │                                   └─► customers
  │         │◄── attachments
  │
  ▼
knowledge_base_articles ──► knowledge_base_categories
knowledge_base_articles ──► agents (author)
knowledge_base_articles ──► tickets (source_ticket_id)

refresh_tokens ──► agents
refresh_tokens ──► customers

audit_log (no FK constraints — append-only, references by value)

ticket_number_sequences (standalone counter table, no FK)
settings (standalone key/value store, no FK)
imap_accounts (standalone — tag_id references tags but no FK defined)
```

---

## 19. Indexes and Performance Notes

### Covering indexes for common queries

The ticket list query (`GET /api/tickets`) filters by `deleted_at IS NULL` and optionally by `status` and `assigned_agent_id`. The composite indexes `idx_tickets_deleted_status` and `idx_tickets_deleted_agent` are designed to cover these.

### Full-text search

`knowledge_base_articles` has a FULLTEXT index on `(title, body_html)`. Queries use `MATCH(...) AGAINST(? IN BOOLEAN MODE)` which supports `+word`, `-word`, and prefix `word*` syntax.

### Prefix indexes on VARCHAR(512)

`Message-ID` fields (`original_message_id`, `raw_message_id`) are indexed with a 191-character prefix (`(191)`) — the maximum for a single-column index on utf8mb4 without changing `innodb_large_prefix`.

### Soft-delete pattern

`tickets`, `customers`, and `knowledge_base_articles` use `deleted_at DATETIME NULL` for soft deletes. All queries must include `WHERE deleted_at IS NULL` (or equivalent) to exclude deleted rows. The `idx_*_deleted` indexes make this efficient.

### Character set

All tables use `utf8mb4` with `utf8mb4_unicode_ci` collation, which correctly handles emoji, CJK characters, and case-insensitive email comparisons in the unique indexes.

### Foreign key checks

`schema.sql` wraps everything in `SET FOREIGN_KEY_CHECKS = 0` / `SET FOREIGN_KEY_CHECKS = 1` to allow tables to be created in any order. This is safe for initial schema creation only — normal application operation relies on FK enforcement.
