# Chat WebSocket Service With systemd

Andrea Helpdesk can manage chat WebSockets in two modes:

- `cron`: the built-in `bin/chat-supervisor.php` starts/stops/restarts `bin/chat-websocket-server.php`.
- `external`: systemd or another service manager owns the daemon. Andrea Helpdesk only displays status.

For production VPS/dedicated installs, systemd is the preferred option.

## 1. Configure Andrea Helpdesk

Open `#/admin/settings/chatservice` and set:

- **Enable internal chat**: on
- **Enable WebSocket chat service**: on
- **WebSocket management mode**: `External system service`
- **WebSocket listen host**: `127.0.0.1`
- **WebSocket listen port**: choose a free port, default `8090`

If multiple Andrea Helpdesk installs run on the same server, each install needs its own WebSocket listen port. The daemon reads the host/port from settings. Environment variables still override settings when you need service-manager-level control:

```bash
CHAT_WEBSOCKET_HOST=127.0.0.1
CHAT_WEBSOCKET_PORT=8090
```

Use a hostname, IPv4 address, or bracketed IPv6 address only for the listen host. Do not enter `http://`, `ws://`, `host:port`, paths, or whitespace. The browser-facing `/ws/chat` URL is handled by your Apache/Nginx reverse proxy.

## 2. Create The systemd Unit

Create `/etc/systemd/system/andrea-chat-websocket.service`:

```ini
[Unit]
Description=Andrea Helpdesk Chat WebSocket Server
After=network.target mysql.service

[Service]
Type=simple
WorkingDirectory=/var/www/andrea-helpdesk
ExecStart=/usr/bin/php bin/chat-websocket-server.php
Restart=always
RestartSec=5
User=www-data
Group=www-data

[Install]
WantedBy=multi-user.target
```

Adjust `WorkingDirectory`, `User`, and `Group` for your install.

## 3. Enable And Start

```bash
sudo systemctl daemon-reload
sudo systemctl enable andrea-chat-websocket
sudo systemctl start andrea-chat-websocket
sudo systemctl status andrea-chat-websocket
```

Logs are written to:

```bash
storage/logs/chat-websocket.log
```

The daemon writes heartbeat/status settings and:

```bash
storage/runtime/chat-websocket.pid
```

## 4. Reverse Proxy

The browser connects to `/ws/chat`; your web server must proxy that path to the local daemon.

Apache:

```apache
ProxyPreserveHost On
ProxyPass "/ws/chat"  "ws://127.0.0.1:8090/ws/chat"
ProxyPassReverse "/ws/chat"  "ws://127.0.0.1:8090/ws/chat"
```

Replace `8090` with this install's configured WebSocket listen port.

Required modules:

```bash
sudo a2enmod proxy proxy_wstunnel rewrite
sudo systemctl reload apache2
```

Nginx:

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

Replace `8090` with this install's configured WebSocket listen port.

## 5. Security Notes

- The WebSocket daemon only accepts authenticated active agents.
- Invalid, expired, customer, or inactive-agent JWTs are rejected.
- Browser WebSocket handshakes with an `Origin` header must come from the configured app host.
- Typing/read/message events are checked against channel membership or direct-message participation before broadcast.
- Admin UI start/stop/restart buttons do not shell out in external mode.
- Keep the daemon bound to `127.0.0.1` unless you have a specific network design and firewall rules.
