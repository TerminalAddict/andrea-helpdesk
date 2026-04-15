# Version

Current release: **1.2.6** (2026-04-16)

See [changelog.md](changelog.md) for full history.

## How versioning works

- `version.json` in the repository root is the authoritative version file.
- `GET /api/version` returns the installed version from that file.
- The admin General settings route (`#/admin/settings/general`) shows the installed version and offers a **Check for Updates** button, which fetches `version.json` from the `main` branch on GitHub and compares.
- When an update is available, an **Update Now** button opens a preflight checklist followed by a one-click updater that downloads, extracts, copies files, and runs database migrations automatically.
- The updater source can be overridden with `UPDATE_REPO_ZIP_URL` and `UPDATE_REPO_PREFIX` for self-hosted update packages; otherwise it defaults to the public GitHub `main` zip.
- When a new release is ready, update `version.json`, `docs/version.md`, and `docs/changelog.md`, then commit and push. All installed instances will detect the new version on their next check.
- `make deploy` now runs `php bin/migrate.php` on the remote host after `composer install`, so numbered DB migrations are applied during normal rsync deployments as well as in-app updates.

## In-app updater

The updater (`src/Core/UpdateController.php`) performs the following steps:

1. **Preflight** (`GET /api/update/preflight`) — checks ZipArchive extension, download capability, write permissions on key directories, temp directory, and disk space. Returns pass/fail per check with specific fix instructions.
2. **Run** (`POST /api/update/run`) — downloads the configured update zip, extracts it, copies files over the installation (skipping `.env`, `storage/`, `vendor/`), runs `schema.sql`, applies new migrations tracked in the `schema_migrations` DB table, and resets the opcode cache.

Database migrations are tracked in `schema_migrations` (auto-created on first update or CLI migrate run). Migration `001_initial.sql` is always skipped as it is covered by `schema.sql`. New migrations are applied in filename order. The updater lock remains held until schema and migration work completes, preventing overlapping update runs from racing each other.
