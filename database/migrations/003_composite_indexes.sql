-- ============================================================
-- Migration 003: Add composite indexes to tickets table
-- Andrea Helpdesk
-- Date: 2026-03-17
-- ============================================================

ALTER TABLE tickets
    ADD INDEX idx_tickets_deleted_status (deleted_at, status),
    ADD INDEX idx_tickets_deleted_agent  (deleted_at, assigned_agent_id);
