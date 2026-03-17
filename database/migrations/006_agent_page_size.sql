-- Migration 006: Per-agent page size preference
ALTER TABLE agents
    ADD COLUMN page_size TINYINT UNSIGNED NOT NULL DEFAULT 20
        COMMENT 'Rows per page for tickets list and dashboard blocks (10/20/50)'
        AFTER signature;
