-- Migration 008: Slack appearance and link preview settings
-- Adds: slack_on_new_reply, slack_unfurl_links, slack_icon_url, slack_icon_emoji

INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('slack_on_new_reply', '1', 'boolean', 'slack', 'Notify on New Customer Reply'),
('slack_unfurl_links', '1', 'boolean', 'slack', 'Show Link Previews'),
('slack_icon_url',     '',  'string',  'slack', 'Bot Icon Image URL'),
('slack_icon_emoji',   '',  'string',  'slack', 'Bot Icon Emoji')
ON DUPLICATE KEY UPDATE label = VALUES(label);
