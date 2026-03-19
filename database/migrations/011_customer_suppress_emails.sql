-- Migration 011: Add suppress_emails flag to customers
ALTER TABLE customers
    ADD COLUMN suppress_emails TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'When 1, all outbound emails to this customer are suppressed'
        AFTER portal_token_expires;
