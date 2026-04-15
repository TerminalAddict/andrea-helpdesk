ALTER TABLE agents
    ADD COLUMN browser_notifications_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER theme,
    ADD COLUMN last_update_check_at DATETIME NULL AFTER last_login_at;

CREATE TABLE IF NOT EXISTS agent_notifications (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id     INT UNSIGNED NOT NULL,
    type        VARCHAR(60) NOT NULL,
    severity    ENUM('info','success','warning','danger') NOT NULL DEFAULT 'info',
    title       VARCHAR(180) NOT NULL,
    body        TEXT NULL,
    link        VARCHAR(255) NULL,
    data_json   TEXT NULL,
    dedupe_key  VARCHAR(191) NULL,
    read_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_agent_notifications_agent_created (agent_id, created_at),
    INDEX idx_agent_notifications_agent_read (agent_id, read_at),
    UNIQUE KEY uq_agent_notifications_dedupe (agent_id, dedupe_key),
    CONSTRAINT fk_agent_notifications_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
