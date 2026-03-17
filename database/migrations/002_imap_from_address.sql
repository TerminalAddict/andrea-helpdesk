-- ============================================================
-- Migration 002: Add from_address to imap_accounts
-- Andrea Helpdesk
-- Date: 2026-03-17
-- ============================================================

ALTER TABLE imap_accounts
    ADD COLUMN from_address VARCHAR(255) NULL DEFAULT NULL
        COMMENT 'Override From address for outgoing emails on tickets tagged by this account. Defaults to username if empty.'
        AFTER username;
