ALTER TABLE tickets
    ADD COLUMN created_by_agent_id INT UNSIGNED NULL AFTER customer_id,
    ADD INDEX idx_tickets_created_by_agent (created_by_agent_id),
    ADD CONSTRAINT fk_tickets_created_by_agent FOREIGN KEY (created_by_agent_id) REFERENCES agents(id) ON DELETE SET NULL;

UPDATE tickets t
LEFT JOIN (
    SELECT r.ticket_id, MIN(r.id) AS first_agent_reply_id
    FROM replies r
    WHERE r.author_type = 'agent'
    GROUP BY r.ticket_id
) fr ON fr.ticket_id = t.id
LEFT JOIN replies r ON r.id = fr.first_agent_reply_id
SET t.created_by_agent_id = r.agent_id
WHERE t.created_by_agent_id IS NULL
  AND t.channel = 'phone'
  AND r.agent_id IS NOT NULL;
