-- Add due date fields to tickets
ALTER TABLE tickets
    ADD COLUMN due_at      DATETIME     NULL DEFAULT NULL AFTER priority,
    ADD COLUMN due_end     DATETIME     NULL DEFAULT NULL AFTER due_at,
    ADD COLUMN due_all_day TINYINT(1)   NOT NULL DEFAULT 0  AFTER due_end,
    ADD INDEX  idx_tickets_due_at (due_at);
