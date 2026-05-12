# Chat WebSocket Reverse Proxy

Andrea Helpdesk serves the SPA over normal HTTP(S), while the chat daemon listens locally. Proxy `/ws/chat` to the daemon.

Default daemon endpoint:

```text
127.0.0.1:8090/ws/chat
```

The listen host and port are configurable in `#/admin/settings/chatservice`. If you run multiple Andrea Helpdesk installs on the same server, keep the host as `127.0.0.1` and assign a different port to each install, then update that site's vhost proxy to match.

The saved listen host must be a hostname, IPv4 address, or bracketed IPv6 address, not a URL, `host:port` pair, or filesystem path. The daemon also rejects browser WebSocket handshakes whose `Origin` host does not match the configured app host.

## Apache

```apache
ProxyPreserveHost On
ProxyPass "/ws/chat"  "ws://127.0.0.1:8090/ws/chat"
ProxyPassReverse "/ws/chat"  "ws://127.0.0.1:8090/ws/chat"
```

Replace `8090` with the configured WebSocket listen port for that install.

Enable modules:

```bash
sudo a2enmod proxy proxy_wstunnel rewrite
sudo systemctl reload apache2
```

## Nginx

```nginx
location /ws/chat {
    proxy_pass http://127.0.0.1:8090/ws/chat;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 3600;
}
```

Replace `8090` with the configured WebSocket listen port for that install.

## Cron-Managed Mode

If you use the built-in cron supervisor, install the normal Andrea Helpdesk cron:

```bash
* * * * * php /path/to/helpdesk/bin/cron.php >> /path/to/helpdesk/storage/logs/cron.log 2>&1
```

`bin/cron.php` runs:

- `bin/chat-supervisor.php`
- `bin/imap-poll.php`

The supervisor starts, stops, and restarts `bin/chat-websocket-server.php` only when `chat_websocket_management_mode = cron`.

On restart or stale-daemon recovery, the supervisor sends `SIGTERM` and waits briefly for the old process to exit before it starts a replacement. This prevents duplicate daemons competing for the same listen port.

## Retention Prune CLI

Admins can preview or run chat retention pruning from the admin UI. The same operation is available from CLI:

```bash
php bin/chat-prune.php --dry-run
php bin/chat-prune.php --run
php bin/chat-prune.php --scope=channel --channel-id=1 --run
php bin/chat-prune.php --scope=direct --run
```
