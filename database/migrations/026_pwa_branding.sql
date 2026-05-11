INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('pwa_icon_url', '', 'string', 'notifications', 'PWA / Notification Icon URL')
ON DUPLICATE KEY UPDATE label = VALUES(label);
