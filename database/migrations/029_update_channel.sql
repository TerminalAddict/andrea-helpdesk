INSERT INTO settings (key_name, value, type, group_name, label)
VALUES ('update_channel', 'stable', 'string', 'general', 'Update Channel')
ON DUPLICATE KEY UPDATE
    group_name = VALUES(group_name),
    type = VALUES(type),
    label = VALUES(label);
