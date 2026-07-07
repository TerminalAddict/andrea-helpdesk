INSERT INTO settings (key_name, value, type, group_name, label) VALUES
('cache_enabled', '0', 'boolean', 'cache', 'Enable Application Cache'),
('cache_home', 'cache', 'string', 'cache', 'Cache Directory'),
('cache_ttl_seconds', '60', 'integer', 'cache', 'Default Cache TTL Seconds'),
('redis_host', '127.0.0.1', 'string', 'cache', 'Redis Host'),
('redis_port', '6379', 'integer', 'cache', 'Redis Port'),
('redis_prefix', 'andrea_helpdesk', 'string', 'cache', 'Redis Key Prefix'),
('redis_database', '1', 'integer', 'cache', 'Redis Database Number')
ON DUPLICATE KEY UPDATE
    group_name = VALUES(group_name),
    type = VALUES(type),
    label = VALUES(label);
