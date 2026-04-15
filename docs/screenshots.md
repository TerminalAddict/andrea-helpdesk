# Screenshots

A visual tour of Andrea Helpdesk. All screens are from the agent UI.

---

## Dashboard

![Dashboard](screenshots/Dashboard.png)

The dashboard is the first screen agents see after login. It shows five live counters at the top — **New Tickets**, **Waiting for Reply**, **Pending Tickets**, **Replied Tickets**, and **Overdue Tickets** — giving an at-a-glance health check of the queue.

Below the counters, the dashboard is split into three ticket lists: **Overdue Tickets**, **My Assigned Tickets** (tickets assigned to the current agent), and **Recently Updated** (across all agents). The overdue list is sorted by oldest `last_attention_at` first so the stalest tickets rise to the top. A search box at the top searches tickets and knowledge base articles simultaneously.

---

## Tickets

![Tickets](screenshots/Tickets.png)

The main ticket list shows all tickets the agent has access to. Columns include ticket number, subject/customer name, status badge, priority badge, tags, comment count, assigned agent, and created/updated timestamps. Tickets whose priority is **Overdue** are highlighted with a stronger red treatment so the assignee stands out immediately.

The filter bar at the top supports filtering by **status** (Active, New, Open, Waiting for Reply, Replied, Pending, Resolved, Closed), **priority**, **assigned agent**, and **tag**, as well as free-text search. The active ticket count is shown above the table. The navbar badge next to "Tickets" shows the count of active (non-resolved, non-closed) tickets at all times.

---

## Ticket Detail

![Ticket Detail](screenshots/Ticket_Detail.png)

The ticket detail view shows the full conversation thread for a ticket. Each message displays the sender name, timestamp, and a **Customer** or **Agent** badge. Private internal notes are visually distinguished so they are never confused with customer-visible replies.

System events — such as the email suppression audit trail visible here — appear inline in the thread as grey italicised entries, recording who made the change and when.

The reply composer is **collapsed by default**. Clicking the **Reply** or **Internal Note** button auto-expands it; a chevron toggle button in the composer header collapses it again. The composer includes an attachment button, a signature toggle checkbox, and a "Keep status" selector. The **To:** address is shown in the header when collapsed so the recipient is always visible.

The reply and internal note composer supports **@mention** — typing `@` opens a live-filtered dropdown of agents. Use arrow keys or click to select; the mention is inserted as a styled chip. Mentioned agents receive an email notification linking to the ticket. Works in both regular replies and internal notes.

Customer names in reply/message headers are clickable links that navigate directly to the customer's profile page.

The **Ticket Info** sidebar on the right shows status, priority, assigned agent, channel, created/updated timestamps, and the **Suppress emails** toggle. When suppression is active, all outbound customer emails for this ticket are silenced — the toggle is recorded as a system event each time it changes. If the ticket priority is **Overdue**, the sidebar also shows a prominent red overdue callout naming the assigned agent (or showing that the ticket is unassigned). The sidebar also shows the linked customer, tags (with a dropdown to select from the global tag list — only tags not already applied are shown; the dropdown is hidden when all tags are applied), attachments, CC participants, and related/parent tickets.

---

## New Ticket

![New Ticket](screenshots/New_Ticket.png)

Agents can create tickets manually using the New Ticket form. The **Customer Email** field is a live search — typing searches existing customers by name or email and pre-fills the **Customer Name** field. If no match is found, a new customer record is created on submission.

Other fields include **Subject**, **Priority** (Normal by default), **Channel** (Web by default), **Assign To**, **CC / Participants** (free-text or search, press Enter to add each address), **Message**, and **Attachments**.

---

## Customers

![Customers](screenshots/Customers.png)

The customer list shows all customer records with their name, email, phone, total ticket count, and the date they were first seen. A live search box filters by name or email. Clicking any row opens the customer detail view.

