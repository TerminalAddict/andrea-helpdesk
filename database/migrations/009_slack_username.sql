-- Migration 009: Slack bot display name setting
-- Adds: slack_username

INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('slack_username', '', 'string', 'slack', 'Bot Display Name')
ON DUPLICATE KEY UPDATE label = VALUES(label);
