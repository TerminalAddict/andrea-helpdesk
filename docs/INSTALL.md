# Installation

This guide covers the three supported install paths for Andrea Helpdesk:

- [command-line install on a VPS or dedicated server](#fastest-vps-path)
- [FTP / SFTP upload for shared hosting](#2-ftp--sftp-install)
- [browser-based setup through `/install/`](#3-browser-installer-walkthrough)

If you just want the fastest path:

- use **Command Line Install** if you have shell access and can point the site document root at `public_html/`
- use **FTP + Web Installer** if you are on shared hosting or cannot point the document root at `public_html/`

After installation, see [PWA and Push Notifications](PWA.md) to configure browser push notifications and install Andrea Helpdesk on desktop, Android, or iOS devices.

## Fastest VPS Path

If you want to watch the CLI install flow first:

- <a href="./videos/andrea-helpdesk-installation-cli.mp4" target="_blank" rel="noopener noreferrer">Watch the local CLI installation video</a>
- <a href="./videos/andrea-helpdesk-installation-cli-remote.mp4" target="_blank" rel="noopener noreferrer">Watch the remote CLI installation video</a>

### Step 1: Create the database

```bash
mysql \
  -u db_admin_uuser \
  -p \
  -e "
    CREATE DATABASE \`andrea-helpdesk\`;
    GRANT ALL PRIVILEGES
      ON \`andrea-helpdesk\`.*
      TO 'andrea-helpdesk-user'@'localhost'
      IDENTIFIED BY 'andrea-helpdesk-pass';
    FLUSH PRIVILEGES;
  "
```

Do **not** use the example database names, usernames, or passwords shown above. Replace all of them with your own values.

### Step 2: Run the installer

```bash
wget -qO - https://www.andreahelpdesk.com/installer/ | bash
```

The CLI installer is interactive. It reads answers from your terminal even when the script itself is piped into `bash`.

If you prefer to download it first:

```bash
wget https://www.andreahelpdesk.com/installer/install-cli.sh
bash install-cli.sh
```

If you want to read the installer before running it, open:

```text
https://www.andreahelpdesk.com/installer/
```

What the CLI installer does:

1. prints the installer source and asks for confirmation before doing any work
2. asks whether this is a **local** install or a **remote** SSH-driven install
3. checks prerequisites such as:
   - `git`
   - `php`
   - `composer`
   - `make`
   - required PHP extensions
4. asks for the repository URL and ref to install
5. asks for the site document root and stops if it cannot be `public_html/`
6. if the chosen install directory already exists and is not empty, warns clearly and asks twice before clearing it
7. asks for application, database, storage, and admin details
8. validates database access before writing `.env`
9. writes `.env`
10. installs dependencies, runs migrations, seeds the initial admin, fetches frontend assets, and installs the cron job
11. performs final verification of the install and prints next steps

---

## Requirements

Minimum server requirements:

- PHP 8.1 or newer
- MySQL 8.0 or newer
- Apache with `mod_rewrite` enabled, or equivalent web-server routing
- PHP extensions:
  - `pdo_mysql`
  - `mbstring`
  - `openssl`
- Recommended / optional:
  - `imap` for inbound email polling
  - `curl` or `allow_url_fopen` for installer asset downloads and update checks
  - cron access for the Andrea background runner (`bin/cron.php`), which handles IMAP/SLA polling, chat WebSocket supervision, and retention pruning

Directory layout requirements:

- `public_html/` must be the web document root
- `STORAGE_PATH` must be an absolute path outside `public_html/`
- the app must be able to write:
  - `.env`
  - the storage directory
  - `storage/logs`
  - `storage/attachments`

---

## Install Paths

### 1. Command Line Install

Use this when:

- you have shell access
- you can run Composer
- your web server can point the site document root at `public_html/`

Before you run the installer, create a database and database user for Andrea Helpdesk.

Example:

```bash
mysql \
  -u db_admin_uuser \
  -p \
  -e "
    CREATE DATABASE \`andrea-helpdesk\`;
    GRANT ALL PRIVILEGES
      ON \`andrea-helpdesk\`.*
      TO 'andrea-helpdesk-user'@'localhost'
      IDENTIFIED BY 'andrea-helpdesk-pass';
    FLUSH PRIVILEGES;
  "
```

Do **not** use the example database names, usernames, or passwords shown above. Replace all of them with your own values.

If you want the guided Bash installer, use:

```bash
wget -qO - https://www.andreahelpdesk.com/installer/ | bash
```

If you prefer downloading the script first:

```bash
wget https://www.andreahelpdesk.com/installer/install-cli.sh
bash install-cli.sh
```

If you want to inspect the installer source in a browser first:

```text
https://www.andreahelpdesk.com/installer/
```

The installer will stop if the document root cannot point to `public_html/`. In that situation, use the FTP / SFTP flow instead.

#### What The CLI Installer Supports

The Bash installer supports two modes.

##### Local Mode

Use local mode when the application will run on the same machine where you launch the installer.

In local mode, the installer:

- clones the repository locally
- writes `.env` locally
- runs Composer locally
- runs `make db-migrate`
- runs `make db-seed`
- runs `make fetch-assets`
- runs `make cron-install-local`
- verifies the local install and probes `APP_URL`

##### Remote Mode

Use remote mode when you want to deploy to another server over SSH.

In remote mode, the installer:

- checks SSH access first
- collects `REMOTE_USER`, `PROD_HOST`, and `REMOTE_PATH`
- generates `Makefile.local`
- clones the repository locally into a working directory
- writes `.env` locally and copies it to the remote host
- deploys the code with `make deploy`
- runs remote `make db-migrate`
- runs remote `make db-seed`
- runs `make cron-install-production`
- verifies the remote application layout, database state, and `APP_URL`

Remote mode currently expects SSH key-based or agent-backed access.

#### What The CLI Installer Checks Before Installing

Before it writes configuration or runs setup commands, the CLI installer checks:

- required commands
- PHP version
- required PHP extensions
- write access to the local working path
- SSH access in remote mode
- document root layout
- database connectivity
- storage-path validity

It also explicitly stops if:

- the install directory is not empty and you choose not to let the installer clear it
- the document root does not point to `public_html/`
- the database cannot be reached
- the storage path is inside the document root

#### CLI Install Walkthrough

The interactive CLI flow is:

1. confirm the installer should continue
2. choose `local` or `remote`
3. enter repository URL and ref
4. enter install path or remote path details
5. enter the document root
6. enter `APP_URL`, timezone, and JWT secret
7. enter database settings
8. enter `STORAGE_PATH`
9. enter the initial admin account details
10. let the installer validate and execute the setup steps

#### Manual Command-Line Install

If you do not want the guided Bash installer, you can still install manually.

#### Step 1: Place the application on the server

Example target path:

```bash
/var/www/andrea-helpdesk
```

If you are using Git:

```bash
git clone <your-repo-or-release-source> /var/www/andrea-helpdesk
cd /var/www/andrea-helpdesk
```

If you are uploading a release archive manually, extract it there and `cd` into the project root.

#### Step 2: Install PHP dependencies

```bash
composer install --no-dev --optimize-autoloader
```

#### Step 3: Create `.env`

```bash
cp .env.example .env
```

Edit `.env` and set at least:

- `APP_URL`
- `APP_TIMEZONE`
- `JWT_SECRET`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `STORAGE_PATH`
- `ADMIN_NAME`
- `ADMIN_EMAIL`
- `ADMIN_PASSWORD`

Important notes:

- `JWT_SECRET` should be a long random value, at least 32 characters
- `STORAGE_PATH` must be outside `public_html/`
- `ADMIN_PASSWORD` is used by `php bin/seed.php`; clear or rotate it afterward if that matters to your process

#### Step 4: Create the storage directories

Example:

```bash
mkdir -p /var/www/andrea-helpdesk-storage/attachments
mkdir -p /var/www/andrea-helpdesk-storage/logs
touch /var/www/andrea-helpdesk-storage/logs/app.log
touch /var/www/andrea-helpdesk-storage/logs/imap.log
```

#### Step 5: Run database setup

```bash
php bin/migrate.php
php bin/seed.php
```

`php bin/migrate.php` creates or updates the schema.

`php bin/seed.php` creates the initial admin agent using the `ADMIN_*` values from `.env`.

#### Step 6: Fetch frontend assets

```bash
make fetch-assets
```

This downloads the self-hosted frontend assets used by the app:

- Bootstrap
- Bootstrap Icons
- jQuery
- DOMPurify
- Quill

#### Step 7: Point the web root to `public_html/`

Your vhost or site config should serve:

```text
/var/www/andrea-helpdesk/public_html
```

not the project root.

If your environment cannot point the document root there, stop and use the FTP / SFTP workflow instead:

```text
https://docs.andreahelpdesk.com/install/#2-ftp--sftp-install
```

#### Step 8: Log in

Open:

```text
https://your-domain.example/
```

and sign in with the admin email and password you set in `.env`.

#### Admin Password Recovery

If you later forget an admin password, use the built-in recovery command:

```bash
make reset-admin-password
```

That command:

- lists admin accounts only
- lets you choose which admin to reset
- prompts for a new password twice
- updates the password hash safely
- revokes that admin's existing refresh-token sessions

You can also run the script directly:

```bash
php bin/reset-admin-password.php
```

#### Step 9: Add cron for background jobs

Recommended cron:

```cron
* * * * * php /var/www/andrea-helpdesk/bin/cron.php >> /var/www/andrea-helpdesk-storage/logs/cron.log 2>&1
```

This handles:

- inbound email polling
- SLA escalation checks
- internal chat WebSocket supervision when chat is set to cron-managed mode
- daily internal chat retention pruning
- log trimming

#### What The CLI Installer Verifies At The End

After the install steps finish, the CLI installer performs a final verification pass. It checks:

- `.env` exists
- Composer dependencies exist
- frontend assets exist
- storage directories and log files exist
- the database schema exists
- the admin agent record exists
- `APP_URL` responds over HTTP

If the HTTP check fails but the filesystem and database checks pass, the installer reports that the application is probably installed correctly but the web server or document-root configuration still needs attention.

---

### 2. FTP / SFTP Install

Use this when you do not have Composer or shell access on the hosting account.

- <a href="./videos/andrea-helpdesk-installation-FTP.mp4" target="_blank" rel="noopener noreferrer">Watch the FTP / SFTP installation video</a>

#### Step 1: Prepare the upload locally

Preferred option:

- <a href="https://github.com/TerminalAddict/andrea-helpdesk/releases" target="_blank" rel="noopener noreferrer">Download the latest packaged release from GitHub Releases</a>

That package already includes production Composer dependencies and bundled frontend assets, so you can upload it directly by FTP / SFTP and continue with the browser installer.

If you are uploading from your own local checkout instead of a packaged release:

From your local checkout:

```bash
composer install --no-dev --optimize-autoloader
```

That ensures the `vendor/` directory is available before upload.

#### Step 2: Upload the project files

Upload the full project to your hosting account, including:

- `public_html/`
- `src/`
- `config/`
- `database/`
- `bin/`
- `vendor/`

The domain or subdomain should point at the uploaded `public_html/` directory.

Example layout:

```text
/home/example/support.example.com/
├── bin
├── config
├── database
├── public_html   ← document root
├── src
├── vendor
└── ../andrea-helpdesk-storage
```

#### Step 3: Create a storage directory outside the web root

Create a writable folder outside `public_html/`, for example:

```text
/home/example/andrea-helpdesk-storage
```

It should contain or allow creation of:

- `attachments/`
- `logs/`

If your host does not let PHP create folders automatically, create them manually.

#### Step 4: Run the browser installer

Open:

```text
https://your-domain.example/install/
```

Then complete the installer steps:

1. requirements check
2. database connection
3. application settings and admin account
4. install confirmation
5. completion log

The installer writes:

- `.env`
- database schema
- admin agent account
- `install.lock`

If the host blocks Composer or asset downloads, the installer will complete with warnings rather than failing hard. That is expected on some shared-hosting systems when you already uploaded `vendor/`.

#### Step 5: Restrict or remove the installer

After the install finishes:

- delete `public_html/install/`, or
- restrict it so it cannot be accessed publicly

The lock file prevents re-running, but removing or restricting the installer is still the right operational step.

---

### 3. Browser Installer Walkthrough

This section documents the `/install/` wizard itself.

#### Step 1: Requirements

The installer checks:

- PHP version
- required extensions
- download capability (`curl` or `allow_url_fopen`)
- schema file presence
- project-root writability
- vendor parent-directory writability
- whether `exec()` is available for Composer

Notes:

- `ext-imap` is shown as optional in the installer so the app can still be installed without email polling on day one
- `exec()` is also treated as optional because many shared hosts disable it

#### Step 2: Database Connection

Fields:

- `Host`
- `Port`
- `Database Name`
- `Username`
- `Password`

The installer tests the connection before allowing you to continue.

#### Step 3: Application Settings

General fields:

- `App URL`
- `Timezone`
- `Storage Path`

Admin account fields:

- `Name`
- `Email`
- `Password`
- `Confirm Password`

`Storage Path` must be an absolute server path outside `public_html/`.

#### Step 4: Ready To Install

The installer shows a summary of:

- app URL
- timezone
- storage path
- database target
- admin email

Click **Run Installation** to execute the install.

#### Step 5: Installation Log

The installer logs each step, including:

- directory creation
- `.env` creation
- schema creation
- admin creation
- frontend asset download
- Composer status
- lock file creation

If there are warnings but no errors, the install is usable, but you should still address those warnings before going live.

---

## Install Screenshots

These are mock screenshots based on the real installer flow. They are intentionally illustrative rather than literal captures.

### CLI Install

![CLI Install](screenshots/install/install-cli.png)

### FTP / Shared Hosting Layout

![FTP Upload Layout](screenshots/install/install-ftp.png)

### Web Installer — Requirements

![Web Installer Requirements](screenshots/install/install-web-requirements.png)

### Web Installer — Database

![Web Installer Database](screenshots/install/install-web-database.png)

### Web Installer — Settings

![Web Installer Settings](screenshots/install/install-web-settings.png)

### Web Installer — Complete

![Web Installer Complete](screenshots/install/install-web-complete.png)

---

## Shared Hosting Notes

Some shared-hosting environments behave differently from VPS installs:

- `exec()` may be disabled
- Composer may not be available
- PHP may not be able to overwrite account-owned files during in-app updates
- cron may need to be configured from the hosting control panel instead of shell

That usually means:

- use the full GitHub release package for FTP/SFTP installs because it includes `vendor/`
- use `/install/` for setup
- use manual FTP/SFTP deployment for upgrades if the in-app updater preflight reports overwrite or permission failures

The in-app updater now prefers the full GitHub release package for the configured update channel and can update packaged PHP dependencies, including Web Push libraries, without Composer. If you override the updater to use a source ZIP without `vendor/`, Composer must be available and executable by PHP.

The default update channel is **stable**. Administrators can opt into **development** releases from `#/admin/settings/general` → **Version & Updates**. Development releases are prerelease builds intended for testing. If a development install is switched back to stable, it will not downgrade; it waits until the stable release reaches or exceeds the installed development version.

Installs older than `1.4.9` must update to `1.4.9` before updating to newer releases. Version `1.4.9` is the updater bridge release that adds full release package and packaged `vendor/` dependency support.

Do not solve updater problems by making the app tree world-writable.

---

## Post-Install Checklist

After any install method:

1. Log in as the admin user.
2. Set branding, SMTP, and IMAP settings.
3. Confirm `APP_URL` is correct in **Admin → Settings → General**.
4. Configure cron for `bin/cron.php`.
5. Send a test SMTP message.
6. Add an IMAP account and run **Poll Now**.
7. Configure inbound sender blocking from **Admin → Settings → Email / SMTP** if you need to reject exact senders or domains. Blocked email receives only the configured blocked-sender response, is immediately closed, and does not create agent, Slack, in-app, or push notifications.
8. Configure application caching from **Admin → Settings → Cache** if you want Redis-backed read caching. The page checks whether php-redis is installed, whether Redis is reachable, and whether the file-cache fallback directory is writable.
9. Configure browser push notifications and PWA install behaviour from **Admin → Settings → Notifications** if you want desktop/mobile OS alerts. The PWA uses the configured **Application Name** and optional **PWA / Notification Icon URL**; when that icon is empty it falls back to Branding → **Favicon URL**.
10. If you enable internal chat, configure channels and the WebSocket service from **Admin → Settings → Chat Service**. If this server hosts multiple Andrea Helpdesk installs, give each install a unique WebSocket listen port and update each vhost `/ws/chat` proxy to match. For production external service mode, see `docs/chat-websocket-systemd.md`.
11. Remove or restrict `public_html/install/`.

## Optional Redis Cache

Andrea Helpdesk can use Redis through the PHP `redis` extension to cache short-lived read query results. Enable it from `#/admin/settings/cache`.

Recommended Debian/Ubuntu setup:

```bash
sudo apt install redis-server php-redis
sudo systemctl enable --now redis-server
sudo systemctl restart apache2
redis-cli -h 127.0.0.1 -p 6379 ping
```

Other package managers commonly use these packages:

| System | Packages |
|--------|----------|
| RHEL/Fedora | `sudo dnf install redis php-pecl-redis` |
| older CentOS/YUM | `sudo yum install redis php-pecl-redis` |
| Arch | `sudo pacman -S redis php-redis` |
| openSUSE | `sudo zypper install redis php-redis` |
| Gentoo | `sudo emerge dev-db/redis dev-php/pecl-redis` |

Use a unique `REDIS_DATABASE` number from `0` to `15` and a unique `REDIS_PREFIX` for each Andrea Helpdesk install sharing the same Redis server. If Redis is unavailable but cache is enabled, Andrea Helpdesk falls back to `CACHEHOME` file caching when that directory is writable.
