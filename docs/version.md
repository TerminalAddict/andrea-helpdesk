# Version

Current release: **1.4.16** (2026-05-12)

See [changelog.md](changelog.md) for full history.

## How versioning works

- `version.json` in the repository root is the authoritative version file.
- `GET /api/version` returns the installed version from that file.
- The admin General settings route (`#/admin/settings/general`) shows the installed version and offers a **Check for Updates** button, which fetches `version.json` from `UPDATE_VERSION_URL` when set, otherwise from the public GitHub `main` branch, and compares.
- Admin sessions also perform a silent background update check at most once per day via the notification center. Overlapping checks for the same admin are serialised server-side before fetching upstream metadata, and if a newer version is found, an in-app notification is created linking directly to `#/admin/settings/general`.
- When an update is available, an **Update Now** button opens a preflight checklist followed by a one-click updater that downloads, extracts, copies files, and runs database migrations automatically.
- The preflight now checks both writable directories and whether existing files are actually overwriteable by the PHP process. This matters on shared hosting, where PHP often cannot replace files owned by your account even if the directory itself appears writable.
- Release metadata may declare `minimum_update_from`. When set, the updater blocks direct upgrades from older installs to newer releases and tells the admin which bridge version to install first.
- The version metadata source can be overridden with `UPDATE_VERSION_URL`. The updater package source can be overridden with `UPDATE_REPO_ZIP_URL` and `UPDATE_REPO_PREFIX`; otherwise it uses the public GitHub metadata to prefer the full release package for the latest version, falling back to the GitHub source zip if the package is unavailable.
- When a new release is ready, prepare the `docs/changelog.md` `Unreleased` section with the relevant notes, then run `make release`. That target bumps `version.json`, updates `docs/version.md`, updates `public_html/service-worker.js`, converts the prepared `Unreleased` notes into the new versioned changelog section, stages the current worktree, commits, and pushes.
- `make deploy` now runs `php bin/migrate.php` on the remote host after `composer install`, so numbered DB migrations are applied during normal rsync deployments as well as in-app updates.

## In-app updater

The updater (`src/Core/UpdateController.php`) performs the following steps:

1. **Preflight** (`GET /api/update/preflight`) — checks upgrade-path compatibility, ZipArchive extension, download capability, a PHP dependency update path, full release package availability when Composer is unavailable, write permissions on key directories including `vendor/`, overwriteability of existing files in those directories, temp directory, and disk space. Returns pass/fail per check with specific fix instructions.
2. **Run** (`POST /api/update/run`) — downloads the full GitHub release package when available, otherwise falls back to the configured/source zip only when Composer is available, extracts it, copies files over the installation (skipping `.env`, `storage/`, `.git`, `install.lock`, `Makefile.local`, and `docs/videos`), updates PHP dependencies from packaged `vendor/` files or by running `composer install --no-dev --optimize-autoloader`, runs `schema.sql`, applies new migrations tracked in the `schema_migrations` DB table, and resets the opcode cache.

The full GitHub release package includes `vendor/`, so shared-hosting installs without Composer can still receive new PHP dependencies through the web updater as long as the PHP process can overwrite the application files. If Composer is unavailable and the full release package is not available yet, wait for the GitHub release workflow to finish and retry; the updater must not use the source archive in that case. If a custom `UPDATE_REPO_ZIP_URL` points at a source archive without `vendor/`, Composer must be installed and executable by PHP.

### Bridge Update Requirement

Version `1.4.9` is the updater bridge release. Installs older than `1.4.9` must update to `1.4.9` before updating to `1.4.10` or newer, because `1.4.9` adds support for full release packages and packaged `vendor/` dependencies.

Current and future releases can enforce this by setting these fields in `version.json`:

```json
{
  "minimum_update_from": "1.4.9",
  "minimum_update_reason": "Version 1.4.9 upgrades the updater so full release packages and packaged vendor dependencies can be installed safely."
}
```

This cannot be enforced retrospectively by updater code that is already installed on a pre-`1.4.9` host, because that old updater does not know about bridge metadata. Those hosts should be manually directed to update to `1.4.9` first.

Database migrations are tracked in `schema_migrations` (auto-created on first update or CLI migrate run). Migration `001_initial.sql` is always skipped as it is covered by `schema.sql`. New migrations are applied in filename order. The updater lock remains held until schema and migration work completes, preventing overlapping update runs from racing each other.
