INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('support_form_allowed_origins', '[]', 'json', 'support-form', 'Allowed Embed Origins')
ON DUPLICATE KEY UPDATE
    type = VALUES(type),
    group_name = VALUES(group_name),
    label = VALUES(label);
