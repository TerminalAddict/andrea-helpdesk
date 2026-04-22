# Andrea Helpdesk — API Specification

All API endpoints are served under the `/api` prefix. The API returns JSON for all responses.

---

## Contents

- [Response format](#response-format)
- [Authentication](#authentication)
- [Middleware / permissions](#middleware--permissions)
- [Auth endpoints](#auth-endpoints)
- [Ticket endpoints](#ticket-endpoints)
- [Reply endpoints](#reply-endpoints)
- [Attachment endpoints](#attachment-endpoints)
- [Tag endpoints](#tag-endpoints)
- [Customer endpoints](#customer-endpoints)
- [Agent endpoints](#agent-endpoints)
- [Settings endpoints](#settings-endpoints)
- [IMAP account endpoints](#imap-account-endpoints)
- [Report endpoints](#report-endpoints)
- [Knowledge base endpoints](#knowledge-base-endpoints)
- [Portal auth endpoints](#portal-auth-endpoints)
- [Portal ticket endpoints](#portal-ticket-endpoints)
- [Public support form endpoints](#public-support-form-endpoints)
- [Calendar endpoints](#calendar-endpoints)
- [Version endpoints](#version-endpoints)
- [Update endpoints](#update-endpoints)

---

## Response format

### Success

```json
{
  "success": true,
  "data": { ... },
  "message": "OK"
}
```

### Paginated list

```json
{
  "success": true,
  "data": [ ... ],
  "meta": {
    "total": 142,
    "page": 1,
    "per_page": 25,
    "last_page": 6
  }
}
```

### Error

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["A valid email address is required"]
  }
}
```

### HTTP status codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created |
| 400 | Bad request / validation error |
| 401 | Unauthenticated |
| 403 | Forbidden (authenticated but no permission) |
| 404 | Resource not found |
| 409 | Conflict (e.g. duplicate email) |
| 429 | Too many requests / rate limited |
| 422 | Unprocessable (business logic rejection) |
| 500 | Server error |

---

## Authentication

The API uses JWT bearer tokens.

- **Access token** — short-lived (15 min). Include in every protected request:
  ```
  Authorization: Bearer <access_token>
  ```
- **Refresh token** — long-lived (30 days, hashed in DB). Exchange for a new access token via `POST /api/auth/refresh`.
- Tokens are issued for two user types: `agent` and `customer`. Each type can only access its own set of endpoints.

---

## Middleware / permissions

Routes are protected by one or more middleware names declared in `config/routes.php`.

| Middleware | Description |
|------------|-------------|
| `auth:agent` | Valid agent JWT required |
| `auth:customer` | Valid customer JWT required |
| `auth:any` | Either agent or customer JWT accepted |
| `role:admin` | Agent must have `role = 'admin'` |
| `permission:can_close_tickets` | Agent flag must be `1` (or admin) |
| `permission:can_delete_tickets` | Agent flag must be `1` (or admin) |
| `permission:can_edit_customers` | Agent flag must be `1` (or admin) |
| `permission:can_view_reports` | Agent flag must be `1` (or admin) |
| `permission:can_manage_kb` | Agent flag must be `1` (or admin) |
| `permission:can_manage_tags` | Agent flag must be `1` (or admin) |
| _(none)_ | Public — no authentication required |

Admins bypass all `permission:*` checks.

---

## Auth endpoints

### `POST /api/auth/login`

Authenticate an agent or customer with email and password.

**No auth required.**

**Request body**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | yes | Email address |
| `password` | string | yes | Password |
| `type` | string | yes | `agent` or `customer` |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "access_token": "eyJ...",
    "refresh_token": "eyJ...",
    "user": { ... }
  }
}
```

The `user` object contains agent or customer fields (no `password_hash`).

---

### `POST /api/auth/refresh`

Exchange a refresh token for a new access token. The old refresh token is revoked and a new one issued (rotation).

**No auth required.**

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `refresh_token` | string | yes |

**Response `200`** — same shape as login: `access_token`, `refresh_token`, `user`.

---

### `POST /api/auth/logout`

Revoke the provided refresh token.

**Auth:** `auth:any`

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `refresh_token` | string | no |

**Response `200`** — `data: null`, `message: "Logged out"`.

---

### `GET /api/auth/me`

Return the currently authenticated user.

**Auth:** `auth:any`

**Response `200`**

```json
{
  "success": true,
  "data": {
    "type": "agent",
    "user": { ... }
  }
}
```

---

### `POST /api/auth/magic-link`

Send a one-click portal login link to a customer email. Always returns `200` regardless of whether the email exists (to prevent enumeration).

**No auth required.**

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `email` | string | yes |

**Response `200`** — `message: "If this email exists, a login link has been sent."`

---

## Ticket endpoints

All ticket endpoints require `auth:agent`.

### `GET /api/tickets`

List tickets with optional filters.

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Any single status: `new`, `open`, `waiting_for_reply`, `replied`, `pending`, `resolved`, `closed`. Use `active` to return all non-resolved, non-closed tickets. |
| `priority` | string | `overdue`, `urgent`, `high`, `normal`, `low` |
| `assigned_to` | int | Agent ID. Use `unassigned` for unassigned tickets |
| `customer_id` | int | Filter by customer |
| `channel` | string | `email`, `web`, `phone`, `portal` |
| `q` | string | Full-text search across subject, body, customer name/email |
| `from` | date | Created on or after (YYYY-MM-DD) |
| `to` | date | Created on or before (YYYY-MM-DD) |
| `tag_id` | int | Filter by tag |
| `sort` | string | Column to sort by: `ticket_number`, `status`, `priority`, `created_at`, `updated_at`, `last_attention_at`. Default: `updated_at` |
| `dir` | string | `asc` or `desc`. Default: `desc` |
| `page` | int | Page number. Default: `1` |
| `per_page` | int | Results per page. Default: `25`, max `100` |

**Response `200`** — paginated list of ticket objects.

Each ticket includes: `id`, `ticket_number`, `subject`, `status`, `priority`, `channel`, `customer_id`, `customer_name`, `customer_email`, `assigned_agent_id`, `agent_name`, `tag_names` (comma-separated), `reply_count`, `parent_ticket_id`, `last_attention_at`, `created_at`, `updated_at`.

---

### `POST /api/tickets`

Create a new ticket on behalf of a customer. The customer is upserted by email.

**Request body**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `customer_email` | string | yes | Customer email (upserted if not found) |
| `customer_name` | string | no | Customer display name |
| `subject` | string | yes | Ticket subject (max 255) |
| `body` | string | no | Initial message plain text (used for validation and email plain-text part) |
| `body_html` | string | no | Rich HTML body from the editor. When present, stored after server-side sanitisation. Falls back to `nl2br(htmlspecialchars($body))` if omitted. |
| `priority` | string | no | Default: `normal` |
| `channel` | string | no | Default: `phone` |
| `assigned_agent_id` | int | no | Assign immediately |
| `parent_ticket_id` | int | no | Create as child of this ticket |
| `due_at` | string | no | Due date/time. Accepts `YYYY-MM-DDTHH:MM`, `YYYY-MM-DD HH:MM:SS`, or `YYYY-MM-DD`. |
| `due_end` | string | no | End of a multi-day range. Same formats as `due_at`. |
| `due_all_day` | bool | no | `1` = all-day event; `0` = timed event. |

**Response `201`** — ticket object.

---

### `GET /api/tickets/:id`

Get a single ticket with its full thread (replies, attachments, participants, tags, relations, parent, children).

**Response `200`** — ticket object plus:
- `replies` — array of reply objects (includes private notes for agents)
- `attachments` — array of attachment objects
- `participants` — array of CC participant objects
- `tags` — array of tag objects
- `relations` — array of related ticket objects
- `parent` — parent ticket (if any)
- `children` — array of child tickets

---

### `PUT /api/tickets/:id`

Update a ticket's subject, priority, assigned agent, or customer. Changes are recorded as system events in the ticket thread (audit trail).

**Request body** (all fields optional)

| Field | Type | Description |
|-------|------|-------------|
| `subject` | string | New subject (max 255 chars) |
| `priority` | string | `overdue`, `urgent`, `high`, `normal`, `low` |
| `assigned_agent_id` | int\|null | Assign or unassign |
| `customer_id` | int | Change the primary customer on the ticket |
| `suppress_emails` | bool | `1` to suppress all outbound customer emails for this ticket; `0` to resume. Does not affect Slack or agent notifications. |
| `due_at` | string\|empty | Due date/time. Accepts `YYYY-MM-DDTHH:MM` (datetime-local), `YYYY-MM-DD HH:MM:SS`, or `YYYY-MM-DD` (date only). Send empty string to clear. |
| `due_end` | string\|empty | End of a multi-day or time-range due date. Same formats as `due_at`. Optional. |
| `due_all_day` | bool | `1` = all-day event (date only, no specific time); `0` = timed event. |

If `assigned_agent_id` changes, an assignment notification is sent to the new agent. If `subject`, `customer_id`, or `suppress_emails` changes, a system event is added to the ticket thread recording the change and the agent who made it.

**Response `200`** — updated ticket object.

---

### `DELETE /api/tickets/:id`

Soft-delete a ticket (and its children) along with physical attachment files.

**Auth:** `auth:agent`, `permission:can_delete_tickets`

**Response `200`** — `data: null`.

---

### `POST /api/tickets/:id/assign`

Assign or unassign a ticket.

**Request body**

| Field | Type | Description |
|-------|------|-------------|
| `agent_id` | int\|null | Agent to assign, or `null`/omit to unassign |

**Response `200`** — updated ticket object.

---

### `POST /api/tickets/:id/status`

Change ticket status. Closing/resolving requires `can_close_tickets` permission (or admin).

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `status` | string | yes — `new`, `open`, `waiting_for_reply`, `replied`, `pending`, `resolved`, `closed` |

**Response `200`** — updated ticket object.

---

### `POST /api/tickets/:id/merge`

Merge this ticket into a target ticket. The source ticket's replies are moved to the target; the source is then soft-deleted.

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `target_ticket_id` | int | yes |

**Response `200`** — `message: "Ticket merged into HD-..."`.

---

### `POST /api/tickets/:id/relations`

Link two tickets as related (symmetric many-to-many).

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `related_ticket_id` | int | yes |

**Response `200`** — `data: null`.

---

### `DELETE /api/tickets/:id/relations/:related_id`

Remove a ticket relation.

**Response `200`** — `data: null`.

---

### `POST /api/tickets/:id/spawn`

Create a child (sub) ticket under this ticket.

**Request body**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `subject` | string | yes | Child ticket subject |
| `priority` | string | no | Default: `normal` |
| `body_html` | string | no | Initial message body |
| `customer_id` | int | no | Defaults to parent ticket's customer |

**Response `201`** — new child ticket object.

---

### `POST /api/tickets/:id/move-to-kb`

Convert this ticket into a knowledge base article (draft). The ticket subject becomes the article title; replies become the article body.

**Response `201`** — knowledge base article object.

---

### `GET /api/tickets/:id/participants`

List CC participants for a ticket.

**Response `200`** — array of participant objects: `id`, `ticket_id`, `email`, `name`, `role`, `customer_id`, `customer_name`.

---

### `POST /api/tickets/:id/participants`

Add a CC participant. The email is upserted as a customer record if not already known.

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `email` | string | yes |
| `name` | string | no |

**Response `200`** — updated participants array.

---

### `DELETE /api/tickets/:id/participants/:participant_id`

Remove a CC participant.

**Response `200`** — `data: null`.

---

### `POST /api/tickets/:id/tags`

Add one or more tags to a ticket. Provide either a tag name (creates if not found) or an array of existing tag IDs. The agent UI uses `tag_ids` (selecting from the global tag list); the `name` path is available for API integrations that need to create-or-attach by name.

**Request body**

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | Tag name — creates the tag if it doesn't exist, then attaches it |
| `tag_ids` | int[] | Array of existing tag IDs to attach |

**Response `200`** — array of tag objects on the ticket.

---

### `DELETE /api/tickets/:id/tags/:tag_id`

Remove a tag from a ticket.

**Response `200`** — `data: null`.

---

## Reply endpoints

All reply endpoints require `auth:agent`.

### `GET /api/tickets/:id/replies`

List all replies for a ticket. Agents see private notes; customers do not.

**Response `200`** — array of reply objects: `id`, `ticket_id`, `author_type`, `agent_id`, `agent_name`, `customer_id`, `body_html`, `body_text`, `is_private`, `direction`, `created_at`, `attachments`.

---

### `POST /api/tickets/:id/replies`

Post a reply to a ticket. Emails the customer and CC participants (unless `is_private` is true). Supports file uploads via `multipart/form-data`.

**Request body** (`multipart/form-data` or JSON)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `body` | string | yes | Plain text body (used for validation and email plain-text part) |
| `body_html` | string | no | Rich HTML body from the editor. Stored after server-side sanitisation. Falls back to `nl2br(htmlspecialchars($body))` if omitted. |
| `type` | string | no | `reply` (default) or `internal` |
| `is_private` | bool | no | `true` = internal note, not sent to customer |
| `cc_emails` | string[] | no | Additional email addresses to CC on this reply |
| `status_after` | string | no | Override ticket status after posting: `new`, `open`, `waiting_for_reply`, `replied`, `pending`, `resolved`, `closed`. If omitted, agent replies automatically set status to `replied` (unless already resolved/closed) |
| `include_signature` | string | no | Pass `0` to send without the agent's personal signature. Default: signature is included |
| `file` | file | no | One or more file attachments |

**Response `201`** — reply object.

**@mention notifications:** if `body_html` contains `<span class="mention mention-{id}">` elements (inserted by the frontend @mention autocomplete), an email notification is sent to each mentioned agent after the reply is saved. Self-mentions are silently ignored. Duplicate mentions in the same reply are deduplicated.

---

### `PUT /api/tickets/:id/replies/:reply_id`

Edit the body of an existing reply. Agents may only edit their own replies; admins may edit any agent reply. Customer replies cannot be edited. Records a system event in the thread.

**Request body**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `body` | string | yes | Updated plain text body |
| `body_html` | string | no | Updated rich HTML body. Stored after server-side sanitisation. Falls back to `nl2br(htmlspecialchars($body))` if omitted. |

**Response `200`** — `data: null`, `message: "Reply updated"`.

---

## Attachment endpoints

### `POST /api/tickets/:id/attachments`

Upload a file attachment to a ticket.

**Auth:** `auth:agent`

**Request:** `multipart/form-data` with `file` field.

**Response `201`** — attachment object: `id`, `ticket_id`, `reply_id`, `filename`, `mime_type`, `size_bytes`, `download_token`, `created_at`.

---

### `DELETE /api/attachments/:id`

Delete an attachment (removes the physical file and database record).

**Auth:** `auth:agent`

**Response `200`** — `data: null`.

---

### Downloading attachments

Attachments are served by `public_html/attachment.php`, not the API. Use:

```
GET /attachment/:id?token=<download_token>
```

Or authenticate with a bearer token (agent or customer JWT). The file is served inline for safe types (images, PDFs, audio, video) or as a download for all others.

---

## Tag endpoints

### `GET /api/tags`

List all tags.

**Auth:** `auth:agent`

**Response `200`** — array of `{ id, name }`.

---

### `POST /api/tags`

Create a tag.

**Auth:** `auth:agent`, `permission:can_manage_tags`

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `name` | string | yes |

**Response `201`** — tag object.

---

### `PUT /api/tags/:id`

Rename a tag.

**Auth:** `auth:agent`, `permission:can_manage_tags`

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `name` | string | yes |

**Response `200`** — updated tag object.

---

### `DELETE /api/tags/:id`

Delete a tag. Removes the tag from all tickets (via `ticket_tag_map` cascade).

**Auth:** `auth:agent`, `permission:can_manage_tags`

**Response `200`** — `data: null`.

---

## Customer endpoints

All customer endpoints require `auth:agent` unless noted.

### `GET /api/customers`

List customers.

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | string | Search by name or email |
| `company` | string | Filter by company |
| `page` | int | Default: `1` |
| `per_page` | int | Default: `25`, max `100` |

**Response `200`** — paginated list of customer objects (no `portal_password_hash`, `portal_token`, or `portal_token_expires`).

---

### `POST /api/customers`

Create a customer. Returns `409` if the email already exists.

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `name` | string | yes |
| `email` | string | yes |
| `phone` | string | no |
| `company` | string | no |
| `notes` | string | no |

**Response `201`** — customer object.

---

### `POST /api/customers/import`

Bulk-import customers from a CSV file. Skips rows where the email already exists (including soft-deleted customers). Returns a summary of created and skipped records.

**Auth:** `auth:agent`, `permission:can_edit_customers`

**Request** — `multipart/form-data`

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `csv` | file | yes | CSV file (max 2 MB). Must have `name` and `email` columns in the header row. `phone` and `company` columns are optional. Column order does not matter. |

**CSV format**

```
name,email,phone,company
Jane Smith,jane@example.com,+64 9 123 4567,Acme Ltd
John Doe,john@example.com,,
```

**Response `200`**

```json
{
  "success": true,
  "data": {
    "created_count": 2,
    "skipped_count": 1,
    "created": [
      { "id": 42, "name": "Jane Smith", "email": "jane@example.com" }
    ],
    "skipped": [
      { "row": 3, "email": "existing@example.com", "reason": "Already exists" }
    ]
  },
  "message": "Import complete"
}
```

Possible `reason` values in `skipped`: `Already exists`, `Invalid email address`, `Missing name or email`.

**Response `400`** — No file uploaded, file exceeds 2 MB, unreadable file, empty CSV, or missing required columns.

---

### `GET /api/customers/:id`

Get a single customer.

**Response `200`** — customer object.

---

### `PUT /api/customers/:id`

Update a customer.

**Request body** (all optional)

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | |
| `email` | string | |
| `phone` | string | |
| `company` | string | |
| `notes` | string | |
| `suppress_emails` | bool | `1` to suppress all outbound emails to this customer globally; `0` to resume. Applies across all tickets — if either the customer or the ticket has suppression enabled, no email is sent. |

**Response `200`** — updated customer object.

---

### `DELETE /api/customers/:id`

Soft-delete a customer.

**Auth:** `role:admin`

**Response `200`** — `data: null`.

---

### `GET /api/customers/:id/tickets`

List all tickets for a customer.

**Query parameters:** `page`, `per_page`

**Response `200`** — paginated ticket list.

---

### `GET /api/customers/:id/replies`

List all replies made by a customer across all tickets.

**Query parameters:** `page`, `per_page`

**Response `200`** — paginated reply list.

---

### `POST /api/customers/:id/portal-invite`

Send a portal magic-link invite email to the customer.

**Auth:** `role:admin`

**Response `200`** — `message: "Portal invite sent"`.

---

### `POST /api/customers/:id/set-password`

Set a portal password for a customer (admin override, no current password required).

**Auth:** `role:admin`

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `password` | string | yes — min 8 chars |
| `password_confirm` | string | yes |

**Response `200`** — `data: null`.

---

## Agent endpoints

### `GET /api/agents`

List all agents.

**Auth:** `auth:agent`

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `include_inactive` | `1` | Include deactivated agents |

**Response `200`** — array of agent objects (no `password_hash`).

---

### `POST /api/agents`

Create a new agent.

**Auth:** `role:admin`

**Request body**

| Field | Type | Required | Default |
|-------|------|----------|---------|
| `name` | string | yes | — |
| `email` | string | yes | — |
| `password` | string | yes (min 8) | — |
| `role` | string | no | `agent` |
| `can_close_tickets` | bool | no | `true` |
| `can_delete_tickets` | bool | no | `false` |
| `can_edit_customers` | bool | no | `false` |
| `can_view_reports` | bool | no | `false` |
| `can_manage_kb` | bool | no | `false` |
| `can_manage_tags` | bool | no | `false` |
| `signature` | string | no | — |

**Response `201`** — agent object.

---

### `GET /api/agents/:id`

Get a single agent.

**Auth:** `auth:agent`

**Response `200`** — agent object.

---

### `PUT /api/agents/:id`

Update an agent (admin only). All fields are optional.

**Auth:** `role:admin`

**Request body** — same fields as `POST /api/agents`, plus `is_active` (bool).

**Response `200`** — updated agent object.

---

### `POST /api/agents/:id/deactivate`

Deactivate an agent account (cannot log in).

**Auth:** `role:admin`

**Response `200`** — `data: null`.

---

### `POST /api/agents/:id/activate`

Re-activate a deactivated agent.

**Auth:** `role:admin`

**Response `200`** — `data: null`.

---

### `POST /api/agents/:id/reset-password`

Generate and set a new random password. Returns the new plaintext password — share securely; it is not stored.

**Auth:** `role:admin`

**Response `200`**

```json
{
  "data": { "new_password": "xK3m..." },
  "message": "Password reset. Share this password securely."
}
```

---

### `PUT /api/agent/profile`

Update the currently authenticated agent's own profile. Requires current password to change password.

**Auth:** `auth:agent`

**Request body** (all optional)

| Field | Type | Description |
|-------|------|-------------|
| `signature` | string | HTML email signature |
| `page_size` | int | `10`, `20`, or `50` |
| `theme` | string | `light` or `dark` |
| `browser_notifications_enabled` | bool | Enable or disable browser notifications for this agent while the app is open |
| `current_password` | string | Required if changing password |
| `new_password` | string | Min 8 chars |

**Response `200`** — updated agent object.

---

## Notification endpoints

All notification endpoints require **`auth:agent`**. The silent update-check endpoint additionally requires **`role:admin`**.

### `GET /api/notifications`

Return the current agent's unread, still-active notification queue for the navbar bell menu.

**Query params**

| Param | Type | Required | Notes |
|-------|------|----------|-------|
| `limit` | int | no | Default `12`, max `50` |
| `after_id` | int | no | When supplied, only notifications with `id > after_id` are returned, ordered ascending for polling |

**Response `200`**

```json
{
  "data": {
    "items": [
      {
        "id": 44,
        "type": "ticket_created",
        "severity": "warning",
        "title": "New ticket HD-2026-04-16-185",
        "body": "Paul CCL · Cannot log in",
        "link": "/tickets/185",
        "data": {
          "ticket_id": 185
        },
        "read_at": null,
        "created_at": "2026-04-16 14:03:11"
      }
    ],
    "unread_count": 3,
    "active_count": 5
  }
}
```

### `GET /api/notifications/active`

Return the current agent's active notification overview for `#/my-profile/notifications`. This includes both unread and read notifications that are still relevant based on current ticket/update state.

**Query params**

| Param | Type | Required | Notes |
|-------|------|----------|-------|
| `limit` | int | no | Default `100`, max `250` |

**Response `200`**

```json
{
  "data": {
    "items": [
      {
        "id": 51,
        "type": "ticket_overdue",
        "severity": "danger",
        "title": "Overdue: HD-2026-04-16-185",
        "body": "Cannot log in · Due date has passed",
        "link": "/tickets/185",
        "data": {
          "ticket_id": 185
        },
        "read_at": "2026-04-16 15:11:02",
        "created_at": "2026-04-16 14:40:07"
      }
    ],
    "unread_count": 0,
    "active_count": 1
  }
}
```

### `POST /api/notifications/:id/read`

Mark one notification as read for the current agent.

**Response `200`**

```json
{
  "data": {
    "unread_count": 2,
    "active_count": 5
  }
}
```

### `POST /api/notifications/read-all`

Mark all notifications as read for the current agent.

### `POST /api/notifications/check-updates`

Run the silent background update check for the current admin. The server enforces a once-per-day limit per admin via `agents.last_update_check_at` and serialises concurrent checks for the same admin before fetching upstream version metadata.

**Auth:** `role:admin`

**Response `200`**

```json
{
  "data": {
    "checked": true,
    "created": true,
    "installed_version": "1.2.8",
    "latest_version": "1.2.9"
  }
}
```

If the latest version is newer and no existing notification has already been recorded for that admin/version pair, an `update_available` in-app notification is created linking to `#/admin/settings/general`.

---

## Settings endpoints

### `GET /api/settings/public`

Public branding and display settings. No authentication required.

**Response `200`**

```json
{
  "data": {
    "company_name": "Acme Support",
    "logo_url": "https://...",
    "primary_color": "#0d6efd",
    "date_format": "d/m/Y H:i",
    "favicon_url": "",
    "global_signature": "<p>-- ...</p>",
    "imap_poll_mode": "cron"
  }
}
```

---

### `GET /api/admin/settings`

Get all runtime settings (or a specific group).

**Auth:** `role:admin`

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `group` | string | `general`, `branding`, `email`, `imap`, `slack` |

Sensitive values (`smtp_password`, `imap_password`) are masked as `***` in the response.

**Response `200`** — object of `{ key_name: value }` pairs (when group specified) or grouped object (all settings).

---

### `PUT /api/admin/settings`

Update one or more settings.

**Auth:** `role:admin`

**Request body**

```json
{
  "settings": {
    "company_name": "Acme Support",
    "smtp_host": "smtp.example.com",
    "smtp_password": "secret"
  }
}
```

`smtp_password` and `imap_password` are encrypted with AES-256-CBC before storage. Sending `***` or an empty string for a password field leaves the existing value unchanged.

SLA-related keys used by the General tab:

| Key | Type | Description |
|-----|------|-------------|
| `sla_enabled` | boolean | Enable inactivity-based escalation |
| `sla_high_after_days` | integer | Days with no attention before a ticket is raised to `high` |
| `sla_overdue_after_days` | integer | Additional days with no attention before the ticket is raised to `overdue` |
| `sla_notify_scope` | string | `all` or `specific` |
| `sla_notify_agent_ids` | json array | Agent IDs used when `sla_notify_scope = specific` |

**Response `200`** — `data: null`.

---

### `POST /api/admin/settings/test-smtp`

Send a test email to the currently authenticated admin's address using the current SMTP settings.

**Auth:** `role:admin`

**Response `200`** — `message: "Test email sent to admin@example.com"`.

---

### `POST /api/admin/settings/test-imap`

Test the legacy single-account IMAP connection (from the `settings` table).

**Auth:** `role:admin`

**Response `200`** — `{ message_count: 42 }`.

---

### `POST /api/admin/settings/test-slack`

Send a test message to the configured Slack webhook.

**Auth:** `role:admin`

**Response `200`** — `data: null`.

---

## IMAP account endpoints

Manage multiple inbound email accounts.

**Auth:** `role:admin` for all endpoints.

### `GET /api/admin/imap-accounts`

List all IMAP accounts. Passwords are masked. Each account includes `last_connected_at`, `last_poll_at` (every poll run), `last_poll_count` (messages in last run), and `last_import_at` (last run that actually imported at least one email — `null` if no emails have ever been imported).

### `POST /api/admin/imap-accounts`

Create a new IMAP account.

**Request body**

| Field | Type | Required | Default |
|-------|------|----------|---------|
| `name` | string | yes | — |
| `host` | string | yes | Hostname or IP. Leading/trailing whitespace stripped. |
| `port` | int | no | `993` |
| `encryption` | string | no | `ssl` — `ssl`, `tls`, `none` |
| `username` | string | yes | Email (`user@domain.com`) or Windows domain (`DOMAIN\user`) format. Leading/trailing whitespace stripped. |
| `password` | string | yes | — |
| `from_address` | string | no | Defaults to `username` |
| `folder` | string | no | `INBOX` |
| `delete_after_import` | bool | no | `false` |
| `tag_id` | int | no | — |
| `is_enabled` | bool | no | `true` |

### `PUT /api/admin/imap-accounts/:id`

Update an IMAP account. Same fields as create; all optional.

### `DELETE /api/admin/imap-accounts/:id`

Delete an IMAP account.

### `POST /api/admin/imap-accounts/:id/test`

Test the connection to this IMAP account. Runs as a CLI subprocess (`bin/imap-test.php`) to ensure DNS resolution works in the same network context as the cron poller. Returns `{ "ok": true, "msg": "Connection successful — credentials accepted." }` on success.

### `GET /api/admin/imap-accounts/:id/list-folders`

List all available folders/mailboxes on this IMAP account. Useful for discovering the correct folder name when the target mailbox is not `INBOX` (e.g. `NETENT\Support` on Exchange or `[Gmail]/All Mail` on Gmail). Returns `{ "data": ["INBOX", "Sent", ...], "message": "N folder(s) found" }`.

### `POST /api/admin/imap-accounts/:id/poll-now`

Trigger an immediate poll of this IMAP account (synchronous, runs in the request).

### `POST /api/imap/trigger-poll`

Trigger a poll of all enabled IMAP accounts.

**Auth:** `auth:agent`

---

## Report endpoints

`GET /api/reports/snapshot` requires only `auth:agent`.

The range-based report endpoints below require both `auth:agent` and `permission:can_view_reports`.

All range-based endpoints accept `from` and `to` query parameters (`YYYY-MM-DD`). Default: first day of the current month through today. If `from > to` they are swapped.

### `GET /api/reports/snapshot`

Live dashboard counters.

**Response `200`**

```json
{
  "data": {
    "new": 5,
    "waiting_for_reply": 8,
    "pending": 3,
    "replied": 11,
    "overdue": 2
  }
}
```

---

### `GET /api/reports/activity-summary`

Summary cards for the selected range. Counts are based on tickets that had any activity in range, bucketed by their current status/priority.

**Response `200`**

```json
{
  "data": {
    "new": 5,
    "waiting_for_reply": 8,
    "pending": 3,
    "replied": 11,
    "overdue": 2,
    "ticket_count": 24
  }
}
```

---

### `GET /api/reports/activity-by-agent`

Agent activity rows for the selected range.

**Response `200`**

```json
{
  "data": [
    {
      "agent_id": 1,
      "agent_name": "Jane Smith",
      "assigned": 9,
      "created": 2,
      "replies": 14,
      "notes": 5,
      "resolved": 3,
      "closed": 1
    }
  ]
}
```

---

### `GET /api/reports/time-to-close`

Average/min/max time from ticket creation to close for tickets closed in the selected range.

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `from` | date | |
| `to` | date | |
| `agent_id` | int | Optionally filter by an agent who recorded the closing system event |

**Response `200`** — `{ avg_minutes, min_minutes, max_minutes, count, tickets[] }`.

---

### `GET /api/reports/activity-volume`

Ticket activity over time, grouped by day, week, or month.

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `from` | date | |
| `to` | date | |
| `group_by` | string | `day` (default), `week`, `month` |

**Response `200`** — array of `{ period, count }`.

```json
{
  "data": [
    {
      "period": "2026-04-01",
      "created": 2,
      "customer_replies": 4,
      "agent_replies": 5,
      "internal_notes": 1,
      "system_events": 3,
      "total": 15
    }
  ]
}
```

---

## Knowledge base endpoints

### `GET /api/kb/categories`

List all KB categories ordered by `sort_order`.

**No auth required.**

**Response `200`** — array of `{ id, name, slug, sort_order, article_count }`.

---

### `POST /api/kb/categories`

Create a category.

**Auth:** `auth:agent`, `permission:can_manage_kb`

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `name` | string | yes |
| `sort_order` | int | no — default `0` |

**Response `201`** — full updated categories array.

---

### `PUT /api/kb/categories/:id`

Update a category name or sort order.

**Auth:** `auth:agent`, `permission:can_manage_kb`

**Request body:** `name` (string), `sort_order` (int) — all optional.

**Response `200`** — full updated categories array.

---

### `DELETE /api/kb/categories/:id`

Delete a category. Articles in it can be moved to another category or left uncategorised.

**Auth:** `auth:agent`, `permission:can_manage_kb`

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `move_to_category_id` | int\|null | Move articles to this category; omit to leave uncategorised |

**Response `200`** — full updated categories array.

---

### `GET /api/kb/articles`

List knowledge base articles.

**No auth required** (unauthenticated callers only see published articles; agents see all).

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | string | Full-text search on title and body |
| `category_id` | int | Filter by category |
| `is_published` | `1` | Filter to published only (useful for public search) |
| `page` | int | Default `1` |
| `per_page` | int | Default `20`, max `50` |

**Response `200`** — paginated list of article objects: `id`, `title`, `slug`, `category_id`, `category_name`, `is_published`, `view_count`, `created_at`, `updated_at`.

---

### `GET /api/kb/articles/:slug`

Get a single article by slug (or numeric ID). Increments `view_count`. Unpublished articles require an agent JWT.

**No auth required** (for published articles).

**Response `200`** — full article object including `body_html`.

---

### `POST /api/kb/articles`

Create a KB article (saved as draft by default).

**Auth:** `auth:agent`

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `title` | string | yes |
| `body_html` | string | yes |
| `category_id` | int | no |
| `is_published` | bool | no — default `false` |

**Response `201`** — article object.

---

### `PUT /api/kb/articles/:id`

Update an article.

**Auth:** `auth:agent`

**Request body:** `title`, `body_html`, `category_id`, `is_published` — all optional.

**Response `200`** — updated article object.

---

### `POST /api/kb/articles/:id/publish`

Publish a draft article.

**Auth:** `role:admin`

**Response `200`** — `data: null`.

---

### `DELETE /api/kb/articles/:id`

Soft-delete an article.

**Auth:** `role:admin`

**Response `200`** — `data: null`.

---

## Portal auth endpoints

Used by the customer-facing portal.

### `POST /api/portal/auth/magic-link`

Same as `POST /api/auth/magic-link` — sends a one-click login link to a customer email.

**No auth required.**

---

### `POST /api/portal/auth/verify-magic-link`

Exchange a magic-link token for a customer JWT pair.

**No auth required.**

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `token` | string | yes — from the link URL |
| `email` | string | yes |

Tokens are single-use and expire after 1 hour. The token is stored as a SHA-256 hash in the database.

**Response `200`** — `{ access_token, refresh_token, user }`.

---

### `POST /api/portal/auth/set-password`

Set a portal password for the first time (no existing password required).

**Auth:** `auth:customer`

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `password` | string | yes — min 8 chars |
| `password_confirm` | string | yes |

**Response `200`** — `data: null`.

---

### `POST /api/portal/auth/change-password`

Change an existing portal password. Requires current password.

**Auth:** `auth:customer`

**Request body**

| Field | Type | Required |
|-------|------|----------|
| `current_password` | string | yes |
| `password` | string | yes — min 8 chars |
| `password_confirm` | string | yes |

**Response `200`** — `data: null`.

---

## Portal ticket endpoints

All portal ticket endpoints require `auth:customer`. Customers can only access tickets where they are the requester or a CC participant.

### `POST /api/portal/tickets`

Create a new ticket from the customer portal.

**Body:**

| Field | Type | Required | Description |
|---|---|---|---|
| `subject` | string | yes | Ticket subject (max 255 chars) |
| `body` | string | yes | Plain text body (used for validation and email plain-text part) |
| `body_html` | string | no | Rich HTML body from the portal editor. Stored after server-side sanitisation. Falls back to `nl2br(htmlspecialchars($body))` if omitted. |

**Response `201`** — created ticket object.

**Notes:** Channel is set to `portal`, status to `new`, priority to `normal`. Auto-responder and agent notification emails are sent (subject to email suppression settings). Ticket number is generated atomically.

---

### `GET /api/portal/tickets`

List tickets accessible to the authenticated customer.

**Query parameters:** `page`, `per_page` (max 50).

**Response `200`** — paginated list of `{ id, ticket_number, subject, status, priority, reply_count, created_at, updated_at }`.

---

### `GET /api/portal/tickets/:id`

Get a single ticket with its public replies and attachments. Private (internal) notes are excluded. Returns `404` if the customer does not have access.

**Response `200`** — ticket object with `replies` (public only) and `attachments`.

---

### `POST /api/portal/tickets/:id/replies`

Post a reply from the customer. Cannot reply to a closed ticket.

**Request body**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `body` | string | yes | Plain text body (used for validation) |
| `body_html` | string | no | Rich HTML body from the portal editor. Stored after server-side sanitisation. Falls back to `nl2br(htmlspecialchars($body))` if omitted. |

**Response `201`** — reply object.

---

### `POST /api/portal/tickets/:id/attachments`

Upload a file attachment from a customer. Supports multiple files.

**Request:** `multipart/form-data` with `file` field(s).

**Response `201`** — array of attachment objects.

---

## Public support form endpoints

These endpoints power the public website support form at `#/login/support-form` and its embeddable `#/login/support-form?embed=1` mode.

### `GET /api/support-form/challenge`

Return a signed fallback human-verification challenge when reCAPTCHA v3 is not configured.

**Auth:** none

**Response `200`**

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "question": "What is 5 + 3?",
    "token": "base64payload.hmac"
  }
}
```

**Notes:** The token expires after 30 minutes and is bound to the requester IP address.

### `POST /api/support-form`

Create a new inbound-style ticket from the public website support form.

**Auth:** none

**Request:** `multipart/form-data`

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Requester name, max 255 chars |
| `email` | string | yes | Requester email |
| `subject` | string | yes | Ticket subject, max 255 chars |
| `message` | string | yes | Plain text message body |
| `file[]` | file[] | no | Zero or more attachments |
| `website` | string | no | Honeypot field; must remain empty |
| `started_at` | integer | yes | Client timestamp used for minimum-submit-time validation |
| `recaptcha_token` | string | conditional | Required when reCAPTCHA keys are configured |
| `human_check_token` | string | conditional | Required when reCAPTCHA is not configured |
| `human_check_answer` | string | conditional | Required when reCAPTCHA is not configured |

**Response `201`** — created ticket object.

**Notes:**
- Tickets created here use channel `web`.
- The initial customer message is stored as the first inbound reply on the ticket.
- Attachments are stored against that initial reply.
- The endpoint applies honeypot, minimum-submit-time, and server-side throttles per IP address and per email address.

---

## Calendar endpoints

### `GET /api/calendar/events`

**Auth:** `auth:agent`

Returns all tickets with a due date, optionally filtered to a date range. Used by the in-app Calendar view.

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `from` | `YYYY-MM-DD` | Start of range (inclusive). |
| `to` | `YYYY-MM-DD` | End of range (inclusive). |

**Response `200`**

```json
{
  "success": true,
  "data": [
    {
      "id": 1, "ticket_number": "HD-2026-04-08-0001", "subject": "Printer broken",
      "status": "open", "priority": "high",
      "due_at": "2026-04-10 09:00:00", "due_end": null, "due_all_day": 0,
      "customer_name": "Jane Smith"
    }
  ]
}
```

---

### `GET /api/calendar/token`

**Auth:** `auth:agent`

Generate (or regenerate) the authenticated agent's personal iCal subscription token and returns ready-to-use URLs. The token is a deterministic HMAC — no DB storage required.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "token": "abc123...",
    "agent_id": 7,
    "ical_url":   "https://support.example.com/api/calendar/ical?agent_id=7&token=abc123...",
    "webcal_url": "webcal://support.example.com/api/calendar/ical?agent_id=7&token=abc123..."
  }
}
```

---

### `GET /api/calendar/ical`

**Auth:** HMAC token (no JWT — designed for calendar app subscriptions)

Returns an iCal (`.ics`) feed of all open tickets with due dates for the agent identified by `agent_id`. Includes `VALARM` reminders 1 day and 1 hour before each due date. Each event has a `URL:` property linking directly to the ticket in the app.

**Query parameters**

| Parameter | Description |
|-----------|-------------|
| `agent_id` | Agent ID (integer) |
| `token` | HMAC-SHA256 token from `GET /api/calendar/token` |

**Response `200`** — `Content-Type: text/calendar; charset=utf-8`

**Response `401`** — plain text `Unauthorized` if token is invalid or agent is inactive.

---

## Version endpoints

**Auth:** `role:admin` for all endpoints.

### `GET /api/version`

Return the installed version from `version.json` in the repository root.

**Response `200`**

```json
{ "version": "1.0.0", "released": "2026-04-03", "description": "..." }
```

---

### `GET /api/version/latest`

Fetch `version.json` from the GitHub `main` branch server-side (via cURL / `file_get_contents`) and return it. Used by the Settings → General **Check for Updates** button to compare installed vs latest without the browser making a cross-origin request.

**Response `200`** — same shape as `GET /api/version`.

**Response `502`** — if GitHub is unreachable or returns unexpected data.

---

## Update endpoints

**Auth:** `role:admin` for all endpoints.

### `GET /api/update/preflight`

Run prerequisite checks before attempting an in-app update. Returns a list of checks with pass/fail status and fix instructions for any failures, including checks for overwriting existing files as well as writable directories.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "ready": true,
    "checks": [
      { "name": "PHP ZipArchive extension", "pass": true,  "detail": "Available",    "fix": "" },
      { "name": "HTTP download (cURL or allow_url_fopen)", "pass": true, "detail": "cURL available", "fix": "" },
      { "name": "Write permission: /public_html/", "pass": false, "detail": "Not writable", "fix": "chmod 755 ..." },
      ...
    ]
  }
}
```

Checks performed:
- PHP `zip` extension loaded
- HTTP download capability (`curl_exec` or `allow_url_fopen`)
- Write permission on `/` (app root), `/public_html/`, `/src/`, `/config/`, `/bin/`, `/database/`
- System temp directory writable
- ≥ 50 MB free disk space

---

### `POST /api/update/run`

Download the latest release from GitHub and apply it to the installation. Requires all preflight checks to be passing. Uses a file lock to prevent concurrent runs.

**Request body** — empty `{}`.

**Steps performed:**
1. Download `main` branch zip from GitHub
2. Extract to temp directory
3. Copy files over the installation (preserving `.env`, `storage/`, `vendor/`, `.git`, `install.lock`, `Makefile.local`)
4. Run `database/schema.sql` (idempotent — all `CREATE TABLE IF NOT EXISTS`)
5. Apply any new numbered migration files from `database/migrations/`, tracked in `schema_migrations` table (created automatically if absent); `001_initial.sql` is skipped as it is covered by `schema.sql`
6. Reset opcode cache (`opcache_reset()`) if available

**Response `200`**

```json
{
  "success": true,
  "data": {
    "log": [
      "Downloading update from GitHub…",
      "Downloaded 1234 KB.",
      "Extracting…",
      "Extracted successfully.",
      "Copying files…",
      "Copied 312 file(s).",
      "Updating database schema…",
      "Schema: 28 statement(s) executed.",
      "Checking for new migrations…",
      "  Applied: 015_new_feature.sql",
      "1 migration(s) applied.",
      "Opcode cache cleared.",
      "done"
    ]
  }
}
```

**Response `200` with `success: false`** — if the update fails mid-way; `data.log` contains the steps completed and the error message.

**Response `423`** — if another update is already in progress (lock held).
