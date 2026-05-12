-- Migration 028: configurable chat WebSocket listen address

INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('chat_websocket_host', '127.0.0.1', 'string', 'chat', 'WebSocket Listen Host'),
('chat_websocket_port', '8090', 'integer', 'chat', 'WebSocket Listen Port')
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    group_name = VALUES(group_name),
    type = VALUES(type);
