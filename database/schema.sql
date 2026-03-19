-- ============================================================
-- Andrea Helpdesk - Database Schema
-- Run via: make db-migrate
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- Agents (staff who handle tickets)
-- ============================================================
CREATE TABLE IF NOT EXISTS agents (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(120) NOT NULL,
    email               VARCHAR(255) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    role                ENUM('admin','agent') NOT NULL DEFAULT 'agent',
    can_close_tickets   TINYINT(1) NOT NULL DEFAULT 1,
    can_delete_tickets  TINYINT(1) NOT NULL DEFAULT 0,
    can_edit_customers  TINYINT(1) NOT NULL DEFAULT 0,
    can_view_reports    TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_kb       TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_tags     TINYINT(1) NOT NULL DEFAULT 0,
    signature           TEXT NULL COMMENT 'Per-agent email signature HTML',
    page_size           TINYINT UNSIGNED NOT NULL DEFAULT 20 COMMENT 'Rows per page for tickets list and dashboard blocks (10/20/50)',
    theme               VARCHAR(20) NOT NULL DEFAULT 'light' COMMENT 'UI theme preference: light or dark',
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at       DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agents_email (email),
    INDEX idx_agents_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Customers (people who submit tickets)
-- ============================================================
CREATE TABLE IF NOT EXISTS customers (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(120) NOT NULL,
    email                   VARCHAR(255) NOT NULL,
    phone                   VARCHAR(40) NULL,
    company                 VARCHAR(120) NULL,
    notes                   TEXT NULL,
    portal_password_hash    VARCHAR(255) NULL COMMENT 'NULL means no portal password set',
    portal_token            VARCHAR(64) NULL COMMENT 'Magic link one-time token',
    portal_token_expires    DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME NULL,
    UNIQUE KEY uq_customers_email (email),
    INDEX idx_customers_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Ticket number sequences (for PREFIX-YYYY-MM-DD-NNNN format)
-- ============================================================
CREATE TABLE IF NOT EXISTS ticket_number_sequences (
    date_key    CHAR(10) NOT NULL COMMENT 'YYYY-MM-DD',
    last_seq    INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (date_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tickets
-- ============================================================
CREATE TABLE IF NOT EXISTS tickets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_number       VARCHAR(40) NOT NULL,
    subject             VARCHAR(255) NOT NULL,
    status              ENUM('new','open','waiting_for_reply','replied','pending','resolved','closed') NOT NULL DEFAULT 'new',
    priority            ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
    channel             ENUM('email','web','phone','portal') NOT NULL DEFAULT 'email',
    customer_id         INT UNSIGNED NOT NULL,
    assigned_agent_id   INT UNSIGNED NULL,
    original_message_id VARCHAR(512) NULL COMMENT 'Message-ID of first inbound email',
    last_message_id     VARCHAR(512) NULL COMMENT 'For In-Reply-To on outbound emails',
    reply_to_address    VARCHAR(255) NULL,
    parent_ticket_id    INT UNSIGNED NULL COMMENT 'Set for child/sub-tickets',
    merged_into_id      INT UNSIGNED NULL COMMENT 'Set on the losing ticket when merged',
    first_response_at   DATETIME NULL COMMENT 'Time of first agent reply (SLA)',
    closed_at           DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME NULL,
    UNIQUE KEY uq_tickets_number (ticket_number),
    INDEX idx_tickets_customer (customer_id),
    INDEX idx_tickets_agent (assigned_agent_id),
    INDEX idx_tickets_status (status),
    INDEX idx_tickets_created (created_at),
    INDEX idx_tickets_deleted (deleted_at),
    INDEX idx_tickets_deleted_status (deleted_at, status),
    INDEX idx_tickets_deleted_agent (deleted_at, assigned_agent_id),
    INDEX idx_tickets_original_msg (original_message_id(191)),
    CONSTRAINT fk_tickets_customer   FOREIGN KEY (customer_id)       REFERENCES customers(id),
    CONSTRAINT fk_tickets_agent      FOREIGN KEY (assigned_agent_id) REFERENCES agents(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_parent     FOREIGN KEY (parent_ticket_id)  REFERENCES tickets(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_merged     FOREIGN KEY (merged_into_id)    REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Ticket Participants (CC / multiple recipients)
-- ============================================================
CREATE TABLE IF NOT EXISTS ticket_participants (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id   INT UNSIGNED NOT NULL,
    email       VARCHAR(255) NOT NULL,
    name        VARCHAR(120) NULL,
    role        ENUM('to','cc','bcc') NOT NULL DEFAULT 'cc',
    customer_id INT UNSIGNED NULL COMMENT 'Linked if email matches a known customer',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_participant (ticket_id, email),
    CONSTRAINT fk_participants_ticket   FOREIGN KEY (ticket_id)   REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_participants_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Replies (all messages in a ticket thread)
-- ============================================================
CREATE TABLE IF NOT EXISTS replies (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id       INT UNSIGNED NOT NULL,
    author_type     ENUM('agent','customer','system') NOT NULL,
    agent_id        INT UNSIGNED NULL,
    customer_id     INT UNSIGNED NULL,
    body_html       MEDIUMTEXT NOT NULL,
    body_text       MEDIUMTEXT NULL,
    is_private      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Internal notes not sent to customer',
    direction       ENUM('inbound','outbound') NOT NULL,
    raw_message_id  VARCHAR(512) NULL COMMENT 'Message-ID header of this email',
    in_reply_to     VARCHAR(512) NULL COMMENT 'In-Reply-To header',
    email_sent_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_replies_ticket (ticket_id),
    INDEX idx_replies_message_id (raw_message_id(191)),
    CONSTRAINT fk_replies_ticket   FOREIGN KEY (ticket_id)   REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_replies_agent    FOREIGN KEY (agent_id)    REFERENCES agents(id) ON DELETE SET NULL,
    CONSTRAINT fk_replies_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Attachments
-- ============================================================
CREATE TABLE IF NOT EXISTS attachments (
    id                          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id                   INT UNSIGNED NOT NULL,
    reply_id                    INT UNSIGNED NULL,
    filename                    VARCHAR(255) NOT NULL COMMENT 'Original filename shown to user',
    stored_path                 VARCHAR(512) NOT NULL COMMENT 'Relative path under storage/attachments/',
    mime_type                   VARCHAR(100) NOT NULL,
    size_bytes                  INT UNSIGNED NOT NULL,
    download_token              VARCHAR(255) NULL,
    uploaded_by_agent_id        INT UNSIGNED NULL,
    uploaded_by_customer_id     INT UNSIGNED NULL,
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_attachments_ticket   FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_attachments_reply    FOREIGN KEY (reply_id)  REFERENCES replies(id) ON DELETE SET NULL,
    CONSTRAINT fk_attachments_agent    FOREIGN KEY (uploaded_by_agent_id)    REFERENCES agents(id) ON DELETE SET NULL,
    CONSTRAINT fk_attachments_customer FOREIGN KEY (uploaded_by_customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tags
-- ============================================================
CREATE TABLE IF NOT EXISTS tags (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(60) NOT NULL,
    UNIQUE KEY uq_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_tag_map (
    ticket_id   INT UNSIGNED NOT NULL,
    tag_id      INT UNSIGNED NOT NULL,
    PRIMARY KEY (ticket_id, tag_id),
    CONSTRAINT fk_ttm_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ttm_tag    FOREIGN KEY (tag_id)    REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Ticket Relations (symmetric many-to-many)
-- ============================================================
CREATE TABLE IF NOT EXISTS ticket_relations (
    ticket_a_id INT UNSIGNED NOT NULL,
    ticket_b_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (ticket_a_id, ticket_b_id),
    CONSTRAINT fk_rel_a FOREIGN KEY (ticket_a_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_rel_b FOREIGN KEY (ticket_b_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT chk_rel_no_self CHECK (ticket_a_id != ticket_b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- IMAP Accounts (multiple inbound email accounts)
-- ============================================================
CREATE TABLE IF NOT EXISTS imap_accounts (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                 VARCHAR(100) NOT NULL,
    host                 VARCHAR(255) NOT NULL,
    port                 SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    encryption           ENUM('ssl','tls','none') NOT NULL DEFAULT 'ssl',
    username             VARCHAR(255) NOT NULL,
    from_address         VARCHAR(255) NULL DEFAULT NULL COMMENT 'Override From address for outgoing emails (defaults to username if empty)',
    password             TEXT,
    folder               VARCHAR(100) NOT NULL DEFAULT 'INBOX',
    delete_after_import  TINYINT(1) NOT NULL DEFAULT 0,
    tag_id               INT UNSIGNED NULL,
    is_enabled           TINYINT(1) NOT NULL DEFAULT 1,
    last_connected_at    DATETIME NULL DEFAULT NULL,
    last_poll_at         DATETIME NULL DEFAULT NULL,
    last_poll_count      INT UNSIGNED NOT NULL DEFAULT 0,
    last_import_at       DATETIME NULL DEFAULT NULL COMMENT 'Last time at least one email was actually imported',
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Settings (runtime configurable key/value store)
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    key_name    VARCHAR(100) NOT NULL,
    value       TEXT NULL,
    type        ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string',
    group_name  VARCHAR(60) NOT NULL DEFAULT 'general',
    label       VARCHAR(120) NOT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Knowledge Base
-- ============================================================
CREATE TABLE IF NOT EXISTS knowledge_base_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    slug        VARCHAR(120) NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kbc_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS knowledge_base_articles (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id     INT UNSIGNED NULL,
    title           VARCHAR(255) NOT NULL,
    slug            VARCHAR(255) NOT NULL,
    body_html       MEDIUMTEXT NOT NULL,
    is_published    TINYINT(1) NOT NULL DEFAULT 0,
    author_agent_id INT UNSIGNED NULL,
    view_count      INT UNSIGNED NOT NULL DEFAULT 0,
    source_ticket_id INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    UNIQUE KEY uq_kba_slug (slug),
    FULLTEXT INDEX ft_kba_search (title, body_html),
    CONSTRAINT fk_kba_category FOREIGN KEY (category_id)      REFERENCES knowledge_base_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_kba_author   FOREIGN KEY (author_agent_id)   REFERENCES agents(id) ON DELETE SET NULL,
    CONSTRAINT fk_kba_ticket   FOREIGN KEY (source_ticket_id)  REFERENCES tickets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Refresh Tokens (JWT rotation)
-- ============================================================
CREATE TABLE IF NOT EXISTS refresh_tokens (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash  VARCHAR(64) NOT NULL COMMENT 'SHA-256 of raw token',
    agent_id    INT UNSIGNED NULL,
    customer_id INT UNSIGNED NULL,
    expires_at  DATETIME NOT NULL,
    revoked     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rt_hash (token_hash),
    INDEX idx_rt_agent (agent_id),
    CONSTRAINT fk_rt_agent    FOREIGN KEY (agent_id)    REFERENCES agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_rt_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Audit Log
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type      ENUM('agent','customer','system') NOT NULL,
    actor_id        INT UNSIGNED NULL,
    action          VARCHAR(80) NOT NULL,
    subject_type    VARCHAR(40) NOT NULL,
    subject_id      INT UNSIGNED NOT NULL,
    payload         JSON NULL,
    ip_address      VARCHAR(45) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_subject (subject_type, subject_id),
    INDEX idx_audit_actor   (actor_type, actor_id),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Default Settings
-- ============================================================
INSERT INTO settings (key_name, value, type, group_name, label) VALUES
-- General
('ticket_prefix',       'HD',               'string',  'general',  'Ticket Number Prefix'),
('timezone',            'Pacific/Auckland',  'string',  'general',  'Timezone'),
('date_format',         'd/m/Y H:i',         'string',  'general',  'Date/Time Format'),
('imap_poll_mode',      'cron',              'string',  'general',  'IMAP Polling Mode'),

-- Branding
('company_name',        'Andrea Helpdesk',   'string',  'branding', 'Company Name'),
('logo_url',            '',                  'string',  'branding', 'Logo URL'),
('favicon_url',         '',                  'string',  'branding', 'Favicon URL'),
('primary_color',       '#0d6efd',           'string',  'branding', 'Primary Color'),
('accent_color',        '#6610f2',           'string',  'branding', 'Accent Color'),
('custom_css',          '',                  'string',  'branding', 'Custom CSS'),

-- Email
('smtp_host',           '',                  'string',  'email',    'SMTP Host'),
('smtp_port',           '587',               'integer', 'email',    'SMTP Port'),
('smtp_username',       '',                  'string',  'email',    'SMTP Username'),
('smtp_password',       '',                  'string',  'email',    'SMTP Password'),
('smtp_from_address',   '',                  'string',  'email',    'From Email Address'),
('smtp_from_name',      'Andrea Helpdesk',   'string',  'email',    'From Name'),
('smtp_encryption',     'tls',               'string',  'email',    'SMTP Encryption (tls/ssl/none)'),
('reply_to_address',    '',                  'string',  'email',    'Reply-To Address'),
('global_signature',    '<p>--<br>Andrea Helpdesk</p>', 'string', 'email', 'Global Email Signature (HTML)'),
('auto_response_enabled','1',                'boolean', 'email',    'Enable Auto-Response'),
('auto_response_subject','Re: {{subject}} [{{ticket_number}}]', 'string', 'email', 'Auto-Response Subject'),
('auto_response_body',  '<p>Dear {{customer_name}},</p><p>Thank you for contacting us. Your ticket has been created with reference number <strong>{{ticket_number}}</strong>.</p><p>We will respond as soon as possible.</p><p>{{global_signature}}</p>', 'string', 'email', 'Auto-Response Body (HTML)'),

-- IMAP
('imap_host',           '',                  'string',  'imap',     'IMAP Host'),
('imap_port',           '993',               'integer', 'imap',     'IMAP Port'),
('imap_username',       '',                  'string',  'imap',     'IMAP Username'),
('imap_password',       '',                  'string',  'imap',     'IMAP Password'),
('imap_folder',         'INBOX',             'string',  'imap',     'IMAP Folder'),
('imap_encryption',     'ssl',               'string',  'imap',     'IMAP Encryption (ssl/tls/none)'),
('imap_delete_after_import', '0',            'boolean', 'imap',     'Delete Email After Import'),

-- Slack
('slack_enabled',       '0',                 'boolean', 'slack',    'Enable Slack Notifications'),
('slack_webhook_url',   '',                  'string',  'slack',    'Slack Webhook URL'),
('slack_channel',       '#helpdesk',         'string',  'slack',    'Slack Channel'),
('slack_on_new_ticket', '1',                 'boolean', 'slack',    'Notify on New Ticket'),
('slack_on_assign',     '1',                 'boolean', 'slack',    'Notify on Ticket Assignment'),
('slack_on_new_reply',  '1',                 'boolean', 'slack',    'Notify on New Customer Reply'),
('slack_unfurl_links',  '1',                 'boolean', 'slack',    'Show Link Previews'),
('slack_username',      '',                  'string',  'slack',    'Bot Display Name'),
('slack_icon_url',      '',                  'string',  'slack',    'Bot Icon Image URL'),
('slack_icon_emoji',    '',                  'string',  'slack',    'Bot Icon Emoji')

ON DUPLICATE KEY UPDATE label = VALUES(label);
