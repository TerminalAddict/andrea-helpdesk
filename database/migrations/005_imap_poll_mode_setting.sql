-- Migration 005: IMAP polling mode setting
INSERT IGNORE INTO settings (key_name, value, type, group_name, label)
VALUES ('imap_poll_mode', 'cron', 'string', 'general', 'IMAP Polling Mode');
