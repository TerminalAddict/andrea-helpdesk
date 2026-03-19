-- Migration 013: Add settings rows that were missing from the initial seed
INSERT IGNORE INTO settings (key_name, value, type, group_name, label) VALUES
('notify_agent_on_new_ticket', '1', 'boolean', 'email',    'Notify Agents on New Ticket'),
('notify_agent_on_new_reply',  '1', 'boolean', 'email',    'Notify Agents on New Customer Reply'),
('support_email_display',      '',  'string',  'branding', 'Support Email (displayed)');
