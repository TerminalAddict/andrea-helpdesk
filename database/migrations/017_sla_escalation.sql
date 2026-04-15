ALTER TABLE tickets
    MODIFY COLUMN priority ENUM('low','normal','high','urgent','overdue') NOT NULL DEFAULT 'normal',
    ADD COLUMN last_attention_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER first_response_at,
    ADD COLUMN sla_high_notified_at DATETIME NULL DEFAULT NULL AFTER last_attention_at,
    ADD COLUMN sla_overdue_notified_at DATETIME NULL DEFAULT NULL AFTER sla_high_notified_at,
    ADD INDEX idx_tickets_attention (last_attention_at);

UPDATE tickets
SET last_attention_at = COALESCE(updated_at, created_at, NOW())
WHERE last_attention_at IS NULL OR last_attention_at = '0000-00-00 00:00:00';

INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('sla_enabled',            '0',   'boolean', 'general', 'Enable SLA Escalation'),
('sla_high_after_days',    '3',   'integer', 'general', 'Raise to High after days with no attention'),
('sla_overdue_after_days', '2',   'integer', 'general', 'Raise to Overdue after additional days with no attention'),
('sla_notify_scope',       'all', 'string',  'general', 'SLA Reminder Recipients'),
('sla_notify_agent_ids',   '[]',  'json',    'general', 'Specific agents for SLA reminders')
ON DUPLICATE KEY UPDATE
    type = VALUES(type),
    group_name = VALUES(group_name),
    label = VALUES(label);
