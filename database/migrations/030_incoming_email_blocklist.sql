INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('incoming_email_blocklist', '[]', 'json', 'email', 'Blocked Incoming Email Addresses'),
('incoming_email_block_message', 'Your email has not been accepted by this helpdesk. This ticket has been closed automatically.', 'string', 'email', 'Blocked Incoming Email Response')
ON DUPLICATE KEY UPDATE
    group_name = VALUES(group_name),
    type = VALUES(type),
    label = VALUES(label);
