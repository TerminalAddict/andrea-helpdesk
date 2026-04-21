-- Migration 020: Track outbound delivery failures on tickets
ALTER TABLE tickets
    ADD COLUMN email_delivery_failed_at DATETIME NULL AFTER suppress_emails,
    ADD COLUMN email_delivery_failed_recipient VARCHAR(255) NULL AFTER email_delivery_failed_at,
    ADD COLUMN email_delivery_failed_summary VARCHAR(255) NULL AFTER email_delivery_failed_recipient;