Two action buttons sit in the top-right: **New Customer** opens a modal to create a single customer record; **Import CSV** opens the bulk import modal. The import modal includes a **Download Template CSV** button that downloads a pre-filled example file. After import, the modal reports how many customers were created and lists any skipped rows with their reason (duplicate email, invalid email, or missing required fields).

---

## Customer Detail

![Customer Detail](screenshots/Customer_Detail.png)

The customer detail view is split into two panels. The left panel shows the customer's name, email, phone, company, and the date they became a customer. Below that, a **Portal Access** section lets agents send a magic-link portal invitation email or manually set a portal password for the customer.

The right panel lists the customer's tickets (ticket number, subject, status, priority, last updated) and a chronological feed of all replies this customer has posted across every ticket — useful for understanding their history at a glance. A **+ New Ticket** button opens the new ticket form pre-filled with this customer.

---

## Reports

![Reports](screenshots/Reports.png)

The reports screen provides a date-range report (From / To with a **Run Report** button). The default range is from the **first day of the current month** through today. The summary row mirrors the dashboard labels — **New**, **Waiting for Reply**, **Pending**, **Replied**, and **Overdue** — but counts only tickets that had activity inside the selected range. A sixth card shows how many tickets are in scope overall.

Below the summary, the **Avg. Time to Close** card measures tickets whose `closed_at` falls inside the selected range. The two tables underneath break activity down further: **Ticket Activity (Daily)** shows created tickets, customer replies, agent replies, internal notes, system events, and total activity per day; **Agent Activity** shows each agent's assigned, created, replied, noted, resolved, and closed counts for the period. Access to reports is controlled by the `view_reports` permission flag.

---

## Knowledge Base

![Knowledge Base](screenshots/Knowledge_Base.png)

The knowledge base is an internal article library. Articles are searchable by title and filterable by category. Each article row shows its title, last-updated timestamp, and edit/delete action buttons.

A **Categories** button opens a modal for managing article categories. The **+ New Article** button opens the article editor. Access to managing the knowledge base is controlled by the `manage_kb` permission flag.

---

## Admin — Agents

![Admin Agents](screenshots/Admin_Agents.png)

The agents admin screen lists all agent accounts with their role badge (**admin** in red, **agent** in blue), their currently granted permission tags, active/inactive status, and Edit / Deactivate action buttons. Admins can add new agents with the **+ Add Agent** button.

Permission tags visible per agent include: `close_tickets`, `delete_tickets`, `edit_customers`, `view_reports`, `manage_kb`, and `manage_tags`. Admin agents bypass all permission checks. Non-admin agents only have access to the actions corresponding to their granted permissions.

---

## Admin Settings — General

![Settings General](screenshots/Settings_General.png)

Route: `#/admin/settings/general`

The General settings section covers system-wide options: **Application Name**, **Application URL** (used in outbound email links), **Timezone**, **Date Format** (PHP `date()` format string), **Ticket Number Prefix**, **IMAP Polling Mode**, and the SLA controls used for inactivity-based escalation.

The SLA section lets admins enable or disable escalation, choose how many days without attention should raise a ticket to **High**, choose the additional number of days before it becomes **Overdue**, and decide whether reminder emails go to **all active agents** or only **specific agents**.

Below the save button, a **Version &amp; Updates** card shows the currently installed version number (fetched from the server) and a **Check for Updates** button. Clicking the button asks the server to fetch `version.json` from the GitHub `main` branch and compare it to the installed version. The result is shown inline — either "You are running the latest version" or "Version X.Y.Z is available".

Admins also get a silent background update check once per day while browsing the app. If a newer release is found, the navbar bell shows an unread notification that links directly back to this **Version &amp; Updates** card.

