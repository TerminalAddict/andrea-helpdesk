# Version

Current release: **1.0.2** (2026-04-03)

See [changelog.md](changelog.md) for full history.

## How versioning works

- `version.json` in the repository root is the authoritative version file.
- `GET /api/version` returns the installed version from that file.
- The Settings → General tab shows the installed version and offers a **Check for Updates** button, which fetches `version.json` from the `main` branch on GitHub and compares.
- When an update is available, an **Update Now** button opens a preflight checklist followed by a one-click updater that downloads, extracts, copies files, and runs database migrations automatically.
- When a new release is ready, update `version.json`, `docs/version.md`, and `docs/changelog.md`, then commit and push. All installed instances will detect the new version on their next check.

## In-app updater

The updater (`src/Core/UpdateController.php`) performs the following steps:

1. **Preflight** (`GET /api/update/preflight`) — checks ZipArchive extension, download capability, write permissions on key directories, temp directory, and disk space. Returns pass/fail per check with specific fix instructions.
2. **Run** (`POST /api/update/run`) — downloads the GitHub `main` zip, extracts it, copies files over the installation (skipping `.env`, `storage/`, `vendor/`), runs `schema.sql`, applies new migrations tracked in the `schema_migrations` DB table, and resets the opcode cache.

Database migrations are tracked in `schema_migrations` (auto-created on first update run). Migration `001_initial.sql` is always skipped as it is covered by `schema.sql`. New migrations are applied in filename order.
