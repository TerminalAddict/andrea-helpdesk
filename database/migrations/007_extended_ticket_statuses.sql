-- Migration 007: Extended ticket statuses
-- Adds: new, waiting_for_reply, replied
-- Changes default from 'open' to 'new'

ALTER TABLE tickets
    MODIFY COLUMN status
        ENUM('new','open','waiting_for_reply','replied','pending','resolved','closed')
        NOT NULL DEFAULT 'new';
