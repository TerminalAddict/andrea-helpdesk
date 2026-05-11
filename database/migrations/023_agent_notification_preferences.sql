ALTER TABLE agents
    ADD COLUMN notification_preferences_json TEXT NULL
        COMMENT 'Per-agent notification type preferences as JSON' AFTER browser_notifications_enabled;

UPDATE agent_notifications
   SET type = 'ticket_sla_overdue'
 WHERE type = 'ticket_overdue';
