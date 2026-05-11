# Version

Current release: **1.4.7** (2026-05-11)

See [changelog.md](changelog.md) for full history.

## How versioning works

- `version.json` in the repository root is the authoritative version file.
- `GET /api/version` returns the installed version from that file.
- The admin General settings route (`#/admin/settings/general`) shows the installed version and offers a **Check for Updates** button, which fetches `version.json` from `UPDATE_VERSION_URL` when set, otherwise from the public GitHub `main` branch, and compares.
- Admin sessions also perform a silent background update check at most once per day via the notification center. Overlapping checks for the same admin are serialised server-side before fetching upstream metadata, and if a newer version is found, an in-app notification is created linking directly to `#/admin/settings/general`.
- When an update is available, an **Update Now** button opens a preflight checklist followed by a one-click updater that downloads, extracts, copies files, and runs database migrations automatically.
- The preflight now checks both writable directories and whether existing files are actually overwriteable by the PHP process. This matters on shared hosting, where PHP often cannot replace files owned by your account even if the directory itself appears writable.
- The version metadata source can be overridden with `UPDATE_VERSION_URL`. The updater package source can be overridden with `UPDATE_REPO_ZIP_URL` and `UPDATE_REPO_PREFIX`; otherwise it defaults to the public GitHub `main` metadata and zip.
- When a new release is ready, prepare the `docs/changelog.md` `Unreleased` section with the relevant notes, then run `make release`. That target bumps `version.json`, updates `docs/version.md`, updates the client version in `public_html/index.php`, converts the prepared `Unreleased` notes into the new versioned changelog section, stages the current worktree, commits, and pushes.
- `make deploy` now runs `php bin/migrate.php` on the remote host after `composer install`, so numbered DB migrations are applied during normal rsync deployments as well as in-app updates.

## In-app updater

The updater (`src/Core/UpdateController.php`) performs the following steps:

1. **Preflight** (`GET /api/update/preflight`) — checks ZipArchive extension, download capability, write permissions on key directories, overwriteability of existing files in those directories, temp directory, and disk space. Returns pass/fail per check with specific fix instructions.
2. **Run** (`POST /api/update/run`) — downloads the configured update zip, extracts it, copies files over the installation (skipping `.env`, `storage/`, `vendor/`), runs `schema.sql`, applies new migrations tracked in the `schema_migrations` DB table, and resets the opcode cache.

Database migrations are tracked in `schema_migrations` (auto-created on first update or CLI migrate run). Migration `001_initial.sql` is always skipped as it is covered by `schema.sql`. New migrations are applied in filename order. The updater lock remains held until schema and migration work completes, preventing overlapping update runs from racing each other.
