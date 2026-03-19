-- Migration 012: Add app_url to settings table (was missing from initial seed)
INSERT IGNORE INTO settings (key_name, value, type, group_name, label)
VALUES ('app_url', '', 'string', 'general', 'Application URL');
