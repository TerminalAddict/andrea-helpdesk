ALTER TABLE agents
    ADD COLUMN chat_handle VARCHAR(80) NULL COMMENT 'Stable lowercase @mention handle for internal chat' AFTER notification_preferences_json;

ALTER TABLE agents
    ADD UNIQUE KEY uq_agents_chat_handle (chat_handle);

CREATE TABLE IF NOT EXISTS chat_channels (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(80) NOT NULL,
    slug                VARCHAR(100) NOT NULL,
    description         VARCHAR(255) NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    retention_days      INT UNSIGNED NULL COMMENT 'NULL uses chat_default_channel_retention_days',
    created_by_agent_id INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chat_channels_slug (slug),
    INDEX idx_chat_channels_active (is_active),
    INDEX idx_chat_channels_created_by (created_by_agent_id),
    CONSTRAINT fk_chat_channels_created_by FOREIGN KEY (created_by_agent_id) REFERENCES agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_channel_members (
    channel_id        INT UNSIGNED NOT NULL,
    agent_id          INT UNSIGNED NOT NULL,
    can_post          TINYINT(1) NOT NULL DEFAULT 1,
    added_by_agent_id INT UNSIGNED NULL,
    added_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_id, agent_id),
    INDEX idx_chat_channel_members_agent (agent_id, channel_id),
    CONSTRAINT fk_chat_channel_members_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_channel_members_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_channel_members_added_by FOREIGN KEY (added_by_agent_id) REFERENCES agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_threads (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    thread_type     ENUM('direct') NOT NULL DEFAULT 'direct',
    agent_one_id    INT UNSIGNED NOT NULL COMMENT 'Lower agent id in the direct-message pair',
    agent_two_id    INT UNSIGNED NOT NULL COMMENT 'Higher agent id in the direct-message pair',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_message_at DATETIME NULL,
    UNIQUE KEY uq_chat_direct_pair (agent_one_id, agent_two_id),
    INDEX idx_chat_threads_agent_one (agent_one_id, last_message_at),
    INDEX idx_chat_threads_agent_two (agent_two_id, last_message_at),
    CONSTRAINT fk_chat_threads_agent_one FOREIGN KEY (agent_one_id) REFERENCES agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_threads_agent_two FOREIGN KEY (agent_two_id) REFERENCES agents(id) ON DELETE CASCADE,
    CONSTRAINT chk_chat_direct_pair_order CHECK (agent_one_id < agent_two_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_scope        ENUM('channel','direct') NOT NULL,
    channel_id           INT UNSIGNED NULL,
    thread_id            BIGINT UNSIGNED NULL,
    sender_agent_id      INT UNSIGNED NOT NULL,
    body_text            TEXT NOT NULL,
    body_rendered_html   TEXT NULL,
    metadata_json        JSON NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    edited_at            DATETIME NULL,
    deleted_at           DATETIME NULL,
    deleted_by_agent_id  INT UNSIGNED NULL,
    INDEX idx_chat_messages_channel_id_created (channel_id, id, created_at),
    INDEX idx_chat_messages_thread_id_created (thread_id, id, created_at),
    INDEX idx_chat_messages_sender_created (sender_agent_id, created_at),
    INDEX idx_chat_messages_created (created_at),
    CONSTRAINT fk_chat_messages_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_messages_thread FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_messages_sender FOREIGN KEY (sender_agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_messages_deleted_by FOREIGN KEY (deleted_by_agent_id) REFERENCES agents(id) ON DELETE SET NULL,
    CONSTRAINT chk_chat_message_scope_target CHECK (
        (message_scope = 'channel' AND channel_id IS NOT NULL AND thread_id IS NULL)
        OR
        (message_scope = 'direct' AND thread_id IS NOT NULL AND channel_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_message_mentions (
    message_id          BIGINT UNSIGNED NOT NULL,
    mentioned_agent_id INT UNSIGNED NOT NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, mentioned_agent_id),
    INDEX idx_chat_mentions_agent_created (mentioned_agent_id, created_at),
    CONSTRAINT fk_chat_mentions_message FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_mentions_agent FOREIGN KEY (mentioned_agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_message_reads (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id             INT UNSIGNED NOT NULL,
    message_scope        ENUM('channel','direct') NOT NULL,
    channel_id           INT UNSIGNED NULL,
    thread_id            BIGINT UNSIGNED NULL,
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_read_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chat_reads_channel (agent_id, message_scope, channel_id),
    UNIQUE KEY uq_chat_reads_direct (agent_id, message_scope, thread_id),
    INDEX idx_chat_reads_agent (agent_id),
    CONSTRAINT fk_chat_reads_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_reads_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_reads_thread FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE,
    CONSTRAINT chk_chat_read_scope_target CHECK (
        (message_scope = 'channel' AND channel_id IS NOT NULL AND thread_id IS NULL)
        OR
        (message_scope = 'direct' AND thread_id IS NOT NULL AND channel_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_channel_notification_preferences (
    agent_id       INT UNSIGNED NOT NULL,
    channel_id     INT UNSIGNED NOT NULL,
    notify_enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (agent_id, channel_id),
    CONSTRAINT fk_chat_channel_notify_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    CONSTRAINT fk_chat_channel_notify_channel FOREIGN KEY (channel_id) REFERENCES chat_channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('chat_enabled', '0', 'boolean', 'chat', 'Enable Internal Chat'),
('chat_default_channel_retention_days', '90', 'integer', 'chat', 'Default Channel Message Retention Days'),
('chat_direct_retention_days', '90', 'integer', 'chat', 'Direct Message Retention Days'),
('chat_max_message_length', '4000', 'integer', 'chat', 'Maximum Chat Message Length'),
('chat_allow_external_links', '1', 'boolean', 'chat', 'Allow External Links In Chat Messages'),
('chat_websocket_enabled', '1', 'boolean', 'chat', 'Enable WebSocket Chat Service'),
('chat_websocket_autostart', '1', 'boolean', 'chat', 'Automatically Start WebSocket Service'),
('chat_websocket_management_mode', 'cron', 'string', 'chat', 'WebSocket Service Management Mode'),
('chat_websocket_host', '127.0.0.1', 'string', 'chat', 'WebSocket Listen Host'),
('chat_websocket_port', '8090', 'integer', 'chat', 'WebSocket Listen Port'),
('chat_websocket_status', 'stopped', 'string', 'chat', 'WebSocket Service Status'),
('chat_websocket_pid', '', 'string', 'chat', 'WebSocket Service PID'),
('chat_websocket_last_seen_at', '', 'string', 'chat', 'WebSocket Last Heartbeat'),
('chat_websocket_last_started_at', '', 'string', 'chat', 'WebSocket Last Started Time'),
('chat_websocket_restart_requested', '0', 'boolean', 'chat', 'WebSocket Restart Requested'),
('chat_websocket_stop_requested', '0', 'boolean', 'chat', 'WebSocket Stop Requested')
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    group_name = VALUES(group_name),
    type = VALUES(type);
