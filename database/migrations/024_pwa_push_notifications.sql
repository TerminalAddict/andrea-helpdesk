CREATE TABLE IF NOT EXISTS agent_push_subscriptions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id         INT UNSIGNED NOT NULL,
    endpoint         TEXT NOT NULL,
    endpoint_hash    CHAR(64) NOT NULL,
    p256dh           VARCHAR(255) NOT NULL,
    auth             VARCHAR(255) NOT NULL,
    content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm',
    user_agent       VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_agent_push_endpoint_hash (endpoint_hash),
    INDEX idx_agent_push_agent (agent_id),
    CONSTRAINT fk_agent_push_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('push_vapid_public_key',  '', 'string', 'notifications', 'VAPID Public Key'),
('push_vapid_private_key', '', 'string', 'notifications', 'VAPID Private Key'),
('push_vapid_subject',     '', 'string', 'notifications', 'VAPID Subject')
ON DUPLICATE KEY UPDATE key_name = key_name;