When an update is available, an **Update Now** button appears alongside the GitHub link. Clicking it opens a modal that first runs **preflight checks** — PHP ZipArchive extension, HTTP download capability, write permissions on all key directories, temp directory writable, and at least 50 MB free disk space. Each failed check shows specific instructions on how to fix it. If all checks pass, a **Proceed with Update** button is enabled. Clicking that downloads the latest zip from GitHub, extracts it, copies the new files over the installation (preserving `.env`, `storage/`, and `vendor/`), runs the database schema update, applies any new migrations, and clears the opcode cache. A live log is shown in a terminal-style panel, and on success the user is prompted to reload the page.

When IMAP Polling Mode is set to **Cron Job (recommended)**, a help box displays the exact crontab line to add, along with instructions for using `make cron-install-production` as a shortcut. The cron script uses a file lock so overlapping runs are safe. The same poller process also runs the SLA escalation scan, so cron/web-triggered polling doubles as the SLA scheduler.

---

## Admin Settings — Branding

![Settings Branding](screenshots/Settings_Branding.png)

Route: `#/admin/settings/branding`

The Branding settings section controls the visual identity of the helpdesk. **Logo URL** sets a custom logo displayed in the navbar. **Favicon URL** accepts `.ico`, `.png`, or `.svg` and is applied immediately to all browser tabs. **Primary Colour** is a hex value used for button and accent colours throughout the UI. **Support Email (displayed)** sets the contact address shown to customers in the portal and outbound emails.

---

## Admin Settings — Email / SMTP

![Settings Email SMTP](screenshots/Settings_Email_SMTP.png)

Route: `#/admin/settings/email`

The Email / SMTP settings section configures outbound mail. Fields include **SMTP Host**, **SMTP Port** (defaults to 587), **Encryption** (TLS/STARTTLS), **SMTP Username**, **SMTP Password** (leave blank to keep the current value — stored encrypted at rest), **From Email**, **From Name**, **Reply-To Email** (replies to this address create or update tickets), and **Email Signature** (supports the `{{agent_name}}` placeholder).

Two checkboxes control agent notification emails: **Notify agents on new ticket** and **Notify agents on new customer reply**. A **Test SMTP** button sends a test email using the saved configuration to verify delivery.

---

## Admin Settings — Slack

![Settings Slack](screenshots/Settings_Slack.png)

Route: `#/admin/settings/slack`

The Slack settings section configures the incoming webhook integration. The **Webhook URL** field accepts a Slack incoming webhook URL. **Channel** sets the target channel (e.g. `#helpdesk`). Individual notification events can be toggled: **Notify on new tickets**, **Notify on ticket assignment**, **Notify on new customer reply**, and **Show link previews** (controls whether Slack unfurls ticket URLs into rich preview cards).

The **Bot display name** and **Bot icon** fields customise how messages appear in Slack — the icon can be set as an image URL or an emoji code, with a quick-pick emoji palette provided.

---

## Admin — Tags

![Settings Tags](screenshots/Settings_Tags.png)

Route: `#/admin/tags`

The Tags screen is where agents with the `manage_tags` permission manage the global tag list. Existing tags can be renamed inline or deleted. New tags are created by typing a name and clicking **+ Add Tag**. Tags created here appear in the ticket list filter and the tag selector on individual tickets.

---

## My Profile

![Settings My Profile](screenshots/Settings_My_Profile.png)

Route: `#/my-profile`

The My Profile screen is per-agent and controls personal preferences. **Email Signature** accepts HTML with `{{agent_name}}` as a placeholder — this is the agent's personal signature prepended to the global signature set by an admin. The read-only **Global Signature** preview shows what will be appended after the personal signature.

Below the display preferences, a **Browser Notifications** section lets the agent enable or disable browser / OS notifications for this account and send a test notification. These alerts are used by the navbar notification center for new tickets, customer replies, overdue/SLA escalations, and update notices while the app is open.

**Display Preferences** includes **Theme** (Light or Dark) and **Tickets per page** (controls row count in the ticket list and dashboard blocks). A **Change Password** section lets agents update their own login password.
