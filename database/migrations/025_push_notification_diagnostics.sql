INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('push_last_send_failed_at', '', 'string', 'notifications', 'Last Push Send Failure At'),
('push_last_send_failure',   '', 'string', 'notifications', 'Last Push Send Failure')
ON DUPLICATE KEY UPDATE key_name = key_name;
