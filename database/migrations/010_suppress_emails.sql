-- Migration 010: Add suppress_emails flag to tickets
ALTER TABLE tickets
    ADD COLUMN suppress_emails TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'When 1, all outbound customer emails are suppressed for this ticket'
        AFTER merged_into_id;
