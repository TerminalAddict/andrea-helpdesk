-- Migration 004: IMAP poll statistics columns
ALTER TABLE imap_accounts
    ADD COLUMN last_connected_at DATETIME NULL DEFAULT NULL AFTER is_enabled,
    ADD COLUMN last_poll_at      DATETIME NULL DEFAULT NULL AFTER last_connected_at,
    ADD COLUMN last_poll_count   INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_poll_at;
